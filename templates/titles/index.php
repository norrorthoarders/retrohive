<?php
/** @var array $rows @var array $platforms @var string $q @var int|null $platform */
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Manage</span>
    <h1>Software models</h1>
    <p class="lede">
      What a thing <em>is</em>, recorded once — the counterpart to hardware models.
      Your copies of it live on the shelf and keep their own condition, so two
      copies of one game are two entries pointing here, not a duplicate.
    </p>
  </div>
  <?php
  // No "New title" button here.
  //
  // A title is created by cataloguing a copy: type a name into the entry form and it is
  // recorded, ready for the next one. Offering it here invited people to build a
  // catalogue of things they do not own, and then to file a copy against the wrong one
  // because two now existed. This screen is for correcting what cataloguing produced.
  ?>
</div>

<?php partial('manage_nav', ['current' => 'titles']); ?>

<form class="filters" method="get" action="<?= e(url('/titles')) ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search titles">
  <select name="platform">
    <option value="">Every platform</option>
    <?php foreach ($platforms as $p): ?>
      <option value="<?= (int) $p['id'] ?>" <?= $platform === (int) $p['id'] ? 'selected' : '' ?>>
        <?= e($p['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Filter</button>
  <?php if ($q !== '' || $platform !== null): ?>
    <a class="btn" href="<?= e(url('/titles')) ?>">Clear</a>
  <?php endif; ?>
</form>

<?php if ($rows === []): ?>
  <div class="empty">
    <h2>No titles yet</h2>
    <p>
      Titles get created as you catalogue: type a name into the “Title” box on
      the entry form and it is recorded here, ready for the next copy. You can
      also add one up front, or let a CSV import create them.
    </p>
    <?php if (can_edit()): ?>
      <p>
        <a class="btn btn--accent" href="<?= e(url('/items/new', ['domain' => 'software'])) ?>">Add software</a>
        <a class="btn" href="<?= e(url('/import')) ?>">Import a CSV</a>
      </p>
    <?php endif; ?>
  </div>

<?php else: ?>
  <table class="table">
    <thead>
      <tr>
        <th>Title</th>
        <th>Platform</th>
        <th>Year</th>
        <th>Developer</th>
        <th>Made from</th>
        <th style="text-align:right">Copies</th>
        <th style="width:1%"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td>
          <a href="<?= e(url('/titles/' . (int) $r['id'])) ?>"><?= e($r['name']) ?></a>
          <?php if ($r['subtitle']): ?>
            <span style="color:var(--dim)"> — <?= e($r['subtitle']) ?></span>
          <?php endif; ?>
        </td>
        <td>
          <span style="display:inline-block;width:8px;height:12px;border-radius:1px;vertical-align:-1px;margin-right:.4rem;background:<?= e($r['platform_color']) ?>"></span>
          <?= e($r['platform_name']) ?>
        </td>
        <td class="mono"><?= $r['release_year'] ? (int) $r['release_year'] : '—' ?></td>
        <td><?= e($r['developer_name'] ?? '—') ?></td>
        <?php
        // Which software model this title was made from, and a way in to change it.
        // The picker lives on the title editor and nothing linked there, so it may as
        // well not have existed - and the column answers "did I set one?" without
        // opening anything.
        ?>
        <td><?= $r['model_name'] ? e((string) $r['model_name']) : '<span style="color:var(--dim)">—</span>' ?></td>
        <td style="text-align:right">
          <?php $n = (int) ($r['visible_copies'] ?? 0); ?>
          <?php if ($n === 0): ?>
            <span style="color:var(--dim)">none</span>
          <?php else: ?>
            <a href="<?= e(url('/items', ['title_id' => (int) $r['id'], 'status' => 'all'])) ?>"><?= $n ?></a>
          <?php endif; ?>
        </td>
        <td style="text-align:right;white-space:nowrap">
          <?php if (can_edit()): ?>
            <a class="btn btn--sm" href="<?= e(url('/titles/' . (int) $r['id'] . '/edit')) ?>">Edit</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
