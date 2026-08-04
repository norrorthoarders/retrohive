<?php
/**
 * Router for PHP's built-in web server, for local development only:
 *
 *   php -S 127.0.0.1:8080 -t public public/router.php
 *
 * Apache and nginx do not use this file - they rewrite to index.php directly.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . '/' . ltrim((string) $path, '/');

if ($path !== '/' && is_file($file)) {
    return false; // let the built-in server serve CSS, JS and uploaded photos
}

require __DIR__ . '/index.php';
