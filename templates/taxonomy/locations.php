<?php
/** @var array $libraries @var int $libraryId @var array $rows @var array $counts @var array $options */
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Manage</span>
    <h1>Locations</h1>
  </div>
</div>

<?php partial('manage_nav', ['current' => 'locations']); ?>

<?php
// No library picker of its own.
//
// The header already has one, and it is the one every other screen follows -
// two selectors for the same choice can disagree, and then somebody is editing
// one library while the bar says another.
//
// ?library= still works: the link from a library's own page passes it, and this
// screen honours it. Only the second control is gone.
?>

<section class="panel">
  <h2 class="panel__title">What you have</h2>

  <?php if ($rows === []): ?>
    <p class="hint" style="margin-bottom:0">
      Nothing yet. Add a room below, then add what is in it by choosing that
      room as its parent.
    </p>
  <?php else: ?>
    <?php
  // No rule between rows.
  //
  // Every row here is a form: a name, a parent, a floor, a Save. Ruled off from
  // each other they read as a ledger of records rather than a list of boxes to
  // type in, and the lines add a horizontal stripe every 40 pixels down a screen
  // that is already busy.
  ?>
  <table class="table table--plain">
      <thead>
        <tr>
          <th>Place</th>
          <th style="width:16rem">Part of</th>
          <th style="width:8rem">Floor</th>
          <th style="width:1%"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): $rid = (int) $row['id']; ?>
        <tr>
          <form method="post" action="<?= e(url('/manage/locations')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="library_id" value="<?= (int) $libraryId ?>">
            <input type="hidden" name="id" value="<?= $rid ?>">
            <td>
              <div style="display:flex;align-items:center;gap:.5rem">
                <span style="width:<?= (int) $row['depth'] * 1.2 ?>rem"></span>
                <input type="text" name="name" maxlength="120" value="<?= e($row['name']) ?>" required>
              </div>
              <?php $n = (int) ($counts[$rid] ?? 0); ?>
              <?php
              // No floor beside the name.
              //
              // There is a Floor column two cells along with the number in it,
              // editable, so this said the same thing twice - once as "Floor 1"
              // and once as a box containing 1 - and the row read as though the
              // two might disagree.
              ?>
            </td>
            <td>
              <select name="parent_id">
                <option value="">Top level</option>
                <?php foreach ($options as $opt):
                    // Nothing may be moved inside itself or inside something it
                    // already contains; offering it and refusing afterwards is
                    // a worse conversation than not offering it.
                    if (in_array((int) $opt['id'], location_subtree_ids($rid), true)) continue; ?>
                  <option value="<?= (int) $opt['id'] ?>"
                          data-floor="<?= $opt['floor'] === null ? '' : (int) $opt['floor'] ?>"
                          <?= (int) ($row['parent_id'] ?? 0) === (int) $opt['id'] ? 'selected' : '' ?>>
                    <?= e($opt['label']) ?><?= $opt['floor'] === null ? '' : ' · ' . e(floor_label($opt['floor'])) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <?php
              // Blank shows what it inherits, so the common case - a shelf in a
              // room that already has a floor - needs nothing typed at all.
              [$parentFloor, ] = $row['parent_id'] === null
                  ? [null, false] : location_floor((int) $row['parent_id']);
              ?>
              <input type="number" name="floor_level" min="-9" max="99" step="1"
                     value="<?= $row['floor_level'] === null ? '' : (int) $row['floor_level'] ?>"
                     placeholder="<?= $parentFloor === null ? '—' : (int) $parentFloor ?>"
                     title="Blank inherits from whatever it is part of. 0 is the entrance level; below it is negative.">
            </td>
            <td style="white-space:nowrap">
              <button class="btn btn--sm" type="submit" name="action" value="save">Save</button>
              <button class="btn btn--sm btn--danger" type="submit" name="action" value="delete"
                      <?php // No mention of unfiling: it is refused instead. ?>
                      data-confirm="Remove &quot;<?= e($row['name']) ?>&quot;? Anything inside it goes too.">
                &times;
              </button>
            </td>
          </form>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
    // No paragraph explaining how sorting and inherited floors work. It was
    // true, and it was six lines of it under a table that demonstrates both
    // without being told.
    ?>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="panel__title">Add a place</h2>
  <form method="post" action="<?= e(url('/manage/locations')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="library_id" value="<?= (int) $libraryId ?>">
    <div class="formgrid">
      <div class="field field--third">
        <label for="new-name">Name</label>
        <input id="new-name" name="name" type="text" maxlength="120" required
               placeholder="Computer room, Cabinet, Shelf 2, Box A">
      </div>
      <div class="field field--third" data-location-add>
        <label for="new-parent">Part of</label>
        <select id="new-parent" name="parent_id" data-parent-select>
          <option value="" data-floor="">Top level</option>
          <?php foreach ($options as $opt): ?>
            <option value="<?= (int) $opt['id'] ?>"
                    data-floor="<?= $opt['floor'] === null ? '' : (int) $opt['floor'] ?>">
              <?= e($opt['label']) ?><?= $opt['floor'] === null ? '' : ' · ' . e(floor_label($opt['floor'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field field--third">
        <label for="new-floor">Floor</label>
        <input id="new-floor" name="floor_level" type="number" min="-9" max="99" step="1"
               placeholder="—" data-floor-input>
        <?php
        // The short version. "Blank inherits" is the one fact somebody needs at
        // the moment of typing; the rest was a paragraph about a number field.
        ?>
        <span class="hint" data-floor-hint>Blank inherits.</span>
      </div>
      <div class="field formgrid--wide">
        <label for="new-notes">Notes</label>
        <input id="new-notes" name="notes" type="text" maxlength="255"
               placeholder="Behind the door, needs a stepladder">
      </div>
    </div>
    <div class="formactions">
      <button class="btn btn--accent" type="submit" name="action" value="save">Add it</button>
    </div>
  </form>
</section>
