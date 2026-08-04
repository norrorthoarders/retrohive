<?php /** @var array $company @var array $developed @var array $published */ ?>

<div class="titleblock" style="--spine: var(--accent-2)">
  <span class="eyebrow">Developer &amp; publisher</span>
  <?php if (!empty($company['logo_filename'])): ?>
    <img class="logo" style="margin:.4rem 0 .8rem" src="<?= e(image_url($company['logo_filename'], 'display')) ?>" alt="<?= e($company['name']) ?> logo">
  <?php endif; ?>
  <h1><?= e($company['name']) ?></h1>
  <p class="sub mono" style="font-size:.85rem">
    <?= e($company['country'] ?: 'Country unknown') ?>
    <?php if ($company['founded_year']): ?> · founded <?= (int) $company['founded_year'] ?><?php endif; ?>
    <?php if ($company['defunct_year']): ?> · closed <?= (int) $company['defunct_year'] ?><?php endif; ?>
  </p>
  <p>
    <?php if ($company['website']): ?>
      <a class="chip" href="<?= e($company['website']) ?>" target="_blank" rel="noopener noreferrer external">Website</a>
    <?php endif; ?>
    <?php if ($company['wikipedia_url']): ?>
      <a class="chip" href="<?= e($company['wikipedia_url']) ?>" target="_blank" rel="noopener noreferrer external">Wikipedia</a>
    <?php endif; ?>
    <?php if (can_edit()): ?>
      <a class="chip" href="<?= e(url('/manage/companies', ['edit' => $company['id']])) ?>">Edit details</a>
    <?php endif; ?>
  </p>
</div>

<?php if ($company['notes']): ?>
  <section class="panel"><div class="notes"><?= e($company['notes']) ?></div></section>
<?php endif; ?>

<?php if ($developed): ?>
<section class="panel" style="margin-top:1rem">
  <h2 class="panel__title">Developed — <?= count($developed) ?> in the collection</h2>
  <div class="grid">
    <?php foreach ($developed as $it) partial('card', ['it' => $it]); ?>
  </div>
</section>
<?php endif; ?>

<?php if ($published): ?>
<section class="panel" style="margin-top:1rem">
  <h2 class="panel__title">Published for others — <?= count($published) ?></h2>
  <div class="shelf">
    <?php foreach ($published as $it) partial('shelf_row', ['it' => $it]); ?>
  </div>
</section>
<?php endif; ?>

<?php if (!$developed && !$published): ?>
  <div class="empty">
    <h2>Nothing filed under this name yet</h2>
    <p>Set <?= e($company['name']) ?> as the developer or publisher on an entry and its catalogue appears here.</p>
  </div>
<?php endif; ?>
