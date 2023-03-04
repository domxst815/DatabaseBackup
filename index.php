<html>

<body>

    <form action="<?php echo $_SERVER["PHP_SELF"]; ?>" method="post">

        <input name="submit" type="submit" value="backup">
        <input name="submit" type="submit" value="restore">

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
    }
}
