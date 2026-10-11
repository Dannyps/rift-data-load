<?php

// JSON for the dashboard. The device counters are cumulative, so per-minute counts are the
// difference to the previous reading of the same register (a drop means the counter was reset).

require(__DIR__ . "/sql/utils.php");

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

// Buckets are aligned in UTC; the lag runs over the whole table so the first reading in the
// window still has a predecessor.
$res = pg_query_params($con, "
    WITH deltas AS (
        SELECT registerid, \"timestamp\",
               CASE WHEN prev IS NULL THEN 0
                    WHEN value >= prev THEN value - prev
                    ELSE value END AS delta
        FROM (SELECT registerid, \"timestamp\", value,
                     lag(value) OVER (PARTITION BY deviceid, registerid ORDER BY \"timestamp\", logentryid) AS prev
              FROM records) r
        WHERE \"timestamp\" >= (now() AT TIME ZONE 'UTC') - $1::interval
    )
    SELECT to_char(date_bin($2::interval, \"timestamp\", timestamp '2000-01-01'), 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS t,
           registerid, sum(delta)::int AS n
    FROM deltas GROUP BY 1, 2 ORDER BY 1, 2
", [$window, $bucket]);

$series = [];
while ($row = pg_fetch_assoc($res)) {
    $series[$row['t']][(int)$row['registerid']] = (int)$row['n'];
}

$points = [];
foreach ($series as $t => $regs) {
    $points[] = ['t' => $t, 'in' => $regs[0] ?? 0, 'out' => $regs[1] ?? 0];
}

$last = pg_fetch_assoc(pg_query($con, "SELECT to_char(max(\"timestamp\"), 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS t, max(received_at) AS r FROM records"));

echo json_encode([
    'range' => $range,
    'bucketSeconds' => ['1 minute' => 60, '5 minutes' => 300, '15 minutes' => 900, '1 hour' => 3600, '1 day' => 86400][$bucket],
    'points' => $points,
    'lastReading' => $last['t'],
    'lastReceived' => $last['r'] ? date('c', strtotime($last['r'])) : null,
]);
