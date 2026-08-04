<?php
/**
 * Metadata sources.
 *
 * @var array $providers @var array $types @var array|null $editing @var array $params
 * @var array|null $testResult @var array|null $probe
 * @var array $allTypes
 */
// $mappings was read here and never passed: platform mapping moved onto the platform,
// and the loop that unpacked it stayed behind, warning on every load and building a
// list nothing then used.
$def = $editing ? metadata_provider_definition($editing['type']) : null;
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Server</span>
    <h1>Instance settings</h1>
    <?php // The tab strip below says which section; the heading says which page. ?>
  </div>
</div>

<?php partial('admin_tabs', ['current' => 'metadata']); ?>




<?php if ($testResult): ?>
  <div class="panel" style="border-left:4px solid var(--<?= $testResult['ok'] ? 'good' : 'bad' ?>);margin-bottom:1rem">
    <h2 class="panel__title">Test</h2>
    <p style="margin-top:0"><?= e($testResult['message']) ?></p>
    <?php if (!empty($testResult['results'])): ?>
      <table class="table">
        <?php $tDom = in_array('software',
            (array) (metadata_provider_definition((string) ($testResult['type'] ?? ''))['domains'] ?? ['software']),
            true) ? 'software' : 'hardware'; ?>
        <thead><tr><th>Title</th><th>Year</th>
          <th><?= e(item_field_label('developer_name', $tDom)) ?></th><th>Platform</th></tr></thead>
        <tbody>
          <?php foreach ($testResult['results'] as $r): ?>
            <tr>
              <td><?= e($r['title']) ?></td>
              <td class="mono"><?= $r['year'] ? (int) $r['year'] : '—' ?></td>
              <td><?= e($r['developer'] ?: '—') ?></td>
              <td><?= e($r['platform'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<section class="panel">
  <h2 class="panel__title"><?= count($providers) ?> configured</h2>
  <?php if (!$providers): ?>
    <p class="lede" style="margin:0">None yet. Add one below — Wikidata needs no account at all.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Name</th><th>Type</th><th>Answers about</th><th>Priority</th><th>Status</th><th>Last used</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($providers as $prov): $d = metadata_provider_definition($prov['type']); ?>
      <?php
      // A disabled source is faded, not just labelled.
      //
      // The Status column already said "Disabled", but a word in the fourth
      // column of six is easy to read past - and the row that is off looks
      // exactly like the rows that are on, which is the wrong way round for
      // something that is not answering.
      //
      // The buttons keep their normal weight: Enable is the thing somebody is
      // reaching for on that row, and fading it would be the opposite of helpful.
      ?>
      <tr<?= (int) $prov['is_enabled'] === 1 ? '' : ' class="is-off"' ?>>
        <td>
          <?php
          // The name opens the source's own page: what it does, which machines it
          // has been tried on, where it fetches from. That used to be a paragraph
          // in the add-a-source list, which is the one place you cannot read it
          // once the source is added.
          ?>
          <a href="<?= e(url('/manage/metadata/' . rawurlencode((string) $prov['type']))) ?>"><?= e($prov['name']) ?></a>
          <?php if ($prov['last_error']): ?>
            <br><span style="font-size:.78rem;color:var(--bad)">
              Last attempt failed: <?= e(truncate($prov['last_error'], 90)) ?>
            </span>
          <?php endif; ?>
        </td>
        <td><span class="chip"><?= e($d['label'] ?? $prov['type']) ?></span></td>
        <td>
          <?php
          // Which halves of the shop it serves, which is the only thing about a
          // source that decides where it can be attached at all.
          foreach (($d['domains'] ?? []) as $dom): ?>
            <span class="chip"><?= e($dom) ?></span>
          <?php endforeach; ?>
        </td>
        <td class="num"><?= (int) $prov['priority'] ?></td>
        <td><?php if ((int) $prov['is_enabled'] === 1): ?>Enabled<?php else: ?>
          <span class="chip">Disabled</span>
        <?php endif; ?></td>
        <td class="mono" style="font-size:.78rem"><?= e($prov['last_used_at'] ? substr((string) $prov['last_used_at'], 0, 16) : 'never') ?></td>
        <td style="text-align:right;white-space:nowrap">
          <a class="btn btn--sm" href="<?= e(url('/manage/metadata', ['edit' => $prov['id']])) ?>">Edit</a>
          <form method="post" action="<?= e(url('/manage/metadata')) ?>" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $prov['id'] ?>">
            <button class="btn btn--sm" type="submit" name="action" value="toggle"><?= (int) $prov['is_enabled'] === 1 ? 'Disable' : 'Enable' ?></button>
          </form>
          <?php
          // No mapping or scope buttons.
          //
          // Which branches a source answers for is decided in the category
          // editor, where the branches are. A count of mapped machines beside a
          // button that fills them in was a second place to arrange the same
          // thing, in different words, and the two never quite agreed.
          ?>
          <form method="post" action="<?= e(url('/manage/metadata')) ?>" style="display:inline" data-confirm="Remove <?= e($prov['name']) ?>?">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $prov['id'] ?>">
            <button class="btn btn--sm btn--danger" type="submit" name="action" value="delete">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<?php
// Ask the configured sources something, together.
//
// Testing was one source at a time and only from inside the form that edits it, so
// comparing two answers meant editing one, testing, editing the other, testing again,
// and holding the first result in your head. This is the question an administrator
// actually has - what do my sources say about this - and it is the same question a
// lookup from an entry asks, so it is a rehearsal rather than a different feature.
?>
<?php if ($providers): ?>
<section class="panel" style="margin-top:1rem">
  <h2 class="panel__title">Try a lookup</h2>
  <form method="post" action="<?= e(url('/manage/metadata')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="probe">
    <div class="field">
      <label for="probe_query">Search for</label>
      <input id="probe_query" name="probe_query" type="text" autocomplete="off"
             placeholder="Turrican, Deluxe Paint, Blizzard 1230…"
             value="<?= e((string) ($probe['query'] ?? '')) ?>">
    </div>
    <div class="field">
      <label for="probe_source">Ask</label>
      <?php
      // A select, not a column of tick boxes. Asking one source at a time is what
      // this is for - comparing two answers means running it twice and reading
      // both, which is the same work either way, and a list of checkboxes for
      // four sources took more room than the results.
      //
      // Disabled ones are listed: an agent is often switched off because it was
      // misbehaving, and this is where you would find out whether it still is.
      $asked = (int) ($probe['sources'][0] ?? 0);
      ?>
      <select id="probe_source" name="sources[]" required>
        <?php foreach ($providers as $prov): ?>
          <option value="<?= (int) $prov['id'] ?>" <?= $asked === (int) $prov['id'] ? 'selected' : '' ?>>
            <?= e((string) $prov['name']) ?><?= (int) $prov['is_enabled'] === 1 ? '' : ' (disabled)' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="formactions">
      <button class="btn btn--accent" type="submit">Search</button>
    </div>
  </form>

  <?php if ($probe && !empty($probe['rows'])): ?>
    <?php foreach ($probe['rows'] as $row): ?>
      <div class="panel" style="margin:1rem 0 0;border-left:3px solid var(--<?= $row['error'] === null ? 'good' : 'bad' ?>)">
        <strong><?= e($row['name']) ?></strong>
        <span class="hint"><?= (int) $row['ms'] ?> ms<?= $row['error'] === null
            ? ', ' . (int) $row['total'] . ' result' . ((int) $row['total'] === 1 ? '' : 's')
            : '' ?></span>
        <?php if ($row['error'] !== null): ?>
          <p style="margin:.5rem 0 0;color:var(--bad);font-size:.88rem"><?= e((string) $row['error']) ?></p>
        <?php elseif ($row['results'] === []): ?>
          <p class="hint" style="margin:.5rem 0 0">
            Answered, and knows nothing about that. Not the same as a failure.
          </p>
        <?php else: ?>
          <?php
          // "Studio" is a games word. This table shows whatever the source
          // returned, and half the sources are hardware databases where the
          // answer is ASUS or Commodore - a company, not a studio. The label
          // follows the source's own domain, using the same function the entry
          // screens use, so there is one set of words for one field.
          $srcDef  = metadata_provider_definition((string) $row['type']);
          $srcDom  = in_array('software', (array) ($srcDef['domains'] ?? []), true) ? 'software' : 'hardware';
          $noYear  = metadata_provider_omits((string) $row['type'], 'year');
          ?>
          <table class="table" style="margin-top:.6rem">
            <thead><tr>
              <th>Title</th>
              <th>Year</th>
              <th><?= e(item_field_label('developer_name', $srcDom)) ?></th>
              <th>Platform</th>
            </tr></thead>
            <tbody>
              <?php foreach ($row['results'] as $r): ?>
                <tr>
                  <td><?= e((string) $r['title']) ?></td>
                  <?php
                  // A dash reads as "the scraper missed it". Where the source has
                  // no such field at all - TheRetroWeb board pages carry no date
                  // of any kind - that is a fact about the site, not a fault.
                  ?>
                  <td class="mono"><?= !empty($r['year'])
                      ? (int) $r['year']
                      : ($noYear ? '<span class="hint">not carried</span>' : '—') ?></td>
                  <td><?= e((string) ($r['developer'] ?: '—')) ?></td>
                  <td><?= e((string) ($r['platform'] ?: '—')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php
// Nothing left to add, nothing to show.
//
// With every source added this was an empty panel headed "Add a source" over a
// table of none, which reads as something broken. It comes back the moment one
// is deleted, because $types holds what has not been added yet.
?>
<?php if ($editing !== null || $types !== []): ?>
<section class="panel" style="margin-top:1rem">
  <h2 class="panel__title"><?= $editing ? 'Edit ' . e($editing['name']) : 'Add a source' ?></h2>

  <?php if (!$editing): ?>
    <?php
    // Narrowed to what this instance holds.
    //
    // A source tagged as covering the PC and nothing else has nothing to offer
    // an install with no PC entries, and listing it anyway is four cards of
    // noise between somebody and the one they want. Read from what is filed
    // rather than from the platform list, because a library copies all
    // sixty-three of those when it synchronises and they say nothing about
    // anybody.
    //
    // A door, not a wall: adding a source before the library it is for is a
    // reasonable thing to do, and an empty instance is not a narrow one - with
    // nothing catalogued there is nothing to narrow by and everything shows.
    ?>

    <?php
    // A table, not a wall of cards.
    //
    // Each card carried a paragraph, a key note and up to eight platform tags -
    // four of them filled the screen, and all of it now has a page of its own
    // behind the name. What is left is the decision: which one, and add it.
    ?>
    <table class="table" style="margin-bottom:1.25rem">
      <thead><tr><th>Source</th><th>Answers about</th><th>Needs a key</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($types as $key => $t): ?>
          <tr>
            <td>
              <a href="<?= e(url('/manage/metadata/' . rawurlencode((string) $key))) ?>">
                <?= e($t['label']) ?>
              </a>
            </td>
            <td>
              <?php foreach (($t['domains'] ?? []) as $dom): ?>
                <span class="chip"><?= e($dom) ?></span>
              <?php endforeach; ?>
            </td>
            <td><span class="hint"><?= !empty($t['needs_key']) ? 'yes' : 'no' ?></span></td>
            <td style="text-align:right">
              <a class="btn btn--sm" href="<?= e(url('/manage/metadata', ['type' => $key])) ?>">Add</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <form method="post" action="<?= e(url('/manage/metadata')) ?>"
        data-metadata-form
        data-key-types="<?= e(json_encode(array_map(
            fn($t) => (bool) ($t['needs_key'] ?? false),
            $types
        ))) ?>"
        data-homepages="<?= e(json_encode(array_map(fn($t) => $t['homepage'] ?? '', $types))) ?>">
    <?= csrf_field() ?>
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <div class="formgrid">
      <div class="field">
        <label for="type">Source</label>
        <?php
        // Narrowed like the cards, with two exceptions that would otherwise be
        // holes: editing a source the narrowing hides, and coming back from a
        // failed test with ?type= naming one. In both cases the source is
        // already the subject, and a select that cannot show its own value is
        // worse than one showing a row too many.
        $chosenType  = (string) ($editing['type'] ?? input('type', ''));
        $selectTypes = ($editing !== null || ($chosenType !== '' && !isset($types[$chosenType])))
            ? $allTypes
            : $types;
        ?>
        <select id="type" name="type" <?= $editing ? 'disabled' : '' ?>>
          <?php foreach ($selectTypes as $key => $t): ?>
            <?php // $types is already narrowed, so the select cannot offer what the
                // cards above do not - picking a source that is not on the page
                // would be its own small mystery. ?>
          <option value="<?= e($key) ?>" <?= $chosenType === $key ? 'selected' : '' ?>><?= e($t['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">
          One agent per source, so the source is also the name. Adding a second
          of the same one is refused rather than quietly making a duplicate.
        </span>
      </div>

      <div class="field">
        <label for="priority">Priority</label>
        <input id="priority" name="priority" type="number" min="1" max="999"
               value="<?= (int) ($editing['priority'] ?? 100) ?>">
        <span class="hint">Lowest first when several sources answer.</span>
      </div>

      <?php
      // Shown only for sources that use a key. For a new source the type is not
      // chosen yet, so it renders hidden and the script reveals it once a
      // key-using source is picked.
      // Every credential any source asks for, each tagged with the sources that
      // want it. One box called "API key" could not express IGDB, which needs a
      // client id and a secret - so the client id had nowhere to be typed and the
      // source could not be configured at all.
      //
      // All of them are rendered and the script shows the ones the chosen source
      // asks for, because the type is picked without a page load.
      $editingType = (string) ($editing['type'] ?? '');
      $allCreds    = [];
      foreach (array_keys(metadata_provider_types()) as $someType) {
          foreach (metadata_provider_credentials((string) $someType) as $field => $meta) {
              // Per source, because one field can be two things: api_key is
              // TheGamesDB's "API key" and IGDB's "Client secret". Rendering it
              // once with whichever label was iterated last would have asked
              // TheGamesDB for a client secret.
              $allCreds[$field]['meta'] = $meta;
              $allCreds[$field]['types'][] = (string) $someType;
              $allCreds[$field]['labels'][(string) $someType] = (string) $meta['label'];
              $allCreds[$field]['secret'] = !empty($meta['secret']) || !empty($allCreds[$field]['secret']);
          }
      }
      // Identifiers before secrets, so IGDB reads Client ID then Client secret -
      // the order the Twitch page presents them and the order somebody copies
      // them in. The union was built by walking the sources, so it came out in
      // whatever order they are declared: TheGamesDB owns api_key too, and being
      // listed first put the secret above the id it belongs to.
      uasort($allCreds, fn($a, $b) => (int) !empty($a['secret']) <=> (int) !empty($b['secret']));
      ?>
      <?php foreach ($allCreds as $field => $info): ?>
        <?php
        $wanted = $editingType !== '' && in_array($editingType, $info['types'], true);
        $stored = $editing ? (string) ($params[$field] ?? '') : '';
        ?>
        <?php // Half width, so a pair sits on one row instead of one under the
              // other with the second wrapped beneath an unrelated column. ?>
        <div class="field field--half" data-cred-field="<?= e($field) ?>"
             data-cred-types="<?= e(implode(' ', $info['types'])) ?>"
             data-cred-labels="<?= e(json_encode($info['labels'], JSON_UNESCAPED_UNICODE)) ?>"
             <?= $wanted ? '' : 'hidden' ?>>
          <label for="cred-<?= e($field) ?>" data-cred-label><?=
            e($editingType !== '' && isset($info['labels'][$editingType])
                ? $info['labels'][$editingType]
                : (string) $info['meta']['label']) ?></label>
          <?php if (!empty($info['secret'])): ?>
            <div class="reveal">
              <input id="cred-<?= e($field) ?>" name="<?= e($field) ?>" type="password"
                     autocomplete="off" spellcheck="false"
                     value="<?= e($stored) ?>" data-reveal-input>
              <button type="button" class="reveal__toggle" data-reveal-toggle
                      aria-label="Show or hide this value">show</button>
            </div>
          <?php else: ?>
            <?php // Not a secret, so not hidden behind a reveal. A client id is
                  // printed in documentation and pasted from a web page. ?>
            <input id="cred-<?= e($field) ?>" name="<?= e($field) ?>" type="text"
                   autocomplete="off" spellcheck="false" value="<?= e($stored) ?>">
          <?php endif; ?>
          <span class="hint" data-cred-hint>
            <?php if ($editing && $def): ?>
              Get it at <a href="<?= e($def['homepage']) ?>" target="_blank" rel="noopener noreferrer external"><?= e($def['homepage']) ?></a>.
            <?php endif; ?>
            Clearing this box and saving removes the stored value.
          </span>
        </div>
      <?php endforeach; ?>

      <?php
      // Only when editing. A source being added has just answered a test it had
      // to pass, so there is no state where it arrives switched off - and the
      // list has a Disable button for the day that changes.
      ?>
      <?php if ($editing !== null): ?>
        <div class="field">
          <span class="label">Status</span>
          <label class="checkline">
            <input type="checkbox" name="is_enabled" value="1"
                   <?= (int) $editing['is_enabled'] === 1 ? 'checked' : '' ?>>
            Enabled
          </label>
        </div>
      <?php endif; ?>
    </div>

    <?php
    // Offered only after a check has already failed, and never before.
    //
    // The check is evidence, not a verdict: a source can be configured correctly
    // and still fail it because the site is down or its page layout moved, and
    // that left somebody unable to add a working source with nothing to do about
    // it. Showing this box up front would make it the habit instead of the
    // remedy.
    ?>
    <?php if (!$editing && input('offer_skip') !== null): ?>
      <div class="field" style="margin-bottom:.6rem">
        <label class="checkline">
          <input type="checkbox" name="skip_probe" value="1">
          Add it without checking
        </label>
        <span class="hint">
          The check just failed. If you are confident the settings are right — the
          site may simply be down — this adds it anyway.
        </span>
      </div>
    <?php endif; ?>

    <div class="formactions">
      <button class="btn btn--accent" type="submit" name="action" value="save">
        <?= $editing ? 'Save' : 'Add agent' ?>
      </button>
      <?php
      // No separate Test button, and no box to type a title into either. Adding
      // runs a check and refuses if it fails, using the term the source itself
      // declares - Turrican for the games databases, Blizzard for the Amiga
      // hardware one. That is a better probe than whatever somebody types on the
      // way past, and one less thing to fill in for a step that happens anyway.
      ?>
      <?php if ($editing): ?>
        <a class="btn" href="<?= e(url('/admin/metadata')) ?>">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</section>
<?php endif; ?>

<?php // Platform mapping used to live here: what this source calls each of your
      // platforms. It belongs on the platform, alongside choosing which agents
      // apply to it - a source is configured once, and which machines it is
      // asked about is a property of the machine. ?>
