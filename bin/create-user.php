#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Account management from the command line, for when you are locked out of
 * the web interface.
 *
 *   php bin/create-user.php --list
 *   php bin/create-user.php tommy                  # create, prompts for password
 *   php bin/create-user.php tommy --role user --email tommy@example.com
 *   php bin/create-user.php --reset tommy          # change an existing password
 *
 * In Docker:  docker compose exec app php bin/create-user.php --list
 */

if (PHP_SAPI !== 'cli') {
    fail("Run this from a terminal, not a browser.\n");
}

define('APP_ROOT', dirname(__DIR__));
define('BASE_PATH', '');

require APP_ROOT . '/src/helpers.php';
require APP_ROOT . '/src/proxy.php';
require APP_ROOT . '/src/db.php';
require APP_ROOT . '/src/acl.php';
require APP_ROOT . '/src/models.php';

date_default_timezone_set((string) config('timezone', 'UTC'));

$args  = array_slice($argv, 1);
$flags = [];
$positional = [];
for ($i = 0; $i < count($args); $i++) {
    if (str_starts_with($args[$i], '--')) {
        $key = substr($args[$i], 2);
        $next = $args[$i + 1] ?? null;
        if ($next !== null && !str_starts_with($next, '--')) {
            $flags[$key] = $next;
            $i++;
        } else {
            $flags[$key] = true;
        }
    } else {
        $positional[] = $args[$i];
    }
}

function prompt_secret(string $label): string
{
    echo $label;
    if (function_exists('shell_exec') && stripos(PHP_OS_FAMILY, 'win') === false) {
        @shell_exec('stty -echo 2>/dev/null');
        $value = rtrim((string) fgets(STDIN), "\n");
        @shell_exec('stty echo 2>/dev/null');
        echo "\n";
        return $value;
    }
    return rtrim((string) fgets(STDIN), "\n");
}

/**
 * Stop, and say so in a way a script can hear.
 *
 * Every refusal in here used to be exit("message"), and exit() with a string
 * prints it and exits 0 - so the shell was told the account had been created
 * whatever happened. bin/testdb.sh --admin is the case that proves it: it feeds
 * two lines to a prompt that asks three questions, the third fails validation,
 * and the script reported "ok administrator created" against an empty users
 * table. A message on stdout is not an exit status.
 */
function fail(string $message): never
{
    fwrite(STDERR, $message);
    exit(1);
}

// --- list -------------------------------------------------------------------
if (isset($flags['list'])) {
    $rows = all('SELECT id, username, display_name, role, is_active, last_login_at FROM users ORDER BY username');
    if (!$rows) {
        exit("No accounts yet. Open the app in a browser and it will offer the first-run setup page.\n");
    }
    printf("%-4s %-20s %-24s %-9s %-7s %s\n", 'ID', 'USERNAME', 'NAME', 'ROLE', 'ACTIVE', 'LAST SIGN-IN');
    foreach ($rows as $r) {
        printf(
            "%-4d %-20s %-24s %-9s %-7s %s\n",
            $r['id'],
            $r['username'],
            (string) ($r['display_name'] ?? ''),
            $r['role'],
            $r['is_active'] ? 'yes' : 'no',
            (string) ($r['last_login_at'] ?? 'never')
        );
    }
    exit(0);
}

// --- reset ------------------------------------------------------------------
if (isset($flags['reset'])) {
    $username = is_string($flags['reset']) ? $flags['reset'] : ($positional[0] ?? '');
    if ($username === '') {
        fail("Usage: php bin/create-user.php --reset USERNAME\n");
    }
    $user = one('SELECT id FROM users WHERE username = ?', [$username]);
    if ($user === null) {
        fail("No account called '$username'. Try --list.\n");
    }
    $password = prompt_secret("New password for $username: ");
    if (strlen($password) < 10) {
        fail("Password must be at least 10 characters. Nothing changed.\n");
    }
    if ($password !== prompt_secret('Repeat it: ')) {
        fail("They did not match. Nothing changed.\n");
    }
    update_row('users', (int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
    exit("Password updated for $username.\n");
}

// --- create -----------------------------------------------------------------
$username = $positional[0] ?? '';
if ($username === '') {
    exit(<<<TXT
    RetroHive account tool

      php bin/create-user.php --list
      php bin/create-user.php USERNAME [--role admin|user] [--name "Full Name"] [--email ADDRESS]
      php bin/create-user.php --reset USERNAME

    TXT);
}

if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
    fail("Username can use letters, numbers, dot, dash and underscore, 3 to 64 characters.\n");
}
if (one('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
    fail("'$username' already exists. Use --reset to change the password.\n");
}

$role = is_string($flags['role'] ?? null) ? $flags['role'] : 'admin';
if (!in_array($role, ['admin', 'user'], true)) {
    fail("Role must be admin or user.\n");
}

$password = prompt_secret("Password for $username: ");
if (strlen($password) < 10) {
    fail("Password must be at least 10 characters. Nothing created.\n");
}
if ($password !== prompt_secret('Repeat it: ')) {
    fail("They did not match. Nothing created.\n");
}

$displayName = is_string($flags['name'] ?? null) ? $flags['name'] : $username;

// Required, the same as everywhere else. An account nobody can reach cannot be
// told it has been invited to anything and cannot confirm itself if this
// instance ever asks accounts to.
$email = is_string($flags['email'] ?? null) ? trim($flags['email']) : '';
if ($email === '') {
    fwrite(STDOUT, 'Email address: ');
    $email = trim((string) fgets(STDIN));
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail("An email address is required, and that one does not look like one.\n");
}
if (one('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
    fail("Another account already uses that address.\n");
}

$id = insert_row('users', [
    'username'      => $username,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'display_name'  => $displayName,
    'email'         => $email,
    'role'          => $role,
    'is_active'     => 1,
    // Created at a shell prompt by somebody with filesystem access, which is a
    // stronger claim than clicking a link in an email would be.
    'email_verified_at' => date('Y-m-d H:i:s'),
]);

// A first administrator with nowhere to put anything is not a usable install.
if ($role === 'admin') {
    $libraryId = ensure_first_library((int) $id);
    fwrite(STDOUT, "Library ready (id $libraryId). Rename it in Manage once you sign in.\n");
}

echo "Created $username (id $id) as $role.\n";
