<?php
/** @var array $title @var array $copies @var array $siblings */
?>

<div class="pagehead">
  <div>
    <h1><?= e($title['name']) ?></h1>
    <?php if ($title['subtitle']): ?>
      <p class="lede"><?= e($title['subtitle']) ?></p>
    <?php endif; ?>
    <p class="lede" style="font-size:.9rem">
      <span style="display:inline-block;width:8px;height:12px;border-radius:1px;vertical-align:-1px;margin-right:.4rem;background:<?= e($title['platform_color']) ?>"></span>
      <?= e($title['platform_name']) ?>
      <?php if ($title['release_year']): ?> · <?= (int) $title['release_year'] ?><?php endif; ?>
      <?php if ($title['developer_name']): ?> · <?= e($title['developer_name']) ?><?php endif; ?>
      <?php if ($title['publisher_name'] && $title['publisher_name'] !== $title['developer_name']): ?>
        · published by <?= e($title['publisher_name']) ?>
      <?php endif; ?>
    </p>
  </div>
  <?php if (can_edit()): ?>
    <a class="btn" href="<?= e(url('/titles/' . (int) $title['id'] . '/edit')) ?>">Edit</a>
  <?php endif; ?>
</div>

<?php if ($title['synopsis']): ?>
  <section class="panel">
    <p style="margin:0;white-space:pre-wrap"><?= e($title['synopsis']) ?></p>
  </section>
<?php endif; ?>

<section class="panel">
  <h2 class="panel__title">
    Your copies
    <span style="color:var(--dim);font-weight:normal">· <?= count($copies) ?></span>
  </h2>

  <?php if ($copies === []): ?>
    <p class="hint" style="margin-bottom:0">
      Nothing on the shelf points at this title yet.
      <?php if (can_edit()): ?>
        <a href="<?= e(url('/items/new', ['title_id' => (int) $title['id']])) ?>">Add a copy</a>.
      <?php endif; ?>
    </p>
  <?php else: ?>
    <p class="hint" style="margin-top:-.4rem">
      Each row is a separate physical copy with its own condition and
      completeness. More than one here is normal, not a duplicate.
    </p>
    <table class="table">
      <thead>
        <tr>
          <th>Library</th><th>Condition</th><th>Completeness</th>
          <th>Box / manual / media</th><th>Status</th><th>Where</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($copies as $c): ?>
        <tr>
          <td>
            <a href="<?= e(url('/items/' . (int) $c['id'])) ?>">
              <span style="display:inline-block;width:8px;height:12px;border-radius:1px;vertical-align:-1px;margin-right:.4rem;background:<?= e($c['library_color']) ?>"></span>
              <?= e($c['library_name']) ?>
            </a>
          </td>
          <td><?= e(condition_label($c['condition_grade'])) ?></td>
          <td><?= e(completeness_label($c['completeness'])) ?></td>
          <td style="font-size:.85rem;color:var(--dim)">
            <?= e(condition_label($c['condition_box'])) ?> /
            <?= e(condition_label($c['condition_manual'])) ?> /
            <?= e(condition_label($c['condition_media'])) ?>
          </td>
          <td><span class="chip"><?= e(status_label($c['status'])) ?></span></td>
          <td style="font-size:.85rem;color:var(--dim)"><?= e($c['location_name'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (can_edit()): ?>
      <a class="btn btn--sm" href="<?= e(url('/items/new', ['title_id' => (int) $title['id']])) ?>">Add another copy</a>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php if ($siblings): ?>
<section class="panel">
  <h2 class="panel__title">The same game on other machines</h2>
  <p class="hint" style="margin-top:-.4rem">
    Matched on the work, not the release. The Amiga and C64 versions are
    genuinely different artefacts, so they are separate titles that share a key.
  </p>
  <div class="chips">
    <?php foreach ($siblings as $sib): ?>
      <a class="chip" href="<?= e(url('/titles/' . (int) $sib['id'])) ?>">
        <?= e($sib['platform_name']) ?>
        <?php if ($sib['release_year']): ?> · <?= (int) $sib['release_year'] ?><?php endif; ?>
        <?php if ((int) $sib['copy_count'] > 0): ?> · <?= (int) $sib['copy_count'] ?> owned<?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($title['external_url']): ?>
  <p><a href="<?= e($title['external_url']) ?>" rel="noopener noreferrer nofollow">Reference page</a></p>
<?php endif; ?>
