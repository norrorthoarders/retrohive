<?php
/** @var array $totals @var array $byLibrary @var array $byPlatform @var array $byCategory @var array $byDecade */
/** @var array $recent @var array $topRated */
/** @var int $noPhotos @var int $noYear @var int $noDev @var int $imageTot */
$owned = (int) ($totals['owned'] ?? 0);
$maxLibrary  = max(1, (int) max(array_column($byLibrary, 'n') ?: [1]));
$maxPlatform = max(1, (int) max(array_column($byPlatform, 'n') ?: [1]));
?>

<?php
// The empty state used to be an early return that replaced the whole page.
//
// Which was right when the whole instance was empty and wrong the moment there
// were tabs: picking an empty shelf from the tab strip took the tab strip away
// with it, so the way back to the other shelves disappeared exactly when
// somebody needed it. The head and the tabs are drawn first now, and the message
// sits where the charts would be.
$shelfEmpty = $owned === 0 && (int) ($totals['items'] ?? 0) === 0;
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Collection overview</span>
    <h1><?= e(config('app_name')) ?></h1>
  </div>
  <div class="pagehead__actions">
    <a class="btn" href="<?= e(url('/items', ['sort' => 'added'])) ?>">Recently added</a>
    <a class="btn" href="<?= e(url('/items', ['photos' => 'none'])) ?>">Missing photos</a>
  </div>
</div>

<?php
// A tab per shelf, and one for all of them.
//
// The totals across every library you can reach are the right default - that is what
// "your collection" means when you have more than one - but a shared club shelf and a
// private collection are different collections, and adding them up answers a question
// nobody asked. Each tab narrows the whole page, not just the counts.
//
// Links, not script: three filtered views of one page, and a tab that only works with
// JavaScript is a link that sometimes does nothing.
?>
<?php if (count($tabLibs ?? []) > 1): ?>
  <nav class="tabs" aria-label="Which library">
    <a href="<?= e(url('/collection')) ?>"
       class="tabs__tab <?= ($tabCurrent ?? '') === '' ? 'is-current' : '' ?>">Everything</a>
    <?php foreach ($tabLibs as $tl): ?>
      <a href="<?= e(url('/collection', ['library' => (string) $tl['slug']])) ?>"
         class="tabs__tab <?= ($tabCurrent ?? '') === (string) $tl['slug'] ? 'is-current' : '' ?>">
        <?= e((string) $tl['name']) ?>
      </a>
    <?php endforeach; ?>
  </nav>
<?php endif; ?>

<?php if ($shelfEmpty): ?>
  <div class="empty">
    <h2><?= ($tabCurrent ?? '') === '' ? 'The shelf is empty' : 'Nothing on this shelf yet' ?></h2>
    <p>
      Add your first title and the catalogue starts building itself: counts per
      library, ratings, decade spread and the list of studios.
    </p>
    <?php if (can_edit()): ?>
      <a class="btn btn--accent" href="<?= e(url('/items/new')) ?>">Add the first title</a>
      <?php // Only when there is nowhere to put anything. With shelves already
            // set up, sending somebody to the platform editor answers a question
            // they have not asked. ?>
      <?php if ($byLibrary === []): ?>
        <a class="btn" href="<?= e(url('/manage/platforms')) ?>">Set up libraries first</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php else: ?>

<div class="stats">
  <div class="stat">
    <span class="stat__n"><?= $owned ?></span>
    <span class="stat__label">Titles owned</span>
  </div>
  <div class="stat">
    <span class="stat__n"><?= count($byLibrary) ?></span>
    <span class="stat__label"><?= count($byLibrary) === 1 ? 'Library' : 'Libraries' ?></span>
  </div>
  <div class="stat">
    <span class="stat__n"><?= $imageTot ?></span>
    <span class="stat__label">Packaging photos</span>
  </div>
  <div class="stat">
    <span class="stat__n"><?= $totals['avg_rating'] ? number_format((float) $totals['avg_rating'], 1) : '—' ?></span>
    <span class="stat__label">Average rating</span>
  </div>
  <div class="stat">
    <span class="stat__n"><?= $totals['earliest'] ? (int) $totals['earliest'] . '–' . (int) $totals['latest'] : '—' ?></span>
    <span class="stat__label">Years covered</span>
  </div>
  <?php if (!empty($totals['value'])): ?>
  <div class="stat">
    <span class="stat__n" style="font-size:1.3rem"><?= e(money((float) $totals['value'])) ?></span>
    <span class="stat__label">Estimated value</span>
  </div>
  <?php endif; ?>
  <?php if ((int) ($totals['wanted'] ?? 0) > 0): ?>
  <div class="stat">
    <span class="stat__n"><?= (int) $totals['wanted'] ?></span>
    <span class="stat__label">On the wishlist</span>
  </div>
  <?php endif; ?>
</div>

<div class="cols cols--main">
  <div>
    <?php if ($recent): ?>
      <section class="panel">
        <h2 class="panel__title">Latest additions</h2>
        <div class="grid">
          <?php foreach ($recent as $it) partial('card', ['it' => $it]); ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($topRated): ?>
      <section class="panel">
        <h2 class="panel__title">Rated highest</h2>
        <div class="shelf">
          <?php foreach ($topRated as $it) partial('shelf_row', ['it' => $it]); ?>
        </div>
      </section>
    <?php endif; ?>
  </div>

  <aside>
    <?php if ($byLibrary): ?>
    <section class="panel">
      <h2 class="panel__title">By library</h2>
      <div class="shelfbar">
        <?php foreach ($byLibrary as $l): ?>
          <div class="shelfbar__row" style="--spine: <?= e($l['accent_color']) ?>">
            <a class="shelfbar__name" href="<?= e(url('/items', ['library' => $l['slug']])) ?>"><?= e($l['name']) ?></a>
            <span class="shelfbar__track"><span class="shelfbar__fill" style="width: <?= round((int) $l['n'] / $maxLibrary * 100) ?>%"></span></span>
            <span class="shelfbar__n"><?= (int) $l['n'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($byPlatform): ?>
    <section class="panel">
      <h2 class="panel__title">By platform</h2>
      <div class="shelfbar">
        <?php foreach ($byPlatform as $p): ?>
          <div class="shelfbar__row" style="--spine: <?= e($p['accent_color']) ?>">
            <a class="shelfbar__name" href="<?= e(url('/items', ['platform' => $p['slug']])) ?>"><?= e($p['name']) ?></a>
            <span class="shelfbar__track"><span class="shelfbar__fill" style="width: <?= round((int) $p['n'] / $maxPlatform * 100) ?>%"></span></span>
            <span class="shelfbar__n"><?= (int) $p['n'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>


    <?php if ($byCategory): ?>
    <section class="panel">
      <h2 class="panel__title">By software type</h2>
      <div class="chips">
        <?php foreach ($byCategory as $c): ?>
          <a class="chip" href="<?= e(url('/items', ['category' => $c['slug']])) ?>"><?= e($c['name']) ?> · <?= (int) $c['n'] ?></a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($byDecade): ?>
    <section class="panel">
      <h2 class="panel__title">By decade</h2>
      <?php $maxDec = max(1, (int) max(array_column($byDecade, 'n'))); ?>
      <div class="shelfbar">
        <?php foreach ($byDecade as $d): ?>
          <div class="shelfbar__row" style="--spine: var(--accent-2)">
            <a class="shelfbar__name mono" href="<?= e(url('/items', ['decade' => (int) $d['decade']])) ?>"><?= (int) $d['decade'] ?>s</a>
            <span class="shelfbar__track"><span class="shelfbar__fill" style="width: <?= round((int) $d['n'] / $maxDec * 100) ?>%"></span></span>
            <span class="shelfbar__n"><?= (int) $d['n'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($noPhotos + $noYear + $noDev > 0): ?>
    <section class="panel">
      <h2 class="panel__title">Gaps to fill</h2>
      <dl class="spec">
        <?php if ($noPhotos): ?>
          <dt>No photos</dt><dd><a href="<?= e(url('/items', ['photos' => 'none'])) ?>"><?= $noPhotos ?> titles</a></dd>
        <?php endif; ?>
        <?php if ($noYear): ?>
          <dt>No year</dt><dd><?= $noYear ?> titles</dd>
        <?php endif; ?>
        <?php if ($noDev): ?>
          <dt>No developer</dt><dd><?= $noDev ?> titles</dd>
        <?php endif; ?>
        <?php if (!empty($totals['wanted'])): ?>
          <dt>Wanted</dt><dd><a href="<?= e(url('/items', ['status' => 'wishlist'])) ?>"><?= (int) $totals['wanted'] ?> titles</a></dd>
        <?php endif; ?>
      </dl>
    </section>
    <?php endif; ?>
  </aside>
</div>

<?php endif; ?>
