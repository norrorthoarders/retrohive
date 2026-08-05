<?php
declare(strict_types=1);

/**
 * The installer, without the wizard.
 *
 * Everything both installers need: the requirement checks, the database work,
 * writing the configuration, and the answer-file format. public/install.php adds
 * seven pages of questions on top; bin/install.php reads the answers instead.
 *
 * It lives here rather than in public/install.php, where it started, because an
 * answer file can say `delete_installer = 1` - and it did, and the wizard took
 * the shared half of the command line installer with it. The next run died on a
 * missing require. A file that deletes itself is a poor place to keep anything
 * another program depends on.
 *
 * Deliberately standalone, like the wizard was: it boots nothing, requires
 * nothing but PDO, and therefore still runs on a server missing half its
 * extensions - which is what lets it report which ones.
 */

const APP_DIR      = __DIR__ . '/..';
const CONFIG_FILE  = APP_DIR . '/src/config.local.php';
const SCHEMA_FILE  = APP_DIR . '/db/schema.sql';
const SEED_FILE    = APP_DIR . '/db/seed.sql';
const UPLOADS_DIR  = APP_DIR . '/public/uploads';

/**
 * A path as it would be typed, not as it was assembled.
 *
 * APP_DIR is __DIR__ . '/..', so every constant built from it carries the
 * public/.. in the middle - and the installer prints those paths at somebody who
 * then has to find the file. "/srv/www/vhosts/x/public/../src/config.local.php"
 * is correct and is not an answer to "where is it".
 *
 * The directory is resolved rather than the file: this is used before the file
 * exists as well as after, and realpath() on something not yet written returns
 * false.
 */
function pretty_path(string $path): string
{
    $dir  = realpath(dirname($path));
    return $dir === false ? $path : $dir . '/' . basename($path);
}

/**
 * A count and the word for it.
 *
 * "1 accounts" was on the finished-installing page, which is a small thing that
 * makes a page look unread.
 */
function counted(int $n, string $one, string $many): string
{
    return $n . ' ' . ($n === 1 ? $one : $many);
}

/**
 * The version of RetroHive this copy is, read from src/version.php.
 *
 * Parsed rather than required, because the installer deliberately boots with
 * nothing but PDO and pulling in application files would undo that.
 */
function installer_version(): string
{
    $file = @file_get_contents(dirname(__DIR__) . '/src/version.php');
    if ($file === false) {
        return '';
    }
    return preg_match("/APP_VERSION\s*=\s*'([^']+)'/", $file, $m) ? $m[1] : '';
}

/**
 * Is there a newer release? Returns [state, latest, url, detail].
 *
 * state is 'current', 'behind', or 'unknown' - and 'unknown' is a real answer
 * rather than an error: a repository with no releases yet, or a machine with no
 * outbound HTTPS, are both perfectly normal states to install from. Nothing
 * here blocks anything.
 */
function installer_update_state(): array
{
    $running = installer_version();
    $url     = 'https://api.github.com/repos/norrorthoarders/retrohive/releases/latest';

    $ctx = stream_context_create(['http' => [
        // Short on purpose. Somebody installing behind a firewall that
        // blackholes outbound traffic should wait three seconds to be told we
        // could not check, not thirty.
        'timeout' => 3,
        'method'  => 'GET',
        'header'  => "Accept: application/vnd.github+json\r\nUser-Agent: RetroHive-installer\r\n",
        'ignore_errors' => true,
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return ['unknown', '', '', 'the release feed could not be reached'];
    }

    $data = json_decode((string) $body, true);
    $tag  = is_array($data) ? ($data['tag_name'] ?? $data['version'] ?? null) : null;
    if ($tag === null) {
        $why = is_array($data) && !empty($data['message'])
            ? 'GitHub said: ' . $data['message']
            : 'the feed named no version';
        return ['unknown', '', '', $why];
    }

    $latest = ltrim((string) $tag, 'vV');
    if ($running === '') {
        return ['unknown', $latest, (string) ($data['html_url'] ?? ''), 'this copy does not state its own version'];
    }

    return [
        version_compare($latest, $running, '>') ? 'behind' : 'current',
        $latest,
        (string) ($data['html_url'] ?? ''),
        '',
    ];
}

/**
 * Bring the application up, once, after the configuration exists.
 *
 * The installer boots with nothing but PDO on purpose, which is right for the
 * five steps that run before there is a configuration to read. The last step is
 * different: it has just written one, and the work left - fetching structure data
 * and copying it into the first library - is application code with its own
 * dependencies.
 *
 * Requiring src/structure.php on its own was not enough, and failed at runtime
 * rather than at load: that file calls metadata_http_get() and
 * seed_library_hardware(), which live in two other files nothing had loaded.
 *
 * Returns false if the application cannot be brought up, so the caller can log
 * it and carry on rather than losing the install.
 */
function installer_boot_app(): bool
{
    static $booted = null;
    if ($booted !== null) {
        return $booted;
    }

    try {
        // APP_DIR, not dirname(__DIR__): the installer already worked out where
        // the application lives, and one answer is better than two that can
        // disagree.
        if (!defined('APP_ROOT')) {
            define('APP_ROOT', APP_DIR);
        }
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', '');
        }
        foreach ([
            'helpers', 'proxy', 'db', 'auth', 'throttle', 'acl', 'log',
            'notify', 'ldap', 'metadata', 'version', 'migrate', 'images',
            'models', 'structure',
        ] as $module) {
            require_once APP_ROOT . '/src/' . $module . '.php';
        }
        // Prove it rather than assume it: a missing function here is exactly
        // the failure this replaced.
        $booted = function_exists('structure_sync')
               && function_exists('seed_library_hardware')
               && function_exists('metadata_http_get');
    } catch (Throwable $e) {
        error_log('[retrohive] installer could not boot the application: ' . $e->getMessage());
        $booted = false;
    }

    return $booted;
}

function requirements(): array
{
    $r = [];

    $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
    $r[] = [
        'name'  => 'PHP 8.1 or newer',
        'ok'    => $phpOk,
        'fatal' => true,
        'found' => PHP_VERSION,
        'fix'   => 'Install a newer PHP. The application uses syntax that will not parse on 8.0.',
    ];

    $extensions = [
        'pdo_mysql' => ['fatal' => true,  'pkg' => 'php8-mysql',    'why' => 'Connects to MariaDB. Nothing works without it.'],
        'mbstring'  => ['fatal' => true,  'pkg' => 'php8-mbstring', 'why' => 'Handles multi-byte titles safely.'],
        'gd'        => ['fatal' => false, 'pkg' => 'php8-gd',       'why' => 'Generates thumbnails. Without it every photo is served full size.'],
        'exif'      => ['fatal' => false, 'pkg' => 'php8-exif',     'why' => 'Rotates phone photos the right way up.'],
        'ldap'      => ['fatal' => false, 'pkg' => 'php8-ldap',     'why' => 'Only needed for directory sign-in. Can be added later.'],
        'curl'      => ['fatal' => false, 'pkg' => 'php8-curl',     'why' => 'Used for metadata lookups. Without it they fall back to file_get_contents, which needs allow_url_fopen.'],
        'simplexml' => ['fatal' => false, 'pkg' => 'php8-xml',      'why' => 'Only needed for CSDb metadata lookups.'],
        'dom'       => ['fatal' => false, 'pkg' => 'php8-xml',      'why' => 'Needed by the HTML-scraping sources (Amiga Hardware Database, TheRetroWeb). Without it they return nothing and log why.'],
        'json'      => ['fatal' => true,  'pkg' => 'php8',          'why' => 'Used by the API and by stored settings.'],
    ];
    foreach ($extensions as $ext => $meta) {
        $r[] = [
            'name'  => 'Extension: ' . $ext,
            'ok'    => extension_loaded($ext),
            'fatal' => $meta['fatal'],
            'found' => extension_loaded($ext) ? 'loaded' : 'missing',
            'fix'   => 'zypper install ' . $meta['pkg'] . ' (or the equivalent), then restart Apache. ' . $meta['why'],
        ];
    }

    $uploadsWritable = is_dir(UPLOADS_DIR) && is_writable(UPLOADS_DIR);
    $r[] = [
        'name'  => 'public/uploads is writable',
        'ok'    => $uploadsWritable,
        'fatal' => true,
        'found' => is_dir(UPLOADS_DIR)
            ? (($uploadsWritable ? 'writable' : 'not writable') . ' by ' . current_user_name())
            : 'directory missing',
        'fix'   => 'chown -R wwwrun:www ' . UPLOADS_DIR . ' && chmod 775 ' . UPLOADS_DIR,
    ];

    // The router only works if .htaccess is being honoured. If this file was
    // reached through a rewrite the test is moot, so check the file exists and
    // that mod_rewrite is present where we can see it.
    $rewrite = function_exists('apache_get_modules')
        ? in_array('mod_rewrite', apache_get_modules(), true)
        : null;
    $r[] = [
        'name'  => 'URL rewriting',
        'ok'    => $rewrite !== false,
        'fatal' => false,
        'found' => $rewrite === null ? 'cannot detect from this SAPI' : ($rewrite ? 'mod_rewrite loaded' : 'mod_rewrite missing'),
        'fix'   => 'Enable mod_rewrite and set AllowOverride All on the document root, otherwise every URL except the front page returns 404.',
    ];

    $r[] = [
        'name'  => 'Schema file readable',
        'ok'    => is_readable(SCHEMA_FILE),
        'fatal' => true,
        'found' => is_readable(SCHEMA_FILE) ? 'db/schema.sql' : 'missing or unreadable',
        'fix'   => 'The db/ directory must be present alongside public/.',
    ];

    $postMax   = return_bytes((string) ini_get('post_max_size'));
    $uploadMax = return_bytes((string) ini_get('upload_max_filesize'));
    $r[] = [
        'name'  => 'Upload limits',
        'ok'    => $uploadMax >= 8 * 1024 * 1024 && $postMax >= $uploadMax,
        'fatal' => false,
        'found' => 'upload_max_filesize=' . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size'),
        'fix'   => 'Set upload_max_filesize to at least 16M and post_max_size well above it. A batch of box photos arrives in one request, and when it exceeds post_max_size PHP discards the whole body with no error.',
    ];

    return $r;
}

function current_user_name(): string
{
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $info = @posix_getpwuid(posix_geteuid());
        if (is_array($info) && isset($info['name'])) {
            return (string) $info['name'];
        }
    }
    return (string) (getenv('USER') ?: 'the web server user');
}

function return_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower($value[strlen($value) - 1]);
    $num  = (int) $value;
    return match ($unit) {
        'g' => $num * 1024 * 1024 * 1024,
        'm' => $num * 1024 * 1024,
        'k' => $num * 1024,
        default => $num,
    };
}

function pdo_connect(string $host, int $port, string $name, string $user, string $pass, bool $withDb = true): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;%scharset=utf8mb4', $host, $port, $withDb ? "dbname=$name;" : '');
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
}

/**
 * Split a dump into statements without tripping over semicolons that live
 * inside strings or comments. Good enough for our own schema files, which is
 * all this needs to handle.
 */
function split_sql(string $sql): array
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

        // Line comments
        if (($ch === '-' && substr($sql, $i, 2) === '--') || $ch === '#') {
            $nl = strpos($sql, "\n", $i);
            $i  = $nl === false ? $len : $nl;
            // Keep the newline. Swallowing it glues the next line onto this one,
            // which makes a column following a commented line invisible to the
            // structure check.
            $current .= "\n";
            continue;
        }
        // Block comments
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

function run_sql_file(PDO $pdo, string $file): array
{
    if (!is_readable($file)) {
        return [0, ['Cannot read ' . basename($file) . '.']];
    }
    $errors = 0;
    $messages = [];
    foreach (split_sql((string) file_get_contents($file)) as $statement) {
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            $errors++;
            if (count($messages) < 5) {
                $messages[] = $e->getMessage();
            }
        }
    }
    return [$errors, $messages];
}

/**
 * Tables this application owns, ordered so that dropping them front to back
 * never trips a foreign key. Anything else in the database is left alone -
 * the schema may well be sharing space with something unrelated.
 */
/**
 * Every table and view schema.sql creates, read from schema.sql.
 *
 * This used to be a list maintained by hand, and it had already drifted: it
 * named a table that has never existed and was missing several that do. A
 * reinstall that leaves tables behind is worse than one that fails outright,
 * because the next install finds a half-populated database and believes it.
 *
 * Returned child-first, which is the reverse of creation order, so dropping
 * them in sequence never trips a foreign key.
 */
function schema_objects(): array
{
    $sql = @file_get_contents(__DIR__ . '/../db/schema.sql');
    if (!is_string($sql) || $sql === '') {
        // Deliberately empty rather than a list kept by hand. A hand-kept list
        // drifts - this one already had - and half-dropping a database is worse
        // than not dropping it: the next install finds the leftovers and
        // believes them. The caller turns this into a refusal.
        return ['tables' => [], 'views' => [], 'unreadable' => true];
    }
    preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?/i', $sql, $t);
    preg_match_all('/CREATE OR REPLACE VIEW\s+`?(\w+)`?/i', $sql, $v);

    return [
        'tables' => array_reverse($t[1]),
        'views'  => $v[1],
    ];
}

/** How much would be lost. Missing tables simply count as zero. */
function existing_data_counts(PDO $pdo): array
{
    $counts = [];
    // 'platforms' used to be called libraries; a library is now the thing
    // people share. Counting seed rows as though they were a collection is
    // what made an empty install report "65 libraries".
    // Singular and plural, because this is counted and then written out: one
    // account is not "1 accounts".
    foreach (['items'       => ['entry', 'entries'],
              'item_images' => ['photo', 'photos'],
              'users'       => ['account', 'accounts'],
              'libraries'   => ['library', 'libraries']] as $table => $label) {
        if (!table_exists($pdo, $table)) {
            continue;
        }
        try {
            $counts[$label[1]] = ['n' => (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn(),
                              'one' => $label[0], 'many' => $label[1]];
        } catch (PDOException $e) {
            $counts[$label] = 0;
        }
    }
    return $counts;
}

/**
 * True when somebody's work is in here, as opposed to a freshly loaded schema.
 *
 * Seeded platforms, genres and studios are structure that ships with the
 * application; losing them costs nothing. Entries, accounts and photographs are
 * the things a person would be upset to lose, so only those count.
 */
function has_real_data(array $counts): bool
{
    return ($counts['entries'] ?? 0) > 0
        || ($counts['accounts'] ?? 0) > 0
        || ($counts['photos'] ?? 0) > 0;
}

/** Is the schema loaded at all? */
function structure_present(PDO $pdo): bool
{
    return table_exists($pdo, 'items') && table_exists($pdo, 'users') && table_exists($pdo, 'libraries');
}

/**
 * Drop everything this application owns, then report what failed.
 * Deliberately not DROP DATABASE: the account may not have that right, and the
 * database may be shared with something else.
 */
function drop_retrovault_tables(PDO $pdo): array
{
    $errors  = [];
    $objects = schema_objects();

    if (!empty($objects['unreadable'])) {
        return ['db/schema.sql could not be read, so there is no reliable list of what to drop. '
              . 'Nothing was removed. Restore the file and try again.'];
    }

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($objects['views'] as $view) {
            try {
                $pdo->exec("DROP VIEW IF EXISTS `$view`");
            } catch (PDOException $e) {
                $errors[] = $view . ': ' . $e->getMessage();
            }
        }
        foreach ($objects['tables'] as $table) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS `$table`");
            } catch (PDOException $e) {
                $errors[] = $table . ': ' . $e->getMessage();
            }
        }

        // Anything of ours still standing. Dropping only a fixed list means a
        // table added since the list was written survives a "total reinstall"
        // and quietly poisons the next one, so check rather than assume.
        $left = leftover_retrovault_objects($pdo, $objects);
        foreach ($left as $name) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS `$name`");
            } catch (PDOException $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }
    } finally {
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (PDOException $e) { /* nothing useful to do */ }
    }

    return $errors;
}

/**
 * Tables named in schema.sql that are still present after the drop.
 *
 * Deliberately not "every table in the database": the installer promises that
 * anything else living here is untouched, and somebody pointing RetroHive at a
 * shared database should be able to believe that.
 */
function leftover_retrovault_objects(PDO $pdo, array $objects): array
{
    $known = array_map('strtolower', array_merge($objects['tables'], $objects['views']));
    if ($known === []) {
        return [];
    }
    try {
        $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    } catch (PDOException $e) {
        return [];
    }
    $left = [];
    foreach ($rows as $row) {
        if (in_array(strtolower((string) $row[0]), $known, true)) {
            $left[] = (string) $row[0];
        }
    }
    return $left;
}

/** Remove uploaded photos, leaving the directory and its .htaccess in place. */
/**
 * Empty the uploads directory.
 *
 * Reports what it saw as well as what it removed. It used to return only the
 * count of successful unlinks, with the error suppressed - so a directory the web
 * user cannot write to came back as zero, and the installer said "No uploaded
 * files to delete" while every file was still sitting there. An erase that leaves
 * the pictures behind and calls itself clean is the wrong way round, and the
 * screen that says so is the only place anybody would have found out.
 *
 * @return array{seen:int,removed:int}
 */
function purge_uploads(): array
{
    $seen    = 0;
    $removed = 0;

    // A directory that is not there is not an empty one.
    //
    // glob() returns [] for a missing path on most systems rather than false, so
    // "there is no such directory" and "there is nothing in it" arrived at the
    // same reassuring sentence. If the uploads directory has moved - a different
    // `uploads.dir` in the configuration, a deployment that keeps it outside the
    // document root - this is what says so.
    if (!is_dir(UPLOADS_DIR)) {
        return ['seen' => 0, 'removed' => 0, 'unreadable' => true, 'missing' => true];
    }

    // glob() returning false is not "the directory is empty".
    //
    // It fails when the directory cannot be read at all - the wrong owner, or an
    // open_basedir that does not include it - and `?: []` turned that into an
    // empty list, which then reported as "no uploaded files to delete". Three
    // different situations were arriving at one reassuring sentence.
    $found = glob(UPLOADS_DIR . '/*');
    if ($found === false) {
        return ['seen' => 0, 'removed' => 0, 'unreadable' => true];
    }

    foreach ($found as $file) {
        if (!is_file($file) || str_starts_with(basename($file), '.')) {
            continue;
        }
        $seen++;
        if (@unlink($file)) {
            $removed++;
        }
    }
    return ['seen' => $seen, 'removed' => $removed, 'unreadable' => false];
}

function table_exists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$table]);
        return (int) $st->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function installer_migration_files(): array
{
    $files = glob(APP_DIR . '/db/migrations/*.sql') ?: [];
    $names = array_map('basename', $files);
    sort($names, SORT_NATURAL);
    return $names;
}

function installer_ensure_migration_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) NOT NULL, checksum CHAR(64) DEFAULT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        duration_ms INT UNSIGNED DEFAULT NULL, PRIMARY KEY (migration)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function installer_applied_migrations(PDO $pdo): array
{
    if (!table_exists($pdo, 'schema_migrations')) {
        return [];
    }
    try {
        return $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function installer_pending_migrations(PDO $pdo): array
{
    $applied = installer_applied_migrations($pdo);
    return array_values(array_diff(installer_migration_files(), $applied));
}

/** Record every migration as done, for a database just built from schema.sql. */
function installer_baseline(PDO $pdo): int
{
    installer_ensure_migration_table($pdo);
    $st = $pdo->prepare('INSERT IGNORE INTO schema_migrations (migration, checksum, duration_ms) VALUES (?, ?, 0)');
    $n = 0;
    foreach (installer_migration_files() as $name) {
        $file = APP_DIR . '/db/migrations/' . $name;
        $st->execute([$name, hash('sha256', (string) @file_get_contents($file))]);
        $n++;
    }
    return $n;
}

/**
 * The installer runs only when there is no configuration file.
 *
 * That is the whole rule, and it is deliberately blunt: a file on disk is
 * something only shell access can create or remove, so the gate cannot be
 * opened from a browser. Reinstalling means moving the config aside first,
 * which is a decision someone has to make at a terminal.
 */
function config_exists(): bool
{
    return is_file(CONFIG_FILE);
}

function config_php(array $v): string
{
    $q = fn($s) => var_export((string) $s, true);
    $bool = fn($b) => $b ? 'true' : 'false';
    $proxies = array_values(array_filter(array_map('trim', explode(',', (string) ($v['trusted_proxies'] ?? '')))));
    $proxyList = $proxies === []
        ? '[]'
        : "[\n        " . implode(",\n        ", array_map($q, $proxies)) . ",\n    ]";

    return <<<PHP
<?php
declare(strict_types=1);

// Written by public/install.php on {$v['written_at']}.
// This file overrides src/config.php. Keep it out of version control.

return [
    'app_name'    => {$q($v['app_name'])},
    'app_tagline' => {$q($v['app_tagline'])},
    'currency'    => {$q($v['currency'])},
    'timezone'    => {$q($v['timezone'])},
    'debug'       => {$bool($v['debug'] ?? false)},
    // Separate switch, off by default even when 'debug' above is on: that
    // one is about whether a PHP error shows its stack trace, this one is
    // about whether /status/debug answers at all rather than 404ing.
    'debug_status' => {$bool($v['debug_status'] ?? false)},

    // 0/false = sign-in required to see anything.
    'public_browse' => false,

    'db' => [
        'host' => {$q($v['db_host'])},
        'port' => {$v['db_port']},
        'name' => {$q($v['db_name'])},
        'user' => {$q($v['db_user'])},
        'pass' => {$q($v['db_pass'])},
    ],

    // Absolute URL clients use. Set this when a reverse proxy terminates TLS,
    // otherwise the API hands mobile clients unreachable http:// image URLs.
    'base_url' => {$q($v['base_url'])},

    // Reverse proxies whose X-Forwarded-* headers may be believed.
    // Anything not listed here is treated as a direct client.
    'trusted_proxies' => {$proxyList},

    'api' => [
        'cors_origins' => ['*'],
        'token_days'   => 0,
    ],
];

PHP;
}

/** What an answer file may say, and what it means when it says nothing. */
function answers_defaults(): array
{
    return [
        'db'       => ['host' => '127.0.0.1', 'port' => 3306, 'name' => '',
                       'user' => '', 'pass' => ''],
        'admin'    => ['username' => '', 'password' => '', 'email' => '',
                       'display_name' => ''],
        'instance' => ['name' => 'RetroHive', 'tagline' => '', 'url' => '',
                       'currency' => 'SEK', 'timezone' => 'Europe/Stockholm',
                       'trusted_proxies' => '', 'debug' => false, 'debug_status' => false],
        // Only the command line installer reads these, and only when it runs as
        // root: the wizard already runs as the web server, so what it writes is
        // owned by the right account without anybody having to say so.
        'server'   => ['web_user' => '', 'web_group' => ''],
        'install'  => ['deploy' => 'install', 'erase_uploads' => false,
                       'force_erase' => false, 'delete_installer' => false,
                       'sign_in' => false, 'metadata_sources' => true,
                       'structure' => 'remote', 'examples' => false],
    ];
}

/**
 * The fields that are never written down, and what stands in for them.
 *
 * Both usernames as well as both passwords. A file that names the database
 * account and the administrator is a file that has to be handled carefully; one
 * where every credential is a blank is one that can be committed, templated and
 * passed around, which is the point of having it.
 */
function answers_placeholders(): array
{
    return [
        'db.user'        => 'change-database-user-here',
        'db.pass'        => 'change-database-password-here',
        'admin.username' => 'change-admin-username-here',
        'admin.password' => 'change-admin-password-here',
    ];
}

/**
 * An answer file, from a set of answers.
 *
 * Written with the comments, because the person opening it is about to fill in
 * four blanks and choose between three words for `deploy`, and a bare key with
 * no explanation is how the wrong one gets chosen.
 */
function answers_export(array $v): string
{
    $ph = answers_placeholders();
    $q  = function ($value): string {
        $value = (string) $value;
        // Quoted when it could be read as something other than a string: an
        // address with a semicolon in it, anything with a comma, anything empty.
        return preg_match('/^[A-Za-z0-9._\/:-]*$/', $value) === 1
            ? $value
            : '"' . str_replace('"', '\"', $value) . '"';
    };
    $bool = fn($b) => $b ? '1' : '0';
    $at   = date('j M Y, H:i');

    $lines = [];
    $lines[] = '; RetroHive install answers, written ' . $at . '.';
    $lines[] = ';';
    $lines[] = '; Fill in the four blanks below and this installs without asking anything:';
    $lines[] = ';';
    $lines[] = ';     php bin/install.php --answers this-file.rsp --dry-run';
    $lines[] = ';     php bin/install.php --answers this-file.rsp';
    $lines[] = ';';
    $lines[] = '; The web installer takes it too, on its first page.';
    $lines[] = ';';
    $lines[] = '; No password or username was saved. Replace every change-...-here below, or';
    $lines[] = '; leave them and set RETROVAULT_DB_PASS and RETROVAULT_ADMIN_PASS in the';
    $lines[] = '; environment instead, which keeps the secrets out of the file for good.';
    $lines[] = '';
    $lines[] = '[db]';
    $lines[] = 'host = ' . $q($v['db']['host'] ?? '127.0.0.1');
    $lines[] = 'port = ' . (int) ($v['db']['port'] ?? 3306);
    $lines[] = 'name = ' . $q($v['db']['name'] ?? '');
    $lines[] = 'user = ' . $ph['db.user'];
    $lines[] = 'pass = ' . $ph['db.pass'];
    $lines[] = '';
    $lines[] = '[admin]';
    $lines[] = 'username = ' . $ph['admin.username'];
    $lines[] = 'password = ' . $ph['admin.password'];
    $lines[] = '; Not a secret, and needed for password resets and mail.';
    $lines[] = 'email = ' . $q($v['admin']['email'] ?? '');
    $lines[] = 'display_name = ' . $q($v['admin']['display_name'] ?? '');
    $lines[] = '';
    $lines[] = '[instance]';
    $lines[] = 'name = ' . $q($v['instance']['name'] ?? 'RetroHive');
    $lines[] = 'tagline = ' . $q($v['instance']['tagline'] ?? '');
    $lines[] = '; The address clients reach this by. Mail and notifications build';
    $lines[] = '; links from it; without it they point nowhere.';
    $lines[] = 'url = ' . $q($v['instance']['url'] ?? '');
    $lines[] = 'currency = ' . $q($v['instance']['currency'] ?? 'SEK');
    $lines[] = 'timezone = ' . $q($v['instance']['timezone'] ?? 'Europe/Stockholm');
    $lines[] = '; Behind a reverse proxy, whose forwarded headers to believe.';
    $lines[] = 'trusted_proxies = ' . $q($v['instance']['trusted_proxies'] ?? '');
    $lines[] = '; Raw PHP errors instead of a clean crash page. For a local install being';
    $lines[] = '; actively worked on, not a server anyone else can reach.';
    $lines[] = 'debug = ' . $bool($v['instance']['debug'] ?? false);
    $lines[] = '; A separate switch: turns on /status/debug, which answers with a build';
    $lines[] = '; number and a deploy timestamp - see README.md. Off by default even when';
    $lines[] = '; debug above is on, and off means that address 404s outright rather than';
    $lines[] = '; refuses, so a stranger cannot tell "not built" from "turned off".';
    $lines[] = 'debug_status = ' . $bool($v['instance']['debug_status'] ?? false);
    $lines[] = '';
    $lines[] = '[server]';
    $lines[] = '; Who the web server runs as. Used by bin/install.php when it is run';
    $lines[] = '; as root, because root owns what root writes - and a configuration';
    $lines[] = '; file the web server cannot read is a 503 with nothing in the log.';
    $lines[] = '; Left blank it looks for wwwrun, www-data, apache, nginx and http.';
    $lines[] = 'web_user = ' . $q($v['server']['web_user'] ?? '');
    $lines[] = 'web_group = ' . $q($v['server']['web_group'] ?? '');
    $lines[] = '';
    $lines[] = '[install]';
    $lines[] = '; install  build the structure in an empty database';
    $lines[] = '; erase    drop what is there first - destroys the collection';
    $lines[] = '; keep     leave the database alone, write the configuration only';
    $lines[] = 'deploy = ' . $q($v['install']['deploy'] ?? 'install');
    $lines[] = '; With erase, whether the photographs on disk go too.';
    $lines[] = 'erase_uploads = ' . $bool($v['install']['erase_uploads'] ?? false);
    $lines[] = '; Erase destroys a collection, so on its own it stops to be confirmed:';
    $lines[] = '; the wizard shows the review page, and bin/install.php refuses. Set this';
    $lines[] = '; to 1 to say you mean it, and neither asks.';
    $lines[] = 'force_erase = ' . $bool($v['install']['force_erase'] ?? false);
    $lines[] = '; remote   fetch the published structure data';
    $lines[] = '; shipped  use the copies in this checkout';
    $lines[] = '; none     start with an empty filing tree';
    $lines[] = 'structure = ' . $q($v['install']['structure'] ?? 'remote');
    $lines[] = '; A handful of catalogue entries to look at.';
    $lines[] = 'examples = ' . $bool($v['install']['examples'] ?? false);
    $lines[] = '; Switch on the lookup sources that need no account or key. The ones';
    $lines[] = '; that do - IGDB, TheGamesDB - are added by hand afterwards, because';
    $lines[] = '; somebody has to go and fetch credentials for them.';
    $lines[] = 'metadata_sources = ' . $bool($v['install']['metadata_sources'] ?? true);
    $lines[] = '; Delete public/install.php once the install has finished. A file that';
    $lines[] = '; refuses to run is still better removed than left in a document root.';
    $lines[] = 'delete_installer = ' . $bool($v['install']['delete_installer'] ?? false);
    $lines[] = '; Sign in as the administrator just created and go straight to the';
    $lines[] = '; instance, instead of stopping on the installer\'s last page.';
    $lines[] = '; The command line installer has no browser to sign in, and ignores it.';
    $lines[] = 'sign_in = ' . $bool($v['install']['sign_in'] ?? false);
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * Reads one. Returns [answers, problems].
 *
 * Problems rather than exceptions, because both callers want the whole list: a
 * provisioning run that fails, is corrected and fails again on the next line is
 * three round trips where one would do.
 */
function answers_parse(string $ini): array
{
    $problems = [];
    $parsed = @parse_ini_string($ini, true, INI_SCANNER_TYPED);
    if (!is_array($parsed)) {
        return [answers_defaults(), ['That is not an answer file - it could not be read as INI.']];
    }

    // A section written twice.
    //
    // parse_ini_string() keeps the last one and discards the first without a
    // word, so a file with two [install] blocks quietly installs with the
    // defaults for everything the first block said - which for `deploy` is the
    // difference between rebuilding a database and leaving it alone. Caught here
    // because the parser will not.
    $seen = [];
    foreach (preg_split('/\R/', $ini) as $line) {
        if (preg_match('/^\s*\[([^\]]+)\]/', $line, $m) === 1) {
            $name = trim($m[1]);
            if (isset($seen[$name])) {
                $problems[] = 'Section [' . $name . '] appears more than once; '
                            . 'everything in the first one would be lost.';
            }
            $seen[$name] = true;
        }
    }

    $out = answers_defaults();
    foreach ($parsed as $section => $values) {
        if (!is_array($values)) {
            $problems[] = 'Line outside any section: ' . (string) $section;
            continue;
        }
        if (!array_key_exists($section, $out)) {
            $problems[] = 'Unknown section [' . (string) $section . '].';
            continue;
        }
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $out[$section])) {
                $problems[] = 'Unknown setting ' . (string) $section . '.' . (string) $key . '.';
                continue;
            }
            $out[$section][$key] = $value;
        }
    }

    // The two passwords from the environment, if it has them, so the file can be
    // templated and hold nothing secret. The environment wins because it is the
    // more specific of the two: a file is written once, an environment is set
    // per run.
    foreach ([['RETROVAULT_DB_PASS', 'db', 'pass'],
              ['RETROVAULT_ADMIN_PASS', 'admin', 'password']] as [$var, $group, $field]) {
        $fromEnv = getenv($var);
        if ($fromEnv !== false && $fromEnv !== '') {
            $out[$group][$field] = $fromEnv;
        }
    }

    return [$out, $problems];
}

/**
 * Everything wrong with a set of answers, at once.
 *
 * `$needAdmin` is false when the database is being left alone, because there is
 * presumably an administrator in it already.
 */
function answers_check(array $a, bool $needAdmin = true): array
{
    $bad = [];

    // Placeholders first. Installing with a database user called
    // change-database-user-here fails later and less clearly.
    foreach (answers_placeholders() as $path => $placeholder) {
        [$section, $key] = explode('.', $path);
        if ((string) ($a[$section][$key] ?? '') === $placeholder) {
            $bad[] = $path . ' still says ' . $placeholder . '.';
        }
    }

    foreach (['name', 'user'] as $field) {
        if (trim((string) $a['db'][$field]) === '') {
            $bad[] = 'db.' . $field . ' is required.';
        }
    }
    if (!in_array($a['install']['deploy'], ['install', 'erase', 'keep'], true)) {
        $bad[] = 'install.deploy must be install, erase or keep.';
    }
    if (!in_array($a['install']['structure'], ['remote', 'shipped', 'none'], true)) {
        $bad[] = 'install.structure must be remote, shipped or none.';
    }

    if ($needAdmin && $a['install']['deploy'] !== 'keep') {
        if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', (string) $a['admin']['username'])) {
            $bad[] = 'admin.username must be 3-60 characters: letters, digits, dot, dash or underscore.';
        }
        // Checked here rather than left to the database, because a ten-character
        // minimum discovered after the schema has loaded means starting again.
        if (mb_strlen((string) $a['admin']['password']) < 10) {
            $bad[] = 'admin.password must be at least 10 characters.';
        }
        if (!filter_var((string) $a['admin']['email'], FILTER_VALIDATE_EMAIL)) {
            $bad[] = 'admin.email must be an address.';
        }
    }

    $url = trim((string) $a['instance']['url']);
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
        $bad[] = 'instance.url does not look like an address.';
    }
    if (!in_array((string) $a['instance']['timezone'], timezone_identifiers_list(), true)) {
        $bad[] = 'instance.timezone is not a timezone PHP knows.';
    }
    return $bad;
}

/**
 * Who the web server runs as, if this is a machine where that matters.
 *
 * Only interesting to the command line installer, and only when it is root: it
 * writes src/config.local.php at 0640, and 0640 owned by root is a file the web
 * server cannot read - which surfaces as a 503 with nothing in any log, because
 * the application never got far enough to write one.
 *
 * Returns [user, group], either of which may be null when nothing was found. The
 * names are the conventional ones per distribution, in the order they are worth
 * trying: SUSE, Debian, RHEL, then the two web servers that sometimes run as
 * themselves.
 *
 * @return array{0: ?string, 1: ?string}
 */
function web_server_account(string $wantUser = '', string $wantGroup = ''): array
{
    $exists = function (string $name, bool $group): bool {
        if ($name === '') { return false; }
        if ($group && function_exists('posix_getgrnam')) { return posix_getgrnam($name) !== false; }
        if (!$group && function_exists('posix_getpwnam')) { return posix_getpwnam($name) !== false; }
        // Without the posix extension, read the files it would have read.
        $file = $group ? '/etc/group' : '/etc/passwd';
        $body = @file_get_contents($file);
        return is_string($body)
            && preg_match('/^' . preg_quote($name, '/') . ':/m', $body) === 1;
    };

    // Said outright in the answer file, which wins: a machine can have more than
    // one of these accounts and only the operator knows which is serving.
    if ($wantUser !== '') {
        $group = $wantGroup !== '' ? $wantGroup : $wantUser;
        return [$exists($wantUser, false) ? $wantUser : null,
                $exists($group, true) ? $group : null];
    }

    foreach ([['wwwrun', 'www'], ['www-data', 'www-data'], ['apache', 'apache'],
              ['nginx', 'nginx'], ['http', 'http']] as [$user, $group]) {
        if ($exists($user, false)) {
            return [$user, $exists($group, true) ? $group : null];
        }
    }
    return [null, null];
}

/**
 * Switch on the metadata sources that need no account.
 *
 * The ones that ask for nothing: no key, no terms, no sign-up. IGDB and
 * TheGamesDB are left out because somebody has to go and fetch credentials for
 * them, and an installer cannot.
 *
 * Here rather than inside the wizard, where it started, because the command line
 * installer did not do it at all - an instance installed from a response file
 * came up with no lookup sources and no sign that it was supposed to have any.
 *
 * Existing rows are left alone, so running it twice adds nothing.
 *
 * @return int  how many were added
 */
function installer_enable_metadata_sources(): array
{
    if (!function_exists('metadata_provider_types')) {
        return ['added' => 0, 'skipped' => []];
    }

    $added   = 0;
    $skipped = [];

    foreach (metadata_provider_types() as $type => $def) {
        if (!empty($def['needs_key'])) {
            continue;
        }
        if ((int) scalar('SELECT COUNT(*) FROM metadata_providers WHERE type = ?', [$type]) > 0) {
            continue;
        }

        // Asked before it is added, not after.
        //
        // These used to be switched on unconditionally, so an instance came up
        // with a source that had moved, gone, or was refusing this network - and
        // the first anybody knew was a lookup that half worked, months later,
        // with no way to tell which source was at fault. The same probe the
        // Test button uses, against the term the source itself declares.
        // params as JSON, which is how the column stores it and how
        // metadata_search() reads it. Handing it the array raised "Array to
        // string conversion" and then quietly probed with no parameters at all -
        // so a source needing one would have failed the test for the wrong
        // reason and been skipped on a lie.
        $probe = metadata_search(
            ['id' => 0, 'type' => (string) $type,
             'params' => json_encode($def['params'] ?? [])],
            metadata_provider_probe((string) $type)
        );

        if ($probe['error'] !== null) {
            $skipped[(string) ($def['label'] ?? $type)] = (string) $probe['error'];
            // Written to the instance's own log, because this happens during an
            // install that may be unattended and the terminal output will be
            // gone. A source somebody expected and did not get is worth finding
            // later without re-running anything.
            if (function_exists('log_event')) {
                log_event('metadata', 'provider.skipped', sprintf(
                    'Not switched on during installation: %s did not answer its own probe - %s',
                    (string) ($def['label'] ?? $type), (string) $probe['error']
                ), LOG_WARNING, ['source' => (string) $type]);
            }
            continue;
        }

        insert_row('metadata_providers', [
            'name'       => (string) ($def['label'] ?? $type),
            'type'       => (string) $type,
            'params'     => json_encode($def['params'] ?? []),
            'priority'   => 100,
            'is_enabled' => 1,
        ]);
        $added++;
    }

    return ['added' => $added, 'skipped' => $skipped];
}

