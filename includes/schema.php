<?php
/**
 * RideCare database schema: users -> bikes -> reminders -> services
 * (that order matters because of the foreign keys).
 *
 * ensure_schema() runs from includes/bootstrap.php on every request:
 *
 *   Fast path  One read-only query against information_schema. If all four tables and all
 *              of their columns exist, nothing else happens.
 *   Slow path  Only when something is missing (or when forced by setup_db.php). Takes a MySQL
 *              advisory lock so two first visitors cannot race, creates missing tables, adds
 *              missing columns, restores missing indexes / foreign keys, then checks again.
 *
 * Nothing is swallowed. If a table still cannot be created or repaired, SchemaException is
 * thrown: the exact MySQL error goes to the server log (Render > Logs) and the visitor sees
 * which tables are affected. Nothing here touches config/db.php or any environment variable.
 */

class SchemaException extends RuntimeException
{
    /** Per-table status rows (see schema_apply) so setup_db.php can show how far it got. */
    public array $report = [];
}

/**
 * Every table the app uses, in dependency order.
 *   create        full CREATE TABLE used on a fresh database
 *   columns       column => definition used to ADD it if an older table lacks it (null = cannot be added later)
 *   indexes       name => [column, unique?]            restored if no matching index exists
 *   foreign_keys  name => [column, ref table, ref column, ON DELETE action]
 */
function schema_definition(): array
{
    $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $today  = date('Y-m-d');

    return [
        'users' => [
            'create' => "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(150) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) $engine",
            'columns' => [
                'id'         => null,
                'name'       => "VARCHAR(100) NOT NULL DEFAULT ''",
                'email'      => "VARCHAR(150) NOT NULL DEFAULT ''",
                'password'   => "VARCHAR(255) NOT NULL DEFAULT ''",
                'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            ],
            'indexes'      => ['uq_users_email' => ['email', true]],
            'foreign_keys' => [],
        ],

        'bikes' => [
            'create' => "CREATE TABLE IF NOT EXISTS bikes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                name VARCHAR(100) NOT NULL,
                model VARCHAR(100) NOT NULL DEFAULT '',
                reg_no VARCHAR(30) NOT NULL DEFAULT '',
                odometer INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_bike_user (user_id),
                CONSTRAINT fk_bike_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) $engine",
            'columns' => [
                'id'         => null,
                'user_id'    => 'INT NOT NULL',
                'name'       => "VARCHAR(100) NOT NULL DEFAULT ''",
                'model'      => "VARCHAR(100) NOT NULL DEFAULT ''",
                'reg_no'     => "VARCHAR(30) NOT NULL DEFAULT ''",
                'odometer'   => 'INT NOT NULL DEFAULT 0',
                'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            ],
            'indexes'      => ['idx_bike_user' => ['user_id', false]],
            'foreign_keys' => ['fk_bike_user' => ['user_id', 'users', 'id', 'CASCADE']],
        ],

        // A reminder = "do X every N km and/or every N days". last_done_* is the baseline the next due date is counted from.
        'reminders' => [
            'create' => "CREATE TABLE IF NOT EXISTS reminders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bike_id INT NOT NULL,
                task VARCHAR(100) NOT NULL,
                interval_km INT NULL,
                interval_days INT NULL,
                last_done_date DATE NOT NULL,
                last_done_km INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_rem_bike (bike_id),
                CONSTRAINT fk_rem_bike FOREIGN KEY (bike_id) REFERENCES bikes(id) ON DELETE CASCADE
            ) $engine",
            'columns' => [
                'id'             => null,
                'bike_id'        => 'INT NOT NULL',
                'task'           => "VARCHAR(100) NOT NULL DEFAULT ''",
                'interval_km'    => 'INT NULL',
                'interval_days'  => 'INT NULL',
                'last_done_date' => "DATE NOT NULL DEFAULT '$today'",
                'last_done_km'   => 'INT NOT NULL DEFAULT 0',
                'created_at'     => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            ],
            'indexes'      => ['idx_rem_bike' => ['bike_id', false]],
            'foreign_keys' => ['fk_rem_bike' => ['bike_id', 'bikes', 'id', 'CASCADE']],
        ],

        'services' => [
            'create' => "CREATE TABLE IF NOT EXISTS services (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bike_id INT NOT NULL,
                reminder_id INT NULL,
                task VARCHAR(100) NOT NULL,
                service_date DATE NOT NULL,
                odometer INT NOT NULL DEFAULT 0,
                cost DECIMAL(10,2) NULL,
                notes VARCHAR(500) NOT NULL DEFAULT '',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_srv_bike (bike_id, service_date),
                CONSTRAINT fk_srv_bike FOREIGN KEY (bike_id) REFERENCES bikes(id) ON DELETE CASCADE,
                CONSTRAINT fk_srv_rem  FOREIGN KEY (reminder_id) REFERENCES reminders(id) ON DELETE SET NULL
            ) $engine",
            'columns' => [
                'id'           => null,
                'bike_id'      => 'INT NOT NULL',
                'reminder_id'  => 'INT NULL',
                'task'         => "VARCHAR(100) NOT NULL DEFAULT ''",
                'service_date' => "DATE NOT NULL DEFAULT '$today'",
                'odometer'     => 'INT NOT NULL DEFAULT 0',
                'cost'         => 'DECIMAL(10,2) NULL',
                'notes'        => "VARCHAR(500) NOT NULL DEFAULT ''",
                'created_at'   => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            ],
            'indexes'      => ['idx_srv_bike' => ['bike_id', false]],
            'foreign_keys' => [
                'fk_srv_bike' => ['bike_id', 'bikes', 'id', 'CASCADE'],
                'fk_srv_rem'  => ['reminder_id', 'reminders', 'id', 'SET NULL'],
            ],
        ],
    ];
}

/* ---------- Read-only inspection ---------- */

/** Prepared SELECT helper, kept local so this file works before bootstrap's db_* helpers are used. */
function schema_rows(string $sql, string $types = '', array $params = []): array
{
    global $conn;
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** [table => [column, ...]] for the given tables, from the connected database. Absent tables are simply not listed. */
function schema_inspect(array $tables): array
{
    // Table names come from schema_definition(), never from user input.
    $in    = "'" . implode("','", array_map('strtolower', $tables)) . "'";
    $found = [];
    $rows  = schema_rows(
        "SELECT table_name AS t, column_name AS c FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name IN ($in)"
    );
    foreach ($rows as $r) {
        $found[strtolower($r['t'])][] = strtolower($r['c']);
    }
    return $found;
}

/** [table => "what is wrong"]; an empty array means the schema is complete. */
function schema_problems(array $def, array $found): array
{
    $problems = [];
    foreach ($def as $table => $spec) {
        if (empty($found[$table])) {
            $problems[$table] = 'table is missing';
            continue;
        }
        $absent = array_diff(array_keys($spec['columns']), $found[$table]);
        if ($absent) {
            $problems[$table] = 'missing column(s): ' . implode(', ', $absent);
        }
    }
    return $problems;
}

/** Is there an index (any name) that starts with this column, and is unique if required? */
function schema_has_index(string $table, string $column, bool $unique): bool
{
    $row = schema_rows(
        'SELECT COUNT(*) AS n FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND seq_in_index = 1
           AND (? = 0 OR non_unique = 0)',
        'ssi', [$table, $column, $unique ? 1 : 0]
    );
    return (int) ($row[0]['n'] ?? 0) > 0;
}

/** Is there a foreign key (any name) on this column? */
function schema_has_fk(string $table, string $column): bool
{
    $row = schema_rows(
        'SELECT COUNT(*) AS n FROM information_schema.key_column_usage
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND referenced_table_name IS NOT NULL',
        'ss', [$table, $column]
    );
    return (int) ($row[0]['n'] ?? 0) > 0;
}

/* ---------- Create / repair ---------- */

/**
 * Make sure the schema is complete. Returns a per-table report (empty on the fast path).
 * $deep = true skips the fast path and re-checks every column, index and foreign key (used by setup_db.php).
 *
 * @throws SchemaException if any table is still missing or incomplete afterwards
 */
function ensure_schema(bool $deep = false): array
{
    global $conn;
    $def = schema_definition();

    if (!$deep && !schema_problems($def, schema_inspect(array_keys($def)))) {
        return [];
    }

    $db = $conn->query('SELECT DATABASE()')->fetch_row()[0] ?? null;
    if ($db === null || $db === '') {
        throw new SchemaException('No database is selected for this connection, so the tables cannot be created.');
    }

    // One request at a time does the DDL; the others wait here, then find the work already done.
    $locked = false;
    try {
        $row    = $conn->query("SELECT GET_LOCK('ridecare_schema_setup', 20)")->fetch_row();
        $locked = (int) ($row[0] ?? 0) === 1;
    } catch (mysqli_sql_exception $e) {
        error_log('[RideCare] schema lock unavailable, continuing without it: ' . $e->getMessage());
    }

    try {
        return schema_apply($def);
    } finally {
        if ($locked) {
            try {
                $conn->query("SELECT RELEASE_LOCK('ridecare_schema_setup')");
            } catch (mysqli_sql_exception $e) {
                // The lock is released automatically when the connection closes.
            }
        }
    }
}

/** Does the actual work for ensure_schema(). Rows: ['table', 'status' => ok|created|repaired|failed|skipped, 'notes' => []]. */
function schema_apply(array $def): array
{
    global $conn;
    $report = [];
    $failed = false;

    foreach ($def as $table => $spec) {
        if ($failed) {
            $report[$table] = ['table' => $table, 'status' => 'skipped', 'notes' => ['not attempted because an earlier table failed']];
            continue;
        }

        $row = ['table' => $table, 'status' => 'ok', 'notes' => []];
        $mark = function (string $note) use (&$row): void {
            $row['status']  = $row['status'] === 'ok' ? 'repaired' : $row['status'];
            $row['notes'][] = $note;
        };

        try {
            // 1. The table itself
            if (empty(schema_inspect([$table])[$table])) {
                $conn->query($spec['create']);
                $row['status'] = 'created';
            }

            // 2. Every column the app reads or writes
            $have = schema_inspect([$table])[$table] ?? [];
            if (!$have) {
                throw new RuntimeException('table is still missing right after CREATE TABLE');
            }
            foreach ($spec['columns'] as $col => $ddl) {
                if (in_array($col, $have, true)) {
                    continue;
                }
                if ($ddl === null) {
                    throw new RuntimeException("column `$col` is missing and cannot be added automatically");
                }
                $conn->query("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
                $mark("added column $col");
            }
        } catch (Throwable $e) {
            error_log("[RideCare] schema: table `$table` could not be created or repaired: " . $e->getMessage());
            $row['status']  = 'failed';
            $row['notes'][] = 'see the server log for the MySQL error';
            $report[$table] = $row;
            $failed = true;   // the tables after this one depend on it
            continue;
        }

        // 3. Indexes and foreign keys: restored when absent. A problem here is reported but does not stop the app.
        foreach ($spec['indexes'] as $name => [$col, $unique]) {
            if (schema_has_index($table, $col, $unique)) {
                continue;
            }
            try {
                $conn->query("ALTER TABLE `$table` ADD " . ($unique ? 'UNIQUE ' : '') . "INDEX `$name` (`$col`)");
                $mark("added index $name");
            } catch (mysqli_sql_exception $e) {
                error_log("[RideCare] schema: could not add index `$name` on `$table`: " . $e->getMessage());
                $row['notes'][] = "warning: could not add index $name (see the server log)";
            }
        }
        foreach ($spec['foreign_keys'] as $name => [$col, $refTable, $refCol, $onDelete]) {
            if (schema_has_fk($table, $col)) {
                continue;
            }
            try {
                $conn->query("ALTER TABLE `$table` ADD CONSTRAINT `$name` FOREIGN KEY (`$col`) "
                           . "REFERENCES `$refTable`(`$refCol`) ON DELETE $onDelete");
                $mark("added foreign key $name");
            } catch (mysqli_sql_exception $e) {
                error_log("[RideCare] schema: could not add foreign key `$name` on `$table`: " . $e->getMessage());
                $row['notes'][] = "warning: could not add foreign key $name (see the server log)";
            }
        }

        $report[$table] = $row;
    }

    // Final check against the real database, so success is never assumed.
    $problems = schema_problems($def, schema_inspect(array_keys($def)));
    if ($failed || $problems) {
        error_log('[RideCare] schema still incomplete: ' . json_encode($problems));
        $names = array_values(array_unique(array_merge(
            array_keys(array_filter($report, fn($r) => in_array($r['status'], ['failed', 'skipped'], true))),
            array_keys($problems)
        )));
        $e = new SchemaException(
            'Database setup is incomplete (' . implode(', ', $names) . '). The tables could not be created automatically. '
            . 'Check that the database user may CREATE and ALTER tables, then look at the server log for the exact MySQL error.'
        );
        $e->report = array_values($report);
        throw $e;
    }

    return array_values($report);
}
