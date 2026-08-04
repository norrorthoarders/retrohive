<?php
/**
 * Adding a piece of hardware.
 *
 * A separate form rather than the software one with fields hidden. A network
 * card has no genre, no developer and no release year, and asking for them -
 * even greyed out - makes the form about what it is not.
 *
 * @var array|null $item @var array $libraries * @var array $nodes @var array $hardware
 */
// $prefill carries answers the link already knew - "add a peripheral for this machine"
// knows the machine - without making the form think it is editing something.
$val = function (string $key, $default = '') use ($item, $prefill) {
    $v = $item[$key] ?? ($prefill[$key] ?? null);
    return ($v === null || $v === '') ? $default : $v;
};
$hw = $hardware ?? [];
$prefill = $prefill ?? [];
$hv = fn(string $k, $d = '') => $item ? (string) ($hw[$k] ?? $d) : $d;
$platformId = (int) $val('platform_id', 0);
?>

<div class="pagehead">
  <div>
    <span class="eyebrow"><?= $item ? 'Edit'
      : (($adding ?? 'machine') === 'part' ? 'A card, an expansion, a peripheral' : 'A computer or console') ?></span>
    <h1><?= $item ? e($item['title'])
        : (($adding ?? 'machine') === 'part' ? 'Add a peripheral' : 'Add a machine') ?></h1>
  </div>
  <div class="pagehead__actions">
    <?php
    // The lookup is the same one the software form offers, and the machinery behind
    // it has understood hardware all along - metadata_to_hardware_fields() maps a
    // suggestion onto processor, memory, interface and the rest, and the review
    // screen has a Hardware block for them. Only the way in was missing, so
    // TheRetroWeb and the Amiga Hardware Database could be configured, enabled and
    // never reachable from the one form they exist for.
    //
    // Editing only. A lookup applies its answer to an entry, so there has to be an
    // entry: on the add form the hint below says so rather than the button failing.
    ?>
    <?php if ($item !== null): ?>
      <?php if (any_metadata_provider()): ?>
        <a class="btn" href="<?= e(url('/metadata/lookup', ['item' => (int) $item['id']])) ?>">Look up metadata</a>
      <?php endif; ?>
      <a class="btn" href="<?= e(url('/items/' . (int) $item['id'])) ?>">View entry</a>
    <?php endif; ?>
    <a class="btn" href="<?= e(url('/hardware')) ?>">Back to hardware</a>
  </div>
</div>

<form method="post" action="<?= e(url($item ? '/items/' . $item['id'] : '/items')) ?>"
      enctype="multipart/form-data" class="panel">
  <?= csrf_field() ?>
  <?php // Where the browser's Edit link came from, so Save and Cancel return there. ?>
  <?php if (!empty($returnTo)): ?>
    <input type="hidden" name="return" value="<?= e((string) $returnTo) ?>">
  <?php endif; ?>
  <input type="hidden" name="domain" value="hardware">
  <input type="hidden" name="as" value="<?= e($adding ?? 'machine') ?>">
  <?php // Carried rather than asked: the machine settles which family it is. ?>

  <fieldset>
    <legend>What it is</legend>
    <div class="formgrid">
            <?php
      // Status at the top, with the name.
      //
      // It decides how the rest of the page reads - a thing you own, a thing you
      // are after, a thing you sold - and it sat near the bottom beside where the
      // thing is kept, which is a question about a shelf rather than about the
      // entry.
      ?>
      <div class="field field--quarter">
        <label for="status">Status</label>
        <select id="status" name="status" data-toggle-when-sold="sale">
          <?php foreach (status_options() as $st): ?>
            <option value="<?= e($st) ?>" <?= $val('status','owned') === $st ? 'selected' : '' ?>>
              <?= e(status_label($st)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field formgrid--wide">
        <label for="title">Object</label>
        <input id="title" name="title" type="text" required maxlength="220"
               data-title-from-model
               value="<?= e($val('title')) ?>" placeholder="Blizzard 1230 IV">
      </div>
      <?php
        // No Library field - the header switcher already says it, a few centimetres
        // above. The value still travels, as a hidden field; an entry being edited keeps
        // its own library regardless of the header, because changing shelves is a move
        // rather than a side effect of navigating.
        $filesInto = (int) ($item['library_id'] ?? 0) ?: (int) ($libraryHere ?? 0);
        ?>
        <input type="hidden" name="library_id" value="<?= $filesInto ?>">
      <?php
      // The platform is the question worth asking; the kind of machine is not.
      // A console is a console because the Mega Drive is, and the model settles
      // it - so the form no longer asks a question it can answer itself.
      // derived_category_id() reads the model, and failing that the platform's
      // class, so an entry is always filed as the right kind of thing.
      $wantRole = ($adding ?? 'machine') === 'machine' ? 'machine' : 'peripheral';
      ?>
      <div class="field field--half">
        <label for="platform_id">Platform</label>
        <?php
        // data-vendor so the company can narrow this, and only when a machine is
        // being added: a machine's maker built the platform, while a card's maker
        // usually did not - a Phase 5 accelerator goes in a Commodore machine, and
        // narrowing on the maker would drop the Amiga off the list.
        ?>
        <select id="platform_id" name="platform_id" required data-platform-select
                data-narrow-by-vendor="<?= ($adding ?? 'machine') === 'machine' ? '1' : '' ?>">
          <option value="">Choose…</option>
          <?php
          // This library's machines only. The form files into one library, so offering
          // another's Amiga beside it listed the same name twice.
          $byClass = platforms_by_class((int) ($libraryHere ?? 0) ?: null);
          foreach ($byClass as $class): ?>
            <optgroup label="<?= e($class['name']) ?>">
              <?php foreach ($class['rows'] as $pl): ?>
                <option value="<?= (int) $pl['id'] ?>"
                        data-slug="<?= e((string) $pl['slug']) ?>"
                        data-vendor="<?= (int) ($pl['vendor_id'] ?? 0) ?>"
                        <?= (int) $val('platform_id', 0) === (int) $pl['id'] ? 'selected' : '' ?>>
                  <?= e($pl['name']) ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
        <span class="hint">
          <?php if ($wantRole === 'machine'): ?>
            Which machine family this is. What kind of machine it counts as —
            computer, console, handheld — follows from it.
          <?php else: ?>
            Which machine family it is for. Only peripherals on the same
            platform can be fitted to a machine.
          <?php endif; ?>
        </span>
      </div>

      <div class="field field--half">
        <?php
        // Place in catalogue, for machines as well as peripherals.
        //
        // A machine's branch used to be derived from its model or its platform's
        // class and never asked for - which works while the tree is the shipped
        // one and answers wrongly the moment somebody arranges their own. The
        // tree now says what each branch holds, so the honest thing is to offer
        // the branches that say they hold this.
        //
        // Declared branches only: `other` means a branch that holds other
        // branches and nothing directly, and filing an entry there is what put
        // Superfrog under "Games" instead of under a kind of game.
        ?>
        <label for="category_id">Place in catalogue</label>
        <select id="category_id" name="category_id" required data-kind-select>
          <option value="">Choose…</option>
          <?php
          $offered = 0;
          foreach ($nodes as $n):
              // `kind`, not `role`: a branch under Peripherals is a peripherals
              // branch whether or not anybody declared it, and the leaf is where a
              // thing actually goes. Filtering on role offered the one branch
              // somebody declared and none of those beneath it.
              if (($n['kind'] ?? '') !== $wantRole) { continue; }
              $offered++; ?>
            <option value="<?= (int) $n['id'] ?>"
                    data-platform="<?= (int) ($n['platform_id'] ?? 0) ?>"
                    <?php // Whether a lookup here would ask anybody, so the
                          // "Save and look up" button can follow the choice. ?>
                    data-sources="<?= !empty($n['has_sources']) ? '1' : '0' ?>"
                    <?= (int) $val('category_id', 0) === (int) $n['id'] ? 'selected' : '' ?>>
              <?= e($n['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($offered === 0): ?>
          <?php // Nothing to choose is a fact about the tree, not about this form. ?>
          <span class="hint">
            No branch says it holds <?= $wantRole === 'machine' ? 'machines' : 'peripherals' ?> yet —
            set one in the <a href="<?= e(url('/manage/tree')) ?>">category editor</a>.
          </span>
        <?php endif; ?>
      </div>

      <div class="field field--half">
        <label for="vendor_id">Company</label>
        <select id="vendor_id" name="vendor_id" data-vendor-select>
          <option value="">Choose…</option>
          <?php foreach ($vendors as $vd): ?>
            <option value="<?= (int) $vd['id'] ?>" data-slug="<?= e((string) $vd['slug']) ?>"
                    <?= (int) ($selectedVendorId ?? 0) === (int) $vd['id'] ? 'selected' : '' ?>>
              <?= e($vd['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if (($adding ?? 'machine') === 'part'): ?>
      <?php
      // Read before either control below uses it: this used to be assigned
      // inside the Peripheral field, which renders after the compatibility box
      // that needs it, so the box got null and the page died mid-form.
      $fitsFrom  = $fits['from'] ?? 'none';
      $fitsNames = $fits['names'] ?? [];
      $compatibleIds   = array_map('intval', $fits['ids'] ?? []);
      ?>
      <div class="field field--half">
        <?php
        // Where it actually is, as against what it fits. One machine or none -
        // the same rule the machine's own list enforces, because it is the same
        // link read from the other end.
        $hostId    = (int) (($currentHost ?? null)['id'] ?? 0);
        $machines  = $installableMachines ?? [];
        ?>
        <label for="installed_in_item_id">Installed in machine</label>
        <?php if ($item === null): ?>
          <p class="hint" style="margin:.3rem 0 0">
            Save it first. Fitting is a link between two entries, so this one has
            to exist before it can be put in anything.
          </p>
        <?php elseif ($machines === [] && $hostId === 0): ?>
          <p class="hint" style="margin:.3rem 0 0">
            No machine catalogued that this fits. Add one and it appears here.
          </p>
        <?php else: ?>
          <select id="installed_in_item_id" name="installed_in_item_id">
            <option value="">Not installed</option>
            <?php
            // The current host is listed even when it falls outside the fits list,
            // so an existing arrangement is never silently dropped by the select
            // simply because the compatibility was recorded later.
            $seen = [];
            foreach ($machines as $mc):
                $seen[] = (int) $mc['id']; ?>
              <option value="<?= (int) $mc['id'] ?>" <?= $hostId === (int) $mc['id'] ? 'selected' : '' ?>>
                <?= e($mc['title']) ?><?= empty($mc['model_name']) ? '' : ' — ' . e((string) $mc['model_name']) ?>
              </option>
            <?php endforeach; ?>
            <?php if ($hostId > 0 && !in_array($hostId, $seen, true)): ?>
              <option value="<?= $hostId ?>" selected>
                <?= e((string) $currentHost['title']) ?> — where it is now
              </option>
            <?php endif; ?>
          </select>
          <span class="hint">
            <?php if ($fitsNames !== []): ?>
              Machines you have catalogued that take a <?= e(implode(', ', $fitsNames)) ?>.
            <?php else: ?>
              Machines you have catalogued on this platform. Record what it fits
              above to narrow this list.
            <?php endif; ?>
            A peripheral is in one machine or none.
          </span>
        <?php endif; ?>
      </div>

      <?php
      // Compatibility sits after "Installed in machine" rather than before it.
      //
      // It is the tall control on this form - a scrolling list of every machine on
      // the platform - and with it second in the row it left the cell beside
      // Company empty down the whole height of the box, so the two short selects
      // that follow were pushed below a screenful of tick boxes. Where it goes and
      // what it fits are also the same question asked twice, and asking the
      // narrower one first reads better: this card is in that machine, and here is
      // everything it could be in.
      ?>
      <div class="field field--half" data-fits-holder>
        <?php
        // Two states, not one control with an editable flag: what the model says
        // is not the operator's to change, and a disabled checkbox that looks
        // like a choice is worse than a sentence that is plainly a statement.
        //
        // This is compatibility - which machines the card *can* go in. It used to
        // be labelled "Installed in machine", which is a different question and
        // the one people actually came here to answer; that now has its own
        // control below.
        ?>
        <span class="label">Hardware compatibility</span>
        <?php
        // The same control in both states, and the same name it has on the
        // peripheral model screen. It used to render as a comma-separated sentence
        // when the model decided the answer and as a box of checkboxes otherwise -
        // two different-looking things saying the same thing, and the sentence gave
        // no hint that the full list of machines was even a fixed set.
        //
        // Read-only is expressed by disabling the inputs rather than by swapping
        // the widget: the ticks stay where a reader expects to find them. Disabled
        // inputs are not submitted, which is exactly right here - the model owns
        // the answer and items_store()/items_update() ignore a posted list anyway.
        $fitsLocked = $fitsFrom === 'model';
        $shownFits  = $fitsLocked ? array_map('intval', $fits['ids'] ?? []) : $compatibleIds;

        $byPlat = [];
        foreach (($compatibleModels ?? []) as $fm) {
            $byPlat[(string) ($fm['platform_slug'] ?? '')][] = $fm;
        }
        ?>
        <div class="fitsbox" data-fits-box<?= $fitsLocked ? ' data-fits-locked' : '' ?>>
          <?php foreach ($byPlat as $pslug => $group): ?>
            <p class="fitsbox__group"><?= e($pslug !== '' ? $pslug : 'other') ?></p>
            <?php foreach ($group as $fm): ?>
              <label class="checkline">
                <input type="checkbox" name="item_compatibility[]" value="<?= (int) $fm['id'] ?>"
                       data-platform="<?= (int) ($fm['platform_id'] ?? 0) ?>"
                       <?= in_array((int) $fm['id'], $shownFits, true) ? 'checked' : '' ?>
                       <?= $fitsLocked ? 'disabled' : '' ?>>
                <?= e($fm['name']) ?>
              </label>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php if ($byPlat === []): ?>
            <p class="hint" style="margin:0">No machine models yet, so there is nothing to fit.</p>
          <?php endif; ?>
        </div>
        <span class="hint">
          <?php if ($fitsLocked): ?>
            From the peripheral model, which decides this: a copy of a card cannot
            fit something the card does not. Change it on the model if it is wrong.
          <?php else: ?>
            Which machines this goes in. Tick as many as apply — a card that fits
            four machines fits four. Choose a peripheral model below and it answers
            this for you.
          <?php endif; ?>
        </span>
      </div>

      <div class="field field--half">
        <label for="model_id">Peripheral model</label>
        <select id="model_id" name="model_id" data-part-select>
          <option value="">Not on the list</option>
          <?php foreach ($parts as $pt): ?>
            <option value="<?= (int) $pt['id'] ?>"
                    data-platform="<?= e((string) ($pt['platform_slug'] ?? '')) ?>"
                    data-category="<?= (int) ($pt['category_id'] ?? 0) ?>"
                    data-maker="<?= e((string) ($pt['vendor_slug'] ?? '')) ?>"
                    data-iface="<?= e((string) ($pt['interface_name'] ?? $pt['interface'] ?? '')) ?>"
                    data-fits="<?= e((string) ($pt['fits_note'] ?? '')) ?>"
                    data-compatiblemodels="<?= e((string) ($pt['compatible_model_name'] ?? '')) ?>"
                    <?= (int) $val('model_id', 0) === (int) $pt['id'] ? 'selected' : '' ?>>
              <?= e($pt['platform_name']) ?> — <?= e($pt['name']) ?><?= $pt['year_from'] ? ', ' . (int) $pt['year_from'] : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="hint" data-part-fits hidden style="margin:.4rem 0 0"></p>

        <span class="hint">
          Only peripherals for the platform above. Choosing one fills in its
          kind, its maker and its specifications. Leave it at "Not on the list"
          for anything else.
        </span>
      </div>
      <?php else: ?>
      <div class="field field--half">
        <label for="model_id">Machine model</label>
        <select id="model_id" name="model_id" data-model-select>
          <option value="">Not a specific model</option>
          <?php foreach ($models as $md): ?>
            <?php
            // Slugs, not ids, for both. A model is a template row and its
            // platform_id points at the template platform, while the Platform
            // select above lists this library's copies - so the two id spaces
            // never overlap and comparing them matched nothing. The slug is the
            // one value that is the same on both sides, which is why the
            // peripheral picker below has always used it.
            ?>
            <option value="<?= (int) $md['id'] ?>"
                    data-vendor="<?= e((string) ($md['vendor_slug'] ?? '')) ?>"
                    data-platform="<?= e((string) ($md['platform_slug'] ?? '')) ?>"
                    <?= (int) $val('model_id', 0) === (int) $md['id'] ? 'selected' : '' ?>>
              <?= e($md['name']) ?><?= $md['year_from'] ? ', ' . (int) $md['year_from'] : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint">
          Picking one narrows the Interface suggestions below to what that
          machine physically has — an A2000 takes Zorro II, an A500 does not.
        </span>
      </div>
      <div class="field formgrid--wide" data-model-summary hidden>
        <p class="hint" style="margin:0"></p>
      </div>
      <?php endif; ?>


    </div>
  </fieldset>

  <fieldset>
    <legend>Identifying it</legend>
    <div class="formgrid">
      <div class="field field--quarter">
        <label for="hw_board_revision">Board revision</label>
        <input id="hw_board_revision" name="hw_board_revision" type="text" maxlength="80"
               value="<?= e($hv('board_revision')) ?>" placeholder="Rev 6A">
        <span class="hint">Rev 6A and 8A of an A500 are different machines. It is the first thing anyone asks.</span>
      </div>
      <div class="field field--quarter">
        <label for="hw_serial_number">Serial number</label>
        <input id="hw_serial_number" name="hw_serial_number" type="text" maxlength="120"
               value="<?= e($hv('serial_number')) ?>">
      </div>
      <div class="field field--quarter">
        <label for="hw_firmware">Firmware</label>
        <input id="hw_firmware" name="hw_firmware" type="text" maxlength="80"
               value="<?= e($hv('firmware')) ?>" placeholder="ROM 2.05">
        <span class="hint">The version on the board, where it has one.</span>
      </div>
      <div class="field field--tiny">
        <label for="hw_region">Region</label>
        <select id="hw_region" name="hw_region">
          <?php foreach (['unknown'=>'Not known','PAL'=>'PAL','NTSC'=>'NTSC','both'=>'Switchable'] as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= $hv('region','unknown') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">What the machine itself is.</span>
      </div>
    </div>
  </fieldset>

  <?php
  // What this particular unit is, as one editable list.
  //
  // Three mechanisms used to answer this: fixed Processor/Memory/Storage boxes,
  // a set of fields the model defined, and a box per slot the model declared.
  // They overlapped, disagreed, and still could not record a monitor's tube
  // size. Now the model only *suggests* rows; the list belongs to the entry.
  $specs = [];
  if ($item !== null) {
      $rawSpecs = scalar('SELECT specs FROM item_hardware WHERE item_id = ?', [(int) $item['id']]);
      if (is_string($rawSpecs) && $rawSpecs !== '') {
          $specs = json_decode($rawSpecs, true) ?: [];
      }
  }

  // A new entry starts from the chosen model's suggestions. An existing one
  // keeps what it was given, because a machine that has been upgraded no longer
  // matches its model and that is the whole point of recording it.
  if ($specs === []) {
      $seedFrom = (int) $val('model_id', 0);
      if ($seedFrom > 0) {
          foreach (model_fields($seedFrom) as $f) {
              $specs[] = ['label' => (string) $f['label'], 'value' => (string) ($f['default_value'] ?? '')];
          }
      }
  }

  // Suggestions for every model on the page, so choosing one fills the list
  // without a round trip. This is what used to reload the whole form and throw
  // away everything already typed.
  $suggestions = [];
  foreach (array_merge($models ?? [], $parts ?? []) as $md) {
      $rows = [];
      foreach (model_fields((int) $md['id']) as $f) {
          $rows[] = ['label' => (string) $f['label'], 'value' => (string) ($f['default_value'] ?? '')];
      }
      if ($rows !== []) {
          $suggestions[(int) $md['id']] = $rows;
      }
  }
  ?>

  <fieldset data-specs
            data-suggestions="<?= e(json_encode($suggestions, JSON_UNESCAPED_UNICODE)) ?>">
    <legend>What it has</legend>

    <div data-spec-rows>
      <?php foreach ($specs as $i => $row): ?>
        <div class="specrow" data-spec-row>
          <input type="text" name="hw_spec_label[]" placeholder="Processor"
                 maxlength="80" aria-label="Name"
                 value="<?= e((string) ($row['label'] ?? '')) ?>">
          <input type="text" name="hw_spec_value[]" placeholder="68000 @ 7.16 MHz"
                 maxlength="400" aria-label="Value"
                 value="<?= e((string) ($row['value'] ?? '')) ?>">
          <?php
          // The same four controls the model editors have. They were only a remove
          // here, so the two screens that edit the same shape of thing behaved
          // differently - and reordering, which the model editor has always allowed,
          // was simply missing on the entry.
          ?>
          <span class="specrow__move">
            <button type="button" class="btn btn--sm" data-spec-up
                    aria-label="Move this row up" title="Move up">&uarr;</button>
            <button type="button" class="btn btn--sm" data-spec-down
                    aria-label="Move this row down" title="Move down">&darr;</button>
            <button type="button" class="btn btn--sm" data-spec-addafter
                    aria-label="Add a row below this one" title="Add below">+</button>
            <button type="button" class="btn btn--sm" data-spec-remove
                    aria-label="Remove this row" title="Remove this row">&times;</button>
          </span>
        </div>
      <?php endforeach; ?>
    </div>

    <template data-spec-template>
      <div class="specrow" data-spec-row>
        <input type="text" name="hw_spec_label[]" placeholder="Processor" maxlength="80" aria-label="Name">
        <input type="text" name="hw_spec_value[]" placeholder="68000 @ 7.16 MHz" maxlength="400" aria-label="Value">
        <span class="specrow__move">
          <button type="button" class="btn btn--sm" data-spec-up aria-label="Move this row up" title="Move up">&uarr;</button>
          <button type="button" class="btn btn--sm" data-spec-down aria-label="Move this row down" title="Move down">&darr;</button>
          <button type="button" class="btn btn--sm" data-spec-addafter aria-label="Add a row below this one" title="Add below">+</button>
          <button type="button" class="btn btn--sm" data-spec-remove aria-label="Remove this row" title="Remove this row">&times;</button>
        </span>
      </div>
    </template>

    <div style="display:flex;gap:.6rem;align-items:center;margin-top:.6rem;flex-wrap:wrap">
      <button type="button" class="btn btn--wide" data-spec-add>Add a row</button>
      <button type="button" class="btn btn--sm" data-spec-reset hidden>Use the model&rsquo;s rows</button>
      <span class="hint" style="margin:0">
      </span>
    </div>
  </fieldset>

<?php partial('document_rows', ['documents' => $documents ?? []]); ?>

  <?php
  // Fitting peripherals is only offered on machines, and only once the machine
  // exists: a link needs two ids, and the second one is not allocated until the
  // entry is saved.
  $isMachine    = is_machine_category((int) $val('category_id', 0));
  $machineId    = $item === null ? 0 : (int) $item['id'];
  $platformHere = (int) $val('platform_id', 0);
  $fitted       = $machineId > 0 ? fitted_peripherals($machineId) : [];
  $available    = ($isMachine && $machineId > 0 && $platformHere > 0)
      ? fittable_peripherals($platformHere, $machineId) : [];
  ?>

  <?php if ($isMachine): ?>
  <fieldset>
    <legend>Installed peripherals</legend>

    <?php if ($machineId === 0): ?>
      <p class="hint" style="margin:0">
        Save this first. A peripheral is a separate entry with its own
        condition and photographs, so fitting one needs both to exist.
      </p>
    <?php else: ?>
      <?php if ($fitted !== []): ?>
        <table class="table" style="margin-bottom:.8rem">
          <tbody>
            <?php foreach ($fitted as $f): ?>
            <tr>
              <td>
                <a href="<?= e(url('/items/' . (int) $f['id'])) ?>"><?= e($f['title']) ?></a>
                <span class="hint"> · <?= e((string) $f['category_name']) ?></span>
              </td>
              <td style="text-align:right;width:8rem">
                <button type="submit" formmethod="post" formnovalidate
                        formaction="<?= e(url('/items/' . $machineId . '/fitted')) ?>"
                        name="remove" value="<?= (int) $f['id'] ?>"
                        class="btn btn--sm btn--danger">Remove</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <?php if ($available === []): ?>
        <p class="hint" style="margin:0">
          Nothing available. Catalogue a peripheral for this machine from
          <?php
          // domain=hardware, or this is not the peripheral form at all.
          //
          // "as" alone decides machine-or-peripheral *within* the hardware form; it is
          // "domain" that chooses the hardware form in the first place. Without it the
          // link landed on the generic "Add to the collection" page, which is not what
          // the sentence above it promises. The same link in the hardware browser has
          // always carried both.
          //
          // The machine and its library travel too: this is an invitation to catalogue
          // something for *this* machine, so it should not have to be picked again.
          ?>
          <a href="<?= e(url('/items/new', array_filter([
               'domain'      => 'hardware',
               'as'          => 'part',
               'library'     => $libraryHere ?? null,
               'platform_id' => $item['platform_id'] ?? null,
             ]))) ?>">Add a peripheral</a>
          and it will appear here.
        </p>
      <?php else: ?>
        <div class="specrow" style="grid-template-columns:1fr auto">
          <select name="fit" aria-label="Peripheral to fit">
            <option value="">Choose a peripheral…</option>
            <?php
            $group = null;
            foreach ($available as $a):
                if ($a['category_name'] !== $group):
                    if ($group !== null) echo '</optgroup>';
                    $group = $a['category_name'];
                    echo '<optgroup label="' . e((string) $group) . '">';
                endif; ?>
              <option value="<?= (int) $a['id'] ?>"><?= e($a['title']) ?></option>
            <?php endforeach;
            if ($group !== null) echo '</optgroup>'; ?>
          </select>
          <button type="submit" formmethod="post" formnovalidate
                  formaction="<?= e(url('/items/' . $machineId . '/fitted')) ?>"
                  class="btn btn--sm">Install the peripheral</button>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </fieldset>
  <?php endif; ?>

  <fieldset>
    <legend>Condition</legend>
    <div class="formgrid">
      <div class="field field--third">
        <label for="hw_working_state">Does it work</label>
        <select id="hw_working_state" name="hw_working_state">
          <?php foreach (['untested'=>'Untested','working'=>'Works','intermittent'=>'Intermittent',
                          'not_working'=>'Does not work','restored'=>'Restored'] as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= $hv('working_state','untested') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field field--third">
        <label for="condition_grade">Cosmetic grade</label>
        <select id="condition_grade" name="condition_grade">
          <?php foreach (condition_options() as $c): ?>
            <option value="<?= e($c) ?>" <?= $val('condition_grade','unknown') === $c ? 'selected' : '' ?>>
              <?= e(condition_label($c)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field field--third">
        <label for="hw_recapped_on">Recapped</label>
        <input id="hw_recapped_on" name="hw_recapped_on" type="date" value="<?= e($hv('recapped_on')) ?>">
      </div>
      <div class="field field--third">
        <?php
        // Beside recapped, because it is the same shape of fact and the heavier job:
        // recapping is one specific repair, servicing is everything else somebody did
        // to it. Two dates rather than one, so "recapped in 2019, serviced last year"
        // is expressible.
        ?>
        <label for="hw_serviced_on">Serviced</label>
        <input id="hw_serviced_on" name="hw_serviced_on" type="date" value="<?= e($hv('serviced_on')) ?>">
        <span class="hint">Leave empty if it has not been, or you do not know.</span>
      </div>

      <?php
      // The box is graded separately from the thing in it, because they wear
      // separately: a mint board in a water-damaged sleeve is one entry with two
      // different answers, and a single grade has to lie about one of them.
      //
      // Whether a box exists at all is its own question. "Not graded" and "there
      // is no box" are different, and a bare card that never shipped boxed is
      // different again from one whose box was lost.
      //
      // The grade sits in the row of grades above rather than under a heading of
      // its own: a label, a checkbox and a hint to say what the checkbox does was
      // three lines of chrome around one tick.
      $hasBox = (int) $val('has_box', 0) === 1;
      ?>
      <input type="hidden" name="has_box_declared" value="1">
      <?php
      // The tick sits above the field it reveals, not below it.
      //
      // Underneath, ticking it inserted the grade above the tick - so the checkbox you
      // just clicked jumped down the page and the pointer was suddenly over something
      // else. Anything that appears should appear below the control that summons it.
      ?>
      <div class="field formgrid--wide">
        <?php
        // The power supply first: it is a fact about the machine, and it does not
        // reveal anything. The box tick does, so it sits last, directly above the grade
        // it summons.
        ?>
        <label class="checkline">
          <input type="checkbox" name="hw_psu_included" value="1" <?= $hv('psu_included') ? 'checked' : '' ?>>
          The power supply is with it
        </label>
        <label class="checkline">
          <input type="checkbox" name="has_box" value="1" data-has-box
                 <?= $hasBox ? 'checked' : '' ?>>
          There is a box or case for it
        </label>
      </div>
      <div class="field field--third" data-box-grade <?= $hasBox ? '' : 'hidden' ?>>
        <label for="condition_box">Box condition</label>
        <select id="condition_box" name="condition_box">
          <?php foreach (component_condition_options() as $c): ?>
            <option value="<?= e($c) ?>" <?= $val('condition_box','unknown') === $c ? 'selected' : '' ?>>
              <?= e(condition_label($c)) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint">The box itself, not what is in it.</span>
      </div>


      <div class="field formgrid--wide">
        <label for="hw_modifications">Modifications and notes</label>
        <textarea id="hw_modifications" name="hw_modifications" rows="8"
                  placeholder="Recapped 2023, Kickstart switcher fitted, replaced PSU, yellowing down the right side, box has a soft corner…"><?= e($hv('modifications')) ?></textarea>
        <span class="hint">
          Anything worth saying about the unit or its box. This is the note shown
          on the entry's own page.
        </span>
      </div>

    </div>
  </fieldset>

  <fieldset>
    <?php
    // "Provenance" is an auction word. It means the history of ownership, which is
    // roughly right, but half this section is a sale and the other half is a purchase -
    // and nobody thinks "let me record the provenance" when they are noting what they
    // paid for a machine. The plain description of both directions is the heading.
    ?>
    <legend>Coming and going</legend>

    <?php
    // A section you can switch off, because "I do not know when I got this" is the
    // ordinary case for half a collection and there was no way to say it. An
    // empty date input is not the same as a date that does not apply: browsers
    // will not always let you clear one once the calendar has touched it, and a
    // blank box beside three filled ones reads as an omission rather than a fact.
    //
    // Unticked means the whole section is cleared to NULL on save - see
    // items_payload(). The hidden marker travels either way so the server can
    // tell "unticked" from "this form does not have the control".
    $hasAcquire = $item === null
        ? false
        : ($val('acquired_on') !== '' || $val('acquired_from') !== ''
           || $val('acquired_price') !== '' || $val('acquired_note') !== '');
    // Sale follows the status rather than a tick of its own. Two controls for one
    // fact could disagree - "status: owned" beside a filled-in sale block is a
    // contradiction the dashboard would report as truth - so status is the single
    // answer and the block appears when it says sold.
    $hasSale = $val('status', 'owned') === 'sold';
    ?>
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
        <input id="acquired_price" name="acquired_price" type="number" step="0.01"
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
                  placeholder="Came with two joysticks and a box of disks"><?= e($val('acquired_note')) ?></textarea>
      </div>
    </div>

    <h3 class="subhead">Sale</h3>
    <p class="hint" data-toggle-empty="sale" <?= $hasSale ? 'hidden' : '' ?>
       style="margin:0 0 .7rem">
      Still owned, so there is nothing to record. Set the status to
      <strong>Sold</strong> under <em>Where it lives</em> and this section opens.
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
        <input id="sold_price" name="sold_price" type="number" step="0.01" value="<?= e($val('sold_price')) ?>">
      </div>
      <div class="field field--quarter">
        <label for="sold_currency">Currency</label>
        <select id="sold_currency" name="sold_currency">
          <?php $soldCur = strtoupper($val('sold_currency', $cur));
                foreach (currency_options() as $code => $label): ?>
            <option value="<?= e($code) ?>" <?= $soldCur === $code ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">
          Its own, because bought in one currency and sold in another is ordinary.
        </span>
      </div>
      <div class="field formgrid--wide">
        <label for="sold_note">Notes</label>
        <textarea id="sold_note" name="sold_note" rows="4"><?= e($val('sold_note')) ?></textarea>
      </div>
    </div>
  </fieldset>

  <?php
  // Locations belong to a library, so the list follows whichever one is chosen -
  // and on a new entry nothing has been chosen yet, which is why this used to
  // come back empty and say "no locations in this library" to somebody who had
  // just made three. Falls back to the library the select is showing.
  $locHere = (int) $val('library_id', 0);
  if ($locHere <= 0) {
      $locHere = (int) ($item['library_id'] ?? 0);
  }
  if ($locHere <= 0) {
      $locHere = (int) (($libraries[0]['id'] ?? 0));
  }
  $places  = location_options($locHere > 0 ? $locHere : null);
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
               value="<?= e($val('location_position')) ?>" placeholder="1, a, left, behind the printer">
      </div>
      <?php
      // No second *notes* box here. "Modifications and notes" in the Condition
      // fieldset is the note for a piece of hardware, and two boxes meant two
      // places to look for the same sentence - with only one of them shown on the
      // entry's page. items.notes is left untouched for software entries, which
      // still have their own.
      //
      // A description is a different thing and does get a box: it is about the
      // model rather than about this unit, a lookup fills it, and without one
      // the scraped blurb had nowhere to be seen or corrected.
      ?>
      <div class="field formgrid--wide">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="4"
                  placeholder="What this machine or card is — a lookup fills this in."><?= e($val('description')) ?></textarea>
        <span class="hint">
          About the model, not about the one you own. Wear, repairs and what came
          with it belong in Modifications and notes.
        </span>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Photographs</legend>
    <?php
    // What is already there, before the box for adding more. The form used
    // to offer only the dropzone, so a photograph could be added and never
    // removed from this screen.
    ?>
    <?php partial('image_rows', ['images' => $images ?? [], 'domain' => 'hardware']); ?>
    <div class="formgrid">
      <div class="field formgrid--wide">
        <div class="dropzone" data-dropzone data-max="4">
          <div class="dropzone__prompt">
            <strong>Drop photos here</strong>
            <span>or click to browse, or paste from the clipboard</span>
          </div>
          <span class="dropzone__hint">
            JPEG, PNG, WebP or GIF, up to <?= round(((int) config('uploads.max_bytes')) / 1048576) ?> MB each,
            four at a time. Board shots are worth more than box shots for hardware.
          </span>
          <input id="images" name="images[]" type="file" accept="image/*" multiple>
          <div class="dropzone__list" data-dropzone-list></div>
        </div>
      </div>
      <?php
      // Which set, then what it shows - the same pair as the software form. The
      // section carries the provenance, so choosing "your photos of the
      // hardware" is what keeps a picture out of the stock set a lookup writes
      // to. The box set is offered only when this one came in a box.
      $upSections = image_sections('hardware');
      $hasBoxNow  = (int) ($val('has_box', 0)) === 1;
      ?>
      <div class="field">
        <label for="image_section">Which set</label>
        <select id="image_section" name="image_section">
          <?php foreach ($upSections as $key => $sec): ?>
            <?php if (!empty($sec['needs_box']) && !$hasBoxNow) { continue; } ?>
            <option value="<?= e($key) ?>" <?= $key === 'unit' ? 'selected' : '' ?>>
              <?= e($sec['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="image_kind">What these photos show</label>
        <select id="image_kind" name="image_kind">
          <?php foreach (image_kind_options() as $k): ?>
            <option value="<?= e($k) ?>" <?= $k === 'unit' ? 'selected' : '' ?>><?= e(image_kind_label($k)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </fieldset>


  <div class="formactions">
    <button class="btn btn--accent" type="submit"><?= $item ? 'Save' : 'Add it' ?></button>
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
    <a class="btn" href="<?= e($returnTo ?? url('/hardware')) ?>">Cancel</a>
  </div>
</form>
