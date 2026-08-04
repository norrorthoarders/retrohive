<?php
declare(strict_types=1);

/**
 * Where things physically are.
 *
 * A tree per library, of any depth. The screen is deliberately one page with a
 * form per row rather than a wizard: you set a room up once, then come back
 * twice a year to add a shelf, and a flow optimised for the first visit makes
 * the other two annoying.
 *
 * Editing needs write access to the library, not administrator rights. It is
 * your furniture.
 */

function locations_index(): void
{
    require_edit();

    $writable = readable_libraries(ACCESS_CONTRIBUTOR);
    if ($writable === []) {
        flash('error', 'You need write access to a library before you can arrange it.');
        redirect('/');
    }

    // A slug or an id. This read input_int(), so the slug the header switcher and
    // every other Manage screen pass - ?library=the-club-shelf - parsed as null and
    // the page silently fell back to the personal shelf. It was the one screen that
    // never followed the switcher.
    $libraryId = null;
    $want      = trim((string) ($_GET['library'] ?? ''));
    if ($want !== '') {
        $hit = one('SELECT id FROM libraries WHERE slug = ? OR id = ?', [$want, (int) $want]);
        if ($hit !== null && can_add_to_library((int) $hit['id'])) {
            $libraryId = (int) $hit['id'];
        }
    }
    if ($libraryId === null) {
        $mine      = working_library();
        $libraryId = $mine !== null && can_add_to_library((int) $mine['id'])
            ? (int) $mine['id']
            : (int) $writable[0]['id'];
    }

    render('taxonomy/locations', [
        'pageTitle' => 'Locations',
        'libraries' => $writable,
        'libraryId' => $libraryId,
        'rows'      => location_tree($libraryId),
        'counts'    => location_counts($libraryId),
        'options'   => location_options($libraryId),
    ]);
}

function locations_save(): void
{
    require_edit();
    csrf_verify();

    $libraryId = (int) input_int('library_id', 0);
    if ($libraryId <= 0 || !can_add_to_library($libraryId)) {
        flash('error', 'That library is not yours to arrange.');
        redirect('/manage/locations');
    }

    // The slug, not the id. The header switcher matches its options on the slug, so
    // redirecting with ?library=3 left it with nothing to select and it fell back to
    // showing the first library - which looked like the app had switched you back to
    // your private shelf, while this page carried on editing the one you chose.
    $back   = ['library' => (string) (scalar('SELECT slug FROM libraries WHERE id = ?', [$libraryId]) ?? $libraryId)];
    $action = (string) input('action', 'save');
    $id     = input_int('id');

    // Everything below works on one row, and every one of them checks the row
    // belongs to the library that was posted - otherwise the id alone would be
    // enough to rearrange somebody else's house.
    $owned = fn(?int $rowId): ?array => $rowId === null ? null
        : one('SELECT * FROM locations WHERE id = ? AND library_id = ?', [$rowId, $libraryId]);

    if ($action === 'delete' && $id !== null) {
        $row = $owned($id);
        if ($row === null) {
            flash('error', 'No such location.');
            redirect('/manage/locations', $back);
        }
        // Refused while anything is filed here. The foreign key would have set
        // items.location_id to NULL and let it through, which is a silent way to
        // lose the answer to "where is my A500" - and the person deleting a
        // shelf is rarely the person who will go looking for it.
        //
        // Counted through the subtree, not just this row: removing a room takes
        // its cabinets with it, so a box three levels down is just as lost.
        $subtree = location_subtree_ids($id);
        $in      = implode(',', array_fill(0, count($subtree), '?'));
        $held    = (int) scalar("SELECT COUNT(*) FROM items WHERE location_id IN ($in)", $subtree);
        if ($held > 0) {
            flash('error', sprintf(
                '%d %s filed in %s or inside it. Move %s first.',
                $held, $held === 1 ? 'entry is' : 'entries are', $row['name'],
                $held === 1 ? 'it' : 'them'
            ));
            redirect('/manage/locations', $back);
        }

        $kids = (int) scalar('SELECT COUNT(*) FROM locations WHERE parent_id = ?', [$id]);
        delete_row('locations', $id);
        location_rebuild_paths();
        flash('ok', sprintf(
            'Removed %s.%s',
            $row['name'],
            $kids > 0 ? " $kids place" . ($kids === 1 ? '' : 's') . ' inside it went too.' : ''
        ));
        redirect('/manage/locations', $back);
    }

    $name = trim((string) input('name', ''));
    if ($name === '') {
        form_failed('/manage/locations', ['name' => 'Give the place a name.']);
    }

    $parentId = input_int('parent_id');
    if ($parentId !== null && $parentId > 0) {
        if ($owned($parentId) === null) {
            flash('error', 'That parent is in another library.');
            redirect('/manage/locations', $back);
        }
    } else {
        $parentId = null;
    }

    // Two places with the same name in the same place is a mistake worth
    // catching; the same name in a different place, or in somebody else's
    // library, is not.
    if (location_name_taken($libraryId, $parentId, $name, $id)) {
        $where = $parentId === null ? 'at the top level' : 'in ' . location_breadcrumb($parentId);
        flash('error', 'There is already a "' . $name . '" ' . $where . '.');
        redirect('/manage/locations', $back);
    }

    // Signed and small. 0 is the level you walk in on and anything below it is
    // negative; beyond that the number is whatever the building calls it, which
    // is why it is a number rather than a list of names. Blank means the
    // question does not apply - the usual case for a shelf in a room that has
    // one already.
    $floor = input('floor_level');
    $floor = ($floor === null || trim((string) $floor) === '') ? null : (int) $floor;
    if ($floor !== null && ($floor < -9 || $floor > 99)) {
        $floor = null;
    }

    $data = [
        'name'        => mb_substr($name, 0, 120),
        'parent_id'   => $parentId,
        'floor_level' => $floor,
        'notes'       => nullify(input('notes')),
    ];

    if ($id === null) {
        $data['library_id'] = $libraryId;
        insert_row('locations', $data);
        location_rebuild_paths();
        flash('ok', $name . ' added.');
        redirect('/manage/locations', $back);
    }

    if ($owned($id) === null) {
        flash('error', 'No such location.');
        redirect('/manage/locations', $back);
    }
    // A room cannot be inside one of its own shelves. Nothing in the schema
    // stops it, and the result is a path rebuild that never finishes.
    if (location_would_loop($id, $parentId)) {
        flash('error', 'A place cannot be inside itself, or inside something it contains.');
        redirect('/manage/locations', $back);
    }

    update_row('locations', $id, $data);
    location_rebuild_paths();
    flash('ok', 'Saved.');
    redirect('/manage/locations', $back);
}
