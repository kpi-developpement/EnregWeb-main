<?php
session_start();
require_once('database.php');

if (!ob_start("ob_gzhandler")) ob_start();

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function normalize_phone($v) { return preg_replace('/[^0-9+]/', '', (string)$v); }

if (!isset($_SESSION['user'])) { header("Location: login.php"); exit(); }

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId <= 0) { session_destroy(); header("Location: login.php"); exit(); }

$me = Database::selectParams(
    "SELECT id, is_admin, COALESCE(can_download,0) AS can_download FROM agent WHERE id = :id LIMIT 1",
    [':id' => $userId], PDO::FETCH_ASSOC
);
if (!empty($me)) {
    $_SESSION['user']['is_admin']     = (int)$me[0]['is_admin'];
    $_SESSION['user']['can_download'] = (int)$me[0]['can_download'];
}
$isAdmin     = !empty($_SESSION['user']['is_admin']) && (int)$_SESSION['user']['is_admin'] === 1;
$canDownload = $isAdmin || (!empty($_SESSION['user']['can_download']) && (int)$_SESSION['user']['can_download'] === 1);

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$adminSelectedAgentId = ($isAdmin) ? (int)($_GET['admin_agent_id'] ?? 0) : 0;

// ════════════════════════════════════════════════════
// AJAX ENDPOINT
// ════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_calls') {
    header('Content-Type: application/json; charset=utf-8');

    $selectedDate = trim((string)($_GET['date'] ?? ''));
    $searchText   = trim((string)($_GET['q'] ?? ''));
    $selectedTag  = trim((string)($_GET['tag'] ?? ''));
    $offset       = max(0, (int)($_GET['offset'] ?? 0));
    $limit        = min(100, max(20, (int)($_GET['limit'] ?? 50)));
    $detectMissed = ((int)($_GET['detect_missed'] ?? 1)) === 1;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
        echo json_encode(['error' => 'Date invalide', 'items' => [], 'total' => 0]);
        exit;
    }

    $where  = ['file_date = :d'];
    $params = [':d' => $selectedDate];

    if ($selectedTag !== '') {
        $where[]        = 'tag = :tag';
        $params[':tag'] = $selectedTag;
    }

    if ($searchText !== '') {
        $where[]      = '(tag LIKE :q OR numero2 LIKE :q OR filename LIKE :q)';
        $params[':q'] = '%' . $searchText . '%';
    }

    if (!$isAdmin) {
        $userTagRows = Database::selectParams(
            "SELECT t.numero FROM tag t
             INNER JOIN tag_agent_lisent ta ON t.id = ta.id_tag
             WHERE ta.id_agent = :uid",
            [':uid' => $userId], PDO::FETCH_ASSOC
        );
        $userTagNums = array_column($userTagRows ?: [], 'numero');

        if (empty($userTagNums)) {
            echo json_encode(['items'=>[],'total'=>0,'offset'=>0,'hasMore'=>false,'stats'=>null,'folder'=>$selectedDate]);
            exit;
        }

        $inPlaceholders = [];
        foreach ($userTagNums as $i => $num) {
            $key = ':ut' . $i;
            $inPlaceholders[] = $key;
            $params[$key]     = $num;
        }
        $where[] = 'tag IN (' . implode(',', $inPlaceholders) . ')';
    }

    $whereStr = 'WHERE ' . implode(' AND ', $where);

    $countRows = Database::selectParams(
        "SELECT COUNT(*) AS cnt FROM audio_index $whereStr",
        $params, PDO::FETCH_ASSOC
    );
    $total = (int)(($countRows[0] ?? [])['cnt'] ?? 0);

    $stats = null;
    if ($offset === 0) {
        $statsRows = Database::selectParams(
            "SELECT direction, COUNT(*) AS cnt FROM audio_index $whereStr GROUP BY direction",
            $params, PDO::FETCH_ASSOC
        );
        $stats = ['inbound' => 0, 'outbound' => 0, 'internal' => 0, 'missed' => 0, 'unknown' => 0];
        foreach (($statsRows ?: []) as $sr) {
            // ⚠️ INVERSION : in DB = Sortant dans l'UI, out DB = Entrant dans l'UI
            if ($sr['direction'] === 'in')  $stats['outbound'] += (int)$sr['cnt'];
            if ($sr['direction'] === 'out') $stats['inbound']  += (int)$sr['cnt'];
        }
    }

    $rows = Database::selectParams(
        "SELECT tag, numero2, direction, call_dt, filename, filepath, filesize
         FROM audio_index $whereStr
         ORDER BY call_dt DESC
         LIMIT $limit OFFSET $offset",
        $params, PDO::FETCH_ASSOC
    );
    if (!is_array($rows)) $rows = [];

    $items = [];
    foreach ($rows as $row) {
        $filePath = $row['filepath']; // ex: audio_mails/2026-05-13/xxx.mp3

        // URL directe Apache (lecture instantanée sans PHP)
        $audioUrl = $filePath;

        // URL téléchargement via download.php (sécurisé)
        $sig   = hash_hmac('sha256', $filePath, $_SESSION['csrf_token']);
        $dlUrl = "download.php?f=" . rawurlencode($filePath) . "&sig=" . $sig . "&dl=1";

        // ⚠️ INVERSION ENTRANT/SORTANT
        // Convention fichier : in = agent à droite (tag=agent, numero2=correspondant) → affiché comme Sortant
        //                      out = agent à gauche                                   → affiché comme Entrant
        switch ($row['direction']) {
            case 'in':
                $direction = 'Sortant'; $directionClass = 'outbound'; break;
            case 'out':
                $direction = 'Entrant'; $directionClass = 'inbound';  break;
            default:
                $direction = 'Inconnu'; $directionClass = 'unknown';  break;
        }

        // Détection appel manqué (filesize en DB, pas d'accès NAS)
        if ($detectMissed && $directionClass === 'inbound') {
            $sz = ($row['filesize'] !== null) ? (int)$row['filesize'] : 0;
            if ($sz > 0 && $sz < 15360) {
                $direction      = 'Manqué';
                $directionClass = 'missed';
                if ($stats !== null) { $stats['inbound']--; $stats['missed']++; }
            }
        }

        $items[] = [
            'a'              => $row['tag'],
            'b'              => $row['numero2'],
            'date'           => $row['call_dt'],
            'direction'      => $direction,
            'directionClass' => $directionClass,
            'audioUrl'       => $audioUrl,  // lecture directe Apache
            'dlUrl'          => $dlUrl,     // téléchargement via PHP
            'search'         => strtolower($row['tag'] . ' ' . $row['numero2']),
        ];
    }

    echo json_encode([
        'items'   => $items,
        'total'   => $total,
        'offset'  => $offset,
        'hasMore' => ($offset + count($items)) < $total,
        'stats'   => $stats,
        'folder'  => $selectedDate,
    ]);
    exit;
}

// ════════════════════════════════════════════════════
// POST handlers (admin)
// ════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type'])) {

    if ($_POST['form_type'] === 'admin_assign') {
        if (!$isAdmin) { http_response_code(403); die("Accès refusé."); }
        if (empty($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) { http_response_code(400); die("CSRF invalide."); }
        $targetAgentId = (int)($_POST['agent_id'] ?? 0);
        $tagId = (int)($_POST['tag_id'] ?? 0);
        if ($targetAgentId <= 0 || $tagId <= 0) { http_response_code(400); die("Paramètres invalides."); }
        Database::executeParams(
            "INSERT INTO tag_agent_lisent (id_agent, id_tag)
             SELECT :aid, :tid FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM tag_agent_lisent WHERE id_agent = :aid2 AND id_tag = :tid2)",
            [':aid' => $targetAgentId, ':tid' => $tagId, ':aid2' => $targetAgentId, ':tid2' => $tagId]
        );
        header("Location: index.php?admin_agent_id=" . $targetAgentId . "#admin-panel"); exit();
    }

    if ($_POST['form_type'] === 'admin_remove') {
        if (!$isAdmin) { http_response_code(403); die("Accès refusé."); }
        if (empty($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) { http_response_code(400); die("CSRF invalide."); }
        $targetAgentId = (int)($_POST['agent_id'] ?? 0);
        $tagId = (int)($_POST['tag_id'] ?? 0);
        if ($targetAgentId <= 0 || $tagId <= 0) { http_response_code(400); die("Paramètres invalides."); }
        Database::executeParams(
            "DELETE FROM tag_agent_lisent WHERE id_agent = :aid AND id_tag = :tid",
            [':aid' => $targetAgentId, ':tid' => $tagId]
        );
        header("Location: index.php?admin_agent_id=" . $targetAgentId . "#admin-panel"); exit();
    }

    if ($_POST['form_type'] === 'admin_set_download') {
        if (!$isAdmin) { http_response_code(403); die("Accès refusé."); }
        if (empty($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) { http_response_code(400); die("CSRF invalide."); }
        $targetAgentId = (int)($_POST['agent_id'] ?? 0);
        $val = ((int)($_POST['can_download'] ?? 0) === 1) ? 1 : 0;
        if ($targetAgentId <= 0) { http_response_code(400); die("Paramètres invalides."); }
        Database::executeParams(
            "UPDATE agent SET can_download = :v WHERE id = :id",
            [':v' => $val, ':id' => $targetAgentId]
        );
        header("Location: index.php?admin_agent_id=" . $targetAgentId . "#admin-panel"); exit();
    }
}

// ════════════════════════════════════════════════════
// Données page principale
// ════════════════════════════════════════════════════
$tags = Database::selectParams(
    "SELECT t.id, t.numero FROM `tag` t
     INNER JOIN `tag_agent_lisent` ta ON t.id = ta.id_tag
     WHERE ta.id_agent = :uid ORDER BY t.numero",
    [':uid' => $userId], PDO::FETCH_ASSOC
);

$agentsAll = []; $tagsAll = []; $agentTags = []; $selectedAgentCanDownload = 0;
if ($isAdmin) {
    $agentsAll = Database::selectParams(
        "SELECT id, username, nom, prenom, COALESCE(can_download,0) AS can_download FROM agent ORDER BY username",
        [], PDO::FETCH_ASSOC
    );
    $tagsAll = Database::selectParams("SELECT id, numero FROM tag ORDER BY numero", [], PDO::FETCH_ASSOC);
    if ($adminSelectedAgentId > 0) {
        $agentTags = Database::selectParams(
            "SELECT t.id, t.numero FROM tag t
             INNER JOIN tag_agent_lisent ta ON ta.id_tag = t.id
             WHERE ta.id_agent = :aid ORDER BY t.numero",
            [':aid' => $adminSelectedAgentId], PDO::FETCH_ASSOC
        );
        foreach ($agentsAll as $a) {
            if ((int)$a['id'] === $adminSelectedAgentId) {
                $selectedAgentCanDownload = (int)$a['can_download']; break;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kyntus Morocco — Enregistrements</title>

    <script>
        (function(){ try { var t = localStorage.getItem('kyntus-theme') || 'light'; document.documentElement.setAttribute('data-theme', t); } catch(e){} })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">
    <link href="assets/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">

    <style>
        :root, [data-theme="light"] {
            --bg:#f5f7fb; --surface:#fff; --surface2:#f9fafc;
            --border:rgba(15,23,42,.08); --border2:rgba(15,23,42,.14);
            --accent:#2563eb; --accent2:#4f46e5;
            --text:#1e293b; --text-strong:#0f172a; --muted:#64748b; --radius:14px;
            --in-color:#16a34a; --in-bg:rgba(34,197,94,.1); --in-border:rgba(34,197,94,.3);
            --out-color:#d97706; --out-bg:rgba(245,158,11,.1); --out-border:rgba(245,158,11,.3);
            --int-color:#0284c7; --int-bg:rgba(56,189,248,.1); --int-border:rgba(56,189,248,.3);
            --missed-color:#dc2626; --missed-bg:rgba(239,68,68,.08); --missed-border:rgba(239,68,68,.3);
            --unk-color:#6b7280; --unk-bg:rgba(156,163,175,.1); --unk-border:rgba(156,163,175,.25);
            --input-bg:#fff; --input-bg-focus:#f8fafc;
            --bg-grad-1:radial-gradient(ellipse 70% 50% at 0% 0%,rgba(37,99,235,.07) 0%,transparent 55%);
            --bg-grad-2:radial-gradient(ellipse 50% 40% at 100% 100%,rgba(79,70,229,.06) 0%,transparent 55%);
            --topbar-bg:rgba(255,255,255,.95);
        }
        [data-theme="dark"] {
            --bg:#0d0f14; --surface:#161922; --surface2:#1e2230;
            --border:rgba(255,255,255,.07); --border2:rgba(255,255,255,.12);
            --accent:#3b82f6; --accent2:#6366f1;
            --text:#e2e5ef; --text-strong:#fff; --muted:#6b7280;
            --in-color:#22c55e; --in-bg:rgba(34,197,94,.08); --in-border:rgba(34,197,94,.25);
            --out-color:#f59e0b; --out-bg:rgba(245,158,11,.08); --out-border:rgba(245,158,11,.25);
            --int-color:#38bdf8; --int-bg:rgba(56,189,248,.08); --int-border:rgba(56,189,248,.25);
            --missed-color:#ef4444; --missed-bg:rgba(239,68,68,.1); --missed-border:rgba(239,68,68,.3);
            --unk-color:#9ca3af; --unk-bg:rgba(156,163,175,.07); --unk-border:rgba(156,163,175,.2);
            --input-bg:rgba(255,255,255,.04); --input-bg-focus:rgba(255,255,255,.06);
            --bg-grad-1:radial-gradient(ellipse 70% 50% at 0% 0%,rgba(59,130,246,.12) 0%,transparent 55%);
            --bg-grad-2:radial-gradient(ellipse 50% 40% at 100% 100%,rgba(99,102,241,.1) 0%,transparent 55%);
            --topbar-bg:rgba(13,15,20,.92);
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        html,body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;font-size:14px;transition:background .25s,color .25s}
        body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:var(--bg-grad-1),var(--bg-grad-2)}

        .topbar{position:sticky;top:0;z-index:100;height:60px;background:var(--topbar-bg);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 24px;gap:16px}
        .topbar-brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--text)}
        .topbar-icon{width:34px;height:34px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:9px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(59,130,246,.3)}
        .topbar-icon svg{width:16px;height:16px;fill:#fff}
        .topbar-name{font-family:'Syne',sans-serif;font-size:16px;font-weight:700;color:var(--text-strong)}
        .topbar-spacer{flex:1}
        .badge-admin{background:rgba(34,197,94,.15);color:var(--in-color);border:1px solid var(--in-border);border-radius:6px;padding:3px 9px;font-size:11px;font-weight:500}
        .theme-toggle{width:38px;height:38px;background:var(--surface);border:1px solid var(--border);border-radius:9px;color:var(--text);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px;transition:transform .2s}
        .theme-toggle:hover{transform:rotate(15deg)}
        [data-theme="light"] .theme-toggle .bi-moon-stars{display:inline-block}
        [data-theme="light"] .theme-toggle .bi-sun{display:none}
        [data-theme="dark"]  .theme-toggle .bi-moon-stars{display:none}
        [data-theme="dark"]  .theme-toggle .bi-sun{display:inline-block}
        .topbar-logout{font-size:13px;color:var(--muted);text-decoration:none;padding:6px 12px;border:1px solid var(--border);border-radius:8px}
        .topbar-logout:hover{color:var(--text);border-color:var(--border2)}

        .main-wrap{position:relative;z-index:1;padding:20px 20px 40px;max-width:1600px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
        .grid-main{display:grid;gap:16px;grid-template-columns:320px 1fr <?= $isAdmin ? '280px' : '' ?>}
        @media(max-width:1100px){.grid-main{grid-template-columns:1fr}}

        .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
        .card-head{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px}
        .card-head-left{display:flex;align-items:center;gap:9px;font-family:'Syne',sans-serif;font-weight:700;font-size:14px;color:var(--text-strong)}
        .card-head-left i{color:var(--accent);font-size:16px}
        .card-body{padding:18px}

        .search-grid{display:grid;gap:12px;align-items:end;grid-template-columns:1fr 1fr 200px auto auto}
        @media(max-width:768px){.search-grid{grid-template-columns:1fr 1fr}}

        .field-group label{display:block;font-size:11px;font-weight:500;letter-spacing:.6px;text-transform:uppercase;color:var(--muted);margin-bottom:7px}
        .field-group input,.field-group select,.field-group .form-control{width:100%;background:var(--input-bg)!important;border:1px solid var(--border)!important;border-radius:9px!important;padding:10px 14px;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--text)!important;outline:none;transition:border-color .2s,background .2s,box-shadow .2s;height:auto!important}
        .field-group input:focus,.field-group select:focus{border-color:var(--accent)!important;background:var(--input-bg-focus)!important;box-shadow:0 0 0 3px rgba(59,130,246,.11)!important}

        .btn-search{height:42px;padding:0 18px;background:linear-gradient(135deg,var(--accent),var(--accent2));border:none;border-radius:9px;color:#fff;font-size:14px;font-weight:500;display:flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;transition:opacity .18s,transform .15s;box-shadow:0 4px 16px rgba(59,130,246,.3);white-space:nowrap}
        .btn-search:hover{opacity:.9;transform:translateY(-1px)}
        .btn-search:disabled{opacity:.6;cursor:wait;transform:none}
        .btn-clear{height:42px;width:42px;background:var(--surface2);border:1px solid var(--border);border-radius:9px;color:var(--muted);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px;transition:color .15s}
        .btn-clear:hover{color:var(--text);border-color:var(--border2)}

        .options-row{display:flex;align-items:center;gap:12px;margin-top:12px;font-size:13px;color:var(--muted);flex-wrap:wrap}
        .opt-check{display:flex;align-items:center;gap:6px;cursor:pointer}
        .opt-check input{margin:0}

        .info-banner{margin-top:14px;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:9px;padding:11px 15px;font-size:13px;color:var(--accent);display:flex;align-items:center;gap:8px}
        .info-banner.error{background:var(--missed-bg);border-color:var(--missed-border);color:var(--missed-color)}
        .info-banner.success{background:var(--in-bg);border-color:var(--in-border);color:var(--in-color)}

        .perf-tag{display:inline-flex;align-items:center;gap:4px;font-size:11px;color:var(--muted);background:var(--input-bg);padding:2px 8px;border-radius:99px;margin-left:auto}

        .quick-filters{display:flex;gap:6px;padding:10px 14px;border-bottom:1px solid var(--border);overflow-x:auto;scrollbar-width:none}
        .quick-filters::-webkit-scrollbar{display:none}
        .qf-btn{flex-shrink:0;background:transparent;border:1px solid var(--border);color:var(--muted);padding:5px 11px;border-radius:7px;font-size:11.5px;font-weight:500;cursor:pointer;display:flex;align-items:center;gap:5px;transition:all .15s;white-space:nowrap}
        .qf-btn:hover{color:var(--text);border-color:var(--border2)}
        .qf-btn.active{background:var(--accent);border-color:var(--accent);color:#fff}
        .qf-btn .qf-count{background:rgba(0,0,0,.2);padding:0 5px;border-radius:99px;font-size:10px;min-width:16px;text-align:center}
        [data-theme="light"] .qf-btn:not(.active) .qf-count{background:rgba(15,23,42,.08)}

        .list-scroll{max-height:65vh;overflow-y:auto}
        .list-scroll::-webkit-scrollbar{width:5px}
        .list-scroll::-webkit-scrollbar-thumb{background:var(--border2);border-radius:99px}

        .call-item{padding:12px 14px;cursor:pointer;border-bottom:1px solid var(--border);border-left:3px solid transparent;transition:background .12s;contain:content}
        .call-item:last-child{border-bottom:0}
        .call-item:hover{background:var(--input-bg)}
        .call-item.hidden{display:none}
        .call-item.active{background:rgba(59,130,246,.07);border-left-color:var(--accent)}

        .call-item[data-direction="Entrant"]{border-left-color:var(--in-border)}
        .call-item[data-direction="Entrant"]:hover,.call-item[data-direction="Entrant"].active{background:var(--in-bg);border-left-color:var(--in-color)}
        .call-item[data-direction="Sortant"]{border-left-color:var(--out-border)}
        .call-item[data-direction="Sortant"]:hover,.call-item[data-direction="Sortant"].active{background:var(--out-bg);border-left-color:var(--out-color)}
        .call-item[data-direction="Manqué"]{border-left-color:var(--missed-border)}
        .call-item[data-direction="Manqué"]:hover,.call-item[data-direction="Manqué"].active{background:var(--missed-bg);border-left-color:var(--missed-color)}
        .call-item[data-direction="Inconnu"]{border-left-color:var(--unk-border)}
        .call-item[data-direction="Inconnu"]:hover,.call-item[data-direction="Inconnu"].active{background:var(--unk-bg);border-left-color:var(--unk-color)}

        .call-row1{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:5px}
        .call-icon-num{display:flex;align-items:center;gap:8px;min-width:0}
        .call-icon-num i{font-size:15px;flex-shrink:0}
        .call-numbers{font-size:13.5px;font-weight:500;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .call-numbers .arrow{color:var(--muted);margin:0 4px}
        .dir-badge{flex-shrink:0;font-size:10px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;padding:2px 8px;border-radius:5px;border:1px solid}
        .dir-badge.inbound{color:var(--in-color);background:var(--in-bg);border-color:var(--in-border)}
        .dir-badge.outbound{color:var(--out-color);background:var(--out-bg);border-color:var(--out-border)}
        .dir-badge.missed{color:var(--missed-color);background:var(--missed-bg);border-color:var(--missed-border);animation:pulse 2s infinite}
        .dir-badge.unknown{color:var(--unk-color);background:var(--unk-bg);border-color:var(--unk-border)}
        @keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.3)}50%{box-shadow:0 0 0 4px rgba(239,68,68,0)}}
        .call-meta{font-size:11.5px;color:var(--muted);display:flex;align-items:center;gap:4px}

        .list-empty{padding:40px 20px;text-align:center;color:var(--muted)}
        .list-empty i{font-size:32px;opacity:.35;display:block;margin-bottom:10px}
        .list-folder{padding:10px 14px 6px;font-size:11.5px;color:var(--muted);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:6px}
        .count-pill{background:rgba(59,130,246,.18);color:var(--accent);border:1px solid rgba(59,130,246,.3);border-radius:99px;padding:1px 9px;font-size:11px;font-weight:600}

        .load-sentinel{padding:16px;text-align:center;color:var(--muted);font-size:12px;display:flex;align-items:center;justify-content:center;gap:8px}
        .spinner{width:16px;height:16px;border:2px solid var(--border2);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}

        .search-overlay{position:absolute;inset:0;z-index:5;background:var(--surface);display:none;align-items:center;justify-content:center;flex-direction:column;gap:12px;color:var(--muted);font-size:13px}
        .search-overlay.active{display:flex}
        .search-overlay .spinner{width:28px;height:28px;border-width:3px}

        .player-shell{background:var(--surface2);border:1px dashed var(--border2);border-radius:12px;min-height:200px;position:relative;overflow:hidden}
        #empty-wave{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;color:var(--muted);font-size:13px;pointer-events:none}
        #empty-wave i{font-size:36px;opacity:.3}
        .waveform-wrap{margin-bottom:10px}
        .range-track{width:100%;margin-top:8px;-webkit-appearance:none;height:4px;background:var(--border2);border-radius:99px;outline:none;cursor:pointer}
        .range-track::-webkit-slider-thumb{-webkit-appearance:none;width:14px;height:14px;border-radius:50%;background:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.3);cursor:pointer}
        .range-track:disabled{opacity:.3;pointer-events:none}
        .time-row{display:flex;justify-content:space-between;margin-top:6px;font-size:12px}
        #current-time{color:var(--accent);font-weight:600}
        #duration{color:var(--muted)}

        .controls{display:flex;align-items:center;justify-content:center;gap:10px;margin-top:18px;flex-wrap:wrap}
        .ctrl-btn{width:44px;height:44px;background:var(--surface2);border:1px solid var(--border2);border-radius:50%;color:var(--text);font-size:18px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .15s,transform .12s}
        .ctrl-btn:hover{background:var(--input-bg);transform:scale(1.06)}
        .ctrl-btn.primary{width:52px;height:52px;font-size:22px;background:linear-gradient(135deg,var(--accent),var(--accent2));border:none;color:#fff;box-shadow:0 6px 20px rgba(59,130,246,.35)}
        .ctrl-btn.primary:hover{opacity:.9}
        .speed-btn{background:var(--surface2);border:1px solid var(--border2);border-radius:22px;color:var(--text);padding:0 14px;height:44px;font-size:13px;font-weight:600;cursor:pointer;min-width:56px}
        .speed-btn:hover{background:var(--input-bg)}
        .dl-btn{width:44px;height:44px;background:var(--surface2);border:1px solid var(--border2);border-radius:50%;color:var(--text);font-size:18px;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:background .15s,transform .12s}
        .dl-btn:hover{background:var(--input-bg);transform:scale(1.06);color:var(--text)}
        .dl-btn.off{opacity:.35;pointer-events:none}

        .perm-badge{font-size:11px;font-weight:500;padding:3px 10px;border-radius:6px;border:1px solid}
        .perm-badge.allowed{color:var(--in-color);background:var(--in-bg);border-color:var(--in-border)}
        .perm-badge.denied{color:var(--muted);background:var(--unk-bg);border-color:var(--unk-border)}

        .admin-label{font-size:11px;font-weight:500;letter-spacing:.6px;text-transform:uppercase;color:var(--muted);margin-bottom:7px;display:block}
        .admin-field{margin-bottom:16px}
        .btn-adm{width:100%;padding:10px;border-radius:9px;font-size:13.5px;font-weight:500;cursor:pointer;border:1px solid;transition:opacity .15s}
        .btn-adm:hover{opacity:.82}
        .btn-adm.allow{background:rgba(34,197,94,.12);color:var(--in-color);border-color:var(--in-border)}
        .btn-adm.revoke{background:rgba(239,68,68,.1);color:var(--missed-color);border-color:var(--missed-border)}
        .btn-adm.primary{background:rgba(59,130,246,.15);color:var(--accent);border-color:rgba(59,130,246,.3);margin-top:8px}
        .tag-list-scroll{max-height:220px;overflow-y:auto}
        .tag-list-scroll::-webkit-scrollbar{width:4px}
        .tag-list-scroll::-webkit-scrollbar-thumb{background:var(--border2);border-radius:99px}
        .tag-row{display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border-radius:8px;font-size:13px}
        .tag-row:hover{background:var(--input-bg)}
        .btn-rm{width:26px;height:26px;background:var(--missed-bg);border:1px solid var(--missed-border);border-radius:6px;color:var(--missed-color);font-size:13px;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0}
        .btn-rm:hover{opacity:.8}

        .select2-container{width:100%!important}
        .select2-container--default .select2-selection--single{background:var(--input-bg)!important;border:1px solid var(--border)!important;border-radius:9px!important;height:42px!important;color:var(--text)!important}
        .select2-container--default .select2-selection--single .select2-selection__rendered{line-height:40px!important;color:var(--text)!important;padding-left:14px!important}
        .select2-container--default .select2-selection--single .select2-selection__arrow{height:42px!important;right:10px!important}
        .select2-container--default .select2-selection--single .select2-selection__arrow b{border-top-color:var(--muted)!important}
        .select2-dropdown{background:var(--surface)!important;border:1px solid var(--border2)!important;border-radius:10px!important;box-shadow:0 16px 40px rgba(0,0,0,.15)!important}
        .select2-search--dropdown{padding:8px!important}
        .select2-search--dropdown .select2-search__field{background:var(--input-bg)!important;border:1px solid var(--border)!important;border-radius:7px!important;color:var(--text)!important;padding:7px 10px!important}
        .select2-results__option{padding:9px 14px!important;font-size:13.5px!important;color:var(--text)!important}
        .select2-container--default .select2-results__option--highlighted[aria-selected]{background:var(--accent)!important;color:#fff!important}
        .select2-results__option[aria-selected=true]{background:rgba(59,130,246,.1)!important}
        .select2-container--open{z-index:9999}

        .sticky-col{position:sticky;top:76px}
        .mt-hint{font-size:11.5px;color:var(--muted);margin-top:5px}
        .sep{border-top:1px solid var(--border);margin:16px 0}
    </style>
</head>
<body>

<header class="topbar">
    <a class="topbar-brand" href="#">
        <div class="topbar-icon">
            <svg viewBox="0 0 24 24"><path d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.58.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1C10.29 21 3 13.71 3 5c0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.26.2 2.47.57 3.6.1.34.02.74-.24 1.01L6.6 10.8z"/></svg>
        </div>
        <span class="topbar-name">Kyntus Morocco</span>
    </a>
    <span class="topbar-spacer"></span>
    <?php if ($isAdmin): ?>
        <span class="badge-admin"><i class="bi bi-shield-check me-1"></i>Admin</span>
    <?php endif; ?>
    <button id="themeToggle" class="theme-toggle" type="button" title="Changer de thème">
        <i class="bi bi-moon-stars"></i><i class="bi bi-sun"></i>
    </button>
    <a class="topbar-logout" href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Déconnexion</a>
</header>

<div class="main-wrap">

    <div class="card">
        <div class="card-body">
            <form id="searchForm" onsubmit="return false;">
                <div class="search-grid">
                    <div class="field-group">
                        <label>Tag</label>
                        <select id="tagSelectSearch" class="form-control">
                            <option value="">Tous les tags</option>
                            <?php foreach ($tags as $tag): ?>
                                <option value="<?= h($tag['numero']) ?>"><?= h($tag['numero']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-group">
                        <label>Numéro</label>
                        <input type="text" id="serverSearch" placeholder="Filtrer par numéro..." autocomplete="off">
                    </div>
                    <div class="field-group">
                        <label>Date</label>
                        <input type="date" id="dateSearch" required value="<?= h(date('Y-m-d')) ?>">
                    </div>
                    <div>
                        <button type="button" id="searchBtn" class="btn-search">
                            <i class="bi bi-search"></i> Rechercher
                        </button>
                    </div>
                    <div>
                        <button type="button" id="clearBtn" class="btn-clear" title="Effacer">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
                <div class="options-row">
                    <label class="opt-check">
                        <input type="checkbox" id="optDetectMissed" checked>
                        <span>Détecter appels manqués</span>
                    </label>
                </div>
                <div id="infoBanner" style="display:none;"></div>
            </form>
        </div>
    </div>

    <div class="grid-main">

        <div class="sticky-col">
            <div class="card">
                <div class="card-head">
                    <div class="card-head-left"><i class="bi bi-list-ul"></i> Appels</div>
                    <span class="perf-tag" id="perfTag" style="display:none;"><i class="bi bi-lightning-fill"></i> <span id="perfMs">0</span> ms</span>
                    <span class="count-pill" id="count_audio">0</span>
                </div>
                <div id="quickFilters" class="quick-filters" style="display:none;"></div>
                <div id="listFolder" class="list-folder" style="display:none;"></div>
                <div style="position:relative;">
                    <div class="search-overlay" id="searchOverlay">
                        <div class="spinner"></div>
                        <div>Chargement des appels...</div>
                    </div>
                    <div id="audioContainer" class="list-scroll">
                        <div class="list-empty" id="emptyState">
                            <i class="bi bi-search"></i>
                            <div style="font-weight:500;margin-bottom:4px;">Prêt à rechercher</div>
                            <div style="font-size:12px;">Choisis une date puis clique sur <b>Rechercher</b>.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="sticky-col">
            <div class="card">
                <div class="card-head">
                    <div class="card-head-left"><i class="bi bi-soundwave"></i> Lecteur</div>
                    <?php if ($canDownload): ?>
                        <span class="perm-badge allowed"><i class="bi bi-check-circle me-1"></i>Téléchargement autorisé</span>
                    <?php else: ?>
                        <span class="perm-badge denied"><i class="bi bi-lock me-1"></i>Téléchargement interdit</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="waveform-wrap">
                        <div id="waveform" class="player-shell">
                            <div id="empty-wave">
                                <i class="bi bi-music-note-beamed"></i>
                                <span>Aucun audio chargé</span>
                                <span style="font-size:12px;">Cliquez sur un appel dans la liste</span>
                            </div>
                        </div>
                        <input id="seek" type="range" class="range-track mt-2" min="0" max="1" value="0" step="0.001" disabled>
                        <div class="time-row">
                            <span id="current-time">00:00</span>
                            <span id="duration">00:00</span>
                        </div>
                    </div>
                    <div class="controls">
                        <button type="button" id="stopBtn" class="ctrl-btn" title="Stop"><i class="bi bi-stop-fill"></i></button>
                        <button type="button" id="fastbackward" class="ctrl-btn" title="-10s"><i class="bi bi-skip-backward-fill"></i></button>
                        <button type="button" id="play" class="ctrl-btn primary PalyPaus"><i class="bi bi-play-fill"></i></button>
                        <button type="button" id="paus" class="ctrl-btn primary PalyPaus d-none"><i class="bi bi-pause-fill"></i></button>
                        <button type="button" id="fastforward" class="ctrl-btn" title="+10s"><i class="bi bi-skip-forward-fill"></i></button>
                        <button type="button" id="speedBtn" class="speed-btn">1x</button>
                        <?php if ($canDownload): ?>
                            <a id="download-btn" href="" target="_blank" rel="noopener" class="dl-btn" title="Télécharger"><i class="bi bi-download"></i></a>
                        <?php else: ?>
                            <span class="dl-btn off" title="Téléchargement interdit"><i class="bi bi-download"></i></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <div class="sticky-col" id="admin-panel">
            <div class="card">
                <div class="card-head">
                    <div class="card-head-left"><i class="bi bi-shield-lock"></i> Administration</div>
                    <span class="badge-admin">Admin</span>
                </div>
                <div class="card-body">
                    <div class="admin-field">
                        <span class="admin-label">Agent</span>
                        <select id="admin_agent_select" class="form-control">
                            <option value="0">— Sélectionner un agent —</option>
                            <?php foreach ($agentsAll as $a): ?>
                                <option value="<?= (int)$a['id'] ?>" <?= ($adminSelectedAgentId === (int)$a['id']) ? 'selected' : '' ?>>
                                    <?= h($a['username'] . ' — ' . trim(($a['nom'] ?? '') . ' ' . ($a['prenom'] ?? ''))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-hint">Sélectionner un agent pour gérer ses droits.</p>
                    </div>

                    <?php if ($adminSelectedAgentId > 0): ?>
                    <div class="sep"></div>
                    <form method="POST" class="admin-field">
                        <input type="hidden" name="form_type" value="admin_set_download">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="agent_id" value="<?= (int)$adminSelectedAgentId ?>">
                        <input type="hidden" name="can_download" value="<?= $selectedAgentCanDownload ? 0 : 1 ?>">
                        <span class="admin-label">Droit de téléchargement</span>
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                            <span style="font-size:13px;">Statut actuel</span>
                            <?php if ($selectedAgentCanDownload): ?>
                                <span class="perm-badge allowed">Autorisé</span>
                            <?php else: ?>
                                <span class="perm-badge denied">Interdit</span>
                            <?php endif; ?>
                        </div>
                        <button class="btn-adm <?= $selectedAgentCanDownload ? 'revoke' : 'allow' ?>" type="submit">
                            <?php if ($selectedAgentCanDownload): ?>
                                <i class="bi bi-x-circle me-1"></i>Retirer le droit
                            <?php else: ?>
                                <i class="bi bi-check-circle me-1"></i>Autoriser
                            <?php endif; ?>
                        </button>
                    </form>
                    <div class="sep"></div>
                    <?php endif; ?>

                    <form method="POST" class="admin-field">
                        <input type="hidden" name="form_type" value="admin_assign">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="agent_id" id="admin_agent_id_hidden" value="<?= (int)$adminSelectedAgentId ?>">
                        <span class="admin-label">Attribuer un tag</span>
                        <select id="admin_tag_select" name="tag_id" class="form-control" required>
                            <?php foreach ($tagsAll as $t): ?>
                                <option value="<?= (int)$t['id'] ?>"><?= h($t['numero']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn-adm primary" type="submit" <?= ($adminSelectedAgentId <= 0) ? 'disabled' : '' ?>>
                            <i class="bi bi-plus-circle me-1"></i>Attribuer
                        </button>
                    </form>

                    <div class="sep"></div>
                    <span class="admin-label">Tags attribués</span>
                    <div class="tag-list-scroll">
                        <?php if ($adminSelectedAgentId <= 0): ?>
                            <p class="mt-hint" style="padding:8px 0;">Sélectionnez un agent.</p>
                        <?php elseif (empty($agentTags)): ?>
                            <p class="mt-hint" style="padding:8px 0;">Aucun tag attribué.</p>
                        <?php else: ?>
                            <?php foreach ($agentTags as $t): ?>
                            <div class="tag-row">
                                <span><?= h($t['numero']) ?></span>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="form_type" value="admin_remove">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="agent_id" value="<?= (int)$adminSelectedAgentId ?>">
                                    <input type="hidden" name="tag_id" value="<?= (int)$t['id'] ?>">
                                    <button class="btn-rm" type="submit"><i class="bi bi-x"></i></button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/wavesurfer.js@6.6.4/dist/wavesurfer.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js" defer></script>
<script src="assets/dist/js/bootstrap.bundle.min.js" defer></script>

<script>
// ══ THEME ══
(function(){
    const btn=document.getElementById('themeToggle');
    if(!btn) return;
    btn.addEventListener('click',function(){
        const html=document.documentElement;
        const next=html.getAttribute('data-theme')==='dark'?'light':'dark';
        html.setAttribute('data-theme',next);
        try{localStorage.setItem('kyntus-theme',next);}catch(e){}
        if(window.wavesurfer&&typeof window.wavesurfer.setOptions==='function'){
            try{window.wavesurfer.setOptions({waveColor:next==='dark'?'rgba(255,255,255,.12)':'rgba(15,23,42,.12)'});}catch(e){}
        }
    });
})();

const PAGE_SIZE=50;
const state={date:'',tag:'',q:'',offset:0,total:0,hasMore:false,activeFilter:'all',loading:false,requestId:0,detectMissed:true};

const $$=id=>document.getElementById(id);
const audioContainer=$$('audioContainer');
const emptyState=$$('emptyState');
const countEl=$$('count_audio');
const folderEl=$$('listFolder');
const filtersEl=$$('quickFilters');
const infoBanner=$$('infoBanner');
const overlay=$$('searchOverlay');
const perfTag=$$('perfTag');
const perfMs=$$('perfMs');

function showBanner(msg,type){
    if(!msg){infoBanner.style.display='none';return;}
    infoBanner.className='info-banner'+(type==='error'?' error':type==='success'?' success':'');
    infoBanner.innerHTML='<i class="bi bi-'+(type==='error'?'exclamation-triangle':type==='success'?'check-circle':'info-circle')+'"></i><span>'+msg+'</span>';
    infoBanner.style.display='flex';
}

function iconFor(cls){
    switch(cls){
        case'inbound':  return['bi-telephone-inbound-fill','var(--in-color)'];
        case'outbound': return['bi-telephone-outbound-fill','var(--out-color)'];
        case'missed':   return['bi-telephone-x-fill','var(--missed-color)'];
        default:        return['bi-telephone-fill','var(--unk-color)'];
    }
}

function esc(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

function renderItems(items,append){
    const old=$$('loadSentinel');
    if(old) old.remove();
    if(!append){
        audioContainer.querySelectorAll('.call-item').forEach(n=>n.remove());
        if(emptyState) emptyState.style.display='none';
    }
    let html='';
    for(const it of items){
        const[icon,color]=iconFor(it.directionClass);
        const dh=it.date?'<div class="call-meta"><i class="bi bi-clock" style="font-size:10px;"></i>'+esc(it.date)+'</div>':'';
        // data-src = URL directe Apache (lecture instantanée)
        // data-dl  = URL download.php (téléchargement sécurisé)
        html+='<div class="call-item" data-src="'+esc(it.audioUrl)+'" data-dl="'+esc(it.dlUrl)+'" data-direction="'+esc(it.direction)+'" data-search="'+esc(it.search)+'">'
            +'<div class="call-row1"><div class="call-icon-num"><i class="bi '+icon+'" style="color:'+color+'"></i>'
            +'<span class="call-numbers">'+esc(it.a)+'<span class="arrow">→</span>'+esc(it.b)+'</span></div>'
            +'<span class="dir-badge '+esc(it.directionClass)+'">'+esc(it.direction)+'</span></div>'+dh+'</div>';
    }
    if(state.hasMore) html+='<div class="load-sentinel" id="loadSentinel"><span class="spinner"></span> Chargement…</div>';
    audioContainer.insertAdjacentHTML('beforeend',html);
    if(state.hasMore) observeSentinel();
    applyFilter();
}

function renderFilters(stats,total){
    if(!stats){filtersEl.style.display='none';return;}
    let h='<button type="button" class="qf-btn active" data-filter="all"><i class="bi bi-collection"></i> Tous <span class="qf-count">'+total+'</span></button>';
    if(stats.inbound>0)  h+='<button type="button" class="qf-btn" data-filter="Entrant"><i class="bi bi-telephone-inbound-fill" style="color:var(--in-color)"></i> Entrants <span class="qf-count">'+stats.inbound+'</span></button>';
    if(stats.outbound>0) h+='<button type="button" class="qf-btn" data-filter="Sortant"><i class="bi bi-telephone-outbound-fill" style="color:var(--out-color)"></i> Sortants <span class="qf-count">'+stats.outbound+'</span></button>';
    if(stats.missed>0)   h+='<button type="button" class="qf-btn" data-filter="Manqué"><i class="bi bi-telephone-x-fill" style="color:var(--missed-color)"></i> Manqués <span class="qf-count">'+stats.missed+'</span></button>';
    filtersEl.innerHTML=h;
    filtersEl.style.display='flex';
    filtersEl.querySelectorAll('.qf-btn').forEach(btn=>{
        btn.addEventListener('click',function(){
            filtersEl.querySelectorAll('.qf-btn').forEach(b=>b.classList.remove('active'));
            this.classList.add('active');
            state.activeFilter=this.dataset.filter;
            applyFilter();
        });
    });
}

function applyFilter(){
    const items=audioContainer.querySelectorAll('.call-item');
    let v=0;
    items.forEach(item=>{
        const ok=(state.activeFilter==='all'||item.dataset.direction===state.activeFilter);
        item.classList.toggle('hidden',!ok);
        if(ok) v++;
    });
    countEl.textContent=state.activeFilter!=='all'?v:state.total;
}

async function loadPage(reset){
    if(state.loading) return;
    state.loading=true;
    if(reset){state.offset=0;state.total=0;state.requestId++;overlay.classList.add('active');}
    const rid=state.requestId;
    const btn=$$('searchBtn');
    const orig=btn.innerHTML;
    if(reset){btn.disabled=true;btn.innerHTML='<span class="spinner" style="width:14px;height:14px;border-color:rgba(255,255,255,.4);border-top-color:#fff"></span> Recherche...';}
    const t0=performance.now();
    try{
        const r=await fetch('index.php?'+new URLSearchParams({ajax:'load_calls',date:state.date,q:state.q,tag:state.tag,offset:state.offset,limit:PAGE_SIZE,detect_missed:state.detectMissed?1:0}),{credentials:'same-origin'});
        const data=await r.json();
        if(rid!==state.requestId) return;
        const elapsed=Math.round(performance.now()-t0);
        if(data.error){
            showBanner(data.error,'error');
            countEl.textContent='0';filtersEl.style.display='none';folderEl.style.display='none';
            audioContainer.querySelectorAll('.call-item').forEach(n=>n.remove());
            const s=$$('loadSentinel');if(s)s.remove();
            if(emptyState) emptyState.style.display='block';
            return;
        }
        showBanner('');
        state.total=data.total;state.hasMore=data.hasMore;state.offset+=data.items.length;
        if(reset){
            if(data.folder){folderEl.innerHTML='<i class="bi bi-folder2-open"></i> '+esc(data.folder);folderEl.style.display='flex';}
            renderFilters(data.stats,data.total);
            perfMs.textContent=elapsed;perfTag.style.display='inline-flex';
        }
        if(data.items.length===0&&reset){
            audioContainer.querySelectorAll('.call-item').forEach(n=>n.remove());
            if(emptyState){emptyState.style.display='block';emptyState.innerHTML='<i class="bi bi-inbox"></i><div style="font-weight:500;margin-bottom:4px;">Aucun appel</div><div style="font-size:12px;">Aucun fichier correspondant.</div>';}
            countEl.textContent='0';perfTag.style.display='none';
        }else{
            renderItems(data.items,!reset);
            countEl.textContent=state.total;
        }
    }catch(e){console.error(e);showBanner('Erreur de chargement','error');}
    finally{
        state.loading=false;
        if(reset){btn.disabled=false;btn.innerHTML=orig;overlay.classList.remove('active');}
    }
}

let observer;
function observeSentinel(){
    const s=$$('loadSentinel');
    if(!s) return;
    if(!observer){
        observer=new IntersectionObserver(entries=>{
            for(const e of entries){
                if(e.isIntersecting&&state.hasMore&&!state.loading){
                    const old=$$('loadSentinel');if(old)old.remove();
                    loadPage(false);
                }
            }
        },{root:audioContainer,rootMargin:'400px'});
    }
    observer.observe(s);
}

function triggerSearch(){
    state.date=$$('dateSearch').value;
    state.tag=$$('tagSelectSearch').value;
    state.q=$$('serverSearch').value.trim();
    state.activeFilter='all';
    state.detectMissed=$$('optDetectMissed').checked;
    if(!state.date){showBanner('Veuillez choisir une date','error');return;}
    loadPage(true);
}

function clearSearch(){
    $$('serverSearch').value='';
    try{$('#tagSelectSearch').val('').trigger('change.select2');}catch(e){}
    state.activeFilter='all';
    audioContainer.querySelectorAll('.call-item').forEach(n=>n.remove());
    filtersEl.style.display='none';folderEl.style.display='none';
    perfTag.style.display='none';countEl.textContent='0';
    if(emptyState){emptyState.style.display='block';emptyState.innerHTML='<i class="bi bi-search"></i><div style="font-weight:500;margin-bottom:4px;">Prêt à rechercher</div><div style="font-size:12px;">Choisis une date puis clique sur <b>Rechercher</b>.</div>';}
    showBanner('');
}

function whenReady(cb){
    if(typeof jQuery!=='undefined'&&typeof WaveSurfer!=='undefined') cb();
    else setTimeout(()=>whenReady(cb),30);
}

document.addEventListener('DOMContentLoaded',function(){
    $$('searchBtn').addEventListener('click',triggerSearch);
    $$('clearBtn').addEventListener('click',clearSearch);
    $$('searchForm').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();triggerSearch();}});

    whenReady(function(){
        function fmt(s){
            if(s==null||isNaN(s)) return'00:00';
            return String(Math.floor(s/60)).padStart(2,'0')+':'+String(Math.floor(s%60)).padStart(2,'0');
        }
        const seek=$$('seek');
        let isSeeking=false,autoplay=false;
        const theme=document.documentElement.getAttribute('data-theme')||'light';

        const wavesurfer=WaveSurfer.create({
            container:'#waveform',
            waveColor:theme==='dark'?'rgba(255,255,255,.12)':'rgba(15,23,42,.12)',
            progressColor:'#3b82f6',
            cursorColor:'#6366f1',
            height:196,
            responsive:true,
            normalize:true,
            interact:true,
            dragToSeek:true,
            barWidth:2,barGap:2,barRadius:2,
            backend:'MediaElement'  // streaming instantané
        });
        window.wavesurfer=wavesurfer;

        function setUI(p){
            if(p){$('#play').addClass('d-none');$('#paus').removeClass('d-none');}
            else{$('#paus').addClass('d-none');$('#play').removeClass('d-none');}
        }

        wavesurfer.on('ready',function(){
            $('#empty-wave').hide();
            $('#duration').text(fmt(wavesurfer.getDuration()));
            seek.disabled=false;seek.value='0';
            if(autoplay){autoplay=false;wavesurfer.play().catch(()=>{});}
        });
        wavesurfer.on('audioprocess',function(){
            const cur=wavesurfer.getCurrentTime(),dur=wavesurfer.getDuration();
            $('#current-time').text(fmt(cur));
            if(!isSeeking&&dur>0) seek.value=(cur/dur).toString();
        });
        wavesurfer.on('play',()=>setUI(true));
        wavesurfer.on('pause',()=>setUI(false));
        wavesurfer.on('finish',()=>setUI(false));
        wavesurfer.on('error',()=>$('#empty-wave').show().find('span').first().text('Erreur audio'));

        seek.addEventListener('mousedown',()=>isSeeking=true);
        seek.addEventListener('touchstart',()=>isSeeking=true,{passive:true});
        seek.addEventListener('input',()=>wavesurfer.seekTo(parseFloat(seek.value||'0')));
        seek.addEventListener('mouseup',()=>isSeeking=false);
        seek.addEventListener('touchend',()=>isSeeking=false);

        $(document).on('click','.PalyPaus',function(){if(wavesurfer&&wavesurfer.getDuration()!=0)wavesurfer.playPause();});
        $('#fastforward').on('click',()=>{if(wavesurfer&&wavesurfer.getDuration())wavesurfer.skip(10);});
        $('#fastbackward').on('click',()=>{if(wavesurfer&&wavesurfer.getDuration())wavesurfer.skip(-10);});
        $('#stopBtn').on('click',function(){wavesurfer.stop();setUI(false);$('#current-time').text('00:00');seek.value='0';});

        const speeds=[1,1.25,1.5,2,0.75];let si=0;
        $('#speedBtn').on('click',function(){si=(si+1)%speeds.length;wavesurfer.setPlaybackRate(speeds[si]);$(this).text(speeds[si]+'x');});

        $('#download-btn').on('click',function(e){if(!$(this).attr('href'))e.preventDefault();});

        audioContainer.addEventListener('click',function(e){
            const item=e.target.closest('.call-item');
            if(!item) return;
            audioContainer.querySelectorAll('.call-item').forEach(n=>n.classList.remove('active'));
            item.classList.add('active');

            // Téléchargement = via download.php (sécurisé)
            $('#download-btn').attr('href', item.dataset.dl || '');

            seek.value='0';seek.disabled=true;
            setUI(false);
            $('#current-time').text('00:00');$('#duration').text('00:00');
            $('#empty-wave').hide();
            autoplay=true;

            // Lecture = URL directe Apache (instantanée)
            wavesurfer.load(item.dataset.src);
        });

        document.addEventListener('keydown',function(e){
            if(['INPUT','TEXTAREA','SELECT'].includes(e.target.tagName)) return;
            if(e.code==='Space'){e.preventDefault();if(wavesurfer.getDuration())wavesurfer.playPause();}
            else if(e.code==='ArrowRight'){if(wavesurfer.getDuration())wavesurfer.skip(5);}
            else if(e.code==='ArrowLeft'){if(wavesurfer.getDuration())wavesurfer.skip(-5);}
        });

        if($.fn.select2){
            $('#tagSelectSearch').select2({width:'100%',placeholder:'Choisir un tag',allowClear:true});
            <?php if($isAdmin): ?>
            const $panel=$('#admin-panel');
            $('#admin_agent_select').select2({width:'100%',placeholder:'Rechercher un agent…',dropdownParent:$panel});
            $('#admin_tag_select').select2({width:'100%',placeholder:'Rechercher un tag…',dropdownParent:$panel});
            $(document).on('select2:open',function(){
                const f=document.querySelector('.select2-container--open .select2-search__field');
                if(f) f.focus();
            });
            $('#admin_agent_select').on('select2:select',function(){
                const v=parseInt($(this).val()||'0',10);
                if(!v) return;
                window.location.href='index.php?admin_agent_id='+v+'#admin-panel';
            });
            $('#admin_agent_select').on('change',function(){$('#admin_agent_id_hidden').val($(this).val()||'0');});
            <?php endif; ?>
        }
    });
});
</script>
</body>
</html>
