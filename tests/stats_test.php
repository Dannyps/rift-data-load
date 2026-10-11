<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Tests for the pure metric functions in src/stats.php: php tests/stats_test.php
require(__DIR__ . "/../src/stats.php");

$failures = 0;
function check(string $name, $got, $want): void
{
    global $failures;
    if ($got === $want) {
        echo "ok   $name\n";
    } else {
        $failures++;
        echo "FAIL $name\n   got  " . json_encode($got) . "\n   want " . json_encode($want) . "\n";
    }
}
function utc(string $s): DateTimeImmutable { return new DateTimeImmutable($s, new DateTimeZone('UTC')); }

/** one reading per minute from $from to $to (UTC), arrivals d_in from the map HH:MM => n, balance given by $balance(HH:MM) */
function entries(string $day, string $from, string $to, array $arrivals = [], ?callable $balance = null): array
{
    $out = [];
    for ($t = utc("$day $from"); $t <= utc("$day $to"); $t = $t->modify('+1 minute')) {
        $hm = $t->format('H:i');
        $out[] = ['timestamp' => $t->format('Y-m-d H:i:s'), 'd_in' => $arrivals[$hm] ?? 0, 'balance' => $balance ? $balance($hm) : 0];
    }
    return $out;
}

// ---- arrivals and late share: meeting 09:30 (UTC for the test), ends 11:30
$start = utc('2027-03-07 09:30:00'); $end = utc('2027-03-07 11:30:00'); $now = utc('2027-03-07 13:00:00');
$arr = ['08:20' => 7 /* before the window */, '08:31' => 10, '09:00' => 40, '09:29' => 30, '09:30' => 5, '09:35' => 6, '09:40' => 4, '09:41' => 3, '10:15' => 2, '11:30' => 1, '11:31' => 9 /* after the end */];
$e = entries('2027-03-07', '08:00', '12:00', $arr);
check('arrivals: window is (start-60, end]', stats_arrivals($e, $start, $end, 10, $now)['total'], 10 + 40 + 30 + 5 + 6 + 4 + 3 + 2 + 1);
check('late with 10 min grace (arrivals after 09:40)', stats_arrivals($e, $start, $end, 10, $now)['late'], 3 + 2 + 1);
check('late with no grace (after 09:30)', stats_arrivals($e, $start, $end, 0, $now)['late'], 6 + 4 + 3 + 2 + 1);
check('a reading stamped exactly at the start is not late (it describes the minute before)', stats_arrivals($e, $start, $end, 0, $now)['late'] === 16, true);
check('late with 15 min grace', stats_arrivals($e, $start, $end, 15, $now)['late'], 2 + 1);
check('late share', stats_late_pct(101, 6), 5.9);
check('late share without arrivals is null', stats_late_pct(0, 0), null);
check('late share unknown is null', stats_late_pct(null, null), null);
check('while running only readings up to now count', stats_arrivals($e, $start, $end, 10, utc('2027-03-07 09:36:00')), ['total' => 10 + 40 + 30 + 5 + 6, 'late' => 0]);
check('nothing counted for an empty day', stats_arrivals([], $start, $end, 10, $now), ['total' => 0, 'late' => 0]);

// ---- devotional peak: balance is 10, then 25 from 08:40, 31 from 08:42, 40 from 08:46
$bal = function (string $hm): int { return match (true) { $hm < '08:40' => 10, $hm < '08:42' => 25, $hm < '08:46' => 31, default => 40 }; };
$e = entries('2027-03-07', '08:00', '09:30', [], $bal);
$dev = utc('2027-03-07 08:40:00');
check('devotional peak = highest balance in [time, time+5 min]', stats_devotional_peak($e, $dev, utc('2027-03-07 10:00:00')), 31);
check('devotional: reading at 08:45 is inside the 5 minutes (inclusive)', stats_devotional_peak(entries('2027-03-07', '08:00', '09:30', [], fn($hm) => $hm === '08:45' ? 99 : 5), $dev, utc('2027-03-07 10:00:00')), 99);
check('devotional: 08:46 is outside', stats_devotional_peak(entries('2027-03-07', '08:00', '09:30', [], fn($hm) => $hm === '08:46' ? 99 : 5), $dev, utc('2027-03-07 10:00:00')), 5);
check('devotional: only readings up to now', stats_devotional_peak($e, $dev, utc('2027-03-07 08:41:00')), 25);
check('devotional: nothing yet', stats_devotional_peak($e, $dev, utc('2027-03-07 08:30:00')), null);
check('devotional: no readings', stats_devotional_peak([], $dev, $now), null);

// ---- devotional time: plan wins, otherwise the setting by morning/afternoon (Lisbon summer = UTC+1)
$settings = STATS_DEFAULTS;
check('devotional time from the plan (wall clock Lisbon -> UTC)', stats_devotional_time('2026-10-11 08:40', '2026-10-11', '09:30:00', $settings)->format('Y-m-d H:i'), '2026-10-11 07:40');
check('devotional fallback, morning meeting', stats_devotional_time(null, '2026-10-11', '11:30:00', $settings)->format('Y-m-d H:i'), '2026-10-11 07:43');
check('devotional fallback, afternoon meeting', stats_devotional_time(null, '2026-10-11', '17:30:00', $settings)->format('Y-m-d H:i'), '2026-10-11 15:58');
check('devotional time in winter (UTC+0)', stats_devotional_time(null, '2027-01-10', '09:30:00', $settings)->format('Y-m-d H:i'), '2027-01-10 08:43');
check('devotional setting is respected', stats_devotional_time(null, '2027-01-10', '09:30:00', ['devotional_morning' => '08:30'] + $settings)->format('H:i'), '08:30');

// ---- exit speed after 13:00 (UTC for the test), threshold 5
$ref = utc('2027-03-07 13:00:00');
$drop = fn(int $reachAt) => fn(string $hm) => $hm < '13:00' ? 300 : max(5, 300 - 20 * ((int)substr($hm, 0, 2) * 60 + (int)substr($hm, 3) - 13 * 60));
$e = entries('2027-03-07', '12:30', '16:30', [], $drop(0));
check('exit: reached after N minutes (300 - 20/min: <=5 at 15 min)', stats_exit($e, $ref, 5, utc('2027-03-07 16:30:00')), ['state' => 'reached', 'minutes' => 15]);
check('exit: not known yet while still above the threshold', stats_exit($e, $ref, 5, utc('2027-03-07 13:10:00')), ['state' => 'pending', 'minutes' => null]);
check('exit: reached is known as soon as it happens', stats_exit($e, $ref, 5, utc('2027-03-07 13:15:00')), ['state' => 'reached', 'minutes' => 15]);
check('exit: already at the threshold at the reference time = 0', stats_exit(entries('2027-03-07', '12:30', '16:30', [], fn($hm) => 3), $ref, 5, $now = utc('2027-03-07 16:30:00')), ['state' => 'reached', 'minutes' => 0]);
check('exit: higher threshold is reached earlier', stats_exit($e, $ref, 200, utc('2027-03-07 16:30:00')), ['state' => 'reached', 'minutes' => 5]);
check('exit: never reaches within 3 h = not_reached', stats_exit(entries('2027-03-07', '12:30', '17:00', [], fn($hm) => 80), $ref, 5, utc('2027-03-07 17:00:00')), ['state' => 'not_reached', 'minutes' => null]);
check('exit: not reached but window still open = pending', stats_exit(entries('2027-03-07', '12:30', '14:00', [], fn($hm) => 80), $ref, 5, utc('2027-03-07 14:00:00')), ['state' => 'pending', 'minutes' => null]);
check('exit: before the reference time = pending', stats_exit($e, $ref, 5, utc('2027-03-07 12:00:00')), ['state' => 'pending', 'minutes' => null]);
check('exit: no readings near the reference time = no_data', stats_exit(entries('2027-03-07', '14:00', '16:30', [], fn($hm) => 0), $ref, 5, utc('2027-03-07 16:30:00')), ['state' => 'no_data', 'minutes' => null]);
check('exit: no readings at all after it = no_data', stats_exit(entries('2027-03-07', '10:00', '12:30', [], fn($hm) => 50), $ref, 5, utc('2027-03-07 16:30:00')), ['state' => 'no_data', 'minutes' => null]);
check('exit: readings that started a few minutes late still count', stats_exit(entries('2027-03-07', '13:05', '16:30', [], fn($hm) => 2), $ref, 5, utc('2027-03-07 16:30:00')), ['state' => 'reached', 'minutes' => 5]);

// ---- small helpers
check('sum ignores nulls', stats_sum([['a' => 3], ['a' => null], ['a' => 4]], 'a'), 7);
check('sum of nothing is null', stats_sum([['a' => null]], 'a'), null);
check('mean ignores nulls', stats_mean([10, null, 20, 30]), 20.0);
check('mean rounds', stats_mean([1, 2, 2]), 1.7);
check('mean of nothing is null', stats_mean([null, null]), null);
check('delta', stats_delta(430, 400), 30);
check('delta with a missing side is null', stats_delta(430, null), null);

// ---- settings validation
check('grace 0 ok', stats_setting_problem('late_grace_min', '0'), null);
check('grace 120 ok', stats_setting_problem('late_grace_min', '120'), null);
check('grace 121 refused', stats_setting_problem('late_grace_min', '121') !== null, true);
check('grace text refused', stats_setting_problem('late_grace_min', 'abc') !== null, true);
check('time 08:43 ok', stats_setting_problem('devotional_morning', '08:43'), null);
check('time 8:43 refused (HH:MM)', stats_setting_problem('devotional_morning', '8:43') !== null, true);
check('time 25:00 refused', stats_setting_problem('exit_ref_1', '25:00') !== null, true);
check('threshold 5 ok', stats_setting_problem('exit_threshold', '5'), null);
check('unknown setting refused', stats_setting_problem('nope', '1') !== null, true);

echo $failures ? "\n$failures FAILED\n" : "\nall passed\n";
exit($failures ? 1 : 0);
