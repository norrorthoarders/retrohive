<?php
/** @var array $it a row from v_items */
$cover = image_url($it['cover_filename'] ?? null, 'thumb');
?>
<a class="shelf__row" href="<?= e(url('/items/' . $it['id'])) ?>" style="--spine: <?= e($it['platform_color'] ?: '#cba6f7') ?>">
  <span class="shelf__spine" aria-hidden="true"></span>
  <?php if ($cover): ?>
    <img class="shelf__thumb" src="<?= e($cover) ?>" alt="" loading="lazy">
  <?php else: ?>
    <span class="shelf__thumb shelf__thumb--empty" aria-hidden="true"></span>
  <?php endif; ?>
  <span>
    <span class="shelf__title"><?= e($it['title']) ?></span>
    <?php if ($it['subtitle']): ?><span class="shelf__sub"><?= e($it['subtitle']) ?></span><?php endif; ?>
  </span>
  <span class="shelf__cell shelf__hide"><?= e($it['developer_name'] ?: '—') ?></span>
  <span class="shelf__cell mono"><?= $it['release_year'] ? (int) $it['release_year'] : '—' ?></span>
  <span class="shelf__cell shelf__hide"><?= e($it['platform_name']) ?></span>
  <?php
  // Which shelf it is on, and which half of the catalogue it belongs to.
  //
  // Searching from the header reaches across every library you can read, so a result
  // with no shelf on it leaves you to open the entry to find out where it lives - and
  // "Amiga 500" could as easily be a machine as a game about one.
  ?>
  <span class="shelf__cell shelf__hide">
    <span class="chip chip--soft" style="--spine: <?= e((string) ($it['library_color'] ?: '#cba6f7')) ?>">
      <?= e((string) ($it['library_name'] ?? '')) ?>
    </span>
  </span>
  <span class="shelf__cell shelf__hide">
    <span class="hint"><?= ($it['domain'] ?? '') === 'hardware' ? 'Hardware' : 'Software' ?></span>
  </span>
  <span class="shelf__hide"><?php partial('rating', ['rating' => $it['rating'], 'showNumber' => false]); ?></span>
</a>
