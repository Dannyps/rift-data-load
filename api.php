<?php

// JSON for the dashboard. The device counters are cumulative, so per-minute counts are the
// difference to the previous reading of the same register (a drop means the counter was reset).

require(__DIR__ . "/sql/utils.php");

const OPENING_BALANCE = 100; // people assumed inside at the first reading

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

// The balance assumes OPENING_BALANCE people were inside at the very first reading, then adds
// incoming and subtracts outgoing from there. Deltas are computed over the whole history (the
// balance needs all of it); buckets are aligned in UTC.
$res = pg_query_params($con, "
    WITH deltas AS (
        SELECT deviceid, logentryid, registerid, \"timestamp\",
               CASE WHEN prev IS NULL THEN 0
                    WHEN value >= prev THEN value - prev
                    ELSE value END AS delta
        FROM (SELECT deviceid, logentryid, registerid, \"timestamp\", value,
                     lag(value) OVER (PARTITION BY deviceid, registerid ORDER BY \"timestamp\", logentryid) AS prev
              FROM records) r
    ), entries AS (
        SELECT \"timestamp\", logentryid,
               sum(delta) FILTER (WHERE registerid = 0) AS d_in,
               sum(delta) FILTER (WHERE registerid = 1) AS d_out
        FROM deltas GROUP BY deviceid, \"timestamp\", logentryid
    ), balanced AS (
        SELECT *, $3::int + sum(coalesce(d_in, 0) - coalesce(d_out, 0)) OVER (ORDER BY \"timestamp\", logentryid) AS balance
        FROM entries
    )
    SELECT to_char(date_bin($2::interval, \"timestamp\", timestamp '2000-01-01'), 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS t,
           sum(coalesce(d_in, 0))::int AS n_in, sum(coalesce(d_out, 0))::int AS n_out,
           (array_agg(balance ORDER BY \"timestamp\" DESC, logentryid DESC))[1]::int AS balance
    FROM balanced
    WHERE \"timestamp\" >= (now() AT TIME ZONE 'UTC') - $1::interval
    GROUP BY 1 ORDER BY 1
", [$window, $bucket, OPENING_BALANCE]);

$points = [];
while ($row = pg_fetch_assoc($res)) {
    $points[] = ['t' => $row['t'], 'in' => (int)$row['n_in'], 'out' => (int)$row['n_out'], 'balance' => (int)$row['balance']];
}

$last = pg_fetch_assoc(pg_query($con, "SELECT to_char(max(\"timestamp\"), 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS t, max(received_at) AS r FROM records"));

echo json_encode([
    'range' => $range,
    'openingBalance' => OPENING_BALANCE,
    'bucketSeconds' => ['1 minute' => 60, '5 minutes' => 300, '15 minutes' => 900, '1 hour' => 3600, '1 day' => 86400][$bucket],
    'points' => $points,
    'lastReading' => $last['t'],
    'lastReceived' => $last['r'] ? date('c', strtotime($last['r'])) : null,
]);
