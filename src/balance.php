<?php

// Rules for the running balance of people inside. The computation itself is the balance_entries()
// SQL function (sql/seed.sql); these are its parameters plus helpers shared by the pages.

// Nobody is in the building at RESET_HOUR (local time), so the balance restarts at zero then, every
// day. Before the first reset in the data it starts from OPENING_BALANCE at the first reading.
const OPENING_BALANCE = 150;
const RESET_HOUR = 4;
const LOCAL_TZ = 'Europe/Lisbon';

/** Naive UTC 'Y-m-d H:i:s' of the RESET_HOUR that starts the local day containing $t. */
function day_start_utc(DateTimeImmutable $t): string
{
    $local = $t->setTimezone(new DateTimeZone(LOCAL_TZ));
    $start = $local->setTime(RESET_HOUR, 0);
    if ($local < $start) {
        $start = $start->modify('-1 day')->setTime(RESET_HOUR, 0);
    }
    return $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** balance_entries() rows from the start of the local day containing $since (null: all history). */
function balance_entries($con, ?DateTimeImmutable $since): array
{
    $res = pg_query_params(
        $con,
        'SELECT "timestamp", logentryid, day, epoch, d_in, d_out, balance FROM balance_entries($1, $2, $3, $4) ORDER BY 1, 2',
        [$since ? day_start_utc($since) : null, OPENING_BALANCE, RESET_HOUR, LOCAL_TZ]
    );
    return pg_fetch_all($res) ?: [];
}

/**
 * Manual resets (naive UTC strings) since $since. The device reports about 4 minutes late, so for a
 * while after a reset no reading belongs to the new epoch yet; callers show zero from the reset on.
 */
function manual_resets_since($con, DateTimeImmutable $since): array
{
    $res = pg_query_params($con, "SELECT at FROM balance_resets WHERE at >= $1 AND at <= (now() AT TIME ZONE 'UTC') ORDER BY at",
        [$since->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
    return array_column(pg_fetch_all($res) ?: [], 'at');
}
