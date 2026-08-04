<?php
declare(strict_types=1);

/**
 * Schema migrations and structure validation.
 *
 * Copying new files over an existing install is the normal way this software
 * gets updated, so the code has to be able to notice that the database is
 * behind and say so, rather than failing later with a column-not-found error
 * halfway through rendering a page.
 *
 * Every migration in db/migrations is written to be idempotent, so re-running
 * one is harmless. The ledger exists to make the state legible, not to make
 * correctness depend on it.
 */

function migrations_dir(): string
{
    return APP_ROOT . '/db/migrations';
}

/** Migration files in the order they must run. */
function migration_files(): array
{
    $files = glob(migrations_dir() . '/*.sql') ?: [];
    $names = array_map('basename', $files);
    sort($names, SORT_NATURAL);
    return $names;
}

/** The ledger is created on demand so a fresh database needs no special case. */
function ensure_migration_table(): void
{
    q('CREATE TABLE IF NOT EXISTS schema_migrations (
        migration   VARCHAR(190) NOT NULL,
        checksum    CHAR(64)     DEFAULT NULL,
        applied_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        duration_ms INT UNSIGNED DEFAULT NULL,
        PRIMARY KEY (migration)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function applied_migrations(): array
{
    ensure_migration_table();
    $rows = all('SELECT migration, checksum, applied_at FROM schema_migrations');
    $out = [];
    foreach ($rows as $r) {
        $out[$r['migration']] = $r;
    }
    return $out;
}

function pending_migrations(): array
{
    $applied = applied_migrations();
    return array_values(array_filter(
        migration_files(),
        fn($f) => !isset($applied[$f])
    ));
}

/**
 * Cheap enough to call on every request: one primary-key count on a tiny table,
 * compared against a directory listing.
 */
function schema_is_current(): bool
{
    try {
        return pending_migrations() === [];
    } catch (Throwable $e) {
        // If we cannot even ask, let the ordinary error handling deal with it.
        return true;
    }
}

function migration_checksum(string $name): string
{
    $path = migrations_dir() . '/' . $name;
    return is_readable($path) ? hash('sha256', (string) file_get_contents($path)) : '';
}

/**
 * Migrations whose file has changed since it was applied. Usually means an
 * edited file rather than a real problem, but it is worth surfacing.
 */
function drifted_migrations(): array
{
    $drift = [];
    foreach (applied_migrations() as $name => $row) {
        if ($row['checksum'] === null || $row['checksum'] === '') {
            continue;
        }
        $now = migration_checksum($name);
        if ($now !== '' && $now !== $row['checksum']) {
            $drift[] = $name;
        }
    }
    return $drift;
}

/** Split a dump into statements, ignoring semicolons inside strings and comments. */
function split_sql_statements(string $sql): array
{
    $statements = [];
    $current    = '';
    $inString   = false;
    $quote      = '';
    $len        = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($inString) {
            $current .= $ch;
            if ($ch === '\\' && $i + 1 < $len) {
                $current .= $sql[++$i];
            } elseif ($ch === $quote) {
                $inString = false;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $inString = true;
            $quote    = $ch;
            $current .= $ch;
            continue;
        }
        if (($ch === '-' && substr($sql, $i, 2) === '--') || $ch === '#') {
            $nl = strpos($sql, "\n", $i);
            $i  = $nl === false ? $len : $nl;
            // Keep the newline. Swallowing it glues the next line onto this one,
            // which makes a column following a commented line invisible to the
            // structure check.
            $current .= "\n";
            continue;
        }
        if ($ch === '/' && substr($sql, $i, 2) === '/*') {
            $end = strpos($sql, '*/', $i);
            $i   = $end === false ? $len : $end + 1;
            continue;
        }
        if ($ch === ';') {
            if (trim($current) !== '') {
                $statements[] = trim($current);
            }
            $current = '';
            continue;
        }
        $current .= $ch;
    }
    if (trim($current) !== '') {
        $statements[] = trim($current);
    }
    return $statements;
}

/**
 * Apply one migration. Returns [ok, messages].
 *
 * DDL cannot be rolled back in MySQL or MariaDB, so a transaction would give
 * false comfort. The migrations are written defensively instead, and a failure
 * is reported loudly with the ledger left untouched so it can be retried.
 */
function run_migration(string $name): array
{
    $path = migrations_dir() . '/' . $name;
    if (!is_readable($path)) {
        return [false, ['Cannot read ' . $name]];
    }

    $started  = microtime(true);
    $messages = [];
    $failed   = 0;

    foreach (split_sql_statements((string) file_get_contents($path)) as $statement) {
        try {
            db()->exec($statement);
        } catch (PDOException $e) {
            $failed++;
            if (count($messages) < 5) {
                $messages[] = $e->getMessage();
            }
        }
    }

    if ($failed > 0) {
        return [false, $messages];
    }

    $duration = (int) round((microtime(true) - $started) * 1000);
    q('INSERT INTO schema_migrations (migration, checksum, duration_ms)
       VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), applied_at = NOW(), duration_ms = VALUES(duration_ms)',
      [$name, migration_checksum($name), $duration]);

    return [true, ['applied in ' . $duration . ' ms']];
}

/** Apply everything outstanding, stopping at the first failure. */
function run_pending_migrations(): array
{
    ensure_migration_table();
    $results = [];
    foreach (pending_migrations() as $name) {
        [$ok, $messages] = run_migration($name);
        $results[] = ['migration' => $name, 'ok' => $ok, 'messages' => $messages];
        if (!$ok) {
            break;
        }
    }
    return $results;
}

/**
 * Mark every migration as applied without running it.
 *
 * A fresh install loads db/schema.sql, which already contains everything the
 * migrations would add, so they must be recorded rather than executed.
 */
function baseline_migrations(): int
{
    ensure_migration_table();
    $n = 0;
    foreach (migration_files() as $name) {
        q('INSERT IGNORE INTO schema_migrations (migration, checksum, duration_ms) VALUES (?, ?, 0)',
          [$name, migration_checksum($name)]);
        $n++;
    }
    return $n;
}

// ---------------------------------------------------------------------------
// Structure validation
//
// The expected shape is read out of db/schema.sql rather than duplicated in a
// hand-written manifest, so it cannot drift from the file that actually builds
// the database.
// ---------------------------------------------------------------------------

/** Parse db/schema.sql into [table => [column, ...]] plus the view names. */
function expected_schema(): array
{
    $path = APP_ROOT . '/db/schema.sql';
    if (!is_readable($path)) {
        return ['tables' => [], 'views' => []];
    }
    $sql = (string) file_get_contents($path);

    $tables = [];
    foreach (split_sql_statements($sql) as $statement) {
        if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\((.*)\)[^)]*$/is', $statement, $m)) {
            continue;
        }
        $table = $m[1];
        $body  = $m[2];

        $columns = [];
        foreach (preg_split('/,\s*\n/', $body) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Skip key and constraint definitions.
            if (preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|INDEX|CONSTRAINT|FOREIGN\s+KEY|FULLTEXT)/i', $line)) {
                continue;
            }
            if (preg_match('/^`?([A-Za-z0-9_]+)`?\s+/', $line, $cm)) {
                $columns[] = $cm[1];
            }
        }
        if ($columns !== []) {
            $tables[$table] = $columns;
        }
    }

    preg_match_all('/CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+`?([A-Za-z0-9_]+)`?/i', $sql, $vm);

    return ['tables' => $tables, 'views' => array_unique($vm[1] ?? [])];
}

/** What the database actually has. */
function actual_schema(): array
{
    $tables = [];
    foreach (all("SELECT TABLE_NAME AS t, COLUMN_NAME AS c
                  FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()") as $row) {
        $tables[$row['t']][] = $row['c'];
    }
    $views = array_column(
        all("SELECT TABLE_NAME AS v FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()"),
        'v'
    );
    return ['tables' => $tables, 'views' => $views];
}

/**
 * Compare the two. Reports what is missing, never what is extra: an extra
 * column is somebody's local addition and none of our business.
 */
function schema_diff(): array
{
    $expected = expected_schema();
    $actual   = actual_schema();

    $missingTables  = [];
    $missingColumns = [];

    foreach ($expected['tables'] as $table => $columns) {
        if (!isset($actual['tables'][$table])) {
            $missingTables[] = $table;
            continue;
        }
        $have = array_map('strtolower', $actual['tables'][$table]);
        foreach ($columns as $column) {
            if (!in_array(strtolower($column), $have, true)) {
                $missingColumns[] = $table . '.' . $column;
            }
        }
    }

    $missingViews = [];
    foreach ($expected['views'] as $view) {
        if (!in_array($view, $actual['views'], true) && !isset($actual['tables'][$view])) {
            $missingViews[] = $view;
        }
    }

    return [
        'ok'              => $missingTables === [] && $missingColumns === [] && $missingViews === [],
        'missing_tables'  => $missingTables,
        'missing_columns' => $missingColumns,
        'missing_views'   => $missingViews,
        'checked_tables'  => count($expected['tables']),
    ];
}

/** Everything the update screens and the CLI need, in one call. */
function update_status(): array
{
    $pending = [];
    $applied = [];
    $drift   = [];
    $error   = null;

    try {
        $applied = applied_migrations();
        $pending = pending_migrations();
        $drift   = drifted_migrations();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $diff = ['ok' => true, 'missing_tables' => [], 'missing_columns' => [], 'missing_views' => [], 'checked_tables' => 0];
    try {
        $diff = schema_diff();
    } catch (Throwable $e) {
        $error = $error ?? $e->getMessage();
    }

    return [
        'app_version'      => APP_VERSION,
        'released'         => APP_RELEASED,
        'migrations_total' => count(migration_files()),
        'applied'          => array_keys($applied),
        'pending'          => $pending,
        'drift'            => $drift,
        'structure'        => $diff,
        'needs_update'     => $pending !== [] || !$diff['ok'],
        'error'            => $error,
    ];
}
