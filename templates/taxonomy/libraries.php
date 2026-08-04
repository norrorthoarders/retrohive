<?php /** @var array $rows */ ?>

<div class="pagehead">
  <div>
    <span class="eyebrow">The shelves you can reach</span>
    <h1>Libraries</h1>
  </div>
  <a class="btn" href="<?= e(url('/manage/libraries')) ?>">Manage libraries</a>
</div>

<p class="lede">
  A library is what people share. Your own is private unless you say otherwise;
  what you call your collection is everything you can reach across all of them.
</p>

<?php if ($rows === []): ?>
  <p class="lede">You have not been let into any yet.</p>
<?php else: ?>
  <div class="cardgrid">
    <?php foreach ($rows as $r): ?>
      <a class="card" href="<?= e(url('/items', ['library' => $r['slug']])) ?>"
         style="border-left:4px solid <?= e($r['accent_color']) ?>">
        <span class="card__eyebrow">
          <?= e(access_label((string) $r['access'])) ?>
          <?php if ((int) ($r['is_personal'] ?? 0) === 1): ?> · yours<?php endif; ?>
          <?php if (($r['kind'] ?? '') === 'shared'): ?> · shared<?php endif; ?>
        </span>
        <h2 class="card__title"><?= e($r['name']) ?></h2>
        <span class="card__meta">
          <?= (int) $r['n'] ?> <?= (int) $r['n'] === 1 ? 'entry' : 'entries' ?>
          <?php if ((int) $r['members'] > 1): ?> · <?= (int) $r['members'] ?> members<?php endif; ?>
        </span>
        <?php if (!empty($r['description'])): ?>
          <span class="card__meta"><?= e(truncate((string) $r['description'], 70)) ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
