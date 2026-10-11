<?php

// Data for the statistics page and the saved meeting reports. Reads the snapshots (service_snapshots, exit_stats),
// the reports typed in the meeting dialog (meeting_reports) and, for the progression chart, the readings.
// The metrics themselves are in stats.php (pure, tested).

require_once(__DIR__ . "/services.php");

const REPORT_NUMBERS = ['assistencia', 'voluntarios', 'visitantes', 'adultos_fora', 'kids', 'auditorio', 'decisoes'];
const REPORT_NAMES = ['mc', 'oferta', 'lider', 'pregacao'];

// ---------------------------------------------------------------- reports (typed in the dialog)

function report_get($con, string $day, string $time): ?array
{
    $r = pg_fetch_assoc(pg_query_params($con, "SELECT * FROM meeting_reports WHERE day = $1 AND start_time = $2", [$day, $time]));
    if (!$r) {
        return null;
    }
    $out = ['savedBy' => $r['saved_by'], 'savedAt' => $r['saved_at']];
    foreach (REPORT_NUMBERS as $k) {
        $out[$k] = (int)$r[$k];
    }
    foreach (REPORT_NAMES as $k) {
        $out[$k] = $r[$k];
    }
    return $out;
}

/** What the dialog can suggest for a meeting: the counter's count and the head-count at the devotional. */
function report_suggestions($con, string $day, string $time): array
{
    $r = pg_fetch_assoc(pg_query_params($con, "SELECT peak, corrected_peak, devotional_at, devotional_peak FROM service_snapshots WHERE day = $1 AND start_time = $2", [$day, $time])) ?: [];
    $count = $r['corrected_peak'] ?? $r['peak'] ?? null;
    return [
        'assistencia' => $count === null ? null : (int)$count,
        'voluntarios' => isset($r['devotional_peak']) ? (int)$r['devotional_peak'] : null,
        'devotionalAt' => isset($r['devotional_at']) ? iso($r['devotional_at']) : null,
    ];
}

/**
 * Validates and saves a report. $in is the raw request data. Returns [null, saved row] or [error message, null].
 * The meeting must exist in the schedule and have started.
 */
function report_save($con, string $user, DateTimeImmutable $now, array $in): array
{
    $day = (string)($in['day'] ?? '');
    $time = (string)($in['time'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        return ['Reunião inválida.', null];
    }
    $meeting = null;
    foreach (scheduled_services($con, week_start($day)) as $s) {
        if ($s['day'] === $day && substr($s['start_time'], 0, 5) === $time) {
            $meeting = $s;
        }
    }
    if (!$meeting) {
        return ['Essa reunião não existe.', null];
    }
    if (parse_ts($meeting['window_start']) > $now->setTimezone(utc())) {
        return ['A reunião ainda não começou.', null];
    }

    $values = [];
    foreach (REPORT_NUMBERS as $k) {
        $v = trim((string)($in[$k] ?? ''));
        if (!preg_match('/^\d{1,6}$/', $v)) {
            return ["Valor inválido em «$k»: indique um número inteiro (0 ou mais).", null];
        }
        $values[$k] = (int)$v;
    }
    foreach (REPORT_NAMES as $k) {
        $v = trim(preg_replace('/\s+/u', ' ', (string)($in[$k] ?? '')));
        if (mb_strlen($v) > 100) {
            return ["O nome em «$k» é demasiado longo.", null];
        }
        $values[$k] = $v;
    }

    pg_query_params($con, "
        INSERT INTO meeting_reports (day, start_time, assistencia, voluntarios, visitantes, adultos_fora, kids, auditorio, decisoes, mc, oferta, lider, pregacao, saved_by, saved_at)
        VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, now())
        ON CONFLICT (day, start_time) DO UPDATE SET assistencia = EXCLUDED.assistencia, voluntarios = EXCLUDED.voluntarios, visitantes = EXCLUDED.visitantes,
            adultos_fora = EXCLUDED.adultos_fora, kids = EXCLUDED.kids, auditorio = EXCLUDED.auditorio, decisoes = EXCLUDED.decisoes,
            mc = EXCLUDED.mc, oferta = EXCLUDED.oferta, lider = EXCLUDED.lider, pregacao = EXCLUDED.pregacao, saved_by = EXCLUDED.saved_by, saved_at = now()",
        [$day, $time, $values['assistencia'], $values['voluntarios'], $values['visitantes'], $values['adultos_fora'], $values['kids'], $values['auditorio'], $values['decisoes'],
         $values['mc'], $values['oferta'], $values['lider'], $values['pregacao'], $user]);

    return [null, report_get($con, $day, $time)];
}

// ---------------------------------------------------------------- meetings with their metrics

/** Meetings (snapshots joined with their saved report) between two dates, as plain rows. */
function stats_rows($con, string $from, string $to): array
{
    return pg_fetch_all(pg_query_params($con, "
        SELECT s.day, to_char(s.start_time, 'HH24:MI') AS time, extract(dow FROM s.day)::int AS dow, s.name, s.window_end, s.peak, s.corrected_peak, s.partial, s.frozen,
               s.arrivals_total, s.arrivals_late, s.late_grace_min, s.devotional_at, s.devotional_peak,
               r.assistencia AS r_assistencia, r.voluntarios, r.visitantes, r.adultos_fora, r.kids, r.auditorio, r.decisoes
        FROM service_snapshots s LEFT JOIN meeting_reports r ON r.day = s.day AND r.start_time = s.start_time
        WHERE s.day BETWEEN $1::date AND $2::date ORDER BY s.day, s.start_time", [$from, $to])) ?: [];
}

/** Attendance of a meeting: the saved report wins, then a manual correction, then the counter's peak. */
function stats_assistencia(array $r): array
{
    if ($r['r_assistencia'] !== null) {
        return [(int)$r['r_assistencia'], 'relatório'];
    }
    if ($r['corrected_peak'] !== null) {
        return [(int)$r['corrected_peak'], 'corrigido'];
    }
    return [$r['peak'] !== null ? (int)$r['peak'] : null, 'contador'];
}

/** One snapshot row shaped for the page. */
function stats_meeting(array $r, DateTimeImmutable $now): array
{
    [$assistencia, $source] = stats_assistencia($r);
    $total = $r['arrivals_total'] === null ? null : (int)$r['arrivals_total'];
    $late = $r['arrivals_late'] === null ? null : (int)$r['arrivals_late'];
    $partial = $r['partial'] === 't';
    return [
        'day' => $r['day'],
        'time' => $r['time'],
        'name' => $r['name'],
        'status' => parse_ts($r['window_end']) > $now->setTimezone(utc()) ? 'running' : 'done',
        'assistencia' => $assistencia,
        'assistenciaSource' => $source,
        'counterPeak' => $r['peak'] === null ? null : (int)$r['peak'],
        'partial' => $partial,
        'late' => [
            'total' => $total,
            'late' => $late,
            'pct' => $partial ? null : stats_late_pct($total, $late),   // a counter that started late sees only part of the arrivals
            'grace' => $r['late_grace_min'] === null ? null : (int)$r['late_grace_min'],
        ],
        'devotional' => ['at' => iso($r['devotional_at']), 'peak' => $r['devotional_peak'] === null ? null : (int)$r['devotional_peak']],
        'categories' => [
            'voluntarios' => $r['voluntarios'] === null ? null : (int)$r['voluntarios'],
            'visitantes' => $r['visitantes'] === null ? null : (int)$r['visitantes'],
            'kids' => $r['kids'] === null ? null : (int)$r['kids'],
            'decisoes' => $r['decisoes'] === null ? null : (int)$r['decisoes'],
        ],
    ];
}

/** Sums over meetings: assistência (always) and the report categories (null when no meeting has them). */
function stats_totals(array $meetings): array
{
    $cat = fn(string $k) => stats_sum(array_map(fn($m) => ['v' => $m['categories'][$k]], $meetings), 'v');
    return [
        'meetings' => count($meetings),
        'assistencia' => stats_sum($meetings, 'assistencia'),
        'voluntarios' => $cat('voluntarios'),
        'visitantes' => $cat('visitantes'),
        'kids' => $cat('kids'),
        'decisoes' => $cat('decisoes'),
    ];
}

function stats_exit_rows($con, string $day): array
{
    $out = [];
    foreach (pg_fetch_all(pg_query_params($con, "SELECT to_char(ref_time, 'HH24:MI') AS ref, state, minutes, threshold FROM exit_stats WHERE day = $1 ORDER BY ref_time", [$day])) ?: [] as $r) {
        $out[] = ['ref' => $r['ref'], 'state' => $r['state'], 'minutes' => $r['minutes'] === null ? null : (int)$r['minutes'], 'threshold' => (int)$r['threshold']];
    }
    return $out;
}

/**
 * Balance of one meeting from 60 minutes before its start to 60 minutes after its end, as [minutes from the start, balance]
 * (pure: $entries are balance_entries() rows). Used for the progression chart; the previous week is drawn the same way.
 */
function stats_progression(array $entries, DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $from = $start->modify('-60 minutes');
    $to = $end->modify('+60 minutes');
    $out = [];
    foreach ($entries as $e) {
        $t = stats_utc($e['timestamp']);
        if ($t >= $from && $t <= $to) {
            $out[] = [(int)round(($t->getTimestamp() - $start->getTimestamp()) / 60), (int)$e['balance']];
        }
    }
    return $out;
}

// ---------------------------------------------------------------- the page's data

/** This week (the Sunday's week) against the week before. */
function stats_week_data($con, DateTimeImmutable $now, string $sunday): array
{
    $prevSunday = (new DateTimeImmutable($sunday))->modify('-7 days')->format('Y-m-d');
    $end = (new DateTimeImmutable($sunday))->modify('+6 days')->format('Y-m-d');
    $settings = stats_settings($con);
    services_week($con, $now, $prevSunday);    // bring both weeks' snapshots up to date
    services_week($con, $now, $sunday);

    $mine = $prev = [];
    foreach (stats_rows($con, $prevSunday, $end) as $r) {
        $m = stats_meeting($r, $now);
        $m['dow'] = (int)$r['dow'];
        if ($r['day'] >= $sunday) {
            $mine[] = $m;
        } else {
            $prev[] = $m;
        }
    }
    $prevBySlot = [];
    foreach ($prev as $m) {
        $prevBySlot[$m['dow'] . ' ' . $m['time']] = $m;
    }
    foreach ($mine as &$m) {
        $p = $prevBySlot[$m['dow'] . ' ' . $m['time']] ?? null;
        $m['previous'] = $p ? [
            'day' => $p['day'], 'assistencia' => $p['assistencia'], 'latePct' => $p['late']['pct'], 'devotionalPeak' => $p['devotional']['peak'],
            'volunteers' => $p['categories']['voluntarios'],
        ] : null;
        $m['delta'] = $p ? stats_delta($m['assistencia'], $p['assistencia']) : null;
    }
    unset($m);

    $sundayNow = array_values(array_filter($mine, fn($m) => $m['day'] === $sunday));
    $sundayPrev = array_values(array_filter($prev, fn($m) => $m['day'] === $prevSunday));

    // progression of the Sunday's meetings, this week and last week's same slot
    $series = [];
    $local = new DateTimeZone(LOCAL_TZ);
    $entriesFor = function (string $day) use ($con, $local) {
        $since = new DateTimeImmutable("$day 12:00", $local);
        return balance_entries($con, $since, $since->modify('+1 day')->setTime(4, 0));
    };
    $entriesNow = $sundayNow ? $entriesFor($sunday) : [];
    $entriesPrev = $sundayPrev ? $entriesFor($prevSunday) : [];
    $durations = [];
    foreach (scheduled_services($con, $sunday) as $s) {
        $durations[substr($s['start_time'], 0, 5)] = (int)$s['duration_min'];
    }
    foreach ($sundayNow as $m) {
        $start = (new DateTimeImmutable("$sunday {$m['time']}", $local))->setTimezone(utc());
        $dur = $durations[$m['time']] ?? 120;
        $p = null;
        if (isset($prevBySlot['0 ' . $m['time']])) {
            $ps = (new DateTimeImmutable("$prevSunday {$m['time']}", $local))->setTimezone(utc());
            $p = stats_progression($entriesPrev, $ps, $ps->modify("+$dur minutes"));
        }
        $dev = $m['devotional']['at'] ? (int)round((strtotime($m['devotional']['at']) - $start->getTimestamp()) / 60) : null;
        $series[] = [
            'time' => $m['time'], 'name' => $m['name'], 'durationMin' => $dur, 'devotionalMin' => $dev,
            'current' => stats_progression($entriesNow, $start, $start->modify("+$dur minutes")),
            'previous' => $p,
        ];
    }

    return [
        'sunday' => $sunday, 'prevSunday' => $prevSunday, 'prev' => $prevSunday, 'next' => (new DateTimeImmutable($sunday))->modify('+7 days')->format('Y-m-d'),
        'isCurrent' => $sunday === week_start($now->setTimezone($local)->format('Y-m-d')),
        'meetings' => $mine,
        'sundayTotal' => stats_totals($sundayNow),
        'prevSundayTotal' => stats_totals($sundayPrev),
        'exit' => stats_exit_rows($con, $sunday),
        'prevExit' => stats_exit_rows($con, $prevSunday),
        'progression' => $series,
        'settings' => $settings,
    ];
}

/** Averages of a month: per meeting slot, and for the Sundays as a whole. */
function stats_month_summary($con, string $ym): array
{
    $from = "$ym-01";
    $to = (new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');
    $now = new DateTimeImmutable('now', utc());
    $slots = [];
    $sundays = [];
    foreach (stats_rows($con, $from, $to) as $r) {
        $m = stats_meeting($r, $now);
        $key = $r['dow'] . ' ' . $r['time'];
        $slots[$key]['dow'] = (int)$r['dow'];
        $slots[$key]['time'] = $r['time'];
        $slots[$key]['name'] = $r['name'];
        $slots[$key]['rows'][] = $m;
        if ((int)$r['dow'] === 0) {
            $sundays[$r['day']][] = $m;
        }
    }
    ksort($slots);
    $out = [];
    foreach ($slots as $s) {
        $rows = $s['rows'];
        $out[] = [
            'dow' => $s['dow'], 'time' => $s['time'], 'name' => $s['name'], 'meetings' => count($rows),
            'assistencia' => stats_mean(array_column($rows, 'assistencia')),
            'latePct' => stats_mean(array_map(fn($m) => $m['late']['pct'], $rows)),
            'devotionalPeak' => stats_mean(array_map(fn($m) => $m['devotional']['peak'], $rows)),
        ];
    }
    $totals = array_map(fn($ms) => stats_totals($ms), $sundays);
    $exit = [];
    foreach (pg_fetch_all(pg_query_params($con, "SELECT to_char(ref_time, 'HH24:MI') AS ref, state, minutes FROM exit_stats WHERE day BETWEEN $1::date AND $2::date", [$from, $to])) ?: [] as $r) {
        $exit[$r['ref']]['reached'][] = $r['state'] === 'reached' ? (int)$r['minutes'] : null;
        $exit[$r['ref']]['not'] = ($exit[$r['ref']]['not'] ?? 0) + ($r['state'] === 'not_reached' ? 1 : 0);
    }
    $exitOut = [];
    foreach ($exit as $ref => $e) {
        $exitOut[] = ['ref' => $ref, 'minutes' => stats_mean($e['reached']), 'notReached' => $e['not']];
    }
    usort($exitOut, fn($a, $b) => strcmp($a['ref'], $b['ref']));
    return [
        'month' => $ym,
        'slots' => $out,
        'sundays' => count($sundays),
        'sundayAssistencia' => stats_mean(array_column($totals, 'assistencia')),
        'exit' => $exitOut,
    ];
}

/** Every Sunday of a year with the day's totals (the meetings added together). */
function stats_year_data($con, int $year): array
{
    $now = new DateTimeImmutable('now', utc());
    $byDay = [];
    foreach (stats_rows($con, "$year-01-01", "$year-12-31") as $r) {
        if ((int)$r['dow'] === 0) {
            $byDay[$r['day']][] = stats_meeting($r, $now);
        }
    }
    $sundays = [];
    $d = new DateTimeImmutable("$year-01-01");
    while ($d->format('w') !== '0') {
        $d = $d->modify('+1 day');
    }
    for (; $d->format('Y') === (string)$year; $d = $d->modify('+7 days')) {
        $day = $d->format('Y-m-d');
        $sundays[] = ['day' => $day] + stats_totals($byDay[$day] ?? []);
    }
    return ['year' => $year, 'sundays' => $sundays];
}
