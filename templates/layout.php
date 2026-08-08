<?php
/** @var string $content */
/** @var string $pageTitle */
// Both pages this layout still wraps are bare, so the flag is what it always is.
// Kept rather than removed: it is what the head and body classes read, and
// hard-coding it would hide why there is no navigation below.
$bare = $bare ?? false;
$flashes = take_flashes();
// $user is no longer read - the navigation that needed it is gone.
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<?php
// Crawlers, told twice.
//
// robots.txt is a request a crawler may honour; a meta tag on the page is the
// one that keeps it out of an index once it is already looking. Sent when the
// instance has asked not to be indexed at all, and always on the pages that let
// somebody in - a registration form has no business in a search result whatever
// the rest of the site has decided.
if (!empty($noindex) || !search_indexing_allowed()):
?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<title><?= e($pageTitle) ?> · <?= e(config('app_name')) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='4' fill='%231e1e2e'/><rect x='6' y='6' width='5' height='20' fill='%23f38ba8'/><rect x='13' y='6' width='5' height='20' fill='%23a6e3a1'/><rect x='20' y='6' width='6' height='20' fill='%2389b4fa'/></svg>">
</head>
<body class="<?= $bare ? 'is-bare' : '' ?>">

<?php
// No navigation.
//
// This application serves two pages now - first-run setup and a 404 - and both
// are bare. The bar that used to be here linked to the entry browser, the manage
// hub and the profile screens, none of which exist any more; drawing it would be
// a menu of dead ends.
//
// The layout is kept rather than folded into the two pages: they share a head, a
// stylesheet and a page frame, and two copies of that is two things to keep in
// step.
?>

<?php
// Notices float above the page rather than pushing it down.
//
// They used to sit in the document, between the header and the content, so "Signed in."
// shifted everything below it and then stayed there for the rest of the visit. A notice
// is about something that just happened, not part of the page - so it is an overlay,
// top right, and it goes away on its own.
//
// The container is always rendered, empty or not: the script raises notices into it too,
// so a save that never reloads can still say so.
//
// role="status" and aria-live="polite" mean a screen reader is told, once, without
// interrupting. Errors are aria-live="assertive" via their own region below.
?>
<?php
// The unread-notification announcement is gone with the notifications page it
// pointed at. Nothing this application still serves has a signed-in reader:
// setup runs before there is an account, and a 404 is a dead end.
?>
<div class="toasts" data-toasts role="status" aria-live="polite">
  <?php foreach ($flashes as $f): ?>
    <?php
    // An error stays until it is dismissed. A success can vanish on its own: missing
    // "Saved." costs nothing, missing "That did not save" costs the work.
    $sticky = ($f['type'] ?? '') === 'error';
    ?>
    <div class="toast toast--<?= e($f['type']) ?>"<?= $sticky ? ' data-toast-sticky' : '' ?>>
      <?php if (!empty($f['link'])): ?>
        <a class="toast__text toast__link" href="<?= e((string) $f['link']) ?>"><?= e($f['message']) ?></a>
      <?php else: ?>
        <span class="toast__text"><?= e($f['message']) ?></span>
      <?php endif; ?>
      <button class="toast__close" type="button" data-toast-close
              aria-label="Dismiss this notice">&times;</button>
    </div>
  <?php endforeach; ?>
</div>

<main class="<?= $bare ? 'shell shell--bare' : 'shell' ?>">
<?= $content ?>
</main>

<?php if (!$bare): ?>
<footer class="footer">
  <span><?= e(config('app_name')) ?> — <?= e(config('app_tagline')) ?></span>
  <?php
    // Two unfiltered COUNT(*)s on every page load, counting soft-deleted rows
    // and entries in libraries the viewer cannot see - so the number quietly
    // reported how much existed elsewhere on the instance. One query now, and
    // it respects the same access rule as everything else.
    [$footerAcl, $footerParams] = library_filter_sql('library_id', ACCESS_VIEWER);
    $footer = one("SELECT COUNT(*) AS n, COALESCE(SUM(image_count), 0) AS photos
                     FROM v_items WHERE $footerAcl", $footerParams) ?? ['n' => 0, 'photos' => 0];
  ?>
  <span class="mono"><?= (int) $footer['n'] ?> entries · <?= (int) $footer['photos'] ?> photos</span>
</footer>
<?php endif; ?>

<script src="<?= e(asset_url('/assets/js/app.js')) ?>" defer></script>
</body>
</html>
