<?php
/** @var array $it a row from v_items */
$cover = image_url($it['cover_filename'] ?? null, 'thumb');
?>
<article class="card" style="--spine: <?= e($it['platform_color'] ?: '#cba6f7') ?>">
  <a class="card__link" href="<?= e(url('/items/' . $it['id'])) ?>">
    <div class="card__art">
      <?php if ($cover): ?>
        <img src="<?= e($cover) ?>" alt="Packaging for <?= e($it['title']) ?>" loading="lazy">
      <?php else: ?>
        <span class="card__noart">no photo yet</span>
      <?php endif; ?>
    </div>
    <div class="card__body">
      <span class="card__title"><?= e($it['title']) ?></span>
      <span class="card__meta">
        <?= e($it['platform_name']) ?><?= $it['release_year'] ? ' · ' . (int) $it['release_year'] : '' ?>
      </span>
      <?php
      // The shelf and the kind, as on the list view. A search from the header crosses
      // every library you can read, and a card that does not say which one it came from
      // makes you open it to find out.
      ?>
      <span class="card__meta">
        <span class="chip chip--soft" style="--spine: <?= e((string) ($it['library_color'] ?: '#cba6f7')) ?>">
          <?= e((string) ($it['library_name'] ?? '')) ?>
        </span>
        <span class="hint"><?= ($it['domain'] ?? '') === 'hardware' ? 'Hardware' : 'Software' ?></span>
      </span>
      <?php if ($it['developer_name']): ?>
        <span class="card__meta"><?= e(truncate($it['developer_name'], 32)) ?></span>
      <?php endif; ?>
      <div class="card__foot">
        <?php partial('rating', ['rating' => $it['rating'], 'showNumber' => false]); ?>
        <?php if (($it['status'] ?? 'owned') !== 'owned'): ?>
          <span class="chip chip--on"><?= e(status_label($it['status'])) ?></span>
        <?php endif; ?>
        <?php // ?? because genres became categories and not every query still
              // selects a genre column. Reading one that is not there is a
              // warning, and a warning on this page is the "Something broke"
              // screen for a chip nobody would miss. ?>
        <?php
        // What kind of thing this is, on the card as well as in the table. A
        // machine and a card look alike as a name and a photograph; so do a game
        // and a paint program as a box.
        $cardKind = item_kind_label($it);
        ?>
        <?php if ($cardKind !== ''): ?>
          <span class="chip"><?= e($cardKind) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </a>
</article>
