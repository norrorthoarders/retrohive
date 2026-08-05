<?php
/** @var string $type @var array $def @var array $rows @var array|null $editing @var array $categories */
$tabs = [
    'platforms'  => 'Libraries',
    'categories' => 'Software types',
    'genres'     => 'Genres',
    'companies'  => 'Developers',
    'tags'       => 'Tags',
];
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Manage</span>
    <h1><?= e($def['title']) ?></h1>
  </div>
</div>

<?php partial('manage_nav', ['current' => $type]); ?>

<?php // A type with no blurb gets no paragraph, rather than an empty one taking up the space. ?>
<?php if (trim((string) ($def['blurb'] ?? '')) !== ''): ?>
  <p class="lede"><?= e($def['blurb']) ?></p>
<?php endif; ?>

<div class="cols cols--main">
  <section class="panel">
    <h2 class="panel__title"><?= count($rows) ?> on file</h2>
    <?php if (!$rows): ?>
      <p class="lede" style="margin:0">Nothing here yet. Add the first one with the form.</p>
    <?php else: ?>
    <?php
      // A filter over what is already on the page. These lists run to hundreds of rows
      // and you almost always know the name of the one you want; paging to find it, or
      // reading down a column, is the slow way round.
      ?>
      <div class="field" style="margin-bottom:.7rem">
        <label class="visually-hidden" for="filter-taxonomy-list">Filter this list</label>
        <input id="filter-taxonomy-list" type="search" placeholder="Filter this list"
               data-tablefilter="#taxonomy-list" data-tablefilter-count="#count-taxonomy-list"
               autocomplete="off" spellcheck="false">
        <span class="hint" id="count-taxonomy-list"></span>
      </div>
      <table class="table" id="taxonomy-list">
      <thead>
        <tr>
          <th>Name</th>
          <?php if ($type === 'genres'): ?><th>Software type</th><?php endif; ?>
          <?php if ($type === 'platforms'): ?><th>Company</th><th>Introduced</th><?php endif; ?>
          <?php if ($type === 'companies'): ?><th>Country</th><th>Link</th><?php endif; ?>
          <th class="num">Used</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <?php if ($type === 'companies' && !empty($r['logo_filename'])): ?>
              <img class="logo--sm" src="<?= e(image_url($r['logo_filename'], 'thumb')) ?>" alt="">
            <?php endif; ?>
            <?php if ($type === 'platforms'): ?>
              <span style="display:inline-block;width:8px;height:14px;border-radius:1px;vertical-align:-2px;margin-right:.4rem;background:<?= e($r['accent_color']) ?>"></span>
            <?php endif; ?>
            <?= e($r['name']) ?>
          </td>
          <?php if ($type === 'genres'): ?>
            <td class="mono" style="font-size:.8rem"><?php
              $catName = '—';
              foreach ($categories as $c) { if ((int) $c['id'] === (int) ($r['category_id'] ?? 0)) { $catName = $c['name']; break; } }
              echo e($catName);
            ?></td>
          <?php endif; ?>
          <?php if ($type === 'platforms'): ?>
            <td><?= e(($r['manufacturer'] ?? '') ?: '—') ?></td>
            <td class="mono"><?= $r['year_introduced'] ? (int) $r['year_introduced'] : '—' ?></td>
          <?php endif; ?>
          <?php if ($type === 'companies'): ?>
            <td><?= e($r['country'] ?: '—') ?></td>
            <td><?php if ($r['website']): ?><a href="<?= e($r['website']) ?>" target="_blank" rel="noopener noreferrer external"><?= e(parse_url($r['website'], PHP_URL_HOST) ?: 'site') ?></a><?php else: ?>—<?php endif; ?></td>
          <?php endif; ?>
          <td class="num"><?= (int) $r['usage_count'] ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a class="btn btn--sm" href="<?= e(url('/manage/' . $type, ['edit' => $r['id']])) ?>">Edit</a>
            <?php if ((int) $r['usage_count'] === 0): ?>
              <form method="post" action="<?= e(url('/manage/' . $type)) ?>" style="display:inline"
                    data-confirm="Delete <?= e($r['name']) ?>?">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn--sm btn--danger" type="submit" name="action" value="delete">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

<?php
/*
 * The editor is a closure because the page draws it twice.
 *
 * It used to be one panel that switched between Edit and Add on $editing, which
 * meant choosing a row took the add form away and the answer to "edit this one"
 * appeared where the add form had been. Manage > Categories does not work that
 * way - the node you picked is the top of the column and adding sits under it -
 * and these screens are the same job. So: the edited row first when there is
 * one, the empty form always, and ids prefixed per panel because two forms on a
 * page cannot both call a field f-name.
 */
$editorPanel = function (?array $row, string $idp, string $margin)
        use ($def, $type, $categories): void {
    $fieldValue = function (string $field, $default = '') use ($row) {
        $v = $row[$field] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    };
    $editing = $row;
?>
    <section class="panel" style="margin:<?= e($margin) ?>">
      <h2 class="panel__title"><?= $editing ? 'Edit ' . e($def['singular']) : 'Add a ' . e($def['singular']) ?></h2>
      <form method="post" action="<?= e(url('/manage/' . $type)) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

        <?php foreach ($def['fields'] as $field => [$label, $ftype, $required]): ?>
          <div class="field" style="margin-bottom:.7rem">
            <label for="<?= e($idp) ?>-f-<?= e($field) ?>"><?= e($label) ?><?= $required ? '' : ' (optional)' ?></label>
            <?php if ($ftype === 'textarea'): ?>
              <textarea id="<?= e($idp) ?>-f-<?= e($field) ?>" name="<?= e($field) ?>" rows="3"><?= e($fieldValue($field)) ?></textarea>
            <?php elseif (str_starts_with($ftype, 'select:')): ?>
              <select id="<?= e($idp) ?>-f-<?= e($field) ?>" name="<?= e($field) ?>">
                <option value="">None</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int) $c['id'] ?>" <?= (int) $fieldValue($field, 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($ftype === 'image'): ?>
              <?php $currentLogo = $editing['logo_filename'] ?? null; ?>
              <?php if ($currentLogo): ?>
                <img class="logo" src="<?= e(image_url($currentLogo, 'thumb')) ?>" alt="Current logo">
                <label class="checkline" style="margin:.4rem 0">
                  <input type="checkbox" name="remove_logo" value="1"> Remove this logo
                </label>
              <?php endif; ?>
              <div class="dropzone" data-dropzone>
                <div class="dropzone__prompt">
                  <strong>Drop a logo here</strong>
                  <span>or click to browse</span>
                </div>
                <span class="dropzone__hint">A square-ish PNG or SVG-exported PNG works best.</span>
                <input id="<?= e($idp) ?>-f-<?= e($field) ?>" name="<?= e($field) ?>" type="file" accept="image/*">
                <div class="dropzone__list" data-dropzone-list></div>
              </div>
            <?php elseif ($ftype === 'makes'): ?>
              <?php
              // What this company makes, as ticks rather than two screens.
              //
              // Manufacturers and Developers were one table shown twice, filtered by
              // this column - so a firm that made machines and published games had to
              // be found on whichever screen you happened to be on, and a company whose
              // tag was wrong looked simply absent. Here it is one row with two ticks,
              // and the pickers elsewhere read them.
              $makesNow = array_filter(explode(',', (string) $fieldValue($field, '')));
              ?>
              <label class="checkline">
                <input type="checkbox" name="makes[]" value="hardware"
                       <?= in_array('hardware', $makesNow, true) ? 'checked' : '' ?>>
                Hardware — machines, cards, peripherals
              </label>
              <label class="checkline">
                <input type="checkbox" name="makes[]" value="software"
                       <?= in_array('software', $makesNow, true) ? 'checked' : '' ?>>
                Software — games, applications
              </label>
              <label class="checkline">
                <input type="checkbox" name="makes[]" value="video"
                       <?= in_array('video', $makesNow, true) ? 'checked' : '' ?>>
                Video — movies, TV shows
              </label>
              <label class="checkline">
                <input type="checkbox" name="makes[]" value="music"
                       <?= in_array('music', $makesNow, true) ? 'checked' : '' ?>>
                Music — albums, artists
              </label>
              <span class="hint">
                Decides which pickers offer this company. A firm that did more than one, like
                Sony across hardware and music, wants each ticked.
              </span>
            <?php elseif ($ftype === 'color'): ?>
              <input id="<?= e($idp) ?>-f-<?= e($field) ?>" name="<?= e($field) ?>" type="color" value="<?= e($fieldValue($field, '#cba6f7')) ?>">
            <?php else: ?>
              <input id="<?= e($idp) ?>-f-<?= e($field) ?>" name="<?= e($field) ?>" type="<?= e($ftype) ?>"
                     value="<?= e($fieldValue($field, $ftype === 'number' && $field === 'sort_order' ? '0' : '')) ?>"
                     <?= $required ? 'required' : '' ?>>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <div style="display:flex;gap:.5rem;margin-top:1rem">
          <button class="btn btn--accent" type="submit"><?= $editing ? 'Save' : 'Add' ?></button>
          <?php if ($editing): ?><a class="btn" href="<?= e(url('/manage/' . $type)) ?>">Cancel</a><?php endif; ?>
        </div>
      </form>
    </section>
<?php
};
?>

  <aside>
    <?php if ($editing !== null) { $editorPanel($editing, 'edit', '0 0 1rem'); } ?>
    <?php $editorPanel(null, 'add', '0'); ?>
  </aside>
</div>
