<?php

// JSON for the services page: ?week=YYYY-MM-DD (any date; the week is Sunday..Saturday, default this week).

require(__DIR__ . "/sql/utils.php");
require(__DIR__ . "/src/auth.php");
require(__DIR__ . "/src/services.php");

header('Content-Type: application/json');
header('Cache-Control: no-store');

$week = $_GET['week'] ?? null;
if ($week !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
    http_response_code(400);
    exit;
}

try {
    $con = db_connect();
    $viewer = viewer_user($con);
    release_session();
    if (!$viewer) {        // the whole site needs an account (or the counter's own network)
        http_response_code(401);
        echo json_encode(['error' => 'login']);
        exit;
    }
    $now = new DateTimeImmutable('now', utc());
    $sunday = week_start($week ?? $now->setTimezone(local_tz())->format('Y-m-d'));
    echo json_encode(services_week($con, $now, $sunday));
} catch (\Throwable $th) {
    error_log("rift-data-load: " . $th->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'error']);
}
