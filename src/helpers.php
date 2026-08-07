<?php
declare(strict_types=1);

// image_url() below resolves `stock:` references, and helpers.php is the one
// file every entry point loads - the front controller, the installer, and each
// of the bin/ scripts. Requiring it here rather than listing it in index.php is
// what stops a CLI tool that shows a filename from fataling on a reference it
// was never told about, the same way models.php pulls in rules.php.
require_once __DIR__ . '/stock.php';

/** Read a config key, optionally dotted: config('db.host'). */
function config(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require APP_ROOT . '/src/config.php';
    }
    if ($key === null) {
        return $cfg;
    }
    $node = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return $default;
        }
        $node = $node[$part];
    }
    return $node;
}

/** Escape for HTML output. Use it on every echoed value. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an application URL, honouring installs in a subdirectory. */
function url(string $path = '/', array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    $out = BASE_PATH . ($path === '/' ? '/' : rtrim($path, '/'));
    if ($query !== []) {
        $query = array_filter($query, fn($v) => $v !== null && $v !== '');
        if ($query !== []) {
            $out .= '?' . http_build_query($query);
        }
    }
    return $out === '' ? '/' : $out;
}

/**
 * URL for a static asset, stamped with its modification time.
 *
 * Without this a browser keeps serving the stylesheet it cached before the
 * update, so a deployment looks half-applied: new markup, old CSS. The stamp
 * changes only when the file does, so caching still works between releases.
 */
function asset_url(string $path): string
{
    $path = '/' . ltrim($path, '/');
    $file = APP_ROOT . '/public' . $path;
    $stamp = is_file($file) ? filemtime($file) : null;
    return BASE_PATH . $path . ($stamp ? '?v=' . $stamp : '');
}

/**
 * URL for an uploaded image variant: 'thumb', 'display' or 'orig'.
 *
 * Also resolves a `stock:` reference to one of the pictures that ship with the
 * package. Both kinds go through here on purpose: every caller that shows an
 * image already calls this function with a nullable string, and having the one
 * function understand both meant the fallback chain could be written as "the
 * first of these that is not null" rather than as a branch repeated in a dozen
 * templates. See src/stock.php.
 */
function image_url(?string $filename, string $variant = 'display'): ?string
{
    if ($filename === null || $filename === '') {
        return null;
    }
    $slug = stock_ref_slug($filename);
    if ($slug !== null) {
        return stock_image_url($slug, $variant === 'thumb' ? 'thumb' : 'display');
    }
    $map = ['thumb' => 'thumb_', 'display' => 'disp_', 'orig' => ''];
    $prefix = $map[$variant] ?? '';
    $candidate = config('uploads.dir') . '/' . $prefix . $filename;
    if (!is_file($candidate)) {
        $prefix = '';
    }
    return BASE_PATH . '/uploads/' . rawurlencode($prefix . $filename);
}

/**
 * Did the caller ask for JSON rather than a page?
 *
 * Used where one handler serves both: the form still posts and redirects for anyone
 * without JavaScript, and the same code answers the page's fetch() when there is. One
 * handler, one set of rules, two ways of replying.
 */
function wants_json(): bool
{
    if (($_POST['_format'] ?? $_GET['_format'] ?? '') === 'json') {
        return true;
    }
    return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

/** Answer with JSON and stop. */
function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect(string $path, array $query = [], string $fragment = ''): never
{
    $target = str_starts_with($path, 'http') ? $path : url($path, $query);
    // A fragment, so a redirect can land on the row it just changed rather than at
    // the top of a long page. Not part of the URL the server sees, but the browser
    // honours it on the response to a 303.
    if ($fragment !== '') {
        $target .= '#' . ltrim($fragment, '#');
    }
    header('Location: ' . $target, true, 303);
    exit;
}

/** Queue a one-shot message for the next page load. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// --- CSRF -------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Session expired. Go back, reload the page and try again.');
    }
}

// --- Request input ----------------------------------------------------------

function input(string $key, ?string $default = null): ?string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    if (is_array($v)) {
        return $default;
    }
    if ($v === null) {
        return $default;
    }
    $v = trim((string) $v);
    return $v === '' ? $default : $v;
}

function input_int(string $key, ?int $default = null): ?int
{
    $v = input($key);
    if ($v === null || !is_numeric($v)) {
        return $default;
    }
    return (int) $v;
}

function input_bool(string $key): int
{
    $v = $_POST[$key] ?? null;
    return ($v === '1' || $v === 'on' || $v === 'true') ? 1 : 0;
}

/** Null out empty strings so the column stores NULL rather than ''. */
function nullify($v)
{
    if (is_string($v)) {
        $v = trim($v);
    }
    return ($v === '' || $v === null) ? null : $v;
}

// --- Text -------------------------------------------------------------------

/**
 * A slug, deterministically.
 *
 * The iconv('ASCII//TRANSLIT') fallback that used to sit here is
 * locale-dependent: the same title slugs differently on two servers, and on a
 * machine with LC_ALL=C it turns every accented character into '?'. Since slugs
 * are stored and used as identifiers, that is a difference that outlives the
 * request. The table below is explicit and gives the same answer everywhere.
 */
function slugify(string $text): string
{
    $text = trim($text);
    $map = [
        'å' => 'a', 'ä' => 'a', 'ö' => 'o', 'Å' => 'a', 'Ä' => 'a', 'Ö' => 'o',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ñ' => 'n', 'Ñ' => 'n', 'ç' => 'c', 'Ç' => 'c',
        'ø' => 'o', 'Ø' => 'o', 'æ' => 'ae', 'Æ' => 'ae', 'ß' => 'ss',
        'ð' => 'd', 'Ð' => 'd', 'þ' => 'th', 'Þ' => 'th',
        'ł' => 'l', 'Ł' => 'l', 'ż' => 'z', 'ź' => 'z', 'ś' => 's', 'ć' => 'c', 'ę' => 'e', 'ą' => 'a',
        'č' => 'c', 'š' => 's', 'ž' => 'z', 'ř' => 'r', 'ě' => 'e', 'ů' => 'u',
        '&' => ' and ', '+' => ' plus ',
    ];
    $text = strtr($text, $map);
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');
    return $text === '' ? 'item-' . bin2hex(random_bytes(3)) : $text;
}

/** Make a slug unique within a table by appending -2, -3 ... */
function unique_slug(string $table, string $base, ?int $ignoreId = null): string
{
    $slug = $base;
    $n = 1;
    while (true) {
        $sql = "SELECT id FROM `$table` WHERE slug = ?" . ($ignoreId ? ' AND id <> ?' : '') . ' LIMIT 1';
        $params = $ignoreId ? [$slug, $ignoreId] : [$slug];
        if (one($sql, $params) === null) {
            return $slug;
        }
        $n++;
        $slug = $base . '-' . $n;
    }
}

function truncate(?string $text, int $len = 160): string
{
    $text = trim((string) $text);
    if (mb_strlen($text) <= $len) {
        return $text;
    }
    return mb_substr($text, 0, $len - 1) . '…';
}

// --- Display labels ---------------------------------------------------------

function condition_label(?string $key): string
{
    return [
        'mint'      => 'Mint',
        'near_mint' => 'Near mint',
        'very_good' => 'Very good',
        'good'      => 'Good',
        'fair'      => 'Fair',
        'poor'      => 'Poor',
        'missing'   => 'Missing',
        'unknown'   => 'Not graded',
    ][$key ?? 'unknown'] ?? 'Not graded';
}

function completeness_label(?string $key): string
{
    return [
        'cib'             => 'Complete in box',
        'boxed_no_manual' => 'Boxed, no manual',
        'loose'           => 'Loose media',
        'manual_only'     => 'Manual only',
        'digital'         => 'Digital / image',
        'unknown'         => 'Unrecorded',
    ][$key ?? 'unknown'] ?? 'Unrecorded';
}

function image_kind_label(?string $key): string
{
    return [
        'box_front'  => 'Box front',
        'box_back'   => 'Box back',
        'box_spine'  => 'Spine',
        'media'      => 'Media',
        'manual'     => 'Manual',
        'extras'     => 'Extras',
        'screenshot' => 'Screenshot',
        'unit'       => 'The hardware itself',
        'other'      => 'Other',
    ][$key ?? 'other'] ?? 'Other';
}

function condition_options(): array
{
    return ['unknown', 'mint', 'near_mint', 'very_good', 'good', 'fair', 'poor'];
}

/**
 * What software comes on.
 *
 * Grouped so the list is readable: a boxed Amiga game and a boxed PC game share a
 * shelf but not a medium. Free text would have been easier and would have produced
 * "3.5"", "3.5 inch" and "DD floppy" in the same library.
 */
function media_options(): array
{
    return [
        'Cartridges and cards' => [
            'Cartridge', 'ROM cartridge', 'Memory card', 'Flash cart',
        ],
        'Floppy disks' => [
            '3-inch disk', '3.5-inch disk', '5.25-inch disk', '8-inch disk',
        ],
        'Tape' => [
            'Cassette', 'Data tape',
        ],
        'Optical' => [
            'CD-ROM', 'DVD-ROM', 'Blu-ray', 'GD-ROM', 'MiniDisc',
        ],
        'Other' => [
            'Hard disk', 'USB stick', 'Download', 'Paper listing',
        ],
    ];
}

/** The same list flattened, for validating what a form sent back. */
function media_option_values(): array
{
    $out = [];
    foreach (media_options() as $group) {
        foreach ($group as $medium) {
            $out[] = $medium;
        }
    }
    return $out;
}

/** Component grades add "missing", which the overall grade has no use for. */
function component_condition_options(): array
{
    return ['unknown', 'mint', 'near_mint', 'very_good', 'good', 'fair', 'poor', 'missing'];
}

function status_options(): array
{
    // No lending, and now nothing left of it. The columns and the enum value
    // outlived the feature for a while, which meant the API still accepted
    // lent_to and a client could set a status this list would not offer -
    // half-removed being worse than either, since it is a feature nobody can
    // reach and everybody has to keep working around. Migration 0026 finished it.
    return ['owned', 'wishlist', 'ordered', 'sold'];
}

function status_label(?string $key): string
{
    return [
        'owned'    => 'Owned',
        'wishlist' => 'Wanted',
        'ordered'  => 'On order',
        'sold'     => 'Sold',
    ][$key ?? 'owned'] ?? 'Owned';
}

function completeness_options(): array
{
    return ['unknown', 'cib', 'boxed_no_manual', 'loose', 'manual_only', 'digital'];
}

function image_kind_options(): array
{
    // 'unit' is the hardware itself. A photograph of a motherboard is not a box
    // front, which is what the Amiga Hardware Database scraper had to call it.
    return ['box_front', 'box_back', 'box_spine', 'media', 'manual', 'extras',
            'screenshot', 'unit', 'other'];
}

function money(?float $amount, ?string $currency = null): string
{
    if ($amount === null) {
        return '—';
    }
    return number_format($amount, 2, ',', ' ') . ' ' . ($currency ?: config('currency'));
}

// --- Rendering --------------------------------------------------------------

/**
 * Render a template with a set of variables.
 *
 * The parameter is `$template`, not `$view`, and that is not cosmetic:
 * extract() is called with EXTR_SKIP, so any variable a caller passes whose name
 * matches a local here is silently dropped. This parameter used to be `$view`,
 * which meant a template asking for `$view` got the template's own path -
 * 'items/hardware' - instead of what the controller passed.
 *
 * The symptom was a listing mode that could never be selected: the software
 * browser had a row view reachable only by ?view=list, and ?view=list did
 * nothing, because the comparison was against a path. Nothing failed; the wrong
 * branch simply always won.
 */
function render(string $template, array $vars = []): void
{
    $vars['pageTitle'] = $vars['pageTitle'] ?? config('app_name');
    extract($vars, EXTR_SKIP);
    ob_start();
    require APP_ROOT . '/templates/' . $template . '.php';
    $content = ob_get_clean();
    require APP_ROOT . '/templates/layout.php';
}

function config_local_path(): string
{
    return APP_ROOT . '/src/config.local.php';
}

/**
 * Is there a usable local configuration?
 *
 * Returns 'present', 'unreadable', 'missing', or 'env' when the settings come
 * from environment variables instead of a file. Deliberately does not touch the
 * database - this runs on every request.
 */
function config_state(): string
{
    $path = config_local_path();
    if (is_file($path)) {
        return is_readable($path) ? 'present' : 'unreadable';
    }
    return getenv('DB_HOST') !== false && getenv('DB_NAME') !== false ? 'env' : 'missing';
}

function app_is_configured(): bool
{
    return in_array(config_state(), ['present', 'env'], true);
}

function installer_path(): string
{
    return APP_ROOT . '/public/install.php';
}

/** Include a reusable template fragment from templates/partials/. */
function partial(string $name, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require APP_ROOT . '/templates/partials/' . $name . '.php';
}

/**
 * Response headers that apply to every page.
 *
 * The uploads directory has had a Content-Security-Policy since the beginning;
 * the application itself had none. Nothing here loads from a CDN and there are
 * no inline event handlers, so 'self' is enough - the inline styles in the
 * admin templates are why style-src is looser than script-src, and only that.
 */
/**
 * A random token, once per request, naming the inline scripts this page trusts.
 *
 * The policy is script-src 'self' with no 'unsafe-inline', which is the right setting
 * and also a trap: an inline <script> is not blocked loudly, it simply never runs. That
 * cost four rounds of debugging once already - a handler was moved to the bottom of
 * app.js, then the top, then inlined, and inlining is what finally proved the cause by
 * stopping it working entirely.
 *
 * So inline script is possible now, but only deliberately: it has to carry this nonce.
 * A tag without one still silently fails, which is why tests/hardening.php refuses any
 * <script> in a template that has not asked for the nonce - the mistake is caught by
 * the suite rather than by somebody's afternoon.
 */
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
    return $nonce;
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    // same-origin leaks the full path on same-origin navigation; this sends the
    // origin alone cross-site and nothing at all when downgrading to http.
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // The header version of "do not index me".
    //
    // A meta tag is only read by something that parses the page; this is read by
    // everything, including whatever fetches a URL somebody pasted into a chat.
    // Sent for the whole instance when it has asked not to be found, which is
    // the default - what this catalogues is the contents of somebody's flat.
    if (function_exists('search_indexing_allowed') && !search_indexing_allowed()) {
        header('X-Robots-Tag: noindex, nofollow');
    }
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
        // blob: as well as data:.
        //
        // Every preview in this application is an object URL - the photo cards on
        // the entry forms, and the circle you drag over a picture to choose your
        // avatar - and URL.createObjectURL() produces a blob: URL. Without this
        // the browser refused to load them and the card said "no preview", which
        // read as a broken thumbnail rather than as a policy doing its job. The
        // avatar cropper never appeared at all, because it waits for the image to
        // load and the load never happened.
        //
        // It costs little: a blob: URL can only be made by script already running
        // on this origin, so this does not admit anything a remote image source
        // would.
        . "img-src 'self' data: blob:; "
        . "script-src 'self' 'nonce-" . csp_nonce() . "'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'; "
        . "object-src 'none'; "
        . "base-uri 'none'"
    );
}

function not_found(string $message = 'Nothing here.'): never
{
    http_response_code(404);
    render('404', ['pageTitle' => 'Not found', 'message' => $message]);
    exit;
}

/** Build a query string preserving current filters but overriding some keys. */
function with_query(array $overrides): string
{
    $q = array_merge($_GET, $overrides);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return $q === [] ? '' : '?' . http_build_query($q);
}

// ---------------------------------------------------------------------------
// Instance settings
//
// Separate from config(): that reads a file an administrator edits over SSH,
// this reads a table they edit in a browser. The split is on purpose - anything
// that could lock somebody out if it were wrong, notably the database
// credentials, stays in the file where a broken value cannot hide the screen
// that would fix it.
// ---------------------------------------------------------------------------

function setting(string $name, ?string $default = null): ?string
{
    if (!isset($GLOBALS['__settings'])) {
        $GLOBALS['__settings'] = [];
        try {
            foreach (all('SELECT name, value FROM settings') as $row) {
                $GLOBALS['__settings'][(string) $row['name']] = $row['value'];
            }
        } catch (Throwable $e) {
            // Before the table exists - during an install, or on an older
            // database - every setting is simply its default.
            $GLOBALS['__settings'] = [];
        }
    }
    $value = $GLOBALS['__settings'][$name] ?? null;
    return ($value === null || $value === '') ? $default : (string) $value;
}

/**
 * Names whose value must never reach a log.
 *
 * Matched as substrings, so smtp_password, metadata_api_key and ldap_bind_pw are all
 * covered without listing them - and anything added later that is named honestly is
 * covered the day it is added.
 */
function setting_is_secret(string $name): bool
{
    foreach (['password', 'passwd', '_pw', 'secret', 'token', 'api_key', 'apikey', 'private'] as $needle) {
        if (str_contains(strtolower($name), $needle)) {
            return true;
        }
    }
    return false;
}

function set_setting(string $name, ?string $value): void
{
    $name = mb_substr($name, 0, 120);

    // What it was, before it stops being that.
    //
    // Configuration changes are logged here rather than in each screen that saves one.
    // There are forty-odd call sites across notifications, metadata, templates and the
    // instance settings, and a screen added next year would have been the one nobody
    // remembered - "log it where it happens" only works if there is one place it
    // happens, and this is that place.
    $before = $GLOBALS['__settings'][$name] ?? null;
    if ($before === null && !isset($GLOBALS['__settings'])) {
        $row    = one('SELECT value FROM settings WHERE name = ?', [$name]);
        $before = $row === null ? null : (string) $row['value'];
    }

    q('INSERT INTO settings (name, value) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE value = VALUES(value)',
      [$name, $value]);
    $GLOBALS['__settings'][$name] = $value;

    // Only actual changes. A settings form posts every field it owns, so logging each
    // save wholesale would bury the one line that mattered under thirty that did not.
    if ((string) $before === (string) $value) {
        return;
    }

    // Secrets are recorded as having changed, never as what they changed to. Knowing
    // the SMTP password was replaced on Tuesday is the useful half; the password itself
    // in a log that gets forwarded to a syslog server is a liability.
    $secret = setting_is_secret($name);
    $short  = function (?string $v): string {
        if ($v === null || $v === '') {
            return '(unset)';
        }
        return mb_strlen($v) > 60 ? mb_substr($v, 0, 57) . '…' : $v;
    };

    if (function_exists('log_event')) {
        log_event('server', 'setting.changed',
            $secret
                ? sprintf('Setting "%s" changed', $name)
                : sprintf('Setting "%s" changed from %s to %s',
                          $name, $short($before), $short($value)),
            LOG_NOTICE,
            $secret
                ? ['setting' => $name, 'secret' => true]
                : ['setting' => $name, 'from' => $short($before), 'to' => $short($value)]);
    }
}

// ---------------------------------------------------------------------------
// Form errors
//
// A message at the top of a long form is a message beside the wrong thing.
// These carry the complaint back to the field it is about, and carry what was
// typed with it - being told "too short" and finding the box empty again is
// two problems where there was one.
//
// Held in the session for exactly one request, like a flash, because the thing
// that validates and the thing that renders are on opposite sides of a
// redirect.
// ---------------------------------------------------------------------------

/**
 * Refuse a submission and send it back to the form.
 *
 * @param array<string,string> $errors  field name => what is wrong with it
 */
function form_failed(string $path, array $errors, array $query = []): void
{
    $_SESSION['form_errors'] = $errors;
    // Everything that was typed, minus the things that must never be put back
    // on a page: a password is retyped, not redisplayed.
    $_SESSION['form_old'] = array_diff_key(
        $_POST,
        // '_csrf' is the field's real name; 'csrf' matched nothing, so the token
        // was being carried back into the session as form state.
        array_flip(['password', 'password_confirm', 'new_password', 'new_password_confirm',
                    'current_password', 'smtp_password', 'api_key', '_csrf', 'csrf'])
    );
    flash('error', count($errors) === 1
        ? reset($errors)
        : 'That could not be saved. ' . count($errors) . ' fields need attention.');
    redirect($path, $query);
}

/** What is wrong with one field, if anything. */
function form_error(string $field): ?string
{
    return $GLOBALS['__form_errors'][$field] ?? null;
}

/** `class="field"` plus an error state where there is one. */
function field_class(string $field, string $base = 'field'): string
{
    return form_error($field) === null ? $base : $base . ' field--error';
}

/** The hint under a field: the complaint if there is one, otherwise the usual text. */
function field_hint(string $field, string $normal = ''): string
{
    $error = form_error($field);
    if ($error !== null) {
        return '<span class="hint" style="color:var(--bad)">' . e($error) . '</span>';
    }
    return $normal === '' ? '' : '<span class="hint">' . $normal . '</span>';
}

/** What was typed last time, for putting the form back as it was. */
function old(string $field, $fallback = '')
{
    return $GLOBALS['__form_old'][$field] ?? $fallback;
}

/** Called once per request, before rendering, to take them out of the session. */
function take_form_state(): void
{
    $GLOBALS['__form_errors'] = $_SESSION['form_errors'] ?? [];
    $GLOBALS['__form_old']    = $_SESSION['form_old']    ?? [];
    unset($_SESSION['form_errors'], $_SESSION['form_old']);
}
