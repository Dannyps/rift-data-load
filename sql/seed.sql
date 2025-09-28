CREATE TABLE records (
    registerid smallint NOT NULL,
    value integer NOT NULL,
    logentryid integer NOT NULL,
    "timestamp" timestamp without time zone NOT NULL,
    deviceid text NOT NULL,
    PRIMARY KEY (logentryid, registerid);
);
