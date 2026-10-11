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

-- Running balance of people inside. The device counters are cumulative, so a reading's change is
-- the difference to the previous reading of the same register (a drop means the counter was
-- reset). The balance restarts at zero each local day at reset_hour; on the very first day in the
-- data it starts at `opening`. from_ts, when given, must be the start of a local day: only that
-- day onwards is returned (one earlier day of readings is still read for the first difference).
-- All timestamps are naive UTC, like the table.
CREATE OR REPLACE FUNCTION balance_entries(from_ts timestamp, opening int, reset_hour int, tz text)
RETURNS TABLE ("timestamp" timestamp, logentryid int, day date, d_in int, d_out int, balance int)
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
               sum(delta) FILTER (WHERE registerid = 0) AS d_in,
               sum(delta) FILTER (WHERE registerid = 1) AS d_out
        FROM deltas GROUP BY deviceid, "timestamp", logentryid
    ), first_day AS (
        SELECT ((((min("timestamp") AT TIME ZONE 'UTC') AT TIME ZONE tz)) - make_interval(hours => reset_hour))::date AS d FROM records
    )
    SELECT e."timestamp", e.logentryid, e.day, coalesce(e.d_in, 0)::int, coalesce(e.d_out, 0)::int,
           ((CASE WHEN e.day = (SELECT d FROM first_day) THEN opening ELSE 0 END)
            + sum(coalesce(e.d_in, 0) - coalesce(e.d_out, 0)) OVER (PARTITION BY e.day ORDER BY e."timestamp", e.logentryid))::int
    FROM entries e
    WHERE from_ts IS NULL OR e."timestamp" >= from_ts
$$;
COMMENT ON FUNCTION balance_entries(timestamp, int, int, text) IS 'v1';

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
