<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Planning Center sync (run every 30 minutes by rift-pco-sync.timer).
//   php pco_sync.php [--dry-run] [--backfill-days=N] [--now=2026-10-11T12:00:00Z]
// --dry-run reads Planning Center and prints what it would do, writing nothing.
// --backfill-days=N also reads the people of plans up to N days back (default: yesterday only).

require(__DIR__ . "/sql/utils.php");
require(__DIR__ . "/src/log.php");
require(__DIR__ . "/src/pco.php");

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[str_replace('-', '_', $m[1])] = $m[2] ?? true;
    }
}

$lock = fopen(DATA_DIR . '/pco-sync.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "another sync is running\n");
    exit(0);
}

try {
    $summary = pco_sync(db_connect(), new DateTimeImmutable($opts['now'] ?? 'now', new DateTimeZone('UTC')), $opts);
} catch (\Throwable $th) {
    fwrite(STDERR, 'sync failed: ' . $th->getMessage() . "\n");
    exit(1);
}

echo $summary['text'], "\n";
if (!empty($opts['dry_run'])) {
    echo "\nService types followed:\n";
    foreach ($summary['types'] as [$id, $name, $folder]) {
        echo "  $id  $name  ($folder)\n";
    }
    echo "\nSpecial days it would have:\n";
    foreach ($summary['wanted'] as [$day, $start, $name, $planId]) {
        echo "  $day $start  $name  (plan $planId)\n";
    }
}
