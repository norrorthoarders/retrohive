<?php
/**
 * Software models.
 *
 * The counterpart to Machine models and Peripheral models, and read the same way: a
 * model says what a boxed release generally *is*, so a title made from one starts with
 * its fields and its box contents already filled in.
 *
 * @var array $models @var array $platforms @var array $categories
 * @var array|null $editing @var array $fields @var array $contents @var int $libraryHere
 */
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Manage</span>
    <h1>Software models</h1>
  </div>
</div>

<?php partial('manage_nav', ['current' => 'swmodels']); ?>

<?php
// The editor first, full width, with the list beneath it.
//
// It was two columns, which made the model form half as wide as the machine and
// peripheral editors it is meant to match - and those have plenty to fill the width:
// specifications, box contents, notes. Same shape as its counterparts now.
?>
<section class="panel">
    <p class="label"><?= $editing ? 'Edit model' : 'Add a model' ?></p>

    <form method="post" action="<?= e(url('/manage/software-models')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

      <div class="field">
        <label for="name">Name</label>
        <input id="name" name="name" type="text" required maxlength="160"
               value="<?= e((string) ($editing['name'] ?? '')) ?>"
               placeholder="Amiga boxed game, 3.5-inch">
      </div>

      <div class="formgrid">
        <div class="field field--half">
          <label for="platform_id">Platform</label>
          <select id="platform_id" name="platform_id" data-platform-select>
            <option value="">Any platform</option>
            <?php foreach ($platforms as $p): ?>
              <option value="<?= (int) $p['id'] ?>"
                      <?= (int) ($editing['platform_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
                <?= e((string) $p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field field--half">
          <label for="category_id">Catalogue place</label>
          <select id="category_id" name="category_id" data-category-select>
            <option value="">Not decided</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int) $c['id'] ?>"
                      data-platform="<?= (int) ($c['platform_id'] ?? 0) ?>"
                      <?= (int) ($editing['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                <?= e((string) $c['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Choosing a platform narrows this to that machine's branches.</span>
        </div>
      </div>

      <?php
      // What it comes on: a row per medium, because a boxed release is often more than
      // one - six floppies and a CD, two cartridges. This was a single text box, so
      // "3.5-inch disk, CD-ROM" was prose nobody could count or filter on.
      //
      // "From" is gone. It was a year on the *model*, which is a shape of release rather
      // than a thing with a date - the year belongs to the title.
      ?>
      <p class="label" style="margin-top:1rem">Comes on</p>
      <button type="button" class="btn btn--wide" data-media-add style="margin-bottom:.7rem"
              <?= ($media ?? []) === [] ? '' : 'hidden' ?>>
        Add a medium
      </button>

      <?php
      // A hidden template, because the list starts empty on a new model and there is
      // nothing to clone. Kept in the markup rather than built in JavaScript so the
      // medium list has one source - this loop - instead of two that can drift.
      ?>
      <template data-media-template>
        <div class="mfrow" data-media-row>
          <select name="media_type[]" aria-label="Medium">
            <?php foreach (media_options() as $group => $items): ?>
              <optgroup label="<?= e($group) ?>">
                <?php foreach ($items as $medium): ?>
                  <option value="<?= e($medium) ?>"><?= e($medium) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
          <input type="number" name="media_qty[]" min="1" max="999" aria-label="How many" value="1">
          <span class="mfrow__move">
            <button type="button" class="btn btn--sm" data-media-up title="Move up">&uarr;</button>
            <button type="button" class="btn btn--sm" data-media-down title="Move down">&darr;</button>
            <button type="button" class="btn btn--sm" data-media-addafter title="Add below">+</button>
            <button type="button" class="btn btn--sm" data-media-remove title="Remove">&times;</button>
          </span>
        </div>
      </template>

      <div data-media-rows>
        <?php foreach (($media ?? []) as $m): ?>
          <div class="mfrow" data-media-row>
            <select name="media_type[]" aria-label="Medium">
              <?php foreach (media_options() as $group => $items): ?>
                <optgroup label="<?= e($group) ?>">
                  <?php foreach ($items as $medium): ?>
                    <option value="<?= e($medium) ?>"
                            <?= (string) ($m['medium'] ?? '') === $medium ? 'selected' : '' ?>>
                      <?= e($medium) ?>
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
            <input type="number" name="media_qty[]" min="1" max="999" aria-label="How many"
                   value="<?= e((string) ($m['quantity'] ?? 1)) ?>">
            <span class="mfrow__move">
              <button type="button" class="btn btn--sm" data-media-up title="Move up">&uarr;</button>
              <button type="button" class="btn btn--sm" data-media-down title="Move down">&darr;</button>
              <button type="button" class="btn btn--sm" data-media-addafter title="Add below">+</button>
              <button type="button" class="btn btn--sm" data-media-remove title="Remove">&times;</button>
            </span>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="field">
        <label for="notes">Notes</label>
        <textarea id="notes" name="notes" rows="2"><?= e((string) ($editing['notes'] ?? '')) ?></textarea>
      </div>

      <?php
      // The same two lists a title inherits. Both use the specification-row controls the
      // model editors already have, so all four editors behave identically.
      ?>
      <p class="label" style="margin-top:1rem">Specifications</p>
      <?php // Empty to begin with, like the hardware editors: a new model has no
            // specifications until somebody says otherwise, and one blank pair of boxes
            // reads as a thing you are expected to fill in. ?>
      <?php
      // The row the Add button clones. The hardware editor has always had one; this
      // form did not, so with the list starting empty the button had nothing to copy
      // and pressing it did nothing - a worse state than the blank row it replaced.
      ?>
      <?php
      // Wrapped in data-modelfields, which is what the repeater script scopes itself to.
      // Without it the script returned immediately and the Add button did nothing - the
      // hardware editor has always had this wrapper and this form never did.
      ?>
      <div data-modelfields>
      <button type="button" class="btn btn--sm" data-mf-add style="margin-bottom:.7rem"
              <?= ($fields ?: []) === [] ? '' : 'hidden' ?>>
        Add a specification
      </button>
      <template data-mf-template>
        <div class="mfrow" data-mf-row>
          <input type="text" name="field_label[]" maxlength="60" aria-label="Name"
                 placeholder="Disks">
          <input type="text" name="field_value[]" maxlength="200" aria-label="Starting value"
                 placeholder="1 x 3.5-inch">
          <span class="mfrow__move">
            <button type="button" class="btn btn--sm" data-mf-up title="Move up">&uarr;</button>
            <button type="button" class="btn btn--sm" data-mf-down title="Move down">&darr;</button>
            <button type="button" class="btn btn--sm" data-mf-addafter title="Add below">+</button>
            <button type="button" class="btn btn--sm" data-mf-remove title="Remove">&times;</button>
          </span>
        </div>
      </template>

      <div data-mf-rows>
        <?php foreach (($fields ?: []) as $f): ?>
          <div class="mfrow" data-mf-row>
            <input type="text" name="field_label[]" maxlength="60" aria-label="Name"
                   placeholder="Disks" value="<?= e((string) ($f['label'] ?? '')) ?>">
            <input type="text" name="field_value[]" maxlength="200" aria-label="Starting value"
                   placeholder="1 x 3.5-inch" value="<?= e((string) ($f['default_value'] ?? '')) ?>">
            <span class="mfrow__move">
              <button type="button" class="btn btn--sm" data-mf-up title="Move up">&uarr;</button>
              <button type="button" class="btn btn--sm" data-mf-down title="Move down">&darr;</button>
              <button type="button" class="btn btn--sm" data-mf-addafter title="Add below">+</button>
              <button type="button" class="btn btn--sm" data-mf-remove title="Remove">&times;</button>
            </span>
          </div>
        <?php endforeach; ?>
      </div>

      </div>

      <p class="label" style="margin-top:1rem">Included in the box</p>
      <div data-specs>
      <button type="button" class="btn btn--wide" data-spec-add style="margin-bottom:.7rem"
              <?= ($contents ?: []) === [] ? '' : 'hidden' ?>>
        Add an item
      </button>
      <template data-spec-template>
        <div class="specrow" data-spec-row>
          <input type="text" name="content_label[]" maxlength="120" aria-label="Item"
                 placeholder="Manual">
          <input type="text" name="content_note[]" maxlength="255" aria-label="Note"
                 placeholder="48 pages">
          <span class="specrow__move">
            <button type="button" class="btn btn--sm" data-spec-up title="Move up">&uarr;</button>
            <button type="button" class="btn btn--sm" data-spec-down title="Move down">&darr;</button>
            <button type="button" class="btn btn--sm" data-spec-addafter title="Add below">+</button>
            <button type="button" class="btn btn--sm" data-spec-remove title="Remove">&times;</button>
          </span>
        </div>
      </template>

      <div data-spec-rows>
        <?php foreach (($contents ?: []) as $c): ?>
          <div class="specrow" data-spec-row>
            <input type="text" name="content_label[]" maxlength="120" aria-label="Item"
                   placeholder="Manual" value="<?= e((string) ($c['label'] ?? '')) ?>">
            <input type="text" name="content_note[]" maxlength="255" aria-label="Note"
                   placeholder="48 pages" value="<?= e((string) ($c['note'] ?? '')) ?>">
            <span class="specrow__move">
              <button type="button" class="btn btn--sm" data-spec-up title="Move up">&uarr;</button>
              <button type="button" class="btn btn--sm" data-spec-down title="Move down">&darr;</button>
              <button type="button" class="btn btn--sm" data-spec-addafter title="Add below">+</button>
              <button type="button" class="btn btn--sm" data-spec-remove title="Remove">&times;</button>
            </span>
          </div>
        <?php endforeach; ?>
      </div>

      </div>

      <div class="formactions">
        <button class="btn btn--accent" type="submit" name="action" value="save">
          <?= $editing ? 'Save changes' : 'Add the model' ?>
        </button>
        <?php if ($editing): ?>
          <a class="btn" href="<?= e(url('/manage/software-models')) ?>">Cancel</a>
          <button class="btn btn--danger" type="submit" name="action" value="delete"
                  data-confirm="Remove this model? Titles made from it keep what it filled in.">
            Delete
          </button>
        <?php endif; ?>
      </div>
    </form>
  </section>

<section class="panel" style="margin-top:1rem">
    <p class="label"><?= count($models) ?> on file</p>

    <?php if ($models === []): ?>
      <p class="hint">
        None yet. Structure data can ship a few, arriving when a library is created or
        resynced - none do on this instance right now.
      </p>
    <?php else: ?>
      <?php
      // A filter over what is already on the page. These lists run to hundreds of rows
      // and you almost always know the name of the one you want; paging to find it, or
      // reading down a column, is the slow way round.
      ?>
      <div class="field" style="margin-bottom:.7rem">
        <label class="visually-hidden" for="filter-swmodel-list">Filter models — name or platform</label>
        <input id="filter-swmodel-list" type="search" placeholder="Filter models — name or platform"
               data-tablefilter="#swmodel-list" data-tablefilter-count="#count-swmodel-list"
               autocomplete="off" spellcheck="false">
        <span class="hint" id="count-swmodel-list"></span>
      </div>
      <table class="table" id="swmodel-list">
        <thead>
          <tr>
            <th>Model</th><th>Platform</th><th>Catalogue place</th>
            <th>Fields</th><th>In box</th><th>Used</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php $lastPlat = null; foreach ($models as $m): ?>
            <?php if (($m['platform_name'] ?? null) !== $lastPlat): $lastPlat = $m['platform_name'] ?? null; ?>
              <tr class="grouphead">
                <td colspan="7"><strong><?= e((string) ($lastPlat ?? 'Any platform')) ?></strong></td>
              </tr>
            <?php endif; ?>
            <tr>
              <td>
                <strong><?= e((string) $m['name']) ?></strong>
                <?php if (!empty($m['media'])): ?>
                  <br><span class="hint"><?= e((string) $m['media']) ?></span>
                <?php endif; ?>
              </td>
              <td><?= e((string) ($m['platform_name'] ?? '—')) ?></td>
              <td><span class="hint"><?= e((string) ($m['category_name'] ?? '—')) ?></span></td>
              <td><?= (int) $m['field_count'] ?></td>
              <td><?= (int) $m['content_count'] ?></td>
              <td><?= (int) $m['usage_count'] ?></td>
              <td>
                <a class="btn btn--sm" href="<?= e(url('/manage/software-models', ['edit' => (int) $m['id']])) ?>">Edit</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
