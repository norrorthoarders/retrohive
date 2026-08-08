<?php
declare(strict_types=1);

/**
 * The endpoints other things call: status, health and robots.
 *
 * Not screens, which is why this file outlived the interface it belonged to. A
 * monitor, a load balancer and a crawler each ask one of these, and none of them
 * has a session or reads HTML.
 *
 * status_serve_html() renders its own page rather than going through the layout,
 * and always did: the layout needs the database connection this page exists to
 * report on, so a status page built from it would fail at the one moment it has
 * a job to do.
 *
 * The registration form that used to live here is in the web client now.
 */

/**
 * A health check a proxy can believe.
 *
 * Every other address on this application either needs a session or redirects
 * to one, so a check pointed at "/" got a 303 and concluded the backend was
 * down. That is a proxy answering 503 in front of a server that is working.
 *
 * 200 when this instance can actually serve a page, 503 when it cannot. The
 * difference is the database: PHP running happily in front of a database it
 * cannot reach is not a healthy instance, and a check that says otherwise is
 * worse than no check.
 *
 * Nothing about the instance is disclosed - a version or a table count would
 * make this a reconnaissance endpoint, and the only thing a checker needs is
 * the status line.
 */
function health_serve(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    // What a checker may ask with, so an OPTIONS probe is answered rather than
    // guessed at.
    header('Allow: GET, HEAD, OPTIONS');

    // An instance that has not been installed yet is not unhealthy.
    //
    // It can serve: it can serve the installer, which is the only page it has
    // any business serving. Reporting 503 here marks the backend DOWN, and a
    // proxy in front then refuses every request - including the one that would
    // have reached /install.php. So unpacking this behind a proxy left you
    // unable to install it, which is a trap of my own making.
    //
    // The distinction is whether there is an installer to reach. A configured
    // instance whose database has gone is a real outage; an unconfigured one
    // with an installer present is a job half done, and the proxy should keep
    // sending traffic so somebody can finish it.
    if (!app_is_configured()) {
        if (is_file(installer_path())) {
            http_response_code(200);
            echo "setup\n";
            return;
        }
        http_response_code(503);
        echo "unconfigured\n";
        return;
    }

    try {
        // Its own connection, with its own short timeout.
        //
        // Going through db() would be neater but it does not return on failure:
        // it sets 500 and renders an error page, so the 503 below could never be
        // reached and a checker was told "internal error" when the honest answer
        // is "not ready". Three seconds, because a check that waits five is a
        // check that has already timed out.
        $c   = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                       $c['host'], (int) $c['port'], $c['name']);
        $probe = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        // The cheapest question that proves the connection and a real table.
        $probe->query('SELECT 1 FROM settings LIMIT 1');
    } catch (Throwable $e) {
        http_response_code(503);
        // Logged, because "the proxy says 503" is where somebody starts and
        // this is the line that tells them which half is broken.
        error_log('[retrohive] health check failed: ' . $e->getMessage());
        echo "unavailable\n";
        return;
    }

    http_response_code(200);
    echo "ok\n";
}

/** robots.txt, served rather than stored. */
function robots_serve(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    // Crawlers cache this hard, and an instance that has just switched itself to
    // private should not wait a week for that to take effect.
    header('Cache-Control: no-store');
    echo robots_txt();
}

/**
 * The data behind /status and /status.json - one place that decides what a
 * human or a script gets to know, so the two can never quietly disagree.
 *
 * Same restraint as health_serve() above, deliberately: no version number,
 * no table counts, no library or user data. A status page is still a public,
 * unauthenticated address, and what it is safe to say there is exactly what
 * /healthz already decided was safe - operational or not, and nothing more
 * specific than that.
 */
function status_data(): array
{
    $now = gmdate('Y-m-d\TH:i:s\Z');

    if (!app_is_configured()) {
        return [
            'status'     => is_file(installer_path()) ? 'setup' : 'unconfigured',
            'database'   => null,
            'checked_at' => $now,
        ];
    }

    try {
        $c   = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                       $c['host'], (int) $c['port'], $c['name']);
        $probe = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $probe->query('SELECT 1 FROM settings LIMIT 1');
    } catch (Throwable $e) {
        error_log('[retrohive] status check failed: ' . $e->getMessage());
        return [
            'status'     => 'unavailable',
            'database'   => 'unreachable',
            'checked_at' => $now,
        ];
    }

    return [
        'status'     => 'operational',
        'database'   => 'connected',
        'checked_at' => $now,
    ];
}

/**
 * A third tier, past /status and admin/status - deliberately not on by
 * default, and off in a way that does not admit it exists: the wrong
 * answer here is not "access denied", it is 404, the same shape as a path
 * nothing has ever mapped. This is a switch somebody flips on purpose while
 * actively testing a deployment, not a permission a person is granted -
 * confusing the two would mean an administrator's own credentials getting
 * this by default, on every instance, forever.
 *
 * Answers what /status and admin/status both withhold on purpose - not more
 * of the same withholding, a different question: not "is the software
 * healthy" but "which build is this, and when did it land here." The build
 * number lives in a plain file at the project root, incremented by hand
 * once per package - there is no CI here to do it automatically, and a
 * number that is wrong is worse than a mechanism that admits it is manual.
 */
function status_serve_debug(): void
{
    if (!(bool) config('debug_status')) {
        // Not not_found(): that renders through the normal layout, which
        // needs the same database connection this switch has nothing to do
        // with. A database outage should not be able to turn "this switch
        // is off" into a fatal error instead of a plain 404.
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found.\n";
        return;
    }

    $build = trim((string) @file_get_contents(APP_ROOT . '/BUILD'));
    $configFile = config_local_path();
    $deployedAt = is_file($configFile) ? gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($configFile)) : null;

    $out = array_merge(status_data(), [
        'build'        => $build !== '' ? $build : null,
        // The config file is rewritten by install.php on every deploy this
        // project does today - a full reinstall, not a patch - so its mtime
        // is a free, honest answer to "when did the code currently running
        // actually land here", without needing a CI system to stamp one.
        'deployed_at'  => $deployedAt,
        'app_version'  => defined('APP_VERSION') ? APP_VERSION : null,
        'api_version'  => defined('API_VERSION') ? API_VERSION : null,
        'php_version'  => PHP_VERSION,
        'hostname'     => gethostname() ?: null,
        'php' => [
            // The three settings that actually explain "why did my upload
            // fail" without needing shell access to read php.ini by hand.
            'memory_limit'         => ini_get('memory_limit'),
            'upload_max_filesize'  => ini_get('upload_max_filesize'),
            'post_max_size'        => ini_get('post_max_size'),
        ],
    ]);

    // Everything below needs the database status_data() already checked -
    // asked for again here rather than assumed, since 'operational' a moment
    // ago is not a guarantee it still is one. Skipped rather than attempted
    // and caught: a second connection failure here would be the same fact
    // status_data() already reported, told twice.
    if ($out['status'] !== 'unavailable') {
        $update = update_status();
        $out['migrations'] = [
            'applied' => count($update['applied']),
            'pending' => $update['pending'],
            'total'   => $update['migrations_total'],
        ];
        $out['schema_ok']    = $update['structure']['ok'];
        $out['needs_update'] = $update['needs_update'];

        // Never params: that column is where API keys live. The same
        // restraint admin/status already applies, extended here rather than
        // re-decided - a debug switch is not a reason to hand out credentials
        // any more than an admin session is.
        $out['metadata_providers'] = array_map(static function (array $p): array {
            return [
                'type'       => $p['type'],
                'name'       => $p['name'],
                'is_enabled' => (bool) $p['is_enabled'],
                'last_error' => $p['last_error'],
            ];
        }, all('SELECT type, name, is_enabled, last_error FROM metadata_providers ORDER BY priority, name'));
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($out['status'] === 'unavailable' ? 503 : 200);
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function status_serve_html(): void
{
    $data = status_data();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($data['status'] === 'unavailable' ? 503 : 200);

    // Deliberately not render()/layout.php: the layout calls working_library(),
    // unread_notification_count() and a raw footer query, all of which need
    // the exact database connection this page exists to report on. A status
    // page that cannot load while the database is down is a status page that
    // fails at the one moment it has a job to do - the same reasoning
    // health_serve() above already applies, extended to cover a rendered page
    // rather than a one-line response.
    $labels = [
        'operational'   => ['Operational', '#a6e3a1'],
        'unavailable'   => ['Unavailable', '#f38ba8'],
        'setup'         => ['Awaiting setup', '#f9e2af'],
        'unconfigured'  => ['Unconfigured', '#f9e2af'],
    ];
    [$label, $color] = $labels[$data['status']] ?? ['Unknown', '#a6adc8'];
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>RetroHive — Status</title>
<meta name="robots" content="noindex, nofollow">
<style>
  body { background:#1e1e2e; color:#cdd6f4; font-family:ui-monospace,monospace;
         max-width:32rem; margin:4rem auto; padding:0 1.5rem; }
  h1 { font-size:1.1rem; font-weight:normal; color:#a6adc8; margin:0 0 1.5rem; }
  .status { font-size:1.4rem; margin-bottom:1.5rem; }
  .dot { display:inline-block; width:.7em; height:.7em; border-radius:50%;
         background:<?= $color ?>; margin-right:.5em; }
  dl { margin:0; }
  dt { color:#a6adc8; float:left; width:8rem; clear:left; }
  dd { margin:0 0 .4rem; }
</style>
</head>
<body>
  <h1>RetroHive</h1>
  <div class="status"><span class="dot"></span><?= e($label) ?></div>
  <dl>
    <?php if ($data['database'] !== null): ?>
    <dt>Database</dt><dd><?= e((string) $data['database']) ?></dd>
    <?php endif; ?>
    <dt>Checked</dt><dd><?= e($data['checked_at']) ?></dd>
  </dl>
</body>
</html>
    <?php
}


function status_serve_json(): void
{
    $data = status_data();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($data['status'] === 'unavailable' ? 503 : 200);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
