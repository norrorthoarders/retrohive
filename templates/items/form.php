<?php
/** @var array|null $item @var array $images @var string $tagCsv */
/** @var array $platforms @var array $genres @var array $companies */
// A draft is an item-shaped array with a null id: it fills the form, and it is
// still an add.
$isEdit = $item !== null && !empty($item['id']);
$action = $isEdit ? url('/items/' . $item['id']) : url('/items');
$val = function (string $key, $default = '') use ($item) {
    $v = $item[$key] ?? null;
    return ($v === null || $v === '') ? $default : $v;
};
?>

<div class="pagehead">
  <div>
    <span class="eyebrow"><?= $isEdit ? 'Editing' : 'New entry' ?></span>
    <h1><?= $isEdit ? e($item['title']) : 'Add a software title' ?></h1>
  </div>
  <div class="pagehead__actions">
    <?php if ($isEdit): ?>
      <?php if (any_metadata_provider()): ?>
        <a class="btn" href="<?= e(url('/metadata/lookup', ['item' => $item['id']])) ?>">Look up metadata</a>
      <?php endif; ?>
      <a class="btn" href="<?= e(url('/items/' . $item['id'])) ?>">View entry</a>
    <?php endif; ?>
    <a class="btn" href="<?= e(url('/items')) ?>">Back to collection</a>
  </div>
</div>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>

  <fieldset>
    <legend>Structure</legend>
      <div class="formgrid">
        <?php
        // No Library field.
        //
        // It was a read-only restatement of the switcher in the header, which is already
        // on screen a few centimetres above - and a form that repeats what the chrome
        // already says makes the form longer without making it clearer. The value still
        // travels, as a hidden field.
        //
        // An entry being edited keeps its own library regardless of what the header
        // says: changing shelves is a move, not a side effect of navigating.
        $filesInto = (int) ($item['library_id'] ?? 0) ?: (int) ($libraryHere ?? 0);
        ?>
        <input type="hidden" name="library_id" value="<?= $filesInto ?>">

      

      <div class="field field--half">
        <label for="platform_id">Platform</label>
        <select id="platform_id" name="platform_id" required data-platform-select>
          <option value="">Choose…</option>
          <?php foreach ($platforms as $p): ?>
            <option value="<?= (int) $p['id'] ?>"
                    <?= (int) $val('platform_id', 0) === (int) $p['id'] ? 'selected' : '' ?>>
              <?= e($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field field--half">
        <label for="category_id">Place in catalogue</label>
        <select id="category_id" name="category_id" required data-category-select>
          <option value="">Choose…</option>
          <?php
          // Grouped by domain so hardware and software do not interleave, and
          // each option carries its whole path - six branches called "Adapters"
          // are indistinguishable otherwise.
          $wantDomain = input('domain');
          $lastDomain = null;
          foreach ($nodes as $n):
              if ($wantDomain && $n['domain'] !== $wantDomain) { continue; }
              if ($n['domain'] !== $lastDomain):
                  if ($lastDomain !== null): ?></optgroup><?php endif;
                  $lastDomain = $n['domain']; ?>
                  <optgroup label="<?= e(ucfirst($n['domain'])) ?>">
              <?php endif; ?>
              <option value="<?= (int) $n['id'] ?>"
                      data-platform="<?= (int) ($n['platform_id'] ?? 0) ?>"
                    <?php // Whether a lookup here would ask anybody, so the
                          // "Save and look up" button can follow the choice. ?>
                    data-sources="<?= !empty($n['has_sources']) ? '1' : '0' ?>"
                      <?= (int) $val('category_id', 0) === (int) $n['id'] ? 'selected' : '' ?>>
                <?= e($n['label']) ?>
              </option>
          <?php endforeach; ?>
          <?php if ($lastDomain !== null): ?></optgroup><?php endif; ?>
        </select>
      </div>

      </div>
    </fieldset>

    <fieldset>
      <legend>The object</legend>
    <div class="formgrid">
      <?php
      // Just the name. The typeahead that linked this to an already-catalogued title
      // is gone for now - the link is still carried when something else sets it, so an
      // entry created from a title page keeps its title_id.
      ?>
      <?php
      // Software model: the counterpart to the hardware form's Machine model.
      //
      // Says what shape of release this is - an Amiga boxed game on 3.5-inch disks, a
      // PC big box - and with it the box contents and specifications that shape usually
      // carries. Optional, because plenty of software arrives loose and a model that
      // does not fit is worse than none.
      //
      // Narrowed by the platform above, like every other picker on this form.
      ?>
      <div class="field field--half">
        <label for="software_model_id">Software model</label>
        <select id="software_model_id" name="software_model_id" data-swmodel-select
                data-presets="<?= e(json_encode($swPresets ?? [], JSON_UNESCAPED_UNICODE)) ?>">
          <option value="">Not a specific kind of release</option>
          <?php foreach (($swModels ?? []) as $sm): ?>
            <option value="<?= (int) $sm['id'] ?>"
                    data-platform="<?= (int) ($sm['platform_id'] ?? 0) ?>"
                    <?= (int) $val('software_model_id', 0) === (int) $sm['id'] ? 'selected' : '' ?>>
              <?= e((string) $sm['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (($swModels ?? []) === []): ?>
          <span class="hint">
            No software models in this library yet —
            <a href="<?= e(url('/manage/software-models')) ?>">define one</a>, or
            <a href="<?= e(url('/libraries/' . (int) ($libraryHere ?? 0) . '/edit')) ?>">synchronise</a>
            with Software models ticked.
          </span>
        <?php else: ?>
        <?php endif; ?>
      </div>

            <?php
      // Status at the top, with the title.
      //
      // It decides how the rest of the page reads - a thing you own, a thing you
      // are after, a thing you sold - and it was near the bottom beside where the
      // thing is kept, which is a question about a shelf rather than about the
      // entry.
      ?>
      <div class="field field--quarter">
        <label for="status">Status</label>
        <select id="status" name="status" data-toggle-when-sold="sale">
          <?php foreach (status_options() as $opt): ?>
            <option value="<?= e($opt) ?>" <?= $val('status', 'owned') === $opt ? 'selected' : '' ?>><?= e(status_label($opt)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field formgrid--wide">
        <label for="title">Title</label>
        <input id="title" name="title" type="text" required maxlength="220"
               value="<?= e($val('title')) ?>" autofocus autocomplete="off">
        <input type="hidden" name="title_id" value="<?= (int) ($item['title_id'] ?? input_int('title_id') ?? 0) ?>">
        <?php if (!empty($item['title_id'])): ?>
          <span class="hint">
            Linked to the title
            <a href="<?= e(url('/titles/' . (int) $item['title_id'])) ?>"><?= e((string) $item['title_name']) ?></a>.
            Anything you leave blank below is inherited from it.
          </span>
        <?php endif; ?>
      </div>
      <div class="field">
        <label for="subtitle">Subtitle or edition</label>
        <input id="subtitle" name="subtitle" type="text" maxlength="220" value="<?= e($val('subtitle')) ?>" placeholder="Data Disk 2, Gold Edition…">
      </div>
      <div class="field">
        <label for="sort_title">Sort as</label>
        <input id="sort_title" name="sort_title" type="text" maxlength="220" value="<?= e($val('sort_title')) ?>" placeholder="Leave blank to sort by title">
        <span class="hint">Use it to file "The Settlers" under S.</span>
      </div>

      <?php
      // Which machines it runs on.
      //
      // The same list, the same table and the same rule as a card's "fits": a game may
      // need an A1200 rather than any Amiga, and saying so is worth a tick. Leave every
      // box clear and it means what it always meant - the whole platform.
      $runsOn = array_map('intval', $fits['ids'] ?? []);
      // The environments this copy is already marked as running under. Read here rather
      // than passed in, because it is one query and only this form wants it.
      $runsUnder = !empty($item['id'])
          ? array_map('intval', array_column(
              all('SELECT os_id FROM item_environments WHERE item_id = ?', [(int) $item['id']]),
              'os_id'))
          : [];
      $byPlat = [];
      foreach (($compatibleModels ?? []) as $fm) {
          $byPlat[(string) ($fm['platform_slug'] ?? '')][] = $fm;
      }
      ?>
      <div class="field field--half">
        <label>Compatible hardware</label>
        <div class="fitsbox" data-fits-box>
          <?php foreach ($byPlat as $pslug => $group): ?>
            <p class="fitsbox__group"><?= e($pslug !== '' ? $pslug : 'other') ?></p>
            <?php foreach ($group as $fm): ?>
              <label class="checkline">
                <input type="checkbox" name="item_compatibility[]" value="<?= (int) $fm['id'] ?>"
                       data-platform="<?= (int) ($fm['platform_id'] ?? 0) ?>"
                       <?= in_array((int) $fm['id'], $runsOn, true) ? 'checked' : '' ?>>
                <?= e($fm['name']) ?>
              </label>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php if ($byPlat === []): ?>
            <p class="hint" style="margin:0">
              No machine models on file yet, so there is nothing to be specific about.
            </p>
          <?php endif; ?>
        </div>
      </div>

      <div class="field field--half">
        <?php
        // The same kind of box as the hardware beside it, and for the same reason.
        //
        // This was one select, which could record exactly one answer - and a boxed PC
        // release from 1995 commonly runs on MS-DOS *and* Windows 3.x. Whichever the
        // person picked, the catalogue then said the others were untrue. Ticks, and
        // item_environments behind them.
        $envByPlat = [];
        foreach (($operatingSystems ?? []) as $os) {
            $envByPlat[(string) ($os['platform_slug'] ?? '')][] = $os;
        }
        ?>
        <label>Compatible environments</label>
        <div class="fitsbox" data-env-box>
          <?php foreach ($envByPlat as $pslug => $group): ?>
            <p class="fitsbox__group"><?= e($pslug !== '' ? $pslug : 'other') ?></p>
            <?php foreach ($group as $os): ?>
              <label class="checkline">
                <input type="checkbox" name="item_environments[]" value="<?= (int) $os['id'] ?>"
                       data-platform="<?= (int) ($os['platform_id'] ?? 0) ?>"
                       <?= in_array((int) $os['id'], $runsUnder ?? [], true) ? 'checked' : '' ?>>
                <?= e((string) $os['name']) ?>
              </label>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php if ($envByPlat === []): ?>
            <p class="hint" style="margin:0">
              No environments in this library yet.
              <a href="<?= e(url('/libraries/' . (int) ($libraryHere ?? 0) . '/edit')) ?>">Synchronise this library</a>
              with Environments ticked — they arrive with the platforms when
              you <a href="<?= e(url('/profile/access')) ?>">synchronise it</a>.
            </p>
          <?php endif; ?>
        </div>
      </div>

      <?php
      // No genre picker. A genre is a category - "Games > Racing" is a leaf like any
      // other - so "Place in catalogue" above already says it. This posted genre_id,
      // which nothing reads any more: a control that looked like it did something.
      ?>
    </div>
  </fieldset>

  <fieldset>
    <legend>Who made it, and when</legend>
    <div class="formgrid">
      <?php
      // One box each, with suggestions.
      //
      // The datalist behind these was never populated - the form was given no company
      // list at all - so the suggestions were empty and every studio had to be spelled
      // from memory, which is how a library ends up holding "Team17", "Team 17" and
      // "team17". It is populated now, and one box that suggests beats a box plus a
      // select that can disagree with it.
      ?>
      <div class="field">
        <label for="developer_name">Developer</label>
                <input id="developer_name" name="developer_name" type="text" list="company-list"
               maxlength="160" value="<?= e($val('developer_name')) ?>"
              >
      </div>
      <div class="field">
        <label for="publisher_name">Publisher</label>
                <input id="publisher_name" name="publisher_name" type="text" list="company-list"
               maxlength="160" value="<?= e($val('publisher_name')) ?>"
              >
      </div>
      <div class="field field--quarter">
        <label for="release_year">Release year</label>
        <input id="release_year" name="release_year" type="number" min="1950" max="<?= (int) date('Y') + 1 ?>" value="<?= e($val('release_year')) ?>">
      </div>
      <div class="field field--quarter">
        <label for="release_date">Exact release date</label>
        <input id="release_date" name="release_date" type="date" value="<?= e($val('release_date')) ?>">
      </div>
      <div class="field formgrid--wide">
        <label for="external_url">Reference link</label>
        <input id="external_url" name="external_url" type="url" maxlength="500" value="<?= e($val('external_url')) ?>" placeholder="https://www.lemonamiga.com/games/details.php?id=…">
        <span class="hint">Lemon Amiga, CSDb, Generation MSX, MobyGames — wherever you look this up.</span>
      </div>
    </div>
    <datalist id="company-list">
      <?php foreach (($companies ?? []) as $co): ?><option value="<?= e($co['name']) ?>"></option><?php endforeach; ?>
    </datalist>
  </fieldset>

  <fieldset>
    <legend>Your assessment</legend>
    <div class="formgrid">
      <?php
        // No rating.
        //
        // Condition already grades the object, and a second one-to-ten score invited the
        // question of which one the dashboard means. Whether you liked the game is not
        // what a catalogue of copies is for.
        ?>
      <div class="field">
        <label for="condition_grade">Condition</label>
        <select id="condition_grade" name="condition_grade">
          <?php foreach (condition_options() as $opt): ?>
            <option value="<?= e($opt) ?>" <?= $val('condition_grade', 'unknown') === $opt ? 'selected' : '' ?>><?= e(condition_label($opt)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="completeness">Completeness</label>
        <select id="completeness" name="completeness">
          <?php foreach (completeness_options() as $opt): ?>
            <option value="<?= e($opt) ?>" <?= $val('completeness', 'unknown') === $opt ? 'selected' : '' ?>><?= e(completeness_label($opt)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      
      
    </div>

    <?php
    // What is actually in this box.
    //
    // Three states per line, not a tick: an unanswered line means nobody has looked,
    // which is a different fact from having checked and found the manual gone. A
    // completeness list that cannot tell those apart is not worth keeping.
    //
    // Prefilled from the release where one is chosen, so the first thing you do is
    // answer the list rather than type it.
    ?>
    <?php
    // Whether there is a box at all.
    //
    // The hardware form has asked this since boxes were added; the software form
    // never did, and the controller inferred it from whether a box grade had been
    // set - so a loose Super Nintendo cartridge with no box still showed a box
    // grade, a contents list and three lines asking what was inside it. A
    // cartridge on its own is the normal way for a console game to survive.
    $hasBox = (int) $val('has_box', 1) === 1;
    ?>
    <input type="hidden" name="has_box_declared" value="1">
    <fieldset>
      <legend>
        <label class="checkline" style="font:inherit;margin:0">
          <input type="checkbox" name="has_box" value="1" data-toggle="box"
                 <?= $hasBox ? 'checked' : '' ?>>
          There is a box or case for it
        </label>
      </legend>
      <?php
      // A little air. The tick sits directly under Condition and Completeness
      // and read as though it belonged to them rather than to the section it
      // opens.
      ?>
      <div style="height:.6rem"></div>
      <p class="hint" data-toggle-empty="box" <?= $hasBox ? 'hidden' : '' ?> style="margin:0">
        Loose — just the media, and no box to record the contents or condition of.
      </p>
      <div data-toggle-body="box" <?= $hasBox ? '' : 'hidden' ?>>
      <?php
      // Named, because the list underneath it is about what came in the box and
      // the fieldset's own legend is about whether there is one at all. Two
      // different questions, and the second was being asked with no label.
      ?>
      <p class="label" style="margin:.2rem 0 .5rem">Packaging contents</p>
      <div data-ic-rows>
        <?php // No blank row by default: the button below is the empty state. ?>
        <?php foreach (($boxContents ?: []) as $row): ?>
          <div class="mfrow" data-ic-row>
            <input type="text" name="content_label[]" maxlength="120" aria-label="Item"
                   placeholder="Manual" value="<?= e((string) ($row['label'] ?? '')) ?>">
            <select name="content_present[]" aria-label="Is it there">
              <?php foreach (['unknown' => 'Not checked', 'yes' => 'Present', 'no' => 'Missing'] as $k => $lbl): ?>
                <option value="<?= e($k) ?>" <?= (string) ($row['present'] ?? 'unknown') === $k ? 'selected' : '' ?>>
                  <?= e($lbl) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="mfrow__move">
              <button type="button" class="btn btn--sm" data-ic-up title="Move up">&uarr;</button>
              <button type="button" class="btn btn--sm" data-ic-down title="Move down">&darr;</button>
              <button type="button" class="btn btn--sm" data-ic-addafter title="Add below">+</button>
              <button type="button" class="btn btn--sm" data-ic-remove title="Remove">&times;</button>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
      <template data-ic-template>
        <div class="mfrow" data-ic-row>
          <input type="text" name="content_label[]" maxlength="120" aria-label="Item" placeholder="Manual">
          <select name="content_present[]" aria-label="Is it there">
            <option value="unknown">Not checked</option>
            <option value="yes">Present</option>
            <option value="no">Missing</option>
          </select>
          <span class="mfrow__move">
            <button type="button" class="btn btn--sm" data-ic-up title="Move up">&uarr;</button>
            <button type="button" class="btn btn--sm" data-ic-down title="Move down">&darr;</button>
            <button type="button" class="btn btn--sm" data-ic-addafter title="Add below">+</button>
            <button type="button" class="btn btn--sm" data-ic-remove title="Remove">&times;</button>
          </span>
        </div>
      </template>
      <button type="button" class="btn btn--wide" data-ic-add>Add an item</button>
      <span class="hint">
        Leave a line as <em>Not checked</em> until you have looked. Choosing a title or a
        software model above fills this in from what that release should contain.
      </span>
      </div>
    </fieldset>

    <div class="formgrid" style="margin-top:.9rem">
      <div class="field" data-toggle-body="box" <?= $hasBox ? '' : 'hidden' ?>>
        <label for="condition_box">Box condition</label>
        <select id="condition_box" name="condition_box">
          <?php foreach (component_condition_options() as $opt): ?>
            <option value="<?= e($opt) ?>" <?= $val('condition_box', 'unknown') === $opt ? 'selected' : '' ?>><?= e(condition_label($opt)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="condition_manual">Manual condition</label>
        <select id="condition_manual" name="condition_manual">
          <?php foreach (component_condition_options() as $opt): ?>
            <option value="<?= e($opt) ?>" <?= $val('condition_manual', 'unknown') === $opt ? 'selected' : '' ?>><?= e(condition_label($opt)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="condition_media">Media condition</label>
        <select id="condition_media" name="condition_media">
          <?php foreach (component_condition_options() as $opt): ?>
            <option value="<?= e($opt) ?>" <?= $val('condition_media', 'unknown') === $opt ? 'selected' : '' ?>><?= e(condition_label($opt)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>The physical copy</legend>
    <div class="formgrid">
      <?php
      // Rows, the same as the software model editor has. This was one free-text
      // box and a count beside it, which cannot say "a cartridge and a manual
      // disk" - and the vocabulary was a seven-item datalist of its own rather
      // than the shared one, so what somebody typed here and what a model offered
      // were two different lists.
      ?>
      <div class="field formgrid--wide">
        <?php partial('media_rows', ['media' => $itemMedia ?? []]); ?>
      </div>
      <div class="field">
        <label for="barcode">Barcode</label>
        <input id="barcode" name="barcode" type="text" maxlength="40" value="<?= e($val('barcode')) ?>">
      </div>
      <div class="field">
        <label for="language">Language</label>
        <input id="language" name="language" type="text" maxlength="80" value="<?= e($val('language')) ?>" placeholder="English, Swedish, multi">
      </div>
      <div class="field">
        <label for="region">Region</label>
        <?php
        // A short list rather than free text: these are the ones that change what the
        // thing actually is, and typing them by hand produced "PAL", "Pal" and "pal".
        // Anything already stored that is not in the list is kept and offered.
        $regions = ['PAL', 'NTSC-U/C', 'NTSC-J', 'Region free'];
        $haveRegion = (string) $val('region');
        if ($haveRegion !== '' && !in_array($haveRegion, $regions, true)) {
            $regions[] = $haveRegion;
        }
        ?>
        <select id="region" name="region">
          <option value="">Not known</option>
          <?php
          // "Region free" belongs in the list: a great deal of Amiga and PC software
          // simply has no region, and leaving it blank says "not recorded" rather than
          // "does not apply".
          ?>
          <?php foreach ($regions as $r): ?>
            <option value="<?= e($r) ?>" <?= $haveRegion === $r ? 'selected' : '' ?>><?= e($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </fieldset>


  <?php
  // Where it lives, in its own section - the same as the hardware form.
  //
  // These three were scattered: status sat under "Your assessment" beside the condition
  // grades, and the location under "The physical copy" beside the media type. They
  // answer one question between them - where is this thing and is it still here - and
  // asking it in two places meant scrolling between them to change a shelf.
  //
  // The library is known from the header long before the entry has one, so the location
  // list is resolved from there when the entry has no library of its own yet.
  $locHere = (int) $val('library_id', 0);
  if ($locHere <= 0) {
      $locHere = (int) ($libraryHere ?? 0);
  }
  if ($locHere <= 0) {
      $mineNow = working_library();
      $locHere = $mineNow === null ? 0 : (int) $mineNow['id'];
  }
  $places = location_options($locHere > 0 ? $locHere : null);
  ?>
  <fieldset>
    <legend>Where it lives</legend>
    <div class="formgrid">
      <div class="field field--half">
        <label for="location_id">Location</label>
        <select id="location_id" name="location_id">
          <option value="">Not filed anywhere</option>
          <?php foreach ($places as $loc): ?>
            <option value="<?= (int) $loc['id'] ?>"
                    <?= (int) $val('location_id', 0) === (int) $loc['id'] ? 'selected' : '' ?>>
              <?= e($loc['label']) ?><?= $loc['floor'] === null ? '' : ' · ' . e(floor_label($loc['floor'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint">
          <?php if ($places === []): ?>
            No locations in this library yet.
            <a href="<?= e(url('/manage/locations')) ?>">Set some up</a> — a room, a
            cabinet, a shelf, as deep as you find useful.
          <?php else: ?>
            <a href="<?= e(url('/manage/locations')) ?>">Manage locations</a>
          <?php endif; ?>
        </span>
      </div>
      <div class="field field--quarter">
        <label for="location_position">Whereabouts</label>
        <input id="location_position" name="location_position" type="text" maxlength="40"
               value="<?= e($val('location_position')) ?>" placeholder="1, a, left, third row">
      </div>
    </div>
  </fieldset>

  <?php
  // The same shape as the hardware form.
  //
  // This was one folded accordion holding acquisition, value, lending and sale at once -
  // and somewhere in the moving about it grew a second copy of the currency and value
  // boxes. Four groups under one heading, each saying what it is for, is what the
  // hardware form does and it reads better.
  //
  // Acquire is a tick, because "I do not know when I got this" is the ordinary case for
  // half a collection and an empty date is not the same as a date that does not apply.
  // Unticked clears the group on save - see items_payload().
  $hasAcquire = $item === null
      ? false
      : ($val('acquired_on') !== '' || $val('acquired_from') !== ''
         || $val('acquired_price') !== '' || $val('acquired_note') !== '');
  $hasSale = $val('status', 'owned') === 'sold';
  ?>
  <fieldset>
    <legend>Coming and going</legend>
    <p class="lede" style="font-size:.9rem;margin-top:0">
      How it arrived, what it is worth, who has it, and whether it has gone.
    </p>

    <input type="hidden" name="provenance_declared" value="1">

    <h3 class="subhead">
      <label class="checkline" style="font:inherit;margin:0">
        <input type="checkbox" name="has_acquire" value="1" data-toggle="acquire"
               <?= $hasAcquire ? 'checked' : '' ?>>
        Acquire
      </label>
    </h3>
    <p class="hint" data-toggle-empty="acquire" <?= $hasAcquire ? 'hidden' : '' ?>
       style="margin:0 0 .7rem">
      Not recorded. Tick it to say when this came in, from whom and for how much.
    </p>
    <div class="formgrid" data-toggle-body="acquire" <?= $hasAcquire ? '' : 'hidden' ?>>
      <div class="field field--quarter">
        <label for="acquired_on">When</label>
        <input id="acquired_on" name="acquired_on" type="date" value="<?= e($val('acquired_on')) ?>">
        <span class="hint">Optional. Leave it blank if you do not know.</span>
      </div>
      <div class="field field--quarter">
        <label for="acquired_from">From</label>
        <input id="acquired_from" name="acquired_from" type="text" maxlength="140"
               value="<?= e($val('acquired_from')) ?>" placeholder="Tradera, a friend, a flea market…">
      </div>
      <div class="field field--quarter">
        <label for="acquired_price">Price acquired</label>
        <input id="acquired_price" name="acquired_price" type="number" step="0.01" min="0"
               value="<?= e($val('acquired_price')) ?>">
      </div>
      <div class="field field--quarter">
        <label for="currency">Currency</label>
        <select id="currency" name="currency">
          <?php $cur = strtoupper($val('currency', (string) config('currency')));
                foreach (currency_options() as $code => $label): ?>
            <option value="<?= e($code) ?>" <?= $cur === $code ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">What you paid in.</span>
      </div>
      <div class="field formgrid--wide">
        <label for="acquired_note">Notes</label>
        <textarea id="acquired_note" name="acquired_note" rows="4"
                  placeholder="Came with the original receipt in the box"><?= e($val('acquired_note')) ?></textarea>
      </div>
    </div>

    <h3 class="subhead">Worth</h3>
    <div class="formgrid">
      <div class="field field--quarter">
        <label for="current_value">What it is worth now</label>
        <input id="current_value" name="current_value" type="number" step="0.01" min="0"
               value="<?= e($val('current_value')) ?>">
      </div>
      <div class="field field--quarter">
        <?php
        // The same currency as the purchase, because it is the same entry - one column,
        // shown twice so a value is never a bare number with no unit on it.
        ?>
        <label for="value_currency">Currency</label>
        <select id="value_currency" name="currency">
          <?php $curNow = strtoupper($val('currency', (string) config('currency')));
                foreach (currency_options() as $code => $label): ?>
            <option value="<?= e($code) ?>" <?= $curNow === $code ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field field--quarter">
        <label for="valued_on">Valued on</label>
        <input id="valued_on" name="valued_on" type="date" value="<?= e($val('valued_on')) ?>">
      </div>
    </div>

    <?php
    // No lending block.
    //
    // Hardware has never had one, and three date boxes for something the status field
    // already says was the only place the two forms disagreed on what an
    // entry is. If lending needs recording properly it wants a proper log, not three
    // columns that go stale the moment something comes back.
    ?>
    </div>

    <h3 class="subhead">Sale</h3>
    <p class="hint" data-toggle-empty="sale" <?= $hasSale ? 'hidden' : '' ?>
       style="margin:0 0 .7rem">
      Still owned, so there is nothing to record. Set the status to
      <strong>Sold</strong> above and this section opens.
    </p>
    <div class="formgrid" data-toggle-body="sale" <?= $hasSale ? '' : 'hidden' ?>>
      <div class="field field--quarter">
        <label for="sold_on">When</label>
        <input id="sold_on" name="sold_on" type="date" value="<?= e($val('sold_on')) ?>">
        <span class="hint">Optional. Blank unless it is gone.</span>
      </div>
      <div class="field field--quarter">
        <label for="sold_to">To</label>
        <input id="sold_to" name="sold_to" type="text" maxlength="140" value="<?= e($val('sold_to')) ?>">
      </div>
      <div class="field field--quarter">
        <label for="sold_price">Price sold</label>
        <input id="sold_price" name="sold_price" type="number" step="0.01" min="0"
               value="<?= e($val('sold_price')) ?>">
      </div>
      <div class="field field--quarter">
        <?php
        // The same currency column as the purchase and the valuation - shown here too so
        // a sale price is never a bare number with no unit beside it.
        ?>
        <label for="sold_currency">Currency</label>
        <select id="sold_currency" name="currency">
          <?php $curSold = strtoupper($val('currency', (string) config('currency')));
                foreach (currency_options() as $code => $label): ?>
            <option value="<?= e($code) ?>" <?= $curSold === $code ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Notes and tags</legend>
    <div class="formgrid">
      <?php
      // The release's blurb, above the notes. A lookup fills this one; the notes
      // below stay whatever somebody wrote about their own copy, which is what
      // importing a description used to overwrite.
      ?>
      <div class="field formgrid--wide">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="4"
                  placeholder="What this release is — a lookup fills this in."><?= e($val('description')) ?></textarea>
        <span class="hint">About the release, not about your copy. The same for everybody who has one.</span>
      </div>
      <div class="field formgrid--wide">
        <label for="notes">Notes</label>
        <textarea id="notes" name="notes" rows="5" placeholder="Cracked intro by Fairlight, water damage on the back panel, bought at Retrospelsmässan…"><?= e($val('notes')) ?></textarea>
      </div>
      <div class="field formgrid--wide">
        <label for="tags">Tags</label>
        <input id="tags" name="tags" type="text" value="<?= e($tagCsv) ?>" placeholder="big box, sealed, nordic release">
        <span class="hint">Comma separated. New tags are created as you use them.</span>
      </div>
    </div>
  </fieldset>

  <?php
  // Two sections, split on provenance.
  //
  // Artwork the publisher made and photographs somebody took answer different
  // questions - "what does this release look like" against "what does my copy
  // look like" - and they were listed together in one grid, in upload order, so
  // a scan of the box sat between two photographs of a shelf. The metadata
  // agents write the first kind and only the first kind; everything uploaded
  // here is the second.
  $artwork = array_values(array_filter($images ?? [],
      fn(array $i): bool => ($i['provenance'] ?? 'personal') === 'official'));
  $personal = array_values(array_filter($images ?? [],
      fn(array $i): bool => ($i['provenance'] ?? 'personal') !== 'official'));
  ?>

  <?php if ($artwork !== []): ?>
  <fieldset>
    <legend>Stock images</legend>
    <p class="hint" style="margin-top:-.4rem">
      Downloaded from a metadata source. It describes the release, not your copy,
      and is the same for everybody who has one.
    </p>
    <?php partial('image_rows', ['images' => $artwork, 'domain' => $domain ?? 'software']); ?>
  </fieldset>
  <?php endif; ?>

  <fieldset>
    <legend>Photographs</legend>
    <p class="hint" style="margin-top:-.4rem">
      Your own, of your own copy.
    </p>
    <?php
    // What is already there, before the box for adding more. The form used
    // to offer only the dropzone, so a photograph could be added and never
    // removed from this screen.
    ?>
    <?php partial('image_rows', ['images' => $personal, 'domain' => $domain ?? 'software']); ?>
    <div class="formgrid">
      <div class="field formgrid--wide">
        <div class="dropzone" data-dropzone data-max="4">
          <div class="dropzone__prompt">
            <strong>Drop photos here</strong>
            <span>or click to browse, or paste from the clipboard</span>
          </div>
          <span class="dropzone__hint">
            JPEG, PNG, WebP or GIF, up to <?= round(((int) config('uploads.max_bytes')) / 1048576) ?> MB each.
            Four at a time.
          </span>
          <input id="images" name="images[]" type="file" accept="image/*" multiple>
          <div class="dropzone__list" data-dropzone-list></div>
        </div>
      </div>
      <?php
      // Which section, then what it shows. The section carries the provenance:
      // choosing "your photos of the box" is what stops a picture ending up
      // among the publisher's artwork, and it is one choice rather than a rule
      // somebody has to remember.
      $upSections = image_sections((string) ($domain ?? 'software'));
      $hasBoxNow  = (int) ($val('has_box', 1)) === 1;
      ?>
      <div class="field">
        <label for="image_section">Which set</label>
        <select id="image_section" name="image_section">
          <?php foreach ($upSections as $key => $sec): ?>
            <?php if (!empty($sec['needs_box']) && !$hasBoxNow) { continue; } ?>
            <option value="<?= e($key) ?>" <?= $sec['scrapable'] ? '' : 'selected' ?>>
              <?= e($sec['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="image_kind">What these photos show</label>
        <select id="image_kind" name="image_kind">
          <?php foreach (image_kind_options() as $k): ?>
            <option value="<?= e($k) ?>" <?= $k === 'box_front' ? 'selected' : '' ?>><?= e(image_kind_label($k)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </fieldset>

<?php partial('document_rows', ['documents' => $documents ?? []]); ?>

  <div class="formactions">
    <button class="btn btn--accent" type="submit"><?= $isEdit ? 'Save changes' : 'Add to collection' ?></button>
    <?php
    // The second way out of this form.
    //
    // Save it, then show what the sources say about it — which is a different
    // intention from "file this and get back to what I was doing", and worth its
    // own button rather than a step you have to remember afterwards.
    //
    // It saves first on purpose. A lookup applies its answer to a row, so doing
    // this before saving needed somewhere to keep half a form and an apply path
    // that wrote to a session instead of a table. With the entry already filed
    // there is nothing to stand in for it, and nothing is written automatically
    // either way: what comes back is the review screen, field by field.
    ?>
    <?php if (any_metadata_provider()): ?>
      <?php
      // Shown when the chosen branch has a source switched on.
      //
      // It used to appear whenever *any* source was configured anywhere, which on
      // a tree where sources are chosen per branch means offering to look
      // something up and then answering "asked: nobody". The script below hides it
      // until the choice makes it true; without JavaScript it stays visible, which
      // is the harmless way round.
      ?>
      <button class="btn" type="submit" name="after" value="lookup"
              data-lookup-button>Save and look up</button>
    <?php endif; ?>
    <a class="btn" href="<?= e($isEdit ? url('/items/' . $item['id']) : url('/items')) ?>">Cancel</a>
  </div>
</form>

<?php if ($isEdit): ?>
<section class="panel" style="margin-top:2rem">
  <h2 class="panel__title">Photos on file</h2>
  <?php if (!$images): ?>
    <p class="lede" style="margin:0">Nothing uploaded yet. Use the photo picker above and save.</p>
  <?php else: ?>
    <?php foreach ($images as $img): ?>
      <div class="imgrow">
        <img src="<?= e(image_url($img['filename'], 'thumb')) ?>" alt="<?= e(image_kind_label($img['kind'])) ?>">
        <form class="imgrow__controls" method="post" action="<?= e(url('/images/' . $img['id'])) ?>">
          <?= csrf_field() ?>
          <div class="field">
            <label for="kind-<?= (int) $img['id'] ?>">Shows</label>
            <select id="kind-<?= (int) $img['id'] ?>" name="kind">
              <?php foreach (image_kind_options() as $k): ?>
                <option value="<?= e($k) ?>" <?= $img['kind'] === $k ? 'selected' : '' ?>><?= e(image_kind_label($k)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="cap-<?= (int) $img['id'] ?>">Caption</label>
            <input id="cap-<?= (int) $img['id'] ?>" name="caption" type="text" maxlength="255" value="<?= e($img['caption'] ?? '') ?>">
          </div>
          <div class="field">
            <label for="ord-<?= (int) $img['id'] ?>">Order</label>
            <input id="ord-<?= (int) $img['id'] ?>" name="sort_order" type="number" value="<?= (int) $img['sort_order'] ?>">
          </div>
          <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:end">
            <button class="btn btn--sm" type="submit" name="action" value="save">Save</button>
            <?php if ((int) $img['is_primary'] !== 1): ?>
              <button class="btn btn--sm" type="submit" name="action" value="primary">Make cover</button>
            <?php else: ?>
              <span class="chip chip--on">Cover</span>
            <?php endif; ?>
            <button class="btn btn--sm btn--danger" type="submit" name="action" value="delete"
                    onclick="return confirm('Delete this photo?')">Delete</button>
          </div>
        </form>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
<?php endif; ?>
