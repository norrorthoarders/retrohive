<?php
/** @var int|null $rating 1..10 */
$rating = isset($rating) && $rating !== null ? (int) $rating : null;
$showNumber = $showNumber ?? true;
if ($rating === null): ?>
  <span class="rating--none">not rated</span>
<?php else:
  $tone = $rating >= 8 ? 'hot' : ($rating <= 4 ? 'cold' : ''); ?>
  <span class="rating" title="Rated <?= $rating ?> out of 10">
    <?php for ($i = 1; $i <= 10; $i++): ?>
      <i class="<?= $i <= $rating ? 'on ' . $tone : '' ?>"></i>
    <?php endfor; ?>
    <?php if ($showNumber): ?><span class="rating__n"><?= $rating ?>/10</span><?php endif; ?>
  </span>
<?php endif; ?>
