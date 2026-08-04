<?php /** @var array $rows @var array $vendors @var array $libraries @var array|null $editing */ ?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Manage</span>
    <h1>Platforms</h1>
  </div>
</div>

<?php partial('manage_nav', ['current' => 'platforms']); ?>

<div class="cols cols--main">
  <section class="panel">
    <h2 class="panel__title"><?= count($rows) ?> platforms</h2>
    <?php
      // A filter over what is already on the page. These lists run to hundreds of rows
      // and you almost always know the name of the one you want; paging to find it, or
      // reading down a column, is the slow way round.
      ?>
      <div class="field" style="margin-bottom:.7rem">
        <label class="visually-hidden" for="filter-platform-list">Filter platforms — name or maker</label>
        <input id="filter-platform-list" type="search" placeholder="Filter platforms — name or maker"
               data-tablefilter="#platform-list" data-tablefilter-count="#count-platform-list"
               autocomplete="off" spellcheck="false">
        <span class="hint" id="count-platform-list"></span>
      </div>
      <table class="table" id="platform-list">
      <thead>
        <tr><th>Name</th><th style="width:9rem">Company</th>
            <th style="width:9rem">Belongs to</th><th style="width:5rem;text-align:right">Entries</th><th style="width:1%"></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <span style="display:inline-block;width:8px;height:12px;border-radius:1px;vertical-align:-1px;margin-right:.4rem;background:<?= e($r['accent_color']) ?>"></span>
            <?= e($r['name']) ?>
            <?php if ($r['year_introduced']): ?><span class="hint">, <?= (int) $r['year_introduced'] ?></span><?php endif; ?>
          </td>
          <td class="hint"><?= e($r['manufacturer'] ?: '—') ?></td>
          <td class="hint"><?= $r['library_id'] === null ? 'Everyone' : e((string) $r['library_name']) ?></td>
          <td style="text-align:right"><?= (int) $r['entries'] ?></td>
          <td style="white-space:nowrap">
            <?php if (can_edit_platform($r)): ?>
              <a class="btn btn--sm" href="<?= e(url('/manage/platforms', ['edit' => (int) $r['id']])) ?>">Edit</a>
              <?php if ((int) $r['entries'] === 0): ?>
                <form method="post" action="<?= e(url('/manage/platforms')) ?>" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn--sm btn--danger" type="submit" name="action" value="delete"
                          data-confirm="Remove <?= e($r['name']) ?>?">&times;</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

<?php
/*
 * Drawn twice: the platform being edited first, the empty form under it.
 *
 * One panel used to switch between the two on $editing, so choosing a row took
 * the add form off the page and put the answer where it had been. Manage >
 * Categories puts the thing you picked at the top of the column with adding
 * beneath it, and these screens do the same job.
 *
 * $withErrors decides which panel wears a failed submission. A rejected edit
 * comes back with ?edit=, a rejected add without one, so exactly one of the two
 * is the form that was posted - and old() and the error classes belong to it
 * alone rather than to both.
 */
$platformPanel = function (?array $row, string $idp, string $margin, bool $withErrors)
        use ($vendors, $currentLibraryId): void {
    $editing = $row;
    $val = fn(string $f, $d = '') => $withErrors
        ? old($f, $row[$f] ?? $d)
        : ($row[$f] ?? $d);
    $cls = fn(string $f, string $base = 'field') => $withErrors ? field_class($f, $base) : $base;
?>
    <section class="panel" style="margin:<?= e($margin) ?>">
      <h2 class="panel__title"><?= $editing ? 'Edit ' . e($editing['name']) : 'Add a platform' ?></h2>
      <form method="post" action="<?= e(url('/manage/platforms')) ?>" class="form">
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

        <div class="<?= e($cls('name')) ?>">
          <label for="<?= e($idp) ?>-name">Name</label>
          <input id="<?= e($idp) ?>-name" name="name" type="text" required maxlength="120"
                 value="<?= e($val('name')) ?>" placeholder="Amiga"
                 <?= $withErrors && form_error('name') ? 'aria-invalid="true"' : '' ?>>
          <?php if ($withErrors) { echo field_hint('name', 'Just the machine. The maker goes below.'); }
                else { ?><span class="hint">Just the machine. The maker goes below.</span><?php } ?>
        </div>
        <div class="field">
          <label for="<?= e($idp) ?>-vendor_id">Company</label>
          <select id="<?= e($idp) ?>-vendor_id" name="vendor_id">
            <option value="">Not set</option>
            <?php foreach ($vendors as $v): ?>
              <option value="<?= (int) $v['id'] ?>" <?= (int) ($editing['vendor_id'] ?? 0) === (int) $v['id'] ? 'selected' : '' ?>>
                <?= e($v['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($vendors === []): ?>
            <span class="hint">
              No company in this library is marked as making hardware. Open
              <a href="<?= e(url('/manage/companies')) ?>">Companies</a> and tick
              <em>Hardware</em> on the ones that built machines — or synchronise the
              library with <em>Overwrite</em> on, which restores the tags from the
              templates.
            </span>
          <?php endif; ?>
          <span class="hint">
            From <a href="<?= e(url('/manage/companies')) ?>">Companies</a>, which is
            this library's own list. A name typed twice is two makers.
          </span>
        </div>
        <?php // No library field: the header already says which library you are
              // working in, and a second control here could disagree with it.
              // Posted hidden so the request still states what it meant. ?>
        <input type="hidden" name="library_id" value="<?= (int) ($currentLibraryId ?? 0) ?>">
        <div class="formgrid">
          <div class="<?= e($cls('year_introduced', 'field field--half')) ?>">
            <label for="<?= e($idp) ?>-year_introduced">Introduced</label>
            <input id="<?= e($idp) ?>-year_introduced" name="year_introduced" type="number" min="1950" max="2100"
                   value="<?= e((string) $val('year_introduced')) ?>">
          </div>
          <div class="field field--half">
            <label for="<?= e($idp) ?>-accent_color">Colour</label>
            <input id="<?= e($idp) ?>-accent_color" name="accent_color" type="color"
                   value="<?= e((string) $val('accent_color', '#a6adc8')) ?>">
          </div>
        </div>
        <div class="formactions">
          <button class="btn btn--accent" type="submit" name="action" value="save"><?= $editing ? 'Save' : 'Add' ?></button>
          <?php if ($editing): ?><a class="btn" href="<?= e(url('/manage/platforms')) ?>">Cancel</a><?php endif; ?>
        </div>
      </form>
    </section>
<?php
};
?>

  <aside>
    <?php if ($editing !== null) { $platformPanel($editing, 'edit', '0 0 1rem', true); } ?>
    <?php $platformPanel(null, 'add', '0', $editing === null); ?>
  </aside>
</div>
