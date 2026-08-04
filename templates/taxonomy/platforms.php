<?php /** @var array $rows */ ?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Browse by machine</span>
    <h1>Platforms</h1>
  </div>
  <?php if (can_edit()): ?>
    <div class="pagehead__actions"><a class="btn" href="<?= e(url('/manage/platforms')) ?>">Manage platforms</a></div>
  <?php endif; ?>
</div>

<div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(260px, 1fr))">
  <?php foreach ($rows as $r): ?>
    <article class="card" style="--spine: <?= e($r['accent_color']) ?>">
      <a class="card__link" href="<?= e(url('/items', ['platform' => $r['slug']])) ?>">
        <div class="card__body" style="padding:1rem">
          <span class="eyebrow"><?= e($r['manufacturer'] ?: 'Library') ?><?= $r['year_introduced'] ? ' · ' . (int) $r['year_introduced'] : '' ?></span>
          <h2 style="margin:0"><?= e($r['name']) ?></h2>
          <p class="mono" style="color:var(--faint);font-size:.8rem;margin:.4rem 0 0">
            <?= (int) $r['n'] ?> <?= (int) $r['n'] === 1 ? 'title' : 'titles' ?>
            <?php if ($r['first_year']): ?> · <?= (int) $r['first_year'] ?>–<?= (int) $r['last_year'] ?><?php endif; ?>
            <?php if ($r['avg_rating']): ?> · avg <?= number_format((float) $r['avg_rating'], 1) ?><?php endif; ?>
          </p>
          <?php if ($r['description']): ?>
            <p style="color:var(--dim);font-size:.85rem;margin:.5rem 0 0"><?= e(truncate($r['description'], 120)) ?></p>
          <?php endif; ?>
        </div>
      </a>
    </article>
  <?php endforeach; ?>
</div>
