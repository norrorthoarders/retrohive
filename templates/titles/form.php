<?php
/** @var array|null $title @var array $platforms @var array $categories @var array $genres @var array $companies */
$v = fn(string $key, $fallback = '') => e((string) ($title[$key] ?? $fallback));
$isNew = $title === null;
?>

<div class="pagehead">
  <div>
    <h1><?= $isNew ? 'New title' : 'Edit ' . e($title['name']) ?></h1>
    <p class="lede">
      What the thing is, as against the copy you own. Everything here is
      inherited by any entry that points at it, and can still be overridden on
      that entry — a regional variant keeps its own language and barcode.
    </p>
  </div>
</div>

<form method="post"
      action="<?= e($isNew ? url('/titles') : url('/titles/' . (int) $title['id'])) ?>"
      class="form">
  <?= csrf_field() ?>

  <div class="fieldrow">
    <div class="field field--half">
      <label for="name">Name</label>
      <input id="name" name="name" required maxlength="220" value="<?= $v('name') ?>">
    </div>
    <div class="field field--half">
      <label for="subtitle">Subtitle</label>
      <input id="subtitle" name="subtitle" maxlength="220" value="<?= $v('subtitle') ?>">
    </div>
  </div>

  <div class="fieldrow">
    <div class="field field--half">
      <label for="sort_name">Sort as</label>
      <input id="sort_name" name="sort_name" maxlength="220" value="<?= $v('sort_name') ?>">
      <span class="hint">“Bard's Tale, The”, so the list sorts where you expect.</span>
    </div>
    <div class="field field--half">
      <label for="platform_id">Platform</label>
      <select id="platform_id" name="platform_id" required>
        <option value="">Choose one</option>
        <?php foreach ($platforms as $p): ?>
          <option value="<?= (int) $p['id'] ?>"
            <?= (int) ($title['platform_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
            <?= e($p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <span class="hint">A title is per machine. The other versions link to each other automatically.</span>
    </div>
  </div>

  <div class="fieldrow">
    <div class="field field--third">
      <label for="category_id">Type</label>
      <select id="category_id" name="category_id">
        <option value="">Unfiled</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>"
            <?= (int) ($title['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
            <?= e($c['label'] ?? $c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php
      // Same: the category is the genre. One control, not two.
      ?>
    <div class="field field--third">
      <label for="release_year">Year</label>
      <input id="release_year" name="release_year" type="number" min="1950" max="<?= (int) date('Y') + 1 ?>"
             value="<?= $v('release_year') ?>">
    </div>
  </div>

  <div class="fieldrow">
    <div class="field field--half">
      <label for="developer_name">Developer</label>
      <input id="developer_name" name="developer_name" list="companies" maxlength="160"
             value="<?= e((string) ($title['developer_name'] ?? '')) ?>">
      <span class="hint">Type a new name and it gets created.</span>
    </div>
    <div class="field field--half">
      <label for="publisher_name">Publisher</label>
      <input id="publisher_name" name="publisher_name" list="companies" maxlength="160"
             value="<?= e((string) ($title['publisher_name'] ?? '')) ?>">
    </div>
  </div>

  <datalist id="companies">
    <?php foreach ($companies as $c): ?>
      <option value="<?= e($c['name']) ?>"></option>
    <?php endforeach; ?>
  </datalist>

  <div class="fieldrow">
    <div class="field field--third">
      <label for="release_date">Exact release date</label>
      <input id="release_date" name="release_date" type="date" value="<?= $v('release_date') ?>">
    </div>
    <div class="field field--third">
      <label for="language">Language</label>
      <input id="language" name="language" maxlength="80" value="<?= $v('language') ?>">
    </div>
    <div class="field field--third">
      <label for="region">Region</label>
      <input id="region" name="region" maxlength="80" value="<?= $v('region') ?>">
    </div>
  </div>

  <?php
  // Which platforms this game exists on.
  //
  // Not a list on this row: a title is one release on one machine, because the Amiga and
  // Mega Drive versions are different artefacts with different developers and packaging.
  // They are tied together by a shared work key, and this is where you say so - it used
  // to be derived from the name alone, which quietly broke the moment one release had a
  // subtitle or a regional rename.
  ?>
  <?php
  // Which template this release follows.
  //
  // The software counterpart of picking a machine model on a hardware entry: choose one
  // and the box contents below arrive already filled in. Optional - a title typed by
  // hand is a perfectly good title.
  ?>
  <div class="field">
    <label for="software_model_id">Made from</label>
    <select id="software_model_id" name="software_model_id">
      <option value="">Nothing — I will fill it in myself</option>
      <?php foreach (($swModels ?? []) as $m): ?>
        <option value="<?= (int) $m['id'] ?>"
                <?= (int) ($title['software_model_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>>
          <?= e((string) $m['name']) ?><?= empty($m['platform_name']) ? '' : ' — ' . e((string) $m['platform_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <span class="hint">
      A software model says what this kind of release usually holds. Picking one fills in
      the box contents below; change them afterwards, because the model describes what is
      usual and this records what your release actually shipped with.
    </span>
  </div>

  <fieldset>
    <legend>Other platforms</legend>
    <?php if (!empty($siblings)): ?>
      <p class="label" style="margin:0 0 .3rem">Already the same work as</p>
      <div class="chips" style="margin-bottom:.7rem">
        <?php foreach ($siblings as $sib): ?>
          <a class="chip" href="<?= e(url('/titles/' . (int) $sib['id'])) ?>">
            <?= e((string) $sib['platform_name']) ?>
            <span class="hint">· <?= e((string) $sib['name']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="formgrid">
      <div class="field formgrid--wide">
        <label for="same_work_as">Same game as</label>
        <select id="same_work_as" name="same_work_as">
          <option value="">— not linked —</option>
          <?php foreach (($works ?? []) as $w): ?>
            <option value="<?= (int) $w['id'] ?>">
              <?= e((string) $w['name']) ?>
              — <?= e((string) $w['platform_name']) ?><?= $w['release_year'] ? ', ' . (int) $w['release_year'] : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint">
          Pick any other release of the same game and this one joins it. Leave it alone to
          keep the current grouping.
        </span>
      </div>
    </div>
  </fieldset>

  <?php
  // What the box should contain. The release knows this; a copy answers it.
  // Same shape as a hardware model's specification rows, and the same controls.
  ?>
  <fieldset>
    <?php
    // "Included in the box", as on the software model editor: both describe the release
    // rather than a copy of it. The entry form keeps "What is in the box", because there
    // it means what *this* copy actually has, which is a different question.
    ?>
    <legend>Included in the box</legend>
    <p class="hint" style="margin:0 0 .6rem">
      Manual, registration card, map, poster, the disks themselves &mdash; whatever a
      complete copy has. Filing a copy offers this list to tick off.
    </p>
    <div data-tc-rows>
      <?php foreach (($contents ?: [[]]) as $row): ?>
        <div class="mfrow" data-tc-row>
          <input type="text" name="content_label[]" maxlength="120" aria-label="Item"
                 placeholder="Manual" value="<?= e((string) ($row['label'] ?? '')) ?>">
          <input type="text" name="content_note[]" maxlength="255" aria-label="Note"
                 placeholder="48 pages, stapled" value="<?= e((string) ($row['note'] ?? '')) ?>">
          <span class="mfrow__move">
            <button type="button" class="btn btn--sm" data-tc-up title="Move up">&uarr;</button>
            <button type="button" class="btn btn--sm" data-tc-down title="Move down">&darr;</button>
            <button type="button" class="btn btn--sm" data-tc-addafter title="Add below">+</button>
            <button type="button" class="btn btn--sm" data-tc-remove title="Remove">&times;</button>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <div class="field">
    <label for="external_url">Reference URL</label>
    <input id="external_url" name="external_url" type="url" maxlength="500" value="<?= $v('external_url') ?>">
    <span class="hint">Lemon Amiga, CSDb, Generation MSX, MobyGames.</span>
  </div>

  <div class="field">
    <label for="synopsis">Synopsis</label>
    <textarea id="synopsis" name="synopsis" rows="4"><?= e((string) ($title['synopsis'] ?? '')) ?></textarea>
  </div>

  <div style="display:flex;gap:.6rem;margin-top:1.25rem;align-items:center">
    <button class="btn btn--accent" type="submit"><?= $isNew ? 'Create title' : 'Save changes' ?></button>
    <a class="btn" href="<?= e($isNew ? url('/titles') : url('/titles/' . (int) $title['id'])) ?>">Cancel</a>

    <?php if (!$isNew): ?>
      <span style="flex:1"></span>
      <button class="btn btn--danger" type="submit" name="action" value="delete"
              data-confirm="Remove this title? Entries pointing at it keep their own details.">
        Delete title
      </button>
    <?php endif; ?>
  </div>
</form>
