<?php
declare(strict_types=1);

/**
 * One definition drives the whole manage screen for each lookup table.
 * fields: name => [label, type, extra]
 */
function taxonomy_defs(): array
{
    return [
        'platforms' => [
            'table'    => 'platforms',
            'title'    => 'Libraries',
            'singular' => 'library',
            'blurb'    => 'One library per machine or format. Every entry in the catalogue belongs to exactly one.',
            'fields'   => [
                'name'            => ['Name', 'text', true],
                // No 'manufacturer' here: it is not a column on platforms, it is
                // an alias over vendor_id. The generic screen has no vendor
                // picker, and /manage/platforms has its own handler that does -
                // this descriptor is only reached if that route is ever removed.
                'year_introduced' => ['Introduced', 'number', false],
                'accent_color'    => ['Shelf colour', 'color', false],
                'description'     => ['Description', 'textarea', false],
            ],
        ],
        'companies' => [
            'table'    => 'companies',
            'title'    => 'Companies',
            'singular' => 'company',
            // Blank rather than deleted: every taxonomy type carries a blurb and the
            // template skips an empty one, so this is how a page says it does not want it.
            'blurb'    => '',
            'fields'   => [
                'name'          => ['Name', 'text', true],
                'makes'         => ['What they made', 'makes', false],
                'logo'          => ['Logo', 'image', false],
                'country'       => ['Country', 'text', false],
                'founded_year'  => ['Founded', 'number', false],
                'defunct_year'  => ['Closed', 'number', false],
                'website'       => ['Website', 'url', false],
                'wikipedia_url' => ['Wikipedia', 'url', false],
                'notes'         => ['Notes', 'textarea', false],
            ],
        ],
        'tags' => [
            'table'    => 'tags',
            'title'    => 'Tags',
            'singular' => 'tag',
            'blurb'    => 'Free-form labels for anything the fixed fields do not cover.',
            'fields'   => [
                'name' => ['Name', 'text', true],
            ],
        ],
    ];
}

function taxonomy_def(string $type): array
{
    $defs = taxonomy_defs();
    if (!isset($defs[$type])) {
        not_found('No such list.');
    }
    return $defs[$type];
}

function taxonomy_index(string $type): void
{
    require_manage();
    $def   = taxonomy_def($type);
    $table = $def['table'];

    $countCol = match ($type) {
        'platforms'  => 'platform_id',
        'categories' => 'category_id',
        // Nothing extra to check: a genre is a category, and entries name it in
        // category_id like anything else. genre_id is gone.
        default      => null,
    };

    if ($countCol !== null) {
        $rows = all("SELECT t.*, (SELECT COUNT(*) FROM items i WHERE i.$countCol = t.id) AS usage_count
                     FROM `$table` t ORDER BY t.name");
    } elseif ($type === 'companies') {
        // This library's, and never the templates.
        //
        // Unscoped it listed the template set plus every library's copy of it, so one
        // studio appeared three times over on an instance with two shelves - 447 rows
        // where there are 149 companies. Companies became per library with the vendors
        // merge; this query did not follow.
        $lib   = working_library();
        $libId = $lib === null ? 0 : (int) $lib['id'];
        $rows  = all('SELECT t.*, (SELECT COUNT(*) FROM items i WHERE i.developer_id = t.id OR i.publisher_id = t.id) AS usage_count
                      FROM companies t WHERE t.library_id = ? ORDER BY t.name', [$libId]);
    } else {
        $rows = all('SELECT t.*, (SELECT COUNT(*) FROM item_tags it WHERE it.tag_id = t.id) AS usage_count
                     FROM tags t ORDER BY t.name');
    }

    $editing = null;
    $editId  = input_int('edit');
    if ($editId !== null) {
        $editing = one("SELECT * FROM `$table` WHERE id = ?", [$editId]);
    }

    render('taxonomy/index', [
        'pageTitle'  => $def['title'],
        'type'       => $type,
        'def'        => $def,
        'rows'       => $rows,
        'editing'    => $editing,
        'categories' => all_categories(),
    ]);
}

function taxonomy_save(string $type): void
{
    require_manage();
    csrf_verify();

    $def   = taxonomy_def($type);
    $table = $def['table'];
    $id    = input_int('id');

    if (input('action') === 'delete' && $id !== null) {
        try {
            delete_row($table, $id);
            flash('ok', ucfirst($def['singular']) . ' deleted.');
        } catch (PDOException $e) {
            // Which entries, and whether they are still on the shelf.
            //
            // A row in the trash keeps its foreign keys, so a deleted entry goes
            // on blocking the company it pointed at - and the old message sent
            // somebody looking through a catalogue where that entry no longer
            // appears. Saying "in the trash" is the difference between a puzzle
            // and a job.
            $live = 0;
            $binned = 0;
            if ($table === 'companies') {
                $live = (int) scalar('SELECT COUNT(*) FROM items
                                       WHERE (developer_id = ? OR publisher_id = ?)
                                         AND deleted_at IS NULL', [$id, $id]);
                $binned = (int) scalar('SELECT COUNT(*) FROM items
                                         WHERE (developer_id = ? OR publisher_id = ?)
                                           AND deleted_at IS NOT NULL', [$id, $id]);
            }
            flash('error', match (true) {
                $live > 0 && $binned > 0 => sprintf(
                    '%d entr%s still %s this, and %d more in the trash. Reassign the first, '
                    . 'then empty the trash.', $live, $live === 1 ? 'y' : 'ies',
                    $live === 1 ? 'uses' : 'use', $binned),
                $binned > 0 => sprintf(
                    '%d deleted entr%s still points at this. It is in the trash, which keeps '
                    . 'what it referred to - empty the trash and this can go.',
                    $binned, $binned === 1 ? 'y' : 'ies'),
                default => 'Still in use by catalogue entries, so it was kept. Reassign those '
                         . 'entries first.',
            });
        }
        redirect('/manage/' . $type);
    }

    $data = [];
    foreach ($def['fields'] as $field => [$label, $ftype, $required]) {
        // Files arrive in $_FILES and are handled after the row exists, since
        // the stored filename needs the row id.
        if ($ftype === 'image') {
            continue;
        }

        // A set of ticks, stored as the SET column it is. Unticking everything is a
        // real answer - a company that made neither is a publisher of nothing, which is
        // odd but not the form's business to refuse - so an absent array means empty
        // rather than "leave it alone".
        if ($ftype === 'makes') {
            $picked = $_POST[$field] ?? [];
            $picked = is_array($picked)
                ? array_values(array_intersect(['hardware', 'software'], $picked))
                : [];
            $data[$field] = implode(',', $picked);
            continue;
        }

        $value = $_POST[$field] ?? null;
        if (is_array($value)) {
            $value = null;
        }
        $value = nullify($value);
        if ($required && $value === null) {
            flash('error', "$label is required.");
            redirect('/manage/' . $type);
        }
        if ($ftype === 'number') {
            $value = $value === null ? null : (int) $value;
        }
        if (str_starts_with($ftype, 'select:')) {
            $value = $value === null ? null : (int) $value;
        }
        if ($ftype === 'url' && $value !== null && !filter_var($value, FILTER_VALIDATE_URL)) {
            flash('error', "$label must be a full URL starting with https://.");
            redirect('/manage/' . $type);
        }
        $data[$field] = $value;
    }

    if ($type === 'platforms' && ($data['accent_color'] ?? null) === null) {
        $data['accent_color'] = '#cba6f7';
    }
    $makingPlatform = $type === 'platforms' && $id === null;
    if (array_key_exists('sort_order', $data) && $data['sort_order'] === null) {
        $data['sort_order'] = 0;
    }

    if ($id !== null) {
        $data['slug'] = unique_slug($table, slugify((string) $data['name']), $id);
        update_row($table, $id, $data);
        flash('ok', $data['name'] . ' updated.');
    } else {
        // The library it belongs to.
        //
        // Left out, the row was created with library_id NULL - the template
        // scope - while the list above reads only the working library's own
        // rows. So it was saved, the flash said so, and it appeared nowhere:
        // filed on the shelf of templates that new libraries are copied from
        // rather than the shelf somebody was looking at.
        //
        // Only for tables that have the column. Platforms and the rest are
        // instance-wide and have no library of their own.
        if (table_has_column($table, 'library_id') && !array_key_exists('library_id', $data)) {
            $lib = working_library();
            if ($lib === null) {
                flash('error', 'Choose a library to work in first — this belongs to one.');
                redirect('/manage/' . $type);
            }
            $data['library_id'] = (int) $lib['id'];
        }

        $data['slug'] = unique_slug($table, slugify((string) $data['name']));
        $id = insert_row($table, $data);
        flash('ok', $data['name'] . ' added.');
    }

    // The branch that goes with a new machine.
    //
    // The two lists are the same fact seen twice - a machine, and the place its
    // things are filed - so making one and not the other leaves a platform
    // nothing can be filed under. Only on creation: renaming a platform later
    // should not grow a second branch.
    if ($makingPlatform) {
        platform_ensure_root((int) $id, (int) ($data['library_id'] ?? 0), (string) $data['name']);
        flash('ok', $data['name'] . ' added, with a branch of its own in the category tree.');
    }

    if ($type === 'companies') {
        if (input('remove_logo') === '1') {
            delete_company_logo($id);
            flash('ok', 'Logo removed.');
        }
        [, $logoError] = store_company_logo($id, 'logo');
        if ($logoError !== null) {
            flash('error', $logoError);
        }
    }

    redirect('/manage/' . $type);
}

// --- Public browse pages ----------------------------------------------------

/**
 * Browse by machine.
 *
 * This was called libraries_index() from when platforms were labelled Libraries
 * in the interface, and it filtered platforms with library_filter_sql('p.id') -
 * comparing a platform id against a list of library ids, which is meaningless
 * and only looked right because both sequences start at 1.
 *
 * Access belongs to the entries, not to the machine: everyone can see that the
 * Amiga exists, but the counts must only include things the viewer can reach.
 */
function platforms_index(): void
{
    [$acl, $aclP] = library_filter_sql('i.library_id', ACCESS_VIEWER);

    // The shared machines plus whatever the libraries this account can reach
    // have defined for themselves. Somebody else's Sharp MZ-2500 is not
    // anybody's business.
    // Templates excluded: they are copied into a library when one is made and
    // are not a machine anybody browses. Including them listed everything twice.
    $mine = accessible_library_ids(acting_user(), ACCESS_VIEWER);
    if ($mine === []) {
        $scope = '1 = 0';
        $args  = $aclP;
    } else {
        $scope = 'p.library_id IN (' . implode(',', array_fill(0, count($mine), '?')) . ')';
        $args  = array_merge($aclP, $mine);
    }

    $rows = all('SELECT p.*, l.name AS library_name,
            v.name AS manufacturer,
            SUM(i.status = \'owned\')     AS n,
            AVG(NULLIF(i.rating, 0))      AS avg_rating,
            MIN(NULLIF(i.release_year,0)) AS first_year,
            MAX(i.release_year)           AS last_year
        FROM platforms p
        LEFT JOIN items i ON i.platform_id = p.id AND i.deleted_at IS NULL AND ' . $acl . '
        LEFT JOIN libraries l ON l.id = p.library_id
        LEFT JOIN companies v ON v.id = p.vendor_id
        WHERE ' . $scope . '
        GROUP BY p.id ORDER BY p.library_id IS NOT NULL, p.name', $args);

    render('taxonomy/platforms', [
        'pageTitle' => 'Platforms',
        'rows'      => $rows,
        // Where a new one would go. A library owner defines machines for their
        // own library; only an administrator adds one everybody shares.
        'ownable'   => array_values(array_filter(
            readable_libraries(ACCESS_OWNER),
            fn($l) => can_own_library((int) $l['id'])
        )),
    ]);
}

/**
 * Browse by library - the shelves themselves, which is what the word means now.
 */
function libraries_index(): void
{
    $rows = [];
    foreach (readable_libraries(ACCESS_VIEWER) as $lib) {
        $id = (int) $lib['id'];
        $rows[] = $lib + [
            'n'       => (int) scalar('SELECT COUNT(*) FROM items WHERE library_id = ? AND deleted_at IS NULL', [$id]),
            'access'  => library_access(current_user(), $id),
            'members' => (int) scalar('SELECT COUNT(*) FROM library_members WHERE library_id = ?', [$id]),
            'owner'   => (string) (scalar('SELECT username FROM users WHERE id = ?', [(int) ($lib['owner_id'] ?? 0)]) ?? ''),
        ];
    }
    render('taxonomy/libraries', ['pageTitle' => 'Libraries', 'rows' => $rows]);
}


function companies_index(): void
{
    [$acl, $aclP] = library_filter_sql('i.library_id', ACCESS_VIEWER);
    $rows = all('SELECT c.*,
            (SELECT COUNT(*) FROM items i WHERE i.developer_id = c.id AND i.deleted_at IS NULL AND i.status = \'owned\' AND ' . $acl . ') AS developed,
            (SELECT COUNT(*) FROM items i WHERE i.publisher_id = c.id AND i.deleted_at IS NULL AND i.status = \'owned\' AND ' . $acl . ') AS published
        FROM companies c ORDER BY c.name', array_merge($aclP, $aclP));

    render('companies/index', ['pageTitle' => 'Developers & publishers', 'rows' => $rows]);
}

/**
 * The node a form means, by slug or by id.
 *
 * The three "which branch" controls were `<select>`s listing every node in the
 * library - 3,672 options each, 1.36 MB of a 1.71 MB page, and unusable as
 * controls: nobody finds "Memory expansions under Amiga 2000" by scrolling. They
 * are text inputs against one shared datalist now, which posts a slug.
 *
 * Ids are still accepted, because `?under=` on the + button of a row passes one
 * and there is no reason to break a working link to fix a select.
 *
 * Scoped to the working library: a slug is unique within one, and resolving
 * across all of them would let a form reach a branch of somebody else's tree.
 */
function tree_node_id_from_input(string $slugField, string $idField): ?int
{
    $id = input_int($idField);
    if ($id !== null && $id > 0) {
        return $id;
    }
    $slug = trim((string) input($slugField, ''));
    if ($slug === '') {
        return null;
    }
    $lib = working_library();
    if ($lib === null) {
        return null;
    }
    $row = one('SELECT id FROM categories WHERE library_id = ? AND slug = ?',
               [(int) $lib['id'], $slug]);
    return $row === null ? null : (int) $row['id'];
}


function companies_show(string $slug): void
{
    $company = company_for_slug($slug);
    if ($company === null) {
        not_found('No such company on file.');
    }
    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);
    $developed = all('SELECT * FROM v_items WHERE developer_id = ? AND ' . $acl . ' ORDER BY release_year IS NULL, release_year, COALESCE(sort_title, title)',
        array_merge([(int) $company['id']], $aclP));
    // developer_id <> ? drops rows where it is NULL, which is most of them.
    $published = all('SELECT * FROM v_items WHERE publisher_id = ? AND (developer_id IS NULL OR developer_id <> ?) AND ' . $acl . ' ORDER BY release_year IS NULL, release_year, COALESCE(sort_title, title)',
        array_merge([(int) $company['id'], (int) $company['id']], $aclP));

    render('companies/show', [
        'pageTitle' => $company['name'],
        'company'   => $company,
        'developed' => $developed,
        'published' => $published,
    ]);
}

// ---------------------------------------------------------------------------
// The catalogue tree
//
// One screen for the whole shape: what exists, where an entry can be filed, and
// which sources answer for each part. Splitting these across three pages would
// mean holding the tree in your head while looking at something else.
// ---------------------------------------------------------------------------

/**
 * May this person edit the tree of the library they are working in?
 *
 * The category tree belongs to a library - every node carries its library_id -
 * so the person who owns that library is exactly who should be arranging it.
 * Requiring an administrator meant somebody with their own private library was
 * told "that area is for administrators" about their own shelves.
 *
 * Administrators keep their way in regardless, because they may be working in a
 * library they are not a member of.
 */
function require_tree_access(): void
{
    if (is_admin()) {
        return;
    }
    $lib = working_library();
    if ($lib !== null
        && access_rank(library_access(acting_user(), (int) $lib['id'])) >= access_rank(ACCESS_CURATOR)) {
        return;
    }
    flash('error', 'You can arrange the tree of a library you curate. This is not one of them.');
    redirect('/');
}

function tree_index(): void
{
    require_tree_access();
    $selectedId = input_int('node');
    $selected   = $selectedId === null ? null : one('SELECT * FROM categories WHERE id = ?', [$selectedId]);

    render('taxonomy/tree', [
        'pageTitle' => 'Category editor',
        'nodes'     => category_tree(),
        // What the tree can be narrowed by. Sixty-three machines is a lot to scroll
        // past to reach the Amiga, and the classes and makers are already on the
        // platform rows - no new data, just a way to ask.
        'filterPlatforms' => (function () {
            $lib = working_library();
            if ($lib === null) {
                return [];
            }
            return all(
                'SELECT p.id, p.name, p.slug, p.machine_class, v.name AS maker
                   FROM platforms p
              LEFT JOIN companies v ON v.id = p.vendor_id
                  WHERE p.library_id = ?
                    AND EXISTS (SELECT 1 FROM categories c
                                 WHERE c.platform_id = p.id AND c.library_id = p.library_id)
                  ORDER BY p.name',
                [(int) $lib['id']]
            );
        })(),
        // The platforms this library actually holds something for, so the tree can be
        // shown the way people think about it: Amiga, then its hardware and software,
        // then the kinds beneath.
        //
        // Only those in use. Hanging the kind tree under all sixty-three would render
        // several thousand rows to say nothing - the point is the machines you have.
        'platformsInUse' => (function () {
            $lib = working_library();
            if ($lib === null) {
                return [];
            }
            return all(
                "SELECT p.id, p.name, p.slug, p.accent_color,
                        (SELECT COUNT(*) FROM items i
                          WHERE i.platform_id = p.id AND i.library_id = p.library_id
                            AND i.deleted_at IS NULL) AS held
                   FROM platforms p
                  WHERE p.library_id = ?
                    AND (EXISTS (SELECT 1 FROM items i
                                  WHERE i.platform_id = p.id AND i.deleted_at IS NULL)
                      OR EXISTS (SELECT 1 FROM hardware_models m WHERE m.platform_id = p.id))
                  ORDER BY p.name",
                [(int) $lib['id']]
            );
        })(),
        'selected'  => $selected,
        'platforms' => all_platforms(),
        'counts'    => tree_entry_counts(),
        'available' => $selected === null ? [] : providers_available_for((int) $selected['id'], input_int('platform')),
        'scopePlatform' => input_int('platform'),
        'scopes'    => $selected === null ? [] : all(
            'SELECT ps.*, mp.name, mp.type, p.name AS platform_name
               FROM provider_scopes ps
               JOIN metadata_providers mp ON mp.id = ps.provider_id
          LEFT JOIN platforms p ON p.id = ps.platform_id
              WHERE ps.category_id = ?', [(int) $selected['id']]
        ),
        'inherited' => $selected === null ? [] : providers_for((int) $selected['id'], input_int('platform')),
        // Models attached at this node, and what it inherits from above. The same
        // inheritance the agents use, shown so that attaching a model to a branch is
        // visibly a thing you can do rather than a column nobody sees.
        'nodeModels' => $selected === null ? [] : models_for_category(
            (int) $selected['id'],
            (function () {
                $mine = working_library();
                return $mine === null ? null : (int) $mine['id'];
            })()
        ),
    ]);
}

/** How many entries sit on each node, so nothing is deleted blind. */
function tree_entry_counts(): array
{
    $out = [];
    foreach (all('SELECT category_id, COUNT(*) AS n FROM items GROUP BY category_id') as $row) {
        $out[(int) $row['category_id']] = (int) $row['n'];
    }
    return $out;
}

function tree_save(): void
{
    require_tree_access();
    csrf_verify();

    $action = input('action', '');
    $id     = input_int('id');

    // One press from the tree: make a branch and open it.
    //
    // The name is a placeholder because asking for one first is what the panel
    // this replaces did, and it put a form between somebody and the thing they
    // had already decided to do. The branch appears selected, with its name in
    // the field, so the next keystroke is the rename.
    if ($action === 'quick_add') {
        $parentId = input_int('parent_id');
        $parent   = $parentId === null ? null : one('SELECT * FROM categories WHERE id = ?', [$parentId]);
        if ($parent === null) {
            flash('error', 'That branch is not there any more.');
            redirect('/manage/tree');
        }

        // "New branch", "New branch 2", and so on: two presses in a row must not
        // collide on the unique slug, and two branches called the same thing in
        // the same place are indistinguishable in the picker.
        $base = 'New branch';
        $name = $base;
        $n    = 1;
        while (one('SELECT id FROM categories WHERE library_id = ? AND parent_id <=> ? AND name = ?',
                   [(int) $parent['library_id'], (int) $parent['id'], $name]) !== null) {
            $n++;
            $name = $base . ' ' . $n;
        }

        $newId = insert_row('categories', [
            'library_id'  => (int) $parent['library_id'],
            'section_id'  => (int) $parent['section_id'],
            'parent_id'   => (int) $parent['id'],
            'platform_id' => $parent['platform_id'] === null ? null : (int) $parent['platform_id'],
            'name'        => $name,
            'slug'        => unique_slug('categories', slugify($parent['slug'] . '-' . $name)),
            'sort_order'  => (int) (scalar('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM categories
                                             WHERE parent_id = ?', [(int) $parent['id']]) ?? 100),
        ]);
        category_rebuild_paths();
        flash('ok', 'Added under ' . $parent['name'] . '. Give it a name.');
        redirect('/manage/tree', ['node' => $newId]);
    }

    if ($action === 'add') {
        $parentId = tree_node_id_from_input('parent_slug', 'parent_id');
        $name     = trim((string) input('name', ''));
        if ($name === '') {
            form_failed('/manage/tree', ['name' => 'Give the new node a name.'], $parentId ? ['node' => $parentId] : []);
        }
        $parent = $parentId === null ? null : one('SELECT * FROM categories WHERE id = ?', [$parentId]);
        $platformId = input_int('platform_id');

        // The library whose tree this is.
        //
        // Left out, the row was created with library_id NULL - the template
        // scope - and the tree only ever shows one library's own nodes. So the
        // node was made, the flash said so, and it appeared nowhere: created
        // into the shelf of templates that new libraries are copied from rather
        // than into the shelf somebody was looking at.
        $treeLib = $parent !== null ? (int) $parent['library_id'] : (int) (working_library()['id'] ?? 0);
        if ($treeLib === 0) {
            flash('error', 'Choose a library to work in before adding to its tree.');
            redirect('/manage/tree');
        }

        // A root is a machine, and machines are made under Platforms.
        //
        // Every root in a seeded library is a platform's branch - sixty-three
        // roots, sixty-three platforms, each carrying its platform_id. A root
        // added here was a machine branch with no machine behind it: it said
        // "platform" on the screen, nothing appeared in the platform list, and
        // nothing could ever be filed under it that knew what it ran on.
        if ($parent === null) {
            flash('error', 'A top-level branch is a machine. Add it under Platforms and its '
                         . 'branch appears here, then hang the kinds of thing off that.');
            redirect('/manage/tree');
        }

        $newId = insert_row('categories', [
            'library_id'  => $treeLib,
            'section_id'  => $parent !== null ? (int) $parent['section_id']
                             : (int) scalar("SELECT id FROM sections WHERE slug = ?",
                                            [input('domain') === 'hardware' ? 'hardware' : 'software']),
            'parent_id'   => $parentId,
            // Only for a kind that genuinely belongs to one machine. Left NULL,
            // which is nearly always right, it applies everywhere.
            'platform_id' => $platformId > 0 ? $platformId : null,
            'name'        => mb_substr($name, 0, 120),
            'slug'        => unique_slug('categories', slugify(
                                ($parent !== null ? $parent['slug'] . '-' : '') . $name)),
            'sort_order'  => (int) (input_int('sort_order') ?? 100),
        ]);
        category_rebuild_paths();
        flash('ok', $name . ' added.');
        redirect('/manage/tree', ['node' => $newId]);
    }

    // Save a whole ordering at once.
    //
    // The page reorders rows without touching the server and posts the result here
    // when you press Save. One request instead of one per click, and - the reason it
    // changed - nothing reloads while you are still arranging things.
    //
    // Above the id guard below: this names many nodes and no single one, so the check
    // that demands an id was bouncing it to an empty redirect - no error, no change,
    // nothing to see.
    if ($action === 'reorder') {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['order'] ?? []))));
        if ($ids === []) {
            flash('error', 'Nothing to save.');
            redirect('/manage/tree');
        }
        // Renumber within each parent, in the order given. Ids not sent are left
        // alone, so a partial ordering cannot silently reshuffle the rest.
        $seen = [];
        foreach ($ids as $i => $cid) {
            $row = one('SELECT parent_id FROM categories WHERE id = ?', [$cid]);
            if ($row === null) {
                continue;
            }
            $key = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            q('UPDATE categories SET sort_order = ? WHERE id = ?', [$seen[$key] * 10, $cid]);
        }
        flash('ok', 'Order saved.');
        redirect('/manage/tree');
    }

    if ($id === null) {
        redirect('/manage/tree');
    }
    $node = one('SELECT * FROM categories WHERE id = ?', [$id]);
    if ($node === null) {
        flash('error', 'No such node.');
        redirect('/manage/tree');
    }

    // Move one place up or down among its siblings.
    //
    // Siblings are renumbered by tens first. Two nodes can legitimately share a
    // sort_order - the seed sets them in tens but nothing enforces it - and swapping
    // two equal numbers moves nothing, so the arrow would appear broken. Renumbering
    // makes the swap always mean something.
    if ($action === 'up' || $action === 'down') {
        $node = $id === null ? null : one('SELECT * FROM categories WHERE id = ?', [$id]);
        if ($node === null) {
            flash('error', 'No such node.');
            redirect('/manage/tree');
        }

        // Same parent *and* same section. Roots of every section share parent_id
        // NULL, but the list groups roots by section - so without this, moving
        // Consoles up could swap it with a software root and change nothing
        // visible. Children inherit their parent's section, so the extra clause
        // is a no-op for them.
        $siblings = all('SELECT id, sort_order, name FROM categories
                          WHERE parent_id <=> ? AND section_id = ? ORDER BY sort_order, name',
                        [$node['parent_id'], (int) $node['section_id']]);
        $step = 10;
        foreach ($siblings as $i => $sib) {
            q('UPDATE categories SET sort_order = ? WHERE id = ?', [($i + 1) * $step, (int) $sib['id']]);
        }

        $at = null;
        foreach ($siblings as $i => $sib) {
            if ((int) $sib['id'] === $id) {
                $at = $i;
                break;
            }
        }
        $swapWith = $action === 'up' ? ($at - 1) : ($at + 1);
        if ($at === null || !isset($siblings[$swapWith])) {
            $why = $action === 'up'
                ? $node['name'] . ' is already first among its siblings.'
                : $node['name'] . ' is already last among its siblings.';
            if (wants_json()) {
                json_out(['ok' => false, 'error' => $why]);
            }
            flash('error', $why);
            redirect('/manage/tree', ['node' => $id]);
        }

        q('UPDATE categories SET sort_order = ? WHERE id = ?', [($swapWith + 1) * $step, $id]);
        q('UPDATE categories SET sort_order = ? WHERE id = ?', [($at + 1) * $step, (int) $siblings[$swapWith]['id']]);

        // Answered as JSON when the page asks that way, so the row can be moved in
        // place instead of the whole tree being rebuilt and the browser landing
        // somewhere else. The form still works without any of that - it is the same
        // handler, and the redirect below is what a plain submit gets.
        if (wants_json()) {
            json_out(['ok' => true, 'moved' => $id, 'with' => (int) $siblings[$swapWith]['id']]);
        }

        flash('ok', $node['name'] . ' moved ' . $action . '.');
        // No #node-N. The fragment was meant to keep your place, but the browser
        // satisfies it by scrolling the row to the top of the viewport - which reads
        // as the page jumping somewhere else, and is worse than simply landing at the
        // top. The list scrolls in its own box now, and the arrows avoid the round
        // trip entirely wherever the page's script runs.
        redirect('/manage/tree', ['node' => $id]);
    }

    if ($action === 'rename') {
        $name = trim((string) input('name', ''));
        if ($name !== '') {
            // What goes here, if the form said. Refused rather than coerced when
            // it does not match the side of the shop this branch is on: a
            // hardware branch holding "games" would be a fact nothing downstream
            // could act on.
            $fields = [
                'name'       => mb_substr($name, 0, 120),
                'sort_order' => (int) (input_int('sort_order') ?? (int) $node['sort_order']),
            ];
            // The kind decides the side of the shop.
            //
            // It used to be the other way round - the domain decided which kinds
            // were offered - and a branch made with + inherits its parent's
            // domain, every root being a machine. So a tree built by hand was
            // hardware all the way down and there was no way to say a branch
            // held games. The kind is what somebody knows; the domain follows.
            // A root has no kind, whatever the request says.
            //
            // The screen stops offering one, and this stops a posted form
            // setting it anyway - a machine that claimed to hold peripherals
            // would make every browser filter beneath it answer wrongly, and
            // "the form does not show it" is a habit rather than a rule.
            $wantRole = $node['parent_id'] === null ? '' : (string) input('role', '');
            if (in_array($wantRole, ['other', 'machine', 'peripheral', 'game', 'application'], true)) {
                $fields['role'] = $wantRole;
                $sideSlug = match ($wantRole) {
                    'machine', 'peripheral'  => 'hardware',
                    'game', 'application'    => 'software',
                    // "Nothing directly" says nothing about the side, so the
                    // branch stays where it is.
                    default                  => null,
                };
                $sideSectionId = $sideSlug !== null
                    ? (int) scalar('SELECT id FROM sections WHERE slug = ?', [$sideSlug])
                    : (int) $node['section_id'];
                $fields['section_id'] = $sideSectionId;

                // And everything under it.
                //
                // A branch made with + inherits its parent's section at the moment
                // it is made, so declaring "Games" as software after building
                // branches beneath it left those branches on the hardware side -
                // and the sources offered for them were hardware sources, under a
                // branch declared for games. Moving a branch moves its subtree,
                // because there is no sense in which the children stayed where
                // they were.
                if ($sideSectionId !== (int) $node['section_id'] && ($node['path'] ?? '') !== '') {
                    q('UPDATE categories SET section_id = ?
                        WHERE library_id <=> ? AND path LIKE ? AND id <> ?',
                      [$sideSectionId, $node['library_id'], (string) $node['path'] . '%', $id]);
                }
            }
            update_row('categories', $id, $fields);
            flash('ok', 'Saved.');
        }
        redirect('/manage/tree', ['node' => $id]);
    }

    if ($action === 'move') {
        $newParent = tree_node_id_from_input('parent_slug', 'parent_id');
        // Moving a node beneath itself would detach the branch from the tree
        // entirely and leave rows nothing can reach.
        if ($newParent !== null && in_array($newParent, category_subtree_ids($id), true)) {
            flash('error', 'A node cannot be moved inside itself.');
            redirect('/manage/tree', ['node' => $id]);
        }
        $parent = $newParent === null ? null : one('SELECT * FROM categories WHERE id = ?', [$newParent]);
        $newSectionId = $parent !== null ? (int) $parent['section_id'] : (int) $node['section_id'];
        update_row('categories', $id, [
            'parent_id'  => $newParent,
            'section_id' => $newSectionId,
        ]);
        // The whole branch follows, so its section has to follow too.
        foreach (category_subtree_ids($id) as $descendant) {
            update_row('categories', $descendant, ['section_id' => $newSectionId]);
        }
        category_rebuild_paths();
        flash('ok', 'Moved.');
        redirect('/manage/tree', ['node' => $id]);
    }

    if ($action === 'copy') {
        $target = tree_node_id_from_input('target_slug', 'target_id');
        if ($target === null) {
            flash('error', 'Choose where to copy it.');
            redirect('/manage/tree', ['node' => $id]);
        }
        if (in_array($target, category_subtree_ids($id), true)) {
            flash('error', 'A branch cannot be copied inside itself.');
            redirect('/manage/tree', ['node' => $id]);
        }
        $newId = copy_subtree($id, $target, trim((string) input('name', '')) ?: null);
        flash('ok', 'Copied, with everything beneath it.');
        redirect('/manage/tree', ['node' => $newId]);
    }

    if ($action === 'delete') {
        // Games is load-bearing. Every game genre hangs off it, titles are filed
        // under it, and the entry form reads it to decide which genres to offer -
        // so removing it would take the genre tree with it and leave the software
        // side of the catalogue with nothing to file anything under. The foreign
        // keys would allow it; that is exactly why the refusal has to live here.
        $protected = category_protected_reason($id);
        if ($protected !== null) {
            flash('error', $protected);
            redirect('/manage/tree', ['node' => $id]);
        }

        $subtree = category_subtree_ids($id);
        $ph = implode(',', array_fill(0, count($subtree), '?'));
        $held = (int) scalar("SELECT COUNT(*) FROM items WHERE category_id IN ($ph)", $subtree);
        if ($held > 0) {
            flash('error', 'That branch still holds ' . $held . ' '
                . ($held === 1 ? 'entry' : 'entries') . '. Move them first — deleting a '
                . 'branch should never be a way to lose things by accident.');
            redirect('/manage/tree', ['node' => $id]);
        }
        // hardware_models.category_id is ON DELETE SET NULL, so deleting a
        // branch does not refuse and does not cascade - it silently empties the
        // one column that says whether a model is a machine or a part. Nothing
        // in the interface shows it happened, and the rows then fall out of
        // every query that joins categories. Counting entries alone missed
        // this entirely.
        $models = (int) scalar(
            "SELECT COUNT(*) FROM hardware_models WHERE category_id IN ($ph)", $subtree);
        if ($models > 0) {
            flash('error', 'That branch is still the kind of ' . $models . ' hardware '
                . ($models === 1 ? 'model' : 'models') . '. Refile them first — deleting it '
                . 'would leave them as neither a machine nor a part.');
            redirect('/manage/tree', ['node' => $id]);
        }
        delete_row('categories', $id);
        category_rebuild_paths();
        flash('ok', 'Deleted.');
        redirect('/manage/tree');
    }

    if ($action === 'scope') {
        $providerId = input_int('provider_id');
        $wanted     = input('enabled', '1') === '1';
        $provider   = $providerId === null ? null : one('SELECT * FROM metadata_providers WHERE id = ?', [$providerId]);
        if ($provider === null) {
            redirect('/manage/tree', ['node' => $id]);
        }
        $scopePlatform = input_int('platform_id');
        [$fits, $why] = provider_fits_node((string) $provider['type'], $id, $scopePlatform);
        if ($wanted && !$fits) {
            flash('error', $why);
            redirect('/manage/tree', ['node' => $id]);
        }
        q('INSERT INTO provider_scopes (provider_id, category_id, platform_id, enabled) VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)',
          // 0, not null. The column is NOT NULL DEFAULT 0 and 0 is what "any
          // machine" means in it - writing null was a constraint violation every
          // time no machine was named, which was every time somebody left the
          // filter alone. Removing that filter did not cause this; it removed
          // the only path that avoided it.
          [$providerId, $id, $scopePlatform !== null && $scopePlatform > 0 ? $scopePlatform : 0,
           $wanted ? 1 : 0]);
        flash('ok', $provider['name'] . ($wanted ? ' enabled here and below.' : ' switched off for this branch.'));
        redirect('/manage/tree', ['node' => $id]);
    }

    if ($action === 'unscope') {
        q('DELETE FROM provider_scopes WHERE category_id = ? AND provider_id = ?', [$id, input_int('provider_id')]);
        flash('ok', 'Back to inheriting from above.');
        redirect('/manage/tree', ['node' => $id]);
    }

    redirect('/manage/tree', ['node' => $id]);
}

// ---------------------------------------------------------------------------
// Machines and parts
//
// Seeded rather than typed, but not frozen. A list nobody can extend is wrong
// the first time somebody owns something unusual, and a list anybody can extend
// freely becomes six spellings of one machine. Editing is an administrator's
// job, and the seed is the starting point rather than the whole truth.
// ---------------------------------------------------------------------------

/**
 * The fields a model carries, from the row editor.
 *
 * Three boxes a row - name, starting value, choices - rather than one textarea
 * holding "Name = value | one, two, three". The syntax was compact and had to
 * be learnt: an equals sign meant one thing, a pipe another, and a value
 * containing either was a trap the parser worked around rather than avoided.
 * Boxes cannot be ambiguous about which part is which.
 */
function save_model_fields(int $modelId): void
{
    $labels = (array) ($_POST['field_label'] ?? []);
    $values = (array) ($_POST['field_value'] ?? []);

    q('DELETE FROM model_fields WHERE model_id = ?', [$modelId]);

    $order = 0;
    foreach ($labels as $i => $label) {
        $label = trim((string) $label);
        if ($label === '') {
            // An empty name is an empty row, which is what a spare row at the
            // bottom of the form looks like. Not an error, just nothing.
            continue;
        }

        $order += 10;
        q('INSERT IGNORE INTO model_fields (model_id, label, default_value, hint, sort_order)
           VALUES (?, ?, ?, NULL, ?)',
          [$modelId,
           mb_substr($label, 0, 80),
           // NULL, not '': there is no starting value, as against the starting
           // value being empty. The entry form shows an empty box either way,
           // but only one of them is true.
           ($v = mb_substr(trim((string) ($values[$i] ?? '')), 0, 400)) === '' ? null : $v,
           $order]);
    }
}

/**
 * Machines and peripherals are two pages, not one.
 *
 * They were one list of everything, which meant scrolling past forty
 * accelerators to reach the A1200 - and the two are edited for different
 * reasons. An A500 is defined once and rarely touched; peripherals accumulate.
 *
 * Both go through here because the form and the save path are genuinely the
 * same; only the half of the list being shown differs.
 */
function models_index(): void
{
    models_page(true);
}

function parts_index(): void
{
    models_page(false);
}

function models_page(bool $machines): void
{
    require_manage();

    // Which library's models. Models are per library now, exactly as platforms and
    // makers are, so this page needs a library the way /manage/platforms does -
    // otherwise it would offer every library's machines as one list and save new
    // ones nowhere in particular.
    $libraryId = (function () {
        $want = trim((string) ($_GET['library'] ?? ''));
        if ($want !== '') {
            $hit = one('SELECT id FROM libraries WHERE slug = ? OR id = ?', [$want, (int) $want]);
            if ($hit !== null && can_own_library((int) $hit['id'])) {
                return (int) $hit['id'];
            }
        }
        $mine = working_library();
        return $mine === null ? 0 : (int) $mine['id'];
    })();

    // Only a model in a library this account owns is editable, and only through
    // this page - the id alone used to be enough to open any model on the
    // instance for editing.
    $editing = null;
    if (input_int('edit')) {
        $editing = one('SELECT * FROM hardware_models WHERE id = ?', [input_int('edit')]);
        if ($editing !== null
            && ($editing['library_id'] === null || !can_own_library((int) $editing['library_id']))) {
            $editing = null;
        }
    }

    // The kinds offered follow the page: adding a machine should not list
    // "Accelerator", and adding a peripheral should not list "Computers".
    // The kinds offered, and only real ones.
    //
    // The peripheral list admitted role 'other', which is not a kind of thing - it is
    // the structural rows: the platform root and the Hardware and Software branches
    // under it. So "Amiga" and "Amiga > Hardware" were offered as types a card could
    // be, alongside a thousand others, and picking one filed a model under a heading.
    //
    // A kind is a leaf or a branch somebody could plausibly file under, which here
    // means: not a platform root, and not a domain node.
    $role  = $machines ? 'machine' : 'peripheral';
    $types = array_values(array_filter(
        hardware_types(),
        function ($t) use ($machines) {
            // The inherited kind, so a branch under a declared one counts too.
            $r = $t['kind'] ?? ($t['role'] ?? '');
            if ($machines) {
                return $r === 'machine';
            }
            if ($r === 'peripheral') {
                return true;
            }
            // 'other' only below the domain node - depth 2 and lower. Depth 0 is the
            // machine itself, depth 1 is Hardware or Software.
            return $r === 'other' && (int) ($t['depth'] ?? 0) >= 2;
        }
    ));

    render('taxonomy/models', [
        'pageTitle' => $machines ? 'Machine models' : 'Peripheral models',
        'machines'  => $machines,
        'current'   => $machines ? 'models' : 'parts',
        'models'    => hardware_models(null, $machines, $libraryId),
        'libraryId' => $libraryId,
        'libraries' => array_values(array_filter(
            readable_libraries(ACCESS_OWNER),
            fn($l) => can_own_library((int) $l['id'])
        )),
        // This library's makers and platforms, because a model in this library
        // points at them. They used to be the template rows, which was right while
        // models were themselves template data and wrong the moment they were not.
        // Hardware makers. One table since the merge, so the tag is what narrows it -
        // an unfiltered list offered every games publisher as a machine manufacturer.
        'vendors'   => all("SELECT * FROM companies
                             WHERE library_id = ? AND FIND_IN_SET('hardware', makes)
                             ORDER BY name", [$libraryId]),
        // Every company, tagged or not. An empty maker list means one of two things -
        // no companies at all, or none marked as making hardware - and the answers
        // differ, so the form needs to be able to tell them apart.
        'companyCount' => (int) scalar('SELECT COUNT(*) FROM companies WHERE library_id = ?',
                                       [$libraryId]),
        'platforms' => all('SELECT * FROM platforms WHERE library_id = ? ORDER BY name', [$libraryId]),
        'types'     => $types,
        'editing'   => $editing,
        'fields'    => $editing ? model_fields((int) $editing['id']) : [],
        // What a machine of this kind usually has, offered as suggestions on
        // the field name boxes. The specification vocabulary is exactly this
        // list - processor, memory, the ports a board carries - so it is worth
        // offering rather than making everybody retype it.
        // Every machine model, with its platform, so the compatibility select
        // can be narrowed in the browser without another request.
        'compatibilityChosen' => $editing ? model_compatibility_ids((int) $editing['id']) : [],
        // The machines a card can be marked as fitting: this library's, not every
        // library's. A private shelf must not be able to point at somebody else's
        // A2000.
        'compatibleModels' => all("SELECT m.id, m.name, m.platform_id, p.name AS platform_name
                               FROM hardware_models m
                               JOIN categories c ON c.id = m.category_id AND c.role = 'machine'
                          LEFT JOIN platforms p ON p.id = m.platform_id
                              WHERE m.library_id = ?
                              ORDER BY p.name, m.name", [$libraryId]),
        'specNames' => array_values(array_unique(array_column(
            all("SELECT DISTINCT name FROM hardware_vocab
                  WHERE kind IN ('feature', 'interface') ORDER BY name"),
            'name'
        ))),
    ]);
}

/** Whichever of the two pages the form was submitted from. */
function models_return_path(): string
{
    return str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/manage/parts')
        ? '/manage/parts' : '/manage/models';
}

function models_save(): void
{
    require_manage();
    csrf_verify();

    $id   = input_int('id');
    $name = trim((string) input('name', ''));

    // Which library this model belongs to, and whether this account owns it. Models
    // are per library now, so ownership of that library is the permission - an
    // administrator with no membership has no business editing somebody's shelf,
    // which is the rule acl.php states and this page used to sidestep by asking
    // only for the admin role.
    $libraryId = (int) (input_int('library_id') ?? 0);
    if ($id !== null) {
        $existing = one('SELECT library_id FROM hardware_models WHERE id = ?', [$id]);
        if ($existing === null || $existing['library_id'] === null) {
            flash('error', 'No such model.');
            redirect(models_return_path());
        }
        $libraryId = (int) $existing['library_id'];
    }
    if ($libraryId <= 0 || !can_own_library($libraryId)) {
        flash('error', 'That library is not yours to add models to.');
        redirect(models_return_path());
    }
    // The slug, for the same reason locations_save() uses it: the header switcher
    // compares against slugs and an id matches none of them.
    $back = ['library' => (string) (scalar('SELECT slug FROM libraries WHERE id = ?', [$libraryId]) ?? $libraryId)];

    if (input('action') === 'delete' && $id !== null) {
        $used = (int) scalar('SELECT COUNT(*) FROM items WHERE model_id = ?', [$id]);
        if ($used > 0) {
            flash('error', 'That is used by ' . $used . ' ' . ($used === 1 ? 'entry' : 'entries')
                . '. Change those first — deleting should not quietly empty a field somebody filled in.');
        } else {
            q('DELETE FROM hardware_models WHERE id = ?', [$id]);
            flash('ok', 'Removed.');
        }
        redirect(models_return_path());
    }

    if ($name === '') {
        flash('error', 'A name is the one thing it cannot do without.');
        redirect(models_return_path(), $id ? ['edit' => $id] + $back : $back);
    }

    // Optional, but hardware if given: a model of a word processor makes no
    // sense, and letting one through would file entries into the software tree.
    $categoryId = input_int('category_id') ?: null;
    if ($categoryId !== null
        && (string) scalar('SELECT s.slug FROM categories c JOIN sections s ON s.id = c.section_id
                             WHERE c.id = ?', [$categoryId]) !== 'hardware') {
        flash('error', 'That type belongs to software. Pick a hardware one, or leave it unset.');
        redirect(models_return_path(), $id ? ['edit' => $id] + $back : $back);
    }
    $platformId = input_int('platform_id') ?: null;

    $data = [
        'vendor_id'   => input_int('vendor_id') ?: null,
        'platform_id' => $platformId,
        'category_id' => $categoryId,
        'name'        => mb_substr($name, 0, 160),
        'year_from'   => input_int('year_from'),
        // Only meaningful for a part; harmless on a machine, which has slots
        // rather than occupying one.
        // Which machines it fits is a set, written after the row exists.
        'fits_note'   => nullify(input('fits_note')),
        'interface'   => nullify(input('interface')),
        'notes'       => nullify(input('notes')),
        'sort_order'  => (int) (input_int('sort_order') ?? 100),
    ];

    if ($id !== null) {
        update_row('hardware_models', $id, $data);
    } else {
        // Unique within the library, not the instance: two collections may both
        // define an Amiga 2000, and the unique key is on (library_key, slug).
        // unique_slug() would append "-2" to the second one for no reason.
        $data['library_id'] = $libraryId;
        $slug = slugify($name);
        if (one('SELECT id FROM hardware_models WHERE library_id = ? AND slug = ?',
                [$libraryId, $slug]) !== null) {
            flash('error', 'That library already has a model called ' . $name . '.');
            redirect(models_return_path(), $back);
        }
        $id = insert_row('hardware_models', $data + ['slug' => $slug]);
    }

    save_model_fields($id);
    // After the row: a new model has no id until it is inserted, and the set
    // points at it.
    set_model_compatibility($id, (array) ($_POST['compatible_models'] ?? []));

    flash('ok', $name . ' saved.');
    redirect(models_return_path(), ['edit' => $id] + $back);
}


// ---------------------------------------------------------------------------
// Platforms
//
// The shared ones belong to the instance and only an administrator changes
// them. A library may define its own, and its owner decides those - which is
// why this is one page rather than an administration screen: the same form
// serves both, and who may press Save is the only difference.
// ---------------------------------------------------------------------------

function platforms_manage_index(): void
{
    require_manage();
    $editing = input_int('edit') ? one('SELECT * FROM platforms WHERE id = ?', [input_int('edit')]) : null;
    if ($editing !== null && !can_edit_platform($editing)) {
        flash('error', 'That machine is not yours to change.');
        redirect('/manage/platforms');
    }

    // The library this page is showing, resolved before the list is built - the list
    // has to follow it. It used to scope to every library the account could read, so
    // two libraries each holding the shipped set produced one list of 127 platforms
    // with every name in it twice, and the "Belongs to" column was the only way to
    // tell which was which.
    $currentLibraryId = (function () {
        $want = trim((string) ($_GET['library'] ?? ''));
        if ($want !== '') {
            $hit = one('SELECT id FROM libraries WHERE slug = ? OR id = ?', [$want, (int) $want]);
            if ($hit !== null && can_own_library((int) $hit['id'])) {
                return (int) $hit['id'];
            }
        }
        $mine = working_library();
        return $mine === null ? 0 : (int) $mine['id'];
    })();

    $scope = 'p.library_id = ?';
    $args  = [$currentLibraryId];

    render('taxonomy/platforms_manage', [
        'pageTitle' => 'Platforms',
        // Already resolved above, so the list and the form cannot disagree.
        'currentLibraryId' => $currentLibraryId,
        'rows'      => all('SELECT p.*, l.name AS library_name,
                                   v.name AS manufacturer,
                                   (SELECT COUNT(*) FROM items i WHERE i.platform_id = p.id AND i.deleted_at IS NULL) AS entries
                              FROM platforms p
                         LEFT JOIN libraries l ON l.id = p.library_id
                         LEFT JOIN companies v ON v.id = p.vendor_id
                             WHERE ' . $scope . '
                          ORDER BY p.library_id IS NOT NULL, p.name', $args),
        // This library's makers, not every one this account can reach.
        //
        // Called with nothing, library_vendors() searches every library you are a
        // member of - so a brand-new empty library offered the makers from all
        // the others, and a platform in it could be attributed to a company that
        // does not exist there. The screen already knows which library it is
        // editing; it simply was not saying.
        'vendors'   => library_vendors($currentLibraryId),
        'libraries' => array_values(array_filter(
            readable_libraries(ACCESS_OWNER),
            fn($l) => can_own_library((int) $l['id'])
        )),
        'editing'   => $editing,
    ]);
}

function platforms_manage_save(): void
{
    require_manage();
    csrf_verify();

    // Keep the library on every exit, as the slug the header switcher matches on.
    // Redirecting bare sent you back with no ?library=, so the switcher found nothing
    // to select and showed the first library - which reads as the app having moved you
    // somewhere else while the page you were on carried on regardless.
    $libraryForBack = input_int('library_id') ?? 0;
    if ($libraryForBack <= 0 && input_int('id')) {
        $libraryForBack = (int) (scalar('SELECT library_id FROM platforms WHERE id = ?', [input_int('id')]) ?? 0);
    }
    $back = $libraryForBack > 0
        ? ['library' => (string) (scalar('SELECT slug FROM libraries WHERE id = ?', [$libraryForBack]) ?? $libraryForBack)]
        : [];

    $id     = input_int('id');
    $action = (string) input('action', 'save');
    $row    = $id === null ? null : one('SELECT * FROM platforms WHERE id = ?', [$id]);

    if ($row !== null && !can_edit_platform($row)) {
        flash('error', 'That machine is not yours to change.');
        redirect('/manage/platforms', $back);
    }

    if ($action === 'delete' && $row !== null) {
        $used = (int) scalar('SELECT COUNT(*) FROM items WHERE platform_id = ?', [$id]);
        if ($used > 0) {
            flash('error', sprintf('%d %s filed under %s. Move them first.',
                                   $used, $used === 1 ? 'entry is' : 'entries are', $row['name']));
            redirect('/manage/platforms', $back);
        }
        // The branch the machine carries goes with the machine.
        //
        // categories.platform_id is ON DELETE SET NULL, so this used to leave the
        // root standing: the filing tree went on showing the machine's name with
        // nothing behind it, nothing filed under it could say what it ran on, and
        // resyncing the library did not repair it either - the resync matches on
        // slug, saw the branch, and called the machine already built.
        //
        // Nothing is filed under it: the check above refuses to delete a machine
        // any entry points at, so by here the tree is empty. Emptiness is checked
        // again anyway, because that guard counts entries by platform and this
        // one counts them by branch, and the two could drift.
        $roots = all('SELECT id FROM categories WHERE platform_id = ? AND parent_id IS NULL',
                     [$id]);
        foreach ($roots as $root) {
            $rootId = (int) $root['id'];
            $held   = (int) scalar(
                'SELECT COUNT(*) FROM items
                  WHERE category_id IN (SELECT * FROM (
                            SELECT id FROM categories
                             WHERE id = ? OR parent_id = ?
                                OR parent_id IN (SELECT * FROM (
                                       SELECT id FROM categories WHERE parent_id = ?) AS d)
                        ) AS t)',
                [$rootId, $rootId, $rootId]);
            if ($held === 0) {
                q('DELETE FROM categories
                    WHERE id = ? OR parent_id = ?
                       OR parent_id IN (SELECT * FROM (
                              SELECT id FROM categories WHERE parent_id = ?) AS d)',
                  [$rootId, $rootId, $rootId]);
            }
        }

        delete_row('platforms', $id);
        log_server('platform.deleted', 'Platform "' . $row['name'] . '" removed', LOG_NOTICE);
        flash('ok', $row['name'] . ' removed.');
        redirect('/manage/platforms', $back);
    }

    // Every problem at once, each against the field it is about. Reporting the
    // first and stopping means learning about the second on the next attempt.
    $errors = [];
    $name   = trim((string) input('name', ''));
    if ($name === '') {
        $errors['name'] = 'Give the platform a name.';
    }

    // A library, always. This used to fall back to library_id NULL for an
    // administrator, which was the shared list - and since that idea was
    // removed, NULL means "template": a row copied into libraries and never
    // shown in any of them. An administrator saving the form would have watched
    // the machine disappear.
    $libraryId = input_int('library_id');
    if ($libraryId === null || $libraryId <= 0) {
        $errors['library_id'] = 'Choose which library this machine belongs to.';
    } elseif (!can_own_library($libraryId)) {
        $errors['library_id'] = 'That library is not yours.';
    }

    $year = input_int('year_introduced');
    if ($year !== null && ($year < 1940 || $year > (int) date('Y') + 1)) {
        $errors['year_introduced'] = 'A year between 1940 and next year.';
    }

    if ($name !== '' && $libraryId !== null && $libraryId > 0) {
        $clash = one('SELECT id FROM platforms WHERE library_id = ? AND slug = ? AND id <> ?',
                     [$libraryId, slugify($name), (int) ($id ?? 0)]);
        if ($clash !== null) {
            $errors['name'] = 'That library already has a machine by that name.';
        }
    }

    // Optional, but when given it has to be a maker from the same library -
    // otherwise the picker becomes a way to point at a vendor row in somebody
    // else's shelf and read its name back out of every platform list.
    $vendorId = input_int('vendor_id');
    if ($vendorId !== null && $vendorId > 0) {
        $vendor = one('SELECT id, library_id FROM companies WHERE id = ?', [$vendorId]);
        if ($vendor === null || (int) $vendor['library_id'] !== (int) $libraryId) {
            $errors['vendor_id'] = 'Choose a maker from this library.';
        }
    } else {
        $vendorId = null;
    }

    if ($errors !== []) {
        form_failed('/manage/platforms', $errors, $id ? ['edit' => $id] + $back : $back);
    }


    $data = [
        'library_id'      => $libraryId,
        // The maker has its own column, so it does not belong in the name as
        // well: "Commodore Amiga [Commodore]" says it twice and makes every
        // list wider for nothing.
        'name'            => mb_substr($name, 0, 120),
        // That column is vendor_id. 'manufacturer' is a read alias the list
        // queries build with LEFT JOIN companies - it has never been a column on
        // platforms, so writing it threw an uncaught PDOException and every
        // attempt to add or edit a platform was a 500. The form has always
        // posted vendor_id, which this then discarded.
        'vendor_id'       => $vendorId,
        'year_introduced' => input_int('year_introduced'),
        'accent_color'    => preg_match('/^#[0-9a-f]{6}$/i', (string) input('accent_color', ''))
            ? (string) input('accent_color') : '#a6adc8',
    ];

    if ($id === null) {
        $data['slug'] = unique_slug('platforms', slugify($name));
        $newId = insert_row('platforms', $data);
        // And its branch in the catalogue editor, or the machine exists with
        // nowhere to file anything under it.
        platform_ensure_root((int) $newId, (int) $libraryId, $name);
        log_server('platform.created', 'Platform "' . $name . '" added', LOG_INFO,
                   ['subject_type' => 'platform', 'subject_id' => $newId]);
        flash('ok', $name . ' added, with a branch of its own in the category tree.');
    } else {
        update_row('platforms', $id, $data);
        log_server('platform.updated', 'Platform "' . $name . '" changed', LOG_INFO,
                   ['subject_type' => 'platform', 'subject_id' => $id]);
        flash('ok', 'Saved.');
    }
    redirect('/manage/platforms', $back);
}

/**
 * Software models: the template a title is made from.
 *
 * Deliberately the same shape as models_index() next door - a list on the left, one
 * editor on the right, the same specification-row controls - because the two answer the
 * same question about different halves of the catalogue.
 */
function software_models_index(): void
{
    require_admin();
    $lib   = working_library();
    $libId = $lib === null ? 0 : (int) $lib['id'];

    $editId  = input_int('edit');
    $editing = $editId === null ? null
        : one('SELECT * FROM software_models WHERE id = ? AND library_id = ?', [$editId, $libId]);

    render('taxonomy/software_models', [
        'pageTitle'   => 'Software models',
        'models'      => software_models($libId),
        'platforms'   => array_values(array_filter(all_platforms(),
                            fn($p) => (int) ($p['library_id'] ?? 0) === $libId)),
        // Software branches only: a release is filed under Games or Applications, never
        // under Peripherals.
        'categories'  => array_values(array_filter(filing_options('software', $libId ?: null),
                            fn($c) => ($c['domain'] ?? '') === 'software')),
        'editing'     => $editing,
        'fields'      => $editing === null ? [] : software_model_fields((int) $editing['id']),
        'contents'    => $editing === null ? [] : software_model_contents((int) $editing['id']),
        // What it comes on: a row per medium, in the order somebody put them.
        'media'       => $editing === null ? [] : all(
            'SELECT * FROM software_model_media WHERE model_id = ? ORDER BY sort_order, id',
            [(int) $editing['id']]
        ),
        'libraryHere' => $libId,
    ]);
}

function software_models_save(): void
{
    require_admin();
    csrf_verify();

    $lib   = working_library();
    $libId = $lib === null ? 0 : (int) $lib['id'];
    $id    = (int) (input_int('id') ?? 0);

    if (input('action') === 'delete' && $id > 0) {
        // The rows a title inherited stay behind. A model is where an answer came from,
        // not where it lives, so removing it must not quietly empty somebody's titles.
        q('DELETE FROM software_models WHERE id = ? AND library_id = ?', [$id, $libId]);
        flash('ok', 'Model removed. Titles made from it keep what it filled in.');
        redirect('/manage/software-models');
    }

    $name = trim((string) input('name', ''));
    if ($name === '') {
        form_failed('/manage/software-models', ['name' => 'Give the model a name.']);
    }

    $data = [
        'library_id'  => $libId,
        'platform_id' => input_int('platform_id') ?: null,
        'category_id' => input_int('category_id') ?: null,
        'name'        => mb_substr($name, 0, 160),
        // media and year_from are no longer written from this form. The media list is a
        // child table now, and the year belonged to the title rather than to the shape
        // of release. The columns stay so an old value is still readable.

        'notes'       => nullify($_POST['notes'] ?? null),
    ];

    if ($id > 0) {
        update_row('software_models', $id, $data);
    } else {
        $data['slug'] = category_unique_slug(null, slugify($name)) ;
        $data['slug'] = unique_slug('software_models', slugify($name));
        $id = (int) insert_row('software_models', $data);
    }

    // Replaced wholesale, like the hardware model editor: the rows on screen are the
    // model's answer, and merging them with what was there would invent a third.
    q('DELETE FROM software_model_fields WHERE model_id = ?', [$id]);
    foreach ((array) ($_POST['field_label'] ?? []) as $i => $label) {
        $label = trim((string) $label);
        if ($label === '') { continue; }
        q('INSERT IGNORE INTO software_model_fields (model_id, label, default_value, sort_order)
           VALUES (?, ?, ?, ?)',
          [$id, mb_substr($label, 0, 60),
           nullify(($_POST['field_value'][$i] ?? null)), ($i + 1) * 10]);
    }

    // The media rows, replaced wholesale like the other child lists: the form posts the
    // complete set, so working out which of them moved is work for no gain.
    q('DELETE FROM software_model_media WHERE model_id = ?', [$id]);
    $types = $_POST['media_type'] ?? [];
    $qtys  = $_POST['media_qty']  ?? [];
    if (is_array($types)) {
        $order = 0;
        foreach ($types as $i => $medium) {
            $medium = (string) $medium;
            // Only what the vocabulary offers. A select can be edited before it is sent,
            // and free text here is how a library ends up with "3.5"" and "3.5 inch".
            if (!in_array($medium, media_option_values(), true)) {
                continue;
            }
            $order += 10;
            insert_row('software_model_media', [
                'model_id'   => $id,
                'medium'     => $medium,
                'quantity'   => max(1, min(999, (int) ($qtys[$i] ?? 1))),
                'sort_order' => $order,
            ]);
        }
    }

    q('DELETE FROM software_model_contents WHERE model_id = ?', [$id]);
    foreach ((array) ($_POST['content_label'] ?? []) as $i => $label) {
        $label = trim((string) $label);
        if ($label === '') { continue; }
        q('INSERT IGNORE INTO software_model_contents (model_id, label, note, sort_order)
           VALUES (?, ?, ?, ?)',
          [$id, mb_substr($label, 0, 120),
           nullify(($_POST['content_note'][$i] ?? null)), ($i + 1) * 10]);
    }

    flash('ok', 'Saved.');
    redirect('/manage/software-models', ['edit' => $id]);
}

/*
 * Environments: what each machine can run.
 *
 * A per-platform list - MS-DOS and Windows 3.x under PC, Workbench under Amiga - that
 * the software entry form offers once a platform is chosen. It is structure like
 * platforms and categories are: per library, copied from the templates when you
 * synchronise, and editable afterwards because a collection will always meet a system
 * the structure data never heard of.
 */
function environments_index(): void
{
    require_manage();

    $mine      = working_library();
    $libraryId = $mine === null ? 0 : (int) $mine['id'];

    $editing = null;
    if (($id = input_int('edit')) !== null) {
        $editing = one('SELECT * FROM operating_systems WHERE id = ? AND library_id = ?',
                       [$id, $libraryId]);
    }

    render('taxonomy/environments', [
        'pageTitle' => 'Environments',
        'editing'   => $editing,
        'libraryId' => $libraryId,
        'platforms' => all('SELECT * FROM platforms WHERE library_id = ? ORDER BY name', [$libraryId]),
        // Grouped by machine, because that is how they are chosen and how they read.
        // Ordered by machine then name, which is what the two columns show. The
        // hand-kept sort_order is gone from the form: it existed to arrange rows inside
        // a group, and there are no groups now.
        'rows'      => all(
            'SELECT o.*, p.name AS platform_name, p.slug AS platform_slug,
                    p.accent_color AS platform_color,
                    -- item_environments, not items.os_id: software runs under several,
                    -- so this is many-to-many and the count is of links, not entries.
                    (SELECT COUNT(*) FROM item_environments e WHERE e.os_id = o.id) AS used
               FROM operating_systems o
               JOIN platforms p ON p.id = o.platform_id
              WHERE o.library_id = ?
           ORDER BY p.name, o.name',
            [$libraryId]
        ),
    ]);
}

function environments_save(): void
{
    require_manage();
    csrf_verify();

    $mine      = working_library();
    $libraryId = $mine === null ? 0 : (int) $mine['id'];
    if ($libraryId <= 0) {
        flash('error', 'Choose a library in the header first.');
        redirect('/manage/environments');
    }

    $action = (string) input('action', 'save');
    $id     = input_int('id');

    if ($action === 'delete' && $id !== null) {
        // Refused while anything is filed under it. An entry pointing at a deleted
        // environment would silently become "not applicable", which is a different
        // claim from the one somebody made.
        $used = (int) scalar('SELECT COUNT(*) FROM item_environments WHERE os_id = ?', [$id]);
        if ($used > 0) {
            flash('error', sprintf('%d entr%s still names it. Change those first.',
                                   $used, $used === 1 ? 'y' : 'ies'));
        } else {
            q('DELETE FROM operating_systems WHERE id = ? AND library_id = ?', [$id, $libraryId]);
            flash('ok', 'Environment removed.');
        }
        redirect('/manage/environments');
    }

    $name       = trim((string) input('name', ''));
    $platformId = input_int('platform_id');
    if ($name === '' || $platformId === null) {
        flash('error', 'An environment needs a name and a platform.');
        redirect('/manage/environments');
    }
    // Its own library's platform, never another's.
    if (one('SELECT id FROM platforms WHERE id = ? AND library_id = ?', [$platformId, $libraryId]) === null) {
        flash('error', 'That platform is not in this library.');
        redirect('/manage/environments');
    }

    $data = [
        'library_id'  => $libraryId,
        'platform_id' => $platformId,
        'name'        => mb_substr($name, 0, 120),
        // sort_order is not asked for any more. It arranged rows within a machine
        // heading, and the list is a flat table with a Platform column now - so the
        // order is machine then name, and there is nothing to keep by hand. Existing
        // values are left alone rather than zeroed.
    ];

    if ($id === null) {
        $data['slug'] = unique_slug('operating_systems', slugify($name));
        insert_row('operating_systems', $data);
        flash('ok', $name . ' added.');
    } else {
        update_row('operating_systems', $id, $data);
        flash('ok', $name . ' saved.');
    }
    redirect('/manage/environments');
}
