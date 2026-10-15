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
-- still read for the first difference); to_ts, when given, is an upper bound on the readings. All timestamps are
-- naive UTC, like the table.
DROP FUNCTION IF EXISTS balance_entries(timestamp, int, int, text);
DROP FUNCTION IF EXISTS balance_entries(timestamp, int, int, text, timestamp);
CREATE FUNCTION balance_entries(from_ts timestamp, opening int, reset_hour int, tz text, to_ts timestamp DEFAULT NULL)
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
              WHERE (from_ts IS NULL OR "timestamp" >= from_ts - interval '1 day') AND (to_ts IS NULL OR "timestamp" <= to_ts)) r
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
COMMENT ON FUNCTION balance_entries(timestamp, int, int, text, timestamp) IS 'v3';

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

-- Admin accounts (role, plus the can_manage_users privilege). password_hash is argon2id; a legacy
-- Apache '$apr1$' hash (imported from .htpasswd) is accepted once and replaced at the first login.
CREATE TABLE IF NOT EXISTS admin_users (
    id serial PRIMARY KEY,
    username text NOT NULL UNIQUE CHECK (username ~ '^[a-z0-9._-]{3,32}$'),
    password_hash text NOT NULL,
    can_manage_users boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(),
    last_login timestamptz
);

-- 'admin' can use every admin page; 'reporter' can only save meeting reports (the dialog on /reunioes)
-- and change its own password. Only an admin can manage users.
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS role text NOT NULL DEFAULT 'admin';
DO $$ BEGIN
    ALTER TABLE admin_users ADD CONSTRAINT admin_users_role_check
        CHECK (role IN ('admin', 'reporter') AND (role = 'admin' OR NOT can_manage_users));
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

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

-- ---------------------------------------------------------------- Planning Center (PCO)

-- Special days come from admin ('manual', wins) or from Planning Center ('pco', refreshed by the sync).
ALTER TABLE special_days
    ADD COLUMN IF NOT EXISTS source text NOT NULL DEFAULT 'manual',
    ADD COLUMN IF NOT EXISTS pco_plan_id text;

-- Every plan seen by the sync (Sundays included). service_times: JSON array of {start, end} as
-- local wall-clock 'YYYY-MM-DD HH:MM' strings (what the church typed; see src/pco.php for the zone
-- quirk). people_raw: {mc, oferta, pregador: raw note text or null, lider: [names]}; it is parsed
-- when shown, because splitting per meeting needs the day's meeting times.
CREATE TABLE IF NOT EXISTS pco_plans (
    plan_id text PRIMARY KEY,
    service_type_id text NOT NULL,
    service_type_name text NOT NULL,
    folder_path text,
    day date NOT NULL,
    title text,
    service_times jsonb NOT NULL DEFAULT '[]',
    people_raw jsonb,
    people_fetched_at timestamptz,
    fetched_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS pco_plans_day ON pco_plans (day);

CREATE TABLE IF NOT EXISTS pco_sync_status (
    id int PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    last_attempt timestamptz,
    last_ok timestamptz,
    plans int,
    error text,
    detail text
);
INSERT INTO pco_sync_status (id) VALUES (1) ON CONFLICT DO NOTHING;

-- Manual per-meeting overrides of a person shown on the card; the value wins over Planning Center.
-- role: mc | oferta | lider | pregador.
CREATE TABLE IF NOT EXISTS people_overrides (
    day date NOT NULL,
    start_time time NOT NULL,
    role text NOT NULL CHECK (role IN ('mc', 'oferta', 'lider', 'pregador')),
    value text NOT NULL,
    set_by text NOT NULL,
    set_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, start_time, role)
);

-- ---------------------------------------------------------------- statistics

-- Tunable numbers. Values already stored in the snapshots are never recomputed when one changes.
CREATE TABLE IF NOT EXISTS settings (
    key text PRIMARY KEY,
    value text NOT NULL,
    set_by text,
    set_at timestamptz NOT NULL DEFAULT now()
);
INSERT INTO settings (key, value) VALUES
    ('late_grace_min', '10'),            -- minutes after the start before an arrival counts as late
    ('devotional_morning', '08:43'),     -- used when the Planning Center plan has no "Devocional" time
    ('devotional_afternoon', '16:58'),
    ('exit_ref_1', '13:00'),             -- exit speed: time from these hours until the balance is <= threshold
    ('exit_ref_2', '19:00'),
    ('exit_threshold', '5')
ON CONFLICT (key) DO NOTHING;

-- Metrics saved with a meeting's snapshot (frozen with it): arrivals from one hour before the start to the end,
-- how many came after start + the grace in force when it was computed, and the head-count at the devotional.
ALTER TABLE service_snapshots
    ADD COLUMN IF NOT EXISTS arrivals_total int,
    ADD COLUMN IF NOT EXISTS arrivals_late int,
    ADD COLUMN IF NOT EXISTS late_grace_min int,
    ADD COLUMN IF NOT EXISTS devotional_at timestamp,
    ADD COLUMN IF NOT EXISTS devotional_peak int;

-- How long the building took to empty after a reference hour: minutes from the reference time until the balance
-- was <= threshold. state: reached | not_reached (not within the window) | no_data (no readings around it).
CREATE TABLE IF NOT EXISTS exit_stats (
    day date NOT NULL,
    ref_time time NOT NULL,
    state text NOT NULL CHECK (state IN ('reached', 'not_reached', 'no_data')),
    minutes int,
    threshold int NOT NULL,
    frozen boolean NOT NULL DEFAULT true,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, ref_time)
);

-- The report typed in the meeting dialog by a logged-in user (what the yearly statistics use).
CREATE TABLE IF NOT EXISTS meeting_reports (
    day date NOT NULL,
    start_time time NOT NULL,
    assistencia int NOT NULL,
    voluntarios int NOT NULL,
    visitantes int NOT NULL,
    adultos_fora int NOT NULL,
    kids int NOT NULL,
    auditorio int NOT NULL,
    decisoes int NOT NULL,
    mc text NOT NULL DEFAULT '',
    oferta text NOT NULL DEFAULT '',
    lider text NOT NULL DEFAULT '',
    pregacao text NOT NULL DEFAULT '',
    saved_by text NOT NULL,
    saved_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, start_time)
);

-- the "Devocional" time written in the plan (local wall-clock 'YYYY-MM-DD HH:MM')
ALTER TABLE pco_plans ADD COLUMN IF NOT EXISTS devotional_start text;
