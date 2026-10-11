<?php

// Replays archived payloads into the database: php load_files.php [file ...]  (default: the live archive).
// Safe to repeat: rows that already exist are skipped. Lines that fail are reported and skipped.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require(__DIR__ . "/src/log.php");
require(__DIR__ . "/sql/utils.php");

$con = db_connect();

$files = array_slice($argv, 1) ?: [RECORDS_FILE];
$loaded = $failed = 0;

foreach ($files as $file) {
    $lines = str_ends_with($file, '.gz') ? gzfile($file) : file($file);
    foreach ($lines as $n => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        try {
            insert_line($con, $line);
            $loaded++;
        } catch (\Throwable $th) {
            $failed++;
            fwrite(STDERR, "$file:" . ($n + 1) . ": " . $th->getMessage() . "\n");
        }
    }
}

echo "loaded $loaded, failed $failed\n";
exit($failed ? 1 : 0);
