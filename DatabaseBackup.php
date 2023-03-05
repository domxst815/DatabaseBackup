<?php

use SQLite3;

class DatabaseBackup
{
    private static $host;
    private static $username;
    private static $password;
    private static $dbName;


    public function __construct()
    {
        self::$host = $_ENV['DB_HOST'];
        self::$username = $_ENV['DB_USER'];
        self::$password = $_ENV['DB_PASSWORD'];
        self::$dbName = $_ENV['DB_NAME'];
    }

    /**
     * Questo metodo è usato per eesguire il backup da un DB MySQL ad un DB SQLite
     * 
     * @return void
     */
    public function backup_from_sql(): void
    {
        $this->create_sql_dump();
        $this->mysql2sqlite();

        $sqlite = new SQLite3('sqlite.db');

        $sql = file_get_contents($_ENV['BACKUP_FILE']);

        $sql = explode(';', $sql);
        foreach ($sql as $query) {
            if (str_starts_with($query, '--')) {
                continue;
            }

            $query = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $query);
            $query .= ';';
            $query = trim($query);

            $sqlite->query($query);
        }

        $sqlite->close();
    }

    /**
     * Questo metodo è usato per eseguire eseguire un restore da un DB SQLite ad un DB MySQL
     * 
     * @return void
     */
    public function restore_from_sqlite(): void
    {
        $this->create_sqlite_dump();

        // Connect to database
        $conn = mysqli_connect(self::$host, self::$username, self::$password, self::$dbName);
        if (!$conn) {
            die("Connection failed: " . mysqli_connect_error());
        }

        mysqli_query($conn, 'SET foreign_key_checks = 0');

        $file = $_ENV["RESTORE_FILE"];
        $multi_query = file_get_contents($file);
        $multi_query = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '', $multi_query);

        try {
            mysqli_multi_query($conn, $multi_query);
            while (mysqli_next_result($conn)) {
                if (!mysqli_more_results($conn)) break;
            }
        } catch (\Exception $e) {
            die($e);
        }

        mysqli_query($conn, 'SET foreign_key_checks = 1');

        mysqli_close($conn);
    }

    /**
     * Questo metodo è usato per creare un file .sql per contenere tutte le query MySQL
     * 
     * @return string
     */
    public function create_sql_dump(): string
    {
        // Connect to database
        $conn = mysqli_connect(self::$host, self::$username, self::$password, self::$dbName);
        if (!$conn) {
            die("Connection failed: " . mysqli_connect_error());
        }
        mysqli_set_charset($conn, 'utf8');

        // Get all table names
        $tables = array();
        $result = mysqli_query($conn, "SHOW TABLES");
        while ($row = mysqli_fetch_row($result)) {
            $tables[] = $row[0];
        }

        // Loop through each table
        $return = "";
        foreach ($tables as $table) {
            // Get create table statement
            $result = mysqli_query($conn, "SHOW CREATE TABLE $table");
            $row = mysqli_fetch_row($result);
            $row[1] = str_replace("CREATE TABLE", "CREATE TABLE IF NOT EXISTS", $row[1]);
            $return .= "\n\n" . $row[1] . ";\n\n";

            // Get all data from table
            $result = mysqli_query($conn, "SELECT * FROM $table");
            $columnCount = mysqli_num_fields($result);
            while ($row = mysqli_fetch_row($result)) {
                $return .= "INSERT INTO $table VALUES(";
                for ($i = 0; $i < $columnCount; $i++) {

                    if (isset($row[$i])) {
                        $row[$i] = mysqli_real_escape_string($conn, $row[$i]);
                        $row[$i] = str_replace("\n", "\\n", $row[$i]);
                        $return .= '"' . $row[$i] . '"';
                    } else {
                        $return .= 'null';
                    }

                    if ($i < ($columnCount - 1)) {
                        $return .= ',';
                    }
                }

                $return .= ");\n";
            }
        }

        // Close database connection
        mysqli_close($conn);

        return file_put_contents($_ENV["BACKUP_FILE"], $return);
    }

    /**
     * Questo metodo è usato per convertire il le query MySQL in query SQLite
     * 
     * @return void
     */
    public function mysql2sqlite(): void
    {
        $sqlite = "" .
            "-- import to SQLite by running: sqlite3.exe db.sqlite3 -init sqlite.sql;\n\n" .
            "PRAGMA journal_mode = MEMORY;\n" .
            "PRAGMA synchronous = OFF;\n" .
            "PRAGMA foreign_keys = OFF;\n" .
            "PRAGMA ignore_check_constraints = OFF;\n" .
            "PRAGMA auto_vacuum = NONE;\n" .
            "PRAGMA secure_delete = OFF;\n" .
            "BEGIN TRANSACTION;\n\n";

        $currentTable = "";

        $lines = file_get_contents($_ENV["BACKUP_FILE"]);
        $lines = explode("\n", $lines);
        $skip = array('/^CREATE DATABASE/i', '/^USE/i', '/^\/\*/i', '/^--/i');
        $keys = array();

        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            for ($j = 0; $j < count($skip); $j++) {
                if (preg_match($skip[$j], $line)) {
                    continue 2;
                }
            }

            if (preg_match('/^(INSERT|\()/i', $line)) {
                $sqlite .= preg_replace("/\\'/i", "''", $line) . "\n";
                continue;
            }

            if (preg_match('/^\s*CREATE TABLE.*[`"](.*)[`"]/i', $line, $m, PREG_OFFSET_CAPTURE)) {
                // dd($m);
                $currentTable = $m[1][0];
                $sqlite .= "\n" . $line . "\n";
                continue;
            }

            if (str_starts_with($line, ")")) {
                $sqlite .= ");\n";
                continue;
            }

            $line = preg_replace('/^CONSTRAINT [`\'"][\w]+[`\'"][\s]+/i', '', $line);
            $line = preg_replace('/^[^FOREIGN][^PRIMARY][^UNIQUE]\w+\s+KEY/i', 'KEY', $line);

            if (preg_match('/^(UNIQUE\s)*KEY\s+[`\'"](\w+)[`\'"]\s+\([`\'"](\w+)[`\'"]/i', $line, $m, PREG_OFFSET_CAPTURE)) {
                $key_unique = $m[1][0];
                $key_name = $m[2][0];
                $key_col = $m[3][0];

                $toPush = "CREATE " . $key_unique . "INDEX `" . $currentTable . "_" . $key_name . "` ON `" . $currentTable . "` (`" . $key_col . "`);";

                array_push($keys, $toPush);
                continue;
            }

            if (preg_match('/^[^)]((?![\w]+\sKEY).)*$/i', $line)) {
                $line = preg_replace('/AUTO_INCREMENT|CHARACTER SET [^ ]+|CHARACTER SET [^ ]+|UNSIGNED/i', '', $line);
                $line = preg_replace('/DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP|COLLATE [^ ]+/i', '', $line);
                $line = preg_replace('/COMMENT\s[\'"`].*[\'"`]/i', '', $line);
                $line = preg_replace('/SET\([^)]+\)|ENUM[^)]+\)/i', 'TEXT ', $line);
                $line = preg_replace('/int\([0-9]*\)/i', 'INTEGER', $line);
                $line = preg_replace('/tinyint/i', 'INTEGER', $line);
                $line = preg_replace('/bigint/i', 'INTEGER', $line);
                $line = preg_replace('/varchar\([0-9]*\)|LONGTEXT/i', 'TEXT', $line);
            }

            if ($line != "") {
                $sqlite .= $line . "\n";
            }
        }

        $sqlite .= "\n";

        $sqlite = preg_replace('/,\n\);/', "\n);", $sqlite);

        $sqlite .= "\n\n" . implode("\n", $keys) . "\n\n";

        $sqlite .= $this->add_structure_table() . "\n\n";

        $sqlite .= "COMMIT;\n" .
            "PRAGMA ignore_check_constraints = ON;\n" .
            "PRAGMA foreign_keys = ON;\n" .
            "PRAGMA journal_mode = WAL;\n" .
            "PRAGMA synchronous = NORMAL;\n";


        file_put_contents($_ENV["BACKUP_FILE"], $sqlite);
    }


    public function add_structure_table(): string
    {
        $tables = array();
        $return = '';

        // Connect to database
        $conn = mysqli_connect(self::$host, self::$username, self::$password, self::$dbName);
        if (!$conn) {
            die("Connection failed: " . mysqli_connect_error());
        }

        $result = mysqli_query($conn, "SHOW TABLES");
        while ($row = mysqli_fetch_row($result)) {
            $tables[] = $row[0];
        }

        $return .= "CREATE TABLE IF NOT EXISTS `struttura` (`id` TEXT NOT NULL, `table_name` TEXT NOT NULL, `col` TEXT NOT NULL, `type` TEXT NOT NULL, PRIMARY KEY (`id`)); \n";

        $id = 0;
        foreach ($tables as $table) {
            $results = mysqli_query($conn, "SHOW COLUMNS FROM $table");
            if (!$results) {
                mysqli_close($conn);
                die("Query failed!");
            }

            while ($row = mysqli_fetch_array($results)) {
                $field = $row[0];
                $type = $row[1];
                $id = $id += 1;
                $return .= "INSERT INTO struttura VALUES ($id, '$table','$field','$type'); \n";
            }
        }

        mysqli_close($conn);

        return $return;
    }

    /**
     * Questo metodo è usato per prendere dati dal DB SQLite e inserirli in un dump .sql
     * 
     * @return string
     */
    public function create_sqlite_dump(): string
    {
        $sqlite = new Sqlite3('sqlite.db');
        $tables = array();
        $return = '';

        if (!$sqlite) die("Errore nella connessione a sqlite");

        $result = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table';");

        if (!$result) die("Errore durante l'esecuzione della query");

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $tables[] = $row['name'];
        }

        foreach ($tables as $table) {
            if ($table === "struttura") continue;

            $return .= "CREATE TABLE IF NOT EXISTS `$table` ( \n";
            $result = $sqlite->query("PRAGMA table_info('$table')");

            while ($row = $result->fetchArray()) {
                $col = $row[1];
                $query = $sqlite->query("SELECT * FROM struttura WHERE table_name = '$table' AND col = '$col';")->fetchArray();
                $type = $query['type'];
                $not_null = $row['notnull'];
                $default_value = $row[4];
                $return .= "`$col` $type ";
                if ($not_null) {
                    $return .= "NOT NULL ";
                }

                if (isset($default_value)) {
                    $return .= "DEFAULT " . $default_value . " ";
                }

                $return = trim($return);
                $return .= ",\n";
            }

            while ($row = $result->fetchArray()) {
                if ($row['pk']) {
                    $return .= "PRIMARY KEY (`$row[1]`) \n";
                }
            }

            $return .= "); \n\n";

            $values = $sqlite->query("SELECT * FROM $table");
            $colCount = $values->numColumns();

            while ($row = $values->fetchArray()) {
                $fields = array();

                $return .= "INSERT IGNORE INTO $table VALUES(";
                for ($i = 0; $i < $colCount; $i++) {
                    if (isset($row[$i])) {
                        $row[$i] = str_replace("\n", "\\n", $row[$i]);
                        $return .= '"' . $row[$i] . '"';
                    } else {
                        $return .= "null";
                    }

                    // while ($value = current($row)) {
                    //     if (!is_numeric(key($row))) {
                    //         $fields[key($row)] = $value;
                    //     }

                    //     next($row);
                    // }

                    if ($i < ($colCount - 1)) {
                        $return .= ",";
                    }
                }

                // $return .= ")\nWHERE NOT EXISTS(SELECT * FROM $table WHERE ";

                // foreach ($fields as $index => $value) {
                //     $return .= "$index='$value'";

                //     if ($index !== array_key_last($fields)) {
                //         $return .= " AND ";
                //     }
                // }

                $return .= "); \n";
            }
            $return .= "\n";
        }
        $sqlite->close();

        return file_put_contents($_ENV["RESTORE_FILE"], $return);
    }
}
