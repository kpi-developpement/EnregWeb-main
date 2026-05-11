<?php
$baseDir = getenv('AUDIO_BASE_DIR') ?: (__DIR__ . '/audio_mails');
$real = realpath($baseDir);
header('Content-Type: text/plain; charset=utf-8');
echo "AUDIO_BASE_DIR={$baseDir}\n";
echo "REAL=" . ($real ?: 'false') . "\n";
echo "IS_DIR=" . (is_dir($baseDir) ? 'yes' : 'no') . "\n";
if ($real) {
    $items = @scandir($real) ?: [];
    echo "ITEMS=" . count($items) . "\n";
    foreach (array_slice($items, 0, 20) as $item) echo $item . "\n";
}
