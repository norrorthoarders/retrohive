<?php /** @var array $rows */ ?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Studios and labels</span>
    <h1>Developers &amp; publishers</h1>
  </div>
  <?php if (can_edit()): ?>
    <div class="pagehead__actions"><a class="btn" href="<?= e(url('/manage/companies')) ?>">Manage companies</a></div>
  <?php endif; ?>
</div>

<?php if (!$rows): ?>
  <div class="empty"><h2>No companies yet</h2><p>They appear here as soon as you name a developer or publisher on a catalogue entry.</p></div>
<?php else: ?>
<section class="panel">
  <table class="table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Country</th>
        <th>Active</th>
        <th>Website</th>
        <th class="num">Developed</th>
        <th class="num">Published</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td>
          <?php if (!empty($r['logo_filename'])): ?>
            <img class="logo--sm" src="<?= e(image_url($r['logo_filename'], 'thumb')) ?>" alt="">
          <?php endif; ?>
          <a href="<?= e(url('/developers/' . $r['slug'])) ?>"><?= e($r['name']) ?></a>
        </td>
        <td><?= e($r['country'] ?: '—') ?></td>
        <td class="mono"><?= $r['founded_year'] ? (int) $r['founded_year'] . '–' . ($r['defunct_year'] ? (int) $r['defunct_year'] : '') : '—' ?></td>
        <td><?php if ($r['website']): ?><a href="<?= e($r['website']) ?>" target="_blank" rel="noopener noreferrer external"><?= e(parse_url($r['website'], PHP_URL_HOST) ?: 'site') ?></a><?php else: ?>—<?php endif; ?></td>
        <td class="num"><?= (int) $r['developed'] ?></td>
        <td class="num"><?= (int) $r['published'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
