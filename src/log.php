<?php

const DATA_DIR = '/var/lib/rift-data-load';
const RECORDS_FILE = DATA_DIR . '/records';
const INGEST_LOG = DATA_DIR . '/ingest.log';

/**
 * Archive of every valid payload exactly as the device sent it, one JSON document per line.
 * Written before the database insert so a DB outage loses nothing: `php load_files.php` replays it.
 */
function archive_payload(string $payload): void
{
    append_line(RECORDS_FILE, $payload);
}

/** One JSON line per request outcome: time, ip, status, plus whatever context the caller adds. */
function log_event(int $status, array $context = []): void
{
    $event = ['time' => date('c'), 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli', 'status' => $status] + $context;
    append_line(INGEST_LOG, json_encode($event, JSON_UNESCAPED_SLASHES));
}

function append_line(string $file, string $line): void
{
    if (file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
        error_log("rift-data-load: could not write to $file");
    }
}
