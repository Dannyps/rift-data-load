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
