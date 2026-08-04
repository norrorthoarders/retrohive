<?php
/**
 * The photographs already on an entry.
 *
 * The form had a dropzone and nothing else: pictures could be added and never
 * removed, so a wrong one or six imported board shots were permanent as far as
 * this screen was concerned. `delete_image()` and `set_primary_image()` have both
 * existed all along, and the API could already do it - only the editor could not.
 *
 * Removal is a tick rather than an immediate button, so it happens on Save with
 * everything else and can be changed your mind about before then.
 *
 * @var array $images
 * @var string $domain which set of sections applies
 */
if (($images ?? []) === []) {
    return;
}
?>
<div class="imagegrid" style="display:flex;flex-wrap:wrap;gap:.9rem;margin-bottom:1rem">
  <?php foreach ($images as $img): ?>
    <?php $id = (int) $img['id']; ?>
    <div style="width:170px">
      <img src="<?= e(image_url((string) $img['filename'], 'thumb')) ?>" alt=""
           loading="lazy"
           style="width:170px;height:130px;object-fit:contain;border-radius:var(--r);
                  border:1px solid var(--line);background:var(--crust)">

      <label class="checkline" style="display:flex;gap:.4rem;align-items:baseline;margin-top:.35rem;font-size:.82rem">
        <input type="radio" name="image_primary" value="<?= $id ?>"
               <?= !empty($img['is_primary']) ? 'checked' : '' ?>>
        <span>Main picture</span>
      </label>

      <?php
      // Which set this one is in, changeable. Without it the only remedy for a
      // picture in the wrong place was to delete it and upload it again - and
      // every existing photograph starts as personal, because there is no way to
      // tell after the fact which were imported.
      $imgSections = image_sections((string) ($domain ?? 'software'));
      $imgIn       = image_section_for($img, (string) ($domain ?? 'software'));
      ?>
      <select name="image_section_of[<?= $id ?>]" aria-label="Which set this photo is in"
              style="width:100%;margin-bottom:.3rem">
        <?php foreach ($imgSections as $sKey => $sec): ?>
          <option value="<?= e($sKey) ?>" <?= $sKey === $imgIn ? 'selected' : '' ?>>
            <?= e($sec['title']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="image_caption[<?= $id ?>]" maxlength="200"
             value="<?= e((string) ($img['caption'] ?? '')) ?>"
             placeholder="Caption" aria-label="Caption for this photograph"
             style="width:100%;margin-top:.3rem;font-size:.82rem">

      <label class="checkline" style="display:flex;gap:.4rem;align-items:baseline;margin-top:.3rem;font-size:.82rem">
        <input type="checkbox" name="image_remove[]" value="<?= $id ?>">
        <span>Remove</span>
      </label>
    </div>
  <?php endforeach; ?>
</div>
<p class="hint" style="margin:0 0 .8rem">
  <?php
  // Said once, here, because a tick that only acts on Save is a tick somebody
  // will expect to have acted already.
  ?>
  Ticked photographs are deleted when you save. The main picture is the one shown
  first on the entry and in listings.
</p>
