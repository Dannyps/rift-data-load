<?php

// JSON for the dashboard. The device counters are cumulative, so per-minute counts are the
// difference to the previous reading of the same register (a drop means the counter was reset).

require(__DIR__ . "/sql/utils.php");
require(__DIR__ . "/src/balance.php");

const RANGES = [
    // range => [window, bucket]
    '1h'  => ['1 hour',   '1 minute'],
    '6h'  => ['6 hours',  '5 minutes'],
    '24h' => ['24 hours', '15 minutes'],
    '7d'  => ['7 days',   '1 hour'],
    '30d' => ['30 days',  '1 day'],
];

$range = $_GET['range'] ?? '24h';
if (!isset(RANGES[$range])) {
    http_response_code(400);
    exit;
}
[$window, $bucket] = RANGES[$range];

header('Content-Type: application/json');
header('Cache-Control: no-store');

$con = db_connect();

// Buckets are aligned in UTC. The balance of a bucket is its last reading's.
$since = new DateTimeImmutable("-$window");
$res = pg_query_params($con, "
    SELECT to_char(date_bin($1::interval, \"timestamp\", timestamp '2000-01-01'), 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS t,
           sum(d_in)::int AS n_in, sum(d_out)::int AS n_out,
           (array_agg(balance ORDER BY \"timestamp\" DESC, logentryid DESC))[1] AS balance,
           (array_agg(epoch ORDER BY \"timestamp\" DESC, logentryid DESC))[1] AS epoch
    FROM balance_entries($2, $3, $4, $5)
    WHERE \"timestamp\" >= (now() AT TIME ZONE 'UTC') - $6::interval
    GROUP BY 1 ORDER BY 1
", [$bucket, day_start_utc($since), OPENING_BALANCE, RESET_HOUR, LOCAL_TZ, $window]);

$points = [];
while ($row = pg_fetch_assoc($res)) {
    $points[] = ['t' => $row['t'], 'in' => (int)$row['n_in'], 'out' => (int)$row['n_out'], 'balance' => (int)$row['balance'], 'epoch' => $row['epoch']];
}

// A reset shows as zero from the moment it was pressed, even before the first reading of the new epoch
// arrives (the device is about 4 minutes late). Once such a reading is there, the real points take over.
$known = array_column($points, 'epoch');
foreach (manual_resets_since($con, $since) as $at) {
    if (!in_array($at, $known, true)) {
        $points[] = ['t' => (new DateTimeImmutable($at, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'), 'in' => 0, 'out' => 0, 'balance' => 0, 'epoch' => $at];
    }
}
usort($points, fn($a, $b) => strcmp($a['t'], $b['t']));

$last = pg_fetch_assoc(pg_query($con, "SELECT to_char(max(\"timestamp\"), 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS t, max(received_at) AS r FROM records"));

echo json_encode([
    'range' => $range,
    'openingBalance' => OPENING_BALANCE,
    'resetHour' => RESET_HOUR,
    'timezone' => LOCAL_TZ,
    'bucketSeconds' => ['1 minute' => 60, '5 minutes' => 300, '15 minutes' => 900, '1 hour' => 3600, '1 day' => 86400][$bucket],
    'points' => $points,
    'lastReading' => $last['t'],
    'lastReceived' => $last['r'] ? date('c', strtotime($last['r'])) : null,
]);
