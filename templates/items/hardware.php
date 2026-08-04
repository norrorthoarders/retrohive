<?php
/** @var array $items @var array $platforms @var array $categories @var array $active */
/** @var int $page @var int $pages @var int $total */

// Hardware is read as specification, not browsed as cover art. A table shows
// interface, revision and working state at a glance, which is what you want
// when checking whether you already own a particular card - a grid of
// thumbnails cannot answer that.
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Machines, cards and parts</span>
    <h1>Hardware</h1>
  </div>
  <div class="formactions" style="margin:0">
    <?php partial('view_switch', ['view' => $view ?? 'table', 'domain' => 'hardware']); ?>
    <a class="btn btn--accent" href="<?= e(url('/items/new', ['domain' => 'hardware', 'as' => 'machine'])) ?>">Add a machine</a>
    <a class="btn" href="<?= e(url('/items/new', ['domain' => 'hardware', 'as' => 'part'])) ?>">Add a peripheral</a>
  </div>
</div>

<form method="get" action="<?= e(url('/hardware')) ?>" class="panel filters">
  <div class="formgrid">
    <div class="field field--third">
      <label for="q">Search</label>
      <input id="q" name="q" type="search" value="<?= e($active['q'] ?? '') ?>"
             placeholder="Model, serial, manufacturer">
    </div>
    <div class="field field--third">
      <label for="platform">Platform</label>
      <select id="platform" name="platform">
        <option value="">Any</option>
        <?php
        // Narrowed to what is on a shelf, plus whatever is currently selected so a
        // filter can always be read back off the URL it came from.
        $platUsed = $inUse['platforms'] ?? null;
        foreach ($platforms as $p):
            if ($platUsed !== null
                && !in_array($p['slug'], $platUsed, true)
                && ($active['platform'] ?? '') !== $p['slug']) { continue; } ?>
          <option value="<?= e($p['slug']) ?>" <?= ($active['platform'] ?? '') === $p['slug'] ? 'selected' : '' ?>>
            <?= e($p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field field--third">
      <?php
      // Machines or cards. The two halves of this browser answer different
      // questions - what have I got, versus what is in it - and until now the
      // only way to see one without the other was to know the tree well enough
      // to pick every branch of it.
      ?>
      <label for="kind">Machines or peripherals</label>
      <select id="kind" name="kind">
        <option value="">Both</option>
        <option value="machine" <?= ($active['kind'] ?? '') === 'machine' ? 'selected' : '' ?>>
          Machines
        </option>
        <option value="peripheral" <?= ($active['kind'] ?? '') === 'peripheral' ? 'selected' : '' ?>>
          Peripherals
        </option>
      </select>
    </div>
    <div class="field field--third">
      <label for="category">Type</label>
      <select id="category" name="category">
        <option value="">Any</option>
        <?php
        // $nodes, not $categories: nothing has ever passed a $categories variable
        // to this template, so the loop ran zero times and the select offered only
        // "Any". $nodes is the same labelled tree the entry form uses, and the
        // label carries the path - six branches called "Adapters" are
        // indistinguishable without one.
        $catUsed = $inUse['categories'] ?? null;
        foreach (($nodes ?? []) as $c):
            if (($c['domain'] ?? '') !== 'hardware') { continue; }
            if ($catUsed !== null
                && !in_array($c['slug'], $catUsed, true)
                && ($active['category'] ?? '') !== $c['slug']) { continue; } ?>
          <option value="<?= e($c['slug']) ?>" <?= ($active['category'] ?? '') === $c['slug'] ? 'selected' : '' ?>>
            <?= e($c['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="formactions">
    <button class="btn btn--accent" type="submit">Filter</button>
    <?php if ($active !== []): ?>
      <a class="btn" href="<?= e(url('/hardware')) ?>">Clear</a>
    <?php endif; ?>
    <span class="hint"><?= (int) $total ?> <?= (int) $total === 1 ? 'item' : 'items' ?></span>
  </div>
</form>

<?php if ($items === []): ?>
  <section class="panel">
    <h2 class="panel__title">Nothing here yet</h2>
    <p class="lede" style="margin:0">
      <?= $active === [] ? 'Add a machine, a card or a spare and it will appear here.'
                          : 'Nothing matches those filters.' ?>
    </p>
  </section>
<?php elseif (($view ?? 'table') === 'cards'): ?>
  <?php
  // The same rows, drawn the way software's browser draws them. Both listings
  // read v_items, so this is a different partial over identical data rather than
  // a second query - and a machine with photographs of its board is worth seeing
  // as a picture, which a table cannot do.
  ?>
  <div class="grid">
    <?php foreach ($items as $it) partial('card', ['it' => $it]); ?>
  </div>
<?php else: ?>
  <table class="table">
    <thead>
      <tr>
        <?php
        // Interface and Library are gone.
        //
        // Interface was a dash on nearly every row: it is set on a card that says
        // which slot it plugs into, and a column that is empty for every machine
        // and most cards costs width without paying for it. It is still on the
        // entry's own page, where there is room to say it properly, and still
        // searchable.
        //
        // Library was the same value on every row by construction. The switcher in
        // the header has no "everything" option - Collection is that view - so this
        // list is always one library, and a column that cannot vary is decoration.
        ?>
        <?php // "Device", not "Machine": the list holds cards and spares as well. ?>
        <?php
        // Sortable, both ways. The Kind column is new: a machine and a card look
        // alike in a list of names, and which one it is decides what the entry
        // even means.
        ?>
        <?php partial('sort_header', ['label' => 'Device',    'key' => 'title',     'sort' => $sort]); ?>
        <?php partial('sort_header', ['label' => 'Kind',      'key' => 'kind',      'sort' => $sort]); ?>
        <?php partial('sort_header', ['label' => 'Platform',  'key' => 'platform',  'sort' => $sort]); ?>
        <?php partial('sort_header', ['label' => 'Type',      'key' => 'type',      'sort' => $sort]); ?>
        <?php partial('sort_header', ['label' => 'Company',   'key' => 'company',   'sort' => $sort]); ?>
        <?php partial('sort_header', ['label' => 'Year',      'key' => 'year',      'sort' => $sort]); ?>
        <?php partial('sort_header', ['label' => 'Condition', 'key' => 'condition', 'sort' => $sort]); ?>
        <th>State</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php
      // Platform-first browsing, as a view rather than a structure.
      //
      // Sorting by platform already existed; this adds the headings that make it read
      // as a hierarchy - Amiga, then what is filed under it. Which is the chain
      // without duplicating a taxonomy per machine: one "Memory" row, arranged for
      // reading. Only shown when that sort is chosen, and only within the current
      // page, because the sort is what decides the order and pagination is what
      // decides the page.
      $groupByPlatform = ($sort ?? '') === 'platform';
      $lastPlatform    = null;
      ?>
      <?php foreach ($items as $r):
        $hw = one('SELECT * FROM item_hardware WHERE item_id = ?', [(int) $r['id']]) ?? []; ?>
        <?php if ($groupByPlatform && (string) $r['platform_name'] !== (string) $lastPlatform):
          $lastPlatform = (string) $r['platform_name']; ?>
          <tr class="grouphead">
            <td colspan="9">
              <span class="spine" style="background:<?= e((string) ($r['platform_color'] ?? '#cba6f7')) ?>"></span>
              <strong><?= e((string) $r['platform_name']) ?></strong>
            </td>
          </tr>
        <?php endif; ?>
        <tr>
          <td>
            <a href="<?= e(url('/items/' . $r['id'])) ?>"><strong><?= e($r['title']) ?></strong></a>
            <?php if (!empty($hw['board_revision'])): ?>
              <span class="chip"><?= e($hw['board_revision']) ?></span>
            <?php endif; ?>
            <?php if (!empty($hw['serial_number'])): ?>
              <br><span class="hint mono"><?= e($hw['serial_number']) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php
            // The word, not the enum: "peripheral" is a column value, and
            // "other" means a branch nobody has said either way about.
            $role = item_kind_label($r);
            ?>
            <span class="chip"><?= e($role === '' ? 'unfiled' : $role) ?></span>
          </td>
          <td><?= e($r['platform_name'] ?? '') ?></td>
          <td><span class="hint"><?= e(category_breadcrumb((int) $r['category_id'])) ?></span></td>
          <?php
          // The cells were in a different order from the headers above them, so
          // Edit sat under "State" and the working state sat under the empty
          // column at the end. Both were right on their own and wrong together,
          // which is the sort of thing that reads as a broken table.
          ?>
          <td><?= e((string) ($r['developer_name'] ?? '—')) ?></td>
          <td class="mono"><?= $r['release_year'] ? (int) $r['release_year'] : '—' ?></td>
          <td>
            <?php $grade = (string) ($r['condition_grade'] ?? ''); ?>
            <span class="hint"><?= $grade === '' ? '—' : e(condition_label($grade)) ?></span>
          </td>
          <td>
            <?php $state = (string) ($hw['working_state'] ?? 'untested');
            $colour = ['working' => 'var(--good)', 'not_working' => 'var(--bad)',
                       'intermittent' => 'var(--warn)', 'restored' => 'var(--good)'][$state] ?? 'var(--dim)'; ?>
            <span style="color:<?= $colour ?>"><?= e(str_replace('_', ' ', $state)) ?></span>
          </td>
          <td class="rowedit">
            <?php
            // Only where this person may actually change it.
            //
            // A read-only member saw an Edit button on every row, and following
            // it reached a refusal - a control that exists only to say no. The
            // check is per entry rather than per library, because a contributor
            // may edit what they added and not what somebody else did.
            ?>
            <?php if (can_write_item($r)): ?>
            <?php
            // Straight into editing, and back here afterwards. The entry page is a fine
            // place to read one thing; it is a detour when you are working down a list
            // fixing several. ?return= is what Save and Cancel follow.
            ?>
            <a class="btn btn--sm" href="<?= e(url('/items/' . $r['id'] . '/edit',
                 ['return' => $_SERVER['REQUEST_URI'] ?? '/hardware'])) ?>">Edit</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($pages > 1): ?>
    <nav class="pager">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a class="<?= $i === $page ? 'on' : '' ?>"
           href="<?= e(url('/hardware', array_merge($active, ['page' => $i]))) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>
