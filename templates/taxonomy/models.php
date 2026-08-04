<?php
/** @var array $models @var array $vendors @var array $platforms @var array $types */
/** @var array|null $editing @var array $fields */
$e = $editing ?? null;
$v = fn(string $k, $d = '') => $e === null ? $d : (string) ($e[$k] ?? $d);
// Which page this is, not what is being edited. The slot a card occupies and
// the machines it fits are peripheral facts; a machine has neither, whether you
// are adding one or changing one. Keying this off $e meant the add form showed
// both on the machine page.
$isMachine = (bool) ($machines ?? false);
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Manage</span>
    <h1><?= $machines ? 'Machine models' : 'Peripheral models' ?></h1>
  </div>
</div>

<?php partial('manage_nav', ['current' => $current]); ?>

<section class="panel">
  <h2 class="panel__title"><?= $e ? 'Edit ' . e($e['name']) : 'Add a model' ?></h2>
  <form method="post" action="<?= e(url($machines ? '/manage/models' : '/manage/parts')) ?>">
    <?= csrf_field() ?>
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><?php endif; ?>
    <?php
    // Which library these models belong to. Models are per library now, the same
    // way platforms and makers are, so the page works on one at a time and the
    // form carries it rather than asking a second time.
    ?>
    <input type="hidden" name="library_id" value="<?= (int) ($libraryId ?? 0) ?>">
    <?php if (count($libraries ?? []) > 1 && !$e): ?>
      <div class="field">
        <label for="m-library">Library</label>
        <select id="m-library" onchange="location.search='?library='+this.value">
          <?php foreach ($libraries as $lib): ?>
            <option value="<?= (int) $lib['id'] ?>" <?= (int) ($libraryId ?? 0) === (int) $lib['id'] ? 'selected' : '' ?>>
              <?= e($lib['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Models belong to one library. Switching here changes which list you are editing.</span>
      </div>
    <?php endif; ?>

    <div class="formgrid">
      <div class="field formgrid--wide">
        <label for="m-name">Name</label>
        <input id="m-name" name="name" type="text" required maxlength="160" value="<?= e($v('name')) ?>"
               placeholder="Amiga 500, or Blizzard 1230 IV">
      </div>


      <div class="field field--third">
        <label for="m-vendor">Company</label>
        <select id="m-vendor" name="vendor_id" data-model-vendor>
          <option value="">Unknown</option>
          <?php foreach ($vendors as $vd): ?>
            <option value="<?= (int) $vd['id'] ?>" <?= (int) $v('vendor_id', 0) === (int) $vd['id'] ? 'selected' : '' ?>>
              <?= e($vd['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php
        // An empty picker should say why it is empty.
        //
        // "Unknown" and nothing else looks like a broken control. Two different causes,
        // and they want different answers: a library with no companies at all needs
        // synchronising, one whose companies are all software publishers needs a maker
        // tagged as making hardware.
        ?>
        <?php if ($vendors === []): ?>
          <span class="hint">
            <?php if ((int) ($companyCount ?? 0) === 0): ?>
              No companies in this library yet —
              <a href="<?= e(url('/libraries/' . (int) $libraryId . '/edit')) ?>">synchronise it</a>
              with Makers ticked, or add one under Companies.
            <?php else: ?>
              This library has companies, but none of them are marked as making hardware.
              Open <a href="<?= e(url('/manage/vendors')) ?>">Companies</a> and tick
              <em>hardware</em> on the ones that made machines.
            <?php endif; ?>
          </span>
        <?php endif; ?>
      </div>

      <div class="field field--third">
        <label for="m-platform">Platform</label>
        <?php
        // The hook goes on unconditionally. It used to be added only for a machine
        // model - but a machine does not fit into anything, so the compatibility box
        // below is peripheral-only, which meant the narrowing script found no
        // platform select in exactly the case it exists for and bailed. Every
        // platform's machines were then offered for a card that plugs into one
        // family.
        //
        // Harmless on a machine, where the box is hidden anyway.
        ?>
        <?php
        // Narrowed by the company only for a machine.
        //
        // A machine's maker is the platform's maker: pick Commodore and the Amiga
        // is what they made. A peripheral's maker usually is not - the Blizzard
        // 1230 IV is a Phase 5 card for a Commodore machine - so narrowing there
        // dropped the Amiga off the list the moment Phase 5 was chosen, and reset
        // the platform to "not decided" on a model that had one.
        ?>
        <select id="m-platform" name="platform_id" data-model-platform
                data-narrow-by-vendor="<?= !empty($machines) ? '1' : '' ?>">
          <option value="" data-vendor="">Any, or not decided</option>
          <?php foreach ($platforms as $p): ?>
            <option value="<?= (int) $p['id'] ?>"
                    data-vendor="<?= (int) ($p['vendor_id'] ?? 0) ?>"
                    <?= (int) $v('platform_id', 0) === (int) $p['id'] ? 'selected' : '' ?>>
              <?= e($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint">
          <?php if (!empty($machines)): ?>
            Choosing a company narrows this to the machines they made.
          <?php else: ?>
            Which machine the part is for. Not narrowed by the company: a card is
            usually made by somebody other than whoever built the machine.
          <?php endif; ?>
        </span>
      </div>

<div class="field field--third">
        <label for="m-type">Place in catalogue</label>
        <select id="m-type" name="category_id" data-type-select>
          <option value="">Not set</option>
          <?php
          // Fetched once, not once per option.
          //
          // machine_type_slugs() runs a query, and it was called inside the loop - so
          // the peripheral page, which lists a thousand kinds rather than the machine
          // page's sixty, ran a thousand queries to render one select. That was the
          // whole of its ten-fold slowness; the page itself was never the problem.
          $machineSlugs = array_flip(machine_type_slugs());
          ?>
          <?php
          // Branches that say they hold this kind of thing.
          //
          // The machine editor and the peripheral editor are the same screen with
          // $machines set differently, and the list was every hardware branch on
          // both - so a peripheral model could be filed under a branch declared
          // for machines, and nothing downstream could tell.
          $wantRole = !empty($machines) ? 'machine' : 'peripheral';
          ?>
          <?php foreach ($types as $t): ?>
            <?php // Inherited kind, matching the controller's own filter. ?>
            <?php if (($t['kind'] ?? ($t['role'] ?? '')) !== $wantRole) { continue; } ?>
            <option value="<?= (int) $t['id'] ?>"
                    data-machine="<?= isset($machineSlugs[$t['slug']]) ? '1' : '0' ?>"
                    data-platform="<?= (int) ($t['platform_id'] ?? 0) ?>"
                    <?= (int) $v('category_id', 0) === (int) $t['id'] ? 'selected' : '' ?>>
              <?= e($t['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint">
          Optional. A computer, console or handheld is a machine; anything else
          is a part that goes in one. Leave it unset and the entry form asks.
        </span>
      </div>

      <div class="field field--third">
        <label for="m-year">Introduced</label>
        <input id="m-year" name="year_from" type="number" min="1970" max="2100" value="<?= e($v('year_from')) ?>">
      </div>

      <?php // Only a part occupies a slot or suits particular machines. ?>
      <div class="field field--third" data-part-only<?= $isMachine ? ' hidden' : '' ?>>
        <label for="m-iface">Slot it occupies</label>
        <input id="m-iface" name="interface" type="text" maxlength="40" value="<?= e($v('interface')) ?>"
               placeholder="trap, z2, isa16">
      </div>

      <div class="field field--third" data-part-only<?= $isMachine ? ' hidden' : '' ?>>
        <span class="label">Hardware compatibility</span>
        <?php
        // A scrollable list of checkboxes rather than a dialogue. It is the same
        // number of clicks, it works before any script has run, and the choices
        // stay visible while you make them - which matters when the answer is
        // "these four" rather than "this one".
        $chosenCompatibility = array_map('intval', $compatibilityChosen ?? []);
        $byPlatform = [];
        foreach (($compatibleModels ?? []) as $fm) {
            $byPlatform[(string) ($fm['platform_name'] ?? 'Other')][] = $fm;
        }
        ksort($byPlatform);
        ?>
        <div class="fitsbox" data-fits-box>
          <?php foreach ($byPlatform as $platName => $group): ?>
            <p class="fitsbox__group"><?= e((string) $platName) ?></p>
            <?php foreach ($group as $fm): ?>
              <label class="checkline">
                <input type="checkbox" name="compatible_models[]" value="<?= (int) $fm['id'] ?>"
                       data-platform="<?= (int) ($fm['platform_id'] ?? 0) ?>"
                       <?= in_array((int) $fm['id'], $chosenCompatibility, true) ? 'checked' : '' ?>>
                <?= e($fm['name']) ?>
              </label>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php if ($byPlatform === []): ?>
            <p class="hint" style="margin:0">No machine models yet, so there is nothing to fit.</p>
          <?php endif; ?>
        </div>
        <span class="hint">
          Which machines this goes in. Tick as many as apply — a card that fits
          four machines fits four. Tick none if it fits the whole family, and say
          so in the note if it is worth saying.
        </span>
        </div>

      <div class="field formgrid--wide">
        <label for="m-notes">Notes</label>
        <textarea id="m-notes" name="notes" rows="2"
                  placeholder="Anything worth knowing about this model in general."><?= e($v('notes')) ?></textarea>
      </div>
    </div>

    <fieldset class="fieldset--fields">
      <legend>Specifications</legend>
      <p class="lede lede--tight">
        Each becomes a box on the entry form, in the order below, with the starting
        value offered as a suggestion.
      </p>

      <div class="field formgrid--wide">
        <label for="m-fields" class="sr-only">Fields</label>
        <datalist id="spec-names">
          <?php foreach ($specNames ?? [] as $n): ?><option value="<?= e($n) ?>"></option><?php endforeach; ?>
        </datalist>
        <div data-modelfields>
          <?php // Add sits under the text and above the rows: it is the thing
                // to press when there is nothing there, and a button that
                // wanders to the bottom of a growing list is a button you have
                // to go looking for. ?>
          <button type="button" class="btn btn--wide" data-mf-add style="margin-bottom:.7rem"
                  <?= ($fields ?: []) === [] ? '' : 'hidden' ?>>
            Add a specification
          </button>

          <div data-mf-rows>
            <?php foreach (($fields ?: []) as $f): ?>
              <div class="mfrow" data-mf-row>
                <input type="text" name="field_label[]" maxlength="80" aria-label="Name" list="spec-names"
                       placeholder="Processor" value="<?= e((string) ($f['label'] ?? '')) ?>">
                <input type="text" name="field_value[]" maxlength="400" aria-label="Starting value"
                       placeholder="68000 @ 7.16 MHz" value="<?= e((string) ($f['default_value'] ?? '')) ?>">
                <span class="mfrow__move">
                  <button type="button" class="btn btn--sm" data-mf-up
                          aria-label="Move this specification up" title="Move up">&uarr;</button>
                  <button type="button" class="btn btn--sm" data-mf-down
                          aria-label="Move this specification down" title="Move down">&darr;</button>
                  <button type="button" class="btn btn--sm" data-mf-addafter
                          aria-label="Add a specification below this one" title="Add below">+</button>
                  <button type="button" class="btn btn--sm" data-mf-remove
                          aria-label="Remove this specification" title="Remove">&times;</button>
                </span>
              </div>
            <?php endforeach; ?>
          </div>

          <template data-mf-template>
            <div class="mfrow" data-mf-row>
              <input type="text" name="field_label[]" maxlength="80" aria-label="Name" list="spec-names" placeholder="Processor">
              <input type="text" name="field_value[]" maxlength="400" aria-label="Starting value" placeholder="68000 @ 7.16 MHz">
              <span class="mfrow__move">
                <button type="button" class="btn btn--sm" data-mf-up aria-label="Move this specification up" title="Move up">&uarr;</button>
                <button type="button" class="btn btn--sm" data-mf-down aria-label="Move this specification down" title="Move down">&darr;</button>
                <button type="button" class="btn btn--sm" data-mf-addafter aria-label="Add a specification below this one" title="Add below">+</button>
                <button type="button" class="btn btn--sm" data-mf-remove aria-label="Remove this specification" title="Remove">&times;</button>
              </span>
            </div>
          </template>
        </div>
        <span class="hint">
          A specification with no starting value gives an empty box on the entry form. A model may have none at all.
        </span>
      </div>
    </fieldset>

    <div class="formactions">
      <button class="btn btn--accent" type="submit"><?= $e ? 'Save' : 'Add it' ?></button>
      <?php if ($e): ?>
        <a class="btn" href="<?= e(url($machines ? '/manage/models' : '/manage/parts')) ?>">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</section>

<section class="panel">
  <h2 class="panel__title">Models <span class="hint"><?= count($models) ?></span></h2>
  <?php
      // A filter over what is already on the page. These lists run to hundreds of rows
      // and you almost always know the name of the one you want; paging to find it, or
      // reading down a column, is the slow way round.
      ?>
      <div class="field" style="margin-bottom:.7rem">
        <label class="visually-hidden" for="filter-model-list">Filter models — name, maker or machine</label>
        <input id="filter-model-list" type="search" placeholder="Filter models — name, maker or machine"
               data-tablefilter="#model-list" data-tablefilter-count="#count-model-list"
               autocomplete="off" spellcheck="false">
        <span class="hint" id="count-model-list"></span>
      </div>
      <table class="table" id="model-list">
    <thead><tr><th>Name</th><th>Type</th><th>Platform</th><th>Describes</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($models as $m):
        $mf = model_fields((int) $m['id']); ?>
        <tr>
          <td><strong><?= e($m['name']) ?></strong>
            <?php if ($m['year_from']): ?><span class="hint">, <?= (int) $m['year_from'] ?></span><?php endif; ?>
            <?php if ($m['vendor_name']): ?><br><span class="hint"><?= e($m['vendor_name']) ?></span><?php endif; ?>
          </td>
          <td>
            <?= e($m['category_name']) ?>
            <br><span class="hint"><?= is_machine_category((int) $m['category_id']) ? 'a machine' : 'a part' ?></span>
          </td>
          <td><span class="hint"><?= e($m['platform_name']) ?></span></td>
          <td><span class="hint"><?= e(implode(' · ', array_column($mf, 'label'))) ?></span></td>
          <td style="text-align:right;white-space:nowrap">
            <a class="btn btn--sm" href="<?= e(url($machines ? '/manage/models' : '/manage/parts', ['edit' => $m['id']])) ?>">Edit</a>
            <form method="post" action="<?= e(url($machines ? '/manage/models' : '/manage/parts')) ?>" style="display:inline"
                  data-confirm="Remove <?= e($m['name']) ?>?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <input type="hidden" name="library_id" value="<?= (int) ($libraryId ?? 0) ?>">
              <button class="btn btn--sm btn--danger" type="submit">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
