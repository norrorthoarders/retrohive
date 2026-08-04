<?php
/** @var array $item @var array $images @var array $tags @var array $siblings */
$cover = null;
foreach ($images as $img) {
    if ((int) $img['is_primary'] === 1) { $cover = $img; break; }
}
$cover = $cover ?? ($images[0] ?? null);
$spine = $item['platform_color'] ?: '#cba6f7';
?>

<div class="titleblock" style="--spine: <?= e($spine) ?>">
  <span class="eyebrow">
    <a href="<?= e(url('/items', ['platform' => $item['platform_slug']])) ?>"><?= e($item['platform_name']) ?></a>
    ·
    <a href="<?= e(url('/items', ['category' => $item['category_slug']])) ?>"><?= e($item['category_name']) ?></a>
    <?php
    // No genre link. A genre is a category now - "Games › Racing" is a leaf of
    // the tree like any other - and v_items stopped carrying genre_name with
    // that change. This kept reading it, which is an undefined key on every
    // entry page and a link to a filter that no longer exists.
    ?>
  </span>
  <h1><?= e($item['title']) ?></h1>
  <?php if ($item['subtitle']): ?><p class="sub"><?= e($item['subtitle']) ?></p><?php endif; ?>
  <?php if ($item['status'] !== 'owned'): ?>
    <p>
      <span class="chip chip--on"><?= e(status_label($item['status'])) ?></span>
      <?php if ($item['status'] === 'sold' && $item['sold_on']): ?>
        <span class="chip"><?= e($item['sold_on']) ?><?= $item['sold_price'] !== null ? ' for ' . e(money((float) $item['sold_price'], $item['sold_currency'] ?: $item['currency'])) : '' ?></span>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</div>

<div class="detail">
  <div>
    <?php if ($cover): ?>
      <figure class="hero" style="margin:0">
        <img data-hero data-zoom="<?= e(image_url($cover['filename'], 'orig')) ?>"
             src="<?= e(image_url($cover['filename'], 'display')) ?>"
             alt="<?= e($cover['caption'] ?: image_kind_label($cover['kind']) . ' of ' . $item['title']) ?>">
      </figure>
      <?php
      // Grouped, because the publisher's artwork and a photograph of the copy on
      // somebody's shelf answer different questions - what is this, and what
      // condition is yours in - and one undifferentiated strip made you work out
      // which was which from the pictures.
      //
      // A section with nothing in it is not drawn, so an entry with only artwork
      // still looks like an entry with only artwork rather than one with two
      // empty shelves.
      $grouped = item_images_by_section((int) $item['id'],
                                        (string) ($item['domain'] ?? 'software'),
                                        (int) ($item['has_box'] ?? 0) === 1);
      ?>
      <?php if (count($images) > 1): ?>
        <?php foreach ($grouped as $group): ?>
          <?php if ($group['images'] === []) { continue; } ?>
          <p class="label" style="margin:.8rem 0 .3rem"><?= e($group['section']['title']) ?></p>
          <div class="gallery" data-gallery>
            <?php foreach ($group['images'] as $img): ?>
              <button type="button"
                      class="<?= $img['id'] === $cover['id'] ? 'is-active' : '' ?>"
                      data-full="<?= e(image_url($img['filename'], 'display')) ?>"
                      data-caption="<?= e($img['caption'] ?: image_kind_label($img['kind'])) ?>"
                      title="<?= e($img['caption'] ?: image_kind_label($img['kind'])) ?>">
                <img src="<?= e(image_url($img['filename'], 'thumb')) ?>" alt="<?= e(image_kind_label($img['kind'])) ?>" loading="lazy">
              </button>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php else: ?>
      <div class="hero hero--empty">no packaging photos yet</div>
      <?php if (can_edit()): ?>
        <p style="margin-top:.6rem"><a class="btn btn--sm" href="<?= e(url('/items/' . $item['id'] . '/edit')) ?>">Upload photos</a></p>
      <?php endif; ?>
    <?php endif; ?>

    <?php
    // For hardware the note lives on item_hardware.modifications, which is the
    // one the form asks for. items.notes is still the note for software; showing
    // it here as well meant a hardware entry could display a sentence with no
    // field behind it anywhere in its own form.
    $isHardware = ($item['domain'] ?? '') === 'hardware';
    $noteText   = $isHardware
        ? (string) (($hardware ?? [])['modifications'] ?? '')
        : (string) ($item['notes'] ?? '');
    ?>
    <?php
    // Description and notes are in the right-hand column.
    //
    // The left column is as wide as a cover photograph, which is a good width for
    // a picture and a poor one for prose: a description ran to four or five words
    // a line down a narrow strip beside a lot of empty space. They read where
    // there is room for them.
    ?>
    <?php if ($siblings): ?>
      <section class="panel" style="margin-top:1rem">
        <h2 class="panel__title">Related on the same library</h2>
        <div class="chips">
          <?php foreach ($siblings as $s): ?>
            <a class="chip" href="<?= e(url('/items/' . $s['id'])) ?>"><?= e($s['title']) ?><?= $s['release_year'] ? ' (' . (int) $s['release_year'] . ')' : '' ?></a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>

  <aside>
    <?php
    // Description first, then whatever this copy's owner wrote: the description
    // answers "what is this" and the note answers "what about this one", and the
    // first question comes first.
    ?>
    <?php if (trim((string) ($item['description'] ?? '')) !== ''): ?>
      <section class="panel">
        <h2 class="panel__title">Description</h2>
        <div class="notes"><?= e((string) $item['description']) ?></div>
      </section>
    <?php endif; ?>

    <?php if (trim($noteText) !== ''): ?>
      <section class="panel">
        <h2 class="panel__title"><?= $isHardware ? 'Modifications and notes' : 'Notes' ?></h2>
        <div class="notes"><?= e($noteText) ?></div>
      </section>
    <?php endif; ?>

    <section class="panel">
      <h2 class="panel__title">Rating</h2>
      <?php partial('rating', ['rating' => $item['rating']]); ?>
    </section>

      <?php
// All of them, not the one that fitted in a column. A PC release commonly runs under
// several, and naming only the first was quietly wrong on exactly the entries where the
// question matters.
$envNames = array_column(all(
    'SELECT o.name FROM item_environments e
       JOIN operating_systems o ON o.id = e.os_id
      WHERE e.item_id = ? ORDER BY o.sort_order, o.name',
    [(int) $item['id']]
), 'name');
$runsOn = $envNames !== []
    ? implode(', ', $envNames)
    : null;
if ($runsOn !== null): ?>
  <p class="lede" style="font-size:.95rem">
    Runs on <strong><?= e((string) $runsOn) ?></strong>.
  </p>
<?php endif; ?>

<?php if (($item['domain'] ?? '') === 'hardware'): ?>
  <section class="panel">
    <h2 class="panel__title">Specification</h2>
    <?php if ($hardware === null): ?>
      <p class="lede" style="margin:0;font-size:.9rem">
        Nothing recorded yet. Edit the entry to add the model, revision, interface
        and what it fits.
      </p>
    <?php else: ?>
      <table class="table">
        <tbody>
          <?php
          $spec = [
            'model'             => 'Model',
            'board_revision'    => 'Board revision',
            'firmware'          => 'Firmware',
            'serial_number'     => 'Serial',
            'interface'         => 'Interface',
            'provides'          => 'Provides',
            'fits'              => 'Fits',
            'region'            => 'Region',
            'recapped_on'       => 'Recapped',
            'modifications'     => 'Notes',
          ];
          // model, interface and fits check hardware_detail()'s resolved_*
          // form first: the entry's own value if it typed one, the model's
          // otherwise. Reading $hardware[$col] alone showed a blank row on
          // every entry whose model carried the value and whose own record did
          // not - which was every entry with a model set and nothing retyped.
          $resolved = ['model' => 'resolved_model', 'interface' => 'resolved_interface',
                       'fits' => 'resolved_fits'];
          foreach ($spec as $col => $label):
              $v = isset($resolved[$col])
                  ? ($hardware[$resolved[$col]] ?? null)
                  : ($hardware[$col] ?? null);
              if ($v === null || $v === '' || $v === 'unknown') continue;
              if ($col === 'interface') {
                  $v = hardware_vocab_label('interface', (string) $v, (int) $item['platform_id']);
              }
          ?>
            <tr>
              <td style="width:11rem"><span class="hint"><?= e($label) ?></span></td>
              <td><?= nl2br(e((string) $v)) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php
          // Whatever this particular unit is, in the order it was written.
          $specs = $hardware['specs'] ?? null;
          $specs = $specs ? json_decode((string) $specs, true) : null;
          if (is_array($specs)):
              foreach ($specs as $row):
                  $label = trim((string) ($row['label'] ?? ''));
                  $value = trim((string) ($row['value'] ?? ''));
                  if ($label === '') continue; ?>
                <tr>
                  <td><span class="hint"><?= e($label) ?></span></td>
                  <td><?= $value === '' ? '<span class="hint">&mdash;</span>' : nl2br(e($value)) ?></td>
                </tr>
              <?php endforeach;
          endif; ?>
          <tr>
            <td><span class="hint">Working state</span></td>
            <td>
              <?php $st = (string) ($hardware['working_state'] ?? 'untested');
              $col = ['working'=>'var(--good)','restored'=>'var(--good)','not_working'=>'var(--bad)',
                      'intermittent'=>'var(--warn)'][$st] ?? 'var(--dim)'; ?>
              <span style="color:<?= $col ?>"><?= e(str_replace('_', ' ', $st)) ?></span>
            </td>
          </tr>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <?php
  // What a card fits, from its model where the model knows and from the card
  // itself otherwise. Shown either way: it is the first thing anybody asks of a
  // loose board.
  if (!is_machine_category((int) ($item['category_id'] ?? 0))):
      $fitsHere = effective_compatibility((int) $item['id'], (int) ($item['model_id'] ?? 0));
      if ($fitsHere['names'] !== []): ?>
        <section class="panel">
          <h2 class="panel__title">Fits</h2>
          <p style="margin:0"><?= e(implode(', ', $fitsHere['names'])) ?></p>
          <p class="hint" style="margin:.4rem 0 0">
            <?= $fitsHere['from'] === 'model'
                ? 'From the peripheral model.'
                : 'Recorded on this one.' ?>
          </p>
        </section>
      <?php endif;
  endif; ?>

  <?php
  // Both directions, not just the machine's. A card's own page is where somebody
  // asks "is this installed in anything, and what?" - and the answer used to be
  // visible only on the machine's page or inside the card's edit form.
  $isMachineHere = is_machine_category((int) ($item['category_id'] ?? 0));
  if ($isMachineHere || $parents !== [] || $children !== []):
    partial('item_links', [
      'item' => $item, 'parents' => $parents, 'children' => $children,
      'chain' => $chain, 'goesWith' => $goesWith, 'linkable' => $linkable,
      // Fitting is done on the edit form, which enforces the rules. No manual
      // link builder here, and nothing destructive on a page being read.
      'showLinkForm' => false,
    ]);
  endif; ?>
<?php endif; ?>

    <?php
    // Credits are a software idea: a developer, a publisher, a release date and a
    // reference link. The hardware form collects none of the four, so on a circuit
    // board this panel was four rows of em dashes. Who made it is already on the
    // entry through its model's vendor, and when it came out belongs to the model
    // rather than to this particular unit.
    ?>
    <?php if (!$isHardware): ?>
    <section class="panel">
      <h2 class="panel__title">Credits</h2>
      <dl class="spec">
        <dt>Developer</dt>
        <dd>
          <?php if ($item['developer_name']): ?>
            <?php if (!empty($item['developer_logo'])): ?>
              <img class="logo--inline" src="<?= e(image_url($item['developer_logo'], 'thumb')) ?>" alt="">
            <?php endif; ?>
            <a href="<?= e(url('/developers/' . $item['developer_slug'])) ?>"><?= e($item['developer_name']) ?></a>
            <?php if ($item['developer_website']): ?>
              <br><a class="mono" style="font-size:.78rem" href="<?= e($item['developer_website']) ?>" rel="noopener noreferrer external" target="_blank"><?= e(parse_url($item['developer_website'], PHP_URL_HOST) ?: 'website') ?></a>
            <?php endif; ?>
          <?php else: ?>—<?php endif; ?>
        </dd>
        <dt>Publisher</dt>
        <dd><?php if ($item['publisher_name']): ?><a href="<?= e(url('/developers/' . $item['publisher_slug'])) ?>"><?= e($item['publisher_name']) ?></a><?php else: ?>—<?php endif; ?></dd>
        <dt>Released</dt>
        <dd class="mono"><?= e($item['release_date'] ?: ($item['release_year'] ? (string) (int) $item['release_year'] : '—')) ?></dd>
        <?php if ($item['external_url']): ?>
          <dt>Reference</dt>
          <dd><a href="<?= e($item['external_url']) ?>" rel="noopener noreferrer external" target="_blank"><?= e(parse_url($item['external_url'], PHP_URL_HOST) ?: 'link') ?></a></dd>
        <?php endif; ?>
      </dl>
    </section>
    <?php endif; ?>

    <?php
    // Documents, where there are any.
    //
    // Links to somebody else's archive rather than files here: the manual is
    // hosted by people who curate it, and what this catalogue is adding is that
    // this entry is the thing that manual is about.
    ?>
    <?php if (($documents ?? []) !== []): ?>
    <section class="panel">
      <h2 class="panel__title">External links</h2>
      <ul style="margin:0;padding-left:1.1rem">
        <?php foreach ($documents as $doc): ?>
          <li style="margin-bottom:.3rem">
            <a href="<?= e((string) $doc['url']) ?>" target="_blank"
               rel="noopener noreferrer external"><?= e((string) $doc['label']) ?></a>
            <span class="hint" style="margin-left:.4rem">
              <?= e(parse_url((string) $doc['url'], PHP_URL_HOST) ?: 'elsewhere') ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="hint" style="margin:.6rem 0 0">
        Held elsewhere, not on this server.
      </p>
    </section>
    <?php endif; ?>

    <section class="panel">
      <h2 class="panel__title"><?= $isHardware ? 'Physical hardware' : 'The physical copy' ?></h2>
      <?php
      // What is in the box, when anybody has said. Missing things are named rather than
      // counted: "missing manual" is what you want to read, not "4 of 5".
      $boxRows = item_contents((int) $item['id']);
      ?>
      <?php if ($boxRows !== []): ?>
        <p class="label" style="margin:0 0 .3rem">In the box</p>
        <div class="chips" style="margin-bottom:.8rem">
          <?php foreach ($boxRows as $bc): ?>
            <?php
            $tone = ['yes' => 'var(--good)', 'no' => 'var(--bad)'][$bc['present']] ?? 'var(--dim)';
            $mark = ['yes' => '&check;', 'no' => '&times;'][$bc['present']] ?? '?';
            ?>
            <span class="chip" title="<?= e((string) ($bc['note'] ?? '')) ?>">
              <span style="color:<?= $tone ?>"><?= $mark ?></span>
              <?= e((string) $bc['label']) ?>
            </span>
          <?php endforeach; ?>
        </div>
        <?php $note = item_completeness_note((int) $item['id']); ?>
        <?php if ($note !== null): ?>
          <p class="hint" style="margin:0 0 .8rem"><?= e($note) ?></p>
        <?php endif; ?>
      <?php endif; ?>
      <dl class="spec">
        <dt>Condition</dt><dd><?= e(condition_label($item['condition_grade'])) ?></dd>
        <?php if ($isHardware): ?>
          <?php
          // Two facts, not one. Whether a box exists, and how good it is - a
          // grade alone cannot say "there isn't one", and the absence of a box is
          // worth stating rather than leaving the reader to infer it.
          ?>
          <dt>Box or case</dt>
          <dd>
            <?php if ((int) ($item['has_box'] ?? 0) === 1): ?>
              Yes<?= $item['condition_box'] === 'unknown'
                    ? ' — not graded'
                    : ' — ' . e(strtolower(condition_label($item['condition_box']))) ?>
            <?php else: ?>
              None
            <?php endif; ?>
          </dd>
          <?php
          // Media, copies, catalogue number, barcode, language and Original are all
          // gone from here. None of them is on the hardware form, so they could only
          // ever show a default - and Original defaulted to 0, which rendered
          // "No / copy" on a board that is plainly the real thing. A row that cannot
          // be filled in and lies when empty is worse than no row.
          ?>
        <?php else: ?>
        <dt>Completeness</dt><dd><?= e(completeness_label($item['completeness'])) ?></dd>
        <?php if ($item['condition_box'] !== 'unknown' || $item['condition_manual'] !== 'unknown' || $item['condition_media'] !== 'unknown'): ?>
          <dt>Box</dt><dd><?= e(condition_label($item['condition_box'])) ?></dd>
          <dt>Manual</dt><dd><?= e(condition_label($item['condition_manual'])) ?></dd>
          <dt>Media</dt><dd><?= e(condition_label($item['condition_media'])) ?></dd>
        <?php endif; ?>
        <?php if ((int) $item['copies'] > 1): ?><dt>Copies</dt><dd class="mono"><?= (int) $item['copies'] ?></dd><?php endif; ?>
        <dt>Media</dt><dd><?= e($item['media_type'] ?: '—') ?><?= (int) $item['media_count'] > 1 ? ' × ' . (int) $item['media_count'] : '' ?></dd>
        <?php if ($item['catalog_number']): ?><dt>Catalog no.</dt><dd class="mono"><?= e($item['catalog_number']) ?></dd><?php endif; ?>
        <?php if ($item['barcode']): ?><dt>Barcode</dt><dd class="mono"><?= e($item['barcode']) ?></dd><?php endif; ?>
        <?php if ($item['language']): ?><dt>Language</dt><dd><?= e($item['language']) ?></dd><?php endif; ?>
        <?php if ($item['region']): ?><dt>Region</dt><dd><?= e($item['region']) ?></dd><?php endif; ?>
        <dt>Original</dt><dd><?= (int) $item['is_original'] === 1 ? 'Yes' : 'No / copy' ?></dd>
        <?php endif; ?>
        <?php if ($item['location_name']): ?>
          <dt>Stored</dt>
          <dd>
            <?= e(location_breadcrumb((int) $item['location_id'])) ?>
            <?php
            // The whereabouts too. "Book Shelf 1" narrows it to a shelf; the
            // position is which slot on that shelf, and leaving it out of the one
            // place that answers "where is it" made recording it pointless.
            ?>
            <?php if (($item['location_position'] ?? '') !== ''): ?>
              <span class="hint"> · <?= e((string) $item['location_position']) ?></span>
            <?php endif; ?>
          </dd>
        <?php endif; ?>
        <?php if ($item['acquired_on'] || $item['acquired_price'] !== null): ?>
          <dt>Acquired</dt>
          <dd class="mono"><?= e($item['acquired_on'] ?: '—') ?><?= $item['acquired_price'] !== null ? ' · ' . e(money((float) $item['acquired_price'], $item['currency'])) : '' ?></dd>
        <?php endif; ?>
        <?php if ($item['current_value'] !== null): ?>
          <dt>Worth now</dt>
          <dd class="mono"><?= e(money((float) $item['current_value'], $item['currency'])) ?><?= $item['valued_on'] ? ' · ' . e($item['valued_on']) : '' ?></dd>
        <?php endif; ?>
      </dl>
    </section>

    <?php if ($tags): ?>
      <section class="panel">
        <h2 class="panel__title">Tags</h2>
        <div class="chips">
          <?php foreach ($tags as $t): ?><span class="chip"><?= e($t['name']) ?></span><?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <section class="panel">
      <h2 class="panel__title">Record</h2>
      <dl class="spec">
        <dt>Added</dt><dd class="mono"><?= e(substr((string) $item['created_at'], 0, 10)) ?></dd>
        <dt>Edited</dt><dd class="mono"><?= e(substr((string) $item['updated_at'], 0, 10)) ?></dd>
        <dt>Photos</dt><dd class="mono"><?= (int) $item['image_count'] ?></dd>
      </dl>
      <?php if (can_edit() && can_write_item($item)): ?>
        <div style="display:flex;gap:.5rem;margin-top:1rem;flex-wrap:wrap">
          <a class="btn btn--sm" href="<?= e(url('/items/' . $item['id'] . '/edit')) ?>">Edit</a>
          <form method="post" action="<?= e(url('/items/' . $item['id'] . '/delete')) ?>" data-confirm="Delete &quot;<?= e($item['title']) ?>&quot; and all its photos? This cannot be undone.">
            <?= csrf_field() ?>
            <button class="btn btn--sm btn--danger" type="submit">Delete</button>
          </form>
        </div>
      <?php endif; ?>
    </section>
  </aside>
</div>

<dialog class="lightbox">
  <img src="" alt="">
  <div class="lightbox__cap"></div>
</dialog>

<?php
// No "what it is fitted to" on a software entry. Fitting is a hardware idea - a
// card is in a machine - and a disk is not installed in anything, so the panel
// only ever said so at length. Hardware keeps its own, above.
?>
