-- Idempotent: run whenever sql/utils.php finds the schema out of date (see SCHEMA_VERSION there).

CREATE TABLE IF NOT EXISTS records (
    registerid smallint NOT NULL,
    value integer NOT NULL,
    logentryid integer NOT NULL,
    "timestamp" timestamp without time zone NOT NULL,
    deviceid text NOT NULL,
    PRIMARY KEY (logentryid, registerid)
);

-- The device clock is not reliable, so keep our own receive time next to its timestamp.
ALTER TABLE records ADD COLUMN IF NOT EXISTS received_at timestamptz NOT NULL DEFAULT now();

CREATE INDEX IF NOT EXISTS records_timestamp_idx ON records ("timestamp");

-- Manual balance resets: an admin confirms the building is empty and the balance restarts at zero
-- from that moment, exactly like the daily reset_hour one. `at` is naive UTC and is compared with
-- the readings' own (event) timestamps.
CREATE TABLE IF NOT EXISTS balance_resets (
    id serial PRIMARY KEY,
    at timestamp NOT NULL,
    created_by text NOT NULL,
    note text,
    created_at timestamptz NOT NULL DEFAULT now()
);

-- The moment the balance last restarted as of a reading at `ts`: the later of the start of its
-- local day (reset_hour) and the latest manual reset. Naive UTC.
CREATE OR REPLACE FUNCTION balance_epoch(ts timestamp, reset_hour int, tz text)
RETURNS timestamp LANGUAGE sql STABLE AS $$
    SELECT greatest(
        (((((ts AT TIME ZONE 'UTC') AT TIME ZONE tz) - make_interval(hours => reset_hour))::date + make_interval(hours => reset_hour))
            AT TIME ZONE tz) AT TIME ZONE 'UTC',
        coalesce((SELECT max(at) FROM balance_resets WHERE at <= ts), '-infinity'::timestamp))
$$;

-- Running balance of people inside. The device counters are cumulative, so a reading's change is
-- the difference to the previous reading of the same register (a drop means the counter was
-- reset). The balance restarts at zero at every epoch (see balance_epoch: each local day at
-- reset_hour, and any manual reset); in the very first epoch of the data it starts at `opening`.
-- `day` is the local day (shifted by reset_hour) a reading belongs to. from_ts, when given, must
-- be the start of a local day: only that day onwards is returned (one earlier day of readings is
-- still read for the first difference). All timestamps are naive UTC, like the table.
DROP FUNCTION IF EXISTS balance_entries(timestamp, int, int, text);
CREATE FUNCTION balance_entries(from_ts timestamp, opening int, reset_hour int, tz text)
RETURNS TABLE ("timestamp" timestamp, logentryid int, day date, epoch timestamp, d_in int, d_out int, balance int)
LANGUAGE sql STABLE AS $$
    WITH deltas AS (
        SELECT deviceid, logentryid, registerid, "timestamp",
               CASE WHEN prev IS NULL THEN 0
                    WHEN value >= prev THEN value - prev
                    ELSE value END AS delta
        FROM (SELECT deviceid, logentryid, registerid, "timestamp", value,
                     lag(value) OVER (PARTITION BY deviceid, registerid ORDER BY "timestamp", logentryid) AS prev
              FROM records
              WHERE from_ts IS NULL OR "timestamp" >= from_ts - interval '1 day') r
    ), entries AS (
        SELECT "timestamp", logentryid,
               ((("timestamp" AT TIME ZONE 'UTC') AT TIME ZONE tz) - make_interval(hours => reset_hour))::date AS day,
               balance_epoch("timestamp", reset_hour, tz) AS epoch,
               sum(delta) FILTER (WHERE registerid = 0) AS d_in,
               sum(delta) FILTER (WHERE registerid = 1) AS d_out
        FROM deltas GROUP BY deviceid, "timestamp", logentryid
    ), first_epoch AS (
        SELECT balance_epoch(min("timestamp"), reset_hour, tz) AS e FROM records
    )
    SELECT e."timestamp", e.logentryid, e.day, e.epoch, coalesce(e.d_in, 0)::int, coalesce(e.d_out, 0)::int,
           ((CASE WHEN e.epoch = (SELECT fe.e FROM first_epoch fe) THEN opening ELSE 0 END)
            + sum(coalesce(e.d_in, 0) - coalesce(e.d_out, 0)) OVER (PARTITION BY e.epoch ORDER BY e."timestamp", e.logentryid))::int
    FROM entries e
    WHERE from_ts IS NULL OR e."timestamp" >= from_ts
$$;
COMMENT ON FUNCTION balance_entries(timestamp, int, int, text) IS 'v2';

-- Recurring weekly services (weekday 0 = Sunday). Window = start_time .. start_time + duration.
CREATE TABLE IF NOT EXISTS services (
    id serial PRIMARY KEY,
    name text NOT NULL,
    weekday smallint NOT NULL CHECK (weekday BETWEEN 0 AND 6),
    start_time time NOT NULL,
    duration_min int NOT NULL DEFAULT 120,
    active boolean NOT NULL DEFAULT true,
    UNIQUE (weekday, start_time)
);

-- One row per service on a special date. A date with any row here REPLACES that day's weekly services.
CREATE TABLE IF NOT EXISTS special_days (
    id serial PRIMARY KEY,
    day date NOT NULL,
    name text NOT NULL,
    start_time time NOT NULL,
    duration_min int NOT NULL DEFAULT 120,
    UNIQUE (day, start_time)
);

-- Saved result per service: updated while it runs, frozen after the window closes. corrected_peak
-- is a manual fix and wins over peak when set.
CREATE TABLE IF NOT EXISTS service_snapshots (
    day date NOT NULL,
    start_time time NOT NULL,
    name text NOT NULL,
    window_start timestamp NOT NULL,
    window_end timestamp NOT NULL,
    peak int,
    peak_at timestamp,
    partial boolean NOT NULL DEFAULT false,
    frozen boolean NOT NULL DEFAULT false,
    corrected_peak int,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, start_time)
);

-- Saved "event" (people in on a day with no scheduled service), one per local day. A row with no
-- peak means the day was examined and had no event.
CREATE TABLE IF NOT EXISTS event_snapshots (
    day date PRIMARY KEY,
    started_at timestamp,
    ended_at timestamp,
    peak int,
    peak_at timestamp,
    frozen boolean NOT NULL DEFAULT false,
    corrected_peak int,
    updated_at timestamptz NOT NULL DEFAULT now()
);

INSERT INTO services (name, weekday, start_time)
SELECT 'Reunião das ' || to_char(t, 'HH24:MI'), 0, t
FROM (VALUES (time '09:30'), (time '11:30'), (time '17:30')) v(t)
WHERE NOT EXISTS (SELECT 1 FROM services);

-- Admin accounts. The only privilege is can_manage_users. password_hash is argon2id; a legacy
-- Apache '$apr1$' hash (imported from .htpasswd) is accepted once and replaced at the first login.
CREATE TABLE IF NOT EXISTS admin_users (
    id serial PRIMARY KEY,
    username text NOT NULL UNIQUE CHECK (username ~ '^[a-z0-9._-]{3,32}$'),
    password_hash text NOT NULL,
    can_manage_users boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(),
    last_login timestamptz
);

CREATE TABLE IF NOT EXISTS admin_login_failures (
    at timestamptz NOT NULL DEFAULT now(),
    username text NOT NULL,
    ip text NOT NULL
);
CREATE INDEX IF NOT EXISTS admin_login_failures_at ON admin_login_failures (at);

CREATE TABLE IF NOT EXISTS admin_audit (
    id bigserial PRIMARY KEY,
    at timestamptz NOT NULL DEFAULT now(),
    username text NOT NULL,
    action text NOT NULL,
    detail text
);

-- Who corrected a count, when and why.
ALTER TABLE service_snapshots
    ADD COLUMN IF NOT EXISTS correction_note text,
    ADD COLUMN IF NOT EXISTS corrected_by text,
    ADD COLUMN IF NOT EXISTS corrected_at timestamptz;
ALTER TABLE event_snapshots
    ADD COLUMN IF NOT EXISTS correction_note text,
    ADD COLUMN IF NOT EXISTS corrected_by text,
    ADD COLUMN IF NOT EXISTS corrected_at timestamptz;
