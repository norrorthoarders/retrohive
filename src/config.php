<?php
declare(strict_types=1);

/**
 * Configuration. Every value can come from an environment variable, which is
 * what the Docker setup uses. For a plain LAMP install, copy
 * config.local.php.example to config.local.php and edit it there instead.
 */

function env_str(string $key, string $default = ''): string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        return $default;
    }
    return $v;
}

function env_int(string $key, int $default = 0): int
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : (int) $v;
}

$config = [
    'app_name'    => env_str('APP_NAME', 'RetroHive'),
    'app_tagline' => env_str('APP_TAGLINE', 'Retro software collection'),
    'currency'    => env_str('APP_CURRENCY', 'SEK'),
    'timezone'    => env_str('APP_TIMEZONE', 'Europe/Stockholm'),
    'debug'       => env_str('APP_DEBUG', '0') === '1',

    // 0 = sign-in required to see anything (default).
    // 1 = anyone can browse, sign-in only needed to change things.
    'public_browse' => env_str('APP_PUBLIC_BROWSE', '0') === '1',

    'db' => [
        'host'    => env_str('DB_HOST', '127.0.0.1'),
        'port'    => env_int('DB_PORT', 3306),
        'name'    => env_str('DB_NAME', 'retrohive'),
        'user'    => env_str('DB_USER', 'retrohive'),
        'pass'    => env_str('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],

    'uploads' => [
        'dir'        => APP_ROOT . '/public/uploads',
        'max_bytes'  => env_int('UPLOAD_MAX_BYTES', 12 * 1024 * 1024),
        // Decoded pixels, which is what actually decides how much memory GD
        // wants. 80 MP passes anything a camera or a flatbed scanner produces
        // and stops a compression bomb well short of the PHP memory limit.
        'max_pixels' => env_int('UPLOAD_MAX_PIXELS', 80000000),
        'thumb_px'   => 480,
        'display_px' => 1600,
        'allowed'    => [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ],
    ],

    'per_page' => env_int('APP_PER_PAGE', 24),

    // Absolute URL clients should use, e.g. https://retro.example.se
    // Leave empty to detect it from the request. Set it if you sit behind a
    // reverse proxy that rewrites Host or the scheme.
    'base_url' => env_str('APP_BASE_URL', ''),

    // Addresses or CIDR ranges of reverse proxies whose X-Forwarded-* headers
    // may be believed. Anything not listed here is treated as a direct client
    // and its forwarded headers ignored, because they are trivial to forge.
    'trusted_proxies' => array_values(array_filter(
        array_map('trim', explode(',', env_str('TRUSTED_PROXIES', '')))
    )),

    'api' => [
        // Browser origins allowed to call the API, as exact origins.
        //
        // Empty by default, which sends no Access-Control-Allow-Origin at all.
        // Native clients ignore CORS entirely and are unaffected; same-origin
        // calls from this instance's own pages never needed it either. It used to
        // default to '*', which is safe as far as it goes - a wildcard forbids
        // credentialed requests, so no browser would attach the session cookie -
        // but it advertised every endpoint to every page on the internet and
        // invited exactly one configuration mistake: adding a real origin later
        // without noticing the wildcard was still in the list.
        //
        // Set API_CORS_ORIGINS to a comma-separated list for a browser front end.
        // '*' still works if that is genuinely wanted.
        'cors_origins' => array_values(array_filter(
            array_map('trim', explode(',', env_str('API_CORS_ORIGINS', '')))
        )),
        // Days before a token issued by /api/v1/auth/login expires. 0 = never.
        'token_days' => env_int('API_TOKEN_DAYS', 0),
    ],
];

// Load the local overrides if we can. A missing or unreadable file is not a
// fatal error here: the front controller detects it and sends the visitor to
// the installer, which can explain the problem, rather than dying with a stack
// trace before anything is even bootstrapped.
$local = APP_ROOT . '/src/config.local.php';
if (is_file($local) && is_readable($local)) {
    /** @var array $override */
    $override = @require $local;
    if (is_array($override)) {
        $config = array_replace_recursive($config, $override);
    }
}

return $config;
