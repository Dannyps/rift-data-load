<?php

// Statistics. This file holds the metrics as PURE functions over the balance_entries() rows, so they can be tested
// without a database (tests/stats_test.php). Entries: ['timestamp' => naive UTC 'Y-m-d H:i:s', 'd_in' => int,
// 'balance' => int, ...], oldest first. A reading's timestamp is the end of the minute it describes.

require_once(__DIR__ . "/balance.php");

const STATS_ARRIVALS_BEFORE_MIN = 60;      // arrivals are counted from this long before the start ...
const STATS_DEVOTIONAL_WINDOW_MIN = 5;     // devotional count: highest balance within this long after its time
const STATS_EXIT_WINDOW_MIN = 180;         // exit speed: look this long after the reference time
const STATS_EXIT_TOLERANCE_MIN = 10;       // ... and there must be a reading this close to the reference time

const STATS_DEFAULTS = [
    'late_grace_min' => '10',
    'devotional_morning' => '08:43',
    'devotional_afternoon' => '16:58',
    'exit_ref_1' => '13:00',
    'exit_ref_2' => '19:00',
    'exit_threshold' => '5',
];

function stats_utc(string $naive): DateTimeImmutable
{
    return new DateTimeImmutable($naive, new DateTimeZone('UTC'));
}

/** Settings as strings, defaults filled in. */
function stats_settings($con): array
{
    $out = STATS_DEFAULTS;
    foreach (pg_fetch_all(pg_query($con, 'SELECT key, value FROM settings')) ?: [] as $r) {
        if (isset($out[$r['key']])) {
            $out[$r['key']] = $r['value'];
        }
    }
    return $out;
}

/** Why a settings value is not acceptable (null = fine). */
function stats_setting_problem(string $key, string $value): ?string
{
    switch ($key) {
        case 'late_grace_min':
            return preg_match('/^\d{1,3}$/', $value) && (int)$value <= 120 ? null : 'Indique minutos entre 0 e 120.';
        case 'exit_threshold':
            return preg_match('/^\d{1,4}$/', $value) ? null : 'Indique um número inteiro.';
        case 'devotional_morning':
        case 'devotional_afternoon':
        case 'exit_ref_1':
        case 'exit_ref_2':
            return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? null : 'Use o formato HH:MM.';
    }
    return 'Definição desconhecida.';
}

/**
 * Arrivals for one meeting: [total, late] = counted entries in (start - 60 min, end] up to $now, and those more
 * than $graceMin minutes after the start.
 */
function stats_arrivals(array $entries, DateTimeImmutable $start, DateTimeImmutable $end, int $graceMin, DateTimeImmutable $now): array
{
    $from = $start->modify('-' . STATS_ARRIVALS_BEFORE_MIN . ' minutes');
    $lateAfter = $start->modify("+$graceMin minutes");
    $total = $late = 0;
    foreach ($entries as $e) {
        $t = stats_utc($e['timestamp']);
        if ($t > $from && $t <= $end && $t <= $now) {
            $total += (int)$e['d_in'];
            if ($t > $lateAfter) {
                $late += (int)$e['d_in'];
            }
        }
    }
    return ['total' => $total, 'late' => $late];
}

/** late share in percent, or null when nobody arrived */
function stats_late_pct(?int $total, ?int $late): ?float
{
    return $total === null || $late === null || $total <= 0 ? null : round(100 * $late / $total, 1);
}

/** Highest balance in [devAt, devAt + 5 min] (readings up to $now), or null when there is no reading there yet. */
function stats_devotional_peak(array $entries, DateTimeImmutable $devAt, DateTimeImmutable $now): ?int
{
    $to = $devAt->modify('+' . STATS_DEVOTIONAL_WINDOW_MIN . ' minutes');
    $peak = null;
    foreach ($entries as $e) {
        $t = stats_utc($e['timestamp']);
        if ($t >= $devAt && $t <= $to && $t <= $now && ($peak === null || (int)$e['balance'] > $peak)) {
            $peak = (int)$e['balance'];
        }
    }
    return $peak;
}

/**
 * Exit speed after $ref (UTC): minutes until the first reading with balance <= $threshold, looking 3 h ahead.
 * Returns ['state' => reached|not_reached|no_data|pending, 'minutes' => int|null].
 * pending = the answer is not known yet (the window has not closed and the threshold was not reached);
 * no_data = no reading within 10 minutes of the reference time (e.g. before the counter existed).
 */
function stats_exit(array $entries, DateTimeImmutable $ref, int $threshold, DateTimeImmutable $now): array
{
    if ($now < $ref) {
        return ['state' => 'pending', 'minutes' => null];
    }
    $end = $ref->modify('+' . STATS_EXIT_WINDOW_MIN . ' minutes');
    $limit = $now < $end ? $now : $end;
    $first = null;
    foreach ($entries as $e) {
        $t = stats_utc($e['timestamp']);
        if ($t < $ref || $t > $limit) {
            continue;
        }
        if ($first === null) {
            $first = $t;
            if ($t > $ref->modify('+' . STATS_EXIT_TOLERANCE_MIN . ' minutes')) {
                return ['state' => 'no_data', 'minutes' => null];
            }
        }
        if ((int)$e['balance'] <= $threshold) {
            return ['state' => 'reached', 'minutes' => max(0, (int)round(($t->getTimestamp() - $ref->getTimestamp()) / 60))];
        }
    }
    if ($first === null) {
        return $now >= $ref->modify('+' . STATS_EXIT_TOLERANCE_MIN . ' minutes')
            ? ['state' => 'no_data', 'minutes' => null]
            : ['state' => 'pending', 'minutes' => null];
    }
    return $now >= $end ? ['state' => 'not_reached', 'minutes' => null] : ['state' => 'pending', 'minutes' => null];
}

/** Devotional time (naive UTC) of a meeting: from its Planning Center plan, else the setting for morning/afternoon. */
function stats_devotional_time(?string $planWall, string $day, string $startTime, array $settings): DateTimeImmutable
{
    $local = new DateTimeZone(LOCAL_TZ);
    if ($planWall !== null && $planWall !== '') {
        return (new DateTimeImmutable($planWall, $local))->setTimezone(new DateTimeZone('UTC'));
    }
    $key = substr($startTime, 0, 5) < '13:00' ? 'devotional_morning' : 'devotional_afternoon';
    return (new DateTimeImmutable("$day {$settings[$key]}", $local))->setTimezone(new DateTimeZone('UTC'));
}

// ---------------------------------------------------------------- small pure helpers for the page

/** Sum of the non-null values of $field over the rows, or null when none has a value. */
function stats_sum(array $rows, string $field): ?int
{
    $sum = null;
    foreach ($rows as $r) {
        if (isset($r[$field])) {
            $sum = ($sum ?? 0) + (int)$r[$field];
        }
    }
    return $sum;
}

function stats_mean(array $values, int $decimals = 1): ?float
{
    $values = array_values(array_filter($values, fn($v) => $v !== null));
    return $values ? round(array_sum($values) / count($values), $decimals) : null;
}

/** current - previous, null if either is missing */
function stats_delta($current, $previous)
{
    return $current === null || $previous === null ? null : $current - $previous;
}
