<?php
/** @var array $nodes @var array|null $selected @var array $platforms */
/** @var array $counts @var array $available @var array $scopes @var array $inherited */
$scopedBy = [];
foreach ($scopes as $s) { $scopedBy[(int) $s['provider_id']] = $s; }
$inheritedNames = array_column($inherited, 'name', 'id');
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Manage</span>
    <h1>Categories</h1>
  </div>
</div>

<?php partial('manage_nav', ['current' => 'tree']); ?>

<div class="cols cols--2">

  <section class="panel" style="margin:0">
    <h2 class="panel__title">The tree</h2>
    <?php
    // One tree, not two.
    //
    // Hardware kinds and software kinds share this table and this editor on purpose:
    // the whole point is that you can hang a subcategory off anything - Network
    // adapters, Applications, Games - by the same act, and the machinery beneath
    // (paths, counts, delete guards, metadata scopes) does not care which side a node
    // is on. Splitting the list into two sections implied they were separate
    // taxonomies that happened to share a screen, which is the opposite of the idea.
    //
    // Roots are labelled "platform" rather than by domain. The domain is still what
    // decides where a kind may be filed, but a root is a machine and holds both sides:
    // Hardware and Software are the rows beneath it.
    ?>
    <?php
    // Collapsible, so the list reads as six roots rather than seventy-eight rows and
    // stays readable however deep it grows.
    //
    // Which nodes have children, and the selected node's ancestors, are worked out
    // here rather than in the browser: the script only toggles a class, so with no
    // JavaScript at all every row is simply visible - the old behaviour - instead of
    // a tree that cannot be opened.
    $hasKids = [];
    foreach ($nodes as $n) {
        if (($n['parent_id'] ?? null) !== null) {
            $hasKids[(int) $n['parent_id']] = true;
        }
    }
    // The path of the selected node, so opening ?node=X does not hide X.
    $openIds = [];
    if ($selected !== null) {
        foreach (array_filter(explode('/', (string) ($selected['path'] ?? '')), 'strlen') as $pid) {
            $openIds[(int) $pid] = true;
        }
    }
    ?>
    <?php
    // Narrowing, not searching: the rows stay put and the ones that do not match are
    // hidden. Nothing is posted, so a filter cannot lose an arrangement you have not
    // saved - and with the filter cleared the tree is exactly as it was.
    $classNames = ['computer' => 'Computers', 'console' => 'Consoles', 'handheld' => 'Handhelds'];
    $makers = [];
    foreach (($filterPlatforms ?? []) as $pf) {
        if (!empty($pf['maker'])) { $makers[(string) $pf['maker']] = true; }
    }
    ksort($makers);
    ?>
    <div class="treefilter" data-tree-filter>
      <input type="search" data-filter-text placeholder="Find a kind or a machine"
             aria-label="Filter the tree">
      <select data-filter-class aria-label="Kind of machine">
        <option value="">Any machine</option>
        <?php foreach ($classNames as $k => $label): ?>
          <option value="<?= e($k) ?>"><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <select data-filter-maker aria-label="Manufacturer">
        <option value="">Any maker</option>
        <?php foreach (array_keys($makers) as $m): ?>
          <option value="<?= e($m) ?>"><?= e($m) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn--sm" type="button" data-filter-clear>Clear</button>
      <?php
      // Open and shut everything. Sixty-three roots is a lot of chevrons to click
      // through when you are looking for one thing and do not know where it is.
      ?>
      <button class="btn btn--sm" type="button" data-tree-expand title="Open every branch">Expand all</button>
      <button class="btn btn--sm" type="button" data-tree-collapse title="Shut every branch">Collapse all</button>
      <span class="hint" data-filter-count></span>
    </div>

    <?php
    // Each root carries what it can be filtered on, so the script never has to ask the
    // server anything.
    $pfById = [];
    foreach (($filterPlatforms ?? []) as $pf) { $pfById[(int) $pf['id']] = $pf; }
    ?>
    <ul class="treelist" data-tree>
      <?php
      // No branch picker any more.
      //
      // It existed for the three "which branch" controls - move, copy, and the
      // Add panel - and all three have gone: the + on a row makes a branch where
      // it stands. The list was 3,672 options and about 0.45 MB of the page, kept
      // for controls that are no longer on it.
      ?>

      <?php
      // Only what is open, plus one level.
      //
      // Every node used to be written out and the closed ones hidden with a
      // class: 3,672 rows and six megabytes of HTML to show sixty-three lines.
      // The server assembles that in 70 ms; it is the browser that has to parse
      // and lay out all of it before anything appears, which is what the long
      // empty page was.
      //
      // One level *past* the open path rather than exactly the open path, so the
      // first click of a toggle still expands instantly with no round trip.
      // Deeper than that, clicking a row's name navigates with ?node=, which
      // opens the whole path on the server - and that is also what keeps this
      // working with JavaScript off, the property the original code was
      // protecting by rendering everything.
      $rendered = 0;
      foreach ($nodes as $n):
        // Ancestors, from the path, which already ends with the node itself.
        $line = array_map('intval', array_values(array_filter(
            explode('/', (string) ($n['path'] ?? '')), 'strlen')));
        array_pop($line);
        $parentId = $n['parent_id'] === null ? null : (int) $n['parent_id'];

        // Everything above the parent must be open. The parent itself may be
        // closed - that is the one level of lookahead the toggle needs.
        $skip = false;
        foreach ($line as $ancestorId) {
            if ($ancestorId !== $parentId && empty($openIds[$ancestorId])) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }
        $rendered++;
        $id = (int) $n['id'];
        $held = $counts[$id] ?? 0;
        $indent = (int) $n['depth'] * 1.2;
      ?>
        <?php
        $parent = $n['parent_id'] === null ? 0 : (int) $n['parent_id'];
        $kids   = !empty($hasKids[$id]);
        // Open if it is on the selected node's path; a root with no selection starts
        // closed, which is the whole point.
        $open   = !empty($openIds[$id]);
        ?>
        <?php
        $pf  = $pfById[(int) ($n['platform_id'] ?? 0)] ?? null;
        ?>
        <li class="treerow" id="node-<?= $id ?>" data-node="<?= $id ?>" data-parent="<?= $parent ?>"
            data-class="<?= e((string) ($pf['machine_class'] ?? '')) ?>"
            data-maker="<?= e((string) ($pf['maker'] ?? '')) ?>"
            data-name="<?= e(mb_strtolower((string) $n['name'])) ?>"
            data-depth="<?= (int) $n['depth'] ?>"
            <?= $kids ? 'data-haskids' : '' ?> <?= $open ? 'data-open' : '' ?>
            style="padding-left:<?= $indent ?>rem">
          <?php if ($kids): ?>
            <button class="treetoggle" type="button" data-toggle-node="<?= $id ?>"
                    aria-expanded="<?= $open ? 'true' : 'false' ?>"
                    title="Show or hide what is inside">&rsaquo;</button>
          <?php else: ?>
            <span class="treetoggle treetoggle--leaf" aria-hidden="true"></span>
          <?php endif; ?>
          <a class="<?= $selected && (int) $selected['id'] === $id ? 'on' : '' ?>"
             href="<?= e(url('/manage/tree', ['node' => $id])) ?>">
            <?php if ((int) $n['depth'] === 0): ?><strong><?= e($n['name']) ?></strong>
            <?php else: ?><?= e($n['name']) ?><?php endif; ?>
            <?php if ((int) $n['depth'] === 0): ?>
              <?php
              // "platform", not the domain.
              //
              // A root is a machine, and it holds both sides of the shop - Hardware and
              // Software hang off it. Labelling it "hardware" because the row happens to
              // carry that domain said something untrue about a branch with games in it.
              ?>
              <span class="hint">platform</span>
            <?php endif; ?>
            <?php
            // No "one machine" chip. It marked a kind pinned to a single platform, which
            // was worth saying when the taxonomy was shared - every node in a
            // platform-rooted tree carries a platform now, so the chip appeared on all of
            // them and the position above already says which machine it is.
            ?>
          </a>
          <?php if ($held > 0): ?><span class="hint"><?= $held ?></span><?php endif; ?>
          <?php
          // The controls that belong on the row: which node, and where it sits among
          // its siblings. Everything that needs a form of its own - renaming, moving
          // to another parent, metadata sources - stays on the right, because those
          // ask a question rather than just doing something.
          //
          // Up and down rather than a number: nobody wants to work out that Storage
          // needs to be 35 to sit between 30 and 40.
          ?>
          <span class="treerow__acts">
            <?php foreach ([['up', '&uarr;', 'Move up'], ['down', '&darr;', 'Move down']] as [$act, $glyph, $label]): ?>
              <?php
              // A plain button, like the specification rows use. Nothing is submitted
              // and nothing reloads: the row moves in the page and the Save bar below
              // appears. That was the whole cause of the reload - these were submit
              // buttons in forms, and any script that failed to intercept handed the
              // browser a full POST.
              ?>
              <button class="btn btn--sm" type="button" data-move="<?= $act ?>"
                      data-node-id="<?= $id ?>" title="<?= e($label) ?>"><?= $glyph ?></button>
            <?php endforeach; ?>
            <?php
            // Add a child here. Points at the form below with this node preselected,
            // rather than a second add form per row: one place that creates nodes, and
            // the row just says where.
            ?>
            <?php
              // One press makes the branch.
              //
              // It used to fill in a form further down the page, which meant
              // scrolling to a panel, typing a name and pressing Add - three
              // steps to do the thing the button already said. It creates a
              // branch under this one with a placeholder name and opens it, so
              // the next thing on screen is the field you were going to type in
              // anyway.
              ?>
              <form method="post" action="<?= e(url('/manage/tree')) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="quick_add">
                <input type="hidden" name="parent_id" value="<?= $id ?>">
                <button class="treebtn" type="submit" title="Add a branch under this one">+</button>
              </form>
            <a class="btn btn--sm" href="<?= e(url('/manage/tree', ['node' => $id])) ?>">Edit</a>
            <form method="post" action="<?= e(url('/manage/tree')) ?>"
                  data-confirm="Delete <?= e($n['name']) ?>?<?= $held > 0 ? ' It still holds ' . (int) $held . ' entries.' : '' ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $id ?>">
              <button class="btn btn--sm btn--danger" type="submit" title="Delete">&times;</button>
            </form>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php
    // Appears only once something has moved. Arranging is free and local; committing is
    // one deliberate act, which is what stops the page reloading under you mid-shuffle.
    ?>
    <form method="post" action="<?= e(url('/manage/tree')) ?>" data-order-form hidden
          style="margin-top:.8rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reorder">
      <div data-order-fields></div>
      <button class="btn btn--accent btn--sm" type="submit">Save the new order</button>
      <button class="btn btn--sm" type="button" data-order-cancel>Undo</button>
    </form>


  </section>

  <div style="margin:0">
    <?php if ($selected === null): ?>
      <section class="panel" style="margin:0 0 1rem">
        <h2 class="panel__title">Nothing selected</h2>
        <p class="lede" style="margin:0">
          Pick a node to rename it, move it, copy the branch to another machine,
          or decide which metadata sources answer for it.
        </p>
      </section>
    <?php else: $sid = (int) $selected['id']; ?>

      <section class="panel" style="margin:0">
        <h2 class="panel__title"><?= e(category_breadcrumb($sid)) ?></h2>
        <form method="post" action="<?= e(url('/manage/tree')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="rename">
          <input type="hidden" name="id" value="<?= $sid ?>">
          <div class="formgrid">
            <div class="field field--half">
              <label for="s-name">Name</label>
              <input id="s-name" name="name" type="text" value="<?= e($selected['name']) ?>" required>
            </div>
            <?php
            // A machine has no kind of its own.
            //
            // It is a place, not a kind of thing: an Amiga branch holds machines
            // and peripherals and games and applications, and the branches under
            // it are what say which. Offering a kind here made the tree claim
            // something about everything filed anywhere beneath it.
            ?>
            <?php
            // Nothing at all on a machine, not even a line saying why.
            //
            // A field somebody cannot use is still a field: it takes a label, a
            // row of the form and a moment to read before being dismissed. The
            // absence says the same thing faster - there is no kind to set here
            // because a machine is a place, not a kind.
            ?>
            <?php if ($selected['parent_id'] !== null): ?>
            <div class="field field--half">
              <?php
              // What goes here, said rather than guessed.
              //
              // Machines and peripherals were already distinguishable; software
              // was not, and a game was decided by whether some branch above
              // happened to be called "Games". That works on the shipped tree and
              // on nothing anybody builds themselves - so somebody starting a
              // library from scratch had no way to say "this holds games", and
              // the browsers had no way to know.
              // All four, whatever side the branch is on today.
              //
              // The list used to follow the branch's own domain, and a branch
              // made with + inherits its parent's - and every root is a machine,
              // so a tree built from scratch was hardware all the way down with
              // no way to say otherwise. The kind is the thing somebody knows;
              // the domain follows from it.
              // Named by side and kind, because "Machines" and "Games" alone
              // leave somebody to remember which half of the shop they are in.
              $kinds = [
                  'machine'     => 'Hardware — Machines',
                  'peripheral'  => 'Hardware — Peripherals',
                  'application' => 'Software — Applications',
                  'game'        => 'Software — Games',
              ];
              ?>
              <label for="s-role">Kind</label>
              <select id="s-role" name="role">
                <?php
                // What it inherits, said in the blank option.
                //
                // A branch under Games is a kind of game whether or not anybody
                // set it, so blank is not "nothing" - it is "whatever this is
                // under". The dash alone left somebody to guess which, and
                // guessing wrongly means declaring a kind that was already true.
                $inheritedKind = $selected['parent_id'] === null
                    ? null : category_effective_role((int) $selected['parent_id']);
                $kindLabels = ['machine' => 'Hardware — Machines',
                               'peripheral' => 'Hardware — Peripherals',
                               'application' => 'Software — Applications',
                               'game' => 'Software — Games'];
                ?>
                <option value="other" <?= (string) $selected['role'] === 'other' ? 'selected' : '' ?>>
                  <?= $inheritedKind === null
                        ? '—'
                        : e('Inherited: ' . ($kindLabels[$inheritedKind] ?? $inheritedKind)) ?>
                </option>
                <?php // Machines and cards, then games and programs: the pairs
                      // people think in, and picking one moves the branch to that
                      // side of the shop. ?>
                <?php foreach ($kinds as $value => $label): ?>
                  <option value="<?= e($value) ?>" <?= (string) $selected['role'] === $value ? 'selected' : '' ?>>
                    <?= e($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
          </div>
          <div class="formactions"><button class="btn btn--accent" type="submit">Save</button></div>
        </form>
      </section>

      <section class="panel" style="margin-top:1rem">
        <h2 class="panel__title">Models here</h2>
        <?php
        // What an entry filed at this node can use. Attached at the node itself, or
        // inherited from a branch above it - the same rule the agents below follow,
        // which is why a model attached to Expansions is offered under Memory without
        // anybody attaching it twice.
        $ownModels = array_values(array_filter($nodeModels ?? [],
            fn($m) => (int) ($m['category_id'] ?? 0) === (int) $selected['id']));
        $viaParent = array_values(array_filter($nodeModels ?? [],
            fn($m) => (int) ($m['category_id'] ?? 0) !== (int) $selected['id']));
        ?>
        <?php if (($nodeModels ?? []) === []): ?>
          <p class="lede" style="font-size:.9rem;margin:0">
            Nothing yet. A model attached here — or to any branch above it — is offered
            when you file something at this node. Add one under
            <a href="<?= e(url('/manage/models')) ?>">Machine models</a> or
            <a href="<?= e(url('/manage/parts')) ?>">Peripheral models</a>.
          </p>
        <?php else: ?>
          <?php if ($ownModels !== []): ?>
            <p class="label" style="margin:0 0 .3rem">Attached here</p>
            <div class="chips" style="margin-bottom:.7rem">
              <?php foreach ($ownModels as $m): ?>
                <span class="chip"><?= e((string) $m['name']) ?><?php if (!empty($m['platform_name'])): ?>
                  <span class="hint"> · <?= e((string) $m['platform_name']) ?></span>
                <?php endif; ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($viaParent !== []): ?>
            <p class="label" style="margin:0 0 .3rem">Inherited from above</p>
            <div class="chips">
              <?php foreach ($viaParent as $m): ?>
                <span class="chip"><?= e((string) $m['name']) ?>
                  <span class="hint"> · from <?= e((string) ($m['category_name'] ?? 'a branch above')) ?></span>
                </span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </section>

      <section class="panel">
        <h2 class="panel__title">Metadata agents</h2>
        <?php
        // No paragraph. What a source set here does is visible in the table
        // under it - the rows say "on", "off" or "inherits" - and half of what
        // this explained was about the machine filter, which has gone.
        ?>

        <?php
        // No "showing sources for" machine filter.
        //
        // A branch belongs to a machine already - every root is a platform - so
        // filtering the sources panel by machine asked a question the branch had
        // already answered, and the answer changed what the list appeared to say
        // without changing anything.
        ?>

        <?php
        // No list of what is in effect: the names below are green when they are.
        //
        // The sentence repeated the table under it in prose, and the two had to be
        // read against each other to work out which row was which. A name that
        // says its own state needs no index.
        $inEffect = array_flip(array_map('intval', array_keys($inheritedNames)));
        ?>
        <?php if ($inherited === []): ?>
          <p class="hint">Nothing answers for this branch yet.</p>
        <?php endif; ?>

        <?php if ($available === []): ?>
          <p class="hint" style="color:var(--warn)">
            No configured source can serve this part of the tree. Add one under
            Instance settings → Metadata agents, or check it covers this machine.
          </p>
        <?php else: ?>
          <table class="table">
            <thead><tr><th>Source</th><th>Here</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($available as $prov): $pid = (int) $prov['id']; $row = $scopedBy[$pid] ?? null; ?>
                <tr>
                  <td>
                    <?php
                    // Three states, three colours, because "answers here" hides a
                    // distinction worth seeing: a source turned on at this branch
                    // is somebody's decision about this branch, and one arriving
                    // from above is a decision about a whole subtree that this
                    // branch happens to be in. Turning the first off changes one
                    // thing; turning the second off carves an exception.
                    //
                    //   green   switched on here
                    //   yellow  inherited from a branch above
                    //   grey    not answering
                    $setHere   = $row !== null && (int) $row['enabled'] === 1;
                    $answering = isset($inEffect[$pid]);
                    $colour    = $setHere ? 'var(--good)'
                               : ($answering ? 'var(--warn)' : 'var(--faint)');
                    ?>
                    <span style="color:<?= $colour ?><?= $answering ? ';font-weight:600' : '' ?>">
                      <?= e($prov['name']) ?>
                    </span>
                    <?php
                    // Said plainly, because a source attached here and switched
                    // off instance-wide will never fetch anything - and the row
                    // above says "on", which is about this branch and not about
                    // the source. Two different switches, and without this the
                    // screen showed one of them.
                    ?>
                    <?php if ((int) ($prov['is_enabled'] ?? 1) !== 1): ?>
                      <span class="chip">switched off</span>
                    <?php endif; ?>
                    <br><span class="hint"><?= e($prov['type']) ?></span>
                    <?php if ((int) ($prov['is_enabled'] ?? 1) !== 1): ?>
                      <br><span class="hint">
                        You can file it here now; it fetches nothing until it is
                        turned on under
                        <a href="<?= e(url('/manage/metadata')) ?>">Metadata agents</a>.
                      </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($setHere): ?>
                      <span class="hint">on here</span>
                    <?php elseif ($row !== null): ?>
                      <?php // An explicit off, which is not the same as never set. ?>
                      <span class="hint">off here</span>
                    <?php elseif ($answering): ?>
                      <span class="hint">from above</span>
                    <?php else: ?>
                      <span class="hint">off</span>
                    <?php endif; ?>
                  </td>
                  <td style="text-align:right">
                    <?php
                    // One button: the one that changes something.
                    //
                    // On and Off side by side made you read the state column to
                    // know which had already happened. Offering only the opposite
                    // of what is true says the state and the action in one place.
                    $answersHere = isset($inEffect[$pid]);
                    ?>
                    <form method="post" action="<?= e(url('/manage/tree')) ?>" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="scope">
                      <input type="hidden" name="id" value="<?= $sid ?>">
                      <input type="hidden" name="provider_id" value="<?= $pid ?>">
                      <input type="hidden" name="platform_id" value="<?= (int) ($scopePlatform ?? 0) ?>">
                      <input type="hidden" name="enabled" value="<?= $answersHere ? '0' : '1' ?>">
                      <button class="btn btn--sm" type="submit"><?= $answersHere ? 'Off' : 'On' ?></button>
                    </form>
                    <?php if ($row !== null): ?>
                      <form method="post" action="<?= e(url('/manage/tree')) ?>" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="unscope">
                        <input type="hidden" name="id" value="<?= $sid ?>">
                        <input type="hidden" name="provider_id" value="<?= $pid ?>">
                        <button class="btn btn--sm" type="submit">Inherit</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </section>

      <?php
      // No Move or copy panel for the moment. Moving a branch and copying a
      // subtree are real operations with real consequences for everything filed
      // under them, and they were sitting one press away from a rename.
      ?>

      <section class="panel" style="margin-top:1rem;border-left:4px solid var(--bad)">
        <h2 class="panel__title">Delete</h2>
        <?php
        // A machine's own branch is removed with the machine, under Platforms.
        //
        // The handler refuses it either way - that is the rule - but a button
        // whose only outcome is a refusal is a worse answer than a sentence
        // saying where the thing actually lives.
        ?>
        <?php if ($selected['parent_id'] === null): ?>
          <p style="margin-top:0;font-size:.9rem;color:var(--dim)">
            This is <?= e($selected['name']) ?>'s own branch. Remove the machine
            under <a href="<?= e(url('/manage/platforms')) ?>">Platforms</a> and its
            branch goes with it.
          </p>
        <?php else: ?>
        <p style="margin-top:0;font-size:.9rem;color:var(--dim)">
          Only once the branch holds nothing. Deleting should never be a way to
          lose things by accident.
        </p>
        <form method="post" action="<?= e(url('/manage/tree')) ?>" data-confirm="Delete <?= e($selected['name']) ?> and everything beneath it?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $sid ?>">
          <button class="btn btn--danger btn--sm" type="submit">Delete this branch</button>
        </form>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php
    // Adding sits in this column rather than under the tree: the tree runs to
    // sixty-three machines, so a form beneath it was a scroll away from the thing
    // it acts on, while this column had room going spare.
    //
    // Below the selected node rather than above it. Choosing a row is a request to
    // work on that row, and the answer to it was arriving under a form about
    // something else - so renaming a node meant scrolling past the box for adding
    // a different one, every time. With nothing selected there is nothing to put
    // first and this is the top of the column, which is why the margin is asked
    // rather than fixed.
    ?>
    <?php
      // No Add panel. The + on a row does it, which is where somebody already
      // is when they decide a branch needs a child.
      ?>
  </div>
</div>
