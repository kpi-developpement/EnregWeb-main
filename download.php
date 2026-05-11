<?php
session_start();

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    die("Non autorisé.");
}

$isAdmin = !empty($_SESSION['user']['is_admin']) && (int)$_SESSION['user']['is_admin'] === 1;
$canDownload = $isAdmin || (!empty($_SESSION['user']['can_download']) && (int)$_SESSION['user']['can_download'] === 1);

$f = $_GET['f'] ?? '';
$sig = $_GET['sig'] ?? '';

if ($f === '' || $sig === '') {
    http_response_code(400);
    die("Paramètres manquants.");
}

if (empty($_SESSION['csrf_token'])) {
    http_response_code(403);
    die("Session invalide.");
}

$expected = hash_hmac('sha256', $f, $_SESSION['csrf_token']);
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    die("Signature invalide.");
}

session_write_close();

$baseDir = getenv('AUDIO_BASE_DIR') ?: (__DIR__ . '/audio_mails');
$baseDir = realpath($baseDir);

if (!$baseDir) {
    http_response_code(500);
    die("Dossier audio introuvable.");
}

$f = urldecode((string)$f);
$f = str_replace('\\', '/', $f);

$absolutePrefix = '/mnt/nas_enrg/audio_mails/';
if (strpos($f, $absolutePrefix) === 0) {
    $f = substr($f, strlen($absolutePrefix));
}

$f = ltrim($f, '/');
if ($f === '' || strpos($f, '..') !== false) {
    http_response_code(403);
    die("Chemin interdit.");
}

$full = realpath($baseDir . '/' . $f);

if (!$full || strpos($full, $baseDir) !== 0) {
    http_response_code(403);
    die("Chemin interdit.");
}

if (!is_file($full)) {
    http_response_code(404);
    die("Fichier introuvable.");
}

$isDownload = (($_GET['dl'] ?? '') === '1');
if ($isDownload && !$canDownload) {
    http_response_code(403);
    die("Téléchargement interdit.");
}

$filename = basename($full);
$filesize = filesize($full);
$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$mimeTypes = [
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'm4a' => 'audio/mp4',
    'ogg' => 'audio/ogg',
    'flac' => 'audio/flac',
    'aac' => 'audio/aac',
];
$contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

$start = 0;
$end = $filesize - 1;
$length = $filesize;

header('Accept-Ranges: bytes');
header('Content-Type: ' . $contentType);
header('Cache-Control: private, max-age=86400');

if (isset($_SERVER['HTTP_RANGE'])) {
    $range = $_SERVER['HTTP_RANGE'];
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
        header('Content-Range: bytes */' . $filesize);
        http_response_code(416);
        exit;
    }
    $reqStart = $m[1] !== '' ? (int)$m[1] : 0;
    $reqEnd = $m[2] !== '' ? (int)$m[2] : $filesize - 1;
    if ($reqStart > $reqEnd || $reqEnd >= $filesize) {
        header('Content-Range: bytes */' . $filesize);
        http_response_code(416);
        exit;
    }
    $start = $reqStart;
    $end = $reqEnd;
    $length = $end - $start + 1;
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$filesize}");
} else {
    http_response_code(200);
}

if ($isDownload) {
    header('Content-Disposition: attachment; filename="' . $filename . '"');
} else {
    header('Content-Disposition: inline; filename="' . $filename . '"');
}
header('Content-Length: ' . $length);

while (ob_get_level()) { ob_end_clean(); }

$fp = fopen($full, 'rb');
if (!$fp) {
    http_response_code(500);
    die("Impossible d'ouvrir le fichier.");
}
if ($start > 0) fseek($fp, $start);

$remaining = $length;
$chunkSize = 524288;
while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
    $read = min($chunkSize, $remaining);
    $data = fread($fp, $read);
    if ($data === false) break;
    echo $data;
    flush();
    $remaining -= strlen($data);
}
fclose($fp);
exit;
