#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Schema migration tool.
 *
 *   php bin/migrate.php status     what is applied, what is pending, is the structure sound
 *   php bin/migrate.php up         apply everything outstanding
 *   php bin/migrate.php doctor     compare the live database against db/schema.sql
 *   php bin/migrate.php baseline   mark all migrations applied without running them
 *
 * Exit codes: 0 all good, 1 something needs attention, 2 a migration failed.
 * That makes it usable from a deploy script.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from a terminal, not a browser.\n");
}

define('APP_ROOT', dirname(__DIR__));
define('BASE_PATH', '');

require APP_ROOT . '/src/helpers.php';
require APP_ROOT . '/src/proxy.php';
require APP_ROOT . '/src/db.php';
require APP_ROOT . '/src/version.php';
require APP_ROOT . '/src/migrate.php';
require APP_ROOT . '/src/throttle.php';

date_default_timezone_set((string) config('timezone', 'UTC'));

$command = $argv[1] ?? 'status';

function line(string $s = ''): void
{
    echo $s, "\n";
}

if (!app_is_configured()) {
    line('No configuration found. Copy src/config.local.php.example to src/config.local.php,');
    line('or open /install.php in a browser.');
    exit(1);
}

switch ($command) {
    case 'status':
        $s = update_status();
        line('RetroHive ' . $s['app_version'] . '  (released ' . $s['released'] . ')');
        line('');
        if ($s['migrations_total'] === 0) {
            line('Migrations: none yet. db/schema.sql is the source of truth for this release;');
            line('            add files to db/migrations once it has shipped. See that folder\'s README.');
        } else {
            line('Migrations: ' . count($s['applied']) . ' of ' . $s['migrations_total'] . ' applied');
        }
        foreach (migration_files() as $file) {
            $mark = in_array($file, $s['pending'], true) ? '[ ]' : '[x]';
            line('  ' . $mark . ' ' . $file);
        }
        if ($s['drift'] !== []) {
            line('');
            line('Changed since they were applied:');
            foreach ($s['drift'] as $d) {
                line('  ! ' . $d);
            }
        }
        line('');
        $st = $s['structure'];
        if ($st['ok']) {
            line('Structure: ' . $st['checked_tables'] . ' tables checked against db/schema.sql, all present.');
        } else {
            line('Structure problems:');
            foreach ($st['missing_tables'] as $t)  { line('  missing table  ' . $t); }
            foreach ($st['missing_columns'] as $c) { line('  missing column ' . $c); }
            foreach ($st['missing_views'] as $v)   { line('  missing view   ' . $v); }
        }
        line('');
        if ($s['needs_update']) {
            line('An update is needed. Back up first, then: php bin/migrate.php up');
            exit(1);
        }
        line('Up to date.');
        exit(0);

    case 'up':
        $pending = pending_migrations();
        if ($pending === []) {
            line('Nothing to apply.');
            $diff = schema_diff();
            if (!$diff['ok']) {
                line('However the structure does not match db/schema.sql. Run: php bin/migrate.php doctor');
                exit(1);
            }
            exit(0);
        }
        line('Applying ' . count($pending) . ' migration(s). Back up first if you have not.');
        line('');
        $failed = false;
        foreach (run_pending_migrations() as $r) {
            line(($r['ok'] ? '  ok    ' : '  FAIL  ') . $r['migration']);
            foreach ($r['messages'] as $m) {
                line('        ' . $m);
            }
            if (!$r['ok']) {
                $failed = true;
            }
        }
        line('');
        if ($failed) {
            line('Stopped at the first failure. Nothing after it was attempted.');
            exit(2);
        }
        $diff = schema_diff();
        line($diff['ok']
            ? 'Done. Structure matches db/schema.sql.'
            : 'Migrations applied, but the structure still differs. Run: php bin/migrate.php doctor');
        exit($diff['ok'] ? 0 : 1);

    case 'doctor':
        $diff = schema_diff();
        line('Checked ' . $diff['checked_tables'] . ' tables defined in db/schema.sql.');
        if ($diff['ok']) {
            line('No differences found.');
            exit(0);
        }
        foreach ($diff['missing_tables'] as $t)  { line('  missing table  ' . $t); }
        foreach ($diff['missing_columns'] as $c) { line('  missing column ' . $c); }
        foreach ($diff['missing_views'] as $v)   { line('  missing view   ' . $v); }
        line('');
        line('Loading db/schema.sql again is safe - every table uses CREATE TABLE IF NOT EXISTS -');
        line('but it will not add a column to a table that already exists. For that, find or');
        line('write the migration that introduces it.');
        exit(1);

    case 'prune':
        $days = isset($argv[2]) ? max(1, (int) $argv[2]) : 30;
        line(throttle_prune($days) . ' sign-in log rows older than ' . $days . ' days removed.');
        exit(0);

    case 'baseline':
        $n = baseline_migrations();
        line('Marked ' . $n . ' migration(s) as applied without running them.');
        exit(0);

    default:
        line('Usage: php bin/migrate.php [status|up|doctor|baseline|prune [days]]');
        exit(1);
}
