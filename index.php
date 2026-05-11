<?php
session_start();
require_once('database.php');

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function starts_with($haystack, $needle) { return strncmp($haystack, $needle, strlen($needle)) === 0; }

function audio_base_dir(): string {
    $dir = getenv('AUDIO_BASE_DIR') ?: (__DIR__ . '/audio_mails');
    $real = realpath($dir);
    return $real ?: $dir;
}

function is_audio_file(string $file): bool {
    return (bool)preg_match('/\.(mp3|wav|m4a|ogg|flac|aac)$/i', $file);
}

function parse_audio_meta(string $filename): array {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $parts = explode('_', $name);
    $a = $parts[0] ?? '';
    $b = $parts[1] ?? '';
    $dateRaw = '';
    $dateHuman = '';

    if (preg_match('/(20\d{2}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2})/', $name, $m)) {
        $dateRaw = $m[1];
        $dt = DateTime::createFromFormat('Y-m-d-H-i-s', $dateRaw);
        if ($dt) $dateHuman = $dt->format('Y-m-d H:i:s');
    }

    return ['a' => $a, 'b' => $b, 'date' => $dateHuman];
}

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId <= 0) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$me = Database::selectParams(
    "SELECT id, is_admin, COALESCE(can_download,0) AS can_download FROM agent WHERE id = :id LIMIT 1",
    [':id' => $userId],
    PDO::FETCH_ASSOC
);

if (!empty($me)) {
    $_SESSION['user']['is_admin'] = (int)$me[0]['is_admin'];
    $_SESSION['user']['can_download'] = (int)$me[0]['can_download'];
}

$isAdmin = !empty($_SESSION['user']['is_admin']) && (int)$_SESSION['user']['is_admin'] === 1;
$canDownload = $isAdmin || (!empty($_SESSION['user']['can_download']) && (int)$_SESSION['user']['can_download'] === 1);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$adminSelectedAgentId = ($isAdmin) ? (int)($_GET['admin_agent_id'] ?? 0) : 0;

$baseDir = audio_base_dir();
$searchText = '';
$selectedTag = '';
$selectedDate = '';
$filteredFiles = [];
$totalFound = 0;
$folderName = '';
$infoBanner = '';
$searchDir = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type'])) {

    if ($_POST['form_type'] === 'admin_assign') {
        if (!$isAdmin) { http_response_code(403); die("Accès refusé (admin requis)."); }
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
        header("Location: index.php?admin_agent_id=".$targetAgentId."#admin-panel");
        exit();
    }

    if ($_POST['form_type'] === 'admin_remove') {
        if (!$isAdmin) { http_response_code(403); die("Accès refusé (admin requis)."); }
        if (empty($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) { http_response_code(400); die("CSRF invalide."); }

        $targetAgentId = (int)($_POST['agent_id'] ?? 0);
        $tagId = (int)($_POST['tag_id'] ?? 0);
        if ($targetAgentId <= 0 || $tagId <= 0) { http_response_code(400); die("Paramètres invalides."); }

        Database::executeParams("DELETE FROM tag_agent_lisent WHERE id_agent = :aid AND id_tag = :tid", [':aid' => $targetAgentId, ':tid' => $tagId]);
        header("Location: index.php?admin_agent_id=".$targetAgentId."#admin-panel");
        exit();
    }

    if ($_POST['form_type'] === 'admin_set_download') {
        if (!$isAdmin) { http_response_code(403); die("Accès refusé (admin requis)."); }
        if (empty($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) { http_response_code(400); die("CSRF invalide."); }

        $targetAgentId = (int)($_POST['agent_id'] ?? 0);
        $val = ((int)($_POST['can_download'] ?? 0) === 1) ? 1 : 0;
        if ($targetAgentId <= 0) { http_response_code(400); die("Paramètres invalides."); }

        Database::executeParams("UPDATE agent SET can_download = :v WHERE id = :id", [':v' => $val, ':id' => $targetAgentId]);
        header("Location: index.php?admin_agent_id=".$targetAgentId."#admin-panel");
        exit();
    }

    if ($_POST['form_type'] === 'audio_search') {
        $searchText = strtolower(trim((string)($_POST['textSearch'] ?? '')));
        $selectedTag = strtolower(trim((string)($_POST['tagSelect'] ?? '')));
        $selectedDate = trim((string)($_POST['dateSearch'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
            $infoBanner = "Date invalide.";
        } else {
            $folderName = $selectedDate;
            $searchDir = rtrim($baseDir, '/') . '/' . $selectedDate;

            if (!is_dir($searchDir)) {
                $infoBanner = "Aucun dossier trouvé pour cette date dans le NAS.";
            } else {
                $files = scandir($searchDir) ?: [];
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') continue;
                    $full = $searchDir . '/' . $file;
                    if (!is_file($full) || !is_audio_file($file)) continue;

                    $fileLower = strtolower($file);
                    if ($selectedTag !== '' && strpos($fileLower, $selectedTag) === false) continue;
                    if ($searchText !== '' && strpos($fileLower, $searchText) === false) continue;

                    $relative = $selectedDate . '/' . $file;
                    $filteredFiles[] = [
                        'relative' => $relative,
                        'filename' => $file,
                        'mtime' => filemtime($full) ?: 0,
                    ];
                }

                usort($filteredFiles, function($a, $b) {
                    return strcmp($a['filename'], $b['filename']);
                });

                $totalFound = count($filteredFiles);
                if ($totalFound === 0) {
                    $infoBanner = "Aucun fichier trouvé pour ce tag/numéro à cette date.";
                }
            }
        }
    }
}

$tags = Database::selectParams(
    "SELECT t.id, t.numero
     FROM `tag` t
     INNER JOIN `tag_agent_lisent` ta ON t.id = ta.id_tag
     WHERE ta.id_agent = :uid
     ORDER BY t.numero",
    [':uid' => $userId],
    PDO::FETCH_ASSOC
) ?: [];

$agentsAll = [];
$tagsAll = [];
$agentTags = [];
$selectedAgentCanDownload = 0;

if ($isAdmin) {
    $agentsAll = Database::selectParams("SELECT id, username, nom, prenom, COALESCE(can_download,0) AS can_download FROM agent ORDER BY username", [], PDO::FETCH_ASSOC) ?: [];
    $tagsAll = Database::selectParams("SELECT id, numero FROM tag ORDER BY numero", [], PDO::FETCH_ASSOC) ?: [];

    if ($adminSelectedAgentId > 0) {
        $agentTags = Database::selectParams(
            "SELECT t.id, t.numero FROM tag t INNER JOIN tag_agent_lisent ta ON ta.id_tag = t.id WHERE ta.id_agent = :aid ORDER BY t.numero",
            [':aid' => $adminSelectedAgentId],
            PDO::FETCH_ASSOC
        ) ?: [];
        foreach ($agentsAll as $a) {
            if ((int)$a['id'] === $adminSelectedAgentId) {
                $selectedAgentCanDownload = (int)$a['can_download'];
                break;
            }
        }
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kyntus Morocco Enrg</title>
  <link href="assets/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/dist/css/style.css" rel="stylesheet">
  <link href="assets/dashboard.css" rel="stylesheet">
  <link href="assets/dist/css/dataTables.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <style>
    :root { --cardRadius: 14px; }
    body { background:#f5f7fb; }
    .select2-container { width: 100% !important; }
    .select2-container--default .select2-selection--single { height: 40px !important; border-radius: 10px; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 38px !important; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px !important; }
    .select2-container--open { z-index: 9999; }
    .card-soft { border: 0; border-radius: var(--cardRadius); box-shadow: 0 10px 30px rgba(16, 24, 40, 0.06); }
    .card-header-soft{ background: #fff; border-bottom: 1px solid rgba(0,0,0,.06); border-top-left-radius: var(--cardRadius); border-top-right-radius: var(--cardRadius); }
    .sticky-side { position: sticky; top: 84px; }
    .list-scroll { max-height: 64vh; overflow:auto; }
    .listen.active { background: rgba(13,110,253,.08); border-left: 4px solid #0d6efd; }
    .listen.preloading .play-icon { opacity:.45; }
    .player-shell{ border: 2px dashed rgba(13,110,253,.15); border-radius: 14px; background: linear-gradient(180deg, #ffffff, #fbfcff); min-height: 220px; position: relative; overflow: hidden; }
    .waveform-container { background: #f8f9fa; border-radius: 8px; padding: 10px; margin-bottom: 12px; }
    .time-display { display:flex; justify-content:space-between; align-items:center; font-size:12px; color:#666; margin-top:6px; font-weight:500; }
    .current-time { font-size:14px; font-weight:600; color:#0d6efd; }
    .total-duration { font-size:14px; font-weight:500; color:#999; }
    .player-controls .btn, .btn-download { border:0; background:#fff; box-shadow:0 10px 24px rgba(16,24,40,.10); border-radius:999px; padding:12px 14px; display:inline-flex; align-items:center; justify-content:center; }
    .btn-download.disabled { opacity:.45; pointer-events:none; }
    #empty-wave { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; text-align:center; color:#6c757d; padding:16px; pointer-events:none; background:transparent; }
  </style>
</head>
<body>
<header class="navbar navbar-dark sticky-top bg-dark shadow" style="height:64px;">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="#">Kyntus Morocco Enrg</a>
    <div class="d-flex align-items-center gap-3">
      <?php if ($isAdmin): ?><span class="badge bg-success">Admin</span><?php endif; ?>
      <a class="text-white-50 text-decoration-none" href="logout.php">déconnecté</a>
    </div>
  </div>
</header>

<div class="container-fluid my-4">
  <div class="row g-3">
    <div class="col-12">
      <div class="card card-soft">
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="form_type" value="audio_search">
            <div class="row g-2 align-items-end">
              <div class="col-lg-4">
                <label class="form-label mb-1">Tag</label>
                <select id="tagSelectSearch" name="tagSelect" class="form-control">
                  <option value="">Tous les tags</option>
                  <?php foreach ($tags as $tag): ?>
                    <?php $sel = ($selectedTag === strtolower((string)$tag['numero'])) ? 'selected' : ''; ?>
                    <option <?= $sel ?> value="<?= h($tag['numero']); ?>"><?= h($tag['numero']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-lg-4">
                <label class="form-label mb-1">2e Numéro <span class="text-muted small">optionnel</span></label>
                <input type="text" name="textSearch" class="form-control" placeholder="Ex: 0612345678" value="<?= h($searchText); ?>">
              </div>
              <div class="col-lg-3">
                <label class="form-label mb-1">Date</label>
                <input type="date" name="dateSearch" class="form-control" value="<?= h($selectedDate); ?>" required>
              </div>
              <div class="col-lg-1 d-grid">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
              </div>
            </div>
            <?php if (!empty($infoBanner)): ?>
              <div class="alert alert-light border mt-3 mb-0"><i class="bi bi-info-circle me-1"></i><?= $infoBanner ?></div>
            <?php endif; ?>
            <div class="text-muted small mt-2">Lecture depuis le NAS: <?= h($baseDir) ?></div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-3">
      <div class="card card-soft sticky-side">
        <div class="card-header card-header-soft d-flex justify-content-between align-items-center">
          <div class="d-flex align-items-center gap-2"><i class="bi bi-list-ul"></i><strong>Liste</strong></div>
          <span class="badge bg-primary rounded-pill" id="count_audio"><?= (int)$totalFound ?></span>
        </div>
        <div class="card-body p-0">
          <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'audio_search' && !empty($filteredFiles)): ?>
            <div class="px-3 pt-3 pb-2 text-muted small"><i class="bi bi-folder2-open me-1"></i><?= h($folderName) ?></div>
            <ul class="list-group list-group-flush list-scroll" id="audioContainer">
              <?php foreach ($filteredFiles as $item): ?>
                <?php
                  $relative = $item['relative'];
                  $file = $item['filename'];
                  $sig = hash_hmac('sha256', $relative, $_SESSION['csrf_token']);
                  $streamUrl = "download.php?f=" . rawurlencode($relative) . "&sig=" . $sig;
                  $downloadUrl = $streamUrl . "&dl=1";
                  $meta = parse_audio_meta($file);
                ?>
                <li class="list-group-item listen" data-src="<?= h($streamUrl) ?>" data-dl="<?= h($downloadUrl) ?>" style="cursor:pointer;">
                  <div class="d-flex gap-3 align-items-start">
                    <div class="pt-1"><i class="bi bi-play-circle-fill text-primary play-icon" style="font-size:28px;"></i></div>
                    <div class="flex-grow-1">
                      <div class="fw-semibold"><?= h($meta['a']) ?> <i class="bi bi-arrow-right"></i> <?= h($meta['b']) ?></div>
                      <div class="text-muted small mt-1"><i class="bi bi-clock me-1"></i><?= h($meta['date'] ?: $file) ?></div>
                    </div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="p-4 text-center text-muted"><div class="fw-semibold">Aucun fichier trouvé</div><div class="small">Fais une recherche.</div></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-<?= $isAdmin ? '6' : '9' ?>">
      <div class="card card-soft">
        <div class="card-header card-header-soft d-flex justify-content-between align-items-center">
          <div class="d-flex align-items-center gap-2"><i class="bi bi-soundwave"></i><strong>Lecteur</strong></div>
          <?php if ($canDownload): ?><span class="badge bg-success">Téléchargement autorisé</span><?php else: ?><span class="badge bg-secondary">Téléchargement non autorisé</span><?php endif; ?>
        </div>
        <div class="card-body">
          <div class="waveform-container">
            <div id="waveform" class="player-shell"><div class="text-center" id="empty-wave"><div class="fw-semibold">Aucun audio chargé</div><div class="small">Clique sur un élément dans la liste.</div></div></div>
            <input id="seek" type="range" class="form-range mt-2" min="0" max="1" value="0" step="0.001" disabled>
            <div class="time-display"><span class="current-time" id="current-time">00:00</span><span class="total-duration" id="duration">00:00</span></div>
          </div>
          <div class="d-flex justify-content-center align-items-center gap-3 mt-4 player-controls">
            <button type="button" id="stopBtn" class="btn" title="Stop"><i class="bi bi-stop-fill text-primary" style="font-size:20px;"></i></button>
            <button type="button" id="fastbackward" class="btn" title="-10s"><i class="bi bi-skip-backward-fill text-primary" style="font-size:20px;"></i></button>
            <button type="button" id="play" class="btn PalyPaus" title="Play"><i class="bi bi-play-fill text-primary" style="font-size:20px;"></i></button>
            <button type="button" id="paus" class="btn d-none PalyPaus" title="Pause"><i class="bi bi-pause-fill text-primary" style="font-size:20px;"></i></button>
            <button type="button" id="fastforward" class="btn" title="+10s"><i class="bi bi-skip-forward-fill text-primary" style="font-size:20px;"></i></button>
            <?php if ($canDownload): ?><a id="download-btn" href="" download class="btn-download" title="Télécharger"><i class="bi bi-download text-primary" style="font-size:20px;"></i></a><?php else: ?><span class="btn-download disabled" title="Téléchargement non autorisé"><i class="bi bi-download text-primary" style="font-size:20px;"></i></span><?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <?php if ($isAdmin): ?>
    <div class="col-12 col-lg-3">
      <div class="card card-soft sticky-side" id="admin-panel">
        <div class="card-header card-header-soft d-flex justify-content-between align-items-center"><strong>Administration</strong><span class="badge bg-success">Admin</span></div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label mb-1">Agent</label>
            <select id="admin_agent_select" class="form-control">
              <option value="0">-- choisir --</option>
              <?php foreach ($agentsAll as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= ($adminSelectedAgentId === (int)$a['id']) ? 'selected' : '' ?>><?= h($a['username'].' — '.trim(($a['nom'] ?? '').' '.($a['prenom'] ?? ''))) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="text-muted small mt-1">Sélection = affiche tags + droits.</div>
          </div>
          <?php if ($adminSelectedAgentId > 0): ?>
          <form method="POST" class="mb-3">
            <input type="hidden" name="form_type" value="admin_set_download"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="agent_id" value="<?= (int)$adminSelectedAgentId ?>"><input type="hidden" name="can_download" value="<?= $selectedAgentCanDownload ? 0 : 1 ?>">
            <div class="d-flex justify-content-between align-items-center mb-2"><strong>Téléchargement</strong><?php if ($selectedAgentCanDownload): ?><span class="badge bg-success">Autorisé</span><?php else: ?><span class="badge bg-secondary">Interdit</span><?php endif; ?></div>
            <button class="btn <?= $selectedAgentCanDownload ? 'btn-outline-danger' : 'btn-outline-success' ?> w-100" type="submit"><?= $selectedAgentCanDownload ? 'Retirer le droit' : 'Autoriser le téléchargement' ?></button>
          </form>
          <?php endif; ?>
          <form method="POST" class="mb-3">
            <input type="hidden" name="form_type" value="admin_assign"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="agent_id" id="admin_agent_id_hidden" value="<?= (int)$adminSelectedAgentId ?>">
            <label class="form-label mb-1">Attribuer un tag</label>
            <select id="admin_tag_select" name="tag_id" class="form-control" required><?php foreach ($tagsAll as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['numero']) ?></option><?php endforeach; ?></select>
            <button class="btn btn-primary w-100 mt-2" type="submit" <?= ($adminSelectedAgentId <= 0) ? 'disabled' : '' ?>>Attribuer</button>
          </form>
          <div class="border rounded-3 p-2" style="max-height:240px; overflow:auto;">
            <?php if ($adminSelectedAgentId <= 0): ?><div class="text-muted small">Choisis un agent.</div>
            <?php elseif (empty($agentTags)): ?><div class="text-muted small">Aucun tag.</div>
            <?php else: ?><div class="list-group"><?php foreach ($agentTags as $t): ?><div class="list-group-item d-flex justify-content-between align-items-center"><span><?= h($t['numero']) ?></span><form method="POST" class="m-0"><input type="hidden" name="form_type" value="admin_remove"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="agent_id" value="<?= (int)$adminSelectedAgentId ?>"><input type="hidden" name="tag_id" value="<?= (int)$t['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-x"></i></button></form></div><?php endforeach; ?></div><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/wavesurfer.js@6.6.4/dist/wavesurfer.min.js"></script>
<script>
function formatTime(seconds) { if (seconds == null || isNaN(seconds)) return '00:00'; const mins = Math.floor(seconds / 60); const secs = Math.floor(seconds % 60); return `${String(mins).padStart(2,'0')}:${String(secs).padStart(2,'0')}`; }
const seek = document.getElementById('seek');
let isSeeking = false;
const wavesurfer = WaveSurfer.create({ container:'#waveform', waveColor:'#cfd6e4', progressColor:'#0d6efd', cursorColor:'#0d6efd', height:200, responsive:true, normalize:true, interact:true, dragToSeek:true, xhr:{ credentials:'same-origin' } });
function setPlayingUI(isPlaying) { if (isPlaying) { $('#play').addClass('d-none'); $('#paus').removeClass('d-none'); } else { $('#paus').addClass('d-none'); $('#play').removeClass('d-none'); } }
wavesurfer.on('ready', function() { $('#empty-wave').hide(); const dur=wavesurfer.getDuration(); $('#duration').text(formatTime(dur)); $('#current-time').text('00:00'); seek.disabled=false; seek.value='0'; });
wavesurfer.on('audioprocess', function() { const cur=wavesurfer.getCurrentTime(); const dur=wavesurfer.getDuration(); $('#current-time').text(formatTime(cur)); if (!isSeeking && dur > 0) seek.value=(cur/dur).toString(); });
wavesurfer.on('error', function(e) { console.error(e); alert('Impossible de lire cet audio. Vérifie download.php dans Network.'); });
wavesurfer.on('play', () => setPlayingUI(true)); wavesurfer.on('pause', () => setPlayingUI(false)); wavesurfer.on('finish', () => setPlayingUI(false));
seek.addEventListener('mousedown', () => isSeeking=true); seek.addEventListener('touchstart', () => isSeeking=true, {passive:true}); seek.addEventListener('input', () => wavesurfer.seekTo(parseFloat(seek.value || '0'))); seek.addEventListener('mouseup', () => isSeeking=false); seek.addEventListener('touchend', () => isSeeking=false);
$(document).on('click', '.PalyPaus', function(){ if (wavesurfer && wavesurfer.getDuration() != 0) wavesurfer.playPause(); });
$('#fastforward').on('click', () => { if (wavesurfer && wavesurfer.getDuration()!=0) wavesurfer.skip(10); });
$('#fastbackward').on('click', () => { if (wavesurfer && wavesurfer.getDuration()!=0) wavesurfer.skip(-10); });
$(document).on('click', '#stopBtn', function(){ wavesurfer.stop(); setPlayingUI(false); $('#current-time').text('00:00'); seek.value='0'; });
$(document).on('click', '#download-btn', function(event){ if (!$(this).attr('href')) event.preventDefault(); });
$(document).on('click', '.listen', function(){ const src=$(this).attr('data-src'); if(!src) return; $('.listen').removeClass('active preloading'); $(this).addClass('active preloading'); const dl=$(this).attr('data-dl') || ''; $('#download-btn').attr('href', dl); seek.value='0'; seek.disabled=true; setPlayingUI(false); $('#current-time').text('00:00'); $('#duration').text('00:00'); $('#empty-wave').hide(); wavesurfer.load(src); const $item=$(this); wavesurfer.once('ready', () => $item.removeClass('preloading')); });
$(function(){ if ($.fn.select2) { $('#tagSelectSearch').select2({ width:'100%', placeholder:'Choisir un tag', allowClear:true, minimumResultsForSearch:0 }); <?php if ($isAdmin): ?> const $adminPanel=$('#admin-panel'); $('#admin_agent_select').select2({ width:'100%', placeholder:'Rechercher un agent...', minimumResultsForSearch:0, dropdownParent:$adminPanel }); $('#admin_tag_select').select2({ width:'100%', placeholder:'Rechercher un tag...', minimumResultsForSearch:0, dropdownParent:$adminPanel }); $(document).on('select2:open', function(){ const field=document.querySelector('.select2-container--open .select2-search__field'); if(field) field.focus(); }); $('#admin_agent_select').on('select2:select', function(){ const v=parseInt($(this).val() || '0',10); if(!v) return; window.location.href='index.php?admin_agent_id='+v+'#admin-panel'; }); $('#admin_agent_select').on('change', function(){ $('#admin_agent_id_hidden').val($(this).val() || '0'); }); $('#admin_agent_id_hidden').val('<?= (int)$adminSelectedAgentId ?>'); <?php endif; ?> } });
</script>
<script src="assets/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
