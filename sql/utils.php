<?php

const DB_CONFIG = '/etc/rift-data-load/db.ini';

function db_connect()
{
    $cfg = parse_ini_file(DB_CONFIG);
    $con = pg_connect(sprintf(
        "host=%s port=%s dbname=%s user=%s password=%s",
        $cfg['host'], $cfg['port'], $cfg['dbname'], $cfg['user'], $cfg['password']
    ));

    if (!$con) {
        throw new Exception("Could not connect to the database.");
    }

    ensure_schema($con);

    return $con;
}

function ensure_schema($con)
{
    try {
        ensure_schema_is_valid($con);
    } catch (\Throwable $th) {
        seed_database($con);
    }
}

function ensure_schema_is_valid($con)
{
    $res = pg_query($con, "SELECT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'records' AND column_name = 'received_at'
        ) AS schema_ok;");

    $res = pg_fetch_assoc($res);

    if ($res['schema_ok'] !== 't')
        throw new Exception("not valid");
}

function seed_database($con)
{
    $seed = file_get_contents(__DIR__ . "/seed.sql");
    if (!pg_query($con, $seed)) {
        throw new Exception("Could not seed the database: " . pg_last_error($con));
    }
}

/** Checks the payload has the shape insert_line() relies on; returns the decoded document. */
function parse_payload(string $line)
{
    $json = json_decode($line);

    if (!is_object($json) || !isset($json->DeviceID) || !isset($json->CountLogs) || !is_array($json->CountLogs)) {
        throw new InvalidArgumentException("Payload is not valid JSON of the expected shape.");
    }

    return $json;
}

/**
 * The count entries of a payload. CountLogs also carries other entries (e.g. clock-change
 * notices with only a Timestamp), and the counts are not always in the first element.
 */
function count_entries($json): array
{
    return array_values(array_filter($json->CountLogs, fn($e) => isset($e->Counts) && is_array($e->Counts)));
}

/** Inserts every count of the payload in one transaction; re-delivered entries are ignored. */
function insert_line($con, $line)
{
    $json = parse_payload($line);

    pg_query($con, "BEGIN");
    try {
        foreach (count_entries($json) as $entry) {
            foreach ($entry->Counts as $log) {
                $res = pg_query_params($con, "INSERT INTO records (registerId, value, logEntryId, timestamp, deviceId) VALUES ($1, $2, $3, $4, $5) ON CONFLICT DO NOTHING", [$log->RegisterId, $log->Value, $entry->LogEntryId, $entry->Timestamp, $json->DeviceID]);
                if ($res === false) {
                    throw new Exception("Insert failed: " . pg_last_error($con));
                }
            }
        }
        pg_query($con, "COMMIT");
    } catch (\Throwable $th) {
        pg_query($con, "ROLLBACK");
        throw $th;
    }
}
