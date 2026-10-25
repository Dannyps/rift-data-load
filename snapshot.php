<?php

// Brings the saved service/event snapshots up to date (run every few minutes by rift-snapshot.timer,
// so windows get frozen even when nobody opens the page).
//   php snapshot.php [--now=2026-10-11T11:30:00Z] [--week=2026-10-11] [--print]
// Without --week it refreshes the current and the previous week. --now is for testing.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require(__DIR__ . "/sql/utils.php");
require(__DIR__ . "/src/services.php");

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

$now = new DateTimeImmutable($opts['now'] ?? 'now', utc());
$con = db_connect();

$this_week = week_start($now->setTimezone(local_tz())->format('Y-m-d'));
$weeks = isset($opts['week'])
    ? [week_start($opts['week'])]
    : [(new DateTimeImmutable($this_week))->modify('-7 days')->format('Y-m-d'), $this_week];

foreach ($weeks as $monday) {
    $view = services_week($con, $now, $monday);
    if (isset($opts['print'])) {
        echo json_encode($view, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    }
}
