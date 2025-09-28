<?php

require("../rift-data-load/sql/utils.php");

$incomingLine = file_get_contents("php://input");

$con = pg_connect("connection string");

if (!$con) {
    return -1;
}
file_put_contents("records", $incomingLine);
insert_line($con, $incomingLine);
