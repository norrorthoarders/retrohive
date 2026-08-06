#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * RetroHive, installed from the command line.
 *
 * The wizard in public/install.php asks seven pages of questions and is the
 * right thing for one machine. It is the wrong thing for the twentieth, for a
 * container that has to come up unattended, and for anything where the answers
 * belong in a file somebody can review before it runs.
 *
 * So this reads the answers instead of asking them, and does the same work in
 * the same order using the same functions - it includes the wizard for its
 * helpers rather than keeping a second copy of pdo_connect() and config_php()
 * that would drift.
 *
 *   php bin/install.php --example > install.rsp
 *   $EDITOR install.rsp
 *   php bin/install.php --answers install.rsp --dry-run
 *   php bin/install.php --answers install.rsp
 *
 * Exit status is 0 only if the install finished. Anything else is a failure with
 * a reason on stderr, which is what a provisioning script needs and what a web
 * page cannot give it.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("bin/install.php is for the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));

// The shared half, which is not the wizard.
//
// This used to require public/install.php, which worked until an answer file
// said `delete_installer = 1` - and then the wizard deleted itself and took the
// command line installer's helpers with it. src/installer.php is not something
// anything deletes.
require APP_ROOT . '/src/installer.php';

// ---------------------------------------------------------------------------
// Saying things
// ---------------------------------------------------------------------------

$QUIET = false;

function say(string $line): void
{
    global $QUIET;
    if (!$QUIET) { fwrite(STDOUT, '  ' . $line . "\n"); }
}

function note(string $line): void
{
    global $QUIET;
    // --quiet used to silence say() and leave these, so a "silent" install still
    // wrote three lines to stdout. Silent means nothing on success and the
    // reason on stderr on failure, because that is what a provisioning run wants
    // in its output when everything worked.
    if (!$QUIET) { fwrite(STDOUT, "\n" . $line . "\n\n"); }
}

/**
 * Stop, with a reason.
 *
 * On stderr and with a non-zero status, because the caller is usually a script
 * that will otherwise carry on and configure something that does not exist.
 */
function stop(string $why): never
{
    fwrite(STDERR, "\nInstall failed: " . $why . "\n\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// The answers
// ---------------------------------------------------------------------------

/**
 * Reads the answer file, or standard input.
 *
 * The parsing, the defaults and the checks all live in public/install.php, which
 * this file already includes: the wizard writes these and reads them too, and
 * two definitions of one format is one definition too many.
 */
function read_answers(string $path): array
{
    // "-" is standard input, for the case where the answers should not exist as
    // a file at all: a provisioning tool holding the credentials in a secret
    // store can pipe them in and leave nothing on disk.
    if ($path === '-') {
        $body = stream_get_contents(STDIN);
        if ($body === false || trim($body) === '') {
            stop('Nothing arrived on standard input.');
        }
    } else {
        if (!is_file($path) || !is_readable($path)) {
            stop($path . ' cannot be read.');
        }
        $body = (string) file_get_contents($path);
    }

    [$answers, $problems] = answers_parse($body);
    if ($problems !== []) {
        refuse($problems, $path === '-' ? 'standard input' : $path);
    }
    return $answers;
}

/** The whole list, on stderr, and stop. */
function refuse(array $problems, string $where): never
{
    fwrite(STDERR, "\nThe answers in " . $where . " were refused:\n");
    foreach ($problems as $line) {
        fwrite(STDERR, '  - ' . $line . "\n");
    }
    fwrite(STDERR, "\n");
    exit(1);
}


// ---------------------------------------------------------------------------
// Asking, when there is no file
// ---------------------------------------------------------------------------

/**
 * One question, with what it will use if the answer is nothing.
 *
 * Validated here rather than at the end. A run that asks fifteen questions and
 * then reports that the third was a bad address has wasted the other twelve.
 */
function ask(string $label, string $default = '', ?callable $ok = null): string
{
    while (true) {
        fwrite(STDOUT, '  ' . $label . ($default !== '' ? " [$default]" : '') . ': ');
        $line = fgets(STDIN);
        if ($line === false) { stop('Standard input closed.'); }
        $value = trim($line);
        if ($value === '') { $value = $default; }
        if ($ok === null) { return $value; }
        $why = $ok($value);
        if ($why === null) { return $value; }
        fwrite(STDOUT, '    ' . $why . "\n");
    }
}

/** One of a set, by name. */
function ask_choice(string $label, array $choices, string $default): string
{
    return ask($label . ' (' . implode('/', $choices) . ')', $default,
        fn($v) => in_array($v, $choices, true)
            ? null : 'One of: ' . implode(', ', $choices) . '.');
}

function ask_yes(string $label, bool $default = false): bool
{
    return ask_choice($label, ['yes', 'no'], $default ? 'yes' : 'no') === 'yes';
}

/**
 * A password, without echoing it and typed twice.
 *
 * `stty -echo` because PHP has no portable way to do this. Where stty is missing
 * the question is still asked, and warns that it will be visible - which is
 * better than refusing to install over it.
 */
function ask_secret(string $label): string
{
    $hidden = @shell_exec('command -v stty') !== null && @shell_exec('command -v stty') !== '';
    if (!$hidden) {
        fwrite(STDOUT, "  (stty is missing, so this will be visible as you type)\n");
    }

    while (true) {
        fwrite(STDOUT, '  ' . $label . ': ');
        if ($hidden) { @shell_exec('stty -echo'); }
        $first = trim((string) fgets(STDIN));
        if ($hidden) { @shell_exec('stty echo'); fwrite(STDOUT, "\n"); }

        if (mb_strlen($first) < 10) {
            fwrite(STDOUT, "    At least ten characters.\n");
            continue;
        }

        fwrite(STDOUT, '  ' . $label . ' again: ');
        if ($hidden) { @shell_exec('stty -echo'); }
        $again = trim((string) fgets(STDIN));
        if ($hidden) { @shell_exec('stty echo'); fwrite(STDOUT, "\n"); }

        if ($first !== $again) {
            fwrite(STDOUT, "    Those two do not match.\n");
            continue;
        }
        return $first;
    }
}

/**
 * The whole questionnaire, seeded with whatever is already known.
 *
 * `$a` starts as the defaults, or as an answer file if one was given - so
 * `--answers half-filled.rsp --interactive` asks about the gaps and offers the
 * rest for confirmation, which is the useful case rather than an edge one.
 */
function ask_everything(array $a): array
{
    if (!stream_isatty(STDIN)) {
        stop('--interactive needs a terminal to ask questions at. '
           . 'Use --answers with a file when there is not one.');
    }

    note('Nothing is written until the end, and it says what it will do first.');

    fwrite(STDOUT, "Database\n");
    $a['db']['host'] = ask('Host', (string) $a['db']['host']);
    $a['db']['port'] = (int) ask('Port', (string) $a['db']['port'],
        fn($v) => preg_match('/^\d+$/', $v) && (int) $v > 0 && (int) $v < 65536
            ? null : 'A port number.');
    $a['db']['name'] = ask('Database', (string) $a['db']['name'],
        fn($v) => $v === '' ? 'Required.' : null);
    $a['db']['user'] = ask('User', (string) $a['db']['user'],
        fn($v) => $v === '' ? 'Required.' : null);
    // Once, and not confirmed: this one already exists and is being repeated,
    // not chosen, so a typo shows up immediately as a refused connection.
    fwrite(STDOUT, '  Password: ');
    @shell_exec('stty -echo');
    $a['db']['pass'] = trim((string) fgets(STDIN));
    @shell_exec('stty echo');
    fwrite(STDOUT, "\n");

    fwrite(STDOUT, "\nWhat to do with it\n");
    $a['install']['deploy'] = ask_choice('Deploy', ['install', 'erase', 'keep'],
                                         (string) $a['install']['deploy']);
    if ($a['install']['deploy'] === 'erase') {
        // Asked outright, because this is the answer that destroys something.
        $a['install']['force_erase'] = ask_yes('Erase really destroys the collection - continue', false);
        if (!$a['install']['force_erase']) {
            stop('Nothing was changed.');
        }
        $a['install']['erase_uploads'] = ask_yes('Delete the uploaded photographs too', false);
    }

    if ($a['install']['deploy'] !== 'keep') {
        fwrite(STDOUT, "\nAdministrator\n");
        $a['admin']['username'] = ask('Username', (string) $a['admin']['username'],
            fn($v) => preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $v)
                ? null : '3-60 characters: letters, digits, dot, dash, underscore.');
        $a['admin']['password'] = ask_secret('Password');
        $a['admin']['email'] = ask('Email', (string) $a['admin']['email'],
            fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'An address.');
        $a['admin']['display_name'] = ask('Display name', (string) ($a['admin']['display_name']
            ?: $a['admin']['username']));
    }

    fwrite(STDOUT, "\nThis instance\n");
    $a['instance']['name'] = ask('Name', (string) $a['instance']['name']);
    $a['instance']['url'] = ask('Public address', (string) $a['instance']['url'],
        fn($v) => $v === '' || filter_var($v, FILTER_VALIDATE_URL)
            ? null : 'An address, or nothing.');
    $a['instance']['timezone'] = ask('Timezone', (string) $a['instance']['timezone'],
        fn($v) => in_array($v, timezone_identifiers_list(), true)
            ? null : 'Not a timezone PHP knows.');
    $a['instance']['currency'] = ask('Currency', (string) $a['instance']['currency']);

    fwrite(STDOUT, "\nStructure data\n");
    $a['install']['structure'] = ask_choice('Structure data', ['remote', 'shipped', 'none'],
                                            (string) $a['install']['structure']);
    $a['install']['examples'] = ask_yes('Add a few example entries',
                                        (bool) $a['install']['examples']);

    // Only when it matters. As a non-root user the files come out owned by
    // whoever is running this, which is usually already right, and a question
    // about it would be a question with no wrong answer.
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        fwrite(STDOUT, "\nWeb server\n");
        [$guessUser, $guessGroup] = web_server_account();
        $a['server']['web_user'] = ask('Runs as user', (string) ($a['server']['web_user']
            ?: ($guessUser ?? '')));
        $a['server']['web_group'] = ask('and group', (string) ($a['server']['web_group']
            ?: ($guessGroup ?? $a['server']['web_user'])));
    }

    fwrite(STDOUT, "\nAfterwards\n");
    $a['install']['delete_installer'] = ask_yes('Delete public/install.php when done',
                                                (bool) $a['install']['delete_installer']);

    return $a;
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$answersPath = '';
$dryRun = false;
$force  = false;
$interactive = false;
$savePath = '';

for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--example':
            fwrite(STDOUT, answers_export(answers_defaults()) . "\n");
            exit(0);
        case '--answers':
        case '-a':
            $answersPath = (string) ($argv[++$i] ?? '');
            break;
        case '--dry-run':
        case '-n':
            $dryRun = true;
            break;
        // Overwrites an existing src/config.local.php. Separate from
        // deploy: erase on purpose - one destroys a configuration, the other
        // destroys a collection, and conflating them is how the wrong one
        // happens.
        case '--force':
        case '-f':
            $force = true;
            break;
        case '--quiet':
        case '-q':
            $QUIET = true;
            break;
        // Asks instead of reading. Combined with --answers it asks about the
        // gaps and offers the rest for confirmation, which is the useful case.
        case '--interactive':
        case '-i':
            $interactive = true;
            break;
        // Writes the answers out, so a run done by hand can install the next
        // machine without one. Credentials come out as placeholders, the same
        // as the file the wizard hands you.
        case '--save-answers':
            $savePath = (string) ($argv[++$i] ?? '');
            break;
        case '--help':
        case '-h':
            fwrite(STDOUT, <<<TXT

RetroHive installer.

  php bin/install.php --interactive
  php bin/install.php --example > install.rsp
  php bin/install.php --answers install.rsp [--dry-run] [--force]

  --interactive   ask the questions instead of reading a file
  --example       print an answer file to start from
  --answers       the answer file to install from, or - for standard input
  --save-answers  write the answers out afterwards, credentials left blank
  --dry-run       check everything and touch nothing
  --force         overwrite an existing src/config.local.php
  --quiet         say nothing unless something goes wrong

Without a file at all:

  php bin/install.php --interactive
  php bin/install.php --interactive --save-answers install.rsp

--answers and --interactive combine: the file fills in what it knows and the
questions cover the rest.

Silent, for a provisioning run:

  RETROVAULT_DB_PASS=... RETROVAULT_ADMIN_PASS=... \
    php bin/install.php --answers install.rsp --quiet || handle-failure

RETROVAULT_DB_PASS and RETROVAULT_ADMIN_PASS override db.pass and
admin.password, so the answer file can be templated and hold no secret. Or pipe
the whole thing in and leave nothing on disk:

  vault read -field=answers secret/retrohive | php bin/install.php --answers -

The answer file holds passwords unless the environment supplies them. chmod 600
it, and delete it afterwards.


TXT);
            exit(0);
        default:
            stop('Unknown argument "' . $argv[$i] . '". Try --help.');
    }
}

if ($answersPath === '' && !$interactive) {
    stop('Nothing to install from. Use --interactive to be asked, '
       . 'or --example then --answers to install from a file.');
}

$a = $answersPath === '' ? answers_defaults() : read_answers($answersPath);
if ($interactive) {
    $QUIET = false;   // it is a conversation; it cannot be silent
    $a = ask_everything($a);
}
if (($bad = answers_check($a)) !== []) {
    refuse($bad, $interactive ? 'the answers given' : ($answersPath === '-' ? 'standard input' : $answersPath));
}

// ---------------------------------------------------------------------------
// 1. Is this machine capable, and is it already installed
// ---------------------------------------------------------------------------

note($dryRun ? 'Checking, and changing nothing.' : 'Installing RetroHive.');

$blocking = [];
foreach (requirements() as $r) {
    if (($r['ok'] ?? true) === false && ($r['fatal'] ?? true)) {
        $blocking[] = (string) $r['label'] . ': ' . (string) ($r['detail'] ?? 'missing');
    }
}
if ($blocking !== []) {
    fwrite(STDERR, "\nThis server cannot run RetroHive yet:\n");
    foreach ($blocking as $line) { fwrite(STDERR, '  - ' . $line . "\n"); }
    fwrite(STDERR, "\n");
    exit(1);
}
say('Server requirements met');

if (config_exists() && !$force) {
    stop(pretty_path(CONFIG_FILE) . ' already exists. Use --force to overwrite it,'
       . ' after deciding whether the instance it describes is still wanted.');
}

// ---------------------------------------------------------------------------
// 2. The database
// ---------------------------------------------------------------------------

try {
    $pdo = pdo_connect((string) $a['db']['host'], (int) $a['db']['port'],
                       (string) $a['db']['name'], (string) $a['db']['user'],
                       (string) $a['db']['pass']);
} catch (Throwable $e) {
    stop('Could not connect to the database: ' . $e->getMessage());
}
say('Connected to ' . $a['db']['name'] . ' on ' . $a['db']['host']);

$counts = existing_data_counts($pdo);
if ($a['install']['deploy'] === 'install' && has_real_data($counts)) {
    stop('That database already holds a collection. Use deploy => "erase" to destroy it,'
       . ' or deploy => "keep" to leave it alone.');
}
// An erase that has not said it means it.
//
// The same rule the wizard applies, and for the same reason: an answer file gets
// copied between machines, and the collection it destroys is whichever database
// it happens to name that day. `deploy = erase` says what to do; `force_erase`
// is the second sentence that says it was meant.
if ($a['install']['deploy'] === 'erase' && !$a['install']['force_erase']) {
    stop('deploy is "erase", which destroys the collection in ' . $a['db']['name']
       . '. Set force_erase = 1 in the [install] section to confirm that.');
}

if ($a['install']['deploy'] === 'erase' && has_real_data($counts)) {
    say('Will erase: ' . implode(', ', array_map(
        fn($v) => counted((int) $v['n'], (string) $v['one'], (string) $v['many']),
        array_values($counts))));
}

if ($dryRun) {
    note('The answers are good and the database is reachable. Nothing was written.');
    exit(0);
}

if ($a['install']['deploy'] === 'erase') {
    drop_retrovault_tables($pdo);
    say('Existing structure dropped');
    if ($a['install']['erase_uploads']) {
        $purge = purge_uploads();
        say(sprintf('Uploads: %d of %d files deleted',
                    (int) ($purge['removed'] ?? 0), (int) ($purge['seen'] ?? 0)));
    }
}

if ($a['install']['deploy'] === 'install' || $a['install']['deploy'] === 'erase') {
    [$errs, $msgs] = run_sql_file($pdo, SCHEMA_FILE);
    if ($errs > 0) {
        stop('The schema did not load: ' . implode(' | ', $msgs));
    }
    say('Structure created');
    say(counted(installer_baseline($pdo), 'migration recorded', 'migrations recorded'));
    // Always, whatever was chosen about structure data: db/seed.sql is the auth
    // methods and platform classes the software cannot run without. Skipping it
    // leaves an instance nobody can sign in to, which is not what "start empty"
    // means.
    run_sql_file($pdo, SEED_FILE);
    say('Core records created');
} else {
    say('Database left untouched');
}

// ---------------------------------------------------------------------------
// 3. The configuration
// ---------------------------------------------------------------------------

$config = config_php([
    'written_at'      => date('j M Y, H:i'),
    'app_name'        => (string) $a['instance']['name'],
    'app_tagline'     => (string) $a['instance']['tagline'],
    'currency'        => (string) $a['instance']['currency'],
    'timezone'        => (string) $a['instance']['timezone'],
    'base_url'        => (string) $a['instance']['url'],
    'trusted_proxies' => (string) $a['instance']['trusted_proxies'],
    'debug'           => (bool) ($a['instance']['debug'] ?? false),
    'debug_status'    => (bool) ($a['instance']['debug_status'] ?? false),
    'db_host'         => (string) $a['db']['host'],
    'db_port'         => (int) $a['db']['port'],
    'db_name'         => (string) $a['db']['name'],
    'db_user'         => (string) $a['db']['user'],
    'db_pass'         => (string) $a['db']['pass'],
]);

if (@file_put_contents(CONFIG_FILE, $config) === false) {
    stop('Could not write ' . pretty_path(CONFIG_FILE) . '. Check who owns src/.');
}
@chmod(CONFIG_FILE, 0640);
say('Configuration written to ' . pretty_path(CONFIG_FILE));

// Ownership, because root owns what root writes.
//
// The wizard never needs this: it runs as the web server, so the file it writes
// is already the web server's. Run from a shell as root, the same file is
// root:root at 0640 - which the web server cannot read, and the symptom is a 503
// with nothing in any log, because the application never got far enough to write
// one. That happened, and this is the fix.
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    [$webUser, $webGroup] = web_server_account((string) $a['server']['web_user'],
                                               (string) $a['server']['web_group']);
    if ($webUser === null) {
        say('WARNING running as root and no web server account was found. '
          . pretty_path(CONFIG_FILE) . ' is owned by root and the server will not '
          . 'read it - set web_user in the [server] section, or chown it by hand');
    } else {
        $group = $webGroup ?? $webUser;
        if (@chown(CONFIG_FILE, $webUser) && @chgrp(CONFIG_FILE, $group)) {
            say('Configuration owned by ' . $webUser . ':' . $group);
        } else {
            say('WARNING could not change the owner of ' . pretty_path(CONFIG_FILE)
              . ' - the web server will not be able to read it');
        }

        // The one directory the application writes to. Everything else is fine
        // owned by root and readable, which is what docs/INSTALL.md says.
        $uploads = APP_DIR . '/public/uploads';
        if (is_dir($uploads)) {
            $ok = @chown($uploads, $webUser) && @chgrp($uploads, $group);
            @chmod($uploads, 0775);
            foreach ((array) @scandir($uploads) as $entry) {
                if ($entry === '.' || $entry === '..') { continue; }
                @chown($uploads . '/' . $entry, $webUser);
                @chgrp($uploads . '/' . $entry, $group);
            }
            say($ok ? 'Uploads directory owned by ' . $webUser . ':' . $group
                    : 'WARNING could not change the owner of ' . pretty_path($uploads));
        }
    }
}

// ---------------------------------------------------------------------------
// 4. The application, which can boot now
// ---------------------------------------------------------------------------

foreach (['helpers', 'proxy', 'db', 'auth', 'throttle', 'acl', 'log', 'ldap',
          'metadata', 'version', 'migrate', 'images', 'models', 'notify',
          'registration', 'structure'] as $unit) {
    require APP_ROOT . '/src/' . $unit . '.php';
}

if ($a['install']['deploy'] !== 'keep') {
    // create_user(), not an insert: the users table has required columns this
    // script has no business knowing about, and a hand-rolled row fails quietly.
    $uid = create_user((string) $a['admin']['username'], (string) $a['admin']['password'],
                       (string) ($a['admin']['display_name'] ?: $a['admin']['username']),
                       'admin', (string) $a['admin']['email']);
    if (!$uid) {
        stop('The administrator account could not be created.');
    }
    $user = one('SELECT * FROM users WHERE id = ?', [(int) $uid]);
    set_acting_user($user);
    say('Administrator ' . $a['admin']['username'] . ' created');
} else {
    $user = one('SELECT * FROM users WHERE role = "admin" AND is_active = 1 ORDER BY id LIMIT 1');
    if ($user === null) {
        stop('deploy is "keep" but there is no administrator in that database.');
    }
    set_acting_user($user);
}

// The name and address again, in the settings table this time. The file is what
// the software reads at boot; these are what the screens show and what mail is
// built from, and an instance with one and not the other is half configured.
set_setting('instance_name', (string) $a['instance']['name']);
if (trim((string) $a['instance']['url']) !== '') {
    set_setting('site_url', rtrim((string) $a['instance']['url'], '/'));
}

// ---------------------------------------------------------------------------
// 5. Structure data and the first library
// ---------------------------------------------------------------------------

if ($a['install']['structure'] !== 'none') {
    [$summary, $errors] = structure_sync($a['install']['structure'] === 'remote');
    foreach (array_slice($errors, 0, 3) as $problem) {
        say('WARNING ' . $problem);
    }
    say(sprintf('Structure data fetched from %s: %d added',
                $a['install']['structure'] === 'remote' ? 'the published copies' : 'this checkout',
                array_sum(array_column($summary, 'added'))));
    // The counts go into the log by way of structure_sync(), so an install leaves
    // the same trail a later sync does and the settings screen has something to
    // compare against from the first day.
}

// The lookup sources that need no account.
//
// The wizard has always done this and the command line installer never did, so
// an instance built from a response file came up with nothing to look titles up
// with, and no sign that it was meant to have any.
if ($a['install']['metadata_sources']) {
    $sources = installer_enable_metadata_sources();
    say($sources['added'] > 0
        ? sprintf('Metadata sources switched on: %d, the ones needing no key that answered',
                  $sources['added'])
        : 'Metadata sources already configured, or none answered');

    // Named here as well as logged. An unattended install will not have anybody
    // reading this, which is why it goes to the log too - but somebody watching
    // should not have to go and look.
    foreach ($sources['skipped'] as $label => $why) {
        say(sprintf('  skipped %s - it did not answer its own probe: %s', $label, $why));
    }
}

if ($a['install']['deploy'] !== 'keep') {
    $libId = (int) ensure_first_library((int) $user['id']);
    say('First library created');

    // Deliberately not seeded here. This library is the one shelf a
    // person is promised as their own - ensure_first_library()'s own
    // comment already says a personal library starts empty, and this
    // call used to disagree with it, copying hardware structure into
    // it directly whenever structure sync was on, structure this
    // account never asked for on their own shelf. seed_shared_example_
    // library() below already carries the same structure and the
    // examples both, into a library that plainly says "this is an
    // example, edit or delete freely" - which is where they belong.
    if ($a['install']['structure'] !== 'none' && $a['install']['examples']) {
        // A second, shared library, not the personal one - the same
        // move the web installer already makes: somebody's own shelf
        // should be waiting for their own collection, not pre-filled
        // with entries that are not theirs.
        $sharedId = seed_shared_example_library((int) $user['id']);
        if ($sharedId > 0) {
            say(sprintf('Shared example library created with %d entries of its own',
                (int) scalar('SELECT COUNT(*) FROM items WHERE library_id = ?', [$sharedId])));
        }
    }
}

if ($savePath !== '') {
    // Through answers_export(), so what comes out is what the wizard hands you:
    // every credential a placeholder, everything else as it was answered.
    if (@file_put_contents($savePath, answers_export($a)) === false) {
        say('WARNING could not write ' . $savePath);
    } else {
        @chmod($savePath, 0600);
        say('Answers written to ' . $savePath);
    }
}

// sign_in is ignored here on purpose: there is no browser at a shell prompt to
// be signed in to anything, and pretending otherwise would mean writing a
// session file nobody holds a cookie for.
if ($a['install']['delete_installer']) {
    $wizard = APP_DIR . '/public/install.php';
    if (!is_file($wizard)) {
        say('Installer already gone');
    } elseif (@unlink($wizard)) {
        say('Installer deleted');
    } else {
        say('WARNING could not delete ' . pretty_path($wizard) . ' - remove it by hand');
    }
    note('Done. Delete the answer file.');
} elseif (is_file(APP_DIR . '/public/install.php')) {
    note('Done. Delete ' . pretty_path(APP_DIR . '/public/install.php') . ' and the answer file.');
} else {
    // Already gone, either from a previous run or by hand. Telling somebody to
    // delete a file that is not there is how they start looking for it.
    note('Done. Delete the answer file.');
}
exit(0);
