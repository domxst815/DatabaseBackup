<?php

require_once "DatabaseBackup.php";
require_once "DotEnv.php";

use DatabaseBackup;
use DotEnv;
use SQLite3;

function dd($var)
{
    echo "<pre>";
    var_dump($var);
    echo "</pre>";
}

(new DotEnv(__DIR__ . "/.env"))->load();
(new DatabaseBackup)->create_sqlite_dump();
