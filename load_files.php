<?php

require("sql/utils.php");

$con = pg_connect("connection string");

if (!$con) {
    return -1;
}

try {
    ensure_schema_is_valid($con);
} catch (\Throwable $th) {
    var_dump($th->getMessage());
    seed_database($con);
}

$lines = file('../repo/records', FILE_IGNORE_NEW_LINES);
array_shift($lines);
file_put_contents("../repo/records", "");

foreach ($lines as $line) {
    insert_line($con, $line);
}