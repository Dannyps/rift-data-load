<?php

// Per-service attendance. The count of a service is the peak balance (people inside, see
// balance.php) during its window; a service window is start_time .. start_time + duration_min.
// Results are saved in service_snapshots, updated while the service runs and frozen once it is over.
// More than EVENT_THRESHOLD people inside, in a stretch that is not part of a scheduled service's
// crowd, makes an "event" (event_snapshots, at most one per local day; see event_stretch).

require_once(__DIR__ . "/balance.php");
require_once(__DIR__ . "/pco.php");
require_once(__DIR__ . "/stats.php");

const EVENT_THRESHOLD = 5;
// Stretches above the threshold that are closer than this are one: a dip of a few minutes
// (one person leaving at 6 -> 5) is not the building emptying.
const EVENT_MERGE_GAP_MIN = 15;
// The device reports each minute about 4 minutes late, so wait before freezing.
const FREEZE_GRACE_MIN = 10;

const DB_TS = 'Y-m-d H:i:s';

function utc(): DateTimeZone
{
    static $z;
    return $z ??= new DateTimeZone('UTC');
}

function local_tz(): DateTimeZone
{
    static $z;
    return $z ??= new DateTimeZone(LOCAL_TZ);
}

/** The Sunday (local date, 'Y-m-d') starting the week that contains $day ('Y-m-d'). */
function week_start(string $day): string
{
    $d = new DateTimeImmutable($day, local_tz());
    return $d->modify('-' . $d->format('w') . ' days')->format('Y-m-d');
}

/**
 * The services scheduled in the week from $sunday: a date with special_days rows uses only those,
 * otherwise the active weekly services for its weekday. Windows are naive UTC (DST handled by Postgres).
 */
function scheduled_services($con, string $sunday): array
{
    $res = pg_query_params($con, "
        WITH days AS (
            SELECT d::date AS day FROM generate_series($1::date, $1::date + 6, interval '1 day') d
        ), specials AS (
            SELECT s.day, s.name, s.start_time, s.duration_min FROM special_days s JOIN days USING (day)
        ), weekly AS (
            SELECT d.day, v.name, v.start_time, v.duration_min
            FROM days d JOIN services v ON v.weekday = extract(dow FROM d.day) AND v.active
            WHERE NOT EXISTS (SELECT 1 FROM specials sp WHERE sp.day = d.day)
        ), all_services AS (
            SELECT *, true AS special FROM specials UNION ALL SELECT *, false FROM weekly
        )
        SELECT day, name, start_time, duration_min, special,
               ((day + start_time) AT TIME ZONE $2) AT TIME ZONE 'UTC' AS window_start,
               (((day + start_time) AT TIME ZONE $2) AT TIME ZONE 'UTC') + make_interval(mins => duration_min) AS window_end
        FROM all_services ORDER BY day, start_time
    ", [$sunday, LOCAL_TZ]);

    return pg_fetch_all($res) ?: [];
}

function parse_ts(string $naiveUtc): DateTimeImmutable
{
    return new DateTimeImmutable($naiveUtc, utc());
}

function iso(?string $naiveUtc): ?string
{
    return $naiveUtc === null ? null : parse_ts($naiveUtc)->format('Y-m-d\TH:i:s\Z');
}

/**
 * Brings the saved snapshots of the week from $sunday up to date as of $now and returns the week.
 * Frozen snapshots are never touched; a manual corrected_peak wins when displaying.
 */
function services_week($con, DateTimeImmutable $now, string $sunday): array
{
    $services = scheduled_services($con, $sunday);

    $saved = [];
    foreach (pg_fetch_all(pg_query_params($con, "SELECT * FROM service_snapshots WHERE day BETWEEN $1::date AND $1::date + 6", [$sunday])) ?: [] as $r) {
        $saved[$r['day'] . ' ' . $r['start_time']] = $r;
    }
    $savedEvents = [];
    foreach (pg_fetch_all(pg_query_params($con, "SELECT * FROM event_snapshots WHERE day BETWEEN $1::date AND $1::date + 6", [$sunday])) ?: [] as $r) {
        $savedEvents[$r['day']] = $r;
    }

    $nowUtc = $now->setTimezone(utc());
    $grace = new DateInterval('PT' . FREEZE_GRACE_MIN . 'M');
    $serviceDays = array_flip(array_column($services, 'day'));
    $settings = stats_settings($con);
    $devotionalPlans = plans_by_day($con, $sunday);
    $savedExit = [];
    foreach (pg_fetch_all(pg_query_params($con, "SELECT day, to_char(ref_time, 'HH24:MI') AS ref FROM exit_stats WHERE day BETWEEN $1::date AND $1::date + 6", [$sunday])) ?: [] as $r) {
        $savedExit[$r['day'] . ' ' . $r['ref']] = true;
    }

    // Which snapshots still need work: started and not frozen yet.
    $todo = [];
    foreach ($services as $s) {
        $row = $saved[$s['day'] . ' ' . $s['start_time']] ?? null;
        // also a frozen meeting that predates the statistics columns: filled once (see snapshot_service)
        if (parse_ts($s['window_start']) <= $nowUtc && (!$row || $row['frozen'] !== 't' || $row['arrivals_total'] === null)) {
            $todo[] = $s;
        }
    }
    // Exit speed after each reference hour (13:00, 19:00) of a day with meetings, once the hour has passed.
    $exitTodo = [];
    foreach (array_keys($serviceDays) as $day) {
        $firstStart = min(array_map(fn($s) => substr($s['start_time'], 0, 5), array_filter($services, fn($s) => $s['day'] === $day)));
        foreach (['exit_ref_1', 'exit_ref_2'] as $k) {
            $ref = $settings[$k];
            $refUtc = (new DateTimeImmutable("$day $ref", local_tz()))->setTimezone(utc());
            if ($ref > $firstStart && $refUtc <= $nowUtc && empty($savedExit["$day $ref"]) && $refUtc > $nowUtc->modify('-9 days')) {
                $exitTodo[] = [$day, $ref, $refUtc];
            }
        }
    }
    $eventDays = [];
    for ($i = 0; $i < 7; $i++) {
        $day = (new DateTimeImmutable($sunday, utc()))->modify("+$i days")->format('Y-m-d');
        $dayStart = parse_ts(day_start_utc(new DateTimeImmutable("$day 12:00", local_tz())));
        $row = $savedEvents[$day] ?? null;
        if ($dayStart <= $nowUtc && (!$row || $row['frozen'] !== 't')) {
            $eventDays[$day] = $dayStart;
        }
    }

    if ($todo || $eventDays || $exitTodo) {
        $firstReading = pg_fetch_result(pg_query($con, 'SELECT min("timestamp") FROM records'), 0, 0);
        $weekStart = new DateTimeImmutable($sunday . ' 12:00', local_tz());
        $entries = $firstReading ? balance_entries($con, $weekStart) : [];

        foreach ($todo as $s) {
            $devWall = null;
            foreach ($devotionalPlans[$s['day']] ?? [] as $plan) {
                if ($plan['devotional'] && plan_matches_meeting($plan['times'], $s['day'], substr($s['start_time'], 0, 5))) {
                    $devWall = $plan['devotional'];
                    break;
                }
            }
            snapshot_service($con, $s, $entries, $firstReading, $nowUtc, $grace, $settings, stats_devotional_time($devWall, $s['day'], $s['start_time'], $settings));
        }
        foreach ($exitTodo as [$day, $ref, $refUtc]) {
            snapshot_exit($con, $day, $ref, $refUtc, $entries, $nowUtc, (int)$settings['exit_threshold']);
        }
        foreach ($eventDays as $day => $dayStart) {
            $windows = array_map(fn($s) => [$s['window_start'], $s['window_end']], array_values(array_filter($services, fn($s) => $s['day'] === $day)));
            snapshot_event($con, $day, $dayStart, $entries, $nowUtc, $grace, $windows);
        }
    }

    return week_view($con, $now, $sunday, $services);
}

function snapshot_service($con, array $s, array $entries, ?string $firstReading, DateTimeImmutable $now, DateInterval $grace, array $settings, DateTimeImmutable $devotionalAt): void
{
    $ws = parse_ts($s['window_start']);
    $we = parse_ts($s['window_end']);

    $peak = $peakAt = null;
    foreach ($entries as $e) {
        $t = parse_ts($e['timestamp']);
        if ($t >= $ws && $t < $we && $t <= $now && ($peak === null || (int)$e['balance'] > $peak)) {
            $peak = (int)$e['balance'];
            $peakAt = $e['timestamp'];
        }
    }

    // Statistics saved with the snapshot: arrivals (late share uses the grace in force NOW; once the meeting is
    // frozen the numbers and the grace they were computed with never change) and the devotional head-count.
    $graceMin = (int)$settings['late_grace_min'];
    $arrivals = stats_arrivals($entries, $ws, $we, $graceMin, $now);
    $devotional = stats_devotional_peak($entries, $devotionalAt, $now);
    $devotionalAt = $devotionalAt->format(DB_TS);

    pg_query_params($con, "
        INSERT INTO service_snapshots (day, start_time, name, window_start, window_end, peak, peak_at, partial, frozen, updated_at,
                                       arrivals_total, arrivals_late, late_grace_min, devotional_at, devotional_peak)
        VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, now(), $10, $11, $12, $13, $14)
        ON CONFLICT (day, start_time) DO UPDATE SET
            name = EXCLUDED.name, window_start = EXCLUDED.window_start, window_end = EXCLUDED.window_end,
            peak = EXCLUDED.peak, peak_at = EXCLUDED.peak_at, partial = EXCLUDED.partial,
            frozen = EXCLUDED.frozen, updated_at = now(),
            arrivals_total = EXCLUDED.arrivals_total, arrivals_late = EXCLUDED.arrivals_late, late_grace_min = EXCLUDED.late_grace_min,
            devotional_at = EXCLUDED.devotional_at, devotional_peak = EXCLUDED.devotional_peak
        WHERE NOT service_snapshots.frozen
    ", [
        $s['day'], $s['start_time'], $s['name'], $s['window_start'], $s['window_end'], $peak, $peakAt,
        // Data that only starts after the service began makes its count a lower bound.
        ($firstReading === null || parse_ts($firstReading) > $ws) ? 't' : 'f',
        $now > $we->add($grace) ? 't' : 'f',
        $arrivals['total'], $arrivals['late'], $graceMin, $devotionalAt, $devotional,
    ]);

    // a meeting frozen before these columns existed: fill them once, with today's settings
    pg_query_params($con, "
        UPDATE service_snapshots SET arrivals_total = $3, arrivals_late = $4, late_grace_min = $5, devotional_at = $6, devotional_peak = $7
        WHERE day = $1 AND start_time = $2 AND frozen AND arrivals_total IS NULL",
        [$s['day'], $s['start_time'], $arrivals['total'], $arrivals['late'], $graceMin, $devotionalAt, $devotional]);
}

/** Saves the exit speed after one reference hour once it is known (reached) or the 3 h window has closed. */
function snapshot_exit($con, string $day, string $ref, DateTimeImmutable $refUtc, array $entries, DateTimeImmutable $now, int $threshold): void
{
    $r = stats_exit($entries, $refUtc, $threshold, $now);
    if ($r['state'] === 'pending') {
        return;
    }
    pg_query_params($con, "
        INSERT INTO exit_stats (day, ref_time, state, minutes, threshold, frozen)
        VALUES ($1, $2, $3, $4, $5, true) ON CONFLICT (day, ref_time) DO NOTHING",
        [$day, $ref, $r['state'], $r['minutes'], $threshold]);
}

/** Planning Center plans of the week by day: [day => [['times' => [...], 'devotional' => wall|null], ...]] */
function plans_by_day($con, string $sunday): array
{
    $out = [];
    foreach (pg_fetch_all(pg_query_params($con, 'SELECT day, service_times, devotional_start FROM pco_plans WHERE day BETWEEN $1::date AND $1::date + 6 ORDER BY service_type_id', [$sunday])) ?: [] as $r) {
        $out[$r['day']][] = ['times' => json_decode($r['service_times'], true), 'devotional' => $r['devotional_start']];
    }
    return $out;
}

/** Does a plan (its service times, wall-clock strings) cover a meeting of its day that starts at $time ('HH:MM')? 30 min early is allowed. */
function plan_matches_meeting(array $times, string $day, string $time): bool
{
    $wall = strtotime("$day $time");
    foreach ($times as $t) {
        if ($wall >= strtotime($t['start']) - PCO_MATCH_SLACK_MIN * 60 && $wall <= strtotime($t['end'])) {
            return true;
        }
    }
    return false;
}

/**
 * The event of a local day, or null. An event is a stretch of consecutive readings with more than
 * EVENT_THRESHOLD people inside that does not touch any meeting window of that day (a stretch that
 * does is the meeting's own crowd, arrivals before the start included). Stretches less than
 * EVENT_MERGE_GAP_MIN apart count as one. With several remaining stretches
 * the one with the highest peak wins (then the longest). $windows = [[window_start, window_end], ...]
 * as naive UTC strings. Returns [started_at, ended_at, peak, peak_at].
 */
function event_stretch(array $entries, string $day, array $windows): ?array
{
    $stretches = [];
    $cur = null;
    foreach ($entries as $e) {
        if ($e['day'] !== $day) {
            continue;
        }
        if ((int)$e['balance'] > EVENT_THRESHOLD) {
            $cur ??= ['first' => $e['timestamp'], 'last' => $e['timestamp'], 'peak' => 0, 'peakAt' => $e['timestamp']];
            $cur['last'] = $e['timestamp'];
            if ((int)$e['balance'] > $cur['peak']) {
                $cur['peak'] = (int)$e['balance'];
                $cur['peakAt'] = $e['timestamp'];
            }
        } elseif ($cur) {
            $stretches[] = $cur;
            $cur = null;
        }
    }
    if ($cur) {
        $stretches[] = $cur;
    }

    $merged = [];
    foreach ($stretches as $st) {
        $prev = end($merged);
        if ($prev && parse_ts($st['first'])->getTimestamp() - parse_ts($prev['last'])->getTimestamp() <= EVENT_MERGE_GAP_MIN * 60) {
            $k = array_key_last($merged);
            $merged[$k]['last'] = $st['last'];
            if ($st['peak'] > $prev['peak']) {
                $merged[$k]['peak'] = $st['peak'];
                $merged[$k]['peakAt'] = $st['peakAt'];
            }
        } else {
            $merged[] = $st;
        }
    }
    $stretches = $merged;

    $best = null;
    foreach ($stretches as $st) {
        foreach ($windows as [$ws, $we]) {
            if (parse_ts($ws) <= parse_ts($st['last']) && parse_ts($we) >= parse_ts($st['first'])) {
                continue 2;
            }
        }
        $len = parse_ts($st['last'])->getTimestamp() - parse_ts($st['first'])->getTimestamp();
        if (!$best || $st['peak'] > $best['peak'] || ($st['peak'] === $best['peak'] && $len > $best['len'])) {
            $best = $st + ['len' => $len];
        }
    }
    return $best ? [$best['first'], $best['last'], $best['peak'], $best['peakAt']] : null;
}

function snapshot_event($con, string $day, DateTimeImmutable $dayStart, array $entries, DateTimeImmutable $now, DateInterval $grace, array $windows = []): void
{
    [$first, $last, $peak, $peakAt] = event_stretch($entries, $day, $windows) ?? [null, null, null, null];

    // A row without a peak means "looked, no event"; it is saved so a past day is not re-examined.
    $frozen = $now > $dayStart->modify('+1 day')->add($grace);

    pg_query_params($con, "
        INSERT INTO event_snapshots (day, started_at, ended_at, peak, peak_at, frozen, updated_at)
        VALUES ($1, $2, $3, $4, $5, $6, now())
        ON CONFLICT (day) DO UPDATE SET
            started_at = EXCLUDED.started_at, ended_at = EXCLUDED.ended_at,
            peak = EXCLUDED.peak, peak_at = EXCLUDED.peak_at, frozen = EXCLUDED.frozen, updated_at = now()
        WHERE NOT event_snapshots.frozen
    ", [$day, $first, $last, $peak, $peakAt, $frozen ? 't' : 'f']);
}

const PEOPLE_ROLES = ['mc' => 'MC', 'oferta' => 'Oferta', 'lider' => 'Líder louvor', 'pregador' => 'Pregador'];

/**
 * The people on each meeting of the week, "day start_time" => [role => ['value' => text|null, 'source' => 'pco'|'manual'|null]]
 * or null when no Planning Center plan matches the meeting and nothing was set by hand.
 * A plan matches a meeting of its day that starts within the plan's service times (30 min early allowed):
 * the Sunday morning plan covers both the 09:30 and the 11:30 meeting. Manual overrides win.
 */
function week_people($con, string $sunday, array $services, bool $withOverrides = true): array
{
    $plans = [];
    foreach (pg_fetch_all(pg_query_params($con, 'SELECT * FROM pco_plans WHERE day BETWEEN $1::date AND $1::date + 6 ORDER BY service_type_id', [$sunday])) ?: [] as $r) {
        $plans[$r['day']][] = ['times' => json_decode($r['service_times'], true), 'raw' => json_decode($r['people_raw'] ?? 'null', true)];
    }
    $overrides = [];
    foreach (pg_fetch_all(pg_query_params($con, "SELECT day, to_char(start_time, 'HH24:MI') AS t, role, value FROM people_overrides WHERE day BETWEEN $1::date AND $1::date + 6", [$sunday])) ?: [] as $r) {
        $overrides[$r['day'] . ' ' . $r['t']][$r['role']] = $r['value'];
    }
    if (!$withOverrides) {
        $overrides = [];
    }

    // meetings of each day, to split tagged notes ("9h30 X / 11h30 Y") per meeting
    $byDay = [];
    foreach ($services as $s) {
        $byDay[$s['day']][] = substr($s['start_time'], 0, 5);
    }

    $out = [];
    foreach ($services as $s) {
        $time = substr($s['start_time'], 0, 5);
        $key = $s['day'] . ' ' . $time;
        $matched = [];
        foreach ($plans[$s['day']] ?? [] as $plan) {
            if (plan_matches_meeting($plan['times'], $s['day'], $time)) {
                $matched[] = $plan;
            }
        }
        $manual = $overrides[$key] ?? [];
        if (!$matched && !$manual) {
            $out[$key] = null;
            continue;
        }

        $people = [];
        foreach (array_keys(PEOPLE_ROLES) as $role) {
            $value = $source = $flag = null;
            if (isset($manual[$role])) {
                [$value, $source] = [$manual[$role], 'manual'];
            } else {
                foreach ($matched as $plan) {
                    $raw = $plan['raw'] ?? null;
                    if ($role === 'lider') {
                        $v = $raw && $raw['lider'] ? implode(' e ', $raw['lider']) : null;
                    } else {
                        [$parsed, $flags] = $raw ? pco_parse_person($raw[$role] ?? null, $byDay[$s['day']]) : [[], []];
                        $v = $parsed[$time] ?? null;
                        $flag = $flags ? implode(', ', $flags) : null;
                        // no Person note at all: the name may be in the item's Band note (only if it reads like a name)
                        if ($raw && trim((string)($raw[$role] ?? '')) === '') {
                            [$bandParsed, $bandFlags] = pco_parse_person($raw[$role . '_band'] ?? null, $byDay[$s['day']]);
                            $bv = $bandParsed[$time] ?? null;
                            if (!$bandFlags && pco_band_value_ok($bv)) {
                                [$v, $flag] = [$bv, 'nota Banda'];
                            }
                        }
                    }
                    if ($v !== null && $v !== '') {
                        [$value, $source] = [$v, 'pco'];
                        break;
                    }
                }
            }
            $people[$role] = ['value' => $value, 'source' => $source, 'flag' => $source === 'pco' ? $flag : null];
        }
        $out[$key] = $people;
    }
    return $out;
}

/** The saved state shaped for the page: only days with a scheduled service or an event. */
function week_view($con, DateTimeImmutable $now, string $sunday, array $services): array
{
    $nowUtc = $now->setTimezone(utc());

    $saved = [];
    foreach (pg_fetch_all(pg_query_params($con, "SELECT * FROM service_snapshots WHERE day BETWEEN $1::date AND $1::date + 6", [$sunday])) ?: [] as $r) {
        $saved[$r['day'] . ' ' . $r['start_time']] = $r;
    }
    $events = [];
    foreach (pg_fetch_all(pg_query_params($con, "SELECT * FROM event_snapshots WHERE day BETWEEN $1::date AND $1::date + 6", [$sunday])) ?: [] as $r) {
        $events[$r['day']] = $r;
    }

    $people = week_people($con, $sunday, $services);

    $days = [];
    foreach ($services as $s) {
        $ws = parse_ts($s['window_start']);
        $we = parse_ts($s['window_end']);
        $row = $saved[$s['day'] . ' ' . $s['start_time']] ?? null;

        if ($nowUtc < $ws) {
            $status = 'unknown';
        } elseif ($nowUtc < $we) {
            $status = 'running';
        } else {
            $status = 'done';
        }

        $count = null;
        if ($status !== 'unknown' && $row) {
            $count = $row['corrected_peak'] !== null ? (int)$row['corrected_peak'] : ($row['peak'] !== null ? (int)$row['peak'] : null);
        }

        $days[$s['day']]['services'][] = [
            'name' => $s['name'],
            'time' => substr($s['start_time'], 0, 5),
            'start' => iso($s['window_start']),
            'end' => iso($s['window_end']),
            'status' => $status,
            'count' => $count,
            'partial' => $status !== 'unknown' && $row && $row['partial'] === 't',
            'special' => $s['special'] === 't',
            'people' => $people[$s['day'] . ' ' . substr($s['start_time'], 0, 5)] ?? null,
        ];
    }
    foreach ($events as $day => $e) {
        if ($e['peak'] === null) {
            continue;
        }
        // A meeting added after the event was recorded covers it: keep the row, show only the meeting.
        foreach ($services as $s) {
            if ($s['day'] === $day && $s['window_start'] <= $e['ended_at'] && $s['window_end'] >= $e['started_at']) {
                continue 2;
            }
        }
        $days[$day]['event'] = [
            'start' => iso($e['started_at']),
            'end' => iso($e['ended_at']),
            'count' => $e['corrected_peak'] !== null ? (int)$e['corrected_peak'] : (int)$e['peak'],
            'peakAt' => iso($e['peak_at']),
        ];
    }
    ksort($days);

    $out = [];
    foreach ($days as $day => $d) {
        $out[] = ['date' => $day, 'services' => $d['services'] ?? [], 'event' => $d['event'] ?? null];
    }

    $prev = (new DateTimeImmutable($sunday))->modify('-7 days')->format('Y-m-d');
    $next = (new DateTimeImmutable($sunday))->modify('+7 days')->format('Y-m-d');

    return [
        'weekStart' => $sunday,
        'weekEnd' => (new DateTimeImmutable($sunday))->modify('+6 days')->format('Y-m-d'),
        'prev' => $prev,
        'next' => $next,
        'isCurrent' => $sunday === week_start($now->setTimezone(local_tz())->format('Y-m-d')),
        'today' => $now->setTimezone(local_tz())->format('Y-m-d'),
        'timezone' => LOCAL_TZ,
        'days' => $out,
    ];
}

/** What Planning Center alone gives for one role of one meeting ("day HH:MM" key), ignoring manual overrides. */
function pco_value_for($con, string $sunday, array $services, string $key, string $role): ?string
{
    static $cache = [];
    $cache[$sunday] ??= week_people($con, $sunday, $services, false);
    return $cache[$sunday][$key][$role]['value'] ?? null;
}
