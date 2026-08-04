<?php
/**
 * What something came on: a medium and how many, repeatable.
 *
 * The software model editor has had this since it was written; the entry form had
 * one free-text box and a number beside it, so "a cartridge and a manual disk"
 * had to be flattened into a sentence.
 *
 * @var array $media rows of ['medium' => …, 'quantity' => …]
 */
$rows = $media ?: [];
?>
<p class="label" style="margin-top:1rem">Comes on</p>
<button type="button" class="btn btn--wide" data-media-add style="margin-bottom:.7rem"
        <?= $rows === [] ? '' : 'hidden' ?>>
  Add a medium
</button>

<?php
// A hidden template, because the list starts empty on a new entry and there is
// nothing to clone. The option list is written twice - here and in the loop -
// which is the one duplication worth having: a <template> cannot be filled from
// the loop below without JavaScript building the options, and then the vocabulary
// would live in two languages instead of two places.
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
  <?php foreach ($rows as $m): ?>
    <div class="mfrow" data-media-row>
      <select name="media_type[]" aria-label="Medium">
        <?php
        // A medium typed in before this list existed will not be in the
        // vocabulary. Kept as its own option rather than silently becoming
        // whatever happens to be first.
        $current = (string) ($m['medium'] ?? '');
        $known   = false;
        foreach (media_options() as $items) {
            if (in_array($current, $items, true)) { $known = true; break; }
        }
        ?>
        <?php if ($current !== '' && !$known): ?>
          <option value="<?= e($current) ?>" selected><?= e($current) ?></option>
        <?php endif; ?>
        <?php foreach (media_options() as $group => $items): ?>
          <optgroup label="<?= e($group) ?>">
            <?php foreach ($items as $medium): ?>
              <option value="<?= e($medium) ?>" <?= $current === $medium ? 'selected' : '' ?>>
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
