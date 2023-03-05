<html>

<body>

    <form action="<?php echo $_SERVER["PHP_SELF"]; ?>" method="post">

        <input name="submit" type="submit" value="backup">
        <input name="submit" type="submit" value="restore">
        <input name="submit" type="submit" value="create_sql_dump">
        <input name="submit" type="submit" value="create_sqlite_dump">

    </form>

</body>

</html>

<?php

require_once "DatabaseBackup.php";
require_once "DotEnv.php";

use DatabaseBackup;
use DotEnv;

if (isset($_POST["submit"])) {
    (new DotEnv(__DIR__ . "/.env"))->load();
    $var = new DatabaseBackup();

    $submit = $_POST["submit"];

    switch ($submit) {
        case "backup":
            $var->backup_from_sql();
            break;
        case "restore":
            $var->restore_from_sqlite();
            break;
        case "create_sql_dump":
            $var->create_sql_dump();
            break;
        case "create_sqlite_dump":
            $var->create_sqlite_dump();
            break;
    }
}
