<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Tests for the week (Monday to Sunday), the report window and the week totals in src/services.php: php tests/week_test.php
require(__DIR__ . "/../src/services.php");   // loading it does not touch the database

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
function u(string $s): DateTimeImmutable { return new DateTimeImmutable($s, new DateTimeZone('UTC')); }

// ---- weeks start on Monday
check('Monday starts its own week', week_start('2026-10-19'), '2026-10-19');
check('Tuesday', week_start('2026-10-20'), '2026-10-19');
check('Saturday', week_start('2026-10-24'), '2026-10-19');
check('Sunday belongs to the week that began on the Monday before', week_start('2026-10-25'), '2026-10-19');
check('the next Monday starts a new week', week_start('2026-10-26'), '2026-10-26');
check('across the end of a year (Thursday 1 Jan 2026)', week_start('2026-01-01'), '2025-12-29');
check('across the end of a year (Sunday 4 Jan 2026)', week_start('2026-01-04'), '2025-12-29');
check('a leap day (Tuesday 29 Feb 2028)', week_start('2028-02-29'), '2028-02-28');
check('the day clocks go back in Portugal (Sunday 25 Oct 2026)', week_start('2026-10-25'), '2026-10-19');
check('the Monday after that change', week_start('2026-10-26'), '2026-10-26');
check('the day clocks go forward (Sunday 29 Mar 2026)', week_start('2026-03-29'), '2026-03-23');
$starts = array_map('week_start', ['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25']);
check('all seven days of a week give the same start', array_values(array_unique($starts)), ['2026-10-19']);

// ---- the report opens two hours before the meeting starts (the stored start is UTC)
check('report: 3 hours before is too early', report_window_open('2026-10-25 09:30:00', u('2026-10-25 06:30:00')), false);
check('report: 2 hours and a minute before is too early', report_window_open('2026-10-25 09:30:00', u('2026-10-25 07:29:00')), false);
check('report: exactly 2 hours before is open', report_window_open('2026-10-25 09:30:00', u('2026-10-25 07:30:00')), true);
check('report: 1 hour before is open', report_window_open('2026-10-25 09:30:00', u('2026-10-25 08:30:00')), true);
check('report: during the meeting', report_window_open('2026-10-25 09:30:00', u('2026-10-25 10:15:00')), true);
check('report: days later', report_window_open('2026-10-25 09:30:00', u('2026-11-02 10:00:00')), true);
check('report: it does not open the day before', report_window_open('2026-10-25 09:30:00', u('2026-10-24 12:00:00')), false);
check('report: the open window is given in minutes', REPORT_OPEN_BEFORE_MIN, 120);
check('report: a non-UTC clock object is converted', report_window_open('2026-10-25 09:30:00', new DateTimeImmutable('2026-10-25 08:30:00', new DateTimeZone('Europe/Lisbon'))), true);

// ---- week totals
$rep = fn($ass, $vol, $vis, $kids, $aud, $dec) => ['assistencia' => $ass, 'voluntarios' => $vol, 'visitantes' => $vis, 'kids' => $kids, 'auditorio' => $aud, 'decisoes' => $dec];
$done = fn($count, $total = null, $report = null) => ['status' => 'done', 'count' => $count, 'total' => $total, 'report' => $report];
check('totals of an empty week', week_totals([]), ['meetings' => 0, 'started' => 0, 'withData' => 0, 'reports' => 0, 'shown' => null,
    'voluntarios' => null, 'visitantes' => null, 'hillsongKids' => null, 'decisoes' => null, 'running' => 0]);
$t = week_totals([$done(138), $done(407, 484, $rep(400, 38, 12, 61, 7, 3)), $done(89)]);
check('totals: shown adds what each card shows (total when reported, else the counter)', $t['shown'], 138 + 484 + 89);
check('totals: categories only add the reported meetings', [$t['voluntarios'], $t['visitantes'], $t['hillsongKids'], $t['decisoes']], [38, 12, 68, 3]);
check('totals: completeness counters', [$t['meetings'], $t['started'], $t['withData'], $t['reports']], [3, 3, 3, 1]);
$t = week_totals([$done(100), ['status' => 'unknown', 'count' => null, 'total' => null, 'report' => null]]);
check('totals: a meeting that has not started is not added', [$t['shown'], $t['meetings'], $t['started'], $t['withData']], [100, 2, 1, 1]);
$t = week_totals([['status' => 'running', 'count' => 250, 'total' => null, 'report' => null], $done(null)]);
check('totals: a running meeting counts and is flagged; one without data is not added', [$t['shown'], $t['running'], $t['withData'], $t['started']], [250, 1, 1, 2]);
$t = week_totals([$done(null), $done(null)]);
check('totals: no figure at all is null, not zero', $t['shown'], null);
$t = week_totals([$done(10, 25, $rep(10, 1, 2, 3, 4, 0)), $done(20, 40, $rep(20, 2, 3, 4, 5, 1))]);
check('totals: sums of two reports (decisions 0 + 1)', [$t['shown'], $t['voluntarios'], $t['visitantes'], $t['hillsongKids'], $t['decisoes']], [65, 3, 5, 16, 1]);
check('totals: a zero is a figure (not hidden as null)', week_totals([$done(10, 25, $rep(10, 0, 0, 0, 0, 0))])['decisoes'], 0);

echo $failures ? "\n$failures FAILED\n" : "\nall passed\n";
exit($failures ? 1 : 0);
