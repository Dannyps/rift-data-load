<?php

// Special days replace the regular services on their date.
//   php special_day.php add 2026-12-19 "Especial de Natal" 11:30 17:30 [--duration=120]
//   php special_day.php list
//   php special_day.php remove 2026-12-19

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require(__DIR__ . "/sql/utils.php");

$args = [];
$duration = 120;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--duration=(\d+)$/', $a, $m)) {
        $duration = (int)$m[1];
    } else {
        $args[] = $a;
    }
}

function fail(string $msg): never
{
    fwrite(STDERR, "$msg\n");
    exit(1);
}

$con = db_connect();
$cmd = array_shift($args);

switch ($cmd) {
    case 'add':
        [$day, $name] = [$args[0] ?? '', $args[1] ?? ''];
        $times = array_slice($args, 2);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $name === '' || !$times) {
            fail('usage: special_day.php add YYYY-MM-DD "Name" HH:MM [HH:MM ...] [--duration=minutes]');
        }
        foreach ($times as $t) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
                fail("bad time: $t");
            }
            pg_query_params($con, "INSERT INTO special_days (day, name, start_time, duration_min) VALUES ($1, $2, $3, $4)
                                   ON CONFLICT (day, start_time) DO UPDATE SET name = EXCLUDED.name, duration_min = EXCLUDED.duration_min",
                [$day, $name, $t, $duration]) or fail(pg_last_error($con));
        }
        echo "ok\n";
        // fall through to list
    case 'list':
        $res = pg_query($con, "SELECT day, name, string_agg(to_char(start_time, 'HH24:MI') || ' (' || duration_min || ' min)', ', ' ORDER BY start_time) AS times
                               FROM special_days GROUP BY day, name ORDER BY day");
        foreach (pg_fetch_all($res) ?: [] as $r) {
            echo "{$r['day']}  {$r['name']}: {$r['times']}\n";
        }
        break;
    case 'remove':
        $day = $args[0] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            fail('usage: special_day.php remove YYYY-MM-DD');
        }
        $res = pg_query_params($con, "DELETE FROM special_days WHERE day = $1", [$day]);
        echo pg_affected_rows($res) . " removed\n";
        break;
    default:
        fail('usage: special_day.php add|list|remove ...');
}
