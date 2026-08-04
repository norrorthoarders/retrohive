<?php /** @var array|null $item @var string $query @var int|null $platformId */
/** @var array $results @var array $errors @var array $providers */ ?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Metadata lookup</span>
    <h1><?= $item ? e($item['title']) : 'Search the sources' ?></h1>
  </div>
  <div class="pagehead__actions">
    <?php if ($item): ?><a class="btn" href="<?= e(url('/items/' . $item['id'] . '/edit')) ?>">Back to the entry</a><?php endif; ?>
    <a class="btn btn--sm" href="<?= e(url('/manage/metadata')) ?>">Sources</a>
  </div>
</div>

<?php if (!$providers): ?>
  <div class="empty">
    <h2>No sources configured</h2>
    <p>Add a metadata source first. Wikidata needs no account at all.</p>
    <a class="btn btn--accent" href="<?= e(url('/manage/metadata')) ?>">Add a source</a>
  </div>
<?php else: ?>

<form class="filters" method="get" action="<?= e(url('/metadata/lookup')) ?>">
  <?php if ($item): ?><input type="hidden" name="item" value="<?= (int) $item['id'] ?>"><?php endif; ?>
  <?php
  // A title and a button, and nothing else.
  //
  // There was a select here labelled "Library" whose first option read "Any
  // platform" and whose contents were libraries - and whatever you chose was
  // passed as `platform`, the id of a machine. A library id compared against
  // platform ids matches nothing on purpose, so choosing anything narrowed the
  // search to a machine that does not exist.
  //
  // Removed rather than repaired, because the right answer was never a control:
  // the entry knows which machine it is for, and the working library is chosen
  // in the header. Two places to say the same thing is how they disagree.
  ?>
  <?php if ($platformId !== null): ?>
    <input type="hidden" name="platform" value="<?= (int) $platformId ?>">
  <?php endif; ?>
  <div class="field">
    <label for="q">Title</label>
    <input id="q" name="q" type="search" value="<?= e($query) ?>" autofocus>
  </div>
  <div class="filters__foot">
    <span class="resultcount"><?= count($results) ?> suggestion<?= count($results) === 1 ? '' : 's' ?></span>
    <button class="btn btn--accent btn--sm" type="submit">Search</button>
  </div>
</form>

<?php foreach ($errors as $name => $msg): ?>
  <div class="flash flash--error"><?= e($name) ?>: <?= e($msg) ?></div>
<?php endforeach; ?>

<?php
// What each source said, in one line.
//
// A source that found nothing has no row on this page, so "did it even ask the
// Big Book?" was a fair question with no way to answer it - a search for a card
// that site has never heard of looked exactly like a search that never ran.
//
// This is not the advice that used to live here. It is the result: names and
// counts, no recommendations, and it disappears entirely when there is nothing
// to report.
?>
<?php if (!empty($asked)): ?>
  <p class="hint" style="margin:.2rem 0 .8rem">
    <?php
    $said = [];
    foreach ($asked as $name => $n) {
        $said[] = e((string) $name) . ' ' . ($n === 0 ? '—' : (int) $n);
    }
    ?>
    Asked: <?= implode(' · ', $said) ?>
  </p>
<?php endif; ?>

<?php
// No notice about unmapped sources either.
//
// It was true and it was advice, and it sat above every result on every lookup
// for anybody who had not mapped a source - which is a paragraph of reading
// between somebody and the answer they came for. The place to say a source is
// not narrowed is the agents page, where the button that narrows it is.
?>
<?php
// Nothing about which sources were not asked. A source that does not cover this
// machine, or does not answer about this half of the shop, will not on the next
// lookup either - naming it every time is noise on a screen somebody is reading
// for the answer. It is in bin/lookup.php --verbose for the day it matters.
?>


<?php if ($results && $item): ?>
  <?php foreach ($results as $i => $r): $fields = metadata_to_item_fields($r); ?>
  <section class="panel" style="margin-top:1rem">
    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:baseline">
      <h2 style="margin:0"><?= e($r['title']) ?><?= $r['year'] ? ' <span class="mono" style="color:var(--faint);font-size:.9rem">(' . (int) $r['year'] . ')</span>' : '' ?></h2>
      <span style="display:flex;gap:.35rem;flex-wrap:wrap">
        <?php
        // Says plainly whether this candidate is even for the right machine.
        $match = $r['platform_match'] ?? 'unknown';
        if ($match === 'exact'): ?>
          <span class="chip" style="border-color:var(--good);color:var(--good)">matches this library</span>
        <?php elseif ($match === 'close'): ?>
          <span class="chip" style="border-color:var(--warn);color:var(--warn)">similar platform</span>
        <?php elseif ($match === 'other'): ?>
          <span class="chip" style="border-color:var(--bad);color:var(--bad)">different platform</span>
        <?php endif; ?>
        <span class="chip"><?= e($r['provider_label']) ?></span>
      </span>
    </div>
    <p class="mono" style="font-size:.78rem;color:var(--faint);margin:.3rem 0 .8rem">
      <?php if (!empty($r['platform'])): ?>
        <?= e($r['platform']) ?>
      <?php elseif (!empty($r['platform_id'])): ?>
        platform id <?= e((string) $r['platform_id']) ?>
      <?php else: ?>
        platform not stated
      <?php endif; ?>
      <?php if ($r['url']): ?> · <a href="<?= e($r['url']) ?>" target="_blank" rel="noopener noreferrer external">source</a><?php endif; ?>
    </p>

    <?php
    // Does this answer look like the thing that was asked about?
    //
    // A search for "Deluxe Paint IV" came back with *Brilliance* - a different
    // program, related enough for a search engine and not at all the entry being
    // catalogued - with its description, reference link and year ticked. One
    // press would have replaced a Deluxe Paint entry with another product's
    // details.
    //
    // A source is still allowed to know something under a name nobody else uses,
    // so this warns rather than hides: what it changes is that nothing is ticked
    // for you. Saying yes to it has to be a decision.
    $looksRight = metadata_title_resembles((string) ($query ?? $item['title']), (string) $r['title']);
    ?>
    <?php if (!$looksRight): ?>
      <p class="flash flash--error" style="margin:.4rem 0 .8rem">
        This is called <strong><?= e((string) $r['title']) ?></strong>, which is not
        what you searched for — check it is the same release before importing
        anything. Nothing here is ticked for you.
      </p>
    <?php endif; ?>

    <?php if (!$fields): ?>
      <p class="lede" style="margin:0">Nothing here that maps onto a field.</p>
    <?php else: ?>
    <form method="post" action="<?= e(url('/metadata/apply')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
      <input type="hidden" name="candidate" value="<?= e(json_encode($r)) ?>">
      <table class="table">
        <thead><tr><th style="width:1%"></th><th>Field</th><th>Currently</th><th>Would become</th></tr></thead>
        <tbody>
          <?php
          // The names the form uses, not a second set of names for the same
          // columns.
          //
          // A machine is not developed: the hardware form calls `developer_name`
          // the Company, because Commodore made the Amiga 2000 rather than
          // developing it. Showing "Developer: Commodore" here and "Company:
          // Commodore" one click later is two words for one field, and leaves the
          // person to work out they are the same one.
          $labelFor = fn(string $f): string => item_field_label($f, $domain ?? null);
          $current = [
            'title' => $item['title'], 'release_year' => $item['release_year'],
            'release_date' => $item['release_date'],
            'developer_name' => $item['developer_name'], 'publisher_name' => $item['publisher_name'],
            'external_url' => $item['external_url'], 'notes' => $item['notes'],
            'description' => $item['description'] ?? null,
          ];
          foreach ($fields as $field => $value):
            $now = $current[$field] ?? null;
            $same = (string) $now === (string) $value; ?>
          <tr>
            <td><input type="checkbox" name="apply[]" value="<?= e($field) ?>" <?= $looksRight && ($now === null || $now === '') && !$same ? 'checked' : '' ?>></td>
            <td><?= e($labelFor($field)) ?></td>
            <td style="color:var(--faint);font-size:.85rem"><?= e(truncate((string) ($now ?? ''), 60)) ?: '—' ?></td>
            <td style="font-size:.85rem">
              <?= e(truncate((string) $value, 90)) ?>

            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php
      // Hardware detail lands in a different table, so it gets its own block
      // rather than being mixed in with title and release year.
      // Only on a hardware entry. These write to item_hardware, and a lookup on
      // a paint program was being offered a "Made in" row and a specification
      // table - fields that half of the catalogue does not have.
      $hwFields = ($domain ?? '') === 'hardware'
          ? metadata_to_hardware_fields($r, (int) $item['platform_id'])
          : [];
      if ($hwFields !== []):
        $hwLabels  = hardware_field_labels();
        $hwCurrent = one('SELECT * FROM item_hardware WHERE item_id = ?', [(int) $item['id']]) ?? [];
      ?>
        <p class="label" style="margin-top:.9rem">Hardware detail</p>
        <table class="table">
          <thead><tr><th style="width:1%"></th><th>Field</th><th>Currently</th><th>Would become</th></tr></thead>
          <tbody>
            <?php foreach ($hwFields as $field => $value):
              $now  = $hwCurrent[$field] ?? null;
              $same = (string) $now === (string) $value; ?>
            <tr>
              <td><input type="checkbox" name="apply_hw[]" value="<?= e($field) ?>"
                         <?= $looksRight && ($now === null || $now === '') && !$same ? 'checked' : '' ?>></td>
              <td><?= e($hwLabels[$field] ?? $field) ?></td>
              <td style="color:var(--faint);font-size:.85rem"><?= e(truncate((string) ($now ?? ''), 50)) ?: '—' ?></td>
              <td style="font-size:.85rem">
                <?= e(truncate((string) $value, 90)) ?>
                <?php if ($field === 'interface'): ?>
                  <span class="hint">(<?= e(hardware_vocab_label('interface', (string) $value, (int) $item['platform_id'])) ?>)</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <?php
      // Specification rows, from a source that carries them. Wikipedia's infobox
      // is the reason this exists: maker, processor, memory, graphics, sound and
      // ports arrive as a list already in the shape of "What it has".
      //
      // Merged rather than written over the top - a row the entry already has is
      // offered but starts unticked, so a lookup cannot quietly replace a line
      // somebody wrote by hand because the source phrases it differently.
      $specRows = ($domain ?? '') === 'hardware'
          ? metadata_spec_rows($r, (int) $item['id'])
          : [];
      if ($specRows !== []):
      ?>
        <p class="label" style="margin-top:.9rem">Specification rows</p>
        <table class="table">
          <thead><tr><th style="width:1%"></th><th>Row</th><th>Currently</th><th>Would become</th></tr></thead>
          <tbody>
            <?php foreach ($specRows as $sr): ?>
              <tr>
                <td><input type="checkbox" name="apply_spec[]" value="<?= (int) $sr['index'] ?>"
                           <?= $looksRight && ($sr['current'] === null || $sr['current'] === '') ? 'checked' : '' ?>></td>
                <td><?= e($sr['label']) ?></td>
                <td style="color:var(--faint);font-size:.85rem"><?= e(truncate((string) ($sr['current'] ?? ''), 40)) ?: '—' ?></td>
                <td style="font-size:.85rem"><?= e(truncate($sr['value'], 80)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="hint" style="margin:.3rem 0 .8rem">
          Added to <strong>What it has</strong>. A row you already have keeps its
          place and its wording unless you tick it.
        </p>
      <?php endif; ?>

      <?php if (!empty($r['documents'])): ?>
        <?php
        // Tickable now. They used to be a list with "not imported" under it,
        // which told you the manual exists somewhere and left you to copy the
        // address down by hand.
        //
        // The link is kept, not the file. A scanned service manual is tens of
        // megabytes and is already hosted by somebody who curates it; copying it
        // here would make this instance responsible for the storage and for a
        // redistribution question nobody asked.
        ?>
        <p class="label" style="margin-top:.9rem">Documents at the source</p>
        <div style="margin:0 0 .6rem">
          <?php foreach ($r['documents'] as $dx => $doc): ?>
            <label class="checkline" style="display:flex;gap:.5rem;align-items:baseline">
              <input type="checkbox" name="documents[]" value="<?= (int) $dx ?>" <?= $looksRight ? 'checked' : '' ?>>
              <span>
                <?= e($doc['name']) ?>
                <a href="<?= e($doc['url']) ?>" target="_blank" rel="noopener noreferrer external"
                   class="hint" style="margin-left:.4rem">open</a>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
        <p class="hint" style="margin:0 0 .8rem">
          Ticked documents are kept as links on the entry. The files stay where they
          are — this records that the manual exists and where it is.
        </p>
      <?php endif; ?>

      <?php if (!empty($r['images'])): ?>
        <p class="label" style="margin-top:.8rem">Stock images</p>
        <?php
        // Which of these the entry already has, worked out before anything is
        // ticked. Matched on the address each was fetched from - the content hash
        // still refuses a duplicate on the way in, but being told afterwards that
        // five of the six you ticked were already there is an answer to a
        // question nobody would have asked.
        $alreadyHere = metadata_images_already_here($r, $item === null ? null : (int) $item['id']);
        ?>
        <div style="display:flex;gap:.8rem;flex-wrap:wrap;margin-bottom:.6rem">
          <?php foreach ($r['images'] as $ix => $img): ?>
            <?php $have = !empty($alreadyHere[(int) $ix]); ?>
            <label style="display:flex;flex-direction:column;gap:.35rem;max-width:150px;<?=
                $have ? 'opacity:.45;cursor:default' : 'cursor:pointer' ?>">
              <?php
              // Through this server, not straight from the source. The content
              // policy refuses a remote address - which is the point of it - so
              // these were blank lines above a row of tick boxes, and choosing
              // between "Box front" and "Box front" was guesswork.
              ?>
              <?php
              // The thumbnail where the source gives one. It is the address that
              // certainly exists - the full-size one is derived by rule - and a
              // preview is 150px wide, so fetching a megabyte to show it was
              // waste even when the rule was right.
              ?>
              <img src="<?= e(url('/metadata/preview', ['url' => $img['thumb_url'] ?? $img['url']])) ?>"
                   alt="" loading="lazy"
                   style="width:150px;height:150px;object-fit:contain;border-radius:var(--r);border:1px solid var(--line);background:var(--crust)">
              <span class="checkline" style="font-size:.8rem">
                <?php if ($have): ?>
                  <?php
                  // Not disabled-and-ticked, which would read as "about to be
                  // imported again". There is nothing to choose here, so there is
                  // no control.
                  ?>
                  <span aria-hidden="true">✓</span>
                <?php else: ?>
                  <input type="checkbox" name="artwork[]" value="<?= (int) $ix ?>">
                <?php endif; ?>
                <?php // The source's own words where it gave any. These are
                      // motherboards, and "Box front" is both wrong and
                      // indistinguishable from the next one along. ?>
                <?= e($img['caption'] ?? image_kind_label($img['kind'] ?? 'other')) ?>
                <?php if ($have): ?><em style="opacity:.8">— already here</em><?php endif; ?>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
        <p class="hint" style="margin:0 0 .8rem">
          <?php
          // Which set these land in, said here rather than discovered afterwards.
          // A lookup only ever writes to the official one, and somebody ticking
          // six pictures should know they are not about to be mixed in with
          // photographs of their own copy.
          $artSection = null;
          foreach (image_sections((string) ($domain ?? 'software')) as $sec) {
              if ($sec['scrapable']) { $artSection = $sec['title']; break; }
          }
          ?>
          Ticked images are downloaded to this server and checked like any upload —
          the preview is not what gets kept — and land in
          <strong><?= e((string) ($artSection ?? 'the official set')) ?></strong>,
          never among your own photographs. Shown through this server rather than
          from the source, so the page loads no third-party addresses.
        </p>
      <?php endif; ?>
      <p class="hint" style="margin:.4rem 0 .8rem">Empty fields are ticked by default; anything you already filled in is left alone unless you say otherwise.</p>
      <button class="btn btn--accent btn--sm" type="submit">Import ticked items</button>
    </form>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
<?php elseif ($results): ?>
  <div class="flash flash--error" style="margin-top:1rem">
    Open this from an entry's edit page to import into it.
  </div>
<?php elseif (trim($query) !== ''): ?>
  <div class="empty"><h2>Nothing found</h2><p>No source recognised that title. Try a shorter or more exact spelling.</p></div>
<?php endif; ?>

<?php endif; ?>
