<?php
/**
 * Links to manuals and schematics held elsewhere.
 *
 * Imported documents appeared on the entry and nowhere on the form, so there was
 * no way to correct a label, remove a dead link, or add one by hand - the only
 * route in was a scraper.
 *
 * Rows in the shape the specifications use, because it is the same kind of thing:
 * a list somebody edits, of pairs, that a lookup can also fill in.
 *
 * @var array $documents
 */
$rows = $documents ?: [];
?>
<fieldset>
  <legend>External links</legend>
  <div data-doc-rows>
    <?php foreach ($rows as $row): ?>
      <div class="specrow" data-doc-row>
        <input type="text" name="document_label[]" placeholder="Service manual"
               maxlength="200" aria-label="What the document is"
               value="<?= e((string) ($row['label'] ?? '')) ?>">
        <input type="url" name="document_url[]" placeholder="https://example.org/manual.pdf"
               maxlength="1000" aria-label="Where it is"
               value="<?= e((string) ($row['url'] ?? '')) ?>">
        <span class="specrow__move">
          <button type="button" class="btn btn--sm" data-doc-up
                  aria-label="Move this row up" title="Move up">&uarr;</button>
          <button type="button" class="btn btn--sm" data-doc-down
                  aria-label="Move this row down" title="Move down">&darr;</button>
          <button type="button" class="btn btn--sm" data-doc-addafter
                  aria-label="Add a row below this one" title="Add below">+</button>
          <button type="button" class="btn btn--sm" data-doc-remove
                  aria-label="Remove this row" title="Remove this row">&times;</button>
        </span>
      </div>
    <?php endforeach; ?>
  </div>

  <template data-doc-template>
    <div class="specrow" data-doc-row>
      <input type="text" name="document_label[]" placeholder="Service manual" maxlength="200" aria-label="What the document is">
      <input type="url" name="document_url[]" placeholder="https://example.org/manual.pdf" maxlength="1000" aria-label="Where it is">
      <span class="specrow__move">
        <button type="button" class="btn btn--sm" data-doc-up aria-label="Move this row up" title="Move up">&uarr;</button>
        <button type="button" class="btn btn--sm" data-doc-down aria-label="Move this row down" title="Move down">&darr;</button>
        <button type="button" class="btn btn--sm" data-doc-addafter aria-label="Add a row below this one" title="Add below">+</button>
        <button type="button" class="btn btn--sm" data-doc-remove aria-label="Remove this row" title="Remove this row">&times;</button>
      </span>
    </div>
  </template>

  <button type="button" class="btn btn--wide" data-doc-add>Add a link</button>
</fieldset>
