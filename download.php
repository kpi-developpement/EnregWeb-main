<?php
session_start();
if (isset($_SESSION['role']) && isset($_SESSION['user'])) {
    header('Location: index.php');
    exit();
}
$divError = 0;
if (isset($_POST['username']) && isset($_POST['password'])) {
    require_once 'database.php';
    $result = Database::auth($_POST['username'], $_POST['password']);
    if ($result) {
        session_regenerate_id(true);
        $_SESSION['user'] = $result;
        $_SESSION['role'] = (!empty($result['is_admin']) && (int)$result['is_admin'] === 1) ? 'admin' : 'user';
        header('Location: index.php');
        exit();
    } else {
        $divError = 1;
    }
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kyntus Morocco — Connexion</title>

    <script>
        (function(){ try { var t = localStorage.getItem('kyntus-theme') || 'light'; document.documentElement.setAttribute('data-theme', t); } catch(e){} })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">

    <style>
        :root, [data-theme="light"] {
            --bg: #f5f7fb;
            --surface: #ffffff;
            --border: rgba(15,23,42,.08);
            --border2: rgba(15,23,42,.14);
            --accent: #2563eb;
            --accent2: #4f46e5;
            --text: #1e293b;
            --text-strong: #0f172a;
            --muted: #64748b;
            --input-bg: #ffffff;
            --input-bg-focus: #f8fafc;
            --error-color: #dc2626;
            --error-bg: rgba(239,68,68,.08);
            --error-border: rgba(239,68,68,.3);
            --topbar-bg: rgba(255,255,255,.95);
            --bg-grad-1: radial-gradient(ellipse 70% 50% at 0% 0%, rgba(37,99,235,.07) 0%, transparent 55%);
            --bg-grad-2: radial-gradient(ellipse 50% 40% at 100% 100%, rgba(79,70,229,.06) 0%, transparent 55%);
        }
        [data-theme="dark"] {
            --bg: #0d0f14;
            --surface: #161922;
            --border: rgba(255,255,255,.07);
            --border2: rgba(255,255,255,.12);
            --accent: #3b82f6;
            --accent2: #6366f1;
            --text: #e2e5ef;
            --text-strong: #ffffff;
            --muted: #6b7280;
            --input-bg: rgba(255,255,255,.04);
            --input-bg-focus: rgba(255,255,255,.06);
            --error-color: #ef4444;
            --error-bg: rgba(239,68,68,.1);
            --error-border: rgba(239,68,68,.3);
            --topbar-bg: rgba(13,15,20,.92);
            --bg-grad-1: radial-gradient(ellipse 70% 50% at 0% 0%, rgba(59,130,246,.12) 0%, transparent 55%);
            --bg-grad-2: radial-gradient(ellipse 50% 40% at 100% 100%, rgba(99,102,241,.1) 0%, transparent 55%);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html, body {
            height: 100%;
            background: var(--bg);
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            transition: background .25s ease, color .25s ease;
        }

        body::before {
            content: '';
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background: var(--bg-grad-1), var(--bg-grad-2);
        }

        /* ── TOPBAR ── */
        .topbar {
            position: fixed; top: 0; left: 0; right: 0; z-index: 100;
            height: 60px;
            background: var(--topbar-bg);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center;
            padding: 0 24px; gap: 16px;
        }

        .topbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; color: var(--text); }
        .topbar-icon {
            width: 34px; height: 34px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 14px rgba(59,130,246,.3);
        }
        .topbar-icon svg { width: 16px; height: 16px; fill: #fff; }
        .topbar-name {
            font-family: 'Syne', sans-serif;
            font-size: 16px; font-weight: 700;
            color: var(--text-strong);
            letter-spacing: -.3px;
        }
        .topbar-spacer { flex: 1; }

        .theme-toggle {
            width: 38px; height: 38px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 9px;
            color: var(--text);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 16px;
            transition: transform .2s;
        }
        .theme-toggle:hover { transform: rotate(15deg); }
        [data-theme="light"] .theme-toggle .bi-moon-stars { display: inline-block; }
        [data-theme="light"] .theme-toggle .bi-sun { display: none; }
        [data-theme="dark"]  .theme-toggle .bi-moon-stars { display: none; }
        [data-theme="dark"]  .theme-toggle .bi-sun { display: inline-block; }

        /* ── LAYOUT ── */
        .page-wrap {
            position: relative; z-index: 1;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 80px 20px 40px;
        }

        /* ── LOGIN CARD ── */
        .login-card {
            width: 100%;
            max-width: 420px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 40px 36px;
            box-shadow: 0 20px 60px rgba(0,0,0,.08);
        }

        [data-theme="dark"] .login-card {
            box-shadow: 0 20px 60px rgba(0,0,0,.4);
        }

        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .login-logo {
            width: 56px; height: 56px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            box-shadow: 0 8px 24px rgba(59,130,246,.3);
        }

        .login-logo svg { width: 26px; height: 26px; fill: #fff; }

        .login-title {
            font-family: 'Syne', sans-serif;
            font-size: 22px; font-weight: 700;
            color: var(--text-strong);
            letter-spacing: -.4px;
            margin-bottom: 4px;
        }

        .login-sub {
            font-size: 13px;
            color: var(--muted);
        }

        /* ── FORM ── */
        .field-group {
            margin-bottom: 16px;
        }

        .field-group label {
            display: block;
            font-size: 11px; font-weight: 500;
            letter-spacing: .6px; text-transform: uppercase;
            color: var(--muted); margin-bottom: 7px;
        }

        .field-wrap {
            position: relative;
        }

        .field-wrap i {
            position: absolute;
            left: 14px; top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: 15px;
            pointer-events: none;
        }

        .field-wrap input {
            width: 100%;
            background: var(--input-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 11px 14px 11px 40px;
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            color: var(--text);
            outline: none;
            transition: border-color .2s, background .2s, box-shadow .2s;
        }

        .field-wrap input::placeholder { color: var(--muted); opacity: .6; }

        .field-wrap input:focus {
            border-color: var(--accent);
            background: var(--input-bg-focus);
            box-shadow: 0 0 0 3px rgba(59,130,246,.12);
        }

        /* ── ERROR ── */
        .error-box {
            background: var(--error-bg);
            border: 1px solid var(--error-border);
            border-radius: 10px;
            padding: 11px 14px;
            font-size: 13px;
            color: var(--error-color);
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 16px;
        }

        /* ── BOUTON ── */
        .btn-login {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            border: none;
            border-radius: 10px;
            color: #fff;
            font-family: 'DM Sans', sans-serif;
            font-size: 15px; font-weight: 500;
            cursor: pointer;
            box-shadow: 0 4px 16px rgba(59,130,246,.35);
            transition: opacity .18s, transform .15s;
            margin-top: 8px;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }

        .btn-login:hover { opacity: .92; transform: translateY(-1px); }
        .btn-login:active { transform: translateY(0); }

        /* ── FOOTER ── */
        .login-footer {
            text-align: center;
            margin-top: 24px;
            font-size: 12px;
            color: var(--muted);
        }
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
    <button id="themeToggle" class="theme-toggle" type="button" title="Changer de thème">
        <i class="bi bi-moon-stars"></i>
        <i class="bi bi-sun"></i>
    </button>
</header>

<div class="page-wrap">
    <div class="login-card">

        <div class="login-header">
            <div class="login-logo">
                <svg viewBox="0 0 24 24"><path d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.58.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1C10.29 21 3 13.71 3 5c0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.26.2 2.47.57 3.6.1.34.02.74-.24 1.01L6.6 10.8z"/></svg>
            </div>
            <h1 class="login-title">Connexion</h1>
            <p class="login-sub">Accès aux enregistrements audio</p>
        </div>

        <?php if ($divError): ?>
            <div class="error-box">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Identifiants incorrects. Veuillez réessayer.
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">

            <div class="field-group">
                <label>Identifiant</label>
                <div class="field-wrap">
                    <i class="bi bi-person"></i>
                    <input type="text" name="username" placeholder="Nom d'utilisateur"
                        value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>"
                        required autocomplete="username">
                </div>
            </div>

            <div class="field-group">
                <label>Mot de passe</label>
                <div class="field-wrap">
                    <i class="bi bi-lock"></i>
                    <input type="password" name="password" placeholder="••••••••"
                        required autocomplete="current-password">
                </div>
            </div>

            <button type="submit" class="btn-login">
                <i class="bi bi-box-arrow-in-right"></i>
                Se connecter
            </button>

        </form>

        <div class="login-footer">© <?= date('Y') ?> Kyntus Morocco</div>

    </div>
</div>

<script>
    (function() {
        const btn = document.getElementById('themeToggle');
        if (!btn) return;
        btn.addEventListener('click', function() {
            const html = document.documentElement;
            const current = html.getAttribute('data-theme') || 'light';
            const next = current === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            try { localStorage.setItem('kyntus-theme', next); } catch(e){}
        });
    })();
</script>

</body>
</html>
webserver@webserver:~/EnregWeb-main$ cat download.php
<?php
// PAS de ob_start() ni ob_gzhandler ici - streaming binaire pur
session_start();

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    die("Non autorise.");
}

$isAdmin     = !empty($_SESSION['user']['is_admin']) && (int)$_SESSION['user']['is_admin'] === 1;
$canDownload = $isAdmin || (!empty($_SESSION['user']['can_download']) && (int)$_SESSION['user']['can_download'] === 1);

$f = $_GET['f'] ?? '';
if ($f === '') { http_response_code(400); die(); }

$f = urldecode((string)$f);
$f = str_replace('\\', '/', $f);
$f = ltrim($f, '/');

// Retire le préfixe audio_mails/ si présent
if (strpos($f, 'audio_mails/') === 0) {
    $f = substr($f, strlen('audio_mails/'));
}
$f = ltrim($f, '/');

// Sécurité : pas de .. ni de null bytes
if (strpos($f, '..') !== false || strpos($f, "\0") !== false) {
    http_response_code(403); die("Interdit.");
}

// Valide le format : YYYY-MM-DD/fichier.ext
if (!preg_match('#^\d{4}-\d{2}-\d{2}/[^/]+\.(mp3|wav|m4a|ogg|flac|aac)$#i', $f)) {
    http_response_code(403); die("Chemin invalide.");
}

session_write_close();

$baseDir = realpath(__DIR__ . '/audio_mails');
if (!$baseDir) { http_response_code(500); die("Dossier audio introuvable."); }

$full = realpath($baseDir . '/' . $f);
if (!$full || strpos($full, $baseDir) !== 0 || !is_file($full)) {
    http_response_code(404); die("Fichier introuvable.");
}

// Téléchargement vs lecture inline
$isDownload = (($_GET['dl'] ?? '') === '1');
if (!$canDownload && $isDownload) { http_response_code(403); die("Telechargement interdit."); }

$filename = basename($full);
$filesize = filesize($full);

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$mime = [
    'mp3'  => 'audio/mpeg',
    'wav'  => 'audio/wav',
    'm4a'  => 'audio/mp4',
    'ogg'  => 'audio/ogg',
    'flac' => 'audio/flac',
    'aac'  => 'audio/aac',
];
$contentType = $mime[$ext] ?? 'audio/mpeg';

$start  = 0;
$end    = $filesize - 1;
$length = $filesize;

// Support Range (obligatoire pour WaveSurfer MediaElement)
if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m)) {
        $start = ($m[1] !== '') ? (int)$m[1] : 0;
        $end   = ($m[2] !== '') ? (int)$m[2] : $filesize - 1;
        if ($start <= $end && $end < $filesize) {
            $length = $end - $start + 1;
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$filesize");
        } else {
            header("Content-Range: bytes */$filesize");
            http_response_code(416);
            exit;
        }
    }
} else {
    http_response_code(200);
}

// Vider TOUS les buffers de sortie avant d'envoyer du binaire
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $contentType);
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: ' . ($isDownload ? 'attachment' : 'inline') . '; filename="' . $filename . '"');

// Ouvre et envoie le fichier
$fp = fopen($full, 'rb');
if (!$fp) { http_response_code(500); die("Erreur ouverture fichier."); }

if ($start > 0) fseek($fp, $start);

$remaining = $length;
while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
    $chunk = min(524288, $remaining); // 512 KB par chunk
    $data  = fread($fp, $chunk);
    if ($data === false) break;
    echo $data;
    flush();
    $remaining -= strlen($data);
}

fclose($fp);
exit;
