<?php
/** @var string $content */
/** @var string $pageTitle */
$bare = $bare ?? false;
$flashes = take_flashes();
$user = current_user();
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

<?php if (!$bare): ?>
<header class="topbar">
  <div class="topbar__inner">
    <a class="wordmark" href="<?= e(url('/')) ?>">
      <span class="wordmark__spines" aria-hidden="true"><i></i><i></i><i></i></span>
      <span class="wordmark__text"><?= e(config('app_name')) ?></span>
    </a>

    <nav class="mainnav" aria-label="Sections">
      <?php
        // Which library everything on the page is about. Carried across the
        // section links so switching Software to Hardware does not silently
        // widen the view back out, and read by Manage so editing locations or
        // models happens in one library rather than in all of them at once.
        $navLibrary = trim((string) ($_GET['library'] ?? ''));
        if ($navLibrary === '' && current_user() !== null) {
            // The library they are working in, not their personal one - so a page
            // reached without ?library= keeps showing where they actually are.
            $mine = working_library();
            $navLibrary = $mine === null ? '' : (string) $mine['slug'];
        }
        $navQuery      = $navLibrary === '' ? [] : ['library' => $navLibrary];
        // The shelves you have taken on, not everything you may read.
        //
        // A published library used to appear here the moment somebody made one, which
        // reads as having been added to something you never agreed to. Joining is a
        // choice now; reading one you have not joined still works by link.
        $navLibraries  = current_user() === null ? [] : joined_libraries();
        // Accept an id as well as a slug. The options are keyed by slug, so a URL
        // carrying ?library=3 used to match nothing and the browser showed the first
        // option - which reads as the app having switched libraries behind your back.
        // Normalising here means one stray redirect cannot cause that.
        if ($navLibrary !== '' && ctype_digit($navLibrary)) {
            foreach ($navLibraries as $navL) {
                if ((int) $navL['id'] === (int) $navLibrary) {
                    $navLibrary = (string) $navL['slug'];
                    break;
                }
            }
        }
      ?>
      <a href="<?= e(url('/collection', $navQuery)) ?>">Collection</a>
      <a href="<?= e(url('/software', $navQuery)) ?>">Software</a>
      <a href="<?= e(url('/hardware', $navQuery)) ?>">Hardware</a>
      <?php // Titles are reached through Software, which is where somebody
            // looking for a game actually goes. A separate entry made the bar
            // longer and answered the same question twice. ?>
      <?php
      // Only while you are working in a library you may arrange.
      //
      // This was on the bar for everybody, so a read-only member of somebody
      // else's shelf saw Manage and was refused at every screen behind it. It
      // follows the library selector: switch to one you curate and it comes
      // back, which is the behaviour that was asked for and the behaviour the
      // gate on those screens already had.
      ?>
      <?php if (can_manage_library()): ?>
        <a href="<?= e(url('/manage', $navQuery)) ?>">Manage</a>
      <?php endif; ?>

      <?php if (count($navLibraries) > 0): ?>
        <?php // Which library you are working in. Everything downstream reads
              // it, so it belongs where it is always visible rather than
              // repeated as a filter on each page. ?>
        <form method="get" action="" class="libraryswitch" data-library-switch>
          <?php foreach ($_GET as $k => $v): if ($k === 'library' || !is_scalar($v)) continue; ?>
            <input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>">
          <?php endforeach; ?>
          <label class="visually-hidden" for="nav-library">Library</label>
          <select id="nav-library" name="library">
            <?php foreach ($navLibraries as $lib): ?>
              <option value="<?= e($lib['slug']) ?>" <?= $navLibrary === $lib['slug'] ? 'selected' : '' ?>>
                <?= e($lib['name']) ?>
              </option>
            <?php endforeach; ?>
            <?php // No "everything" here. Collection is that view, and having
                  // both meant two ways to say the same thing that could
                  // disagree with each other. ?>
          </select>
          <noscript><button class="btn btn--sm" type="submit">Go</button></noscript>
        </form>
        <?php
        // Edit and New sit beside the switcher rather than inside it. A select is
        // for choosing which library you are working in; an option that navigates
        // somewhere else instead is a different kind of thing wearing the same
        // clothes, and picking it by accident with the keyboard would change the
        // page rather than the scope.
        //
        // Outside the form as well, or they would submit it.
        $navCurrent = null;
        foreach ($navLibraries as $lib) {
            if ($navLibrary === $lib['slug']) {
                $navCurrent = $lib;
                break;
            }
        }
        ?>
        <?php
        // Two separate pages, not one form with a hidden id. Editing goes straight
        // to this library; creating goes to the page that asks what a new one should
        // start out holding, which is a question only creation can answer.
        ?>
        <span class="libraryactions">
          <?php if ($navCurrent !== null && can_own_library((int) $navCurrent['id'])): ?>
            <a class="btn btn--sm" title="Edit <?= e((string) $navCurrent['name']) ?>"
               href="<?= e(url('/libraries/' . (int) $navCurrent['id'] . '/edit')) ?>">Edit</a>
          <?php endif; ?>
          <a class="btn btn--sm" title="Create a library"
             href="<?= e(url('/libraries/new')) ?>">Create</a>
        </span>
      <?php endif; ?>
    </nav>

    <?php
    // Searching from the software or hardware browser stays there.
    //
    // The header box is the same control on every page, so it searched everything from
    // everywhere - which is right on the dashboard and wrong two clicks into Software,
    // where the answer came back full of machines. The domain travels with the query
    // when there is one to travel.
    // From the page being rendered, not from the query string: /software carries its
    // domain in the route rather than in a parameter, so reading $_GET would have kept
    // the filter only when somebody had already typed it into the URL.
    $searchDomain = (string) ($domain ?? ($_GET['domain'] ?? ''));
    if (!in_array($searchDomain, ['software', 'hardware'], true)) {
        $searchDomain = '';
    }
    ?>
    <form class="quicksearch" method="get" action="<?= e(url('/items')) ?>" role="search">
      <?php if ($searchDomain !== ''): ?>
        <input type="hidden" name="domain" value="<?= e($searchDomain) ?>">
      <?php endif; ?>
      <input type="search" name="q"
             placeholder="<?= $searchDomain === 'software' ? 'Search software' : ($searchDomain === 'hardware' ? 'Search hardware' : 'Search titles, studios, catalog no.') ?>"
             value="<?= e($_GET['q'] ?? '') ?>"
             aria-label="<?= $searchDomain === '' ? 'Search the collection' : 'Search ' . e($searchDomain) ?>">
    </form>

    <div class="topbar__actions">
      <?php if (can_edit()): ?>
        <a class="btn btn--accent" href="<?= e(url('/items/new')) ?>">Add title</a>
      <?php endif; ?>
      <?php if ($user): ?>
        <details class="usermenu">
          <summary aria-label="Your account">
            <?php partial('avatar', ['user' => $user, 'size' => 'sm']); ?>
          </summary>
          <div class="usermenu__panel">
            <div class="usermenu__who">
              <?php partial('avatar', ['user' => $user, 'size' => 'md']); ?>
              <span>
                <strong><?= e($user['display_name'] ?: $user['username']) ?></strong>
                <span class="usermenu__role"><?= e(ucfirst($user['role'])) ?></span>
              </span>
            </div>
            <?php // Yours, then the instance's. Two different jobs, and running
                  // them together made it easy to reach for one meaning the
                  // other. ?>
            <span class="usermenu__label">My profile</span>
            <a href="<?= e(url('/profile')) ?>">Your profile</a>
            <?php $unread = unread_notification_count(); ?>
            <a href="<?= e(url('/notifications')) ?>">
              Notifications<?php if ($unread > 0): ?>
                <span class="chip chip--count"><?= $unread ?></span>
              <?php endif; ?>
            </a>
            <?php
            // One entry, not two. "Your libraries" and "Library access" listed the same
            // shelves from two angles - what you own and what you can reach - and the
            // second already covered both, including the ones you have been invited to.
            ?>
            <a href="<?= e(url('/profile/access')) ?>">Library access</a>
            <a href="<?= e(url('/profile/notifications')) ?>">Notification settings</a>
            <a href="<?= e(url('/profile/tokens')) ?>">App access</a>
            <?php
            // Maintenance is here for everybody, not only administrators: half
            // the jobs are about one library and belong to whoever holds it.
            // Administrators get it again under Server, where the instance-wide
            // ones are.
            ?>
            <?php // Maintenance is an administrator's page now. The jobs about one
                  // library moved into that library's editor, which is where
                  // somebody holding one already goes. ?>

            <?php
            // Administrators only, and this one really is.
            //
            // Instance settings, user management, the logs, every library on the
            // instance: none of it belongs to a library, and none of it is a
            // curator's business. I widened this to can_manage_library() while
            // fixing the *Manage* menu - which is library work and was correctly
            // widened - and took this block with it by mistake, so anybody who
            // owned a private library was shown a Server section they would be
            // refused at every link.
            ?>
            <?php if (is_admin()): ?>
              <?php // Everything that configures the instance is one page with
                    // tabs. User management keeps its own, because it is a list
                    // of people rather than a set of switches. ?>
              <span class="usermenu__label">Server</span>
              <a href="<?= e(url('/admin/settings')) ?>">Instance settings</a>
              <a href="<?= e(url('/manage/users')) ?>">User management</a>
              <?php // Every library on the instance, with the switches an administrator
                    // has over them. It existed and was reachable only by typing the
                    // address. ?>
              <a href="<?= e(url('/manage/libraries')) ?>">Library management</a>
              <a href="<?= e(url('/admin/logs')) ?>">Logs</a>
              <a href="<?= e(url('/manage/maintenance')) ?>">Maintenance</a>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/logout')) ?>">
              <?= csrf_field() ?>
              <button type="submit" class="linkish">Sign out</button>
            </form>
          </div>
        </details>
      <?php else: ?>
        <a class="btn" href="<?= e(url('/login')) ?>">Sign in</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<?php endif; ?>

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
// Anything unread, raised once as a notice you can click.
//
// The count in the profile menu is easy to miss - it is behind a click, in a corner,
// and unchanged whether one thing arrived or twenty. This says so where notices go, and
// takes you to the page when clicked rather than telling you to go there.
//
// Once per visit, not once per page: a notice that reappears on every navigation is an
// interruption rather than a message. The session flag is cleared when the notifications
// page is opened, so a later arrival announces itself again.
//
// Never on the notifications page itself: you are already looking at them, and
// announcing them there re-set the flag on the very request that had just cleared it -
// so a later arrival was never announced again.
$onNotifications = str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), BASE_PATH . '/notifications');
$unreadNow = (current_user() === null || $onNotifications) ? 0 : unread_notification_count();
if ($unreadNow > 0 && empty($_SESSION['unread_announced'])) {
    $_SESSION['unread_announced'] = true;
    $flashes[] = [
        'type'    => 'ok',
        'message' => $unreadNow === 1
            ? 'You have an unread notification.'
            : sprintf('You have %d unread notifications.', $unreadNow),
        'link'    => url('/notifications'),
    ];
}
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
