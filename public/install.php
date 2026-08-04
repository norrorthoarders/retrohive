<?php
declare(strict_types=1);

/**
 * RetroHive web installer.
 *
 * Deliberately standalone: it does not boot the application, because the whole
 * point is that the application cannot boot yet. It requires nothing but PDO,
 * so it still runs on a server that is missing half the extensions - and can
 * therefore tell you which ones.
 *
 * DELETE THIS FILE once the install is finished. It refuses to run against a
 * configured install with accounts in it, but a file that cannot run is still
 * better removed than left lying in a document root.
 */

// Not on the command line. bin/install.php includes this file for the helpers
// below - pdo_connect(), run_sql_file(), config_php(), the requirements check -
// and wants none of the wizard. A session there would be a file in /tmp nobody
// reads, and the early return further down stops before any of the wizard runs.
// Every function in this file is still defined either way: PHP hoists
// unconditional top-level declarations when the file is compiled, not when the
// return is reached.
if (PHP_SAPI !== 'cli') {
    session_start();
}
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../src/installer.php';





// --- Small helpers ----------------------------------------------------------

function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/**
 * Which steps can be jumped to.
 *
 * Nothing before the last one changes anything, so going back to correct a
 * decision is free. A step opens as soon as the one before it has been
 * answered - not merely visited, which would let somebody skip past a choice
 * and reach the apply step with nothing decided.
 */
/**
 * The stages the install page shows, in the order they happen.
 *
 * Named here so the page and the code that ticks them cannot drift apart.
 */
function install_stages(): array
{
    return [
        'start'   => 'Connecting to the database',
        'db'      => 'Preparing the database',
        'schema'  => 'Building the structure',
        'config'  => 'Writing the configuration',
        'starter' => 'Fetching the structure data',
        'admin'   => 'Creating the administrator and first library',
        'done'    => 'Finishing',
    ];
}

/** The progress list, sent before any work starts. */
function install_progress_shell(): string
{
    $out = '<div class="installrun" id="installrun"><p class="label" style="margin:0 0 .6rem">Installing</p><ul class="installrun__steps">';
    foreach (install_stages() as $key => $label) {
        $out .= '<li id="stage-' . h($key) . '">' . h($label) . '</li>';
    }
    $out .= '</ul><p class="hint" style="margin:.6rem 0 0">Do not reload the page.</p></div>';

    // Stages are queued and shown at a readable pace, rather than the server sleeping
    // between them. Half the work finishes in milliseconds - the database, the schema,
    // the config file - so without this those four flash past and the whole thing
    // looks like one long pause on "Fetching the structure data".
    //
    // The delay is in the display only. The install runs at full speed and the queue
    // drains alongside it, so this costs nothing except at the very end, where it
    // waits for the last stage to have been seen before clearing.
    $out .= '<script>
(function () {
  var q = [], busy = false, DWELL = 420, prev = null, drained = null;
  function apply(t) {
    if (prev) { var p = document.getElementById("stage-" + prev); if (p) { p.className = "is-done"; } }
    var e = document.getElementById("stage-" + t.key);
    if (e) {
      e.className = "is-now";
      if (t.note) { e.innerHTML += \' <span class="hint">\' + t.note + "</span>"; }
    }
    prev = t.key;
  }
  function drain() {
    if (!q.length) { busy = false; if (drained) { drained(); } return; }
    busy = true;
    apply(q.shift());
    setTimeout(drain, DWELL);
  }
  window.__rvTick = function (key, note) { q.push({ key: key, note: note }); if (!busy) { drain(); } };
  // Called once the server has finished: mark the last stage done and clear the panel,
  // but only after everything queued has actually been on screen.
  window.__rvDone = function () {
    drained = function () {
      if (prev) { var p = document.getElementById("stage-" + prev); if (p) { p.className = "is-done"; } }
      setTimeout(function () {
        var r = document.getElementById("installrun");
        if (r) { r.remove(); }
        var res = document.getElementById("installresult");
        if (res) { res.removeAttribute("hidden"); }
      }, DWELL);
    };
    if (!busy) { drained(); }
  };
})();
</script>';

    // Padding, because some proxies will not forward anything until they have a few
    // kilobytes. Costs nothing and is the difference between a live page and a blank
    // one on a default nginx.
    return $out . '<!--' . str_repeat(' ', 4096) . '-->';
}

/**
 * Mark a stage as running, and the one before it as done.
 *
 * Real progress: each call happens when that part of the work actually begins, and is
 * flushed immediately. Nothing here is on a timer.
 */
function install_tick(string $key, ?string $note = null): void
{
    // Enqueued rather than applied: the page decides how long each stage is visible,
    // so the server never waits on the display.
    echo '<script>window.__rvTick&&__rvTick("' . h($key) . '"'
       . ($note === null ? '' : ',"' . h($note) . '"') . ');</script>' . "\n";
    if (function_exists('ob_flush')) { @ob_flush(); }
    @flush();
}

function step_reachable(int $n): bool
{
    // Installing is a one-way door, and the stepper should say so from both sides.
    //
    // Before it starts, step 7 is not a place you can go: it is the act itself, and
    // the only way in is the button on Review. Once it has started, nothing before it
    // is reachable either - the tables are already going, so a link back to
    // "Deployment" would offer to change a decision that has been acted on.
    $started = (bool) recall('installing')
            || ((bool) recall('applied') && config_exists());
    if ($started) {
        return $n === 7;
    }

    switch ($n) {
        case 1: return true;
        case 2: return true;
        case 3: return (bool) recall('db_reached');
        case 4: return recall('deploy_action') !== null;
        case 5: return (bool) recall('settings_set');
        case 6: return (bool) recall('admin_set');
    }
    return false;
}

/** Everything the wizard has been told, ready for the apply step. */
function plan(): array
{
    return [
        'db'     => [
            'host' => (string) recall('db_host', ''), 'port' => (string) recall('db_port', '3306'),
            'name' => (string) recall('db_name', ''), 'user' => (string) recall('db_user', ''),
            'pass' => (string) recall('db_pass', ''),
        ],
        'deploy' => (string) recall('deploy_action', ''),
        'uploads_too' => (bool) recall('erase_uploads', false),
        'admin'  => (string) recall('admin_username', ''),
        'email'  => (string) recall('admin_email', ''),
        // What to do about structure data: remote, local or none.
        //
        // This was missing, so $plan['structure'] was always unset and the install
        // phase fell back to 'remote' - which meant choosing "none" on the settings
        // step loaded the templates anyway and then copied them into the library.
        // The setting was recorded, read back on the settings page, and never once
        // consulted by the thing it was supposed to control.
        'structure' => (string) recall('structure', 'remote'),
        'examples'  => (string) recall('examples', '0') === '1',
    ];
}

function step(): int
{
    // Seven: requirements, connection, deployment, settings, administrator,
    // review, install. The ceiling has to match, or the last button silently
    // redraws the previous page - which is exactly what a clamp of six did to
    // step 7 when it was added.
    return max(1, min(7, (int) ($_GET['step'] ?? $_POST['step'] ?? 1)));
}

function token(): string
{
    if (empty($_SESSION['install_csrf'])) {
        $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['install_csrf'];
}

function check_token(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!hash_equals(token(), (string) ($_POST['_csrf'] ?? ''))) {
        http_response_code(419);
        exit('Session expired. Reload the installer and start again.');
    }
}

/** Saved answers survive between steps without touching disk. */
function remember(array $values): void
{
    $_SESSION['install'] = array_merge($_SESSION['install'] ?? [], $values);
}

function recall(string $key, $default = null)
{
    return $_SESSION['install'][$key] ?? $default;
}

// --- Environment inspection -------------------------------------------------













// --- Database ---------------------------------------------------------------


























// --- Migrations, without booting the application ---------------------------
// The installer is standalone by design, so it carries its own small version of
// what src/migrate.php does rather than requiring the app to be configured.















// --- Installed-state detection ---------------------------------------------



/**
 * The wizard writes the configuration at step 3 and would otherwise lock itself
 * out before step 4. This flag, set only when the installer legitimately
 * started with no config present, lets that one session finish.
 */
function wizard_is_active(): bool
{
    return (bool) recall('wizard_active');
}

/** The settings the wizard collected, in the shape config_php() expects. */
function config_values(): array
{
    return [
        'app_name'        => (string) recall('app_name', 'RetroHive'),
        'currency'        => (string) recall('currency', 'SEK'),
        'timezone'        => (string) recall('timezone', 'Europe/Stockholm'),
        'base_url'        => (string) recall('base_url', ''),
        'trusted_proxies' => (string) recall('trusted_proxies', ''),
        'db_host'         => (string) recall('db_host', '127.0.0.1'),
        'db_port'         => (string) recall('db_port', '3306'),
        'db_name'         => (string) recall('db_name', 'retrohive'),
        'db_user'         => (string) recall('db_user', 'retrohive'),
        'db_pass'         => (string) recall('db_pass', ''),
    ];
}



/** Rebuild the configuration text from the answers collected so far. */
function config_from_session(): string
{
    return config_php([
        'app_name'        => (string) recall('app_name', 'RetroHive'),
        'app_tagline'     => (string) recall('app_tagline', 'Retro software collection'),
        'currency'        => (string) recall('currency', 'SEK'),
        'timezone'        => (string) recall('timezone', 'Europe/Stockholm'),
        'base_url'        => (string) recall('base_url', ''),
        'trusted_proxies' => (string) recall('trusted_proxies', ''),
        // remote | local | none
        'structure'       => (string) recall('structure', 'remote'),
        // Separate from the templates choice: reference data and examples are two
        // decisions, and bundling them meant wanting one forced the other.
        'examples'        => (string) recall('examples', '0'),
        'db_host'         => (string) recall('db_host', ''),
        'db_port'         => (int) recall('db_port', 3306),
        'db_name'         => (string) recall('db_name', ''),
        'db_user'         => (string) recall('db_user', ''),
        'db_pass'         => (string) recall('db_pass', ''),
        'written_at'      => date('Y-m-d H:i'),
    ]);
}

// --- Page shell -------------------------------------------------------------

function head(string $title): void
{
    $step = step();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<title><?= h($title) ?> · RetroHive installer</title>
<link rel="stylesheet" href="assets/css/app.css?v=<?= h((string) @filemtime(__DIR__ . '/assets/css/app.css')) ?>">
<style>
    /* A statement of fact above the checks: not an error, and not something to
       dismiss. Colour carries the severity; the border carries the eye. */
    .note {
      border-left: 3px solid #45475a;
      background: #181825;
      border-radius: 10px;
      padding: .8rem 1rem;
      margin: 0 0 1.25rem;
      font-size: .92rem;
      line-height: 1.55;
    }
    .note--warn { border-left-color: #f9e2af; }
    .note--ok   { border-left-color: #a6e3a1; }

  .wiz { max-width: 860px; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
  .steps { display: flex; gap: .4rem; flex-wrap: wrap; margin-bottom: 2rem; list-style: none; padding: 0; }
  .steps li a { color: inherit; text-decoration: none; }
  .steps li a:hover { text-decoration: underline; }
  .steps li { font-family: var(--mono); font-size: .72rem; letter-spacing: .1em; text-transform: uppercase;
              color: var(--faint); border: 1px solid var(--line); border-radius: 100px; padding: .25rem .7rem; }
  .steps li.on { background: var(--accent); border-color: var(--accent); color: var(--crust); font-weight: 650; }
  .steps li.done { border-color: var(--good); color: var(--good); }
  .req { width: 100%; border-collapse: collapse; font-size: .9rem; }
  .req td { padding: .5rem .6rem; border-bottom: 1px solid var(--line); vertical-align: top; }
  .req tr:last-child td { border-bottom: 0; }
  .req .state { width: 1%; white-space: nowrap; font-family: var(--mono); font-size: .78rem; }
  .yes { color: var(--good); } .no { color: var(--bad); } .warn { color: var(--warn); }
  .fix { color: var(--faint); font-size: .82rem; margin-top: .2rem; }
  pre.cfg { background: var(--crust); border: 1px solid var(--line); border-radius: var(--r);
            padding: .9rem; overflow: auto; font-size: .78rem; line-height: 1.5; max-height: 380px; }
</style>
<style>
  .installrun { margin-top: 1rem; padding: .9rem 1rem; border: 1px solid var(--line);
                border-radius: 10px; background: var(--panel); }
  .installrun__steps { list-style: none; margin: 0; padding: 0; font-size: .9rem; }
  .installrun__steps li { padding: .18rem 0; color: var(--dim); }
  .installrun__steps li::before { content: '\00b7  '; }
  .installrun__steps li.is-now  { color: var(--text); }
  .installrun__steps li.is-now::before  { content: '\2192  '; }
  .installrun__steps li.is-done { color: var(--ok, #a6e3a1); }
  .installrun__steps li.is-done::before { content: '\2713  '; }
</style>
</head>
<body>
<main class="wiz">
  <div style="display:flex;align-items:center;gap:.55rem;margin-bottom:.35rem">
    <span style="display:flex;gap:2px">
      <i style="width:4px;height:20px;border-radius:1px;background:var(--bad)"></i>
      <i style="width:4px;height:20px;border-radius:1px;background:var(--good)"></i>
      <i style="width:4px;height:20px;border-radius:1px;background:#89b4fa"></i>
    </span>
    <strong style="letter-spacing:-.03em">RetroHive installer</strong>
  </div>

  <ul class="steps">
    <?php foreach (['Requirements', 'Connection', 'Deployment', 'Settings', 'Administrator', 'Review', 'Install'] as $i => $label): ?>
      <?php $n = $i + 1; $cls = $step === $n ? 'on' : ($step > $n ? 'done' : ''); ?>
      <li class="<?= $cls ?>">
        <?php if (step_reachable($n) && $n !== $step): ?>
          <a href="?step=<?= $n ?>"><?= $n ?>. <?= h($label) ?></a>
        <?php else: ?>
          <?= $n ?>. <?= h($label) ?>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php
}

function foot(): void
{
    echo '</main></body></html>';
}

function flash_error(string $msg): void
{
    echo '<div class="flash flash--error" style="margin-bottom:1rem">' . h($msg) . '</div>';
}

function flash_ok(string $msg): void
{
    echo '<div class="flash flash--ok" style="margin-bottom:1rem">' . h($msg) . '</div>';
}


// ============================================================================
// The answer file
//
// One definition, used by both installers: this file writes it at the end of a
// wizard run, reads it at the start of one, and bin/install.php installs from it
// without asking anything.
//
// INI rather than PHP. The command line installer alone could have read a PHP
// file - it already runs whatever is in the checkout - but the wizard accepts
// one by upload, and `require` on an uploaded file is remote code execution
// wearing a hat. parse_ini_string() executes nothing, takes comments, and is
// what a sysadmin expects a preseed file to look like anyway.
//
// Credentials are never written. They come out as the placeholders below, and
// an answer file still carrying one is refused rather than installed with a
// database user literally called change-database-user-here.
// ============================================================================











/** The answers this wizard run collected, in the answer file's shape. */
function answers_from_session(): array
{
    return [
        'db' => [
            'host' => (string) recall('db_host', '127.0.0.1'),
            'port' => (int) recall('db_port', 3306),
            'name' => (string) recall('db_name', ''),
            // Never read back into the file, but the shape wants them.
            'user' => '', 'pass' => '',
        ],
        'admin' => [
            'username' => '', 'password' => '',
            'email'        => (string) recall('admin_email', ''),
            'display_name' => (string) recall('admin_display_name', ''),
        ],
        'instance' => [
            'name'            => (string) recall('app_name', 'RetroHive'),
            'tagline'         => (string) recall('app_tagline', ''),
            'url'             => (string) recall('base_url', ''),
            'currency'        => (string) recall('currency', 'SEK'),
            'timezone'        => (string) recall('timezone', 'Europe/Stockholm'),
            'trusted_proxies' => (string) recall('trusted_proxies', ''),
        ],
        'install' => [
            'deploy'        => (string) recall('deploy_action', 'install'),
            'erase_uploads' => (bool) recall('erase_uploads', false),
            'structure'     => (string) recall('structure', 'remote'),
            'examples'      => (string) recall('examples', '0') === '1',
        ],
    ];
}

// Everything above is a function. Everything below is the wizard, and the
// command line wants none of it.
if (PHP_SAPI === 'cli') {
    return;
}

// ============================================================================
// Download the generated configuration
//
// Offered whenever src/ is not writable, and again at the end so the file can
// be kept somewhere safe. Requires answers in the session, so a fresh browser
// cannot pull someone else's database password out of it.
// ============================================================================

// The answers, so the next machine does not need the wizard at all.
//
// Offered at the end, where somebody has just answered all of it once and is in
// the best position to know they will be answering it again. Credentials are
// left as placeholders by answers_export(); nothing secret is in the session by
// then anyway except the database password, and writing it into a file people
// download is how it ends up in a ticket.
if (($_GET['download'] ?? '') === 'answers') {
    if (recall('db_name') === null) {
        http_response_code(404);
        exit('Nothing to write. Work through the installer first.');
    }
    $body = answers_export(answers_from_session());
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="retrohive-install.rsp"');
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}

if (($_GET['download'] ?? '') === 'config') {
    if (recall('db_name') === null || recall('db_host') === null) {
        http_response_code(404);
        exit('Nothing to download. Work through the installer first.');
    }
    $body = config_from_session();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="config.local.php"');
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}

// ============================================================================
// Refuse to run when a configuration already exists
// ============================================================================

if (config_exists() && !wizard_is_active()) {
    head('Already configured');
    ?>
    <h1>RetroHive is already configured</h1>
    <p class="lede">
      <span class="mono"><?= h(pretty_path(CONFIG_FILE)) ?></span> exists, so the installer has
      stopped. It only runs on a system that has not been set up yet.
    </p>

    <div class="panel" style="border-left:4px solid var(--bad)">
      <h2 class="panel__title">Remove this file</h2>
      <p style="margin-top:0">
        An installer sitting in a document root is a liability even when it
        declines to do anything.
      </p>
      <pre class="cfg">rm <?= h(__FILE__) ?></pre>
    </div>

    <div class="panel" style="margin-top:1rem">
      <h2 class="panel__title">If you really do need to run it again</h2>
      <p style="margin-top:0;font-size:.9rem;color:var(--dim)">
        Move the configuration aside and reload this page. Keeping a copy means
        you can put it straight back if you change your mind.
      </p>
      <pre class="cfg">mv <?= h(pretty_path(CONFIG_FILE)) ?> <?= h(pretty_path(CONFIG_FILE)) ?>.bak</pre>
      <p style="font-size:.9rem;color:var(--dim)">
        The wizard will then notice the existing database and offer to keep it —
        which is what you want when moving to a new server — or to erase it.
        Nothing is destroyed without a typed confirmation.
      </p>
    </div>

    <div class="panel" style="margin-top:1rem">
      <h2 class="panel__title">Updating rather than reinstalling</h2>
      <p style="margin-top:0;font-size:.9rem;color:var(--dim)">
        If you have just copied new files over an existing install, you want the
        updater, not this. It applies pending migrations and checks the database
        structure without touching your settings.
      </p>
      <pre class="cfg">php bin/migrate.php status</pre>
      <p><a class="btn" href="update.php">Open the updater</a></p>
    </div>

    <p style="margin-top:1.5rem"><a class="btn btn--accent" href="./">Go to RetroHive</a></p>
    <?php
    foot();
    exit;
}

// Past the gate with no config: this session is doing a legitimate install.
if (!config_exists()) {
    remember(['wizard_active' => true]);
}

check_token();

// ============================================================================
// Steps
// ============================================================================

$step = step();

// The one-way door, enforced rather than merely unlinked.
//
// step_reachable() decides what the stepper offers, but a URL typed by hand does not
// consult it. Once the install has started there is nothing to go back to, so every
// other step redirects to it.
if (($step !== 7)
    && ((bool) recall('installing') || ((bool) recall('applied') && config_exists()))) {
    header('Location: ?step=7');
    exit;
}

// An answer file, offered on the first page.
//
// Presets the whole wizard from a file a previous run wrote, so a second machine
// is one page and a button rather than seven pages of the same answers. The
// credentials are the one thing the file does not carry, so those steps are
// still shown and still have to be filled in - which is the right shape: the
// tedious part is preset, the secret part is asked for.
//
// Parsed, never executed. parse_ini_string() runs nothing, which is the whole
// reason the format is INI and not PHP - `require` on an uploaded file would
// hand anybody who can reach an uninstalled instance a shell.
$presetProblems = [];
$presetOk = false;
if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preset'])) {
    check_token();

    $body = '';
    if (isset($_FILES['answers']) && is_uploaded_file((string) ($_FILES['answers']['tmp_name'] ?? ''))) {
        // Capped: this is a short text file, and an installer is reachable
        // before anything is configured.
        if ((int) $_FILES['answers']['size'] > 64 * 1024) {
            $presetProblems[] = 'That file is far too large to be an answer file.';
        } else {
            $body = (string) file_get_contents((string) $_FILES['answers']['tmp_name']);
        }
    } elseif (trim((string) ($_POST['answers_text'] ?? '')) !== '') {
        $body = (string) $_POST['answers_text'];
    } else {
        $presetProblems[] = 'Choose a file, or paste one in.';
    }

    if ($body !== '' && $presetProblems === []) {
        [$answers, $presetProblems] = answers_parse($body);
        if ($presetProblems === []) {
            remember([
                'db_host'         => (string) $answers['db']['host'],
                'db_port'         => (string) (int) $answers['db']['port'],
                'db_name'         => (string) $answers['db']['name'],
                'app_name'        => (string) $answers['instance']['name'],
                'app_tagline'     => (string) $answers['instance']['tagline'],
                'base_url'        => (string) $answers['instance']['url'],
                'currency'        => (string) $answers['instance']['currency'],
                'timezone'        => (string) $answers['instance']['timezone'],
                'trusted_proxies' => (string) $answers['instance']['trusted_proxies'],
                'deploy_action'   => (string) $answers['install']['deploy'],
                'erase_uploads'   => (bool) $answers['install']['erase_uploads'],
                'structure'       => (string) $answers['install']['structure'],
                'examples'        => $answers['install']['examples'] ? '1' : '0',
                'admin_email'        => (string) $answers['admin']['email'],
                'admin_display_name' => (string) $answers['admin']['display_name'],
                // What to do once the work is finished. Only ever set from a
                // file: somebody walking the wizard by hand has not agreed to
                // have the installer deleted underneath them.
                'delete_installer'   => (bool) $answers['install']['delete_installer'],
                'sign_in'            => (bool) $answers['install']['sign_in'],
                'metadata_sources'   => (bool) $answers['install']['metadata_sources'],
            ]);
            // Credentials only if the file actually carried them - which it does
            // when the environment filled the placeholders in, and does not when
            // somebody downloaded it and has not edited it yet.
            $creds = [];
            $ph = answers_placeholders();
            if ((string) $answers['db']['user'] !== '' && $answers['db']['user'] !== $ph['db.user']) {
                $creds['db_user'] = (string) $answers['db']['user'];
            }
            if ((string) $answers['db']['pass'] !== '' && $answers['db']['pass'] !== $ph['db.pass']) {
                $creds['db_pass'] = (string) $answers['db']['pass'];
            }
            if ((string) $answers['admin']['username'] !== ''
                && $answers['admin']['username'] !== $ph['admin.username']) {
                $creds['admin_username'] = (string) $answers['admin']['username'];
            }
            if ($creds !== []) { remember($creds); }
            $presetOk = true;

            // Complete, so there is nothing left to ask.
            //
            // answers_check() passing means the file carried the database
            // account and the administrator as well as everything else - so the
            // remaining five pages would each be shown filled in for somebody to
            // press past. Skip them.
            $ready = answers_check($answers);
            if ($ready === []) {
                // Reachable, checked here rather than asserted. Setting
                // db_reached on the strength of a file saying so would send
                // somebody to the review page to be told at the last moment
                // that the database was never there.
                try {
                    pdo_connect((string) $answers['db']['host'], (int) $answers['db']['port'],
                                (string) $answers['db']['name'], (string) $answers['db']['user'],
                                (string) $answers['db']['pass']);
                    $reached = true;
                } catch (Throwable $e) {
                    $reached = false;
                    $presetOk = false;
                    $presetProblems[] = 'Could not connect to that database: ' . $e->getMessage();
                }

                if ($reached) {
                    remember([
                        'db_user'      => (string) $answers['db']['user'],
                        'db_pass'      => (string) $answers['db']['pass'],
                        'db_reached'   => true,
                        'settings_set' => true,
                        'admin_username' => (string) $answers['admin']['username'],
                        // Hashed now, so the session never holds a password in
                        // plain text - the same thing step 5 does with the one
                        // it is typed into.
                        'admin_hash'   => password_hash((string) $answers['admin']['password'],
                                                        PASSWORD_DEFAULT),
                        'admin_set'    => true,
                    ]);

                    // Straight to it, unless it is an erase that has not said it
                    // means it.
                    //
                    // `deploy = erase` alone is not enough: a file gets copied
                    // between machines, and the one it destroys is whichever it
                    // was dropped on. `force_erase = 1` is the second sentence
                    // that makes it deliberate, and with it there is no prompt
                    // anywhere - which is the point of a no-prompt file.
                    //
                    // Without it the answers are still loaded, so nothing is
                    // retyped: the review page appears with everything filled in
                    // and the button is theirs to press.
                    $erasing = (string) $answers['install']['deploy'] === 'erase';
                    if (!$erasing || (bool) $answers['install']['force_erase']) {
                        if ((bool) $answers['install']['sign_in']) {
                            // Move to the session the application uses, now,
                            // while a cookie can still be sent.
                            //
                            // The installer runs under PHP's default session
                            // name and the application under "retrohive", so
                            // writing user_id into this one would be writing it
                            // somewhere the application never looks. Switching
                            // sends a Set-Cookie, and the install below streams
                            // its output for a minute or more - by the time it
                            // finishes, headers are long gone. So it happens
                            // here, before a byte is written.
                            $carry = $_SESSION['install'] ?? [];
                            session_write_close();
                            session_name('retrohive');
                            session_start();
                            $_SESSION['install'] = $carry;
                        }
                        $step = 7;
                        $_POST['apply'] = '1';
                    } else {
                        remember(['erase_unconfirmed' => true]);
                        header('Location: ?step=7');
                        exit;
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------------- 1. Checks
if ($step === 1) {
    $checks = requirements();
    $blocking = array_filter($checks, fn($c) => $c['fatal'] && !$c['ok']);

    // The fourth value is why a check failed. Nothing shows it any more - the
    // line says the check failed and stops there - so it is not unpacked, rather
    // than sitting unused and inviting somebody to wonder what it was for.
    [$updState, $updLatest, $updUrl] = installer_update_state();

    head('Requirements');
    ?>
    <h1>Before we start</h1>

    <?php if ($updState === 'behind'): ?>
      <?php
      // One sentence, the same one the settings screen gives.
      //
      // It used to quote what GitHub said and then explain that it did not
      // matter - a paragraph about somebody else's API, on the first page of an
      // installer, saying at length that it was not worth reading. Whether this
      // copy is current is worth a line; why the check failed is not.
      ?>
      <div class="note note--warn">
        Installing <?= h(installer_version()) ?> — outdated,
        <?php if ($updUrl): ?>
          <a href="<?= h($updUrl) ?>" rel="noopener noreferrer external">version
            <?= h($updLatest) ?> available</a>.
        <?php else: ?>
          version <?= h($updLatest) ?> available.
        <?php endif; ?>
      </div>
    <?php elseif ($updState === 'unknown'): ?>
      <div class="note">
        Installing <?= h(installer_version() ?: 'this copy') ?> — could not check
        for a newer version.
      </div>
    <?php else: ?>
      <div class="note note--ok">
        Installing <?= h(installer_version()) ?> — up to date.
      </div>
    <?php endif; ?>
      <?php
      // A file, dropped. Nothing else.
      //
      // No AJAX: the form posts, the server validates with the same code the
      // command line installer uses, and the page comes back in one of three
      // states. A JSON endpoint would have been a second way to reach one
      // answer, and the reload costs nothing on a page that is already a form.
      //
      // The button below is for a browser with no JavaScript, and is hidden by
      // the script the moment there is any - so dropping or choosing a file
      // submits on its own, which is what makes this a drop zone rather than a
      // form with a fancy border.
      ?>
      <form method="post" action="?step=1" enctype="multipart/form-data"
            id="rv-preset" style="margin:1.2rem 0">
        <input type="hidden" name="_csrf" value="<?= h(token()) ?>">
        <input type="hidden" name="preset" value="1">

        <div id="rv-drop" style="
             border:2px dashed <?= $presetOk ? 'var(--ok,#3ba55d)' : ($presetProblems ? 'var(--bad,#e05260)' : 'var(--line,#3a3a4a)') ?>;
             border-radius:10px;padding:1.4rem;text-align:center;cursor:pointer;
             transition:border-color .15s, background .15s">
          <strong style="font-size:.95rem">Response configuration</strong>

          <?php if ($presetOk): ?>
            <div style="margin-top:.5rem;color:var(--ok,#3ba55d);font-size:.9rem">
              Accepted — the pages below are filled in.
            </div>
          <?php elseif ($presetProblems): ?>
            <?php
            // A fumbled second drop does not throw away a file that was already
            // accepted, so the line has to say which of the two happened.
            ?>
            <div style="margin-top:.5rem;color:var(--bad,#e05260);font-size:.9rem">
              <?= recall('db_name') !== null
                  ? 'Not usable — the earlier one still stands.'
                  : 'Not usable. Carry on below, or drop another.' ?>
            </div>
            <ul style="margin:.5rem 0 0;padding:0;list-style:none;
                       color:var(--dim);font-size:.8rem;line-height:1.5">
              <?php foreach (array_slice($presetProblems, 0, 4) as $problem): ?>
                <li><?= h($problem) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <input type="file" name="answers" id="rv-file" accept=".rsp,.ini,text/plain"
                 style="display:block;margin:.7rem auto 0">
          <noscript><button class="btn" type="submit" style="margin-top:.6rem">Use it</button></noscript>
        </div>
      </form>

      <script>
      (function () {
        var form = document.getElementById('rv-preset');
        var zone = document.getElementById('rv-drop');
        var file = document.getElementById('rv-file');
        if (!form || !zone || !file) { return; }

        // The input is only visible without JavaScript. With it, the whole box
        // is the target.
        file.style.display = 'none';

        var idle = zone.style.borderColor;
        function lit(on) { zone.style.borderColor = on ? '#7aa2f7' : idle; }

        zone.addEventListener('click', function () { file.click(); });
        file.addEventListener('change', function () {
          if (file.files.length) { form.submit(); }
        });

        ['dragenter', 'dragover'].forEach(function (e) {
          zone.addEventListener(e, function (ev) { ev.preventDefault(); lit(true); });
        });
        ['dragleave', 'dragend'].forEach(function (e) {
          zone.addEventListener(e, function () { lit(false); });
        });
        zone.addEventListener('drop', function (ev) {
          ev.preventDefault();
          lit(false);
          if (!ev.dataTransfer || !ev.dataTransfer.files.length) { return; }
          // Assigned to the input rather than sent by fetch, so the request is
          // the same multipart post the button makes and the server has one
          // path to handle.
          file.files = ev.dataTransfer.files;
          form.submit();
        });

        // Dropping anywhere else should not make the browser navigate to the
        // file, which is what it does by default and looks like a crash.
        ['dragover', 'drop'].forEach(function (e) {
          document.addEventListener(e, function (ev) {
            if (!zone.contains(ev.target)) { ev.preventDefault(); }
          });
        });
      })();
      </script>

    <div class="panel">
      <table class="req">
        <?php foreach ($checks as $c): ?>
        <tr>
          <td class="state">
            <?php if ($c['ok']): ?><span class="yes">PASS</span>
            <?php elseif ($c['fatal']): ?><span class="no">FAIL</span>
            <?php else: ?><span class="warn">WARN</span><?php endif; ?>
          </td>
          <td>
            <strong><?= h($c['name']) ?></strong>
            <span class="mono" style="color:var(--faint);font-size:.78rem"> — <?= h($c['found']) ?></span>
            <?php if (!$c['ok']): ?><div class="fix"><?= h($c['fix']) ?></div><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <?php if ($blocking): ?>
      <div class="flash flash--error" style="margin-top:1rem">
        Fix the failures above, restart Apache, then reload this page.
      </div>
      <p style="margin-top:1rem"><a class="btn" href="?step=1">Re-check</a></p>
    <?php else: ?>
      <form method="post" action="?step=2" style="margin-top:1.5rem">
        <input type="hidden" name="_csrf" value="<?= h(token()) ?>">
        <button class="btn btn--accent" type="submit">Continue to the database</button>
      </form>

    <?php endif; ?>
    <?php
    foot();
    exit;
}

// -------------------------------------------------------------- 2. Connection
//
// This step proves one thing: that we can reach the database and that it
// exists. Nothing is created and nothing is destroyed here, which is what makes
// it safe to re-run while sorting out a hostname or a grant.
if ($step === 2) {
    $error   = null;
    $summary = [];
    $reached = false;

    $host = post('db_host', (string) recall('db_host', '127.0.0.1'));
    $port = post('db_port', (string) recall('db_port', '3306'));
    $name = post('db_name', (string) recall('db_name', 'retrohive'));
    $user = post('db_user', (string) recall('db_user', 'retrohive'));
    $pass = post('db_pass', (string) recall('db_pass', ''));

    // Only when the button was actually pressed. Arriving here from step 1 is
    // also a POST, and connecting on the way in means a failure is reported
    // before anyone has been given the chance to type anything.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_connection'])) {
        try {
            $pdo = pdo_connect($host, (int) $port, $name, $user, $pass);
            $reached = true;

            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $charset = (string) $pdo->query('SELECT @@character_set_database')->fetchColumn();
            $summary[] = 'Connected to ' . $version;
            $summary[] = 'Database "' . $name . '" exists and is reachable';

            // Deliberately silent about what is already here. This step answers
            // one question - can we reach the database - and the next one is
            // where an existing collection is reported and a choice made about
            // it. Warning twice made the first one look like a problem with the
            // connection.

            if (stripos($charset, 'utf8mb4') === false) {
                $summary[] = 'WARNING: the charset is ' . $charset . ', not utf8mb4. Swedish and '
                           . 'Japanese titles will be mangled. Fix this before going any further.';
            } else {
                $summary[] = 'Character set is utf8mb4';
            }

            // Can this account actually create tables? Better to find out now
            // than halfway through loading the schema.
            try {
                $pdo->exec('CREATE TABLE IF NOT EXISTS _rv_permission_probe (id INT)');
                $pdo->exec('DROP TABLE IF EXISTS _rv_permission_probe');
                $summary[] = 'The account may create and drop tables';
            } catch (PDOException $e) {
                $summary[] = 'WARNING: this account cannot create tables. GRANT ALL ON `'
                           . $name . '`.* is what the next step needs.';
            }

            remember([
                'db_host' => $host, 'db_port' => $port, 'db_name' => $name,
                'db_user' => $user, 'db_pass' => $pass, 'db_reached' => true,
            ]);
        } catch (PDOException $e) {
            $error = $e->getMessage();
            remember(['db_reached' => false]);
        }
    }

    head('Connection');
    ?>
    <h1>Connect to the database</h1>
    <p class="lede">
      This step only checks that the server answers and the database is there.
      Nothing is created or changed, so you can re-run it as often as you like
      while sorting out a hostname or a grant.
    </p>

    <?php if ($error): ?><?php flash_error($error); ?><?php endif; ?>
    <?php foreach ($summary as $line): ?>
      <?php str_starts_with($line, 'WARNING') ? flash_error($line) : flash_ok($line); ?>
    <?php endforeach; ?>

    <form method="post" action="?step=2" class="panel">
      <input type="hidden" name="_csrf" value="<?= h(token()) ?>">
      <div class="formgrid">
        <div class="field field--half">
          <label for="db_host">Host</label>
          <input id="db_host" name="db_host" type="text" required value="<?= h($host) ?>" placeholder="10.0.0.30">
          <span class="hint">The MariaDB server, which may not be this machine.</span>
        </div>
        <div class="field field--tiny">
          <label for="db_port">Port</label>
          <input id="db_port" name="db_port" type="number" required value="<?= h($port) ?>">
        </div>
        <div class="field field--third">
          <label for="db_name">Database</label>
          <input id="db_name" name="db_name" type="text" required value="<?= h($name) ?>">
          <span class="hint">It must already exist; the installer will not create it.</span>
        </div>
        <div class="field field--third">
          <label for="db_user">Username</label>
          <input id="db_user" name="db_user" type="text" required value="<?= h($user) ?>">
        </div>
        <div class="field field--third">
          <label for="db_pass">Password</label>
          <input id="db_pass" name="db_pass" type="password" value="<?= h($pass) ?>" autocomplete="off">
        </div>
      </div>
      <div class="formactions">
        <button class="btn btn--accent" type="submit" name="test_connection" value="1">Test the connection</button>
      </div>
    </form>

    <?php if (($reached || recall('db_reached')) && $error === null): ?>
      <form method="post" action="?step=3" style="margin-top:1.5rem">
        <input type="hidden" name="_csrf" value="<?= h(token()) ?>">
        <button class="btn btn--accent" type="submit">Continue to deployment</button>
      </form>
    <?php endif; ?>
    <?php
    foot();
    exit;
}

// -------------------------------------------------------------- 3. Deployment
//
// Separate from connecting on purpose. This is the only step that writes to the
// database, so it is the only one where a mistake costs anything.
if ($step === 3) {
    if (!recall('db_reached')) { header('Location: ?step=2'); exit; }

    $error = null;
    // Which field the message belongs to, when it belongs to one.
    $errorField = null;
    $host = (string) recall('db_host'); $port = (string) recall('db_port');
    $name = (string) recall('db_name'); $user = (string) recall('db_user');
    $pass = (string) recall('db_pass');

    $pdo = null;
    try { $pdo = pdo_connect($host, (int) $port, $name, $user, $pass); }
    catch (PDOException $e) { $error = 'Lost the connection: ' . $e->getMessage(); }

    if ($pdo !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $choice = post('deploy_action');
        $counts = existing_data_counts($pdo);

        if ($choice === 'erase' && has_real_data($counts)
            && strtoupper(trim(post('erase_confirm'))) !== 'ERASE') {
            $error      = trim(post('erase_confirm')) === ''
                ? 'Type ERASE in the confirmation box to choose a total reinstall.'
                : 'That is not ERASE. Type it exactly, in capitals.';
            // Marked on the box as well: a message at the top of the page is a
            // message beside the wrong thing, and this page has two panels.
            $errorField = 'erase_confirm';
        } elseif (in_array($choice, ['install', 'keep', 'erase'], true)) {
            // Recorded, not performed. Nothing touches the database until the
            // last step, so changing your mind here costs nothing.
            remember([
                'deploy_action' => $choice,
                // An erase is total: the tables and the photos together.
                'erase_uploads' => $choice === 'erase',
            ]);
            header('Location: ?step=4');
            exit;
        } else {
            $error = 'Choose what to do with this database.';
        }
    }

    $state = $pdo === null ? null : [
        'structure' => structure_present($pdo),
        'counts'    => existing_data_counts($pdo),
    ];
    $chosen = (string) recall('deploy_action', '');

    head('Deployment');
    ?>
    <h1>Deployment</h1>
    <p class="lede">
      Decide what should happen to this database. Nothing is carried out here —
      the last step does all of it at once, so you can come back and change this.
    </p>

    <?php if ($error): ?><?php flash_error($error); ?><?php endif; ?>

    <?php
    $hasCollection = $state !== null && has_real_data($state['counts']);
    $bits = [];
    if ($state !== null) {
        foreach ($state['counts'] as $label => $n) {
            if ($n > 0) { $bits[] = '<strong>' . (int) $n . '</strong> ' . h($label); }
        }
    }
    ?>

    <?php if ($state === null): ?>
      <p class="lede">The connection was lost. <a href="?step=2">Go back and check it.</a></p>

    <?php elseif (!$state['structure']): ?>
      <section class="panel" style="border-left:4px solid var(--good)">
        <h2 class="panel__title">The database is empty</h2>
        <p style="margin-top:0">Nothing of RetroHive's is here, so there is nothing to lose.</p>
        <form method="post" action="?step=3">
          <input type="hidden" name="_csrf" value="<?= h(token()) ?>">
          <input type="hidden" name="deploy_action" value="install">
          <p style="font-size:.9rem;color:var(--dim)">
            This step builds the structure. Whether to fill it with structure data
            — and whether to take that from GitHub or the copies that shipped —
            is asked on the Settings step.
          </p>
          <button class="btn btn--accent" type="submit">Create the structure, and continue</button>
        </form>
      </section>

    <?php elseif (!$hasCollection): ?>
      <section class="panel" style="border-left:4px solid var(--good)">
        <h2 class="panel__title">The tables exist but hold no collection</h2>
        <p style="margin-top:0">
          <?= $bits === [] ? 'Nothing in them at all.' : implode(', ', $bits) . ' — structure data only.' ?>
          Nothing anybody would miss.
        </p>
        <form method="post" action="?step=3">
          <input type="hidden" name="_csrf" value="<?= h(token()) ?>">
          <input type="hidden" name="deploy_action" value="erase">
          <button class="btn btn--accent" type="submit">Rebuild it, and continue</button>
        </form>
      </section>
      <section class="panel" style="margin-top:1rem">
        <h2 class="panel__title">Or leave it alone</h2>
        <form method="post" action="?step=3">
          <input type="hidden" name="_csrf" value="<?= h(token()) ?>">
          <input type="hidden" name="deploy_action" value="keep">
          <button class="btn" type="submit">Keep it, and continue</button>
        </form>
      </section>

    <?php else: ?>
      <p class="lede">There is a collection here: <?= implode(', ', $bits) ?>. Two ways forward.</p>
      <div class="cols cols--2">
        <section class="panel" style="margin:0;border-left:4px solid var(--good)">
          <h2 class="panel__title">Preserve it<?= $chosen === 'keep' ? ' — chosen' : '' ?></h2>
          <p style="margin-top:0;font-size:.9rem;color:var(--dim)">
            Left exactly as it is; only the configuration file is written. What
            you want when moving to a new server.
          </p>
          <form method="post" action="?step=3">
            <input type="hidden" name="_csrf" value="<?= h(token()) ?>">
            <input type="hidden" name="deploy_action" value="keep">
            <button class="btn btn--accent" type="submit">Keep it, and continue</button>
          </form>
        </section>
        <section class="panel" style="margin:0;border-left:4px solid var(--bad)">
          <h2 class="panel__title">Total reinstall<?= $chosen === 'erase' ? ' — chosen' : '' ?></h2>
          <p style="margin-top:0;font-size:.9rem;color:var(--dim)">
            Drops and rebuilds every RetroHive table, and deletes the uploaded
            photos. Other tables in this database are untouched.
            <strong>Not reversible.</strong> Back up first:
          </p>
          <pre class="cfg">./bin/backup.sh /srv/backups/retrohive</pre>
          <form method="post" action="?step=3">
            <input type="hidden" name="_csrf" value="<?= h(token()) ?>">
            <input type="hidden" name="deploy_action" value="erase">
            <?php $eraseBad = ($errorField ?? '') === 'erase_confirm'; ?>
            <div class="field" style="max-width:20rem">
              <label for="erase_confirm">Type ERASE to choose this</label>
              <input id="erase_confirm" name="erase_confirm" type="text" autocomplete="off"
                     placeholder="ERASE" value="<?= h(post('erase_confirm', '')) ?>"
                     <?= $eraseBad ? 'aria-invalid="true" autofocus' : '' ?>>
              <?php if ($eraseBad): ?>
                <span class="hint" style="color:var(--bad)"><?= h($error) ?></span>
              <?php endif; ?>
            </div>
            <div style="margin-top:1rem">
              <button class="btn btn--danger" type="submit">Choose reinstall, and continue</button>
            </div>
          </form>
        </section>
      </div>
    <?php endif; ?>
    <?php foot(); exit;
}

// ------------------------------------------------------------- 4. Settings
if ($step === 4) {
    if (recall('deploy_action') === null) { header('Location: ?step=3'); exit; }

    $guessHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $guessProto = (
        ($_SERVER['HTTPS'] ?? '') === 'on'
        || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    ) ? 'https' : 'http';
    $v = [
        'app_name'        => post('app_name', (string) recall('app_name', 'RetroHive')),
        'app_tagline'     => post('app_tagline', (string) recall('app_tagline', 'Retro software collection')),
        'currency'        => post('currency', (string) recall('currency', 'SEK')),
        'timezone'        => post('timezone', (string) recall('timezone', 'Europe/Stockholm')),
        'base_url'        => post('base_url', (string) recall('base_url', $guessProto . '://' . $guessHost)),
        'trusted_proxies' => post('trusted_proxies', (string) recall('trusted_proxies', '')),
        // Whether the catalogue starts with anything in it.
        'structure'       => post('structure', (string) recall('structure', 'remote')),
        // A checkbox: absent on a POST means unticked, which is different from absent
        // because the page has not been submitted yet.
        'examples'        => $_SERVER['REQUEST_METHOD'] === 'POST'
            ? (isset($_POST['examples']) ? '1' : '0')
            : (string) recall('examples', '0'),
        // Ticked unless somebody says otherwise. The wizard has always switched
        // these on and nothing asked; making it a question should not quietly
        // change the answer for everybody who presses Continue.
        'metadata_sources' => $_SERVER['REQUEST_METHOD'] === 'POST'
            ? (isset($_POST['metadata_sources']) ? '1' : '0')
            : (recall('metadata_sources', true) ? '1' : '0'),
    ];
    $error = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!in_array($v['timezone'], timezone_identifiers_list(), true)) {
            $error = 'That is not a recognised timezone identifier.';
        } elseif (!in_array($v['structure'], ['remote', 'local', 'none'], true)) {
            $error = 'Choose what to do about the structure data.';
        } else {
            remember($v + ['settings_set' => true]);
            header('Location: ?step=5');
            exit;
        }
    }

    head('Settings');
    ?>
    <h1>Settings</h1>
    <p class="lede">
      These go into <span class="mono">src/config.local.php</span>, which is written
      at the last step along with everything else.
    </p>
    <?php if ($error): ?><?php flash_error($error); ?><?php endif; ?>
    <form method="post" action="?step=4" class="panel">
      <input type="hidden" name="_csrf" value="<?= h(token()) ?>">
      <div class="formgrid">
        <div class="field field--half">
          <label for="app_name">Name</label>
          <input id="app_name" name="app_name" type="text" value="<?= h($v['app_name']) ?>">
        </div>
        <div class="field field--half">
          <label for="app_tagline">Tagline</label>
          <input id="app_tagline" name="app_tagline" type="text" value="<?= h($v['app_tagline']) ?>">
        </div>
        <div class="field field--tiny">
          <label for="currency">Currency</label>
          <input id="currency" name="currency" type="text" value="<?= h($v['currency']) ?>">
        </div>
        <div class="field field--third">
          <label for="timezone">Timezone</label>
          <input id="timezone" name="timezone" type="text" value="<?= h($v['timezone']) ?>">
          <span class="hint">A tz identifier, such as Europe/Stockholm.</span>
        </div>
        <div class="field field--half">
          <label for="base_url">Public address</label>
          <input id="base_url" name="base_url" type="text" value="<?= h($v['base_url']) ?>">
          <span class="hint">Used in emails and API responses.</span>
        </div>
        <div class="field field--half">
          <label for="trusted_proxies">Trusted proxies</label>
          <input id="trusted_proxies" name="trusted_proxies" type="text" value="<?= h($v['trusted_proxies']) ?>"
                 placeholder="172.16.1.1">
          <span class="hint">Comma separated.</span>
        </div>

        <div class="field" style="grid-column:1/-1">
          <span class="label">Structure data</span>
          <label class="checkline">
            <input type="radio" name="structure" value="remote"
                   <?= ($v['structure'] ?? 'remote') === 'remote' ? 'checked' : '' ?>>
            <?php
            // Named for what it actually brings. "Makers, studios and genres" were
            // the old words: makers and studios became one companies table, and
            // genres became the category tree.
            ?>
            Fetch machines, companies and category trees from GitHub
          </label>
          <label class="checkline">
            <input type="radio" name="structure" value="local"
                   <?= ($v['structure'] ?? '') === 'local' ? 'checked' : '' ?>>
            Use the copies that shipped with this install
          </label>
          <label class="checkline">
            <input type="radio" name="structure" value="none"
                   <?= ($v['structure'] ?? '') === 'none' ? 'checked' : '' ?>>
            None &mdash; start empty
          </label>
        </div>

        <?php
        // Examples are a separate question.
        //
        // They were bundled with the reference data, so anyone who wanted sixty-three
        // platforms also got six invented entries and a second library they had not
        // asked for - and the only way to decline was to decline the platforms too.
        // Reference data is scaffolding; examples are somebody else's collection.
        ?>
        <div class="field" style="grid-column:1/-1">
          <span class="label">Example entries</span>
          <label class="checkline">
            <input type="checkbox" name="examples" value="1"
                   <?= ($v['examples'] ?? '0') === '1' ? 'checked' : '' ?>>
            Add a few example entries and a shared example library
          </label>
        </div>
        <?php
        // The lookup sources, which the installer used to switch on without
        // asking. IGDB and TheGamesDB are not among them: they want credentials
        // somebody has to go and fetch, and an installer cannot.
        ?>
        <div class="field" style="grid-column:1/-1">
          <span class="label">Metadata sources</span>
          <label class="checkline">
            <input type="checkbox" name="metadata_sources" value="1"
                   <?= ($v['metadata_sources'] ?? '1') === '1' ? 'checked' : '' ?>>
            Switch on the lookup sources that need no account or key
          </label>
        </div>
      </div>
      <div class="formactions">
        <button class="btn btn--accent" type="submit">Continue</button>
      </div>
    </form>
    <?php foot(); exit;
}

// -------------------------------------------------------- 5. Administrator
if ($step === 5) {
    if (!recall('settings_set')) { header('Location: ?step=4'); exit; }

    $error = null;
    $username = post('username', (string) recall('admin_username', ''));
    $email    = post('email', (string) recall('admin_email', ''));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');

        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
            $error = 'Username can use letters, numbers, dot, dash and underscore, 3 to 64 characters.';
        } elseif (strlen($password) < 10) {
            $error = 'Use a password of at least 10 characters.';
        } elseif ($password !== $confirm) {
            $error = 'The two passwords do not match.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'That does not look like an email address.';
        } else {
            // Hashed here rather than at the end, so the session never holds a
            // password in plain text even briefly.
            remember([
                'admin_username' => $username,
                'admin_email'    => $email,
                'admin_hash'     => password_hash($password, PASSWORD_DEFAULT),
                'admin_set'      => true,
            ]);
            header('Location: ?step=6');
            exit;
        }
    }

    head('Administrator');
    ?>
    <h1>Administrator account</h1>
    <p class="lede">
      The first account. It gets the admin role, and a library of its own to put
      things in. Created at the last step with everything else.
    </p>
    <?php if ($error): ?><?php flash_error($error); ?><?php endif; ?>
    <form method="post" action="?step=5" class="panel">
      <input type="hidden" name="_csrf" value="<?= h(token()) ?>">
      <div class="formgrid">
        <div class="field field--half">
          <label for="username">Username</label>
          <input id="username" name="username" type="text" required value="<?= h($username) ?>" autocomplete="username">
        </div>
        <div class="field field--half">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" value="<?= h($email) ?>" placeholder="you@example.com">
          <span class="hint">Optional. You can sign in with it as well as the username.</span>
        </div>
        <div class="field field--half">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
          <span class="hint">At least 10 characters.</span>
        </div>
        <div class="field field--half">
          <label for="password_confirm">Password again</label>
          <input id="password_confirm" name="password_confirm" type="password" required minlength="10" autocomplete="new-password">
        </div>
      </div>
      <div class="formactions">
        <button class="btn btn--accent" type="submit">Continue</button>
        <?php if (recall('admin_set')): ?>
          <span class="hint">Already entered. Submitting again replaces it.</span>
        <?php endif; ?>
      </div>
    </form>
    <?php foot(); exit;
}

// --------------------------------------------------------- 6. Review, 7. Install
//
// Two steps, not one.
//
// Review shows the plan and changes nothing; Install is its own phase and the only
// thing that touches the database. Splitting them is what makes the progress honest:
// the browser gets a real page for the work rather than a form that appears to hang,
// and "go back" stops being a question, because by the time you are on step 7 the
// tables are already going.
if (!recall('admin_set')) { header('Location: ?step=5'); exit; }

$plan     = plan();
// Verified against the disk, not remembered. A session can outlive a
// redeployment - the config file is gitignored, so a fresh clone has none -
// and trusting the flag alone renders a success page for an install that is
// no longer there.
$applied  = (bool) recall('applied') && config_exists();
$deleted  = false;
$error    = null;
$log      = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selfdestruct'])) {
    // Only once there is a configuration to run from. Deleting the installer
    // while the application cannot start leaves no way back in except a shell.
    if (!config_exists()) {
        $error = 'There is no configuration file yet, so the installer is the only way '
               . 'to finish. It will not delete itself while that is true.';
    } else {
        $deleted = @unlink(__FILE__);
        if ($deleted) {
            unset($_SESSION['install']['wizard_active']);
        }
    }
}

$running = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply']) && !$applied;

if ($running) {
    // Marked before the first table is touched, so a reload or a back button lands on
    // step 7 rather than offering to start again.
    remember(['installing' => true]);

    // The page is sent now and filled in as the work happens, so the browser paints
    // something immediately instead of waiting for the whole install. Buffering is the
    // enemy of that: zlib, nginx and PHP's own buffers will each happily hold the lot
    // until the script ends.
    while (ob_get_level() > 0) { ob_end_flush(); }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    ob_implicit_flush(true);
    header('X-Accel-Buffering: no');
    header('Content-Type: text/html; charset=utf-8');

    head('Installing');
    echo install_progress_shell();
    install_tick('start');

    try {
        $pdo = pdo_connect($plan['db']['host'], (int) $plan['db']['port'],
                           $plan['db']['name'], $plan['db']['user'], $plan['db']['pass']);

        install_tick('db');
        // 1. The database
        if ($plan['deploy'] === 'erase') {
            $before = existing_data_counts($pdo);
            $pdo->exec('DROP TABLE IF EXISTS schema_migrations');
            $dropErrors = drop_retrovault_tables($pdo);
            if ($dropErrors !== []) {
                throw new RuntimeException('Some tables could not be dropped: '
                    . implode(' | ', array_slice($dropErrors, 0, 3)));
            }
            $log[] = 'Erased: ' . implode(', ', array_map(
                fn($v) => counted((int) $v['n'], (string) $v['one'], (string) $v['many']),
                array_values($before)));
            if ($plan['uploads_too']) {
                $purge = purge_uploads();
                // How many photographs the database had, to compare with what was
                // on disk. The two lines used to be written independently, so
                // "Erased: 8 photos" and "No uploaded files to delete" could sit
                // one above the other and neither knew the other was there.
                // Keyed by the plural label, not the table name: existing_data_counts()
                // builds $counts['photos'], because the same array is what the
                // "Erased: …" line is written from. I read it as ['item_images'],
                // which is null, so the branch below could never fire - a
                // diagnostic that was itself undiagnosable.
                $photoRows = (int) ($before['photos']['n'] ?? 0);

                if (!empty($purge['missing'])) {
                    $log[] = sprintf('%s does not exist, so no uploaded file was touched '
                        . '— if photographs are being stored, they are somewhere else, '
                        . 'and uploads.dir in the configuration is what says where',
                        pretty_path(UPLOADS_DIR));
                } elseif (!empty($purge['unreadable'])) {
                    $log[] = sprintf('%s could not be read, so no uploaded file was '
                        . 'touched — check that the web user can list it',
                        pretty_path(UPLOADS_DIR));
                } elseif ($purge['seen'] === 0 && $photoRows > 0) {
                    // The interesting case, and the one that reads as a
                    // contradiction if nobody says anything: rows for
                    // photographs, and no files where this expects them.
                    $log[] = sprintf('%s recorded, but no files were found in %s — '
                        . 'they are either stored somewhere else or were already gone, '
                        . 'and nothing on disk has been deleted',
                        counted($photoRows, 'photograph was', 'photographs were'),
                        pretty_path(UPLOADS_DIR));
                } elseif ($purge['seen'] === 0) {
                    $log[] = 'No uploaded files to delete';
                } elseif ($purge['removed'] === $purge['seen']) {
                    $log[] = counted($purge['removed'], 'uploaded file deleted', 'uploaded files deleted');
                } else {
                    // Said plainly, because the erase has not finished and nothing
                    // else will mention it. The usual cause is the directory being
                    // owned by somebody other than the web user.
                    $log[] = sprintf('%d of %d uploaded files could not be deleted — check '
                        . 'who owns %s; the rows are gone and the files are still there',
                        $purge['seen'] - $purge['removed'], $purge['seen'],
                        pretty_path(UPLOADS_DIR));
                }
            }
        }

        if ($plan['deploy'] === 'install' || $plan['deploy'] === 'erase') {
            [$errs, $msgs] = run_sql_file($pdo, SCHEMA_FILE);
            if ($errs > 0) {
                throw new RuntimeException('The schema did not load: ' . implode(' | ', $msgs));
            }
            install_tick('schema');
            $log[] = 'Structure created';
            $log[] = counted(installer_baseline($pdo), 'migration recorded', 'migrations recorded');
            // Always: db/seed.sql is auth methods and platform classes, which
            // the software cannot run without. It stopped being structure data
            // when the two were split, and skipping it would leave an instance
            // nobody can sign in to - which is not what "start empty" means.
            run_sql_file($pdo, SEED_FILE);
            $log[] = 'Core records created';
        } else {
            $log[] = 'Existing collection left untouched';
        }

        // 2. The configuration file
        $content = config_php(config_values());
        if (@file_put_contents(CONFIG_FILE, $content) !== false) {
            @chmod(CONFIG_FILE, 0640);
            $log[] = 'Configuration written to ' . pretty_path(CONFIG_FILE);
            install_tick('config');
        } else {
            throw new RuntimeException('Could not write ' . CONFIG_FILE
                . '. Let the web server write it just once - chgrp www '
                . dirname(CONFIG_FILE) . ' && chmod 775 ' . dirname(CONFIG_FILE)
                . ' - then press Install again. Nothing else has been left half done.');
        }

        // 3. The administrator, and somewhere to put things
        $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $st->execute([$plan['admin']]);
        if ((int) $st->fetchColumn() > 0) {
            $log[] = 'An account called ' . $plan['admin'] . ' already existed and was left alone';
        } else {
            $hasAuth = table_exists($pdo, 'auth_methods');
            // Verified on creation: this is the person running the install,
            // and there is nobody else on the instance to vouch for them. If
            // they had to confirm an address before signing in, and the relay
            // they have not configured yet could not send it, the install would
            // finish with nobody able to log into it.
            $sql = $hasAuth
                ? 'INSERT INTO users (username, auth_method_id, password_hash, display_name, email, role, is_active, email_verified_at)
                   VALUES (?, 1, ?, ?, ?, \'admin\', 1, NOW())'
                : 'INSERT INTO users (username, password_hash, display_name, email, role, is_active, email_verified_at)
                   VALUES (?, ?, ?, ?, \'admin\', 1, NOW())';
            $ins = $pdo->prepare($sql);
            $ins->execute([$plan['admin'], (string) recall('admin_hash'), $plan['admin'],
                           $plan['email'] !== '' ? $plan['email'] : null]);
            $adminId = (int) $pdo->lastInsertId();

            // Structure data, if it was asked for. Deliberately after the account
            // exists: an install that fails here still leaves somebody able to
            // sign in and try again from the settings page, rather than a
            // half-built instance nobody can reach.
            $want = (string) ($plan['structure'] ?? 'remote');
            if ($want !== 'none') {
                try {
                    install_tick('starter', $want === 'remote' ? 'over the network' : 'from disk');

                    // Where it came from is said in the result, not announced
                    // before it. This wrote "Fetching structure data from GitHub"
                    // into a list headed "what was done" - a present participle
                    // among past-tense entries, describing a step that had not
                    // happened yet, immediately above the line reporting how it
                    // went. The progress ticker is what narrates; this list
                    // records.
                    $from = $want === 'remote' ? 'GitHub' : 'the copies that shipped';

                    if (!installer_boot_app()) {
                        throw new RuntimeException('the application could not be loaded');
                    }
                    [$summary, $errors] = structure_sync($want === 'remote');
                    $log[] = sprintf('Structure data: %d rows added from %s',
                                     array_sum(array_column($summary, 'added')), $from);
                    foreach ($errors as $err) {
                        $log[] = 'Structure data: ' . $err;
                    }
                    // A network that is not there should not stop an install.
                    if ($want === 'remote' && $errors !== []) {
                        // Past tense, like everything else in this list.
                        $log[] = 'Fell back to the copies that shipped';
                        [$summary2] = structure_sync(false);
                        // The same words as the line above, for the same thing:
                        // "from disk" and "from the copies that shipped" side by
                        // side reads as two different sources.
                        $log[] = sprintf('Structure data: %d rows added from the copies that shipped',
                                         array_sum(array_column($summary2, 'added')));
                    }
                } catch (Throwable $e) {
                    $log[] = 'Structure data could not be loaded from '
                        . (($plan['structure'] ?? 'remote') === 'remote' ? 'GitHub' : 'disk')
                        . ': ' . $e->getMessage()
                           . ' (Instance settings can retry it)';
                }
            } else {
                $log[] = 'Structure data skipped; the catalogue starts empty '
                       . '(the administrator still gets their own personal library)';
            }
            install_tick('admin');
        $log[] = 'Administrator ' . $plan['admin'] . ' created';

            $lib = $pdo->prepare(
                // No domain column: a library holds both software and hardware,
                // and what an entry is comes from where it sits in the tree.
                // is_personal marks the one shelf every account gets and
                // nobody can delete.
                "INSERT INTO libraries (name, slug, description, owner_id, kind, is_personal, is_default, sort_order)
                 SELECT 'My Private Library', 'my-private-library',
                        'Yours alone. It cannot be shared, which is what makes it the one place you always have.',
                        ?, 'private', 1, 1, 10 FROM DUAL
                  WHERE NOT EXISTS (SELECT 1 FROM libraries)");
            $lib->execute([$adminId]);
            $mem = $pdo->prepare("INSERT IGNORE INTO library_members (library_id, user_id, access)
                                  SELECT id, ?, 'owner' FROM libraries WHERE is_default = 1");
            $mem->execute([$adminId]);
            // Named, so the summary is not ambiguous about what got made.
            //
            // "First library created" reads like the installer made a decision; it did
            // not. Every account has exactly one personal shelf and this is the
            // administrator's - it exists whatever was chosen about structure data,
            // because an account with nowhere to put anything is not a working account.
            $log[] = 'Personal library created for the administrator';

            // Copy the structure data into it. The installer builds this library
            // with raw SQL rather than through ensure_first_library(), so the
            // seeding that function does never ran - which is why a freshly
            // installed instance had platforms in the templates and none in the
            // only library that existed.
            try {
                if (!installer_boot_app()) {
                    throw new RuntimeException('the application could not be loaded');
                }
                $libId = (int) $pdo->query('SELECT id FROM libraries WHERE is_default = 1 LIMIT 1')->fetchColumn();

                // Only if structure data was asked for.
                //
                // This ran whatever the answer on the settings step, so choosing "none"
                // still filled the library - from the old SQL seed file, which the schema
                // step loaded regardless of that answer. The choice gated fetching the
                // JSON templates and not copying them, which is the half nobody sees.
                if ($libId > 0 && $want !== 'none') {
                    // The sources that need no key, configured before the
                    // library is seeded so seeding can switch them on.
                    //
                    // A fresh install used to configure none at all, so a new
                    // catalogue could look up nothing until somebody went to the
                    // agents screen and added them one at a time. These are the
                    // ones that ask for nothing: no account, no key, no terms to
                    // agree to. IGDB and TheGamesDB are left out because they
                    // need credentials somebody has to go and get.
                    // Shared with bin/install.php, which used to skip this
                    // entirely - see installer_enable_metadata_sources().
                    $sources = (string) recall('metadata_sources', '1') === '1'
                        ? installer_enable_metadata_sources()
                        : ['added' => 0, 'skipped' => []];
                    if ($sources['added'] > 0) {
                        $log[] = sprintf(
                            'Metadata sources configured: %d, the ones needing no key that answered',
                            $sources['added']);
                    }
                    // A source that did not answer is named on the summary as
                    // well as in the log. Somebody finishing an install should
                    // not discover months later that a lookup has been quietly
                    // asking four sources instead of six.
                    foreach ($sources['skipped'] as $label => $why) {
                        $log[] = sprintf('Skipped %s: it did not answer its own probe - %s',
                                         $label, $why);
                    }

                    $copied = seed_library_hardware($libId);
                    $log[]  = sprintf('Structure data copied into the library: %d machines', $copied);

                    // What each machine runs, copied with the platforms. Reported
                    // because a summary that lists everything except the newest thing
                    // is how somebody concludes it did not happen.
                    $envs = (int) scalar(
                        'SELECT COUNT(*) FROM operating_systems WHERE library_id = ?', [$libId]
                    );
                    if ($envs > 0) {
                        $log[] = sprintf('Environments copied: %d', $envs);
                    }
                    // The trees too, which are now by far the biggest thing seeding
                    // makes - one per machine, sized to what that kind of machine has -
                    // and the summary said nothing about them at all.
                    $cats = (int) scalar(
                        'SELECT COUNT(*) FROM categories WHERE library_id = ?', [$libId]
                    );
                    if ($cats > 0) {
                        // What those branches say they hold, not just how many
                        // there are.
                        //
                        // A count of 3,672 says the copy ran. It does not say the
                        // tree is usable, and the thing that makes it usable -
                        // every branch declaring games, applications, machines or
                        // peripherals - is new enough to be worth showing rather
                        // than assuming. If a line here ever reads "0 games", the
                        // template data or the importer has stopped agreeing with
                        // the column, which is exactly the failure that would
                        // otherwise be found weeks later by a browser filter
                        // returning nothing.
                        $kinds = [];
                        foreach (all('SELECT role, COUNT(*) AS n FROM categories
                                       WHERE library_id = ? AND role <> "other"
                                       GROUP BY role ORDER BY n DESC', [$libId]) as $row) {
                            $kinds[] = (int) $row['n'] . ' ' . (string) $row['role']
                                     . ((int) $row['n'] === 1 ? '' : 's');
                        }
                        $log[] = sprintf(
                            'Category trees built: %d kinds across %d machines%s',
                            $cats,
                            (int) scalar(
                                'SELECT COUNT(*) FROM categories WHERE library_id = ? AND parent_id IS NULL',
                                [$libId]
                            ),
                            $kinds === [] ? '' : ' — ' . implode(', ', $kinds)
                        );
                    }

                    // Whether those sources were actually switched on anywhere.
                    //
                    // "Configured: 7" says they exist; it does not say the tree
                    // asks any of them, and those are different facts - the first
                    // was true on the run before this one, when nothing had been
                    // switched on at all and nothing said so.
                    $switched = (int) scalar(
                        'SELECT COUNT(DISTINCT ps.category_id) FROM provider_scopes ps
                           JOIN categories c ON c.id = ps.category_id
                          WHERE c.library_id = ? AND ps.enabled = 1', [$libId]);
                    if ($switched > 0) {
                        $log[] = sprintf(
                            'Metadata sources switched on for %d branches, inherited by the rest',
                            $switched);
                    }

                    // A couple of example entries, so the first page somebody
                    // sees is not empty. Only where they asked for structure data:
                    // "start empty" means empty.
                    // Asked for separately. Reference data is scaffolding you want on
                    // any instance; examples are somebody else's collection, and the
                    // only way to decline them used to be declining the platforms too.
                    if ($want !== 'none' && !empty($plan['examples'])) {
                        $ex = seed_library_examples($libId);
                        if ($ex > 0) {
                            $log[] = sprintf('%d example entries added, to edit or delete', $ex);
                        }

                        // And a second, shared one. A single library shows nothing
                        // about what a library is for; two, holding different
                        // machines, show that each has its own makers, platforms and
                        // models.
                        $sharedId = seed_shared_example_library($adminId);
                        if ($sharedId > 0) {
                            $log[] = sprintf(
                                'Shared example library created with %d entries of its own',
                                (int) scalar('SELECT COUNT(*) FROM items WHERE library_id = ?', [$sharedId])
                            );
                        }
                    }
                }
            } catch (Throwable $e) {
                $log[] = 'Could not copy the structure data into the library: ' . $e->getMessage()
                       . ' (Instance settings can retry it)';
            }
        }

        remember(['applied' => true, 'apply_log' => $log, 'installed_admin' => $adminId]);
        install_tick('done');
        $applied = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// What the answer file asked for after the work, if it asked for anything.
//
// Both are off unless a file said otherwise: somebody who walked the wizard by
// hand has not agreed to have the installer deleted underneath them, and has not
// asked to be signed in either.
$autoSignIn = false;
if ($applied && $running) {
    if ((bool) recall('delete_installer')) {
        // Only with a configuration to run from, the same rule the button on
        // this page follows: deleting the installer while the application cannot
        // start leaves no way back in except a shell.
        if (config_exists() && @unlink(__FILE__)) {
            $log[] = 'Installer deleted';
            unset($_SESSION['install']['wizard_active']);
        } else {
            $log[] = 'The installer could not delete itself - remove '
                   . pretty_path(__FILE__) . ' by hand';
        }
    }

    if ((bool) recall('sign_in')) {
        $who = (int) recall('installed_admin', 0);
        if ($who > 0) {
            // Set directly rather than through attempt_login(): there is no
            // password here to check, only the hash that was just written, and
            // the account was created by this request a second ago.
            //
            // No session_regenerate_id() either. The page has been streaming
            // since before the first table was made, so the cookie header it
            // wants to send is long gone - and warning about that on the success
            // page would be the only thing anybody read.
            $_SESSION['user_id'] = $who;
            $autoSignIn = true;
        }
    }
}

// The work is finished. From here the page is the result, and the running panel goes.
if ($running) {
    // Everything from here is the result. Hidden until the panel has finished, or the
    // summary appears underneath a list that still says "Finishing" - which is what it
    // did: the server was done, but the display had four stages left to show.
    echo '<div id="installresult" hidden>';
    remember(['installing' => false]);
}

if ($applied && $log === []) {
    $log = (array) recall('apply_log', []);
}

// Already sent when streaming: the page opened before the work started.
if (!$running) {
    head($applied ? 'Installed' : 'Ready to install');
}
?>

<?php if (!$applied): ?>
  <h1>Ready to install</h1>
  <p class="lede">
    Nothing has been changed yet. This is everything that will happen, in order.
    Any of it can still be altered by going back.
  </p>

  <?php if ($error): ?><?php flash_error($error); ?><?php endif; ?>

  <div class="panel">
    <h2 class="panel__title">What will happen</h2>
    <table class="table">
      <tbody>
        <tr>
          <td style="width:30%">Database</td>
          <td>
            <span class="mono"><?= h($plan['db']['user']) ?>@<?= h($plan['db']['host']) ?>:<?= h($plan['db']['port']) ?>/<?= h($plan['db']['name']) ?></span>
            <a class="hint" href="?step=2" style="margin-left:.5rem">change</a>
          </td>
        </tr>
        <tr>
          <td>Deployment</td>
          <td>
            <?php if ($plan['deploy'] === 'install'): ?>
              Create the structure
            <?php elseif ($plan['deploy'] === 'erase'): ?>
              <strong style="color:var(--bad)">Drop every RetroHive table, rebuild, and delete the uploaded photos</strong>
            <?php else: ?>
              Leave the existing collection exactly as it is
            <?php endif; ?>
            <a class="hint" href="?step=3" style="margin-left:.5rem">change</a>
          </td>
        </tr>
        <tr>
          <td>Configuration</td>
          <td>
            Write <span class="mono"><?= h(pretty_path(CONFIG_FILE)) ?></span>
            <a class="hint" href="?step=4" style="margin-left:.5rem">change</a>
          </td>
        </tr>
        <tr>
          <td>Administrator</td>
          <td>
            <span class="mono"><?= h($plan['admin']) ?></span><?= $plan['email'] !== '' ? ' &lt;' . h($plan['email']) . '&gt;' : '' ?>, with a first library
            <a class="hint" href="?step=5" style="margin-left:.5rem">change</a>
          </td>
        </tr>
      </tbody>
    </table>

    <form method="post" action="?step=7" style="margin-top:1rem"
          <?= $plan['deploy'] === 'erase' ? 'data-confirm="Erase the existing collection and install?"' : '' ?>>
      <input type="hidden" name="_csrf" value="<?= h(token()) ?>">
      <?php
      // apply travels as a hidden field, not on the button.
      //
      // The submit handler disables the button to stop a second click, and a disabled
      // control is not serialised - so the value the installer keys on never arrived,
      // it re-rendered this same step, and the progress panel appeared and vanished.
      // The field does not care whether the button is disabled.
      ?>
      <input type="hidden" name="apply" value="1">
      <button class="btn btn--accent" type="submit">Install now</button>
      <?php
      // Offered here as well as at the end, because here is where somebody is
      // looking at the whole plan and deciding it is right - which is the moment
      // they are best placed to keep it for the next machine, and the last
      // moment before the answers stop being a plan and start being an instance.
      //
      // Streamed by ?download=answers straight from the session. Nothing is
      // written to disk: an installer that leaves a file full of answers in the
      // document root has undone the reason none of the credentials are in it.
      ?>
      <a class="btn" href="?download=answers" style="margin-left:.4rem">Download answers</a>
    </form>
    <?php
    // Something to look at while it works.
    //
    // The install is one POST that drops tables, rebuilds, fetches the structure data
    // over the network and copies it into a library - several seconds, during which
    // the page simply sat there and looked hung. This is honest about what it is: a
    // list of the steps with the current one marked, advancing on a timer rather than
    // reporting real progress, because a single request cannot report on itself. The
    // last line stays until the server answers.
    ?>
  </div>

<?php else: ?>
  <h1>Installed</h1>
  <p class="lede">RetroHive is ready.</p>

  <div class="panel">
    <h2 class="panel__title">What was done</h2>
    <ul style="margin:0;padding-left:1.1rem;color:var(--dim);font-size:.9rem;line-height:1.8">
      <?php foreach ($log as $line): ?><li><?= h($line) ?></li><?php endforeach; ?>
    </ul>
  </div>

  <div class="panel" style="margin-top:1rem;border-left:4px solid var(--bad)">
    <h2 class="panel__title">Remove the installer</h2>
    <?php if ($deleted): ?>
      <p style="margin-top:0;color:var(--good)">Gone.</p>
    <?php elseif (!file_exists(__FILE__)): ?>
      <p style="margin-top:0;color:var(--good)">Already gone.</p>
    <?php else: ?>
      <p style="margin-top:0">
        It refuses to run now that a configuration exists, but leaving it in a
        document root is still a liability.
      </p>
      <?php
      // Posts to step 7, not 6. This panel only ever appears after the install, and
      // step 6 now redirects here - so a form aimed at 6 was bounced before its
      // handler could run and the button silently did nothing.
      ?>
      <form method="post" action="?step=7" style="margin:.8rem 0">
        <input type="hidden" name="_csrf" value="<?= h(token()) ?>">
        <button class="btn btn--danger" type="submit" name="selfdestruct" value="1">Delete install.php now</button>
      </form>
      <p style="font-size:.85rem;color:var(--dim);margin-bottom:.4rem">Or from a shell:</p>
      <pre class="cfg">rm <?= h(__FILE__) ?></pre>
    <?php endif; ?>
  </div>

  <div class="panel" style="margin-top:1rem">
    <h2 class="panel__title">Worth doing next</h2>
    <ul style="margin:0;padding-left:1.1rem;color:var(--dim);font-size:.9rem;line-height:1.7">
      <li>Tighten the config file: <span class="mono">chmod 640 <?= h(pretty_path(CONFIG_FILE)) ?></span>, group-owned by the web server group.</li>
      <li>
        The library you are working in is chosen in the header. Add more, or
        invite people into one, from <strong>Library access</strong> in the
        account menu — Manage is for what goes <em>in</em> a library, not for the
        libraries themselves.
      </li>
      <li>For phones and desktop apps, issue a token under <strong>App access</strong>.</li>
      <li>Schedule <span class="mono">bin/backup.sh</span> — it dumps the database and tars the photos.</li>
      <li>If you use LDAP or Active Directory, see <span class="mono">docs/LDAP.md</span>.</li>
    </ul>
  </div>

  <p style="margin-top:1.5rem">
    <a class="btn btn--accent" href="./login">Sign in as <?= h($plan['admin']) ?></a>
  </p>
<?php endif; ?>
<?php
// Close the result wrapper and let the progress panel finish before showing it.
if ($running) {
    echo '</div><script>window.__rvDone&&__rvDone();</script>';
}

// Signed in already, so there is nothing on this page worth reading.
//
// In the markup rather than a Location header: this response began streaming
// before the first table was created, so the headers went out minutes ago. The
// delay lets the progress list finish drawing, because a page that vanishes
// mid-animation reads as a crash rather than as success.
if ($autoSignIn) {
    echo '<script>setTimeout(function(){location.href="./";},1200);</script>'
       . '<noscript><meta http-equiv="refresh" content="2;url=./"></noscript>';
}

foot();
