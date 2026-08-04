<?php
/**
 * What a library actually holds.
 *
 * The management list showed a number in an Entries column, which is enough to decide
 * that a library is not empty and nothing like enough to decide what to do about it.
 * This is the itemised answer: every entry, linked, and the platforms, makers, models
 * and places the library defined for itself - the things people forget a library owns
 * until they have deleted it.
 *
 * @var array $library @var array $summary @var array $entries @var int $page @var int $perPage
 * @var array $platforms @var array $companies @var array $locations
 * @var array $hardware @var array $software @var array $members
 */
$lid   = (int) $library['id'];
$total = (int) $summary['entries'];
$pages = max(1, (int) ceil($total / max(1, $perPage)));

$list = function (string $title, array $rows, ?callable $link) {
    ?>
    <section class="panel" style="margin:0 0 1rem">
      <h2 class="panel__title"><?= count($rows) ?> <?= e($title) ?></h2>
      <?php if ($rows === []): ?>
        <p class="hint" style="margin:0">None.</p>
      <?php else: ?>
        <ul style="margin:0;padding-left:1.1rem;font-size:.92rem;line-height:1.7">
          <?php foreach ($rows as $r): ?>
            <li>
              <?php $href = $link === null ? null : $link($r); ?>
              <?php if ($href !== null): ?>
                <a href="<?= e($href) ?>"><?= e((string) $r['name']) ?></a>
              <?php else: ?>
                <?= e((string) $r['name']) ?>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <?php
};
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Manage</span>
    <h1>What <?= e((string) $library['name']) ?> holds</h1>
  </div>
  <div>
    <?php // An owner who is not an administrator has no business on either of the
          // management screens, so they are offered their own editor instead. ?>
    <?php if (is_admin()): ?>
      <a class="btn btn--sm" href="<?= e(url('/manage/libraries')) ?>">All libraries</a>
      <a class="btn btn--sm" href="<?= e(url('/manage/libraries/' . $lid)) ?>">Manage this one</a>
    <?php else: ?>
      <a class="btn btn--sm" href="<?= e(url('/libraries/' . $lid . '/edit')) ?>">Library settings</a>
    <?php endif; ?>
  </div>
</div>

<section class="panel">
  <h2 class="panel__title">
    <?= $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?>
    <?php if ($pages > 1): ?>
      <span class="hint">page <?= $page ?> of <?= $pages ?></span>
    <?php endif; ?>
  </h2>

  <?php if ($entries === []): ?>
    <p class="hint" style="margin:0">
      Nothing is filed here. The library may still define platforms, makers and models
      of its own — those are listed below, and they go too if it is deleted.
    </p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr><th>Entry</th><th>Kind</th><th>Machine</th><th class="num">Images</th><th>Added</th></tr>
      </thead>
      <tbody>
        <?php foreach ($entries as $i): ?>
          <tr>
            <td>
              <?php
              // Linked to the entry itself, which is the point of the screen: it is
              // hard to decide about a collection you can only see the size of.
              ?>
              <a href="<?= e(url('/items/' . (int) $i['id'])) ?>"><?= e((string) $i['title']) ?></a>
            </td>
            <td><?= e((string) ($i['category_name'] ?? '—')) ?></td>
            <td><?= e((string) ($i['platform_name'] ?? '—')) ?></td>
            <td class="num"><?= (int) $i['images'] ?></td>
            <td class="hint"><?= e(substr((string) $i['created_at'], 0, 10)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($pages > 1): ?>
      <div style="display:flex;gap:.5rem;margin-top:.8rem">
        <?php if ($page > 1): ?>
          <a class="btn btn--sm" href="<?= e(url('/manage/libraries/' . $lid . '/contents', ['page' => $page - 1])) ?>">Previous</a>
        <?php endif; ?>
        <?php if ($page < $pages): ?>
          <a class="btn btn--sm" href="<?= e(url('/manage/libraries/' . $lid . '/contents', ['page' => $page + 1])) ?>">Next</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<div class="cols cols--2" style="margin-top:1rem">
  <div>
    <?php
    // These are the ones people are surprised by. An entry is obviously lost when a
    // library goes; a machine definition the library added for itself is not obvious
    // at all, and is often the thing that took the longest to get right.
    $list('platforms this library defined', $platforms,
          fn($r) => url('/manage/platforms', ['edit' => (int) $r['id']]));
    $list('companies', $companies,
          fn($r) => url('/manage/companies', ['edit' => (int) $r['id']]));
    $list('places', $locations, null);
    ?>
  </div>
  <div>
    <?php
    $list('hardware models', $hardware,
          fn($r) => url('/manage/models', ['edit' => (int) $r['id']]));
    $list('software models', $software,
          fn($r) => url('/manage/software-models', ['edit' => (int) $r['id']]));
    ?>
    <section class="panel" style="margin:0">
      <h2 class="panel__title"><?= count($members) ?> member<?= count($members) === 1 ? '' : 's' ?></h2>
      <?php if ($members === []): ?>
        <p class="hint" style="margin:0">Nobody but the owner.</p>
      <?php else: ?>
        <ul style="margin:0;padding-left:1.1rem;font-size:.92rem;line-height:1.7">
          <?php foreach ($members as $m): ?>
            <li>
              <?= e((string) $m['username']) ?>
              <span class="hint"><?= e((string) $m['access']) ?><?=
                (string) $m['status'] === 'accepted' ? '' : ', ' . e((string) $m['status']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
</div>
