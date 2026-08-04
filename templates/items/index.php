<?php
/** @var array $items @var int $total @var int $page @var int $pages */
/** @var string $sort @var array $active @var array $platforms @var array $categories @var array $genres @var string $view */
$isWishlist = ($active['status'] ?? '') === 'wishlist';
?>

<div class="pagehead">
  <div>
    <span class="eyebrow"><?= ($domain ?? null) === 'software' ? 'Disks, tapes and cartridges' : 'Everything you can reach' ?></span>
    <h1><?= ($domain ?? null) === 'software' ? 'Software' : 'Collection' ?></h1>
  </div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <?php partial('view_switch', ['view' => $view ?? 'cards', 'domain' => $domain ?? 'software']); ?>
    <?php
    // Titles are reached from here, not from Manage.
    //
    // A title is what a copy is a copy of, so the place you want it is the place you
    // are looking at copies. Putting it in the manage nav filed it with platforms and
    // makers, which is reference data somebody sets up once - a title is not that.
    ?>
    <a class="btn btn--accent" href="<?= e(url('/items/new', ($domain ?? null) ? ['domain' => $domain] : [])) ?>">
      Add <?= ($domain ?? null) === 'software' ? 'software' : 'an entry' ?>
    </a>
  </div>
</div>

<?php
// Back to the browser you filtered from.
//
// This always posted to /items, which is the Collection - so filtering the
// software browser answered on a different page, with the domain lost and the
// heading changed to "Everything you can reach". The form has to come back to
// where it was submitted.
$filterAction = match ($domain ?? null) {
    'software' => '/software',
    'hardware' => '/hardware',
    default    => '/items',
};
?>
<form class="filters" method="get" action="<?= e(url($filterAction)) ?>">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <?php
  // Stay on this side of the collection.
  //
  // The form posted to /items with no domain, so searching from the software browser
  // returned hardware too - the filter was in the URL you arrived by and the form threw
  // it away. The browse query has always supported this; nothing was sending it.
  ?>
  <?php if (($domain ?? null) !== null): ?>
    <input type="hidden" name="domain" value="<?= e((string) $domain) ?>">
  <?php endif; ?>
  <div class="filters__grid filters__grid--row">
    <div class="field">
      <label for="f-q">Search</label>
      <input id="f-q" type="search" name="q" value="<?= e($active['q'] ?? '') ?>" placeholder="Title, studio, catalog no.">
    </div>

    <?php
    // No library picker here. The one in the header says which shelf you are
    // looking at, and a second control saying the same thing is how the two come
    // to disagree - the header said one library while this said another, and the
    // list obeyed whichever was posted last. Carried as a hidden field so the
    // header's choice survives a filter.
    ?>
    <?php if (($library ?? '') !== ''): ?>
      <input type="hidden" name="library" value="<?= e((string) $library) ?>">
    <?php endif; ?>

    <div class="field">
      <label for="f-platform">Platform</label>
      <select id="f-platform" name="platform">
        <option value="">Every platform</option>
        <?php foreach ($platforms as $p): ?>
          <option value="<?= e($p['slug']) ?>" <?= ($active['platform'] ?? '') === $p['slug'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <?php
      // Games or everything else, the counterpart of the hardware browser's
      // Machines/Peripherals. Separate from "Software type", which is the branch
      // of the tree: this is the one question people ask of a shelf.
      ?>
      <label for="f-kind">Games or applications</label>
      <select id="f-kind" name="kind">
        <option value="">Both</option>
        <option value="game" <?= ($active['kind'] ?? '') === 'game' ? 'selected' : '' ?>>Games</option>
        <option value="software" <?= ($active['kind'] ?? '') === 'software' ? 'selected' : '' ?>>
          Applications
        </option>
      </select>
    </div>
    <div class="field">
      <label for="f-category">Software type</label>
      <select id="f-category" name="category">
        <option value="">All types</option>
        <?php
        // $nodes, which is what the controller passes. This read $categories - a
        // variable nothing has ever sent - so the loop ran over nothing and the
        // select held one option: "All types", and a filter with a single choice
        // reads as a filter that does not work.
        //
        // PHP said so at the time. "Undefined variable $categories" was in the
        // server log while I was working on this same file, and I read past it.
        //
        // Only the kinds this library actually has something filed under, since
        // a list of every branch of the tree is not something anybody scrolls.
        $typeSlugs = [];
        foreach ($items as $onShelf) {
            if (($onShelf['category_slug'] ?? '') !== '') {
                $typeSlugs[(string) $onShelf['category_slug']] = true;
            }
        }
        foreach (($facets['categories'] ?? []) as $slugInUse) {
            $typeSlugs[(string) $slugInUse] = true;
        }
        ?>
        <?php foreach (($nodes ?? []) as $c): ?>
          <?php
          if (($domain ?? null) !== null && ($c['domain'] ?? '') !== $domain) { continue; }
          $chosen = ($active['category'] ?? '') === $c['slug'];
          // The one in force stays listed even if nothing matches it, or
          // choosing it would remove it from the list you chose it from.
          if (!$chosen && !isset($typeSlugs[(string) $c['slug']])) { continue; }
          ?>
          <option value="<?= e($c['slug']) ?>" <?= $chosen ? 'selected' : '' ?>><?= e($c['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php
      // No genre filter. A genre is a category now - "Games > Racing" is a leaf like any
      // other - and the category filter matches a whole branch, so choosing Games covers
      // every genre under it and choosing Racing narrows to one. Two controls for one
      // question was the leftover of a table that no longer exists.
      ?>

    <div class="field">
      <label for="f-rating">Minimum rating</label>
      <select id="f-rating" name="min_rating">
        <option value="">Any</option>
        <?php for ($i = 1; $i <= 10; $i++): ?>
          <option value="<?= $i ?>" <?= (int) ($active['min_rating'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?>+</option>
        <?php endfor; ?>
      </select>
    </div>

    <div class="field">
      <label for="f-condition">Condition</label>
      <select id="f-condition" name="condition">
        <option value="">Any condition</option>
        <?php foreach (condition_options() as $opt): ?>
          <option value="<?= e($opt) ?>" <?= ($active['condition'] ?? '') === $opt ? 'selected' : '' ?>><?= e(condition_label($opt)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="f-photos">Photos</label>
      <select id="f-photos" name="photos">
        <option value="">Any</option>
        <option value="some" <?= ($active['photos'] ?? '') === 'some' ? 'selected' : '' ?>>Has photos</option>
        <option value="none" <?= ($active['photos'] ?? '') === 'none' ? 'selected' : '' ?>>Missing photos</option>
      </select>
    </div>

    <div class="field">
      <label for="f-status">Status</label>
      <select id="f-status" name="status">
        <option value="">On the shelf</option>
        <option value="all" <?= ($active['status'] ?? '') === 'all' ? 'selected' : '' ?>>Everything</option>
        <?php foreach (status_options() as $opt): ?>
          <option value="<?= e($opt) ?>" <?= ($active['status'] ?? '') === $opt ? 'selected' : '' ?>><?= e(status_label($opt)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php
    // The tag picker is gone from the bar - it was the widest control for the
    // least used filter, and it is what pushed the row onto a second line. A tag
    // is still filtered by following a tag from an entry, and that choice
    // survives applying the other filters.
    ?>
    <?php if (($active['tag'] ?? '') !== ''): ?>
      <input type="hidden" name="tag" value="<?= e((string) $active['tag']) ?>">
    <?php endif; ?>

    <div class="field">
      <label for="f-sort">Sort by</label>
      <select id="f-sort" name="sort">
        <?php foreach (sort_options() as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="filters__foot">
    <span class="resultcount"><?= $total ?> <?= $total === 1 ? 'entry' : 'entries' ?></span>
    <span style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
      <button class="btn btn--accent btn--sm" type="submit">Filter</button>
      <a class="btn btn--sm" href="<?= e(url(($domain ?? null) === 'software' ? '/software' : '/items')) ?>">Clear</a>
    </span>
  </div>
</form>

<?php if (!$items): ?>
  <div class="empty">
    <h2>No matches</h2>
    <p>Nothing in the catalogue fits those filters. Widen the search, or add the title if it is genuinely missing.</p>
    <?php if (can_edit()): ?><a class="btn btn--accent" href="<?= e(url('/items/new')) ?>">Add title</a><?php endif; ?>
  </div>
<?php elseif ($view === 'table'): ?>
  <?php
  // A real table, the same shape as the hardware one.
  //
  // This used the shelf rows, which carry a thumbnail each - so "table" was a
  // list of cover art in a column, which is what the card view is already for.
  // Somebody switching to a table wants to read down a column of text and
  // compare, and a picture beside every line is what stops that working.
  //
  // The columns are the ones that differ between software entries: who made it,
  // when, what it came on, and what condition the copy is in. Platform is here
  // because the browser spans machines; it is not repeated per row when the
  // sort has already grouped by it.
  ?>
  <table class="table">
    <thead>
      <tr>
        <?php partial('sort_header', ['label' => 'Title',     'key' => 'title',     'sort' => $sort]); ?>
        <?php // A game and a paint program look alike as a box. ?>
        <?php partial('sort_header', ['label' => 'Kind',      'key' => 'kind',      'sort' => $sort]); ?>
        <?php partial('sort_header', ['label' => 'Platform',  'key' => 'platform',  'sort' => $sort]); ?>
        <?php partial('sort_header', ['label' => 'Studio',    'key' => 'company',   'sort' => $sort]); ?>
        <?php partial('sort_header', ['label' => 'Year',      'key' => 'year',      'sort' => $sort]); ?>
        <?php partial('sort_header', ['label' => 'Media',     'key' => 'media',     'sort' => $sort]); ?>
        <?php partial('sort_header', ['label' => 'Condition', 'key' => 'condition', 'sort' => $sort]); ?>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $r): ?>
        <tr>
          <td>
            <a href="<?= e(url('/items/' . $r['id'])) ?>"><strong><?= e((string) $r['title']) ?></strong></a>
            <?php if (($r['subtitle'] ?? '') !== ''): ?>
              <br><span class="hint"><?= e((string) $r['subtitle']) ?></span>
            <?php endif; ?>
          </td>
          <td><span class="chip"><?= e(item_kind_label($r) ?: 'software') ?></span></td>
          <td><?= e((string) ($r['platform_name'] ?? '')) ?></td>
          <td><?= e((string) ($r['developer_name'] ?? $r['publisher_name'] ?? '—')) ?></td>
          <td class="mono"><?= $r['release_year'] ? (int) $r['release_year'] : '—' ?></td>
          <td><span class="hint"><?= e((string) ($r['media_type'] ?? '—')) ?></span></td>
          <td>
            <?php
            // The grade if it has one, and the word for it rather than the key:
            // "very_good" is a column value, not something anybody wrote.
            $grade = (string) ($r['condition_grade'] ?? '');
            ?>
            <span class="hint"><?= $grade === '' ? '—' : e(condition_label($grade)) ?></span>
          </td>
          <td class="rowedit">
            <?php
            // Only where this person may actually change it.
            //
            // A read-only member saw an Edit button on every row, and following
            // it reached a refusal - a control that exists only to say no. The
            // check is per entry rather than per library, because a contributor
            // may edit what they added and not what somebody else did.
            ?>
            <?php if (can_write_item($r)): ?>
            <?php
            // Straight into editing and back here afterwards, as the hardware
            // table does: reading one entry is a page, fixing six is a list.
            ?>
            <a class="btn btn--sm" href="<?= e(url('/items/' . $r['id'] . '/edit',
                 ['return' => $_SERVER['REQUEST_URI'] ?? '/software'])) ?>">Edit</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php else: ?>
  <div class="grid">
    <?php foreach ($items as $it) partial('card', ['it' => $it]); ?>
  </div>
<?php endif; ?>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pages">
  <?php if ($page > 1): ?>
    <a href="<?= e(with_query(['page' => $page - 1])) ?>">Previous</a>
  <?php endif; ?>
  <?php
  $window = 2;
  for ($p = 1; $p <= $pages; $p++):
    if ($p !== 1 && $p !== $pages && abs($p - $page) > $window) {
        if (abs($p - $page) === $window + 1) echo '<span>…</span>';
        continue;
    } ?>
    <?php if ($p === $page): ?>
      <span class="is-current"><?= $p ?></span>
    <?php else: ?>
      <a href="<?= e(with_query(['page' => $p])) ?>"><?= $p ?></a>
    <?php endif; ?>
  <?php endfor; ?>
  <?php if ($page < $pages): ?>
    <a href="<?= e(with_query(['page' => $page + 1])) ?>">Next</a>
  <?php endif; ?>
</nav>
<?php endif; ?>
