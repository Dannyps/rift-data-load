<?php

// Planning Center Services (PCO) sync: special days from the plans, and the people on each plan.
//
// Quirks verified against the live account:
//  * The account's time zone is America/Los_Angeles, but the times in it are wall-clock times typed
//    for Portugal ("17:30" is stored as 17:25 Pacific): convert to the account zone, read the date
//    and HH:MM, and treat them as local (Europe/Lisbon) wall-clock. Everything below stores those
//    wall-clock strings.
//  * A Sunday morning plan has ONE service time that covers both the 09:30 and 11:30 meetings, so
//    Sundays keep the weekly meetings and the plan only supplies people (matched by time window).
//  * People are not team positions (except the worship leader) but free-text "Person" notes on
//    plan items, optionally tagged per meeting ("9h30 X", "X (9:30)").
// No LLM or fuzzy matching anywhere: fixed patterns only.

require_once(__DIR__ . "/balance.php");

const PCO_CONFIG = '/etc/rift-data-load/pco.ini';
const PCO_BASE = 'https://api.planningcenteronline.com';
// Folders to follow (and everything below them): Porto, and Young Adults & Youth under Grande Porto.
const PCO_ROOT_FOLDERS = ['701584', '704393'];
// Never follow Kids: folders named like it, and service types named like it (incl. "(HSK)").
const PCO_EXCLUDE_FOLDER = '/kids/i';
const PCO_EXCLUDE_TYPE = '/\bkids\b|\(HSK\)/iu';
// Sundays are matched to the weekly meetings; every other day becomes a special day.
const PCO_MEETING_MINUTES = 120;
const PCO_PLANS_FROM_DAYS = 14;      // plans from this many days ago ...
const PCO_PLANS_TO_DAYS = 548;       // ... to about 18 months ahead
const PCO_PEOPLE_BACK_DAYS = 1;      // people are (re)read for plans from yesterday ...
const PCO_PEOPLE_AHEAD_DAYS = 21;    // ... to three weeks ahead (--backfill-days reads further back)
const PCO_MATCH_SLACK_MIN = 30;      // a meeting belongs to a plan if it starts within its service times (+30 min before)

// ---------------------------------------------------------------- parsing of the people notes

// plan item (type "item", first one in plan order with a non-empty Person note) per role
const PCO_ROLE_ITEMS = [
    ['mc', '/^\W*MC\b/iu'],                     // Sundays: "> MC:ORAÇÃO + BOAS VINDAS + ANÚNCIOS"
    ['mc', '/BOAS[\s-]*VINDAS/iu'],             // Youth / Young Adults: "BOAS-VINDAS + ANÚNCIOS + GAME TIME"
    ['pregador', '/PREGA[ÇC][ÃA]O/iu'],
    ['oferta', '/^\W*OFERTA/iu'],
];
const PCO_LEADER_POSITION = '/^\s*a\)\s*L[ií]der\s+Louvor\s*$/iu';

function pco_clean_name(string $s): string
{
    $s = trim(preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $s)));
    if (preg_match('/^(Pr|Pra|Ps|Pa|Pst)\.?\s+/iu', $s, $m)) {
        $s = ucfirst(strtolower($m[1])) . '. ' . substr($s, strlen($m[0]));
    }
    return $s;
}

/**
 * One Person note -> text per meeting. $meetings are 'HH:MM' strings.
 * Lines tagged with a time ("9h30 X", "X (9:30)") apply to that meeting only, untagged lines to all.
 * Returns [text-per-meeting|null, flags] where flags can contain 'free text' (not name-like, shown
 * raw) and 'tag HH:MM matches no meeting'.
 */
function pco_parse_person(?string $note, array $meetings): array
{
    $tagged = $plain = $flags = [];
    foreach (preg_split('/\R/u', (string)$note) as $line) {
        if (trim($line) === '') {
            continue;
        }
        if (preg_match('/^\s*(\d{1,2})\s*[h:]\s*(\d{2})\s*[-–:]?\s*(.*\S)\s*$/u', $line, $m)) {
            $tagged[sprintf('%02d:%s', $m[1], $m[2])] = pco_clean_name($m[3]);
        } elseif (preg_match('/^\s*(.*?\S)\s*[(\[]\s*(\d{1,2})\s*[h:]\s*(\d{2})\s*[)\]]\s*$/u', $line, $m)) {
            $tagged[sprintf('%02d:%s', $m[2], $m[3])] = pco_clean_name($m[1]);
        } else {
            $plain[] = pco_clean_name($line);
        }
    }

    $result = [];
    if ($tagged) {
        foreach (array_keys($tagged) as $t) {
            if (!in_array($t, $meetings, true)) {
                $flags[] = "tag $t matches no meeting";
            }
        }
        foreach ($meetings as $m) {
            $result[$m] = $tagged[$m] ?? ($plain ? implode(' / ', $plain) : null);
        }
    } else {
        $text = implode(' / ', $plain);
        foreach ($meetings as $m) {
            $result[$m] = $text !== '' ? $text : null;
        }
    }
    foreach (array_unique(array_filter($result)) as $v) {
        if (!preg_match('/^[\p{L}.\'’\- ]+(?:\s(?:e|\+|&)\s[\p{L}.\'’\- ]+)*(?:\s*\([^)]*\))?$/u', $v)) {
            $flags[] = 'free text';
        }
    }
    return [$result, array_values(array_unique($flags))];
}

/** Which roles' items does a title belong to? First matching role in PCO_ROLE_ITEMS order. */
function pco_item_roles(string $title): array
{
    $roles = [];
    foreach (PCO_ROLE_ITEMS as [$role, $rx]) {
        if (preg_match($rx, $title)) {
            $roles[] = $role;
        }
    }
    return $roles;
}

// ---------------------------------------------------------------- HTTP client

function pco_credentials(): array
{
    $cfg = @parse_ini_file(getenv('RIFT_PCO_CONFIG') ?: PCO_CONFIG);   // the variable is for tests only
    if (!$cfg || empty($cfg['client_id']) || empty($cfg['secret'])) {
        throw new RuntimeException('Planning Center credentials missing (' . PCO_CONFIG . ')');
    }
    return $cfg;
}

/** GET a Planning Center API path (decoded JSON). Retries on 429 and transient errors. */
function pco_get(string $path): array
{
    static $cred, $last = 0.0;
    $cred ??= pco_credentials();

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $gap = microtime(true) - $last;
        if ($gap < 0.12) {
            usleep((int)((0.12 - $gap) * 1e6));
        }
        $ch = curl_init(PCO_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERPWD => $cred['client_id'] . ':' . $cred['secret'],
            CURLOPT_USERAGENT => 'rift-data-load (claude@dannyps.net)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $last = microtime(true);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw !== false && $status === 200) {
            $json = json_decode(substr($raw, $headerSize), true);
            if (is_array($json)) {
                return $json;
            }
            throw new RuntimeException("Planning Center sent invalid JSON for $path");
        }
        if ($status === 429 && $raw !== false && preg_match('/^retry-after:\s*(\d+)/mi', substr($raw, 0, $headerSize), $m)) {
            sleep(min(60, (int)$m[1] + 1));
        } elseif ($status === 401 || $status === 403 || $status === 404) {
            throw new RuntimeException("Planning Center answered HTTP $status for $path");
        } else {
            sleep($attempt * 2);
        }
        if ($attempt === 4) {
            throw new RuntimeException("Planning Center request failed for $path (HTTP $status" . ($err ? ", $err" : '') . ')');
        }
    }
    throw new LogicException('unreachable');
}

/** All pages of a collection endpoint, merged: ['data' => [...], 'included' => [...]]. */
function pco_get_all(string $path): array
{
    $data = $included = [];
    for ($offset = 0; ; $offset += 100) {
        $page = pco_get($path . (str_contains($path, '?') ? '&' : '?') . "per_page=100&offset=$offset");
        $data = array_merge($data, $page['data'] ?? []);
        $included = array_merge($included, $page['included'] ?? []);
        if (empty($page['links']['next'])) {
            return ['data' => $data, 'included' => $included];
        }
    }
}

// ---------------------------------------------------------------- discovery

/** Wall-clock 'Y-m-d H:i' of a UTC ISO time as read in the account's zone (= the typed Portuguese time). */
function pco_wall(string $iso, DateTimeZone $orgTz): string
{
    return (new DateTimeImmutable($iso))->setTimezone($orgTz)->format('Y-m-d H:i');
}

/** Service types under PCO_ROOT_FOLDERS, minus Kids: [[id, name, folder path], ...]. */
function pco_service_types(): array
{
    $types = [];
    $walk = function (string $folderId, string $path) use (&$walk, &$types) {
        foreach (pco_get_all("/services/v2/folders/$folderId/service_types")['data'] as $t) {
            $name = (string)$t['attributes']['name'];
            if (!preg_match(PCO_EXCLUDE_TYPE, $name) && empty($t['attributes']['archived_at'])) {
                $types[$t['id']] = [$t['id'], $name, $path];
            }
        }
        foreach (pco_get_all("/services/v2/folders/$folderId/folders")['data'] as $f) {
            $name = (string)$f['attributes']['name'];
            if (!preg_match(PCO_EXCLUDE_FOLDER, $name)) {
                $walk($f['id'], "$path / $name");
            }
        }
    };
    foreach (PCO_ROOT_FOLDERS as $id) {
        $root = pco_get("/services/v2/folders/$id");
        $walk($id, (string)$root['data']['attributes']['name']);
    }
    return array_values($types);
}

/**
 * Plans of one service type in the sync window with their service times as wall-clock strings.
 * Plans without a "service" time, and the far-future placeholder plans, are dropped.
 */
function pco_plans(string $typeId, DateTimeZone $orgTz, DateTimeImmutable $now, int $backDays = PCO_PLANS_FROM_DAYS): array
{
    $from = $now->modify("-$backDays days")->format('Y-m-d');
    $until = $now->modify('+' . PCO_PLANS_TO_DAYS . ' days')->format('Y-m-d');
    $res = pco_get_all("/services/v2/service_types/$typeId/plans?filter=after&after=$from&order=sort_date&include=plan_times");

    $times = [];
    foreach ($res['included'] as $inc) {
        if ($inc['type'] === 'PlanTime') {
            $times[$inc['id']] = $inc['attributes'];
        }
    }
    $plans = [];
    foreach ($res['data'] as $p) {
        $svc = [];
        foreach ($p['relationships']['plan_times']['data'] ?? [] as $ref) {
            $a = $times[$ref['id']] ?? null;
            if ($a && $a['time_type'] === 'service' && !empty($a['starts_at'])) {
                $start = pco_wall($a['starts_at'], $orgTz);
                $end = !empty($a['ends_at']) ? pco_wall($a['ends_at'], $orgTz) : $start;
                $svc[$start] = ['start' => $start, 'end' => max($start, $end)];
            }
        }
        if (!$svc) {
            continue;
        }
        ksort($svc);
        $svc = array_values($svc);
        $day = substr($svc[0]['start'], 0, 10);
        if ($day > $until) {
            continue;
        }
        $title = trim((string)($p['attributes']['title'] ?? ''));
        $plans[] = ['id' => (string)$p['id'], 'day' => $day, 'title' => $title !== '' ? $title : null, 'times' => $svc];
    }
    return $plans;
}

/** People of one plan as raw notes: ['mc' => text|null, 'oferta' => ..., 'pregador' => ..., 'lider' => [names]]. */
function pco_plan_people(string $typeId, string $planId): array
{
    $base = "/services/v2/service_types/$typeId/plans/$planId";
    $raw = ['mc' => null, 'oferta' => null, 'pregador' => null, 'lider' => []];

    foreach (pco_get_all("$base/items")['data'] as $item) {
        $a = $item['attributes'];
        if ($a['item_type'] !== 'item') {
            continue;
        }
        $roles = array_filter(pco_item_roles((string)$a['title']), fn($r) => $raw[$r] === null);
        if (!$roles) {
            continue;
        }
        $notes = [];
        foreach (pco_get_all("$base/items/{$item['id']}/item_notes")['data'] as $n) {
            if ($n['attributes']['category_name'] === 'Person' && trim((string)$n['attributes']['content']) !== '') {
                $notes[] = trim((string)$n['attributes']['content']);
            }
        }
        if ($notes) {
            foreach ($roles as $r) {
                $raw[$r] = implode("\n", $notes);
            }
        }
    }

    foreach (pco_get_all("$base/team_members")['data'] as $m) {
        $a = $m['attributes'];
        if (preg_match(PCO_LEADER_POSITION, (string)($a['team_position_name'] ?? '')) && ($a['status'] ?? '') !== 'D') {
            $raw['lider'][] = pco_clean_name((string)$a['name']);
        }
    }
    $raw['lider'] = array_values(array_unique($raw['lider']));
    return $raw;
}

// ---------------------------------------------------------------- sync

function pco_status($con, array $set): void
{
    $cols = [];
    $vals = [];
    foreach ($set as $k => $v) {
        $vals[] = $v;
        $cols[] = "$k = $" . count($vals);
    }
    pg_query_params($con, 'UPDATE pco_sync_status SET ' . implode(', ', $cols) . ' WHERE id = 1', $vals);
}

/**
 * Full sync. $opts: backfill_days (also read people of plans that far back), dry_run (write nothing).
 * Returns ['text' => summary, 'plans' => n, 'wanted' => special days, 'types' => ...]; on failure it
 * records the error in pco_sync_status and rethrows (the saved data is left as it was).
 */
function pco_sync($con, DateTimeImmutable $now, array $opts = []): array
{
    $dry = !empty($opts['dry_run']);
    if (!$dry) {
        pco_status($con, ['last_attempt' => $now->format('c')]);
    }
    try {
        $summary = pco_sync_run($con, $now, $opts);
    } catch (\Throwable $th) {
        if (!$dry) {
            pco_status($con, ['error' => mb_substr($th->getMessage(), 0, 500)]);
        }
        throw $th;
    }
    if (!$dry) {
        pco_status($con, ['last_ok' => $now->format('c'), 'error' => null, 'plans' => $summary['plans'], 'detail' => $summary['text']]);
    }
    return $summary;
}

function pco_sync_run($con, DateTimeImmutable $now, array $opts): array
{
    $dry = !empty($opts['dry_run']);
    $org = pco_get('/services/v2');
    $orgTz = new DateTimeZone($org['data']['attributes']['time_zone'] ?? 'America/Los_Angeles');
    $local = new DateTimeZone(LOCAL_TZ);
    $today = $now->setTimezone($local)->format('Y-m-d');
    $nowLocalTime = $now->setTimezone($local)->format('H:i');

    $backfill = (int)($opts['backfill_days'] ?? 0);
    $peopleFrom = $now->setTimezone($local)->modify('-' . max(PCO_PEOPLE_BACK_DAYS, $backfill) . ' days')->format('Y-m-d');
    $peopleTo = $now->setTimezone($local)->modify('+' . PCO_PEOPLE_AHEAD_DAYS . ' days')->format('Y-m-d');

    $types = pco_service_types();
    $plansSeen = [];     // plan_id => [typeId, typeName, folder, plan]
    foreach ($types as [$typeId, $typeName, $folder]) {
        foreach (pco_plans($typeId, $orgTz, $now, max(PCO_PLANS_FROM_DAYS, (int)($opts['backfill_days'] ?? 0) + 1)) as $plan) {
            $plansSeen[$plan['id']] = [$typeId, $typeName, $folder, $plan];
        }
    }

    $existing = [];
    foreach (pg_fetch_all(pg_query($con, 'SELECT plan_id, people_raw FROM pco_plans')) ?: [] as $r) {
        $existing[$r['plan_id']] = $r['people_raw'];
    }

    // plans (+ people for the near ones)
    $peopleRead = 0;
    foreach ($plansSeen as $planId => [$typeId, $typeName, $folder, $plan]) {
        $people = isset($existing[$planId]) && $existing[$planId] !== null ? json_decode($existing[$planId], true) : null;
        $fetchedAt = null;
        if ($plan['day'] >= $peopleFrom && $plan['day'] <= $peopleTo) {
            $people = pco_plan_people($typeId, $planId);
            $fetchedAt = $now->format('c');
            $peopleRead++;
        }
        if ($dry) {
            continue;
        }
        pg_query_params($con, "
            INSERT INTO pco_plans (plan_id, service_type_id, service_type_name, folder_path, day, title, service_times, people_raw, people_fetched_at, fetched_at)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, now())
            ON CONFLICT (plan_id) DO UPDATE SET
                service_type_id = EXCLUDED.service_type_id, service_type_name = EXCLUDED.service_type_name, folder_path = EXCLUDED.folder_path,
                day = EXCLUDED.day, title = EXCLUDED.title, service_times = EXCLUDED.service_times,
                people_raw = COALESCE(EXCLUDED.people_raw, pco_plans.people_raw),
                people_fetched_at = COALESCE(EXCLUDED.people_fetched_at, pco_plans.people_fetched_at), fetched_at = now()",
            [$planId, $typeId, $typeName, $folder, $plan['day'], $plan['title'], json_encode($plan['times']),
             $people === null ? null : json_encode($people, JSON_UNESCAPED_UNICODE), $fetchedAt]);
    }
    if (!$dry) {
        // plans that are in the window but no longer in Planning Center (deleted/moved)
        $ids = array_keys($plansSeen);
        $res = pg_query_params($con, 'SELECT plan_id FROM pco_plans WHERE day >= $1', [$today]);
        foreach (pg_fetch_all($res) ?: [] as $r) {
            if (!isset($plansSeen[$r['plan_id']])) {
                pg_query_params($con, 'DELETE FROM pco_plans WHERE plan_id = $1', [$r['plan_id']]);
            }
        }
    }

    // special days from non-Sunday plans
    $manualDays = array_column(pg_fetch_all(pg_query($con, "SELECT DISTINCT day FROM special_days WHERE source = 'manual'")) ?: [], 'day');
    $manualDays = array_flip($manualDays);
    $wanted = [];     // "day start" => [day, start, name, plan_id]
    $clashes = [];
    foreach ($plansSeen as $planId => [$typeId, $typeName, $folder, $plan]) {
        $weekday = (int)(new DateTimeImmutable($plan['day']))->format('w');
        if ($weekday === 0 || $plan['day'] < $today) {
            continue;
        }
        foreach ($plan['times'] as $t) {
            $day = substr($t['start'], 0, 10);
            $start = substr($t['start'], 11, 5);
            if (isset($manualDays[$day]) || (int)(new DateTimeImmutable($day))->format('w') === 0) {
                continue;
            }
            $key = "$day $start";
            if (isset($wanted[$key])) {
                $clashes[] = "$key ({$plan['title']} / {$wanted[$key][2]})";
                continue;
            }
            $wanted[$key] = [$day, $start, $plan['title'] ?? $typeName, $planId];
        }
    }
    ksort($wanted);

    $added = $updated = $removed = 0;
    if (!$dry) {
        pg_query($con, 'BEGIN');
        try {
            foreach ($wanted as [$day, $start, $name, $planId]) {
                $r = pg_query_params($con, "
                    INSERT INTO special_days (day, name, start_time, duration_min, source, pco_plan_id)
                    VALUES ($1, $2, $3, $4, 'pco', $5)
                    ON CONFLICT (day, start_time) DO UPDATE SET name = EXCLUDED.name, pco_plan_id = EXCLUDED.pco_plan_id, duration_min = EXCLUDED.duration_min
                    WHERE special_days.source = 'pco' AND (special_days.name, special_days.pco_plan_id) IS DISTINCT FROM (EXCLUDED.name, EXCLUDED.pco_plan_id)
                    RETURNING (xmax = 0) AS inserted", [$day, $name, $start, PCO_MEETING_MINUTES, $planId]);
                if ($row = pg_fetch_assoc($r)) {
                    $row['inserted'] === 't' ? $added++ : $updated++;
                }
            }
            // PCO rows from today on that are no longer wanted; never past rows, nor a meeting that already started
            foreach (pg_fetch_all(pg_query_params($con, "SELECT id, day, to_char(start_time, 'HH24:MI') AS t FROM special_days WHERE source = 'pco' AND day >= $1", [$today])) ?: [] as $r) {
                $started = $r['day'] === $today && $r['t'] <= $nowLocalTime;
                if (!isset($wanted[$r['day'] . ' ' . $r['t']]) && !$started) {
                    pg_query_params($con, 'DELETE FROM special_days WHERE id = $1', [$r['id']]);
                    $removed++;
                }
            }
            pg_query($con, 'COMMIT');
        } catch (\Throwable $th) {
            pg_query($con, 'ROLLBACK');
            throw $th;
        }
    }

    $text = sprintf('%d service types, %d plans (people read for %d); special days +%d ~%d -%d%s%s',
        count($types), count($plansSeen), $peopleRead, $dry ? count($wanted) : $added, $updated, $removed,
        $dry ? ' (dry run: ' . count($wanted) . ' wanted)' : '', $clashes ? '; clashes: ' . implode(', ', $clashes) : '');
    return ['text' => $text, 'plans' => count($plansSeen), 'wanted' => $wanted, 'types' => $types];
}
