<?php

function ensure_schema_is_valid($con)
{
    var_dump($con);
    $res = pg_query($con, "SELECT EXISTS (
        SELECT 1
        FROM pg_tables
        WHERE tablename = 'records'
        ) AS table_existence;");

    $res = pg_fetch_assoc($res);

    if ($res['table_existence'] !== 't')
        throw new Exception("not valid");
}

function seed_database($con)
{
    $seed = file_get_contents("sql/seed.sql");
    pg_query($con, $seed);
}

function insert_line($con, $line)
{
    $json = json_decode($line);

    if ($json == null) {
        throw new Exception("Could not parse line as json.");
    }

    foreach ($json->CountLogs[0]->Counts as $log) {
        $res = pg_query_params($con, "INSERT INTO records (registerId, value, logEntryId, timestamp, deviceId) VALUES ($1, $2, $3, $4, $5)", [$log->RegisterId, $log->Value, $json->CountLogs[0]->LogEntryId, $json->CountLogs[0]->Timestamp, $json->DeviceID ]);
        assert($res === 1);
    }
}
