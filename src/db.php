<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = config('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'],
        $c['port'],
        $c['name'],
        $c['charset']
    );

    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            // How long to wait for a database that is not answering.
            //
            // Unset, this waits for the operating system to give up on the TCP
            // connection - thirty seconds on Linux, and *every* request waits
            // that long, because almost every page needs the database. A pool of
            // PHP workers empties in a few seconds of that, and a proxy in front
            // then has nothing left to talk to and reports "no server is
            // available to handle this request".
            //
            // Which is a 503 caused by a slow database rather than a broken
            // application, and it took a health check being switched off to find
            // it. Five seconds: long enough for a busy server, short enough that
            // workers are returned before the pool drains.
            PDO::ATTR_TIMEOUT            => (int) (config('db.timeout') ?? 5),
        ]);
    } catch (PDOException $e) {
        if (config('debug')) {
            throw $e;
        }
        if (PHP_SAPI === 'cli') {
            throw new RuntimeException(
                'Database unavailable: ' . $e->getMessage()
            );
        }

        // Which failure is this, though?
        //
        // A configured instance whose database has gone away and an instance
        // that was never finished look identical from here - the connection
        // fails either way - and the second one is the common case for somebody
        // who has just unpacked this. Sending them to the installer is what used
        // to happen and is what they need; telling them to check DB_HOST is an
        // answer to a question they have not asked yet.
        //
        // Only when the installer is actually there. On an instance that has
        // been finished properly it has been deleted, and then this is a real
        // outage and says so.
        $selfPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $onInstaller = str_ends_with($selfPath, '/install.php');
        $haveInstaller = function_exists('installer_path') && is_file(installer_path());

        // 503, not 500: the database being unreachable is a "come back later",
        // and it is what a proxy in front should be told so it stops sending
        // traffic here rather than serving errors.
        http_response_code(503);
        header('Retry-After: 30');

        if (str_starts_with($selfPath, '/api/')) {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['error' => ['code' => 'database_unavailable',
                'message' => 'The database is not answering.']]));
        }

        if ($haveInstaller && !$onInstaller) {
            header('Location: ' . (defined('BASE_PATH') ? BASE_PATH : '') . '/install.php', true, 302);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        exit('<!doctype html><meta charset="utf-8"><title>Database unavailable</title>'
           . '<body style="font-family:system-ui;background:#1e1e2e;color:#cdd6f4;padding:3rem;line-height:1.6">'
           . '<h1>The database is not answering</h1>'
           . '<p>The settings in <code>src/config.local.php</code> are readable, so this is the '
           . 'database itself: check that MariaDB is running and reachable from this host.</p>'
           . '<p style="color:#a6adc8">There is no installer to send you to — which means this '
           . 'instance was finished properly, and this is an outage rather than a setup step.</p>'
           . '</body>');
    }

    /**
     * Put the database session in the same timezone as PHP.
     *
     * Without this, MariaDB reads and writes TIMESTAMP columns in the server's
     * timezone (usually UTC) while PHP formats dates in the configured one.
     * Everything looks fine until you compare a PHP-generated timestamp against
     * updated_at - which is exactly what the API sync endpoint does - and the
     * offset silently hides recent changes.
     */
    try {
        $offset = (new DateTimeImmutable('now', new DateTimeZone((string) config('timezone', 'UTC'))))->format('P');
        $pdo->exec("SET time_zone = '$offset'");
    } catch (Throwable $e) {
        // A server without timezone tables still works; the app just uses its own.
        error_log('[retrohive] could not set session time_zone: ' . $e->getMessage());
    }

    return $pdo;
}

/** Run a query with bound parameters and return the statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** First row, or null. */
function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** All rows. */
function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Single scalar from the first column of the first row. */
function scalar(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function last_id(): int
{
    return (int) db()->lastInsertId();
}

/**
 * Insert an associative array into a table. Returns the new id.
 */
function insert_row(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        implode(', ', array_map(fn($c) => "`$c`", $cols)),
        implode(', ', array_map(fn($c) => ":$c", $cols))
    );
    q($sql, $data);
    return last_id();
}

/**
 * Update a single row by primary key.
 */
function update_row(string $table, int $id, array $data): void
{
    if ($data === []) {
        return;
    }
    $sets = implode(', ', array_map(fn($c) => "`$c` = :$c", array_keys($data)));
    $data['__id'] = $id;
    q(sprintf('UPDATE `%s` SET %s WHERE id = :__id', $table, $sets), $data);
}

function delete_row(string $table, int $id): void
{
    q(sprintf('DELETE FROM `%s` WHERE id = ?', $table), [$id]);
}

/**
 * Does this table have this column?
 *
 * Asked once per table per request and kept, because the alternative is a
 * schema query inside a loop. Used where a generic screen writes to several
 * tables and only some of them are scoped to a library - "add library_id if
 * there is one" is a fact about the table, not something a caller should have
 * to carry a list of.
 */
function table_has_column(string $table, string $column): bool
{
    if (!isset($GLOBALS['__table_columns'][$table])) {
        $cols = [];
        foreach (all('SELECT COLUMN_NAME FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) as $row) {
            $cols[(string) $row['COLUMN_NAME']] = true;
        }
        $GLOBALS['__table_columns'][$table] = $cols;
    }
    return isset($GLOBALS['__table_columns'][$table][$column]);
}
