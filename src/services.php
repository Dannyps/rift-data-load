<?php

// Per-service attendance. The count of a service is the peak balance (people inside, see
// balance.php) during its window; a service window is start_time .. start_time + duration_min.
// Results are saved in service_snapshots, updated while the service runs and frozen once it is over.
// On days with no scheduled service, more than EVENT_THRESHOLD people inside makes an "event"
// (event_snapshots, at most one per local day).

require_once(__DIR__ . "/balance.php");
require_once(__DIR__ . "/pco.php");

const EVENT_THRESHOLD = 5;
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

    // Which snapshots still need work: started and not frozen yet.
    $todo = [];
    foreach ($services as $s) {
        $row = $saved[$s['day'] . ' ' . $s['start_time']] ?? null;
        if (parse_ts($s['window_start']) <= $nowUtc && (!$row || $row['frozen'] !== 't')) {
            $todo[] = $s;
        }
    }
    $eventDays = [];
    for ($i = 0; $i < 7; $i++) {
        $day = (new DateTimeImmutable($sunday, utc()))->modify("+$i days")->format('Y-m-d');
        $dayStart = parse_ts(day_start_utc(new DateTimeImmutable("$day 12:00", local_tz())));
        $row = $savedEvents[$day] ?? null;
        if (!isset($serviceDays[$day]) && $dayStart <= $nowUtc && (!$row || $row['frozen'] !== 't')) {
            $eventDays[$day] = $dayStart;
        }
    }

    if ($todo || $eventDays) {
        $firstReading = pg_fetch_result(pg_query($con, 'SELECT min("timestamp") FROM records'), 0, 0);
        $weekStart = new DateTimeImmutable($sunday . ' 12:00', local_tz());
        $entries = $firstReading ? balance_entries($con, $weekStart) : [];

        foreach ($todo as $s) {
            snapshot_service($con, $s, $entries, $firstReading, $nowUtc, $grace);
        }
        foreach ($eventDays as $day => $dayStart) {
            snapshot_event($con, $day, $dayStart, $entries, $nowUtc, $grace);
        }
    }

    return week_view($con, $now, $sunday, $services);
}

function snapshot_service($con, array $s, array $entries, ?string $firstReading, DateTimeImmutable $now, DateInterval $grace): void
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

    pg_query_params($con, "
        INSERT INTO service_snapshots (day, start_time, name, window_start, window_end, peak, peak_at, partial, frozen, updated_at)
        VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, now())
        ON CONFLICT (day, start_time) DO UPDATE SET
            name = EXCLUDED.name, window_start = EXCLUDED.window_start, window_end = EXCLUDED.window_end,
            peak = EXCLUDED.peak, peak_at = EXCLUDED.peak_at, partial = EXCLUDED.partial,
            frozen = EXCLUDED.frozen, updated_at = now()
        WHERE NOT service_snapshots.frozen
    ", [
        $s['day'], $s['start_time'], $s['name'], $s['window_start'], $s['window_end'], $peak, $peakAt,
        // Data that only starts after the service began makes its count a lower bound.
        ($firstReading === null || parse_ts($firstReading) > $ws) ? 't' : 'f',
        $now > $we->add($grace) ? 't' : 'f',
    ]);
}

function snapshot_event($con, string $day, DateTimeImmutable $dayStart, array $entries, DateTimeImmutable $now, DateInterval $grace): void
{
    $first = $last = $peak = $peakAt = null;
    foreach ($entries as $e) {
        if ($e['day'] === $day && (int)$e['balance'] > EVENT_THRESHOLD) {
            $first ??= $e['timestamp'];
            $last = $e['timestamp'];
            if ($peak === null || (int)$e['balance'] > $peak) {
                $peak = (int)$e['balance'];
                $peakAt = $e['timestamp'];
            }
        }
    }

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
        $wall = strtotime($s['day'] . ' ' . $time);
        $matched = [];
        foreach ($plans[$s['day']] ?? [] as $plan) {
            foreach ($plan['times'] as $t) {
                if ($wall >= strtotime($t['start']) - PCO_MATCH_SLACK_MIN * 60 && $wall <= strtotime($t['end'])) {
                    $matched[] = $plan;
                    break;
                }
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
