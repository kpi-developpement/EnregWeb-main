<?php
/**
 * indexer.php — Indexation avec détection du sens d'appel
 *
 * Sortant (OUT) : +262...12754_0180432048_2026-...mp3
 *                 └correspondant┘└── AGENT ──┘
 *                 → l'AGENT est en parts[1] (à droite)
 *
 * Entrant (IN)  : 0180432048_+262...12754_2026-...mp3
 *                 └── AGENT ──┘└correspondant┘
 *                 → l'AGENT est en parts[0] (à gauche)
 *
 * Modes :
 *   php indexer.php              → une seule passe puis quitte
 *   php indexer.php --daemon     → boucle infinie (relance toutes les INDEXER_INTERVAL secondes)
 *   INDEXER_DAEMON=1 php indexer.php  → idem via variable d'environnement
 */

chdir(__DIR__);
require_once(__DIR__ . '/database.php');

$BASE_DIR  = '/var/www/html/audio_mails';
$REL_BASE  = 'audio_mails';
$DAYS_BACK = 400;
$LOCK_FILE = sys_get_temp_dir() . '/audio_indexer.lock';

// ── Mode daemon ────────────────────────────────────────────
$DAEMON_MODE     = in_array('--daemon', $argv ?? []) || getenv('INDEXER_DAEMON') === '1';
$DAEMON_INTERVAL = (int)(getenv('INDEXER_INTERVAL') ?: 30); // secondes entre deux passes

// ── Verrou (une seule instance) ────────────────────────────
$lock = fopen($LOCK_FILE, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Une autre instance tourne déjà, abandon.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Démarrage" . ($DAEMON_MODE ? " (mode daemon, intervalle={$DAEMON_INTERVAL}s)" : "") . ".\n";
echo "  BASE_DIR  = $BASE_DIR\n";
echo "  DAYS_BACK = $DAYS_BACK\n";

// ============================================================
// Normalisation : "0180432048" == "180432048"
// ============================================================
function normalizeNumber(string $n): string {
    $n = preg_replace('/\D+/', '', $n);
    $n = ltrim($n, '0');
    return $n;
}

// ============================================================
// Parser avec détection du sens
// ============================================================
function parseFilename(string $filename, array $knownAgents): ?array {
    $fn = pathinfo($filename, PATHINFO_FILENAME);
    $parts = explode('_', $fn);
    if (count($parts) < 3) return null;

    $callDt = null;
    foreach ($parts as $p) {
        $obj = DateTime::createFromFormat('Y-m-d-H-i-s', $p);
        if ($obj) { $callDt = $obj->format('Y-m-d H:i:s'); break; }
    }
    if (!$callDt) return null;

    $numA = $parts[0] ?? '';
    $numB = $parts[1] ?? '';
    if ($numA === '' || $numB === '') return null;

    $normA = normalizeNumber($numA);
    $normB = normalizeNumber($numB);

    if (isset($knownAgents[$normA])) {
        return ['tag' => $knownAgents[$normA], 'numero2' => $numB, 'direction' => 'in',  'call_dt' => $callDt];
    }
    if (isset($knownAgents[$normB])) {
        return ['tag' => $knownAgents[$normB], 'numero2' => $numA, 'direction' => 'out', 'call_dt' => $callDt];
    }
    return ['tag' => $numB, 'numero2' => $numA, 'direction' => 'out', 'call_dt' => $callDt];
}

// ============================================================
// Une passe d'indexation complète
// ============================================================
function runIndexPass(string $BASE_DIR, string $REL_BASE, int $DAYS_BACK, array $knownAgents): void {
    $totalScanned  = 0;
    $totalInserted = 0;
    $totalSkipped  = 0;
    $totalErrors   = 0;
    $totalIn       = 0;
    $totalOut      = 0;
    $totalUnknown  = 0;
    $startedAt     = microtime(true);

    if (!is_dir($BASE_DIR)) {
        echo "[" . date('Y-m-d H:i:s') . "] ERREUR : dossier $BASE_DIR introuvable.\n";
        return;
    }

    $datesToScan = [];
    for ($i = 0; $i < $DAYS_BACK; $i++) {
        $datesToScan[] = date('Y-m-d', strtotime("-$i days"));
    }

    foreach ($datesToScan as $dateStr) {
        $dir = $BASE_DIR . '/' . $dateStr;
        if (!is_dir($dir)) continue;

        $tDir = microtime(true);

        $existingRows = Database::selectParams(
            "SELECT filename FROM audio_index WHERE file_date = :d",
            [':d' => $dateStr], PDO::FETCH_ASSOC
        );
        $existing = [];
        if (is_array($existingRows)) {
            foreach ($existingRows as $r) { $existing[$r['filename']] = true; }
        }

        $raw = scandir($dir, SCANDIR_SORT_NONE);
        $dirInserted = 0;

        foreach ($raw as $f) {
            if (!preg_match('/\.(mp3|wav|m4a|ogg|flac)$/i', $f)) continue;
            $totalScanned++;

            if (isset($existing[$f])) { $totalSkipped++; continue; }

            $parsed = parseFilename($f, $knownAgents);
            if (!$parsed) { $totalSkipped++; continue; }

            $normTag = normalizeNumber($parsed['tag']);
            if (!isset($knownAgents[$normTag])) $totalUnknown++;
            if ($parsed['direction'] === 'in')  $totalIn++;
            if ($parsed['direction'] === 'out') $totalOut++;

            $relPath  = $REL_BASE . '/' . $dateStr . '/' . $f;
            $fullPath = $dir . '/' . $f;
            $size     = @filesize($fullPath) ?: null;

            $ok = Database::executeParams(
                "INSERT IGNORE INTO audio_index
                 (file_date, tag, numero2, direction, call_dt, filename, filepath, filesize)
                 VALUES (:d, :t, :n, :dir, :dt, :fn, :fp, :sz)",
                [
                    ':d'   => $dateStr,
                    ':t'   => $parsed['tag'],
                    ':n'   => $parsed['numero2'],
                    ':dir' => $parsed['direction'],
                    ':dt'  => $parsed['call_dt'],
                    ':fn'  => $f,
                    ':fp'  => $relPath,
                    ':sz'  => $size,
                ]
            );
            if ($ok) { $totalInserted++; $dirInserted++; }
            else { $totalErrors++; error_log("[indexer] Erreur INSERT pour $relPath"); }
        }

        if ($dirInserted > 0) {
            printf("  %s : +%d nouveaux (%.2fs)\n", $dateStr, $dirInserted, microtime(true) - $tDir);
        }
    }

    $elapsed = microtime(true) - $startedAt;
    printf(
        "[%s] Passe terminée en %.2fs — scannés:%d nouveaux:%d (in:%d out:%d) ignorés:%d agents_inconnus:%d erreurs:%d\n",
        date('Y-m-d H:i:s'), $elapsed,
        $totalScanned, $totalInserted, $totalIn, $totalOut,
        $totalSkipped, $totalUnknown, $totalErrors
    );
}

// ============================================================
// Charge les agents depuis la DB
// ============================================================
function loadKnownAgents(): array {
    $tagRows = Database::selectParams("SELECT numero FROM tag", [], PDO::FETCH_ASSOC);
    $knownAgents = [];
    if (is_array($tagRows)) {
        foreach ($tagRows as $r) {
            $norm = normalizeNumber($r['numero']);
            if ($norm !== '') $knownAgents[$norm] = $r['numero'];
        }
    }
    return $knownAgents;
}

// ============================================================
// Boucle principale
// ============================================================
if (!$DAEMON_MODE) {
    // ── Passe unique ───────────────────────────────────────
    $knownAgents = loadKnownAgents();
    echo "  Tags connus en base : " . count($knownAgents) . "\n";
    if (count($knownAgents) === 0) {
        echo "ERREUR : aucun tag en base.\n";
        flock($lock, LOCK_UN);
        exit(1);
    }
    runIndexPass($BASE_DIR, $REL_BASE, $DAYS_BACK, $knownAgents);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

// ── Mode daemon : boucle infinie ───────────────────────────
$passNumber = 0;
while (true) {
    $passNumber++;
    echo "[" . date('Y-m-d H:i:s') . "] === Passe #$passNumber ===\n";

    // Recharge les agents à chaque passe (nouveaux tags possibles)
    $knownAgents = loadKnownAgents();
    echo "  Tags connus en base : " . count($knownAgents) . "\n";

    if (count($knownAgents) === 0) {
        echo "  Aucun tag — passe ignorée.\n";
    } else {
        runIndexPass($BASE_DIR, $REL_BASE, $DAYS_BACK, $knownAgents);
    }

    echo "[" . date('Y-m-d H:i:s') . "] Prochain scan dans {$DAEMON_INTERVAL}s...\n";
    sleep($DAEMON_INTERVAL);
}
