<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

/**
 * Work out the prefix the app is mounted under, so it runs equally well at the
 * document root and in a subdirectory like /retrohive.
 *
 * Deriving this from dirname(SCRIPT_NAME) is tempting but wrong: some servers
 * set SCRIPT_NAME to the requested path when that path looks like a static file
 * (PHP's built-in server does this for /items/export.csv), which would make the
 * prefix "/items" and break the route. Only trust SCRIPT_NAME when it actually
 * points at the front controller.
 */
$override = getenv('APP_BASE_PATH');
if ($override !== false && $override !== '') {
    $basePath = rtrim('/' . trim($override, '/'), '/');
} else {
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $basePath = str_ends_with($script, '/index.php')
        ? substr($script, 0, -strlen('/index.php'))
        : '';
}
define('BASE_PATH', $basePath === '/' ? '' : $basePath);

require APP_ROOT . '/src/helpers.php';
require APP_ROOT . '/src/proxy.php';
require APP_ROOT . '/src/db.php';
require APP_ROOT . '/src/auth.php';
require APP_ROOT . '/src/throttle.php';
require APP_ROOT . '/src/acl.php';
require APP_ROOT . '/src/log.php';
require APP_ROOT . '/src/structure.php';
require APP_ROOT . '/src/notify.php';
// Who may make an account, and whether search engines are welcome.
require APP_ROOT . '/src/registration.php';
require APP_ROOT . '/src/ldap.php';
require APP_ROOT . '/src/metadata.php';
require APP_ROOT . '/src/version.php';
require APP_ROOT . '/src/migrate.php';
require APP_ROOT . '/src/images.php';
require APP_ROOT . '/src/models.php';   // which pulls in src/rules.php
require APP_ROOT . '/src/maintenance.php';
require APP_ROOT . '/src/settings_schema.php';
require APP_ROOT . '/src/api.php';
// Six controllers are gone with the screens they drew - dashboard, browse,
// taxonomy, titles, locations and notifications. What remains of the other four
// is what the API and the surviving endpoints call: first-run setup, the status
// and robots handlers, CSV export, and the import parser the API shares.
require APP_ROOT . '/src/controllers/items.php';
require APP_ROOT . '/src/controllers/account.php';
require APP_ROOT . '/src/controllers/registration.php';
require APP_ROOT . '/src/controllers/import.php';
require APP_ROOT . '/src/controllers/api.php';
require APP_ROOT . '/src/controllers/api_admin.php';

date_default_timezone_set((string) config('timezone', 'UTC'));

// Must run before sessions start or any URL is built.
apply_proxy_headers();

if (config('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
    set_exception_handler(function (Throwable $e): void {
        crash_page(retrovault_record_crash($e, 'error.uncaught'));
    });

    // Fatals do not reach an exception handler.
    //
    // A missing function, exhausted memory, a parse error in an included file:
    // none of them throw, so the handler above never sees them and the instance's
    // own log said nothing at all about the loudest failures it has. This runs on
    // the way out and reports the ones that ended the request.
    register_shutdown_function(function (): void {
        $last = error_get_last();
        if ($last === null || !in_array($last['type'] ?? 0,
                [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        $ref = retrovault_record_crash(
            new ErrorException((string) $last['message'], 0, (int) $last['type'],
                               (string) $last['file'], (int) $last['line']),
            'error.fatal');
        // Only if nothing has been sent yet: a fatal halfway through a page has
        // already written half of it, and appending a second document to that is
        // worse than leaving it truncated.
        if (!headers_sent()) {
            crash_page($ref);
        }
    });
}

// The health check, answered before anything else happens.
//
// It has to come before the session and before the gate, because both of those
// reach for the database - and a check that cannot answer while the database is
// unwell is a check that reports nothing at the exact moment somebody needs to
// know. Its own connection, its own short timeout, and no session at all.
// GET, HEAD and OPTIONS, because a checker picks whichever it likes.
//
// pfSense's HAProxy page recommends OPTIONS as the usual method for server
// checks, and answering only GET would have failed those checks for a reason
// that has nothing to do with health - the request would fall through to the
// gate and be redirected to a sign-in page. Which is the same class of mistake
// this endpoint exists to end.
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)
    && rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/')
       === rtrim(BASE_PATH . '/healthz', '/')) {
    health_serve();
    exit;
}

start_session();
// Errors and what was typed, taken out of the session for this one request.
take_form_state();

send_security_headers();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri    = rawurldecode(is_string($uri) ? $uri : '/');
if (BASE_PATH !== '' && str_starts_with($uri, BASE_PATH)) {
    $uri = substr($uri, strlen(BASE_PATH));
}
$path = '/' . trim($uri, '/');

// --- Not configured yet ---------------------------------------------------
// NOTE: nothing above this line may touch the database. current_user() does, and
// having it here meant an unconfigured instance died on the connection instead of
// being sent to the installer - the one case this block exists for.
// Send first-time visitors to the installer instead of letting them hit a
// database error. The check is a file stat, not a connection, so it costs
// nothing on a working install.
if (!app_is_configured() && $path !== '/install.php') {
    $state         = config_state();
    $haveInstaller = is_file(installer_path());

    if (str_starts_with($path, '/api/')) {
        api_cors();
        api_error(
            'not_configured',
            $state === 'unreadable'
                ? 'src/config.local.php exists but the web server cannot read it.'
                : 'RetroHive is not configured yet. Open /install.php in a browser.',
            503
        );
    }

    if ($haveInstaller && $state !== 'unreadable') {
        header('Location: ' . BASE_PATH . '/install.php', true, 302);
        exit;
    }

    // Either the installer is gone, or the config is present but unreadable -
    // which the installer cannot fix for you, since the fix is a shell command.
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    $file = htmlspecialchars(config_local_path(), ENT_QUOTES);
    $user = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
        ? (string) (@posix_getpwuid(posix_geteuid())['name'] ?? 'the web server user')
        : 'the web server user';
    echo '<!doctype html><meta charset="utf-8"><title>Not configured</title>'
       . '<body style="font-family:system-ui;background:#1e1e2e;color:#cdd6f4;padding:3rem;line-height:1.6">';
    if ($state === 'unreadable') {
        echo '<h1>Configuration is unreadable</h1>'
           . '<p><code>' . $file . '</code> exists, but ' . htmlspecialchars($user, ENT_QUOTES)
           . ' cannot read it.</p>'
           . '<pre style="background:#11111b;padding:1rem;border-radius:6px">'
           . 'chgrp www ' . $file . "\nchmod 640 " . $file . '</pre>'
           . '<p style="color:#a6adc8">Every directory on the path also needs +x for that group.</p>';
    } else {
        echo '<h1>RetroHive is not configured</h1>'
           . '<p>No <code>' . $file . '</code>, and <code>public/install.php</code> is not present.</p>'
           . '<p>Copy <code>src/config.local.php.example</code> to <code>src/config.local.php</code> '
           . 'and fill in the database settings, or restore the installer.</p>';
    }
    echo '</body>';
    exit;
}

// --- Database behind the code ---------------------------------------------
// New files copied over an existing install will reference columns the database
// does not have yet. Say so plainly instead of failing later mid-page.
if (!str_starts_with($path, '/update.php') && !schema_is_current()) {
    if (str_starts_with($path, '/api/')) {
        api_cors();
        api_error(
            'update_required',
            'The database schema is behind this version of the software. Run bin/migrate.php or open /update.php.',
            503
        );
    }
    if (is_file(APP_ROOT . '/public/update.php')) {
        header('Location: ' . BASE_PATH . '/update.php', true, 302);
        exit;
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Update required</title>'
       . '<body style="font-family:system-ui;background:#1e1e2e;color:#cdd6f4;padding:3rem;line-height:1.6">'
       . '<h1>Database update required</h1>'
       . '<p>The files were updated but the database has not caught up.</p>'
       . '<pre style="background:#11111b;padding:1rem;border-radius:6px">php bin/migrate.php status'
       . "\nphp bin/migrate.php up</pre></body>";
    exit;
}

// --- API ------------------------------------------------------------------
// Handled before the browser gate: an unauthenticated API call must get a JSON
// 401, never a redirect to the sign-in page.
if (str_starts_with($path, '/api/')) {
    api_cors();

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    // Some clients cannot send PATCH or DELETE; let them tunnel it.
    $override = strtoupper((string) ($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ''));
    if ($method === 'POST' && in_array($override, ['PATCH', 'PUT', 'DELETE'], true)) {
        $method = $override;
    }

    $apiRoutes = [
        ['GET',    '#^/api/v1/meta$#',                    fn() => api_meta()],
        ['POST',   '#^/api/v1/auth/login$#',              fn() => api_login()],
        ['POST',   '#^/api/v1/auth/verify$#',             fn() => api_auth_verify()],
        ['POST',   '#^/api/v1/auth/verify/resend$#',      fn() => api_auth_verify_resend()],
        ['GET',    '#^/api/v1/auth/register/status$#',    fn() => api_auth_register_status()],
        ['POST',   '#^/api/v1/auth/register$#',           fn() => api_auth_register()],
        ['POST',   '#^/api/v1/auth/logout$#',             fn() => api_logout()],
        ['GET',    '#^/api/v1/auth/me$#',                 fn() => api_me()],

        // Your own account, and the instance's - the screens a native client
        // used to have to send somebody to a browser for.
        ['GET',    '#^/api/v1/profile$#',                 fn() => api_profile_show()],
        ['PATCH',  '#^/api/v1/profile$#',                 fn() => api_profile_update()],
        ['GET',    '#^/api/v1/profile/notifications$#',   fn() => api_notification_prefs_show()],
        ['PATCH',  '#^/api/v1/profile/notifications$#',   fn() => api_notification_prefs_update()],
        ['GET',    '#^/api/v1/admin/settings$#',          fn() => api_settings_show()],
        ['PATCH',  '#^/api/v1/admin/settings$#',          fn() => api_settings_update()],
        ['GET',    '#^/api/v1/items/(\d+)/links/candidates$#', fn($id) => api_item_links_candidates((int) $id)],
        ['GET',    '#^/api/v1/items/(\d+)/links$#',        fn($id) => api_item_links_index((int) $id)],
        ['POST',   '#^/api/v1/items/(\d+)/links$#',        fn($id) => api_item_links_create((int) $id)],
        ['DELETE', '#^/api/v1/items/(\d+)/links/(\d+)$#',   fn($id, $l) => api_item_links_delete((int) $id, (int) $l)],
        ['POST',   '#^/api/v1/items/(\d+)/images/import$#', fn($id) => api_item_images_import((int) $id)],
        ['POST',   '#^/api/v1/profile/avatar$#',          fn() => api_profile_avatar_upload()],
        ['DELETE', '#^/api/v1/profile/avatar$#',          fn() => api_profile_avatar_delete()],
        ['GET',    '#^/api/v1/admin/libraries$#',         fn() => api_admin_libraries_index()],
        ['POST',   '#^/api/v1/admin/libraries$#',         fn() => api_admin_libraries_create()],
        ['PATCH',  '#^/api/v1/admin/libraries/(\d+)$#',    fn($id) => api_admin_libraries_update((int) $id)],
        ['DELETE', '#^/api/v1/admin/libraries/(\d+)$#',    fn($id) => api_admin_libraries_delete((int) $id)],
        ['GET',    '#^/api/v1/admin/users$#',             fn() => api_users_index()],
        ['POST',   '#^/api/v1/admin/users$#',             fn() => api_users_create()],
        ['PATCH',  '#^/api/v1/admin/users/(\d+)$#',        fn($id) => api_users_update((int) $id)],
        ['DELETE', '#^/api/v1/admin/users/(\d+)$#',        fn($id) => api_users_delete((int) $id)],
        ['GET',    '#^/api/v1/admin/users/(\d+)/access$#', fn($id) => api_user_access_show((int) $id)],
        ['PUT',    '#^/api/v1/admin/users/(\d+)/access$#', fn($id) => api_user_access_update((int) $id)],
        ['PATCH',  '#^/api/v1/admin/users/(\d+)/access$#', fn($id) => api_user_access_update((int) $id)],
        ['GET',    '#^/api/v1/admin/logs$#',              fn() => api_logs_index()],
        ['GET',    '#^/api/v1/admin/status$#',            fn() => api_admin_status()],
        ['GET',    '#^/api/v1/admin/maintenance$#',       fn() => api_maintenance_index()],
        ['POST',   '#^/api/v1/admin/maintenance/([a-z_]+)$#',
                                                          fn($job) => api_maintenance_run((string) $job)],

        ['GET',    '#^/api/v1/tokens$#',                  fn() => api_tokens_index()],
        ['POST',   '#^/api/v1/tokens$#',                  fn() => api_tokens_create()],
        ['DELETE', '#^/api/v1/tokens/(\d+)$#',            fn($id) => api_tokens_revoke((int) $id)],

        ['GET',    '#^/api/v1/items$#',                   fn() => api_items_index()],
        ['GET',    '#^/api/v1/items/random$#',            fn() => api_items_random()],
        ['POST',   '#^/api/v1/items/bulk$#',              fn() => api_items_bulk()],
        ['GET',    '#^/api/v1/barcode/([A-Za-z0-9._-]+)$#', fn($code) => api_barcode_lookup((string) $code)],
        ['GET',    '#^/api/v1/metadata/search$#',        fn() => api_metadata_search()],
        ['POST',   '#^/api/v1/metadata/preview$#',       fn() => api_metadata_preview()],
        ['POST',   '#^/api/v1/metadata/apply$#',         fn() => api_metadata_apply()],
        ['POST',   '#^/api/v1/items$#',                   fn() => api_items_create()],
        ['GET',    '#^/api/v1/items/(\d+)$#',             fn($id) => api_items_show((int) $id)],
        ['PATCH',  '#^/api/v1/items/(\d+)$#',             fn($id) => api_items_update((int) $id)],
        ['PUT',    '#^/api/v1/items/(\d+)$#',             fn($id) => api_items_update((int) $id)],
        ['DELETE', '#^/api/v1/items/(\d+)$#',             fn($id) => api_items_delete((int) $id)],

        ['GET',    '#^/api/v1/items/(\d+)/images$#',      fn($id) => api_item_images_index((int) $id)],
        ['POST',   '#^/api/v1/items/(\d+)/images$#',      fn($id) => api_item_images_upload((int) $id)],
        ['PATCH',  '#^/api/v1/images/(\d+)$#',            fn($id) => api_images_update((int) $id)],
        ['DELETE', '#^/api/v1/images/(\d+)$#',            fn($id) => api_images_delete((int) $id)],

        ['GET',    '#^/api/v1/models$#',                  fn() => api_models_index()],
        ['GET',    '#^/api/v1/libraries$#',               fn() => api_libraries_index()],
        ['POST',   '#^/api/v1/libraries$#',               fn() => api_libraries_create()],
        ['GET',    '#^/api/v1/profile/libraries$#',       fn() => api_profile_libraries()],
        ['POST',   '#^/api/v1/libraries/(\d+)/join$#',    fn($id) => api_libraries_join((int) $id)],
        ['POST',   '#^/api/v1/libraries/(\d+)/leave$#',   fn($id) => api_libraries_leave((int) $id)],
        ['POST',   '#^/api/v1/libraries/(\d+)/invite/(accept|decline)$#',
                                                            fn($id, $a) => api_library_invite_respond((int) $id, $a)],
        ['POST',   '#^/api/v1/libraries/(\d+)/ownership/offer$#',
                                                          fn($id) => api_library_offer_ownership((int) $id)],
        ['POST',   '#^/api/v1/libraries/(\d+)/ownership/(accept|decline|withdraw)$#',
                                                            fn($id, $a) => api_library_ownership_respond((int) $id, $a)],
        ['PATCH',  '#^/api/v1/libraries/(\d+)$#',          fn($id) => api_libraries_update((int) $id)],
        ['PUT',    '#^/api/v1/libraries/(\d+)$#',          fn($id) => api_libraries_update((int) $id)],
        ['DELETE', '#^/api/v1/libraries/(\d+)$#',          fn($id) => api_libraries_delete((int) $id)],
        ['GET',    '#^/api/v1/libraries/(\d+)/contents$#', fn($id) => api_library_contents((int) $id)],
        ['GET',    '#^/api/v1/libraries/(\d+)/structure-status$#',
                                                            fn($id) => api_libraries_structure_status((int) $id)],
        ['POST',   '#^/api/v1/libraries/(\d+)/populate$#', fn($id) => api_libraries_populate((int) $id)],
        ['POST',   '#^/api/v1/admin/libraries/(\d+)/disable$#', fn($id) => api_libraries_disable((int) $id)],
        ['POST',   '#^/api/v1/admin/libraries/(\d+)/enable$#',  fn($id) => api_libraries_enable((int) $id)],
        ['POST',   '#^/api/v1/admin/libraries/(\d+)/owner$#',   fn($id) => api_libraries_force_owner((int) $id)],
        ['POST',   '#^/api/v1/admin/libraries/(\d+)/purge$#',   fn($id) => api_libraries_purge((int) $id)],
        ['POST',   '#^/api/v1/admin/example-library$#',    fn() => api_admin_example_library_create()],
        ['GET',    '#^/api/v1/directory$#',               fn() => api_directory_index()],
        ['GET',    '#^/api/v1/libraries/(\d+)/members$#',  fn($id) => api_library_members_index((int) $id)],
        ['POST',   '#^/api/v1/libraries/(\d+)/members$#',  fn($id) => api_library_members_invite((int) $id)],
        ['PATCH',  '#^/api/v1/libraries/(\d+)/members/(\d+)$#',
                                                            fn($id, $mid) => api_library_members_update((int) $id, (int) $mid)],
        ['DELETE', '#^/api/v1/libraries/(\d+)/members/(\d+)$#',
                                                            fn($id, $mid) => api_library_members_delete((int) $id, (int) $mid)],
        ['GET',    '#^/api/v1/admin/metadata-providers$#', fn() => api_metadata_providers_index()],
        ['POST',   '#^/api/v1/admin/metadata-providers$#', fn() => api_metadata_providers_create()],
        ['PATCH',  '#^/api/v1/admin/metadata-providers/(\d+)$#',
                                                            fn($id) => api_metadata_providers_update((int) $id)],
        ['DELETE', '#^/api/v1/admin/metadata-providers/(\d+)$#',
                                                            fn($id) => api_metadata_providers_delete((int) $id)],
        ['GET',    '#^/api/v1/admin/auth-methods$#',       fn() => api_auth_methods_index()],
        ['POST',   '#^/api/v1/admin/auth-methods$#',       fn() => api_auth_methods_create()],
        ['POST',   '#^/api/v1/admin/auth-methods/test$#',  fn() => api_auth_methods_test()],
        ['POST',   '#^/api/v1/admin/auth-methods/inspect$#', fn() => api_auth_methods_inspect()],
        ['PATCH',  '#^/api/v1/admin/auth-methods/(\d+)$#', fn($id) => api_auth_methods_update((int) $id)],
        ['DELETE', '#^/api/v1/admin/auth-methods/(\d+)$#', fn($id) => api_auth_methods_delete((int) $id)],
        ['GET',    '#^/api/v1/admin/auth-methods/(\d+)/groups$#',
                                                            fn($id) => api_auth_group_maps_index((int) $id)],
        ['POST',   '#^/api/v1/admin/auth-methods/(\d+)/groups$#',
                                                            fn($id) => api_auth_group_maps_create((int) $id)],
        ['DELETE', '#^/api/v1/admin/auth-methods/(\d+)/groups/(\d+)$#',
                                                            fn($id, $mapId) => api_auth_group_maps_delete((int) $id, (int) $mapId)],
        ['GET',    '#^/api/v1/admin/update$#',             fn() => api_admin_update_show()],
        ['POST',   '#^/api/v1/admin/update/check$#',       fn() => api_admin_update_check()],
        ['GET',    '#^/api/v1/admin/system-status$#',      fn() => api_admin_system_status()],
        ['GET',    '#^/api/v1/admin/registration$#',       fn() => api_registration_show()],
        ['PATCH',  '#^/api/v1/admin/registration$#',       fn() => api_registration_update()],
        ['GET',    '#^/api/v1/platforms$#',               fn() => api_platforms_index()],
        ['POST',   '#^/api/v1/platforms$#',               fn() => api_platforms_create()],
        ['PATCH',  '#^/api/v1/platforms/(\d+)$#',          fn($id) => api_platforms_update((int) $id)],
        ['PUT',    '#^/api/v1/platforms/(\d+)$#',          fn($id) => api_platforms_update((int) $id)],
        ['DELETE', '#^/api/v1/platforms/(\d+)$#',          fn($id) => api_platforms_delete((int) $id)],
        ['GET',    '#^/api/v1/titles$#',                  fn() => api_titles_index()],
        ['POST',   '#^/api/v1/titles$#',                  fn() => api_titles_create()],
        ['GET',    '#^/api/v1/titles/(\d+)$#',            fn($id) => api_titles_show((int) $id)],
        ['PATCH',  '#^/api/v1/titles/(\d+)$#',            fn($id) => api_titles_update((int) $id)],
        ['PUT',    '#^/api/v1/titles/(\d+)$#',            fn($id) => api_titles_update((int) $id)],
        ['DELETE', '#^/api/v1/titles/(\d+)$#',            fn($id) => api_titles_delete((int) $id)],

        ['GET',    '#^/api/v1/locations$#',               fn() => api_locations_index()],
        ['POST',   '#^/api/v1/locations$#',               fn() => api_locations_create()],
        ['PATCH',  '#^/api/v1/locations/(\d+)$#',          fn($id) => api_locations_update((int) $id)],
        ['PUT',    '#^/api/v1/locations/(\d+)$#',          fn($id) => api_locations_update((int) $id)],
        ['DELETE', '#^/api/v1/locations/(\d+)$#',          fn($id) => api_locations_delete((int) $id)],
        // Pictures waiting for a decision. Avatars are an instance matter and
        // sit under /admin; a library's photographs belong to the library.
        ['POST',   '#^/api/v1/admin/users/(\d+)/avatar/approve$#', fn($id) => api_avatar_decide((int) $id, true)],
        ['POST',   '#^/api/v1/admin/users/(\d+)/avatar/reject$#',  fn($id) => api_avatar_decide((int) $id, false)],
        ['GET',    '#^/api/v1/libraries/(\d+)/pending-images$#',   fn($id) => api_library_pending_images((int) $id)],
        ['POST',   '#^/api/v1/images/(\d+)/approve$#',             fn($id) => api_image_decide((int) $id, true)],
        ['POST',   '#^/api/v1/images/(\d+)/reject$#',              fn($id) => api_image_decide((int) $id, false)],
        // Proving that mail actually arrives, which saving the settings does not.
        ['GET',    '#^/api/v1/admin/mail$#',              fn() => api_mail_status()],
        ['POST',   '#^/api/v1/admin/mail/send-code$#',    fn() => api_mail_send_code()],
        ['POST',   '#^/api/v1/admin/mail/confirm-code$#', fn() => api_mail_confirm_code()],
        ['POST',   '#^/api/v1/companies/(\d+)/logo$#',    fn($id) => api_companies_logo((int) $id)],
        ['DELETE', '#^/api/v1/companies/(\d+)/logo$#',    fn($id) => api_companies_logo((int) $id)],
        ['POST',   '#^/api/v1/admin/logs/prune$#',        fn() => api_logs_prune()],
        ['GET',    '#^/api/v1/libraries/(\d+)/invitable$#', fn($id) => api_library_invitable((int) $id)],
        ['POST',   '#^/api/v1/admin/logo/(small|large)$#', fn($w) => api_instance_logo($w)],
        ['DELETE', '#^/api/v1/admin/logo/(small|large)$#', fn($w) => api_instance_logo($w)],
        ['GET',    '#^/api/v1/prefs$#',                   fn() => api_prefs_index()],
        ['PATCH',  '#^/api/v1/prefs$#',                   fn() => api_prefs_update()],
        ['POST',   '#^/api/v1/prefs$#',                   fn() => api_prefs_update()],
        ['GET',    '#^/api/v1/stock-images$#',            fn() => api_stock_images_index()],
        ['GET',    '#^/api/v1/categories$#',              fn() => api_categories_index()],
        ['POST',   '#^/api/v1/categories$#',              fn() => api_categories_create()],
        ['PATCH',  '#^/api/v1/categories/(\d+)$#',         fn($id) => api_categories_update((int) $id)],
        ['PUT',    '#^/api/v1/categories/(\d+)$#',         fn($id) => api_categories_update((int) $id)],
        ['POST',   '#^/api/v1/categories/(\d+)/image$#',   fn($id) => api_category_image_upload((int) $id)],
        ['DELETE', '#^/api/v1/categories/(\d+)/image$#',   fn($id) => api_category_image_delete((int) $id)],
        ['POST',   '#^/api/v1/categories/(\d+)/move$#',    fn($id) => api_categories_move((int) $id)],
        ['DELETE', '#^/api/v1/categories/(\d+)$#',         fn($id) => api_categories_delete((int) $id)],
        ['GET',    '#^/api/v1/companies$#',               fn() => api_companies_index()],
        ['POST',   '#^/api/v1/companies$#',               fn() => api_companies_create()],
        ['GET',    '#^/api/v1/companies/(\d+)$#',         fn($id) => api_companies_show((int) $id)],
        ['PATCH',  '#^/api/v1/companies/(\d+)$#',         fn($id) => api_companies_update((int) $id)],
        ['PUT',    '#^/api/v1/companies/(\d+)$#',         fn($id) => api_companies_update((int) $id)],
        ['DELETE', '#^/api/v1/companies/(\d+)$#',         fn($id) => api_companies_delete((int) $id)],
        ['GET',    '#^/api/v1/people$#',                  fn() => api_people_index()],
        ['POST',   '#^/api/v1/people$#',                  fn() => api_people_create()],
        ['PATCH',  '#^/api/v1/people/(\d+)$#',             fn($id) => api_people_update((int) $id)],
        ['PUT',    '#^/api/v1/people/(\d+)$#',             fn($id) => api_people_update((int) $id)],
        ['DELETE', '#^/api/v1/people/(\d+)$#',             fn($id) => api_people_delete((int) $id)],

        ['GET',    '#^/api/v1/credit-roles$#',             fn() => api_credit_roles_index()],
        ['POST',   '#^/api/v1/credit-roles$#',             fn() => api_credit_roles_create()],
        ['PATCH',  '#^/api/v1/credit-roles/(\d+)$#',        fn($id) => api_credit_roles_update((int) $id)],
        ['PUT',    '#^/api/v1/credit-roles/(\d+)$#',        fn($id) => api_credit_roles_update((int) $id)],
        ['DELETE', '#^/api/v1/credit-roles/(\d+)$#',        fn($id) => api_credit_roles_delete((int) $id)],

        ['GET',    '#^/api/v1/environments$#',            fn() => api_environments_index()],
        ['POST',   '#^/api/v1/environments$#',            fn() => api_environments_create()],
        ['PATCH',  '#^/api/v1/environments/(\d+)$#',        fn($id) => api_environments_update((int) $id)],
        ['PUT',    '#^/api/v1/environments/(\d+)$#',        fn($id) => api_environments_update((int) $id)],
        ['DELETE', '#^/api/v1/environments/(\d+)$#',        fn($id) => api_environments_delete((int) $id)],

        ['GET',    '#^/api/v1/hardware-models$#',          fn() => api_hardware_models_index()],
        ['POST',   '#^/api/v1/hardware-models$#',          fn() => api_hardware_models_create()],
        ['GET',    '#^/api/v1/hardware-models/(\d+)$#',      fn($id) => api_hardware_models_show((int) $id)],
        ['PATCH',  '#^/api/v1/hardware-models/(\d+)$#',      fn($id) => api_hardware_models_update((int) $id)],
        ['PUT',    '#^/api/v1/hardware-models/(\d+)$#',      fn($id) => api_hardware_models_update((int) $id)],
        ['DELETE', '#^/api/v1/hardware-models/(\d+)$#',      fn($id) => api_hardware_models_delete((int) $id)],

        ['GET',    '#^/api/v1/software-models$#',          fn() => api_software_models_index()],
        ['POST',   '#^/api/v1/software-models$#',          fn() => api_software_models_create()],
        ['GET',    '#^/api/v1/software-models/(\d+)$#',      fn($id) => api_software_models_show((int) $id)],
        ['PATCH',  '#^/api/v1/software-models/(\d+)$#',      fn($id) => api_software_models_update((int) $id)],
        ['PUT',    '#^/api/v1/software-models/(\d+)$#',      fn($id) => api_software_models_update((int) $id)],
        ['DELETE', '#^/api/v1/software-models/(\d+)$#',      fn($id) => api_software_models_delete((int) $id)],

        ['POST',   '#^/api/v1/import$#',                    fn() => api_import_run()],

        ['GET',    '#^/api/v1/credits$#',                 fn() => api_credits_index()],
        ['POST',   '#^/api/v1/credits$#',                 fn() => api_credits_create()],
        ['DELETE', '#^/api/v1/credits/(\d+)$#',            fn($id) => api_credits_delete((int) $id)],

        ['GET',    '#^/api/v1/tags$#',                    fn() => api_tags_index()],
        ['POST',   '#^/api/v1/tags$#',                    fn() => api_tags_create()],
        ['PATCH',  '#^/api/v1/tags/(\d+)$#',               fn($id) => api_tags_update((int) $id)],
        ['PUT',    '#^/api/v1/tags/(\d+)$#',               fn($id) => api_tags_update((int) $id)],
        ['DELETE', '#^/api/v1/tags/(\d+)$#',               fn($id) => api_tags_delete((int) $id)],
        // Every other type api_taxonomy_create() used to handle -
        // platforms, categories, companies, tags - now has its own
        // dedicated, correctly-permissioned route registered ahead of
        // this one. 'libraries' was never valid here either - the
        // function itself has always refused that type outright
        // ("Create libraries through the web interface; they carry
        // membership.") - and the real POST /api/v1/libraries route
        // above already handles it correctly. Nothing reaches this
        // generic endpoint validly any more; kept only as the function
        // definition itself, not as a route, in case a future taxonomy
        // type is ever added the same generic way.

        ['GET',    '#^/api/v1/stats$#',                   fn() => api_stats()],
        ['GET',    '#^/api/v1/notifications$#',           fn() => api_notifications_index()],
        ['POST',   '#^/api/v1/notifications/read$#',      fn() => api_notifications_read()],
        ['GET',    '#^/api/v1/sync$#',                    fn() => api_sync()],
    ];

    // A shutdown function, not a wrapper around the handler call below:
    // api_error() and api_ok() both end in exit(), so anything written
    // to run *after* $handler(...$m) here would never run for an error
    // response - which is most of what's worth counting. A shutdown
    // function runs regardless of how the script ends, normal return or
    // exit() alike, so http_response_code() is read once, correctly,
    // no matter which path got there.
    $requestStart = microtime(true);
    $matchedPattern = null;
    register_shutdown_function(function () use (&$matchedPattern, $method, $requestStart) {
        if ($matchedPattern === null) {
            return;
        }
        $status = http_response_code();
        api_record_request_stat($method, $matchedPattern, $status === false ? 200 : $status,
                                 (microtime(true) - $requestStart) * 1000);
    });

    $pathMatched = [];
    foreach ($apiRoutes as [$verb, $pattern, $handler]) {
        if (preg_match($pattern, $path, $m)) {
            $pathMatched[] = $verb;
            if ($verb !== $method) {
                continue;
            }
            $matchedPattern = $pattern;
            array_shift($m);
            $handler(...$m);
            exit;
        }
    }

    if ($pathMatched !== []) {
        $allow = implode(', ', array_unique(array_merge($pathMatched, ['OPTIONS'])));
        header('Allow: ' . $allow);
        api_error('method_not_allowed', "That endpoint accepts $allow.", 405);
    }

    api_error('not_found', 'No API endpoint at ' . $method . ' ' . $path . '.', 404);
}

// Gate the whole app unless anonymous browsing is enabled.
//
// The ways in have to be reachable by somebody who is not in yet, which is the
// whole of what they are for. Without this the registration routes answered 303
// to /login before they were ever reached - the mode said open, the route
// existed, and a stranger still could not get to it.
//
// Two of them carry a token, so this is a prefix test rather than a list of
// exact paths. The token decides nothing here: it is checked properly by the
// route, against the mode, and a wrong one answers 404 like everything else.
// Paths reachable without a session. /login and /register went with the screens
// they were - what is left is first-run setup and the things other services ask.
$open = ['/setup', '/robots.txt', '/healthz', '/status', '/status.json', '/status/debug'];
$openPrefixes = ['/join/', '/invite/'];
$isOpenPath = in_array($path, $open, true);
foreach ($openPrefixes as $prefix) {
    if (str_starts_with($path, $prefix)) {
        $isOpenPath = true;
        break;
    }
}
if (!config('public_browse') && !is_logged_in() && !$isOpenPath) {
    if (user_count() === 0) {
        redirect('/setup');
    }
    redirect('/login', ['next' => $path]);
}

// Record which library the person is working in, once per request.
//
// Here, not in the bootstrap: everything above has established that a database exists
// and the schema is current, which current_user() needs. Placing it earlier meant an
// unconfigured instance died connecting instead of being sent to the installer.
//
// working_library() both reads ?library= and remembers it, but it used to be called
// only where that parameter was *absent* - so the one moment it could learn something
// was the one moment nobody asked it, and every page without ?library= snapped back to
// the personal shelf. Asking unconditionally is what makes the choice stick.
if (is_logged_in()) {
    working_library();
}

$routes = [

    ['GET',  '#^/items/export\.csv$#',       fn() => items_export_csv()],

    // Collection is the overview, not a page of links.
    //
    // browse_index() was a landing page offering ways in - by platform, by category, by
    // decade - which is a menu about the catalogue rather than a view of it. The
    // dashboard already answers "what have I got", so that is what the Collection tab
    // shows; the ways in are the filters on the browser itself.
    // The old name still answers, so a bookmark does not break.
    // /browse pointed at browse_index(), which was removed with its template - a live
    // route calling a function that does not exist, so the address answered with a
    // fatal rather than a 404. Collection is the overview now.
    // Creating and editing a library are two separate pages, registered before the
    // browse view so the more specific paths win - routes are matched in order.
    // Joining and leaving a published shelf. Its own route rather than an action on the
    // edit form: you are not editing the library, you are deciding whether it is yours.
    // Handing a library over: an offer, an answer, and a way to take it back.

    // App access lists the signed-in user's own tokens, so it belongs with the
    // profile. The old path still works so existing links do not break.
    // One library page, at the address people were sent to.
    //
    // There were three: /libraries (a summary), /profile/access (a flat list of
    // everything reachable) and the tabbed access page - and the tabs landed on the one
    // nobody linked to. They are the same question asked three ways, so this is now the
    // tabbed page and /libraries redirects here.
    // What each machine runs. Structure like platforms and categories, so it lives
    // with them rather than with the software being catalogued.
    // Manufacturers and Developers were one table shown twice, filtered by the `makes`
    // column - so a firm that built machines and published games appeared on whichever
    // screen you were on, and one whose tag was wrong appeared on neither. One screen
    // now, with the tags visible and editable on the row.
    // Manage on its own lands on libraries, which is where most trips here go.
    // Manage is the catalogue. Libraries are not part of it: who may read a
    // shelf is account administration, and landing on it made Manage look like
    // it was about permissions.
    // The first entry in the bar, so clicking Manage lands where the eye
    // already is rather than one along.
    // Locations first, not companies.
    //
    // /manage/vendors redirects to the companies list, so pressing Manage landed
    // on a screen about firms. Locations is the first thing in the menu and the
    // first thing somebody setting up a library actually needs - where their
    // things are.
    // Old path, kept so a bookmark still answers.
    // Library management is its own page now, not the tail of the access page.
    // Checks anybody with a library can run, and repairs for the instance that
    // only an administrator can. One page, because "what is wrong with this" is
    // one question whoever is asking it.
    // The administrator's own two screens. Separate routes rather than modes of the
    // owner's editor, because they are a different job with a different audience.
    // One source in full, because a card cannot carry fifty-six platform chips.
    // Candidate artwork, proxied so the content policy can stay as it is.
    // One source's own page. Before the generic /manage/<type> route, or that
    // would answer for it first.

    // The three ways in. Which doors open is decided in one place, so a mode
    // cannot be shut on one route and ajar on another - and the token routes
    // answer 404 rather than "wrong token", because telling those apart tells
    // somebody probing which instance they are looking at.

    // Generated, so it can answer for the settings as they are now rather than
    // as they were when somebody last edited a file.

    // Public and unauthenticated, on purpose - see status_data()'s own
    // comment for what that does and does not mean it discloses.
    ['GET',  '#^/status$#',                  fn() => status_serve_html()],
    ['GET',  '#^/status\.json$#',            fn() => status_serve_json()],
    ['GET',  '#^/status/debug$#',            fn() => status_serve_debug()],


    // The root sends people to the interface.
    //
    // This application serves an API and two pages, neither of which is a
    // destination - somebody typing the bare address wants the catalogue, and
    // before the interface was removed they got it here. A 404 at the root of a
    // running instance reads as "this is broken", not "you are one hop away".
    //
    // First run wins: an instance with no administrator has one thing worth
    // doing, and sending somebody to a client they cannot sign in to helps
    // nobody.
    ['GET',  '#^/$#',                        fn() => user_count() === 0 ? redirect('/setup') : to_client()],

    ['GET',  '#^/robots\.txt$#',              fn() => robots_serve()],

    // First run only. auth_setup() refuses once an administrator exists, so this
    // is not a door left open - it is the one screen that has to work before
    // there is anybody to authenticate.
    ['GET',  '#^/setup$#',                   fn() => auth_setup_form()],
    ['POST', '#^/setup$#',                   fn() => auth_setup()],
];

foreach ($routes as [$verb, $pattern, $handler]) {
    if ($verb !== $method) {
        continue;
    }
    if (preg_match($pattern, $path, $m)) {
        array_shift($m);
        $handler(...$m);
        exit;
    }
}

not_found('No page at ' . $path . '.');
