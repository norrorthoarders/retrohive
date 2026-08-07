#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Find and optionally remove uploaded files nothing points at.
 *
 *   php bin/cleanup-uploads.php            list what is orphaned
 *   php bin/cleanup-uploads.php --delete   remove it
 *
 * Files become orphaned when an entry is deleted while its photos are not, or
 * after the installer erases a collection without clearing public/uploads.
 *
 * Reports the reverse case too: rows in the database whose file is missing.
 * That one usually means a restore that skipped the uploads directory, and no
 * amount of deleting will fix it - better to say so than stay quiet.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from a terminal, not a browser.\n");
}

define('APP_ROOT', dirname(__DIR__));
define('BASE_PATH', '');

require APP_ROOT . '/src/helpers.php';
require APP_ROOT . '/src/proxy.php';
require APP_ROOT . '/src/db.php';
require APP_ROOT . '/src/images.php';

$delete = in_array('--delete', $argv, true);

if (!app_is_configured()) {
    exit("No configuration found. Nothing to compare against.\n");
}

$dir = uploads_dir();
if (!is_dir($dir)) {
    exit("No uploads directory at $dir\n");
}

/** Every filename the database expects, without variant prefixes. */
function referenced_filenames(): array
{
    $names = [];
    foreach (['SELECT filename AS f FROM item_images',
              'SELECT logo_filename AS f FROM companies WHERE logo_filename IS NOT NULL',
              'SELECT avatar_filename AS f FROM users WHERE avatar_filename IS NOT NULL',
              // The fourth, missing since category default pictures were added:
              // without it, --delete removed every branch's own picture and left
              // the rows pointing at nothing.
              'SELECT default_image_filename AS f FROM categories WHERE default_image_filename IS NOT NULL'] as $sql) {
        try {
            foreach (all($sql) as $row) {
                if (!empty($row['f'])) {
                    $names[(string) $row['f']] = true;
                }
            }
        } catch (PDOException $e) {
            // A table that does not exist yet simply contributes nothing.
        }
    }
    return $names;
}

$referenced = referenced_filenames();
$prefixes   = ['', 'thumb_', 'disp_'];

$orphans = [];
$bytes   = 0;
foreach (glob($dir . '/*') ?: [] as $path) {
    if (!is_file($path)) {
        continue;
    }
    $base = basename($path);
    if (str_starts_with($base, '.')) {
        continue;                       // .htaccess, .gitkeep
    }

    // Strip a known variant prefix to find the name the database would hold.
    $stem = $base;
    foreach (['thumb_', 'disp_'] as $prefix) {
        if (str_starts_with($base, $prefix)) {
            $stem = substr($base, strlen($prefix));
            break;
        }
    }

    if (!isset($referenced[$stem])) {
        $orphans[] = $path;
        $bytes    += (int) filesize($path);
    }
}

// The other direction: rows whose file has gone.
$missing = [];
foreach (array_keys($referenced) as $name) {
    if (!is_file($dir . '/' . $name)) {
        $missing[] = $name;
    }
}

printf("Uploads directory: %s\n", $dir);
printf("Referenced by the database: %d file(s)\n", count($referenced));
printf("Orphaned on disk: %d file(s), %.1f MB\n", count($orphans), $bytes / 1048576);

if ($missing !== []) {
    printf("\nMissing from disk but still referenced: %d\n", count($missing));
    foreach (array_slice($missing, 0, 10) as $name) {
        echo '  ' . $name . "\n";
    }
    if (count($missing) > 10) {
        echo '  ... and ' . (count($missing) - 10) . " more\n";
    }
    echo "  These will show as broken images. Restoring public/uploads is the fix;\n";
    echo "  deleting the rows would lose the captions and ordering with them.\n";
}

if ($orphans === []) {
    echo "\nNothing to clean up.\n";
    exit(0);
}

echo "\n";
foreach (array_slice($orphans, 0, 20) as $path) {
    echo '  ' . basename($path) . "\n";
}
if (count($orphans) > 20) {
    echo '  ... and ' . (count($orphans) - 20) . " more\n";
}

if (!$delete) {
    echo "\nNothing was deleted. Re-run with --delete to remove them.\n";
    exit(0);
}

$removed = 0;
foreach ($orphans as $path) {
    if (@unlink($path)) {
        $removed++;
    }
}
printf("\nDeleted %d of %d file(s).\n", $removed, count($orphans));
exit($removed === count($orphans) ? 0 : 1);
