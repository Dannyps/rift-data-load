<?php

require(__DIR__ . "/src/log.php");
require(__DIR__ . "/sql/utils.php");
require(__DIR__ . "/src/auth.php");

const AUTH_TOKEN_FILE = '/etc/rift-data-load/auth-token';

function respond(int $status, string $body, array $context = []): never
{
    http_response_code($status);
    log_event($status, $context);
    echo $body;
    exit;
}

$expected = trim(file_get_contents(AUTH_TOKEN_FILE));
// getallheaders(), not $_SERVER: Apache does not hand Authorization to PHP.
$given = array_change_key_case(getallheaders(), CASE_LOWER)['authorization'] ?? '';

if ($expected === '' || !hash_equals($expected, $given)) {
    respond(401, "unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, "method not allowed");
}

// The token was accepted, so this address really is the counter's network: remember it (people browsing from the same
// address are signed in read-only, see device_user()). A failure here must never lose a reading.
try {
    record_device_seen(db_connect(), client_ip());
} catch (\Throwable $th) {
    error_log("rift-data-load: device_seen: " . $th->getMessage());
}

$incomingLine = file_get_contents("php://input");

try {
    $json = parse_payload($incomingLine);
} catch (InvalidArgumentException $e) {
    respond(400, "bad request", ['error' => $e->getMessage(), 'bytes' => strlen($incomingLine)]);
}

$context = [
    'device' => $json->DeviceID,
    'logEntryIds' => array_map(fn($e) => $e->LogEntryId ?? null, count_entries($json)),
    'clockChanged' => count($json->CountLogs) > count(count_entries($json)) ?: null,
];

archive_payload($incomingLine);

try {
    insert_line(db_connect(), $incomingLine);
} catch (\Throwable $th) {
    error_log("rift-data-load: " . $th->getMessage());
    respond(500, "error", $context + ['error' => $th->getMessage()]);
}

respond(200, "ok", $context);
