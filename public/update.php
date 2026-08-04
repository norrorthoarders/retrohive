<?php
declare(strict_types=1);

/**
 * Web updater.
 *
 * Reached automatically when the files are newer than the database. Standalone
 * like the installer, because the application deliberately refuses to boot in
 * that state.
 *
 * Requires an administrator sign-in when one is possible. When the users table
 * itself is missing - the case where the schema is badly behind - it falls back
 * to a token written to a file only someone with shell access can read.
 */

define('APP_ROOT', dirname(__DIR__));
define('BASE_PATH', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/'));

require APP_ROOT . '/src/helpers.php';
require APP_ROOT . '/src/proxy.php';
require APP_ROOT . '/src/db.php';
require APP_ROOT . '/src/version.php';
require APP_ROOT . '/src/migrate.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set((string) config('timezone', 'UTC'));

if (!app_is_configured()) {
    header('Location: ' . BASE_PATH . '/install.php');
    exit;
}

session_name('retrohive');
session_start();

function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Who may run this.
 *
 * Normal case: an admin session. If the users table is missing or unreadable
 * the schema is too far behind to ask, so a one-time token file is accepted
 * instead - writing it needs shell access, which is the same bar as running
 * the CLI tool.
 */
function updater_authorised(): array
{
    try {
        if (table_present('users')) {
            $id = $_SESSION['user_id'] ?? null;
            if ($id !== null) {
                $u = one('SELECT username, role FROM users WHERE id = ? AND is_active = 1', [(int) $id]);
                if ($u !== null && $u['role'] === 'admin') {
                    return [true, 'signed in as ' . $u['username'], false];
                }
                return [false, 'This account is not an administrator.', false];
            }
            return [false, 'Sign in as an administrator first.', false];
        }
    } catch (Throwable $e) {
        // fall through to the token path
    }

    $tokenFile = APP_ROOT . '/.update-token';
    if (is_readable($tokenFile)) {
        $expected = trim((string) file_get_contents($tokenFile));
        $given    = trim((string) ($_REQUEST['token'] ?? ''));
        if ($expected !== '' && hash_equals($expected, $given)) {
            return [true, 'authorised by update token', true];
        }
        return [false, 'A token file exists. Append ?token=… from .update-token to the URL.', true];
    }

    return [false, 'The accounts table is unreadable, so sign-in is not possible. Either run php bin/migrate.php up over SSH, or write a shared secret to .update-token and reload with ?token=…', true];
}

function table_present(string $table): bool
{
    try {
        return (int) scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        ) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function csrf(): string
{
    if (empty($_SESSION['update_csrf'])) {
        $_SESSION['update_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['update_csrf'];
}

[$allowed, $why, $tokenMode] = updater_authorised();
$status  = update_status();
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $allowed) {
    if (!hash_equals(csrf(), (string) ($_POST['_csrf'] ?? ''))) {
        http_response_code(419);
        exit('Session expired. Reload and try again.');
    }
    if (isset($_POST['migrate'])) {
        $results = run_pending_migrations();
        $status  = update_status();
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<title>Update · RetroHive</title>
<link rel="stylesheet" href="<?= h(BASE_PATH) ?>/assets/css/app.css?v=<?= h((string) @filemtime(__DIR__ . '/assets/css/app.css')) ?>">
<style>
  .wiz { max-width: 860px; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
  pre.cfg { background: var(--crust); border: 1px solid var(--line); border-radius: var(--r);
            padding: .9rem; overflow: auto; font-size: .8rem; line-height: 1.5; }
  .mig { font-family: var(--mono); font-size: .82rem; }
  .yes { color: var(--good); } .no { color: var(--bad); } .warn { color: var(--warn); }
</style>
</head>
<body>
<main class="wiz">
  <span class="eyebrow">RetroHive <?= h(APP_VERSION) ?></span>
  <h1><?= $status['needs_update'] ? 'Database update required' : 'Up to date' ?></h1>

  <?php if (!$status['needs_update'] && $results === []): ?>
    <p class="lede">
      The database matches this version of the software. Nothing to do.
    </p>
    <p><a class="btn btn--accent" href="<?= h(BASE_PATH) ?>/">Open RetroHive</a></p>
  <?php else: ?>
    <p class="lede">
      The files have been updated but the database has not caught up. Until the
      migrations below are applied the application will not start, because it
      would otherwise fail partway through a page with a missing column.
    </p>
  <?php endif; ?>

  <?php foreach ($results as $r): ?>
    <div class="flash flash--<?= $r['ok'] ? 'ok' : 'error' ?>">
      <?= h($r['migration']) ?> — <?= $r['ok'] ? 'applied' : 'FAILED' ?>
      <?php if ($r['messages']): ?><br><span class="mono" style="font-size:.78rem"><?= h(implode(' | ', $r['messages'])) ?></span><?php endif; ?>
    </div>
  <?php endforeach; ?>

  <section class="panel" style="margin-top:1rem">
    <h2 class="panel__title">Migrations</h2>
    <table class="table">
      <tbody>
        <?php foreach (migration_files() as $file):
          $isPending = in_array($file, $status['pending'], true); ?>
        <tr>
          <td class="mig" style="width:1%"><?= $isPending ? '<span class="warn">pending</span>' : '<span class="yes">applied</span>' ?></td>
          <td class="mig"><?= h($file) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($status['drift']): ?>
      <p class="hint" style="color:var(--warn)">
        Changed on disk since being applied: <?= h(implode(', ', $status['drift'])) ?>.
        Usually an edited file rather than a problem, but worth knowing.
      </p>
    <?php endif; ?>
  </section>

  <section class="panel" style="margin-top:1rem">
    <h2 class="panel__title">Structure check</h2>
    <?php $st = $status['structure']; ?>
    <?php if ($st['ok']): ?>
      <p style="margin:0"><span class="yes">All <?= (int) $st['checked_tables'] ?> tables and their columns match <span class="mono">db/schema.sql</span>.</span></p>
    <?php else: ?>
      <p style="margin-top:0">Compared against <span class="mono">db/schema.sql</span>:</p>
      <ul class="mig" style="color:var(--bad)">
        <?php foreach ($st['missing_tables'] as $t): ?><li>missing table <?= h($t) ?></li><?php endforeach; ?>
        <?php foreach ($st['missing_columns'] as $c): ?><li>missing column <?= h($c) ?></li><?php endforeach; ?>
        <?php foreach ($st['missing_views'] as $v): ?><li>missing view <?= h($v) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if ($status['pending']): ?>
    <section class="panel" style="margin-top:1rem;border-left:4px solid var(--accent)">
      <h2 class="panel__title">Apply <?= count($status['pending']) ?> migration(s)</h2>
      <p style="margin-top:0;font-size:.9rem;color:var(--dim)">
        Take a backup first. Schema changes cannot be rolled back, so a dump is
        the only way back if something goes wrong:
      </p>
      <pre class="cfg">./bin/backup.sh /srv/backups/retrohive</pre>

      <?php if ($allowed): ?>
        <p class="hint"><?= h($why) ?></p>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf()) ?>">
          <button class="btn btn--accent" type="submit" name="migrate" value="1">Apply migrations now</button>
        </form>
      <?php else: ?>
        <div class="flash flash--error"><?= h($why) ?></div>
        <?php if (!$tokenMode): ?>
          <p><a class="btn" href="<?= h(BASE_PATH) ?>/login">Sign in</a></p>
        <?php endif; ?>
        <p style="font-size:.9rem;color:var(--dim)">Or over SSH, which needs no sign-in at all:</p>
        <pre class="cfg">php bin/migrate.php status
php bin/migrate.php up</pre>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if (!$status['pending'] && !$status['structure']['ok']): ?>
    <div class="flash flash--error" style="margin-top:1rem">
      Every migration has been applied, yet the structure still differs. That
      usually means a migration was recorded without running. Try
      <span class="mono">php bin/migrate.php doctor</span>.
    </div>
  <?php endif; ?>
</main>
</body>
</html>
