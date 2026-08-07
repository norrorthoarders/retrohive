<?php
declare(strict_types=1);

// The rules an entry obeys, shared by the web form and the API.
//
// Required here rather than only in public/index.php: every test suite, the
// installer and bin/*.php require the modules by hand, and a shared file that
// only one entry point loads is not shared - it is a fatal error waiting for the
// second caller.
require_once __DIR__ . '/rules.php';

/** Every platform, regardless of access. Use readable_libraries() for pickers. */
/**
 * Platforms this account can use.
 *
 * The shared ones, which everybody gets, plus any defined by a library they can
 * reach. Somebody cataloguing a machine nobody has heard of should not have to
 * ask an administrator to add it first - and their Sharp MZ-2500 has no
 * business appearing in a stranger's list either.
 *
 * $all is for administration screens, which are about the instance rather than
 * about what one person is cataloguing.
 */
function all_platforms(bool $all = false): array
{
    // Template rows - library_id NULL - are never offered. They exist to be
    // copied into a library and are not reachable until they have been: there
    // is no shared list, so a machine belongs to somebody or to nobody.
    if ($all) {
        return all('SELECT * FROM platforms WHERE library_id IS NOT NULL ORDER BY name');
    }

    $ids = accessible_library_ids(acting_user(), ACCESS_VIEWER);
    if ($ids === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return all(
        "SELECT p.*, v.name AS manufacturer
           FROM platforms p
      LEFT JOIN companies v ON v.id = p.vendor_id
          WHERE p.library_id IN ($in) ORDER BY p.name",
        $ids
    );
}

/** The manufacturers one library knows about. */
function library_vendors(?int $libraryId = null): array
{
    // Companies that make hardware. One table, asked a narrower question - the tag is
    // what used to be the choice of table.
    if ($libraryId !== null && $libraryId > 0) {
        return all("SELECT * FROM companies
                     WHERE library_id = ? AND FIND_IN_SET('hardware', makes)
                     ORDER BY name", [$libraryId]);
    }
    $ids = accessible_library_ids(acting_user(), ACCESS_VIEWER);
    if ($ids === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return all("SELECT * FROM companies
                 WHERE library_id IN ($in) AND FIND_IN_SET('hardware', makes)
                 ORDER BY name", $ids);
}

/**
 * Give a new library the seeded machines and makers.
 *
 * Copied rather than shared, so somebody renaming their Amiga does not rename
 * everybody's. The makers go first because the platforms point at them.
 */
/**
 * Copy the template structure into a library.
 *
 * Additive by default: every pass below matches on slug and skips what is already here,
 * so a maker whose country you corrected and a model whose specifications you fixed
 * survive a resync. `$overwrite` runs a second pass that copies the template's values
 * over the top - a deliberate choice on the form, never a side effect of syncing.
 */
/**
 * Which parts of the starter set a library wants.
 *
 * Everything, unless asked otherwise - so every existing caller keeps its behaviour.
 * The keys are the things a person would tick, not the tables they happen to live in:
 * "makers and platforms" is one decision, "the category trees" is another, and
 * somebody who wants a filing tree without a hundred and fifty companies should be
 * able to say so.
 */
function seed_parts_all(): array
{
    return ['makers' => true, 'credit_roles' => true, 'platforms' => true, 'categories' => true,
            'hardware_models' => true, 'software_models' => true,
            'environments' => true, 'locations' => true];
}

function seed_library_hardware(int $libraryId, bool $overwrite = false, ?array $parts = null): int
{
    $parts = $parts === null ? seed_parts_all() : ($parts + array_fill_keys(array_keys(seed_parts_all()), false));

    // Dependencies, resolved rather than refused.
    //
    // A platform without its maker has a null vendor_id for ever; a model has to be
    // filed under a category that exists; the trees are rooted at platforms. Asking for
    // a part without its foundation is asking for something incoherent, so the
    // foundation comes too - quietly, because the alternative is an error message about
    // an implementation detail.
    if (!empty($parts['platforms']))        { $parts['makers'] = true; }
    if (!empty($parts['categories']))       { $parts['platforms'] = true; $parts['makers'] = true; }
    if (!empty($parts['hardware_models']))  { $parts['categories'] = true; $parts['platforms'] = true; $parts['makers'] = true; }
    if (!empty($parts['software_models']))  { $parts['categories'] = true; $parts['platforms'] = true; $parts['makers'] = true; }
    if (!empty($parts['environments']))     { $parts['platforms'] = true; $parts['makers'] = true; }

    // Additive, not once-only. It used to bail if the library had any platform
    // at all, which meant a library made before a template existed never got it
    // - and a library made before manufacturers were copied at all had none for
    // ever, with no way to ask for them. Anything already there by slug is left
    // alone, including a name somebody has since corrected.
    // Every column except the logo. logo_filename names a file in public/uploads,
    // and pointing sixty library rows at one file means removing any of them takes
    // the badge off all the others.
    if (!empty($parts['makers'])) {
    q('INSERT INTO companies
           (library_id, makes, name, slug, country, founded_year, defunct_year,
            website, wikipedia_url, notes)
       SELECT ?, t.makes, t.name, t.slug, t.country, t.founded_year, t.defunct_year,
              t.website, t.wikipedia_url, t.notes
         FROM companies t
    LEFT JOIN companies mine ON mine.library_id = ? AND mine.slug = t.slug
        WHERE t.library_id IS NULL AND mine.id IS NULL',
      [$libraryId, $libraryId]);

    }

    if (!empty($parts['credit_roles'])) {
    q('INSERT INTO credit_roles
           (library_id, name, slug, domains, sort_order)
       SELECT ?, t.name, t.slug, t.domains, t.sort_order
         FROM credit_roles t
    LEFT JOIN credit_roles mine ON mine.library_id = ? AND mine.slug = t.slug
        WHERE t.library_id IS NULL AND mine.id IS NULL',
      [$libraryId, $libraryId]);

    }

    if (!empty($parts['platforms'])) {
    // The join maps each template maker to the copy just made for this library.
    q('INSERT INTO platforms
           (library_id, name, slug, vendor_id, year_introduced, accent_color, domains)
       SELECT ?, t.name, t.slug, mine.id, t.year_introduced, t.accent_color, t.domains
         FROM platforms t
    LEFT JOIN companies   tv   ON tv.id = t.vendor_id
    LEFT JOIN companies   mine ON mine.library_id = ? AND mine.slug = tv.slug
    LEFT JOIN platforms have ON have.library_id = ? AND have.slug = t.slug
        WHERE t.library_id IS NULL AND have.id IS NULL',
      [$libraryId, $libraryId, $libraryId]);

    // A platform that arrived before its maker did has a null vendor_id. Once
    // the maker exists, join them up rather than leaving the gap for ever.
    q('UPDATE platforms p
         JOIN platforms t  ON t.library_id IS NULL AND t.slug = p.slug
         JOIN companies   tv ON tv.id = t.vendor_id
         JOIN companies   mv ON mv.library_id = p.library_id AND mv.slug = tv.slug
          SET p.vendor_id = mv.id
        WHERE p.library_id = ? AND p.vendor_id IS NULL',
      [$libraryId]);

    // The interface vocabulary is per platform, so copying platforms without it
    // leaves every new library unable to say what a card plugs into. The
    // sentinel-0 rows are "applies anywhere" and are shared by everybody.
    q("INSERT IGNORE INTO hardware_vocab (kind, platform_id, code, name, sort_order)
       SELECT hv.kind, mine.id, hv.code, hv.name, hv.sort_order
         FROM hardware_vocab hv
         JOIN platforms t    ON t.id = hv.platform_id AND t.library_id IS NULL
         JOIN platforms mine ON mine.library_id = ? AND mine.slug = t.slug",
      [$libraryId]);

    // --- The taxonomy, rooted at the machines --------------------------------
    //
    // One tree per platform: Amiga > Hardware > Peripherals > Adapters, and Amiga >
    // Software > Games > Racing. Real rows, not a view - so a Game Boy can simply not
    // have Network adapters, which is the whole point of doing it this way rather than
    // drawing one shared taxonomy under every machine.
    }

    if (!empty($parts['categories'])) {
        seed_library_categories($libraryId);
    }
    // Only alongside the trees. It guarantees somewhere for software to be filed, which
    // matters when a library has a taxonomy - and left ungated it planted one lone
    // category into libraries that had asked for makers and nothing else.
    if (!empty($parts['categories'])) {
        ensure_games_category($libraryId);
    }

    // The environments each machine can run, matched to this library's platforms by
    // slug like everything else. Without this the entry form's Environment select is
    // empty: it lists what the *library's* platform offers, and the seeded rows all
    // belonged to the templates.
    if (!empty($parts['environments'])) {
    q("INSERT INTO operating_systems (library_id, platform_id, name, slug, sort_order)
       SELECT ?, mp.id, t.name, t.slug, t.sort_order
         FROM operating_systems t
         JOIN platforms tp ON tp.id = t.platform_id AND tp.library_id IS NULL
         JOIN platforms mp ON mp.library_id = ? AND mp.slug = tp.slug
    LEFT JOIN operating_systems mine
           ON mine.library_id = ? AND mine.slug = t.slug
        WHERE t.library_id IS NULL AND mine.id IS NULL", [$libraryId, $libraryId, $libraryId]);

    }

    if (!empty($parts['locations'])) {
        // Somewhere to put things. Structure, not examples.
        seed_library_locations($libraryId);
    }

    // --- Software models -----------------------------------------------------
    //
    // Same shape as the hardware copy above: the model first, then its fields and box
    // contents remapped onto the new ids. Additive by slug.
    if (!empty($parts['software_models'])) {
    q('INSERT INTO software_models
           (library_id, platform_id, category_id, publisher_id, name, slug, media,
            year_from, notes, sort_order)
       SELECT ?, mp.id, mc.id, mpub.id, t.name, t.slug, t.media,
              t.year_from, t.notes, t.sort_order
         FROM software_models t
    LEFT JOIN platforms  tp   ON tp.id = t.platform_id
    LEFT JOIN platforms  mp   ON mp.library_id = ? AND mp.slug = tp.slug
    LEFT JOIN categories tc   ON tc.id = t.category_id
    LEFT JOIN categories mc   ON mc.library_id = ? AND mc.slug = CONCAT(tp.slug, \'-\', tc.slug)
    LEFT JOIN companies  tpub ON tpub.id = t.publisher_id
    LEFT JOIN companies  mpub ON mpub.library_id = ? AND mpub.slug = tpub.slug
    LEFT JOIN software_models mine ON mine.library_id = ? AND mine.slug = t.slug
        WHERE t.library_id IS NULL AND mine.id IS NULL',
      [$libraryId, $libraryId, $libraryId, $libraryId, $libraryId]);

    q('INSERT IGNORE INTO software_model_fields (model_id, label, default_value, hint, sort_order)
       SELECT mine.id, f.label, f.default_value, f.hint, f.sort_order
         FROM software_model_fields f
         JOIN software_models t    ON t.id = f.model_id AND t.library_id IS NULL
         JOIN software_models mine ON mine.library_id = ? AND mine.slug = t.slug',
      [$libraryId]);

    q('INSERT IGNORE INTO software_model_contents (model_id, label, note, sort_order)
       SELECT mine.id, c.label, c.note, c.sort_order
         FROM software_model_contents c
         JOIN software_models t    ON t.id = c.model_id AND t.library_id IS NULL
         JOIN software_models mine ON mine.library_id = ? AND mine.slug = t.slug',
      [$libraryId]);

    }
    if ($overwrite) {
        library_overwrite_from_templates($libraryId);
    }

    if (!empty($parts['hardware_models'])) {
    // --- Models, and everything hanging off them ---------------------------
    //
    // Copied in the same additive spirit: anything already here by slug is left
    // alone, including a name somebody has corrected. Four tables, in dependency
    // order, and the last one is the interesting case.
    //
    // The ids cannot be reused, so every child row has to be remapped from the
    // template model to this library's copy. model_compatibility is remapped on *both*
    // sides - it says "this card fits that machine", and both ends have to end up
    // pointing at the library's own rows or a private shelf would hold a card
    // whose compatibility list reaches into the template set.
    q('INSERT INTO hardware_models
           (library_id, vendor_id, platform_id, category_id, name, slug, year_from,
            fits_note, interface, interface_vocab_id, notes, sort_order)
       SELECT ?, mv.id, mp.id, COALESCE(mc.id, t.category_id), t.name, t.slug, t.year_from,
              t.fits_note, t.interface, mvocab.id, t.notes, t.sort_order
         FROM hardware_models t
    LEFT JOIN categories tc ON tc.id = t.category_id
    LEFT JOIN platforms  tpc ON tpc.id = t.platform_id
    LEFT JOIN categories mc
           ON mc.library_id = ?
          AND mc.slug = CONCAT(tpc.slug, \'-\', tc.slug)
    LEFT JOIN companies    tv ON tv.id = t.vendor_id
    LEFT JOIN companies    mv ON mv.library_id = ? AND mv.slug = tv.slug
    LEFT JOIN platforms  tp ON tp.id = t.platform_id
    LEFT JOIN platforms  mp ON mp.library_id = ? AND mp.slug = tp.slug
    LEFT JOIN hardware_vocab tvocab ON tvocab.id = t.interface_vocab_id
    LEFT JOIN hardware_vocab mvocab
           ON mvocab.code = tvocab.code AND mvocab.kind = tvocab.kind
          AND mvocab.platform_id = mp.id
    LEFT JOIN hardware_models mine ON mine.library_id = ? AND mine.slug = t.slug
        WHERE t.library_id IS NULL AND mine.id IS NULL',
      // Five: the inserted library_id, then mc, mv, mp and mine.
      [$libraryId, $libraryId, $libraryId, $libraryId, $libraryId]);

    // The rows a model suggests on the entry form.
    q('INSERT INTO model_fields (model_id, label, default_value, hint, sort_order)
       SELECT mine.id, f.label, f.default_value, f.hint, f.sort_order
         FROM model_fields f
         JOIN hardware_models t    ON t.id = f.model_id AND t.library_id IS NULL
         JOIN hardware_models mine ON mine.library_id = ? AND mine.slug = t.slug
    LEFT JOIN model_fields have
           ON have.model_id = mine.id AND have.label = f.label
        WHERE have.id IS NULL',
      [$libraryId]);

    // What a machine physically has. The vocabulary is per platform, so the slot
    // has to be matched by code within this library's copy of the platform.
    q('INSERT INTO model_slots (model_id, vocab_id, quantity, notes)
       SELECT mine.id, mvocab.id, sl.quantity, sl.notes
         FROM model_slots sl
         JOIN hardware_models t    ON t.id = sl.model_id AND t.library_id IS NULL
         JOIN hardware_models mine ON mine.library_id = ? AND mine.slug = t.slug
         JOIN hardware_vocab tvocab ON tvocab.id = sl.vocab_id
         JOIN platforms mp ON mp.id = mine.platform_id
         JOIN hardware_vocab mvocab
              ON mvocab.code = tvocab.code AND mvocab.kind = tvocab.kind
             AND mvocab.platform_id = mp.id
    LEFT JOIN model_slots have ON have.model_id = mine.id AND have.vocab_id = mvocab.id
        WHERE have.id IS NULL',
      [$libraryId]);

    // Both ends remapped, as above.
    q("INSERT IGNORE INTO model_compatibility (model_id, compatible_model_id)
       SELECT mineCard.id, mineMach.id
         FROM model_compatibility mf
         JOIN hardware_models tCard ON tCard.id = mf.model_id      AND tCard.library_id IS NULL
         JOIN hardware_models tMach ON tMach.id = mf.compatible_model_id AND tMach.library_id IS NULL
         JOIN hardware_models mineCard ON mineCard.library_id = ? AND mineCard.slug = tCard.slug
         JOIN hardware_models mineMach ON mineMach.library_id = ? AND mineMach.slug = tMach.slug",
      [$libraryId, $libraryId]);
    }

    // The shipped tree says what it holds, rather than leaving the browsers to
    // guess from branch names.
    category_declare_kinds($libraryId);

    // And the sources those branches are worth asking. After the kinds, because
    // it is the kinds it reads.
    seed_library_provider_scopes($libraryId);

    return (int) scalar('SELECT COUNT(*) FROM platforms WHERE library_id = ?', [$libraryId]);
}

/** Platforms one library defined for itself. */
function library_platforms(int $libraryId): array
{
    // v.name AS manufacturer, because the templates that render these rows read
    // that alias. Without the join it is an undefined index and the maker column
    // is permanently a dash.
    return all(
        'SELECT p.*, v.name AS manufacturer
           FROM platforms p
      LEFT JOIN companies v ON v.id = p.vendor_id
          WHERE p.library_id = ? ORDER BY p.name',
        [$libraryId]
    );
}

/**
 * May this account change this platform?
 *
 * A shared one belongs to the instance, so only an administrator touches it.
 * One a library defined is that library's, and its owner decides.
 */
function can_edit_platform(array $platform): bool
{
    // acting_user(), not is_admin(): the latter reads the session, and every
    // other access question in the system is asked of the acting user. They are
    // the same person in a web request and different in a token request, a
    // console script or a test - and a permission check that changes its mind
    // depending on how it was reached is not one.
    $user  = acting_user();
    $admin = $user !== null && ($user['role'] ?? '') === 'admin';

    if ($platform['library_id'] === null) {
        return $admin;
    }
    return $admin || can_own_library((int) $platform['library_id']);
}

/**
 * Recompute the materialised path on every category.
 *
 * Four levels, which is what the tree allows. Cheap enough to do wholesale
 * rather than work out which rows moved.
 */
function rebuild_category_paths(?int $libraryId = null): void
{
    // One library when named, everything otherwise. Rebuilding the whole table after
    // copying a single library's worth is correct but wasteful, and on an instance with
    // several libraries it rewrites rows nobody touched.
    $where = $libraryId === null ? '' : ' AND library_id <=> ?';
    $args  = $libraryId === null ? [] : [$libraryId];

    q("UPDATE categories SET path = CONCAT('/', id, '/'), depth = 0
        WHERE parent_id IS NULL" . $where, $args);

    for ($level = 1; $level <= 4; $level++) {
        q("UPDATE categories c JOIN categories p ON p.id = c.parent_id
              SET c.path = CONCAT(p.path, c.id, '/'), c.depth = p.depth + 1
            WHERE p.depth = ?"
          . ($libraryId === null ? '' : ' AND c.library_id <=> ?'),
          $libraryId === null ? [$level - 1] : [$level - 1, $libraryId]);
    }
}

function all_categories(?int $libraryId = null): array
{
    // One library's, or every library this account can reach. Never the template rows -
    // those exist to be copied and are filed under nothing.
    //
    // Ordered by sort_order first, name second - the same ordering the real
    // tree editor's own query already uses. This read alphabetically only
    // until now, which meant the column existed and was silently ignored:
    // sort_order changed nothing about what anyone actually saw, and a
    // reordering feature built on top of that would have had no visible
    // effect regardless of how correctly it wrote the numbers.
    if ($libraryId !== null && $libraryId > 0) {
        return all('SELECT c.*, s.slug AS domain FROM categories c JOIN sections s ON s.id = c.section_id
                     WHERE c.library_id = ? ORDER BY c.sort_order, c.name', [$libraryId]);
    }
    $reach = accessible_library_ids(acting_user(), ACCESS_VIEWER);
    if ($reach === []) {
        return [];
    }
    return all('SELECT c.*, s.slug AS domain FROM categories c JOIN sections s ON s.id = c.section_id
                 WHERE c.library_id IN ('
             . implode(',', array_fill(0, count($reach), '?')) . ') ORDER BY c.sort_order, c.name', $reach);
}


function all_companies(?string $makes = null, ?int $libraryId = null): array
{
    // One library's companies, not every library's.
    //
    // This selected the whole table - the templates plus each library's copy of them -
    // so on an instance with three shelves every studio appeared four times in the
    // developer picker. Companies became per-library when the vendors and companies
    // tables were merged; this was the last reader still treating them as global.
    //
    // Defaults to the shelf being worked in, like every other picker.
    if ($libraryId === null) {
        $lib       = working_library();
        $libraryId = $lib === null ? 0 : (int) $lib['id'];
    }

    // One side of the shop when asked. A developer picker wants the software makers, a
    // manufacturer picker the hardware ones, and a firm that does both is correctly in
    // each - which is the point of merging the tables.
    if ($makes === 'hardware' || $makes === 'software') {
        return all("SELECT * FROM companies
                     WHERE library_id = ? AND FIND_IN_SET(?, makes)
                     ORDER BY name", [$libraryId, $makes]);
    }
    return all('SELECT * FROM companies WHERE library_id = ? ORDER BY name', [$libraryId]);
}

/** A library's people - directors, artists, authors - the same per-library shape companies already settled on. */
function all_people(?int $libraryId = null): array
{
    if ($libraryId === null) {
        $lib       = working_library();
        $libraryId = $lib === null ? 0 : (int) $lib['id'];
    }
    return all('SELECT * FROM people WHERE library_id = ? ORDER BY name', [$libraryId]);
}

/** A library's credit roles, optionally narrowed to the domain(s) that matter for a given title. */
function all_credit_roles(?int $libraryId = null, ?string $domain = null): array
{
    if ($libraryId === null) {
        $lib       = working_library();
        $libraryId = $lib === null ? 0 : (int) $lib['id'];
    }
    if ($domain !== null) {
        return all("SELECT * FROM credit_roles
                     WHERE library_id = ? AND FIND_IN_SET(?, domains)
                     ORDER BY sort_order, name", [$libraryId, $domain]);
    }
    return all('SELECT * FROM credit_roles WHERE library_id = ? ORDER BY sort_order, name', [$libraryId]);
}

function all_tags(): array
{
    return all('SELECT * FROM tags ORDER BY name');
}

/**
 * Look up a company by name, creating it if it is new.
 * Lets the item form take a plain typed name instead of forcing a two-step flow.
 */
/**
 * The company a typed or imported name means, in the library it is being used in.
 *
 * Two faults, both of the same kind and both invisible until somebody clicked
 * the studio's name on an entry:
 *
 * - the lookup was by name across every row, so typing "Llamasoft" could bind
 *   the *template* row - the one with library_id NULL that entries never point
 *   at and whose page reports nothing filed under it;
 * - a name nobody had used before was created with no library_id at all, which
 *   made another row of exactly that kind.
 *
 * So: the working library's own row first. Then a template row of that name,
 * which is *copied into* the library rather than pointed at - the structure data
 * knows the country and the website, and throwing that away to make an empty
 * row would be worse. Then a new row in the library.
 *
 * @param string $makes 'software' or 'hardware', for a row this has to create
 */
function company_id_for_name(?string $name, string $makes = 'software'): ?int
{
    $name = trim((string) $name);
    if ($name === '') {
        return null;
    }
    $makes = in_array($makes, ['hardware', 'video', 'audio'], true) ? $makes : 'software';

    $lib = working_library();
    $libId = $lib === null ? null : (int) $lib['id'];

    if ($libId !== null) {
        $mine = one('SELECT id, makes FROM companies WHERE name = ? AND library_id = ? LIMIT 1',
                    [$name, $libId]);
        if ($mine !== null) {
            // A studio that made games and now turns up on a machine is one row
            // that does both, not two rows with the same name.
            if (!in_array($makes, explode(',', (string) $mine['makes']), true)) {
                update_row('companies', (int) $mine['id'],
                           ['makes' => (string) $mine['makes'] . ',' . $makes]);
            }
            return (int) $mine['id'];
        }
    }

    $template = one('SELECT * FROM companies WHERE name = ? AND library_id IS NULL LIMIT 1', [$name]);

    if ($libId === null) {
        // No library in hand - an import run from the command line, or a test.
        // The template is the right answer here rather than a new orphan row.
        return $template === null ? null : (int) $template['id'];
    }

    $fields = [
        'library_id' => $libId,
        'name'       => mb_substr($name, 0, 160),
        'slug'       => unique_slug('companies', slugify($name)),
        'makes'      => $makes,
    ];
    if ($template !== null) {
        // The template's own slug, not a fresh one.
        //
        // /developers/team17 has to reach the row the entries point at, and a
        // copy given `team17-2` left that address resolving to the template -
        // which is the very page that says "nothing filed under this name yet".
        // The seeder's own copies keep the slug for the same reason, so this is
        // matching what the rest of the application already does rather than
        // inventing a rule.
        $fields['slug'] = (string) $template['slug'];
        // Copied, not referenced: what the structure data knows about the firm
        // comes with it.
        foreach (['makes', 'domain', 'country', 'founded', 'website', 'wikipedia_url', 'notes'] as $carry) {
            if (array_key_exists($carry, $template) && $template[$carry] !== null && $template[$carry] !== '') {
                $fields[$carry] = $template[$carry];
            }
        }
        if (!in_array($makes, explode(',', (string) $fields['makes']), true)) {
            $fields['makes'] = (string) $fields['makes'] . ',' . $makes;
        }
    }
    return insert_row('companies', $fields);
}

/**
 * Find-or-create by name, the same reasoning company_id_for_name()
 * already settled on, without that function's template-copying half -
 * people carry no seeded structure data the way companies do, so
 * there is nothing to copy from, only a name to match or create.
 * $libraryId explicit rather than read from the session's own working
 * library: this is called from metadata apply, which already knows
 * the exact library the item it is crediting belongs to, and that is
 * not always the same thing as whichever library the session happens
 * to be working in at the moment.
 */
function person_id_for_name(?string $name, int $libraryId): ?int
{
    $name = trim((string) $name);
    if ($name === '' || $libraryId <= 0) {
        return null;
    }

    $existing = one('SELECT id FROM people WHERE name = ? AND library_id = ? LIMIT 1', [$name, $libraryId]);
    if ($existing !== null) {
        return (int) $existing['id'];
    }

    return insert_row('people', [
        'library_id' => $libraryId,
        'name'       => mb_substr($name, 0, 160),
        'slug'       => unique_slug('people', slugify($name)),
    ]);
}

function find_item(int $id): ?array
{
    return one('SELECT * FROM v_items WHERE id = ?', [$id]);
}

/**
 * An entry's pictures.
 *
 * Approved ones only, unless the caller says otherwise. This is what every
 * gallery, every cover and every API read goes through, so defaulting to
 * approved is what makes "a pending picture is not shown" true everywhere at
 * once rather than in each of the places somebody remembered.
 *
 * A library that has never switched approval on has no pending rows at all - the
 * column defaults to approved - so this changes nothing for almost every
 * instance and costs one comparison on an already-indexed column.
 */
function item_images(int $itemId, bool $includePending = false): array
{
    $where = $includePending ? '' : " AND approval_state = 'approved'";
    return all("SELECT * FROM item_images WHERE item_id = ?$where
                 ORDER BY is_primary DESC, sort_order, id", [$itemId]);
}

/** What is waiting on one library, newest first. */
function library_pending_images(int $libraryId): array
{
    return all("SELECT img.*, i.title AS item_title, i.library_id, u.display_name, u.username
                  FROM item_images img
                  JOIN items i ON i.id = img.item_id AND i.deleted_at IS NULL
             LEFT JOIN users u ON u.id = img.uploaded_by
                 WHERE img.approval_state = 'pending' AND i.library_id = ?
              ORDER BY img.created_at DESC, img.id DESC", [$libraryId]);
}

function item_tags(int $itemId): array
{
    return all('SELECT t.* FROM tags t JOIN item_tags it ON it.tag_id = t.id WHERE it.item_id = ? ORDER BY t.name', [$itemId]);
}

function sync_item_tags(int $itemId, string $csv): void
{
    q('DELETE FROM item_tags WHERE item_id = ?', [$itemId]);
    foreach (array_filter(array_map('trim', explode(',', $csv))) as $name) {
        $slug = slugify($name);
        $tag = one('SELECT id FROM tags WHERE slug = ?', [$slug]);
        $tagId = $tag !== null
            ? (int) $tag['id']
            : insert_row('tags', ['name' => mb_substr($name, 0, 80), 'slug' => $slug]);
        q('INSERT IGNORE INTO item_tags (item_id, tag_id) VALUES (?, ?)', [$itemId, $tagId]);
    }
}

/**
 * Build the WHERE clause for the browse page from query-string filters.
 * Returns [sqlFragment, params, activeFilters].
 */
function build_item_filters(array $qs): array
{

    $where  = [];
    $params = [];
    $active = [];

    // Access control first: nothing below can widen what this comes back with.
    // Access is decided by library membership now; the platform is only the
    // machine an entry runs on and grants nothing by itself.
    [$aclSql, $aclParams] = library_filter_sql('library_id', ACCESS_VIEWER);
    $where[] = $aclSql;


    $params  = array_merge($params, $aclParams);

    // Machines or peripherals, on the hardware browser.
    //
    // After the ACL parameters are merged, not before. Placeholders are bound in
    // the order they appear in the statement, and adding a value ahead of the
    // ACL's while the clause sits behind it bound "machine" to the library test
    // and the library id to the role - which matched nothing and looked like an
    // empty shelf rather than a bug.
    //
    // The role lives on the category and the browse view carries it now, so this
    // is a column test rather than a query per row. 'other' is never asked for:
    // a branch with no role set is neither, and hiding those would hide entries
    // somebody then cannot find.
    $role = trim((string) ($qs['kind'] ?? ''));
    // movie and tv_show join machine and peripheral: all four are real
    // category_role values, so all four are the same column test. Video needed
    // this for the same reason hardware did - Movies and TV Shows are two
    // different things to browse and were sharing one list.
    if (in_array($role, ['machine', 'peripheral', 'movie', 'tv_show', 'music'], true)) {
        $where[]  = 'category_role = ?';
        $params[] = $role;
        $active['kind'] = $role;
    } elseif (in_array($role, ['game', 'software'], true)) {
        // Software has no role of its own, so this asks the same question the
        // Kind column answers: is any branch above it the Games one.
        //
        // Done as a subquery against the ancestry rather than in PHP, because a
        // filter has to narrow the count and the pagination too - filtering the
        // page after it was fetched would say "40 entries" and show nine.
        $gameSql = '(SELECT COUNT(*) FROM categories anc
                      WHERE LOCATE(CONCAT("/", anc.id, "/"), v_items.category_path) > 0
                        AND LOWER(anc.name) = "games") > 0';
        $where[] = $role === 'game' ? $gameSql : 'NOT ' . $gameSql;
        $active['kind'] = $role;
    }
    // Software, hardware, video and music are all browsed separately. Each
    // is genuinely a different thing to catalogue - a game wants a genre, a
    // machine wants an interface and a revision, a film wants a director -
    // and a shared list leaves most of the columns blank on every row.
    if (!empty($qs['domain']) && in_array($qs['domain'], ['software', 'hardware', 'video', 'audio'], true)) {
        $where[]  = 'domain = ?';
        $params[] = (string) $qs['domain'];
        $active['domain'] = (string) $qs['domain'];
    }

    // The libraries page links here with ?library=slug, so the filter has to
    // exist or that link quietly shows everything instead of one shelf.
    if (!empty($qs['library'])) {
        $lib = one('SELECT id, name FROM libraries WHERE slug = ?', [trim((string) $qs['library'])]);
        if ($lib !== null) {
            $where[]  = 'library_id = ?';
            $params[] = (int) $lib['id'];
            $active['library'] = (string) $lib['name'];
        }
    }


    if (!empty($qs['q'])) {
        $raw = trim((string) $qs['q']);

        // Prose goes through the FULLTEXT index on items; the identifier-ish
        // columns keep their own. This used to be LIKE '%term%' across seven
        // columns, which no index can serve at all.
        //
        // Company names are not in the index because they live in another
        // table, so they stay a LIKE - against an indexed column, and only
        // after the fulltext clause has already narrowed the set.
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $raw) . '%';
        // The column list has to match the index exactly, or MySQL refuses with
        // "Can't find FULLTEXT index matching the column list" - so widening the
        // index without widening this broke every search, which is what the web
        // suite caught.
        $where[] = '(MATCH (title, subtitle, notes, description) AGAINST (? IN BOOLEAN MODE)'
                 . ' OR title LIKE ? OR developer_name LIKE ? OR publisher_name LIKE ?'
                 . ' OR catalog_number = ? OR barcode = ?)';
        array_push($params, fulltext_query($raw), $like, $like, $like, $raw, $raw);
        $active['q'] = $raw;
    }
    // ?category= matches the branch, not just the leaf.
    //
    // An entry is filed at a leaf - "Amiga > Software > Games > Racing" - so an exact
    // match on the slug meant filtering by Games returned nothing at all, because
    // nothing is filed directly under Games. The tree is there to be asked questions
    // like "everything under Games", and path is a chain of ids, so a subtree is a
    // prefix comparison rather than a walk.
    if (!empty($qs['category'])) {
        $node = one('SELECT id, path FROM categories WHERE slug = ? LIMIT 1', [$qs['category']]);
        if ($node === null) {
            $where[] = '1 = 0';   // a slug nobody has: an empty result, not every result
        } else {
            $where[] = 'category_id IN (SELECT id FROM categories WHERE path LIKE ?)';
            $params[] = (string) $node['path'] . '%';
        }
        $active['category'] = $qs['category'];
    }

    // A genre, across every platform that has one.
    //
    // `category` names one node in one platform's tree, which is right for
    // hardware and software where the platform is most of the point - an Amiga
    // graphics card is a different thing from a PC one. It is wrong for films
    // and records: the tree is copied per platform, so Action exists once per
    // format and asking for "action films" through `category` means asking for
    // action films *on Blu-ray*, with the DVD and VHS copies filed under
    // different rows of the same name.
    //
    // This asks by the template branch instead - `source_slug`, which every
    // copy carries and which is indexed - so one choice covers every format.
    // Narrowing to one of them is what the platform filter is for, and the two
    // combine.
    if (!empty($qs['genre'])) {
        $nodes = all('SELECT path FROM categories
                       WHERE source_slug = ? AND library_id IS NOT NULL',
                     [(string) $qs['genre']]);
        if ($nodes === []) {
            $where[] = '1 = 0';
        } else {
            // Everything at or beneath each copy, so Adventure also finds the
            // Point and click filed under it.
            $where[] = 'category_id IN (SELECT id FROM categories WHERE '
                     . implode(' OR ', array_fill(0, count($nodes), 'path LIKE ?')) . ')';
            foreach ($nodes as $n) {
                $params[] = (string) $n['path'] . '%';
            }
        }
        $active['genre'] = $qs['genre'];
    }

    foreach (['platform' => 'platform_slug', 'developer' => 'developer_slug'] as $key => $col) {
        if (!empty($qs[$key])) {
            $where[] = "$col = ?";
            $params[] = $qs[$key];
            $active[$key] = $qs[$key];
        }
    }
    if (!empty($qs['year'])) {
        $where[] = 'release_year = ?';
        $params[] = (int) $qs['year'];
        $active['year'] = (int) $qs['year'];
    }
    if (!empty($qs['decade'])) {
        $d = (int) $qs['decade'];
        $where[] = 'release_year BETWEEN ? AND ?';
        array_push($params, $d, $d + 9);
        $active['decade'] = $d;
    }
    if (!empty($qs['min_rating'])) {
        $where[] = 'rating >= ?';
        $params[] = (int) $qs['min_rating'];
        $active['min_rating'] = (int) $qs['min_rating'];
    }
    if (!empty($qs['condition'])) {
        $where[] = 'condition_grade = ?';
        $params[] = $qs['condition'];
        $active['condition'] = $qs['condition'];
    }
    if (!empty($qs['barcode'])) {
        $where[] = 'barcode = ?';
        $params[] = trim((string) $qs['barcode']);
        $active['barcode'] = trim((string) $qs['barcode']);
    }

    // Everything on one shelf, and on everything below it.
    if (!empty($qs['location'])) {
        $ids = location_subtree_ids((int) $qs['location']);
        if ($ids !== []) {
            $where[] = 'location_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params  = array_merge($params, $ids);
            $active['location'] = location_breadcrumb((int) $qs['location']);
        }
    }

    // Every copy of one title, or every release of one work across platforms.
    if (!empty($qs['title_id'])) {
        $where[]  = 'title_id = ?';
        $params[] = (int) $qs['title_id'];
        $active['title_id'] = (int) $qs['title_id'];
    }
    if (!empty($qs['work'])) {
        $where[]  = 'title_work_key = ?';
        $params[] = trim((string) $qs['work']);
        $active['work'] = trim((string) $qs['work']);
    }

    // Tags: several may be given, comma separated, and all must match.
    if (!empty($qs['tag'])) {
        $tags = is_array($qs['tag']) ? $qs['tag'] : explode(',', (string) $qs['tag']);
        $slugs = [];
        foreach ($tags as $t) {
            $slug = slugify((string) $t);
            if ($slug !== '') {
                // FIND_IN_SET over a GROUP_CONCAT forced a full scan of the
                // view no matter what else was in the WHERE. This uses the
                // index on tags.slug and the primary key on item_tags.
                $where[]  = 'EXISTS (SELECT 1 FROM item_tags _it
                                       JOIN tags _t ON _t.id = _it.tag_id
                                      WHERE _it.item_id = v_items.id AND _t.slug = ?)';
                $params[] = $slug;
                $slugs[]  = $slug;
            }
        }
        if ($slugs !== []) {
            $active['tag'] = implode(',', $slugs);
        }
    }

    if (($qs['photos'] ?? '') === 'none') {
        $where[] = 'image_count = 0';
        $active['photos'] = 'none';
    } elseif (($qs['photos'] ?? '') === 'some') {
        $where[] = 'image_count > 0';
        $active['photos'] = 'some';
    }

    // Status. Without one, show what you actually have on the shelf: owned,
    // lent out and on order, but not wanted or already sold.
    $status = (string) ($qs['status'] ?? (($qs['list'] ?? '') === 'wishlist' ? 'wishlist' : ''));
    if ($status === 'all') {
        $active['status'] = 'all';
    } elseif ($status !== '' && in_array($status, status_options(), true)) {
        $where[]  = 'status = ?';
        $params[] = $status;
        $active['status'] = $status;
        if ($status === 'wishlist') {
            $active['list'] = 'wishlist';
        }
    } else {
        $where[] = "status IN ('owned','ordered')";
    }

    $sql = implode(' AND ', $where);
    return [$sql, $params, $active];
}

function item_sort_clause(?string $sort): string
{
    return match ($sort) {
        'title_desc'  => 'COALESCE(sort_title, title) DESC',
        'year'        => 'release_year IS NULL, release_year ASC, COALESCE(sort_title, title)',
        'year_desc'   => 'release_year IS NULL, release_year DESC, COALESCE(sort_title, title)',
        'rating'      => 'rating IS NULL, rating DESC, COALESCE(sort_title, title)',
        'value'       => 'current_value IS NULL, current_value DESC, COALESCE(sort_title, title)',
        'price'       => 'acquired_price IS NULL, acquired_price DESC, COALESCE(sort_title, title)',
        'rating_asc'  => 'rating IS NULL, rating ASC, COALESCE(sort_title, title)',
        'added'       => 'created_at DESC',
        'updated'     => 'updated_at DESC',
        'platform'    => 'platform_name, COALESCE(sort_title, title)',
        'platform_desc' => 'platform_name DESC, COALESCE(sort_title, title)',
        // The rest of what a table column header can ask for. Every one has a
        // descending twin, because a column you can only sort one way is a
        // column somebody clicks twice and wonders about.
        'company'      => 'developer_name IS NULL, developer_name, COALESCE(sort_title, title)',
        'company_desc' => 'developer_name IS NULL, developer_name DESC, COALESCE(sort_title, title)',
        'publisher'      => 'publisher_name IS NULL, publisher_name, COALESCE(sort_title, title)',
        'publisher_desc' => 'publisher_name IS NULL, publisher_name DESC, COALESCE(sort_title, title)',
        'condition'      => 'condition_grade IS NULL, condition_grade, COALESCE(sort_title, title)',
        'condition_desc' => 'condition_grade IS NULL, condition_grade DESC, COALESCE(sort_title, title)',
        'kind'         => 'category_role, COALESCE(sort_title, title)',
        'kind_desc'    => 'category_role DESC, COALESCE(sort_title, title)',
        'type'         => 'category_name, COALESCE(sort_title, title)',
        'type_desc'    => 'category_name DESC, COALESCE(sort_title, title)',
        'media'        => 'media_type IS NULL, media_type, COALESCE(sort_title, title)',
        'media_desc'   => 'media_type IS NULL, media_type DESC, COALESCE(sort_title, title)',
        default       => 'COALESCE(sort_title, title) ASC',
    };
}

function sort_options(): array
{
    return [
        'title'      => 'Title A–Z',
        'title_desc' => 'Title Z–A',
        'year_desc'  => 'Newest release',
        'year'       => 'Oldest release',
        'rating'     => 'Highest rated',
        'rating_asc' => 'Lowest rated',
        'added'      => 'Recently added',
        'updated'    => 'Recently edited',
        'value'      => 'Highest value',
        'price'      => 'Most expensive',
        // Sorts by platform_name (see item_sort_sql), so it says platform. It read
        // "Library" - a different column, and a different question.
        'platform'   => 'Platform',
    ];
}


/**
 * Make sure there is somewhere to put things.
 *
 * A brand new install has no library, and without one the add form has nothing
 * to offer and nothing can be catalogued at all. Both the web installer and the
 * command line account tool call this, so either route to a first account
 * produces a usable system rather than an empty one.
 *
 * Returns the library id.
 */
/**
 * The library a person means when they have not said.
 *
 * Their own, if they have one. A shared instance should open on your shelf
 * rather than on everything you happen to be able to see, because "everything"
 * is a report and "mine" is what you came to look at.
 */
/**
 * The library the person is working in, for as long as they stay signed in.
 *
 * Choosing one in the header both selects it and remembers it. Every page that then
 * has no ?library= of its own - the library editor, a redirect after saving a model,
 * anything reached by a plain link - stays where they were instead of snapping back to
 * their personal shelf, which is what made the header look like it had changed library
 * behind them.
 *
 * Held in the session, so signing out and in again returns to the personal library and
 * nothing else does.
 *
 * ?library=all is the Collection view asking for everything at once. It is not a
 * library, so it is not remembered as one.
 */
function working_library(): ?array
{
    if (acting_user() === null) {
        return null;
    }

    $want = trim((string) ($_GET['library'] ?? ''));
    if ($want !== '' && $want !== 'all') {
        $hit = one('SELECT * FROM libraries WHERE slug = ? OR id = ?', [$want, (int) $want]);
        if ($hit !== null && can_read_library((int) $hit['id'])) {
            $_SESSION['working_library'] = (int) $hit['id'];
            return $hit;
        }
    }

    $held = (int) ($_SESSION['working_library'] ?? 0);
    if ($held > 0 && can_read_library($held)) {
        $row = one('SELECT * FROM libraries WHERE id = ?', [$held]);
        if ($row !== null) {
            return $row;
        }
        // Deleted, or access revoked. Forget it rather than checking again on
        // every request for the rest of the session.
        unset($_SESSION['working_library']);
    }

    return default_library();
}

function default_library(): ?array
{
    $user = acting_user();
    if ($user === null) {
        return null;
    }
    $own = one(
        'SELECT l.* FROM libraries l
           JOIN library_members m ON m.library_id = l.id AND m.user_id = ?
          WHERE l.is_personal = 1 AND l.owner_id = ?
          LIMIT 1',
        [(int) $user['id'], (int) $user['id']]
    );
    if ($own !== null) {
        return $own;
    }
    // No personal library: fall back to the one they can write to, if there is
    // exactly one. More than one and there is no obvious answer, so do not
    // invent one.
    $writable = readable_libraries(ACCESS_CONTRIBUTOR);
    return count($writable) === 1 ? $writable[0] : null;
}

function ensure_first_library(int $ownerId, string $name = 'My Private Library'): int
{
    // A personal library belongs to one account. Reusing somebody else's is
    // what the shared kind is for.
    $existing = one('SELECT id FROM libraries WHERE is_personal = 1 AND owner_id = ?', [$ownerId]);
    if ($existing !== null) {
        $id = (int) $existing['id'];
        // Whoever set it up should be able to use it.
        q('INSERT IGNORE INTO library_members (library_id, user_id, access) VALUES (?, ?, ?)',
          [$id, $ownerId, ACCESS_OWNER]);
        return $id;
    }

    $id = insert_row('libraries', [
        'is_personal' => 1,
        'name'        => $name,
        'slug'        => unique_slug('libraries', slugify($name)),
        'description' => 'Yours alone. It cannot be shared, which is what makes it the one place you always have.',
        'owner_id'    => $ownerId,
        'kind'        => 'private',
        'is_default'  => 1,
        'sort_order'  => 10,
    ]);

    q('INSERT IGNORE INTO library_members (library_id, user_id, access) VALUES (?, ?, ?)',
      [$id, $ownerId, ACCESS_OWNER]);

    // A new personal shelf starts empty.
    //
    // It used to be filled with the whole starter set - sixty-three platforms, a hundred
    // and fifty makers, three and a half thousand categories - the moment an account
    // first signed in. For an account created by a directory that is a shelf somebody
    // never asked for, full of machines they may have no interest in, and the only way
    // back was to delete it all by hand.
    //
    // Synchronising is one button on the library's own page and it says exactly what it
    // will copy. Starting empty and choosing is better than starting full and pruning.
    //
    // The installer seeds its administrator's library explicitly, so a fresh instance
    // still has something to look at.

    return $id;
}

// ---------------------------------------------------------------------------
// The category tree
//
// Platform is deliberately not part of it. A browse view composes
// library > domain > platform > category from the entry's two fields, which
// gives the nesting people expect without duplicating "Peripherals > Storage"
// beneath every machine. Adding "Networking" once should not mean adding it
// eleven times.
// ---------------------------------------------------------------------------

/** Every category, ordered so a tree can be rendered by walking the list. */
function category_tree(?string $domain = null, ?int $libraryId = null): array
{
    // Whose taxonomy. Defaults to the library being worked in, because the editor edits
    // one library's tree - showing every reachable library's would put the same names
    // on screen several times over, which is exactly the duplication this change exists
    // to remove.
    if ($libraryId === null) {
        $lib       = working_library();
        $libraryId = $lib === null ? 0 : (int) $lib['id'];
    }

    $sql  = 'SELECT c.* FROM categories c WHERE c.library_id = ?';
    $args = [$libraryId];
    if ($domain !== null) {
        $sql   .= ' AND c.section_id = (SELECT id FROM sections WHERE slug = ?)';
        $args[] = $domain;
    }
    // Ordered in PHP, depth-first, siblings by sort_order then name.
    //
    // It used to be 'ORDER BY domain DESC, path' - and path is a chain of ids, so
    // siblings came out in id order (lexicographically, at that: /1/100/ before
    // /1/74/). sort_order was stored, editable, and had no effect on this list at all,
    // which is why moving a node up or down could not work.
    $rows = all($sql, $args);

    $kids = [];
    foreach ($rows as $r) {
        $kids[(int) ($r['parent_id'] ?? 0)][] = $r;
    }
    foreach ($kids as &$group) {
        usort($group, function ($a, $b) {
            return [(int) $a['sort_order'], (string) $a['name']]
               <=> [(int) $b['sort_order'], (string) $b['name']];
        });
    }
    unset($group);

    $out  = [];
    $walk = function (int $parent) use (&$walk, &$out, $kids) {
        foreach ($kids[$parent] ?? [] as $row) {
            $out[] = $row;
            $walk((int) $row['id']);
        }
    };
    // Roots ordered by their section's own sort_order - hardware, software,
    // video, music, and whatever comes after them. This used to be
    // ['hardware', 'software'] matching the old domain DESC; the two-value
    // hardcoded list is what a real section table replaces.
    $sectionOrder = array_column(all('SELECT id FROM sections ORDER BY sort_order'), 'id');
    foreach ($sectionOrder as $sid) {
        foreach ($kids[0] ?? [] as $root) {
            if ((int) ($root['section_id'] ?? 0) === (int) $sid) {
                $out[] = $root;
                $walk((int) $root['id']);
            }
        }
    }
    return $out;
}

/** The ids of a category and everything beneath it. */
function category_subtree_ids(int $categoryId): array
{
    $row = one('SELECT path FROM categories WHERE id = ?', [$categoryId]);
    if ($row === null) {
        return [];
    }
    return array_map('intval', array_column(
        all('SELECT id FROM categories WHERE path LIKE ?', [$row['path'] . '%']), 'id'
    ));
}

/** A category and each of its ancestors, nearest first. */
function category_ancestry(int $categoryId): array
{
    $row = one('SELECT path FROM categories WHERE id = ?', [$categoryId]);
    if ($row === null) {
        return [];
    }
    $ids = array_values(array_filter(explode('/', (string) $row['path']), 'strlen'));
    return array_reverse(array_map('intval', $ids));
}

/**
 * What a branch holds, inherited from above when it has not said.
 *
 * A branch made under Games is a kind of game whether or not anybody set it -
 * "Games › Point and click" is not a place with no opinion, it is a place that
 * inherited one. Only the nearest ancestor that has said anything counts, so a
 * branch can still override what it is under.
 *
 * Returns null when nothing above it has said either, which is the honest answer
 * for a machine and for a branch that only holds other branches.
 */
function category_effective_role(int $categoryId): ?string
{
    static $cache = [];
    if (array_key_exists($categoryId, $cache)) {
        return $cache[$categoryId];
    }

    // machine/peripheral cover hardware, game/application cover software -
    // movie/tv_show/music were added to the template data before any item
    // ever used one, and this list was never extended to match. Nothing
    // caught it because nothing exercised a video or music item's own
    // category role until now - the same shape of gap several other spots
    // in this session turned out to have.
    $kinds = ['machine', 'peripheral', 'game', 'application', 'movie', 'tv_show', 'music'];
    // Nearest first, so the first hit is the one that governs. One query for the
    // whole line rather than one per step up it.
    $line = category_ancestry($categoryId);
    if ($line === []) {
        return $cache[$categoryId] = null;
    }
    $in   = implode(',', array_fill(0, count($line), '?'));
    $rows = [];
    foreach (all("SELECT id, role FROM categories WHERE id IN ($in)", $line) as $row) {
        $rows[(int) $row['id']] = (string) $row['role'];
    }
    foreach ($line as $id) {
        if (in_array($rows[$id] ?? '', $kinds, true)) {
            return $cache[$categoryId] = $rows[$id];
        }
    }
    return $cache[$categoryId] = null;
}

/**
 * The filename to show when this category's own entries have no
 * photograph of their own - a generic VHS clamshell, a boxed Amiga
 * disk, whatever this branch's own things generally come in. The same
 * walk category_effective_role() already does, nearest ancestor with
 * an answer wins, because a leaf that has not been given its own
 * picture almost always wants whatever the branch above it already
 * says - the ordinary case, the same reasoning kind inheritance
 * already gives.
 */
function category_effective_default_image(int $categoryId): ?string
{
    static $cache = [];
    if (array_key_exists($categoryId, $cache)) {
        return $cache[$categoryId];
    }

    $line = category_ancestry($categoryId);
    if ($line === []) {
        return $cache[$categoryId] = null;
    }
    $in   = implode(',', array_fill(0, count($line), '?'));
    $rows = [];
    foreach (all("SELECT id, default_image_filename FROM categories WHERE id IN ($in)", $line) as $row) {
        $rows[(int) $row['id']] = $row['default_image_filename'];
    }
    foreach ($line as $id) {
        if (!empty($rows[$id] ?? null)) {
            return $cache[$categoryId] = (string) $rows[$id];
        }
    }
    return $cache[$categoryId] = null;
}

/**
 * Which shipped picture this branch declares, walking up until something says.
 *
 * The same walk as category_effective_default_image() beside it, over the other
 * column - and separate from it because the two answer to different people. That
 * one is what a curator uploaded; this one is what the structure feed said, and
 * is rewritten on every import. The curator's wins.
 *
 * Returns a slug, or null. Checked against the catalogue on the way out as well
 * as on the way in: a row written by an older release naming a picture this one
 * no longer ships would otherwise resolve to a broken image, and falling through
 * to the kind's own picture is the better failure.
 */
function category_effective_stock_image(int $categoryId): ?string
{
    static $cache = [];
    if (array_key_exists($categoryId, $cache)) {
        return $cache[$categoryId];
    }

    $line = category_ancestry($categoryId);
    if ($line === []) {
        return $cache[$categoryId] = null;
    }
    $in   = implode(',', array_fill(0, count($line), '?'));
    $rows = [];
    foreach (all("SELECT id, stock_image FROM categories WHERE id IN ($in)", $line) as $row) {
        $rows[(int) $row['id']] = $row['stock_image'];
    }
    foreach ($line as $id) {
        $slug = (string) ($rows[$id] ?? '');
        if ($slug !== '' && isset(stock_images()[$slug])) {
            return $cache[$categoryId] = $slug;
        }
    }
    return $cache[$categoryId] = null;
}

/**
 * Small per-user choices - which view a browser section opens in, and whatever
 * comes after it. See the user_prefs table.
 *
 * Names are capped and values are capped, because both go into fixed columns and
 * a silent truncation is a preference that reads back as something the person
 * did not choose. Over-long names are refused rather than trimmed: a caller
 * writing one has a bug, and trimming would make two different keys collide.
 *
 * @return array<string, string>
 */
function user_prefs(int $userId, bool $refresh = false): array
{
    // Cached per request because a page may read several. `$refresh` is how a
    // write invalidates it - a second static reset function would have had no
    // way to reach this one's scope, which is worth saying because the obvious
    // first attempt at it does not work.
    static $cache = [];
    if (!$refresh && isset($cache[$userId])) {
        return $cache[$userId];
    }
    $out = [];
    foreach (all('SELECT name, value FROM user_prefs WHERE user_id = ?', [$userId]) as $r) {
        $out[(string) $r['name']] = (string) $r['value'];
    }
    return $cache[$userId] = $out;
}

function user_pref(int $userId, string $name, string $default = ''): string
{
    return user_prefs($userId)[$name] ?? $default;
}

function set_user_pref(int $userId, string $name, string $value): bool
{
    $name = trim($name);
    // Refused rather than trimmed. A caller writing an over-long name has a bug,
    // and trimming would make two different keys collide silently.
    if ($name === '' || mb_strlen($name) > 60) {
        return false;
    }
    // Empty means "forget this", so a preference put back to its default leaves
    // no row rather than a row repeating what the default already says.
    if ($value === '') {
        q('DELETE FROM user_prefs WHERE user_id = ? AND name = ?', [$userId, $name]);
    } else {
        q('INSERT INTO user_prefs (user_id, name, value) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE value = VALUES(value)',
          [$userId, $name, mb_substr($value, 0, 500)]);
    }
    user_prefs($userId, true);
    return true;
}

/** "Peripherals › Storage", for showing where an entry sits. */
function category_breadcrumb(int $categoryId, string $separator = ' › '): string
{
    $ids = array_reverse(category_ancestry($categoryId));
    if ($ids === []) {
        return '';
    }
    $rows = all('SELECT id, name FROM categories WHERE id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = (string) $r['name'];
    }
    return implode($separator, array_values(array_filter(
        array_map(fn($id) => $byId[$id] ?? null, $ids)
    )));
}

/** Recompute path and depth. Called after any change to the shape of the tree. */
function category_rebuild_paths(): void
{
    q("UPDATE categories SET path = CONCAT('/', id, '/'), depth = 0 WHERE parent_id IS NULL");
    // Deep enough for any sane taxonomy, and it stops rather than looping if
    // somebody has managed to make a cycle.
    for ($level = 1; $level <= 8; $level++) {
        q("UPDATE categories c JOIN categories p ON p.id = c.parent_id
              SET c.path = CONCAT(p.path, c.id, '/'), c.depth = p.depth + 1
            WHERE p.depth = ? AND c.parent_id IS NOT NULL", [$level - 1]);
    }
}

// ---------------------------------------------------------------------------
// Which metadata sources serve a given entry
// ---------------------------------------------------------------------------

/**
 * The providers offered for one category and platform.
 *
 * Scopes are inherited downwards and the nearest one wins, so enabling a source
 * on "Peripherals" covers Adapters and Storage beneath it, while a row on
 * Adapters can switch it off again for that branch alone. A platform-specific
 * scope beats a platform-agnostic one at the same depth, which is what makes
 * "the Amiga hardware database, but only for Amiga" expressible.
 *
 * With no scopes configured at all, every enabled provider is offered - a fresh
 * install should not silently have nothing available.
 */
function providers_for(int $categoryId, ?int $platformId = null): array
{
    $ancestry = category_ancestry($categoryId);   // nearest first
    if ($ancestry === []) {
        return [];
    }


    // An empty ancestry would build "IN ()", which is a syntax error rather
    // than an empty set. It should not happen for a real category, and a
    // malformed id should not take the page down when it does.
    if ($ancestry === []) {
        return [];
    }

    $rows = all(
        'SELECT ps.provider_id, ps.enabled, ps.platform_id, c.depth FROM provider_scopes ps
           JOIN categories c ON c.id = ps.category_id
          WHERE ps.category_id IN (' . implode(',', array_fill(0, count($ancestry), '?')) . ')
            AND (ps.platform_id = 0' . ($platformId !== null ? ' OR ps.platform_id = ?' : '') . ')',
        $platformId !== null ? array_merge($ancestry, [$platformId]) : $ancestry
    );

    // The nearest kind wins, so a row deep in the tree can switch off something
    // enabled further up without affecting its siblings. At the same depth, a
    // row naming a machine beats one that applies to any - which is what makes
    // "this source everywhere except on the C64" expressible.
    $decision = [];
    foreach ($rows as $row) {
        $pid   = (int) $row['provider_id'];
        $score = ((int) $row['depth'] * 2) + ((int) $row['platform_id'] === 0 ? 0 : 1);
        if (!isset($decision[$pid]) || $score > $decision[$pid]['score']) {
            $decision[$pid] = ['score' => $score, 'enabled' => (int) $row['enabled'] === 1];
        }
    }

    // Nothing until somebody says so.
    //
    // The default was "every source that fits", which meant a fresh branch showed
    // every source already answering for it and the On buttons had nothing to do.
    // Switching a source on for a branch is a decision, and a decision nobody has
    // made is not a quiet yes.
    //
    // Inheritance still carries downward: a source turned on at Amiga > Software
    // answers for everything under it without being turned on again.
    $out = [];
    foreach ($decision as $pid => $said) {
        if (!$said['enabled']) {
            continue;
        }
        $row = one('SELECT * FROM metadata_providers WHERE id = ? AND is_enabled = 1', [$pid]);
        if ($row !== null) {
            $out[] = $row;
        }
    }
    usort($out, static fn($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
    return $out;

}




/**
 * Copy a branch under another node, renaming slugs as it goes.
 *
 * Without this, giving a second machine the same shelf structure means building
 * Peripherals > Adapters > Network adapters by hand every time, which is how
 * people stop maintaining a taxonomy.
 *
 * Returns the id of the new top node.
 */
function copy_subtree(int $sourceId, int $targetParentId, ?string $newName = null,
                     bool $top = true): int
{
    $source = one('SELECT * FROM categories WHERE id = ?', [$sourceId]);
    $parent = one('SELECT * FROM categories WHERE id = ?', [$targetParentId]);
    if ($source === null || $parent === null) {
        throw new InvalidArgumentException('No such node.');
    }

    $name = $newName ?? (string) $source['name'];

    // The copy belongs to wherever it lands, and keeps what the source was.
    //
    // Three things were missing, and each mattered more once the taxonomy became per
    // library and per platform. library_id was never set, so every copy arrived as a
    // template row - filed under nothing, invisible to the library that made it, and
    // liable to be overwritten by the next reseed. role was not carried either, so
    // copying Peripherals into another machine produced a branch of "other" kinds and
    // the entry form stopped offering them as peripherals. And the platform is the
    // target's, not the source's: a branch copied under Game Boy is Game Boy's.
    $newId = insert_row('categories', [
        'library_id'  => $parent['library_id'],
        'section_id'  => (int) $parent['section_id'],
        'role'        => (string) $source['role'],
        'parent_id'   => $targetParentId,
        'platform_id' => $parent['platform_id'] ?? $source['platform_id'],
        'name'        => $name,
        'description' => $source['description'],
        // Unique within the library, which is what the key is on. unique_slug() checks
        // the whole table and would append "-2" to a name no other library can see.
        'slug'        => category_unique_slug(
            $parent['library_id'] === null ? null : (int) $parent['library_id'],
            slugify((string) $parent['slug'] . '-' . $name)
        ),
        'sort_order'  => (int) $source['sort_order'],
    ]);

    foreach (all('SELECT id FROM categories WHERE parent_id = ? ORDER BY sort_order, name', [$sourceId]) as $child) {
        copy_subtree((int) $child['id'], $newId, null, false);
    }

    // Once, at the end, for the library that changed - not on every level of the
    // recursion, which rebuilt the whole table once per node copied.
    if ($top) {
        rebuild_category_paths($parent['library_id'] === null ? null : (int) $parent['library_id']);
    }
    return $newId;
}

/** A slug free within one library, which is where the uniqueness actually is. */
function category_unique_slug(?int $libraryId, string $base): string
{
    $base = $base === '' ? 'kind' : $base;
    $slug = $base;
    for ($n = 2; $n < 200; $n++) {
        $taken = one('SELECT id FROM categories WHERE library_id <=> ? AND slug = ?', [$libraryId, $slug]);
        if ($taken === null) {
            return $slug;
        }
        $slug = $base . '-' . $n;
    }
    return $base . '-' . bin2hex(random_bytes(3));
}

/**
 * May this source be enabled on this node?
 *
 * Every provider declares what it can serve - a domain, and the machines it
 * knows about. The Amiga Hardware Database has nothing to say about a C64
 * cartridge or about Deluxe Paint, so offering it there is a promise the
 * software cannot keep. Checking the declaration rather than trusting whoever
 * ticks the box keeps the choice honest.
 *
 * Returns [allowed, reason].
 */
function provider_fits_node(string $providerType, int $nodeId, ?int $platformId = null): array
{
    $types = function_exists('metadata_provider_types') ? metadata_provider_types() : [];
    $def   = $types[$providerType] ?? null;
    if ($def === null) {
        return [false, 'Unknown source type.'];
    }

    $node = one('SELECT * FROM categories WHERE id = ?', [$nodeId]);
    if ($node === null) {
        return [false, 'No such node.'];
    }

    // The node's own domain, or any beneath it.
    //
    // A source set on a node answers for everything under it, so what matters is
    // whether anything under it is the kind of thing this source knows about -
    // not what the node itself happens to be filed as.
    //
    // The platform roots are the case that showed it. `categories.domain` is an
    // enum of exactly two values, so "Amiga", the parent of an Amiga > Hardware
    // branch and an Amiga > Software branch, has to be one or the other and is
    // filed as software. The Amiga Hardware Database was therefore refused at the
    // one node an Amiga collector would naturally set it on, with "this is under
    // software" - which is true of the row and false of the branch.
    $domains = $def['domains'] ?? ['software'];
    $nodeSection = scalar('SELECT slug FROM sections WHERE id = ?', [(int) $node['section_id']]);
    $here    = [(string) $nodeSection];
    if ($node['path'] !== null && $node['path'] !== '') {
        foreach (all('SELECT DISTINCT s.slug AS domain FROM categories c
                       JOIN sections s ON s.id = c.section_id
                       WHERE c.library_id <=> ? AND c.path LIKE ?',
                     [$node['library_id'], (string) $node['path'] . '%']) as $d) {
            $here[] = (string) $d['domain'];
        }
    }
    if (array_intersect($domains, array_unique($here)) === []) {
        return [false, ucfirst((string) $def['label']) . ' covers '
            . implode(' and ', $domains) . ', and there is nothing of that kind '
            . 'at this node or beneath it.'];
    }

    // The machine is said, not enforced.
    //
    // This refused any platform outside the source's list, which is wrong for
    // anybody whose tree is their own: a person who never synchronises the
    // templates and calls their machine `amiga-500-mine` matches no list, and
    // every source was refused on every node they own. A tag written by us cannot
    // know what somebody else calls their shelves.
    //
    // So every source can be enabled on any node, and what comes back says
    // whether it has been tried on this machine. Untested is not the same as
    // useless, and the person holding the collection is better placed to find out
    // than a list shipped a year ago.
    $tested = $def['tested_with'] ?? [];
    if ($tested !== [] && $platformId !== null) {
        $slug = (string) scalar('SELECT slug FROM platforms WHERE id = ?', [$platformId]);
        if (!in_array($slug, $tested, true)) {
            return [true, (string) $def['label'] . ' has not been tried on this machine — '
                . 'it may still answer.'];
        }
        return [true, 'Tested with this machine.'];
    }

    return [true, 'Fits.'];
}

/** The sources that could sensibly be enabled on a node, for the editor. */
function providers_available_for(int $nodeId, ?int $platformId = null): array
{
    // Switched-off sources are offered too.
    //
    // Attaching a source to a branch is a statement about the shape of the
    // catalogue - "this is where Amiga hardware lives, and the Big Book knows
    // about that" - and it is true whether or not the source is switched on
    // today. Hiding the disabled ones meant the structure could only be built in
    // the order the sources happened to be configured, and a source turned off
    // for an afternoon quietly dropped out of the editor while its scope rows
    // stayed in the database.
    //
    // Nothing here decides whether a lookup runs: both providers_for() and
    // enabled_metadata_providers() filter on is_enabled, so a scope pointing at
    // a source that is off simply never fires.
    // A branch that has not said what it holds offers nothing.
    //
    // Fitness used to be judged from the domain of the row and everything under
    // it, so a machine - which is neither side of the shop and cannot be given a
    // kind - matched both, and every source in the instance appeared on it. The
    // question a source answers is "what kind of thing is filed here", and a
    // branch that has not answered it has nothing to offer sources for.
    // Inherited when this branch has not said. A branch under Games is a kind of
    // game whether or not anybody set it, so its sources are the ones Games would
    // have offered - otherwise every leaf would need declaring by hand before it
    // could fetch anything.
    $role = category_effective_role($nodeId);
    if ($role === null) {
        return [];
    }

    // And the side of the shop follows from the kind, not from the row's domain
    // column - the kind is the thing somebody set on purpose.
    $side = in_array($role, ['machine', 'peripheral'], true) ? 'hardware' : 'software';

    $out = [];
    foreach (all('SELECT * FROM metadata_providers ORDER BY name') as $p) {
        $def = metadata_provider_definition((string) $p['type']);
        if ($def === null || !in_array($side, $def['domains'] ?? [], true)) {
            continue;
        }
        $out[] = $p + ['fit_reason' => ''];
    }
    return $out;
}

/**
 * Nodes an entry can be filed under, each labelled with where it sits.
 *
 * A flat list of leaf names is unusable once the tree has per-platform
 * branches: six nodes called "Adapters" look identical and picking the wrong
 * one files an Amiga card under the C64. The label carries the path so the
 * choice is unambiguous, and only groups are offered - a domain or a machine
 * is a place in the structure, not a kind of thing.
 */
/**
 * The filing tree as a flat list of options, each labelled with its full path.
 *
 * $domain narrows it: a form about hardware should not offer "Games", because
 * that is not merely untidy - it puts a wrong entry one mis-click away. The
 * domain is read from categories and nowhere else.
 */
function filing_options(?string $domain = null, ?int $libraryId = null): array
{
    // One library's kinds, and never the templates.
    //
    // This listed every category on the instance, which was the same thing while there
    // was one taxonomy. It is not now: it offered twenty template rows - the set that
    // exists only to be copied - and an entry filed under one points at a row no
    // library owns, which then disappears the moment the templates are reseeded.
    if ($libraryId === null) {
        $lib       = working_library();
        $libraryId = $lib === null ? 0 : (int) $lib['id'];
    }

    $sql  = 'SELECT c.*, s.slug AS domain FROM categories c JOIN sections s ON s.id = c.section_id WHERE c.library_id = ?';
    $args = [$libraryId];
    if ($domain !== null) {
        $sql   .= ' AND s.slug = ?';
        $args[] = $domain;
    }
    // Breadcrumbs built once from the rows in hand, not asked per row.
    //
    // This called category_breadcrumb() for every option, and that is two queries each
    // - an ancestry walk and a name lookup. At 2,568 kinds in a library that is about
    // five thousand round trips to render one select, which is what made the entry form
    // take over a second.
    $rows = all($sql . " ORDER BY s.slug DESC, path", $args);

    $pathIds = [];

    // Names for the whole library, not just the rows being returned.
    //
    // A path runs through ancestors of both domains - "Amiga > Software > Games" starts
    // at a hardware row, because the machine is the root. Looking names up only among
    // the filtered rows dropped that first step, so a software list read "Software >
    // Games > Action" with no way to tell which machine's branch it was, and the same
    // path exists under every platform.
    $nameById = [];
    foreach (all('SELECT id, name FROM categories WHERE library_id = ?', [$libraryId]) as $n) {
        $nameById[(int) $n['id']] = (string) $n['name'];
    }
    // By reference, or the trail this records never leaves the closure and every
    // branch would look like it inherits nothing. A closure that quietly writes
    // to its own copy is the kind of thing that reads correctly and does nothing.
    $crumbOf = function (array $n) use ($nameById, &$pathIds): string {
        // path is '/1/17/44/', so the trail is already recorded; no walk needed.
        $ids = array_filter(explode('/', (string) $n['path']), fn($x) => $x !== '');
        $pathIds[(int) $n['id']] = array_map('intval', array_values($ids));
        $out = [];
        foreach ($ids as $id) {
            if (isset($nameById[(int) $id])) {
                $out[] = $nameById[(int) $id];
            }
        }
        return $out === [] ? (string) $n['name'] : implode(' › ', $out);
    };

    $out = [];
    foreach ($rows as $n) {
        $out[] = [
            'id'     => (int) $n['id'],
            // Both are needed: forms post the id, filters use the slug in the
            // query string so a link stays readable and survives a reseed.
            'slug'   => (string) $n['slug'],
            'name'   => (string) $n['name'],
            'label'  => $crumbOf($n),
            'depth'  => (int) $n['depth'],
            'domain' => (string) $n['domain'],
            // Which machine's branch this is, so the entry form can narrow the list
            // once a platform is chosen - 3,700 kinds is not a list anyone reads.
            'platform_id' => $n['platform_id'] === null ? 0 : (int) $n['platform_id'],
            // Machine, peripheral, or neither. The entry form uses it to stop
            // offering Peripherals when you said you were adding a machine.
            //
            // `role` is what this branch says; `kind` is what it holds, which is
            // inherited when it has said nothing. A form filtering on `role`
            // offered only the branch somebody declared and none of the branches
            // beneath it - so "Peripherals › Storage" could not be chosen while
            // "Peripherals" could, which is backwards: the leaf is where a thing
            // actually goes.
            'role'   => (string) $n['role'],
        ];
    }

    // Resolved from the list itself rather than a query per node: the whole
    // library is already here, and 3,700 round trips to answer a question the
    // rows can answer between them is how a form becomes a page load.
    $roleById = [];
    foreach ($out as $row) {
        $roleById[$row['id']] = $row['role'];
    }
    // Same list category_effective_role() uses - kept in step with it rather
    // than re-deriving separately, since both are answering the identical
    // question for the identical column.
    $kinds = ['machine', 'peripheral', 'game', 'application', 'movie', 'tv_show', 'music'];
    foreach ($out as $i => $row) {
        $kind = in_array($row['role'], $kinds, true) ? $row['role'] : '';
        if ($kind === '') {
            // Nearest first: the ancestry is the path, and the first ancestor
            // that has said anything governs.
            foreach (array_reverse($pathIds[$row['id']] ?? []) as $ancestor) {
                if (in_array($roleById[$ancestor] ?? '', $kinds, true)) {
                    $kind = $roleById[$ancestor];
                    break;
                }
            }
        }
        $out[$i]['kind'] = $kind;
    }

    // Whether a lookup on this branch would ask anybody.
    //
    // Sources are switched on per branch and inherit downward, so this is "does
    // any branch at or above this one have a source switched on". One query for
    // the switched-on set, then the same path walk as above - the alternative is
    // providers_for() per option, which on the shipped tree is 3,672 ancestry
    // queries to draw one form.
    $scoped = [];
    foreach (all('SELECT DISTINCT ps.category_id FROM provider_scopes ps
                    JOIN categories c ON c.id = ps.category_id
                   WHERE c.library_id = ? AND ps.enabled = 1', [$libraryId]) as $row) {
        $scoped[(int) $row['category_id']] = true;
    }
    foreach ($out as $i => $row) {
        $has = isset($scoped[$row['id']]);
        if (!$has) {
            foreach ($pathIds[$row['id']] ?? [] as $ancestor) {
                if (isset($scoped[$ancestor])) {
                    $has = true;
                    break;
                }
            }
        }
        $out[$i]['has_sources'] = $has;
    }

    return $out;
}

// ---------------------------------------------------------------------------
// What is fitted to what
//
// The reason a database beats a spreadsheet for hardware. A Blizzard 1230 is
// installed in an A1200; a 4 MB SIMM is installed in the Blizzard; a 1084S was
// bundled with the machine. Recorded since the hardware schema landed and never
// shown until now, which made it worthless.
// ---------------------------------------------------------------------------

/** How one entry relates to another, in words rather than an enum value. */
function link_relations(): array
{
    return [
        'installed_in' => ['is installed in', 'has installed'],
        'bundled_with' => ['came with',       'came with'],
        'spare_for'    => ['is a spare for',  'has spares'],
        'connects_to'  => ['connects to',     'is connected to'],
    ];
}

function link_label(string $relation, bool $fromChild = true): string
{
    $r = link_relations()[$relation] ?? ['relates to', 'relates to'];
    return $fromChild ? $r[0] : $r[1];
}

/**
 * What this entry hangs off: the machine it is fitted to, and so on upward.
 *
 * category_name and model_vendor travel along because the pages that render these
 * name the thing rather than the relationship: "MNT ZZ9000 · MNT Research ·
 * Graphics card" is what a reader wants, where "is installed in" is the sentence
 * read from the other end and was confusing on the machine's own page.
 *
 * The maker comes from the model's vendor, which is where a peripheral's
 * manufacturer lives - an item carries a developer and a publisher, which are
 * software ideas and empty on a circuit board.
 */
function item_parents(int $itemId): array
{
    return all(
        "SELECT l.id AS link_id, l.relation, l.note, i.id, i.title, i.category_id,
                i.platform_id, i.category_name, v.name AS model_vendor
           FROM item_links l
           JOIN v_items i ON i.id = l.parent_item_id
      LEFT JOIN hardware_models hm ON hm.id = i.model_id
      LEFT JOIN companies v ON v.id = hm.vendor_id
          WHERE l.child_item_id = ? ORDER BY l.relation, i.title",
        [$itemId]
    );
}

/** What is fitted to this entry. Same extra columns as item_parents(). */
function item_children(int $itemId): array
{
    return all(
        "SELECT l.id AS link_id, l.relation, l.note, i.id, i.title, i.category_id,
                i.platform_id, i.category_name, v.name AS model_vendor
           FROM item_links l
           JOIN v_items i ON i.id = l.child_item_id
      LEFT JOIN hardware_models hm ON hm.id = i.model_id
      LEFT JOIN companies v ON v.id = hm.vendor_id
          WHERE l.parent_item_id = ? ORDER BY l.relation, i.title",
        [$itemId]
    );
}

/**
 * The whole chain upward, so a SIMM can say it is in a Blizzard in an A1200.
 *
 * Depth-capped rather than trusting the data: a cycle would otherwise hang the
 * page, and nothing stops somebody recording one by hand.
 */
/**
 * Would linking child under parent close a loop?
 *
 * item_ancestry_chain() below answers a different question - "what is this
 * fitted into", for a breadcrumb - and only follows 'installed_in', six levels
 * deep. Using it as the cycle guard meant a chain mixing relations
 * (A installed_in B, B connects_to C, C installed_in A) was never noticed, and
 * nor was anything more than six deep. Both then made item_goes_with() recurse
 * until the page died.
 *
 * This walks every relation, breadth-first, with no depth limit and a visited
 * set - so it terminates even if a cycle already exists in the data.
 */
function item_link_would_loop(int $parentId, int $childId): bool
{
    if ($parentId === $childId) {
        return true;
    }

    // Everything reachable *downwards* from the proposed child. If the proposed
    // parent is in there, the new edge closes a loop.
    $seen  = [$childId => true];
    $queue = [$childId];

    while ($queue !== []) {
        $batch = array_splice($queue, 0, 100);
        $rows  = all(
            'SELECT DISTINCT child_item_id FROM item_links WHERE parent_item_id IN ('
            . implode(',', array_fill(0, count($batch), '?')) . ')',
            $batch
        );
        foreach ($rows as $row) {
            $next = (int) $row['child_item_id'];
            if ($next === $parentId) {
                return true;
            }
            if (!isset($seen[$next])) {
                $seen[$next] = true;
                $queue[]     = $next;
            }
        }
    }

    return false;
}

function item_ancestry_chain(int $itemId, int $limit = 6): array
{
    $chain = [];
    $seen  = [$itemId => true];
    $current = $itemId;

    for ($i = 0; $i < $limit; $i++) {
        $up = one(
            "SELECT i.id, i.title FROM item_links l JOIN items i ON i.id = l.parent_item_id
              WHERE l.child_item_id = ? AND l.relation = 'installed_in' LIMIT 1",
            [$current]
        );
        if ($up === null || isset($seen[(int) $up['id']])) {
            break;
        }
        $chain[] = $up;
        $seen[(int) $up['id']] = true;
        $current = (int) $up['id'];
    }
    return $chain;
}

/**
 * Everything that would leave with this entry if it were sold.
 *
 * Recursive, because selling an A1200 takes the Blizzard with it and the SIMM
 * in the Blizzard with that. Answering "what am I actually parting with" is the
 * question this data exists for.
 */
function item_goes_with(int $itemId, int $depth = 0): array
{
    if ($depth > 5) {
        return [];
    }
    $out = [];
    foreach (item_children($itemId) as $child) {
        $out[] = $child + ['depth' => $depth];
        foreach (item_goes_with((int) $child['id'], $depth + 1) as $deeper) {
            $out[] = $deeper;
        }
    }
    return $out;
}

/** Entries that could sensibly be linked to this one: same library, not itself. */
function linkable_items(int $itemId, int $libraryId): array
{
    return all(
        'SELECT id, title, category_id, platform_id FROM v_items
          WHERE library_id = ? AND id <> ? ORDER BY title LIMIT 500',
        [$libraryId, $itemId]
    );
}


// ---------------------------------------------------------------------------
// Browsing by narrowing
//
// Every level counts only what exists and only what the viewer can reach, so a
// branch never appears empty. A fresh install showing twelve machines and sixty
// types, all with nothing in them, teaches nobody anything.
// ---------------------------------------------------------------------------

/** Libraries you can see, with how much is in each. */
function browse_libraries(): array
{
    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);
    $counts = [];
    foreach (all("SELECT library_id, domain, COUNT(*) AS n FROM v_items
                   WHERE $acl GROUP BY library_id, domain", $aclP) as $r) {
        $counts[(int) $r['library_id']][(string) $r['domain']] = (int) $r['n'];
    }
    $out = [];
    foreach (readable_libraries(ACCESS_VIEWER) as $lib) {
        $id = (int) $lib['id'];
        $out[] = $lib + [
            'hardware' => $counts[$id]['hardware'] ?? 0,
            'software' => $counts[$id]['software'] ?? 0,
            'total'    => array_sum($counts[$id] ?? []),
        ];
    }
    return $out;
}

/** Machines that actually hold something, within a library and domain. */
function browse_platforms(?int $libraryId, string $domain): array
{
    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);
    $sql  = "SELECT platform_id, COUNT(*) AS n FROM v_items WHERE $acl AND domain = ?";
    $args = array_merge($aclP, [$domain]);
    if ($libraryId !== null) {
        $sql   .= ' AND library_id = ?';
        $args[] = $libraryId;
    }
    $rows = all($sql . ' GROUP BY platform_id', $args);

    $byId = [];
    foreach ($rows as $r) { $byId[(int) $r['platform_id']] = (int) $r['n']; }
    if ($byId === []) {
        return [];
    }
    $out = [];
    foreach (all('SELECT * FROM platforms WHERE id IN ('
        . implode(',', array_fill(0, count($byId), '?')) . ') ORDER BY name',
        array_keys($byId)) as $p) {
        $out[] = $p + ['n' => $byId[(int) $p['id']]];
    }
    return $out;
}

/**
 * Types holding something, at the top level of the tree.
 *
 * Rolled up to the root of each branch: eleven peripherals is more useful at a
 * glance than four adapters, three storage and four networking, and the next
 * click gets you there anyway.
 */
function browse_types(?int $libraryId, string $domain, ?int $platformId): array
{
    [$acl, $aclP] = library_filter_sql('i.library_id', ACCESS_VIEWER);
    $sql  = "SELECT root.id, root.name, root.slug, COUNT(*) AS n
               FROM v_items i
               JOIN categories c    ON c.id = i.category_id
               JOIN categories root ON root.id = CAST(SUBSTRING_INDEX(SUBSTRING(c.path, 2), '/', 1) AS UNSIGNED)
               JOIN sections   sec  ON sec.id = c.section_id
              WHERE $acl AND sec.slug = ?";
    $args = array_merge($aclP, [$domain]);
    if ($libraryId !== null)  { $sql .= ' AND i.library_id = ?';  $args[] = $libraryId; }
    if ($platformId !== null) { $sql .= ' AND i.platform_id = ?'; $args[] = $platformId; }

    return all($sql . ' GROUP BY root.id, root.name, root.slug ORDER BY root.sort_order, root.name', $args);
}

/**
 * Which hardware types describe a machine rather than a part.
 *
 * A computer, a console or a handheld is a thing you own; an accelerator or a
 * network adapter goes inside one. Nothing else records the difference, which
 * is why the type is the only question the editor has to ask.
 */
/**
 * Categories that hold machines rather than the things fitted to them.
 *
 * Read from `categories.role`, not from a list in this file. It used to be
 * ['computers', 'console', 'handheld'] hardcoded here, which meant the tree
 * lived in the database and what the tree *meant* lived in code - so adding a
 * class of machine needed a deploy, and a console was filed as a kind of
 * computer because that is where the array put it.
 */
function machine_category_ids(?int $libraryId = null): array
{
    // Scoped, and no longer cached across libraries. The static was safe while there
    // was one taxonomy; with one per library it would answer the first library asked
    // for every library after it.
    if ($libraryId === null) {
        $lib       = working_library();
        $libraryId = $lib === null ? 0 : (int) $lib['id'];
    }
    return array_map('intval', array_column(
        all("SELECT c.id FROM categories c JOIN sections s ON s.id = c.section_id
              WHERE s.slug = 'hardware' AND c.role = 'machine' AND c.library_id = ?", [$libraryId]),
        'id'
    ));
}

function category_role(?int $categoryId): string
{
    if ($categoryId === null) {
        return 'other';
    }
    $role = scalar('SELECT role FROM categories WHERE id = ?', [$categoryId]);
    return $role === null ? 'other' : (string) $role;
}

function is_machine_category(?int $categoryId): bool
{
    return category_role($categoryId) === 'machine';
}

function is_peripheral_category(?int $categoryId): bool
{
    return category_role($categoryId) === 'peripheral';
}

/** Kept for callers that still want the slugs; derived, not declared. */
function machine_type_slugs(?int $libraryId = null): array
{
    // One library's, and distinct. Unscoped it returned the same slug once per library
    // that had it - which was harmless in an IN() and misleading everywhere else.
    if ($libraryId === null) {
        $lib       = working_library();
        $libraryId = $lib === null ? 0 : (int) $lib['id'];
    }
    return array_column(
        all("SELECT DISTINCT c.slug FROM categories c JOIN sections s ON s.id = c.section_id
              WHERE s.slug = 'hardware' AND c.role = 'machine' AND c.library_id = ?", [$libraryId]),
        'slug'
    );
}

// ---------------------------------------------------------------------------
// Hardware classes
//
// Console, computer, handheld - and whatever gets added later, which is the
// point of it being a table.
// ---------------------------------------------------------------------------

/**
 * What kind of machine a platform holds, worked out from its machine models.
 *
 * platforms.class_id used to say this directly, which meant the same fact was
 * recorded twice: on the platform and on every machine model filed under it.
 * They could disagree, and the model is the one that decides - an Amiga 500 is a
 * computer because its model says so, not because the Amiga platform was tagged.
 *
 * Keyed by platform *slug*, not id. Machine models point at the template
 * platform while every picker lists a library's copy of it, so an id lookup
 * matches nothing - the same boundary that has caught five other queries in this
 * codebase. The slug is the same on both sides.
 *
 * A platform with no machine models yet reports nothing, which is a real state
 * and not an error.
 */
function platform_kinds(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    // The commonest machine category among the platform's models. A platform
    // whose models disagree is unusual and the majority is the honest answer;
    // ties fall to the name, so at least it is stable.
    $cache = [];
    foreach (all("SELECT pl.slug AS platform_slug, c.id, c.name, c.slug, COUNT(*) AS n
                    FROM hardware_models m
                    JOIN platforms pl  ON pl.id = m.platform_id
                    JOIN categories c  ON c.id = m.category_id AND c.role = 'machine'
                GROUP BY pl.slug, c.id
                ORDER BY pl.slug, n DESC, c.name") as $r) {
        $key = (string) $r['platform_slug'];
        if (!isset($cache[$key])) {
            $cache[$key] = ['id' => (int) $r['id'], 'name' => $r['name'], 'slug' => $r['slug']];
        }
    }
    return $cache;
}


// platform_classes() went with the column: nothing called it once the kind came
// from the models, and a function reading a table nobody writes is the same trap
// as a column nobody sets.

/**
 * Platforms grouped by class, for the browse tree.
 *
 * Anything unclassified is gathered under a null key rather than dropped: a
 * platform somebody added and has not filed yet is still a platform, and
 * hiding it would look like the add had failed.
 */
function platforms_by_class(?int $libraryId = null): array
{
    $out = [];
    // One library when named, otherwise every library this account can reach.
    //
    // "Every library" is right for a browse filter and wrong for a form: an entry is
    // filed into one library, and offering the other library's Amiga alongside this
    // one's put the same name in the list twice with no way to tell them apart.
    $ids = $libraryId !== null && $libraryId > 0
        ? [$libraryId]
        : accessible_library_ids(acting_user(), ACCESS_VIEWER);
    if ($ids === []) {
        return [];
    }
    $where = 'p.library_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $args  = $ids;

    $rows  = all("SELECT p.* FROM platforms p WHERE $where ORDER BY p.name", $args);
    $kinds = platform_kinds();

    // Grouped by what the platform's machine models say it holds, rather than by
    // a column repeating the same fact. A platform with no models yet has no
    // kind to report, which is honest: it goes under "Not yet decided" instead
    // of claiming to be a computer.
    foreach ($rows as $row) {
        $kind = $kinds[(string) $row['slug']] ?? null;
        $key  = $kind['slug'] ?? '';
        $out[$key]['name']   = $kind['name'] ?? 'Not yet decided';
        $out[$key]['slug']   = $kind['slug'] ?? null;
        $out[$key]['rows'][] = $row + ['class_name' => $kind['name'] ?? null,
                                       'class_slug' => $kind['slug'] ?? null];
    }

    // Named groups first, alphabetically; the undecided last, because it is a
    // holding pen rather than a kind.
    uksort($out, fn($a, $b) => $a === '' ? 1 : ($b === '' ? -1 : strcmp($a, $b)));
    return $out;
}

// ---------------------------------------------------------------------------
// Fitting peripherals to machines
//
// A peripheral is catalogued on its own - it has a condition, a serial number,
// photographs and a value - and is fitted to at most one machine at a time.
// The database enforces the "at most one" with a unique key on a generated
// column; these functions are what makes the interface offer only the choices
// that can actually be made.
// ---------------------------------------------------------------------------

/**
 * Peripherals that could be fitted to this machine.
 *
 * Three conditions, and each of them exists because offering the alternative
 * invites a wrong entry:
 *
 *   same platform   a Mega Drive pad does not go in a Saturn
 *   not fitted      it is inside something else, so it is not available
 *   readable        you cannot fit something you are not allowed to see
 *
 * Anything already fitted to this machine is excluded too. It used to be
 * included so a select could show its own value, but the fitted peripherals are
 * listed above the control with their own Remove buttons - so offering them
 * again in "choose a peripheral to fit" invited fitting what was already there.
 */
function fittable_peripherals(int $platformId, ?int $machineId = null): array
{
    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);

    $machineIds = machine_category_ids();
    $notMachine = $machineIds === []
        ? '1 = 1'
        : 'i.category_id NOT IN (' . implode(',', array_fill(0, count($machineIds), '?')) . ')';

    $args = array_merge([$platformId], $machineIds, $aclP);

    // "Not a machine" is not the same claim as "is a peripheral", and treating
    // the two as one is how Deluxe Paint IV came to be offered as something you
    // could install in an Amiga 2000: a boxed game on the same platform is not
    // filed under a machine category, so it passed. The domain is the question
    // being asked here - a peripheral is hardware - and v_items already carries
    // it from the category.
    return all(
        "SELECT i.*, l.parent_item_id AS fitted_to
           FROM v_items i
      LEFT JOIN item_links l
             ON l.child_item_id = i.id AND l.relation = 'installed_in'
          WHERE i.platform_id = ?
            AND i.domain = 'hardware'
            AND $notMachine
            AND $acl
            AND l.id IS NULL
          ORDER BY i.category_name, COALESCE(i.sort_title, i.title)",
        $args
    );
}

/**
 * Machines this peripheral could be installed in, for the peripheral's own form.
 *
 * The same relationship as fittable_peripherals(), read from the other end. A
 * card is edited far more often than the machine it lives in, so asking "where
 * is this fitted?" on the card is the question that actually gets asked - and
 * answering it meant opening the machine and hunting for the card in a list.
 *
 * Narrowed by what the card fits, when anything says: a BigRAM 2008 is offered
 * the A2000, A3000 and A4000 entries and not the A500, because its model records
 * which machines it goes in. With nothing recorded, every machine on the same
 * platform is offered rather than none - an unknown fit is not a refusal.
 */
function installable_machines(int $peripheralId, int $platformId, array $compatibleModelIds = []): array
{
    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);

    $machineIds = machine_category_ids();
    if ($machineIds === []) {
        return [];
    }
    $isMachine = 'i.category_id IN (' . implode(',', array_fill(0, count($machineIds), '?')) . ')';

    $args = array_merge([$platformId], $machineIds, [$peripheralId], $aclP);

    // Only machines whose model is one this card fits, when the card says. The
    // clause is built rather than always applied because an empty IN () list is
    // a syntax error, and "fits nothing recorded" must not read as "fits none".
    $compatibilityClause = '';
    $compatibleModelIds = array_values(array_unique(array_map('intval', $compatibleModelIds)));
    if ($compatibleModelIds !== []) {
        $compatibilityClause = ' AND i.model_id IN ('
            . implode(',', array_fill(0, count($compatibleModelIds), '?')) . ')';
        $args = array_merge($args, $compatibleModelIds);
    }

    return all(
        "SELECT i.*
           FROM v_items i
          WHERE i.platform_id = ?
            AND $isMachine
            AND i.id <> ?
            AND $acl
            $compatibilityClause
          ORDER BY COALESCE(i.sort_title, i.title)",
        $args
    );
}

/** The machine a peripheral is currently installed in, if any. */
function current_host_machine(int $peripheralId): ?array
{
    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);
    return one(
        "SELECT i.*, l.id AS link_id
           FROM item_links l
           JOIN v_items i ON i.id = l.parent_item_id
          WHERE l.child_item_id = ? AND l.relation = 'installed_in' AND $acl
          LIMIT 1",
        array_merge([$peripheralId], $aclP)
    );
}

/** What is currently fitted to this machine. */
function fitted_peripherals(int $machineId): array
{
    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);
    return all(
        "SELECT i.*, l.id AS link_id, l.note AS link_note
           FROM item_links l
           JOIN v_items i ON i.id = l.child_item_id
          WHERE l.parent_item_id = ? AND l.relation = 'installed_in' AND $acl
          ORDER BY i.category_name, COALESCE(i.sort_title, i.title)",
        array_merge([$machineId], $aclP)
    );
}

/**
 * Fit a peripheral, or report why not. Returns [ok, message].
 *
 * The database refuses a second parent outright; this exists so the refusal
 * arrives as a sentence rather than a constraint violation, and so the
 * platform rule is checked at all - SQL cannot express "and of the same
 * machine family".
 */
function fit_peripheral(int $machineId, int $peripheralId): array
{
    if ($machineId === $peripheralId) {
        return [false, 'Something cannot be fitted to itself.'];
    }

    $machine = find_item($machineId);
    $part    = find_item($peripheralId);

    if ($machine === null || $part === null) {
        return [false, 'No such entry.'];
    }
    if (!can_write_item($machine)) {
        return [false, 'That machine is read-only for your account.'];
    }
    if (!can_read_item($part)) {
        return [false, 'No such entry.'];
    }
    if ((int) $machine['platform_id'] !== (int) $part['platform_id']) {
        return [false, sprintf(
            '%s is for the %s, and this is a %s.',
            $part['title'], $part['platform_name'], $machine['platform_name']
        )];
    }
    // Hardware only, and checked here rather than trusted from the picker: the
    // list is a convenience and a POST is not obliged to have come from one. A
    // game is not a peripheral however plausible the id looks, and without this
    // the only thing standing between a boxed copy of Superfrog and a place
    // inside an Amiga 2000 was a dropdown that did not offer it.
    if (($part['domain'] ?? '') !== 'hardware') {
        return [false, $part['title'] . ' is software, not something fitted inside a machine.'];
    }
    if (is_machine_category((int) $part['category_id'])) {
        return [false, $part['title'] . ' is a machine, not something fitted inside one.'];
    }

    $already = one(
        "SELECT parent_item_id FROM item_links
          WHERE child_item_id = ? AND relation = 'installed_in'",
        [$peripheralId]
    );
    if ($already !== null) {
        if ((int) $already['parent_item_id'] === $machineId) {
            return [true, 'Already fitted here.'];
        }
        $host = find_item((int) $already['parent_item_id']);
        return [false, sprintf(
            '%s is already fitted to %s. Remove it there first.',
            $part['title'], $host === null ? 'another machine' : $host['title']
        )];
    }
    if (item_link_would_loop($machineId, $peripheralId)) {
        return [false, 'That would make a loop.'];
    }

    q("INSERT INTO item_links (parent_item_id, child_item_id, relation) VALUES (?, ?, 'installed_in')",
      [$machineId, $peripheralId]);

    return [true, $part['title'] . ' fitted.'];
}

function unfit_peripheral(int $machineId, int $peripheralId): bool
{
    $machine = find_item($machineId);
    if ($machine === null || !can_write_item($machine)) {
        return false;
    }
    q("DELETE FROM item_links
        WHERE parent_item_id = ? AND child_item_id = ? AND relation = 'installed_in'",
      [$machineId, $peripheralId]);
    return true;
}

/** Hardware models: machines and parts together. */
/*
 * vendor_slug travels with vendor_name because a model names the template
 * maker while the form lists a library one. Only the slug is the same on both
 * sides, so only the slug can match them.
 */
/**
 * Models attached at a node, and everything it inherits.
 *
 * The node's own models plus every ancestor's, nearest first - the same rule metadata
 * agents already follow, and the reason category_ancestry() returns the path nearest
 * first. A BigRAM attached to Expansions is offered under Expansions > Memory without
 * anybody attaching it twice.
 *
 * Returns [] for a node that does not exist, rather than silently meaning "all".
 */
function models_for_category(int $categoryId, ?int $libraryId = null): array
{
    $ancestry = category_ancestry($categoryId);
    if ($ancestry === []) {
        return [];
    }
    $all = hardware_models(null, null, $libraryId);
    $rank = array_flip($ancestry);              // 0 = this node, 1 = its parent, ...
    $hit  = array_values(array_filter($all, fn($m) => isset($rank[(int) ($m['category_id'] ?? 0)])));
    usort($hit, function ($a, $b) use ($rank) {
        return [$rank[(int) $a['category_id']], (string) $a['name']]
           <=> [$rank[(int) $b['category_id']], (string) $b['name']];
    });
    return $hit;
}

function hardware_models(?int $platformId = null, ?bool $machinesOnly = null,
                         ?int $libraryId = null): array
{
    // All four joins are LEFT: platform and category are both optional on a
    // model (a card that suits several families, a model nobody has filed yet),
    // and an INNER JOIN quietly dropped exactly those rows from the picker.
    // The vocabulary is joined by id where one was chosen, falling back to the
    // code for models entered before the id existed.
    $sql = 'SELECT hm.*, p.name AS platform_name, p.slug AS platform_slug,
                   c.name AS category_name, c.slug AS category_slug,
                   v.name AS vendor_name,
                   v.slug AS vendor_slug,
                   (SELECT GROUP_CONCAT(f.name ORDER BY f.name SEPARATOR \', \')
                      FROM model_compatibility mf JOIN hardware_models f ON f.id = mf.compatible_model_id
                     WHERE mf.model_id = hm.id
                       AND f.library_id <=> hm.library_id) AS compatible_model_name,
                   hv.name AS interface_name
              FROM hardware_models hm
         LEFT JOIN platforms  p ON p.id = hm.platform_id
         LEFT JOIN categories c ON c.id = hm.category_id
         LEFT JOIN companies    v ON v.id = hm.vendor_id

         LEFT JOIN hardware_vocab hv
                ON hv.id = hm.interface_vocab_id
               OR (hm.interface_vocab_id IS NULL AND hv.code = hm.interface
                   AND hv.platform_id IN (0, COALESCE(hm.platform_id, 0)))
             WHERE 1 = 1';
    $args = [];

    // Whose models. One library's, or every library this account can reach when
    // none is named - never the template rows, which exist only to be copied and
    // are filed under nothing.
    //
    // Models used to be global, so this had to match platforms by slug to bridge
    // template and library copies. They are per library now and point at their own
    // library's platform, so the id matches directly and the subquery is gone.
    if ($libraryId !== null) {
        $sql   .= ' AND hm.library_id = ?';
        $args[] = $libraryId;
    } else {
        $reach = accessible_library_ids(acting_user(), ACCESS_VIEWER);
        if ($reach === []) {
            return [];
        }
        $sql .= ' AND hm.library_id IN (' . implode(',', array_fill(0, count($reach), '?')) . ')';
        $args = array_merge($args, $reach);
    }

    if ($platformId !== null) {
        $sql   .= ' AND hm.platform_id = ?';
        $args[] = $platformId;
    }
    if ($machinesOnly !== null) {
        // role, not a list of slugs.
        //
        // This used to fetch every machine kind's slug and match on it. That worked
        // while slugs were unique across the instance; the trees are per library and
        // per platform now, so the same list contains "amiga-computers" and
        // "pc-computers" from every library at once - hundreds of strings to express
        // one fact the row already carries. The join on categories is right here.
        $sql .= ' AND c.role ' . ($machinesOnly ? '=' : '<>') . " 'machine'";
    }
    return all($sql . ' ORDER BY p.name, hm.sort_order, hm.name', $args);
}

/**
 * Somewhere to put things.
 *
 * Structure, not examples. This lived in seed_library_examples(), and a resync copies
 * structure while deliberately skipping examples - so a library made by syncing had
 * platforms, makers, models and a full category tree, and nowhere to say a thing sits.
 * A shelf layout is scaffolding you would want in any library; four example entries are
 * the thing you do not want dropped into a shelf somebody curates.
 *
 * Locations are per library - library_id is NOT NULL - so unlike platforms and models
 * they cannot be template rows shared by everybody.
 *
 * Additive: it does nothing at all once the library has any location, so a resync never
 * disturbs a layout somebody has built.
 */
function seed_library_locations(int $libraryId): void
{
    if ((int) scalar('SELECT COUNT(*) FROM locations WHERE library_id = ?', [$libraryId]) > 0) {
        return;
    }

    // A house with a floor below ground and two above it, because that is the shape the
    // tree exists to handle: floor is inherited, so Book Shelf 1 is on floor -1 without
    // anybody restating it, and moving the Basement takes its shelves with it.
    $places   = [];
    $addPlace = function (string $name, ?string $parent, ?int $floor) use (&$places, $libraryId) {
        $places[$name] = (int) insert_row('locations', [
            'library_id'  => $libraryId,
            'parent_id'   => $parent === null ? null : ($places[$parent] ?? null),
            'name'        => $name,
            'floor_level' => $floor,
        ]);
    };

    $addPlace('Retroway 22',    null,             null);
    $addPlace('Basement',       'Retroway 22',    -1);
    $addPlace('Book Shelf 1',   'Basement',       null);
    $addPlace('Living Room',    'Retroway 22',    1);
    $addPlace('Master Bedroom', 'Retroway 22',    2);
    $addPlace('Shelf 1',        'Master Bedroom', null);
    location_rebuild_paths();
}

/**
 * A couple of example entries, so a new library is not an empty page.
 *
 * Not in the structure data and not in the SQL seed, for two reasons that are
 * structural rather than a preference: items.library_id is NOT NULL and the seed
 * runs ninety lines before the first library exists, and an item has no slug, so
 * the importer's skip-what-is-already-there cannot key on anything. This runs
 * once, from the installer, straight after the library is made.
 *
 * Guarded on the library being empty. Somebody who deletes the examples and
 * re-syncs should not get them back.
 */
function seed_library_examples(int $libraryId): int
{
    if ((int) scalar('SELECT COUNT(*) FROM items WHERE library_id = ? AND deleted_at IS NULL', [$libraryId]) > 0) {
        return 0;
    }

    $made = 0;
    // Keyed by model slug so the fitting below can find what it just made.
    $created = [];

    // No explanatory notes on these any more. Hardware entries show
    // item_hardware.modifications, not items.notes, so a sentence written here
    // appeared nowhere - and the ones that used to be here described the data
    // model rather than the object, which is not what a note is for.
    foreach ([
        ['amiga-500',   'Amiga 500',   'Rev 6A'],
        ['amiga-2000',  'Amiga 2000',  null],
        ['bigram-2008', 'BigRAM 2008', null],
        // Two cards in the example machine, not one: a single fitted peripheral
        // reads like a limit, and the second one is what shows that a machine
        // holds a list.
        ['zz9000',      'MNT ZZ9000',  'R-4'],
    ] as [$slug, $title, $rev]) {
        // This library's own copy of the model, made by seed_library_hardware()
        // just above. It used to be the template row, which was right while models
        // were shared and wrong now that they are not - an entry belongs to a
        // library and so does everything it points at.
        // year_from as well as vendor_id: the entry inherits both, and
        // selecting one without the other would have left Year null while
        // looking correct. The column is year_from, not year - I wrote the
        // second and it would have been a silent null on every example.
        $model = one('SELECT id, platform_id, category_id, vendor_id, year_from
                        FROM hardware_models
                       WHERE library_id = ? AND slug = ?', [$libraryId, $slug]);
        if ($model === null) {
            continue;   // the structure data was not loaded; nothing to point at
        }
        if ($model['platform_id'] === null) {
            continue;
        }
        $plat = ['id' => (int) $model['platform_id']];

        // Both machines go on the same shelf; the cards are inside the A2000, so
        // they take their whereabouts from it rather than carrying one. One machine
        // gets a position and the other does not, because the field is optional and
        // an example where every row is filled in does not show that.
        $onShelf  = in_array($slug, ['amiga-500', 'amiga-2000'], true);
        $position = $slug === 'amiga-2000' ? '1' : null;

        // Looked up rather than read from $places, which is a local of
        // seed_library_locations() and was never in scope here: the ?? swallowed
        // the undefined variable, so both machines were filed nowhere and the
        // example that exists to show a shelf showed an empty one. Still null if
        // the locations were not seeded, which is a library that asked for no
        // structure - and null is the right answer there.
        $shelfId = $onShelf
            ? (int) (scalar('SELECT id FROM locations WHERE library_id = ? AND name = ?',
                            [$libraryId, 'Book Shelf 1']) ?? 0) ?: null
            : null;

        // The maker and the year come off the model.
        //
        // The shipped models carry both - the Amiga 500 knows it is a Commodore
        // from 1987 - and the entry made from one was leaving them null, so
        // every example arrived with an empty Company and Year. Anything an
        // entry inherits from its model should arrive with it; the point of a
        // model is that you do not type these twice.
        $itemId = (int) insert_row('items', [
            'library_id'   => $libraryId,
            'platform_id'  => (int) $plat['id'],
            'category_id'  => (int) $model['category_id'],
            'model_id'     => (int) $model['id'],
            'title'        => $title,
            'developer_id' => $model['vendor_id'] === null ? null : (int) $model['vendor_id'],
            'release_year' => $model['year_from'] === null ? null : (int) $model['year_from'],
            'status'       => 'owned',
            'location_id'  => $shelfId,
            'location_position' => $onShelf ? $position : null,
        ]);

        if ($rev !== null) {
            insert_row('item_hardware', ['item_id' => $itemId, 'board_revision' => $rev]);
        }
        $created[$slug] = $itemId;
        $made++;
    }

    // Both cards start out fitted to the Amiga 2000, because that is what makes
    // the relationship visible without anybody having to build it first: the
    // machine's page lists two peripherals, and each card's page names the
    // machine it is in.
    //
    // Written straight rather than through fit_peripheral(): that function asks
    // whether the acting user may write to the machine, and this runs from the
    // installer where there is no acting user yet. The invariants it protects are
    // satisfied here by construction - one host, same platform, same library, and
    // a card cannot be its own parent.
    // The software side too, so a fresh install has both halves.
    // Not counted toward $made: these are starter structure, the same
    // category seed_library_hardware() already occupies outside this
    // count, not "example entries" in their own right. Counting them
    // here would have inflated "14 example entries" to 19 for adding
    // five packaging templates nobody asked to see as entries.
    seed_library_software_models($libraryId);
    $made += seed_library_software_examples($libraryId);
    $made += seed_library_video_examples($libraryId);
    $made += seed_library_music_examples($libraryId);

    $host = $created['amiga-2000'] ?? null;
    if ($host !== null) {
        foreach (['bigram-2008', 'zz9000'] as $cardSlug) {
            $card = $created[$cardSlug] ?? null;
            if ($card === null || $card === $host) {
                continue;
            }
            q("INSERT IGNORE INTO item_links (parent_item_id, child_item_id, relation)
               VALUES (?, ?, 'installed_in')", [$host, $card]);
        }
    }

    return $made;
}

/**
 * A couple of boxed programs, so the software side is not empty on a fresh install.
 *
 * The hardware examples have always been there and the software ones never were, which
 * made the two halves look unequally finished - and left nothing to show what a title,
 * a software model and a box-contents list actually do together.
 *
 * Two, deliberately: a game and an application, because the fields that matter differ
 * (a game has a genre and a publisher; an application is filed under Paint and has a
 * version). Both Amiga, because that is where the structure data is richest.
 *
 * Additive by title name, so a resync never produces a second Superfrog.
 */
/**
 * The software packaging templates the example titles themselves
 * already look for by slug - amiga-boxed-game-disk, pc-dos-floppy-
 * bigbox, and so on - never created anywhere. The starter structure
 * copies these in for real libraries when structure.json has entries
 * to offer; it never has for software, so seed_library_software_
 * examples() has been quietly matching against nothing since it was
 * written, and every title it makes has gone in with a null model_id.
 * category_id is left null on purpose: a packaging shape like "PC
 * floppy, big box" describes several genres at once, not one leaf of
 * the tree, the same reasoning the schema's own comment on the column
 * already gives.
 */
function seed_library_software_models(int $libraryId): int
{
    $made = 0;
    foreach ([
        ['slug' => 'amiga-boxed-game-disk',   'name' => 'Amiga, boxed, disk',      'platform' => 'amiga', 'media' => '3.5-inch disk'],
        ['slug' => 'amiga-boxed-application', 'name' => 'Amiga, boxed, disk (application)', 'platform' => 'amiga', 'media' => '3.5-inch disk'],
        ['slug' => 'pc-dos-floppy-bigbox',    'name' => 'PC DOS, big box, floppy', 'platform' => 'pc',    'media' => '3.5-inch disk'],
        ['slug' => 'pc-win9x-cdrom-jewel',    'name' => 'PC Windows, jewel case, CD-ROM', 'platform' => 'pc', 'media' => 'CD-ROM'],
        ['slug' => 'c64-cassette-game',       'name' => 'C64, cassette',           'platform' => 'c64',   'media' => 'cassette'],
    ] as $m) {
        if (one('SELECT id FROM software_models WHERE library_id = ? AND slug = ?', [$libraryId, $m['slug']]) !== null) {
            continue;
        }
        $plat = one('SELECT id FROM platforms WHERE library_id = ? AND slug = ?', [$libraryId, $m['platform']]);
        if ($plat === null) {
            continue;
        }
        insert_row('software_models', [
            'library_id'  => $libraryId,
            'platform_id' => (int) $plat['id'],
            'name'        => $m['name'],
            'slug'        => $m['slug'],
            'media'       => $m['media'],
        ]);
        $made++;
    }
    return $made;
}

function seed_library_software_examples(int $libraryId): int
{
    $made = 0;

    foreach ([
        [
            'name'      => 'Superfrog',
            'model'     => 'amiga-boxed-game-disk',
            'category'  => 'amiga-platformer',
            'developer' => 'Team17',
            'year'      => 1993,
            'media'     => '3.5-inch disk',
            'contents'  => [['Manual', 'yes'], ['Disks', 'yes'], ['Registration card', 'no']],
            'box'       => 'very_good',
        ],
        [
            'name'      => 'Doom',
            'model'     => 'pc-dos-floppy-bigbox',
            'category'  => 'pc-action',
            'developer' => 'id Software',
            'publisher' => 'id Software',
            'year'      => 1993,
            'media'     => '3.5-inch disk',
            'contents'  => [['Manual', 'yes'], ['Disks', 'yes']],
            'box'       => 'good',
        ],
        [
            'name'      => 'Blake Stone: Aliens of Gold',
            'model'     => 'pc-dos-floppy-bigbox',
            'category'  => 'pc-action',
            // Written by one firm and sold by another, which is the shape of
            // nearly every shareware-era PC release and something the Amiga
            // examples never show.
            'developer' => 'JAM Productions',
            'publisher' => 'Apogee Software',
            'year'      => 1993,
            'media'     => '3.5-inch disk',
            'contents'  => [['Manual', 'yes'], ['Disks', 'yes'], ['Registration card', 'unknown']],
            'box'       => 'very_good',
        ],
        [
            'name'      => 'Jagged Alliance: Deadly Games',
            'model'     => 'pc-win9x-cdrom-jewel',
            'category'  => 'pc-strategy',
            'developer' => 'Sir-Tech',
            'publisher' => 'Sir-Tech',
            'year'      => 1996,
            'media'     => 'CD-ROM',
            'contents'  => [['Manual', 'yes'], ['Disc', 'yes']],
            'box'       => 'good',
        ],
        [
            // A cassette, because that is how most C64 games were actually
            // bought - and the only example in the set that is not a box.
            'name'      => 'Revenge of the Mutant Camels',
            'model'     => 'c64-cassette-game',
            'category'  => 'c64-shoot-em-up',
            'developer' => 'Llamasoft',
            'publisher' => 'Llamasoft',
            'year'      => 1984,
            'media'     => 'cassette',
            'contents'  => [['Inlay', 'yes'], ['Cassette', 'yes']],
            'box'       => 'fair',
        ],
        [
            'name'      => 'Deluxe Paint IV',
            'model'     => 'amiga-boxed-application',
            'category'  => 'amiga-paint',
            'developer' => 'Electronic Arts',
            'year'      => 1991,
            'media'     => '3.5-inch disk',
            'contents'  => [['Manual', 'yes'], ['Disks', 'yes'], ['Reference card', 'unknown']],
            'box'       => 'good',
        ],
    ] as $ex) {
        if (one('SELECT id FROM items WHERE library_id = ? AND title = ?',
                [$libraryId, $ex['name']]) !== null) {
            continue;   // already here, so this is a re-run
        }

        $model = one('SELECT id, platform_id, category_id FROM software_models
                       WHERE library_id = ? AND slug = ?', [$libraryId, $ex['model']]);
        $cat   = one('SELECT id, platform_id FROM categories
                       WHERE library_id = ? AND slug = ?', [$libraryId, $ex['category']]);
        if ($cat === null) {
            continue;   // the starter tree was not built; nothing to file it under
        }
        $platformId = (int) ($cat['platform_id'] ?? ($model['platform_id'] ?? 0));
        if ($platformId <= 0) {
            continue;
        }

        // The maker, if the starter companies are here. Matched by name rather than
        // created, so an example never invents a studio.
        $dev = one("SELECT id FROM companies
                     WHERE library_id = ? AND name = ? AND FIND_IN_SET('software', makes)",
                   [$libraryId, $ex['developer']]);
        $pub = empty($ex['publisher']) ? null : one("SELECT id FROM companies
                     WHERE library_id = ? AND name = ? AND FIND_IN_SET('software', makes)",
                   [$libraryId, $ex['publisher']]);

        // The work, recorded once - the thing a second copy would point at.
        //
        // Reused if it is already there. `uq_titles_platform_name` is on
        // (platform, name, year), and the examples only skip themselves when the
        // library already holds *items* - so a library whose entries were deleted
        // but whose titles remain hit that constraint and the seeding died with a
        // PDOException halfway through. Which is exactly what a shelf looks like
        // after somebody clears it out and starts again.
        $existingTitle = one('SELECT id FROM titles WHERE platform_id = ? AND name = ?
                               AND release_year <=> ?',
                             [$platformId, $ex['name'], $ex['year']]);
        if ($existingTitle !== null) {
            $titleId = (int) $existingTitle['id'];
        } else {
        $titleId = (int) insert_row('titles', [
            'platform_id'       => $platformId,
            'category_id'       => (int) $cat['id'],
            'developer_id'      => $dev === null ? null : (int) $dev['id'],
            'software_model_id' => $model === null ? null : (int) $model['id'],
            'name'              => $ex['name'],
            'slug'              => unique_slug('titles', slugify($ex['name'] . '-amiga')),
            'work_key'          => slugify($ex['name']),
            'release_year'      => $ex['year'],
        ]);

        // What the box should hold, on the title: a fact about the release.
        // Written with the title, so a title that was already there keeps the
        // contents it already had rather than gaining a second set of them.
        $order = 0;
        foreach ($ex['contents'] as [$label, $_present]) {
            $order += 10;
            insert_row('title_contents', [
                'title_id' => $titleId, 'label' => $label, 'sort_order' => $order,
            ]);
        }
        }

        // And the copy on the shelf, with what this one actually has.
        $itemId = (int) insert_row('items', [
            'library_id'    => $libraryId,
            'platform_id'   => $platformId,
            'category_id'   => (int) $cat['id'],
            'title_id'      => $titleId,
            'title'         => $ex['name'],
            'developer_id'  => $dev === null ? null : (int) $dev['id'],
            // Who sold it, when that is somebody else. On the Amiga examples the
            // maker and the publisher are the same firm and this stays null; on
            // a shareware-era PC release they are the whole story - JAM
            // Productions wrote Blake Stone and Apogee sold it.
            'publisher_id'  => $pub === null ? null : (int) $pub['id'],
            'release_year'  => $ex['year'],
            'media_type'    => $ex['media'],
            'status'        => 'owned',
            'has_box'       => 1,
            'condition_box' => $ex['box'],
            'completeness'  => 'cib',
        ]);

        $order = 0;
        foreach ($ex['contents'] as [$label, $present]) {
            $order += 10;
            insert_row('item_contents', [
                'item_id' => $itemId, 'label' => $label,
                'present' => $present, 'sort_order' => $order,
            ]);
        }
        $made++;
    }

    return $made;
}

/**
 * A couple of physical releases on the video side, so a fresh install
 * shows what a movie or show looks like alongside the software and
 * hardware examples that have always been there. No software-model
 * equivalent here - video releases have no boxed-release template
 * concept in this schema - so this is items and companies only, not a
 * title-plus-model pair.
 *
 * Two formats deliberately: a disc and a tape, since "Video" was never
 * only Blu-ray.
 */
/**
 * Find or create a company by name, scoped to an explicit library rather
 * than working_library() - company_id_for_name() reads that, and this
 * runs from the installer, where there is no session and so no working
 * library at all. Calling that function here would have silently done
 * nothing every time, exactly as it is documented to: "no library in
 * hand... the template is the right answer here rather than a new
 * orphan row" - correct for an import run, wrong for seeding a library
 * that definitely exists and is passed in directly.
 */
function seed_company_for_name(int $libraryId, string $name, string $makes): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $mine = one('SELECT id, makes FROM companies WHERE name = ? AND library_id = ? LIMIT 1', [$name, $libraryId]);
    if ($mine !== null) {
        if (!in_array($makes, explode(',', (string) $mine['makes']), true)) {
            update_row('companies', (int) $mine['id'], ['makes' => (string) $mine['makes'] . ',' . $makes]);
        }
        return (int) $mine['id'];
    }
    return (int) insert_row('companies', [
        'library_id' => $libraryId,
        'name'       => mb_substr($name, 0, 160),
        'slug'       => unique_slug('companies', slugify($name)),
        'makes'      => $makes,
    ]);
}

/** The same find-or-create pattern seed_company_for_name() uses, for a person instead of a company. */
function seed_person_for_name(int $libraryId, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $mine = one('SELECT id FROM people WHERE name = ? AND library_id = ? LIMIT 1', [$name, $libraryId]);
    if ($mine !== null) {
        return (int) $mine['id'];
    }
    return (int) insert_row('people', [
        'library_id' => $libraryId,
        'name'       => mb_substr($name, 0, 160),
        'slug'       => unique_slug('people', slugify($name)),
    ]);
}

/**
 * A title, a person, and a credit tying them together for one role - the
 * example data's own way of putting a name to a role, the same real
 * mechanism a person cataloguing a real film or record would use, not a
 * free-text field invented to avoid it. Title-less items stay title-less
 * everywhere else in this example set; this is the one place a title
 * exists at all, and only because a credit has nowhere else to attach.
 */
function seed_credit_for_item(int $libraryId, int $titleId, string $roleSlug, string $personName): void
{
    $role = one('SELECT id FROM credit_roles WHERE slug = ?', [$roleSlug]);
    if ($role === null) {
        return;
    }
    $personId = seed_person_for_name($libraryId, $personName);
    if ($personId === null) {
        return;
    }
    if (one('SELECT id FROM credits WHERE title_id = ? AND role_id = ? AND person_id = ?',
            [$titleId, (int) $role['id'], $personId]) !== null) {
        return;
    }
    insert_row('credits', [
        'library_id' => $libraryId, 'title_id' => $titleId, 'role_id' => (int) $role['id'],
        'person_id'  => $personId, 'sort_order' => 100,
    ]);
}

function seed_library_video_examples(int $libraryId): int
{
    $made = 0;

    foreach ([
        [
            'name'      => 'Metropolis Nights',
            'platform'  => 'blu-ray',
            // A genre, not the kind branch above it.
            //
            // These filed under 'movies' and 'tv-shows' because that was as deep
            // as the tree went when they were written. It goes deeper now, and
            // an example sitting at the branch head rather than in a genre is a
            // worked example of the wrong thing - it is the first entry anybody
            // sees, and it teaches where entries go.
            'category'  => 'movie-thriller',
            'studio'    => 'Silverreel Pictures',
            'director'  => 'Corinne Vasquez',
            'year'      => 1998,
            'box'       => 'near_mint',
            'tags'      => 'neo-noir, favourite',
        ],
        [
            'name'      => 'Late Shift Detective',
            'platform'  => 'vhs',
            'category'  => 'tv-crime',
            'studio'    => 'Harborlight Television',
            'director'  => 'Marcus Idoia',
            'year'      => 1991,
            'box'       => 'good',
        ],
    ] as $ex) {
        if (one('SELECT id FROM items WHERE library_id = ? AND title = ?', [$libraryId, $ex['name']]) !== null) {
            continue;
        }

        $plat = one('SELECT id FROM platforms WHERE library_id = ? AND slug = ?', [$libraryId, $ex['platform']]);
        if ($plat === null) {
            continue;
        }
        $cat = one('SELECT id FROM categories WHERE library_id = ? AND platform_id = ? AND source_slug = ?',
                   [$libraryId, $plat['id'], $ex['category']]);
        if ($cat === null) {
            // An instance whose structure feed predates the genres has Movies
            // and TV Shows and nothing under them. Filing one level up is worse
            // than filing it properly and much better than skipping the example
            // entirely, which is what `continue` alone did.
            $fallback = str_starts_with((string) $ex['category'], 'tv-') ? 'tv-shows' : 'movies';
            $cat = one('SELECT id FROM categories WHERE library_id = ? AND platform_id = ? AND source_slug = ?',
                       [$libraryId, $plat['id'], $fallback]);
        }
        if ($cat === null) {
            continue;
        }

        $studioId = seed_company_for_name($libraryId, $ex['studio'], 'video');

        // A title exists here for one reason: a credit has to attach to
        // one, and putting a name to a director is worth that. Nothing
        // else about this title matters the way it does for a software
        // release with a boxed-release template behind it - no contents
        // list, no work_key beyond the slug itself.
        //
        // Checked for an existing one first, the same guard the software
        // examples already needed: titles have no library_id at all, so
        // two different libraries both seeding "Metropolis Nights" on
        // Blu-ray hit the same row rather than two - and without this
        // check, the second library's own insert died on a duplicate
        // rather than reusing what the first already made.
        $existingTitle = one('SELECT id FROM titles WHERE platform_id = ? AND name = ? AND release_year <=> ?',
                             [(int) $plat['id'], $ex['name'], $ex['year']]);
        if ($existingTitle !== null) {
            $titleId = (int) $existingTitle['id'];
        } else {
            $titleId = (int) insert_row('titles', [
                'platform_id'  => (int) $plat['id'],
                'category_id'  => (int) $cat['id'],
                'developer_id' => $studioId,
                'name'         => $ex['name'],
                'slug'         => unique_slug('titles', slugify($ex['name'] . '-' . $ex['platform'])),
                'work_key'     => slugify($ex['name']),
                'release_year' => $ex['year'],
            ]);
        }
        seed_credit_for_item($libraryId, $titleId, 'director', $ex['director']);

        $itemId = (int) insert_row('items', [
            'library_id'    => $libraryId,
            'platform_id'   => (int) $plat['id'],
            'category_id'   => (int) $cat['id'],
            'title_id'      => $titleId,
            'title'         => $ex['name'],
            'developer_id'  => $studioId,
            'release_year'  => $ex['year'],
            'status'        => 'owned',
            'has_box'       => 1,
            'condition_box' => $ex['box'],
            'completeness'  => 'cib',
        ]);
        if (!empty($ex['tags'])) {
            sync_item_tags($itemId, $ex['tags']);
        }
        $made++;
    }

    return $made;
}

/**
 * A couple of releases on the music side - a disc and a record, the same
 * "more than one format" point the video examples make. `developer_id`
 * carries the performing artist here, matching how the same column
 * carries the studio on a video release and the developer on a piece of
 * software: whoever made the thing, not whoever pressed or sold it.
 */
function seed_library_music_examples(int $libraryId): int
{
    $made = 0;

    foreach ([
        [
            'name'      => 'Nightbound Sessions',
            'platform'  => 'cd',
            'category'  => 'music-jazz',
            'artist'    => 'The Coastline Drifters',
            'composer'  => 'Naomi Fentress',
            'label'     => 'Amberlane Records',
            'year'      => 1997,
            'box'       => 'very_good',
        ],
        [
            'name'      => 'Analogue Horizon',
            'platform'  => 'vinyl',
            'category'  => 'music-electronic',
            'artist'    => 'Solvent Skies',
            'composer'  => 'Erik Halvorsen',
            'label'     => 'Amberlane Records',
            'year'      => 1979,
            'box'       => 'good',
            'tags'      => 'favourite',
        ],
    ] as $ex) {
        if (one('SELECT id FROM items WHERE library_id = ? AND title = ?', [$libraryId, $ex['name']]) !== null) {
            continue;
        }

        $plat = one('SELECT id FROM platforms WHERE library_id = ? AND slug = ?', [$libraryId, $ex['platform']]);
        if ($plat === null) {
            continue;
        }
        // Per record now, rather than 'recordings' for both - see the note on
        // the video examples. Falls back to the branch above when the genre is
        // missing, which is what an instance whose structure feed predates the
        // genres will find: an example filed one level up is worse than a
        // perfect one and much better than none.
        $cat = one('SELECT id FROM categories WHERE library_id = ? AND platform_id = ? AND source_slug = ?',
                   [$libraryId, $plat['id'], $ex['category']]);
        if ($cat === null) {
            $cat = one('SELECT id FROM categories WHERE library_id = ? AND platform_id = ? AND source_slug = ?',
                       [$libraryId, $plat['id'], 'recordings']);
        }
        if ($cat === null) {
            continue;
        }

        $artistId = seed_company_for_name($libraryId, $ex['artist'], 'audio');
        $labelId  = seed_company_for_name($libraryId, $ex['label'], 'audio');

        // Same guard the video examples just needed, for the same reason:
        // titles have no library_id at all, so two different libraries
        // both seeding "Nightbound Sessions" on CD hit the same row
        // rather than two, and without this check the second library's
        // own insert died on a duplicate rather than reusing what the
        // first already made.
        $existingTitle = one('SELECT id FROM titles WHERE platform_id = ? AND name = ? AND release_year <=> ?',
                             [(int) $plat['id'], $ex['name'], $ex['year']]);
        if ($existingTitle !== null) {
            $titleId = (int) $existingTitle['id'];
        } else {
            $titleId = (int) insert_row('titles', [
                'platform_id'  => (int) $plat['id'],
                'category_id'  => (int) $cat['id'],
                'developer_id' => $artistId,
                'publisher_id' => $labelId,
                'name'         => $ex['name'],
                'slug'         => unique_slug('titles', slugify($ex['name'] . '-' . $ex['platform'])),
                'work_key'     => slugify($ex['name']),
                'release_year' => $ex['year'],
            ]);
        }
        seed_credit_for_item($libraryId, $titleId, 'composer', $ex['composer']);

        $itemId = (int) insert_row('items', [
            'library_id'    => $libraryId,
            'platform_id'   => (int) $plat['id'],
            'category_id'   => (int) $cat['id'],
            'title_id'      => $titleId,
            'title'         => $ex['name'],
            'developer_id'  => $artistId,
            'publisher_id'  => $labelId,
            'release_year'  => $ex['year'],
            'status'        => 'owned',
            'has_box'       => 1,
            'condition_box' => $ex['box'],
            'completeness'  => 'cib',
        ]);
        if (!empty($ex['tags'])) {
            sync_item_tags($itemId, $ex['tags']);
        }
        $made++;
    }

    return $made;
}

/**
 * A second, shared library - the place examples live, so the personal
 * library a fresh install creates stays genuinely empty and waiting for
 * somebody's own collection, not pre-filled with entries that are not
 * theirs before they have added a single real one.
 *
 * Returns the new library's id, or 0 if there was already a shared one - this runs
 * from the installer and must not add a second on a re-run.
 */
function seed_shared_example_library(int $ownerId): int
{
    if ((int) scalar("SELECT COUNT(*) FROM libraries WHERE kind = 'shared'") > 0) {
        return 0;
    }

    $libraryId = (int) insert_row('libraries', [
        'name'         => 'The club shelf',
        'slug'         => unique_slug('libraries', 'the-club-shelf'),
        'description'  => 'A shared library, as an example. It belongs to whoever set this '
                        . 'instance up; invite people to it, or publish it so anybody '
                        . 'signed in can join. Rename it, empty it, or delete it.',
        'kind'         => 'shared',
        'owner_id'     => $ownerId,

        // Not published.
        //
        // This shipped with public_read = 1, which grants every account on the instance
        // read access the moment it signs in - so a directory user's first sight of
        // RetroHive was three entries from somebody else's shelf, on their own
        // dashboard, with no way to tell why. The ACL was behaving correctly; the
        // default was wrong.
        //
        // An example of a shared library does not have to be shared with everyone to
        // demonstrate the point. Publishing it is one tick on its own page, and then it
        // shows up under "Open to join" for people to take or leave.
        'public_read'  => 0,
        'public_write' => 0,
        'accent_color' => '#89b4fa',
        'is_personal'  => 0,
        'is_default'   => 0,
        'sort_order'   => 20,
    ]);
    q('INSERT IGNORE INTO library_members (library_id, user_id, access, granted_by) VALUES (?, ?, ?, ?)',
      [$libraryId, $ownerId, ACCESS_OWNER, $ownerId]);
    $GLOBALS['__membership_cache'] = [];

    // The same cross-domain examples the personal library used to get
    // instead - hardware, software, video, and music, not the three
    // hand-picked machines this used to seed on its own. Examples belong
    // somewhere that plainly says "this is somebody else's, edit or
    // delete freely" rather than the one shelf a person is promised as
    // their own; this library exists to be that place, so it is where
    // they go now, not scattered across both.
    seed_library_hardware($libraryId);
    seed_library_examples($libraryId);

    return $libraryId;
}

/**
 * Why this category cannot be removed, or null if it can.
 *
 * Only one is protected, and it is protected for a structural reason rather than a
 * sentimental one: 'games' is what every game genre hangs off, and the entry form
 * narrows its genre list by it. Deleting it would empty the genre tree - the foreign
 * key is ON DELETE SET NULL - and leave the software half of the catalogue with
 * nothing to file under.
 *
 * Matched on the slug, not the id, so it survives a reseed.
 */
function category_protected_reason(int $categoryId): ?string
{
    $row = one('SELECT c.id, c.name, s.slug AS domain, c.path, c.library_id, c.parent_id
                  FROM categories c JOIN sections s ON s.id = c.section_id WHERE c.id = ?',
               [$categoryId]);
    if ($row === null) {
        return null;
    }

    // A machine's own branch is the machine's, not the tree's.
    //
    // Every root is a platform and carries its id; deleting one here would leave
    // the platform standing with nothing to file under it, which is the exact
    // state the "machines with no branch" maintenance job exists to repair. The
    // place to remove a machine is the platform editor, where removing it takes
    // both halves.
    if ($row['parent_id'] === null) {
        return 'This is ' . (string) $row['name'] . '\'s own branch. Remove the machine under '
             . 'Platforms and its branch goes with it.';
    }

    // Structural, not by name.
    //
    // This used to refuse anything whose slug was exactly 'games'. That was true while
    // there was one flat taxonomy; the trees are rooted at machines now, so the branch
    // is "amiga-games" and the check silently stopped matching anything at all - a
    // guard that had quietly become decoration.
    //
    // What actually needs defending is not a word but a capability: a library has to
    // keep somewhere to file software. Deleting the last such branch leaves the
    // software half of the catalogue with nothing to point at, and no message anywhere
    // saying why. Everything else - branches with children, categories holding entries -
    // is somebody's to remove, and the delete path counts those separately.
    if ((string) $row['domain'] !== 'software') {
        return null;
    }

    $subtree = (string) $row['path'] . '%';
    $left = (int) scalar(
        "SELECT COUNT(*) FROM categories c JOIN sections s ON s.id = c.section_id
          WHERE c.library_id <=> ? AND s.slug = 'software' AND c.path NOT LIKE ?",
        [$row['library_id'], $subtree]
    );
    if ($left > 0) {
        return null;
    }

    $going = (int) scalar('SELECT COUNT(*) FROM categories WHERE path LIKE ?', [$subtree]);
    return sprintf(
        '%s is the only place this library can file software%s. Removing it would leave '
        . 'nothing to put a game or an application under. Add another software branch '
        . 'first, or rename this one if the word is wrong.',
        (string) $row['name'],
        $going > 1 ? sprintf(' - it and %d branch(es) beneath it', $going - 1) : ''
    );
}

/**
 * Make sure the software side has something to file under.
 *
 * Categories are still shared across the instance rather than per library, so this is
 * one row that has to exist rather than one per library. Called where a library is set
 * up, because that is the moment somebody would notice it missing - an instance whose
 * structure data never loaded would otherwise offer no software type at all.
 */
function ensure_games_category(?int $libraryId = null): int
{
    // Per library, because the taxonomy is. The one hardcoded slug in the codebase, and
    // it stays hardcoded on purpose: 'games' is what every game genre hangs off, so its
    // absence is a broken library rather than a matter of taste.
    if ($libraryId !== null) {
        $libId = $libraryId;
    } else {
        $lib   = working_library();
        $libId = $lib === null ? null : (int) $lib['id'];
    }

    // Any software branch will do.
    //
    // What this guarantees is a place to file software, not a row called "Games". The
    // trees are rooted at machines now, so the branch is "amiga-games" - and matching on
    // the word made this create an orphan root beside perfectly good per-platform ones.
    //
    // Shallowest first, so it prefers a real branch over a leaf tucked deep in one.
    $softwareSectionId = (int) scalar("SELECT id FROM sections WHERE slug = 'software'");
    $id = scalar(
        "SELECT id FROM categories
          WHERE library_id <=> ? AND section_id = ?
          ORDER BY depth, sort_order, id LIMIT 1",
        [$libId, $softwareSectionId]
    );
    if ($id !== null) {
        return (int) $id;
    }
    $newId = (int) insert_row('categories', [
        'library_id' => $libId,
        'name'       => 'Games',
        'slug'       => 'games',
        'section_id' => $softwareSectionId,
        'role'       => 'other',
        'sort_order' => 10,
    ]);
    rebuild_category_paths($libId);
    return $newId;
}

/**
 * Build this library's category tree, one branch per machine.
 *
 * Platform at the root, then Hardware and Software, then the shipped kinds beneath
 * each. Slugs are prefixed with the platform, because they are unique per library and
 * "adapters" has to be able to mean a different row under the Amiga than under the PC.
 *
 * Only platforms this library has models for. Building all sixty-three would be four
 * thousand rows to describe machines nobody owns; the ones that arrive with models are
 * the ones the structure data actually knows something about, and `Copy the branch` in
 * the editor is how any other platform gets a tree later.
 */
/**
 * Replace this library's rows with the template's, matched on slug.
 *
 * Only the descriptive columns: ids, library_id and anything the library owns - what is
 * filed where, which entries exist - are never touched. Rows the templates do not have
 * are left alone rather than deleted, because "sync" is not "make identical": a machine
 * somebody added by hand is theirs.
 */
function library_overwrite_from_templates(int $libraryId): void
{
    q("UPDATE companies m
         JOIN companies t ON t.library_id IS NULL AND t.slug = m.slug
          SET m.name = t.name, m.makes = t.makes, m.country = t.country,
              m.founded_year = t.founded_year, m.defunct_year = t.defunct_year,
              m.website = t.website, m.wikipedia_url = t.wikipedia_url, m.notes = t.notes
        WHERE m.library_id = ?", [$libraryId]);

    q("UPDATE platforms m
         JOIN platforms t ON t.library_id IS NULL AND t.slug = m.slug
          SET m.name = t.name, m.year_introduced = t.year_introduced,
              m.accent_color = t.accent_color, m.domains = t.domains
        WHERE m.library_id = ?", [$libraryId]);

    q("UPDATE hardware_models m
         JOIN hardware_models t ON t.library_id IS NULL AND t.slug = m.slug
          SET m.name = t.name, m.year_from = t.year_from, m.fits_note = t.fits_note,
              m.interface = t.interface, m.notes = t.notes, m.sort_order = t.sort_order
        WHERE m.library_id = ?", [$libraryId]);

    // Categories are matched on the platform-prefixed slug, and only their wording is
    // replaced - not parent_id, because a branch somebody moved is a decision, and
    // re-parenting it here would undo that silently while claiming to have synced.
    // Joined on source_slug, which is indexed, rather than on a CONCAT() no index can
    // serve. Same rows, without the scan.
    q("UPDATE categories m
         JOIN categories t ON t.library_id IS NULL AND t.slug = m.source_slug
          SET m.name = t.name, m.description = t.description, m.role = t.role
        WHERE m.library_id = ? AND m.source_slug IS NOT NULL", [$libraryId]);
}

/** This library's software models, newest question first: which platform is it for. */
function software_models(?int $libraryId = null): array
{
    if ($libraryId === null) {
        $lib       = working_library();
        $libraryId = $lib === null ? 0 : (int) $lib['id'];
    }
    return all(
        'SELECT m.*, p.name AS platform_name, p.slug AS platform_slug, c.name AS category_name,
                (SELECT COUNT(*) FROM software_model_fields f WHERE f.model_id = m.id) AS field_count,
                (SELECT COUNT(*) FROM software_model_contents k WHERE k.model_id = m.id) AS content_count,
                (SELECT COUNT(*) FROM titles t WHERE t.software_model_id = m.id) AS usage_count
           FROM software_models m
      LEFT JOIN platforms  p ON p.id = m.platform_id
      LEFT JOIN categories c ON c.id = m.category_id
          WHERE m.library_id = ?
       ORDER BY p.name, m.sort_order, m.name',
        [$libraryId]
    );
}

/** What a model says a title should start with. */
function software_model_fields(int $modelId): array
{
    return all('SELECT * FROM software_model_fields WHERE model_id = ? ORDER BY sort_order, id', [$modelId]);
}

function software_model_contents(int $modelId): array
{
    return all('SELECT * FROM software_model_contents WHERE model_id = ? ORDER BY sort_order, id', [$modelId]);
}

/**
 * What a release made from this model comes on - a row per medium, because a
 * boxed release is often more than one. The third of the three child lists, and
 * the one that had no accessor of its own: both callers wrote the query out
 * inline, which is why the API had no obvious thing to call.
 */
function software_model_media(int $modelId): array
{
    return all('SELECT * FROM software_model_media WHERE model_id = ? ORDER BY sort_order, id', [$modelId]);
}

function seed_library_categories(int $libraryId): void
{
    // Built set-wise, not one platform at a time.
    //
    // This used to loop platform x domain x depth, one INSERT ... SELECT each: about
    // six hundred and thirty round trips to make three and a half thousand rows, and
    // most of an install's twenty seconds. The work is identical for every platform of
    // the same class, so it is grouped by class instead - two domains, five depths,
    // three classes, plus two statements for the roots. Twelve or so, not six hundred.
    //
    // The class of each platform is still decided one at a time, because that is a
    // judgement (models first, the platform's own answer otherwise) rather than a bulk
    // operation - but it is one small query per platform against template data, not an
    // insert.
    $platforms = all(
        'SELECT id, name, slug, domains FROM platforms WHERE library_id = ? ORDER BY name',
        [$libraryId]
    );
    if ($platforms === []) {
        return;
    }

    // Which kind of machine each one is, from the template models filed under it. A
    // Game Boy has no disk controller and no antivirus tools, and copying one tree
    // verbatim gave it both.
    //
    // One query for all of them: the majority machine kind per platform slug.
    $derived = [];
    foreach (all(
        "SELECT tp.slug AS pslug, c.slug AS kind, COUNT(*) AS n
           FROM hardware_models m
           JOIN platforms  tp ON tp.id = m.platform_id AND tp.library_id IS NULL
           JOIN categories c  ON c.id = m.category_id AND c.role = 'machine'
          WHERE m.library_id IS NULL
       GROUP BY tp.slug, c.slug
       ORDER BY tp.slug, n DESC, c.slug"
    ) as $r) {
        $derived[(string) $r['pslug']] ??= (string) $r['kind'];   // first wins: the majority
    }

    // Which section(s) a kind of platform participates in. A computer, console or
    // handheld sits in both Hardware and Software - the same machine is a physical
    // object and something with software written for it. A video or audio format
    // sits in exactly one: a VHS tape is not "hardware" in the sense a motherboard
    // is, and it does not run software. This is what lets step 1 and step 2 below
    // stay generic across every section rather than hardcoding two.
    //
    // Not read from a stored column any more - platforms.domains is the real,
    // direct answer to that now, the same shape companies.makes already used.
    // What this table still earns its keep for is step 3's finer-grained
    // matching: 'computer', 'console' and 'handheld' all mean domains
    // hardware+software, but a template category can still be scoped to just
    // one of the three (a console has no BASIC ROM category), and that
    // distinction has no representation in domains at all - domains says
    // which sections a platform is in, not which kind of hardware it is
    // within Hardware. Kept as a fixed table because it is genuinely fixed:
    // a kind's domains are a property of what the kind means, not data that
    // varies per platform the way a platform's own domains column now can.
    $kindDomains = [
        'computer'     => ['hardware', 'software'],
        'console'      => ['hardware', 'software'],
        'handheld'     => ['hardware', 'software'],
        'video-format' => ['video'],
        'audio-format' => ['audio'],
    ];
    $byKind = array_fill_keys(array_keys($kindDomains), []);
    $fresh  = [];
    foreach ($platforms as $pf) {
        $pSlug = (string) $pf['slug'];
        $built = one('SELECT id, platform_id FROM categories
                       WHERE library_id = ? AND slug = ? AND parent_id IS NULL',
                     [$libraryId, $pSlug]);
        if ($built !== null) {
            // Already built - unless the machine it belonged to went away.
            //
            // categories.platform_id is ON DELETE SET NULL, so deleting a
            // platform leaves its branch standing with the right name, the right
            // slug and nothing behind it. This check used to match on the slug
            // alone, so a resync saw the branch, called it built, and skipped the
            // machine for ever: the branch said "Sharp X68000" on screen while
            // nothing filed under it could say what it ran on, and running the
            // resync again changed nothing. Relinking is the whole repair - the
            // tree beneath it is still there.
            if ($built['platform_id'] === null) {
                q('UPDATE categories SET platform_id = ? WHERE id = ?',
                  [(int) $pf['id'], (int) $built['id']]);
                q('UPDATE categories SET platform_id = ? WHERE library_id = ? AND platform_id IS NULL
                    AND id IN (SELECT * FROM (SELECT c.id FROM categories c
                                               WHERE c.library_id = ?
                                                 AND c.slug LIKE ?) AS t)',
                  [(int) $pf['id'], $libraryId, $libraryId, $pSlug . '-%']);
            }
            continue;
        }
        $fresh[] = $pf;

        // The template's machine kinds are 'computers', 'console', 'handheld'; the
        // kinds on a category row are the singular words. Models decide where they
        // exist; the platform's own domains answer otherwise - a single 'video' or
        // 'audio' domain means there is only one honest kind it can be, so that
        // case needs no model evidence at all the way computer/console/handheld
        // genuinely does.
        $domainList = explode(',', (string) ($pf['domains'] ?? ''));
        $kind = ['computers' => 'computer', 'console' => 'console',
                 'handheld' => 'handheld'][$derived[$pSlug] ?? ''] ?? match (true) {
            $domainList === ['video'] => 'video-format',
            $domainList === ['audio'] => 'audio-format',
            default                   => 'computer',
        };
        $byKind[$kind][] = (int) $pf['id'];
    }
    if ($fresh === []) {
        return;
    }

    $sectionIdBySlug = [];
    foreach (all('SELECT id, slug FROM sections') as $s) {
        $sectionIdBySlug[(string) $s['slug']] = (int) $s['id'];
    }
    // Which section a platform's own root node belongs to - the first of its
    // kind's sections, which is 'hardware' for anything computer-shaped and the
    // (only) section for a format. The root is an organisational grouping rather
    // than content itself; what actually lives in a section is the branch or
    // branches step 2 creates under it.
    $branchLabels = [
        'hardware' => ['Hardware', 10], 'software' => ['Software', 20],
        'video'    => ['Video', 10],    'audio'    => ['Audio', 10],
    ];

    // 1. The machines themselves, one statement per kind rather than one for
    //    everything - the root's section now varies by kind, so platforms that
    //    do not share a section cannot share a statement either.
    foreach ($byKind as $kind => $platIdsForClass) {
        if ($platIdsForClass === []) {
            continue;
        }
        $rootSectionId = $sectionIdBySlug[$kindDomains[$kind][0]];
        $cin = implode(',', array_fill(0, count($platIdsForClass), '?'));
        q("INSERT INTO categories (library_id, section_id, role, platform_id, name, slug, sort_order)
           SELECT ?, ?, 'other', p.id, p.name, p.slug, 10
             FROM platforms p
            WHERE p.id IN ($cin)", array_merge([$libraryId, $rootSectionId], $platIdsForClass));
    }

    // 2. One branch per section a platform's kind participates in - Hardware and
    //    Software for a computer, just Video for a DVD, just Music for a CD.
    foreach ($byKind as $kind => $platIdsForClass) {
        if ($platIdsForClass === []) {
            continue;
        }
        $cin = implode(',', array_fill(0, count($platIdsForClass), '?'));
        foreach ($kindDomains[$kind] as $sectionSlug) {
            [$label, $ord] = $branchLabels[$sectionSlug];
            $sid = $sectionIdBySlug[$sectionSlug];
            q("INSERT INTO categories
                   (library_id, section_id, role, platform_id, parent_id, name, slug, sort_order, source_slug)
               SELECT ?, ?, 'other', r.platform_id, r.id, ?, CONCAT(p.slug, '-', ?), ?, ?
                 FROM categories r
                 JOIN platforms p ON p.id = r.platform_id
                WHERE r.library_id = ? AND r.parent_id IS NULL AND r.platform_id IN ($cin)",
              array_merge([$libraryId, $sid, $label, $sectionSlug, $ord, $sectionSlug, $libraryId], $platIdsForClass));
        }
    }

    // 3. The kinds, one statement per section, depth and kind.
    //
    //    Depth matters because a row resolves its parent through the copy made on the
    //    pass before; kind matters because it decides which kinds a machine gets at
    //    all. Platforms of the same kind at the same depth are identical work.
    $maxDepth = (int) scalar('SELECT COALESCE(MAX(depth), 0) FROM categories WHERE library_id IS NULL');

    foreach ($byKind as $kind => $platIds) {
        if ($platIds === []) {
            continue;
        }
        $cin = implode(',', array_fill(0, count($platIds), '?'));
        foreach ($kindDomains[$kind] as $sectionSlug) {
            $sid = $sectionIdBySlug[$sectionSlug];
            for ($d = 0; $d <= $maxDepth; $d++) {
                q("INSERT INTO categories
                       (library_id, section_id, role, parent_id, platform_id, name, slug,
                        source_slug, description, sort_order, stock_image)
                   SELECT ?, ?, t.role,
                          COALESCE(mineParent.id, domNode.id),
                          p.id, t.name, CONCAT(p.slug, '-', t.slug), t.slug,
                          t.description, t.sort_order,
                          -- Carried down with the rest of what the template says.
                          -- default_image_filename deliberately is not: that one names
                          -- a file on this instance's disk and a template has none, but
                          -- a stock picture ships with the package and exists on every
                          -- install by definition, so the copy can safely name it.
                          t.stock_image
                     FROM categories t
                     JOIN platforms  p ON p.id IN ($cin)
                     -- Joined on (library_id, platform_id, source_slug), all indexed.
                     -- These were CONCAT() comparisons, which no index can serve.
                     JOIN categories domNode
                       ON domNode.library_id = ? AND domNode.platform_id = p.id
                      AND domNode.source_slug = ?
                LEFT JOIN categories tParent
                       ON tParent.id = t.parent_id
                LEFT JOIN categories mineParent
                       ON mineParent.library_id = ? AND mineParent.platform_id = p.id
                      AND mineParent.source_slug = tParent.slug
                LEFT JOIN categories mine
                       ON mine.library_id = ? AND mine.platform_id = p.id
                      AND mine.source_slug = t.slug
                    WHERE t.library_id IS NULL AND t.section_id = ? AND t.depth = ?
                      AND mine.id IS NULL
                      -- Empty applies_to means every machine; otherwise this kind has
                      -- to be in the list. FIND_IN_SET, so 'computer,console' matches
                      -- either without matching 'computerish'.
                      AND (t.applies_to = '' OR FIND_IN_SET(?, t.applies_to))
                      -- A branch whose parent was skipped is skipped with it, rather
                      -- than being re-homed on the section's node.
                      AND (t.parent_id IS NULL OR mineParent.id IS NOT NULL)",
                  array_merge([$libraryId, $sid], $platIds,
                              [$libraryId, $sectionSlug, $libraryId, $libraryId, $sid, $d, $kind]));
            }
        }
    }

    rebuild_category_paths($libraryId);
}

/** What a release's box should contain, in order. */
function title_contents(int $titleId): array
{
    return all('SELECT * FROM title_contents WHERE title_id = ? ORDER BY sort_order, id', [$titleId]);
}

/** Replace a release's contents list. */
function set_title_contents(int $titleId, array $labels, array $notes = []): void
{
    q('DELETE FROM title_contents WHERE title_id = ?', [$titleId]);
    $n = 0;
    foreach ($labels as $i => $label) {
        $label = trim((string) $label);
        if ($label === '') {
            continue;
        }
        $n += 10;
        q('INSERT IGNORE INTO title_contents (title_id, label, note, sort_order) VALUES (?, ?, ?, ?)',
          [$titleId, mb_substr($label, 0, 120), nullify($notes[$i] ?? null), $n]);
    }
}

/** What this copy has, in order. */
function item_contents(int $itemId): array
{
    return all('SELECT * FROM item_contents WHERE item_id = ? ORDER BY sort_order, id', [$itemId]);
}

/**
 * Replace what a copy has.
 *
 * Three states per line, because "not ticked" on a fresh entry means nobody has looked
 * yet - which is a different fact from having checked and found the manual missing, and
 * telling them apart is the point of keeping the list at all.
 */
function set_item_contents(int $itemId, array $labels, array $present = [], array $notes = []): void
{
    q('DELETE FROM item_contents WHERE item_id = ?', [$itemId]);
    $n = 0;
    foreach ($labels as $i => $label) {
        $label = trim((string) $label);
        if ($label === '') {
            continue;
        }
        $state = (string) ($present[$i] ?? 'unknown');
        $n += 10;
        q('INSERT IGNORE INTO item_contents (item_id, label, present, note, sort_order)
           VALUES (?, ?, ?, ?, ?)',
          [$itemId, mb_substr($label, 0, 120),
           in_array($state, ['yes', 'no', 'unknown'], true) ? $state : 'unknown',
           nullify($notes[$i] ?? null), $n]);
    }
}

/**
 * The lines to show for an entry: its own, or the release's as a starting point.
 *
 * A copy that has never been checked shows the release's list with everything unknown,
 * so the first thing you do is answer it rather than type it out.
 */
function item_contents_for_form(?int $itemId, ?int $titleId): array
{
    $own = $itemId === null ? [] : item_contents($itemId);
    if ($own !== []) {
        return $own;
    }
    $out = [];
    foreach ($titleId === null ? [] : title_contents($titleId) as $row) {
        $out[] = ['label' => $row['label'], 'present' => 'unknown', 'note' => $row['note']];
    }
    return $out;
}

/** How complete a copy is, as a sentence rather than a fraction nobody reads. */
function item_completeness_note(int $itemId): ?string
{
    $rows = item_contents($itemId);
    if ($rows === []) {
        return null;
    }
    $have = 0;
    $missing = [];
    foreach ($rows as $r) {
        if ($r['present'] === 'yes') {
            $have++;
        } elseif ($r['present'] === 'no') {
            $missing[] = (string) $r['label'];
        }
    }
    if ($missing === []) {
        return $have === count($rows) ? 'Complete' : null;
    }
    return 'Missing ' . implode(', ', array_map('mb_strtolower', $missing));
}

/** Which machines this particular card fits, as recorded on the item itself. */
function item_compatibility_ids(int $itemId): array
{
    return array_map('intval', array_column(
        all('SELECT compatible_model_id FROM item_compatibility WHERE item_id = ?', [$itemId]),
        'compatible_model_id'
    ));
}

/**
 * Replace what one card says it fits.
 *
 * Only reached when the model has nothing to say - see effective_compatibility(). Machines
 * only, and only ones that exist, because the browser sends whatever it likes.
 */
function set_item_compatibility(int $itemId, array $compatibleIds): void
{
    q('DELETE FROM item_compatibility WHERE item_id = ?', [$itemId]);
    foreach (array_unique(array_map('intval', $compatibleIds)) as $id) {
        if ($id <= 0) {
            continue;
        }
        // Same library as the entry, as well as being a machine. Models are per
        // library, so without this an id posted by hand could point a private
        // card at another library's machine.
        $ok = one("SELECT m.id FROM hardware_models m
                     JOIN categories c ON c.id = m.category_id AND c.role = 'machine'
                     JOIN items i ON i.id = ?
                    WHERE m.id = ? AND m.library_id = i.library_id", [$itemId, $id]);
        if ($ok !== null) {
            q('INSERT IGNORE INTO item_compatibility (item_id, compatible_model_id) VALUES (?, ?)', [$itemId, $id]);
        }
    }
}

/**
 * What a card actually fits, and where that answer came from.
 *
 * Returns ['ids' => int[], 'names' => string[], 'from' => 'model'|'item'|'none'].
 *
 * The model wins when it has an answer: a copy of a BigRAM 2008 cannot fit
 * something a BigRAM 2008 does not, so letting the item disagree would be letting
 * it be wrong. The item's own list is kept either way, so detaching the model
 * later does not lose what somebody typed.
 */
function effective_compatibility(?int $itemId, ?int $modelId): array
{
    $names = function (array $ids): array {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return array_column(
            all("SELECT name FROM hardware_models WHERE id IN ($in) ORDER BY name", $ids),
            'name'
        );
    };

    if ($modelId !== null && $modelId > 0) {
        $ids = model_compatibility_ids($modelId);
        if ($ids !== []) {
            return ['ids' => $ids, 'names' => $names($ids), 'from' => 'model'];
        }
    }

    $own = $itemId !== null && $itemId > 0 ? item_compatibility_ids($itemId) : [];
    return ['ids' => $own, 'names' => $names($own), 'from' => $own === [] ? 'none' : 'item'];
}

/** Which machine models a peripheral fits. Ids only; the form needs no more. */
function model_compatibility_ids(int $modelId): array
{
    return array_map('intval', array_column(
        all('SELECT compatible_model_id FROM model_compatibility WHERE model_id = ?', [$modelId]),
        'compatible_model_id'
    ));
}

/**
 * Replace the set of machines a peripheral fits.
 *
 * Replace rather than merge: the form shows every box, so what comes back is
 * the whole answer, and a box somebody cleared has to actually clear.
 */
function set_model_compatibility(int $modelId, array $compatibleIds): void
{
    q('DELETE FROM model_compatibility WHERE model_id = ?', [$modelId]);
    foreach (array_unique(array_map('intval', $compatibleIds)) as $id) {
        if ($id <= 0 || $id === $modelId) {
            continue;   // nothing fits itself
        }
        // Machines only, and only ones that exist: the browser sends whatever
        // it likes.
        // A machine, and one in the same library as the card. Both ends of a
        // compatibility claim have to belong to the same shelf now that models do.
        $ok = one("SELECT m.id FROM hardware_models m
                     JOIN categories c ON c.id = m.category_id AND c.role = 'machine'
                     JOIN hardware_models card ON card.id = ?
                    WHERE m.id = ? AND m.library_id <=> card.library_id", [$modelId, $id]);
        if ($ok !== null) {
            q('INSERT IGNORE INTO model_compatibility (model_id, compatible_model_id) VALUES (?, ?)', [$modelId, $id]);
        }
    }
}

/** The fields a model carries, in the order somebody put them in. */
function model_fields(int $modelId): array
{
    return all('SELECT * FROM model_fields WHERE model_id = ? ORDER BY sort_order, label', [$modelId]);
}

/** What a machine will take. */
function model_slots(int $modelId): array
{
    return all(
        'SELECT hv.code, hv.name, ms.quantity, ms.notes
           FROM model_slots ms JOIN hardware_vocab hv ON hv.id = ms.vocab_id
          WHERE ms.model_id = ? ORDER BY hv.sort_order, hv.name',
        [$modelId]
    );
}

/**
 * Hardware types only, for a form that is about hardware.
 *
 * Offering "Games" when adding a graphics card is not merely untidy - it makes
 * a wrong entry one mis-click away.
 */
function hardware_types(): array
{
    return filing_options('hardware');
}

/** Machine makers, for the model editor. */
function all_vendors(): array
{
    return library_vendors();
}

// ---------------------------------------------------------------------------
// Search
// ---------------------------------------------------------------------------

/**
 * Turn what somebody typed into something MATCH ... AGAINST will accept.
 *
 * Boolean mode gives operators meaning: a stray '+' or '-' in a title - and
 * retro titles are full of them, from +H2K to Bomb Jack II - is a syntax error
 * rather than a character. Each word is quoted, and a trailing wildcard is
 * added to the last one so typing "speedb" still finds Speedball while you are
 * still typing.
 *
 * Words shorter than the server's ft_min_word_len are dropped by MariaDB
 * silently; the LIKE clause alongside this in build_item_filters() is what
 * catches those.
 */
function fulltext_query(string $raw): string
{
    $words = preg_split('/\s+/u', trim($raw)) ?: [];
    $out   = [];
    foreach ($words as $i => $word) {
        $clean = str_replace(['"', '*', '(', ')', '@', '~', '<', '>'], ' ', $word);
        $clean = trim($clean);
        if ($clean === '') {
            continue;
        }
        $isLast = $i === count($words) - 1;
        // Quoting neutralises the operators; the wildcard has to sit outside
        // the quotes to still mean anything.
        $out[] = $isLast && mb_strlen($clean) >= 3
            ? '+' . $clean . '*'
            : '+"' . $clean . '"';
    }
    return $out === [] ? '' : implode(' ', $out);
}

// ---------------------------------------------------------------------------
// Titles: the thing, as against your copy of it
//
// hardware_models has always recorded what a Blizzard 1230 IV *is*, once,
// separately from the one on your shelf. Software had no equivalent, so every
// copy re-entered the title, developer, publisher, year and genre - which meant
// a second copy was a retyping exercise, the Amiga and C64 releases of one game
// had no relationship at all, and a metadata import run twice produced two
// descriptions that could then drift apart.
//
// A title is deliberately per platform. The Amiga and C64 Speedball 2 are
// different artefacts and pretending otherwise loses more than it saves;
// work_key is the shared handle that puts them back together when you want
// "every version of this game".
// ---------------------------------------------------------------------------

function find_title(int $id): ?array
{
    return one('SELECT * FROM v_titles WHERE id = ?', [$id]);
}


/** Every release of one work, across platforms. */
function title_siblings(string $workKey, ?int $excludeId = null): array
{
    if ($workKey === '') {
        return [];
    }
    $sql  = 'SELECT * FROM v_titles WHERE work_key = ?';
    $args = [$workKey];
    if ($excludeId !== null) {
        $sql   .= ' AND id <> ?';
        $args[] = $excludeId;
    }
    return all($sql . ' ORDER BY platform_name, release_year', $args);
}

/** Titles matching a search box, optionally narrowed to one machine. */
function search_titles(string $q, ?int $platformId = null, int $limit = 50): array
{
    $sql  = 'SELECT * FROM v_titles WHERE 1 = 1';
    $args = [];
    if ($platformId !== null && $platformId > 0) {
        $sql   .= ' AND platform_id = ?';
        $args[] = $platformId;
    }
    if (trim($q) !== '') {
        $sql   .= ' AND (name LIKE ? OR subtitle LIKE ?)';
        $like   = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($q)) . '%';
        $args[] = $like;
        $args[] = $like;
    }
    $limit = max(1, min(200, $limit));
    return all($sql . " ORDER BY COALESCE(sort_name, name) LIMIT $limit", $args);
}

/** Only the titles these items point at, for a sync payload. */
function titles_for_items(array $titleIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $titleIds))));
    if ($ids === []) {
        return [];
    }
    return all(
        'SELECT * FROM v_titles WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
        $ids
    );
}

/**
 * Create or update a title. Returns [id, errors].
 *
 * Developer and publisher accept a plain name and create the company if it is
 * new, exactly as the entry form already does, so importing does not need a
 * two-step dance.
 */
function save_title(?int $id, array $in): array
{
    $errors = [];

    $name       = trim((string) ($in['name'] ?? ''));
    $platformId = (int) ($in['platform_id'] ?? 0);

    if ($name === '') {
        $errors['name'] = 'A title needs a name.';
    }
    if ($platformId <= 0 || one('SELECT id FROM platforms WHERE id = ?', [$platformId]) === null) {
        $errors['platform_id'] = 'Choose which machine this release is for.';
    }
    if ($errors !== []) {
        return [null, $errors];
    }

    $year = isset($in['release_year']) && $in['release_year'] !== null && $in['release_year'] !== ''
        ? (int) $in['release_year'] : null;

    $data = [
        'platform_id'  => $platformId,
        'category_id'  => isset($in['category_id']) && (int) $in['category_id'] > 0 ? (int) $in['category_id'] : null,
        'developer_id' => company_id_for_name(isset($in['developer']) ? (string) $in['developer'] : null),
        'publisher_id' => company_id_for_name(isset($in['publisher']) ? (string) $in['publisher'] : null),
        'name'         => mb_substr($name, 0, 220),
        'subtitle'     => nullify($in['subtitle'] ?? null),
        'sort_name'    => nullify($in['sort_name'] ?? null),
        'release_year' => $year,
        'release_date' => nullify($in['release_date'] ?? null),
        'language'     => nullify($in['language'] ?? null),
        'region'       => nullify($in['region'] ?? null),
        'external_url' => nullify($in['external_url'] ?? null),
        'software_model_id' => ($in['software_model_id'] ?? null) ?: null,
        'synopsis'     => nullify($in['synopsis'] ?? null),
        // Which work this is a release of.
        //
        // Titles are per platform on purpose - the Amiga and Mega Drive releases of one
        // game are different artefacts - and work_key is what puts them back together.
        // It was derived from the name and never editable, so two releases matched only
        // while they were spelled identically: a subtitle on one, a regional rename, and
        // the link was silently gone with nothing on any screen to show it.
        //
        // Now: link to an existing release and adopt its key; otherwise fall back to the
        // name, which is right often enough to be a good default.
        'work_key'     => (function () use ($in, $name) {
            $sibling = (int) ($in['same_work_as'] ?? 0);
            if ($sibling > 0) {
                $key = scalar('SELECT work_key FROM titles WHERE id = ?', [$sibling]);
                if ($key !== null && (string) $key !== '') {
                    return (string) $key;
                }
            }
            $typed = trim((string) ($in['work_key'] ?? ''));
            return $typed !== '' ? slugify($typed) : slugify($name);
        })(),
    ];

    if ($id === null) {
        $platformSlug  = (string) (scalar('SELECT slug FROM platforms WHERE id = ?', [$platformId]) ?? '');
        $data['slug']  = unique_slug('titles', slugify($name . '-' . $platformSlug));
        $user          = function_exists('acting_user') ? acting_user() : null;
        $data['created_by'] = $user === null ? null : (int) $user['id'];
        $newId = (int) insert_row('titles', $data);

        // What the model says the box holds, copied onto the title. Copied rather than
        // pointed at, because the model describes what is usual and the title records
        // what this release actually shipped with - and a model edited next year must
        // not silently rewrite what you catalogued today.
        $mid = (int) ($data['software_model_id'] ?? 0);
        if ($mid > 0) {
            q('INSERT IGNORE INTO title_contents (title_id, label, note, sort_order)
               SELECT ?, c.label, c.note, c.sort_order
                 FROM software_model_contents c WHERE c.model_id = ?', [$newId, $mid]);
        }
        return [$newId, []];
    }

    update_row('titles', $id, $data);
    return [$id, []];
}

/**
 * Find a title by name and platform, creating it if it is new.
 *
 * The unique key on (platform_id, name, release_year) is what makes this safe
 * to call repeatedly: importing the same CSV twice finds the existing row the
 * second time rather than making a second one.
 */
function title_id_for(string $name, int $platformId, ?int $year = null, array $extra = []): ?int
{
    $name = trim($name);
    if ($name === '' || $platformId <= 0) {
        return null;
    }

    $existing = $year === null
        ? one('SELECT id FROM titles WHERE platform_id = ? AND name = ? AND release_year IS NULL', [$platformId, $name])
        : one('SELECT id FROM titles WHERE platform_id = ? AND name = ? AND release_year = ?', [$platformId, $name, $year]);
    if ($existing !== null) {
        return (int) $existing['id'];
    }

    // No year given, but a single unambiguous match with one? Reuse it rather
    // than creating a near-duplicate that differs only in a blank field.
    if ($year === null) {
        $candidates = all('SELECT id FROM titles WHERE platform_id = ? AND name = ?', [$platformId, $name]);
        if (count($candidates) === 1) {
            return (int) $candidates[0]['id'];
        }
    }

    [$id, $errors] = save_title(null, $extra + [
        'name'         => $name,
        'platform_id'  => $platformId,
        'release_year' => $year,
    ]);
    return $errors === [] ? (int) $id : null;
}

/**
 * The item columns a title supplies, for anything the caller did not state.
 *
 * An entry that names a title inherits its metadata; an entry that overrides
 * one field keeps the rest. Nothing here overwrites a value already present in
 * $stated, which is what makes "same game, different regional variant" work.
 */
function title_defaults_for_item(array $title, array $stated): array
{
    $out = [];
    $map = [
        'title'        => 'name',
        'subtitle'     => 'subtitle',
        'sort_title'   => 'sort_name',
        'platform_id'  => 'platform_id',
        'category_id'  => 'category_id',
        'developer_id' => 'developer_id',
        'publisher_id' => 'publisher_id',
        'release_year' => 'release_year',
        'release_date' => 'release_date',
        'language'     => 'language',
        'region'       => 'region',
        'external_url' => 'external_url',
    ];
    foreach ($map as $itemCol => $titleCol) {
        $given = $stated[$itemCol] ?? null;
        if ($given === null || $given === '' || $given === 0) {
            if (($title[$titleCol] ?? null) !== null && $title[$titleCol] !== '') {
                $out[$itemCol] = $title[$titleCol];
            }
        }
    }
    return $out;
}

/**
 * Copies of a title the caller can already see.
 *
 * Not a duplicate check in the "refuse this" sense - two copies of a game in
 * different condition are two legitimate entries, which is the whole reason
 * items and titles are separate tables. It is a "you may already own this"
 * prompt, so adding a second copy is a decision rather than an accident.
 */
function existing_copies(?int $titleId, ?string $barcode = null, ?string $title = null, ?int $platformId = null): array
{
    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);

    if ($titleId !== null && $titleId > 0) {
        return all("SELECT * FROM v_items WHERE title_id = ? AND $acl ORDER BY id",
                   array_merge([$titleId], $aclP));
    }
    if ($barcode !== null && trim($barcode) !== '') {
        return all("SELECT * FROM v_items WHERE barcode = ? AND $acl ORDER BY id",
                   array_merge([trim($barcode)], $aclP));
    }
    if ($title !== null && trim($title) !== '' && $platformId !== null && $platformId > 0) {
        return all("SELECT * FROM v_items WHERE title = ? AND platform_id = ? AND $acl ORDER BY id",
                   array_merge([trim($title), $platformId], $aclP));
    }
    return [];
}

// ---------------------------------------------------------------------------
// What happened to an entry, and when
//
// acquired_price, current_value and sold_price are single-valued on items, so
// re-valuing something used to destroy what it was worth before. The columns
// stay - every list query wants them and none wants a join - but each change
// also leaves a row here, which turns the collection valuation into a time
// series rather than a snapshot.
// ---------------------------------------------------------------------------

function record_item_event(int $itemId, string $kind, array $fields = []): void
{
    $user = function_exists('acting_user') ? acting_user() : null;
    insert_row('item_events', [
        'item_id'     => $itemId,
        'kind'        => $kind,
        'happened_on' => $fields['happened_on'] ?? date('Y-m-d'),
        'amount'      => $fields['amount']   ?? null,
        'currency'    => $fields['currency'] ?? null,
        'party'       => isset($fields['party']) ? mb_substr((string) $fields['party'], 0, 140) : null,
        'note'        => isset($fields['note']) ? mb_substr((string) $fields['note'], 0, 255) : null,
        'user_id'     => $user === null ? null : (int) $user['id'],
    ]);
}

/** The acquisition event that goes with a newly created entry. */
function record_acquisition_event(int $itemId, array $data): void
{
    if (($data['acquired_price'] ?? null) === null && ($data['acquired_on'] ?? null) === null) {
        return;
    }
    record_item_event($itemId, 'acquired', [
        'happened_on' => $data['acquired_on'] ?? date('Y-m-d'),
        'amount'      => $data['acquired_price'] ?? null,
        'currency'    => $data['currency'] ?? config('currency'),
        'party'       => $data['acquired_from'] ?? null,
        'note'        => $data['acquired_note'] ?? null,
    ]);
}

/**
 * Note anything worth remembering about an edit before it overwrites the
 * previous value: a revaluation, a loan, a sale.
 */
function record_value_change(int $itemId, array $before, array $after): void
{
    if (array_key_exists('current_value', $after)
        && (string) ($after['current_value'] ?? '') !== (string) ($before['current_value'] ?? '')) {
        record_item_event($itemId, 'valued', [
            'happened_on' => $after['valued_on'] ?? date('Y-m-d'),
            'amount'      => $after['current_value'],
            'currency'    => $after['currency'] ?? $before['currency'] ?? config('currency'),
        ]);
    }

    $statusBefore = (string) ($before['status'] ?? '');
    $statusAfter  = (string) ($after['status'] ?? $statusBefore);

    if ($statusAfter !== $statusBefore) {
        // No lent or returned branches: 'lent' is not a status any more, so
        // neither can be reached. The event kinds stay in the enum for rows
        // written before migration 0026 - deleting somebody's record of who had
        // a thing in 2024 is not what removing a feature means.
        if ($statusAfter === 'sold') {
            record_item_event($itemId, 'sold', [
                'happened_on' => $after['sold_on'] ?? date('Y-m-d'),
                'amount'      => $after['sold_price'] ?? null,
                'currency'    => $after['currency'] ?? $before['currency'] ?? config('currency'),
                'party'       => $after['sold_to'] ?? $before['sold_to'] ?? null,
                'note'        => $after['sold_note'] ?? null,
            ]);
        }
    }
}

function item_events(int $itemId): array
{
    return all(
        'SELECT e.*, u.display_name, u.username
           FROM item_events e LEFT JOIN users u ON u.id = e.user_id
          WHERE e.item_id = ? ORDER BY e.happened_on DESC, e.id DESC',
        [$itemId]
    );
}

// ---------------------------------------------------------------------------
// Hardware: model versus unit
//
// Several columns exist on both hardware_models and item_hardware. The value on
// the entry is an override for that particular unit; NULL means "whatever the
// model says". Resolving it here rather than in each template is what stops two
// pages disagreeing about which one wins.
// ---------------------------------------------------------------------------

function hardware_detail(array $item): array
{
    $hw = one('SELECT * FROM item_hardware WHERE item_id = ?', [(int) $item['id']]) ?? [];
    $model = ($item['model_id'] ?? null) === null
        ? null
        : one('SELECT * FROM hardware_models WHERE id = ?', [(int) $item['model_id']]);

    $resolve = function (string $unitKey, ?string $modelKey = null) use ($hw, $model) {
        $own = $hw[$unitKey] ?? null;
        if ($own !== null && $own !== '') {
            return $own;
        }
        $key = $modelKey ?? $unitKey;
        return $model === null ? null : ($model[$key] ?? null);
    };

    return $hw + [
        'resolved_model'     => $resolve('model', 'name'),
        'resolved_fits'      => $resolve('fits_note'),
        'resolved_interface' => $resolve('interface'),
        'model_row'          => $model,
    ];
}

// ---------------------------------------------------------------------------
// Where things physically are
//
// The same shape as the category tree - parent, materialised path, depth - for
// the same reason: a subtree is one LIKE rather than recursion, and the code to
// maintain it already existed and was worth having twice rather than
// generalising into something neither of them fits.
//
// Deliberately not split by domain. A shelf holds what it holds; a box of Amiga
// disks and an A500 can share a cupboard, and making "Computer room" twice so
// that hardware and software each have one would be a distinction the room does
// not have. If you keep them apart in practice, say so in the tree - "Disks" and
// "Machines" as two branches - which is the same thing said once.
// ---------------------------------------------------------------------------

/**
 * Every location in a library, ordered so a tree can be drawn by walking it.
 *
 * Sorted naturally rather than by a hand-kept order column: "Shelf 10" belongs
 * after "Shelf 2", and a number you have to maintain to say so is a number that
 * drifts the first time you insert a shelf in the middle. Sorting happens in
 * PHP because SQL has no natural sort worth the name.
 */
function location_tree(int $libraryId): array
{
    $rows = all('SELECT * FROM locations WHERE library_id = ?', [$libraryId]);

    // Children grouped under their parent, each group naturally sorted, then
    // walked depth-first so the caller can render by reading the list.
    $byParent = [];
    foreach ($rows as $row) {
        $byParent[(int) ($row['parent_id'] ?? 0)][] = $row;
    }
    foreach ($byParent as &$group) {
        usort($group, function (array $a, array $b): int {
            // Floors first where both have one, so -1 Basement comes before
            // 1 Living room rather than after it alphabetically. Only their own
            // floors: siblings that inherit share one, so it decides nothing.
            $fa = $a['floor_level'];
            $fb = $b['floor_level'];
            if ($fa !== null && $fb !== null && (int) $fa !== (int) $fb) {
                return (int) $fa <=> (int) $fb;
            }
            return strnatcasecmp((string) $a['name'], (string) $b['name']);
        });
    }
    unset($group);

    $out = [];
    $walk = function (int $parent) use (&$walk, &$out, $byParent): void {
        foreach ($byParent[$parent] ?? [] as $row) {
            $out[] = $row;
            $walk((int) $row['id']);
        }
    };
    $walk(0);

    return $out;
}

/**
 * Locations as a flat list of options, each labelled with its full path.
 *
 * "Computer room › Cabinet › Shelf 2" reads the same in a select as it does on
 * the wall, and without it six shelves called "Shelf 1" are indistinguishable.
 */
function location_options(?int $libraryId = null): array
{
    if ($libraryId === null || $libraryId <= 0) {
        return [];
    }
    $out = [];
    foreach (location_tree($libraryId) as $row) {
        // The effective floor, not the one typed here: a room inside "Floor 1"
        // is on floor 1, and a picker that says otherwise is answering a
        // different question from the one being asked.
        [$floor, $inherited] = location_floor((int) $row['id']);
        $out[] = [
            'id'        => (int) $row['id'],
            'name'      => (string) $row['name'],
            'label'     => location_breadcrumb((int) $row['id']),
            'depth'     => (int) $row['depth'],
            'floor'     => $floor,
            'own_floor' => $row['floor_level'] === null ? null : (int) $row['floor_level'],
            'inherited' => $inherited,
        ];
    }
    return $out;
}

/** "Computer room › Cabinet › Shelf 2". */
function location_breadcrumb(int $locationId, string $separator = ' › '): string
{
    $row = one('SELECT path FROM locations WHERE id = ?', [$locationId]);
    if ($row === null) {
        return '';
    }
    $ids = array_values(array_filter(explode('/', (string) $row['path']), 'strlen'));
    if ($ids === []) {
        return '';
    }
    $rows = all(
        'SELECT id, name FROM locations WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
        $ids
    );
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = (string) $r['name'];
    }
    return implode($separator, array_values(array_filter(
        array_map(fn($id) => $byId[(int) $id] ?? null, $ids)
    )));
}

/** A location and everything beneath it, for "what is in this room". */
function location_subtree_ids(int $locationId): array
{
    $row = one('SELECT path FROM locations WHERE id = ?', [$locationId]);
    if ($row === null) {
        return [];
    }
    return array_map('intval', array_column(
        all('SELECT id FROM locations WHERE path LIKE ?', [$row['path'] . '%']), 'id'
    ));
}

/**
 * Is there already a place with this name in the same place?
 *
 * "The same place" is the parent, not the library. Two libraries may both have
 * an Office, because they are two people's offices; one library may have
 * "Cabinet A > Shelf 1" and "Cabinet B > Shelf 1", because those are two
 * shelves. What it may not have is two Shelf 1s in Cabinet A.
 *
 * Checked here rather than with a unique key because parent_id is NULL at the
 * top level and no NULL collides with another, so the roots - the ones most
 * likely to be duplicated by accident - would be exactly the ones a key missed.
 */
function location_name_taken(int $libraryId, ?int $parentId, string $name, ?int $ignoreId = null): bool
{
    $sql  = 'SELECT id FROM locations WHERE library_id = ? AND name = ? AND parent_id '
          . ($parentId === null ? 'IS NULL' : '= ?');
    $args = [$libraryId, trim($name)];
    if ($parentId !== null) {
        $args[] = $parentId;
    }
    if ($ignoreId !== null) {
        $sql   .= ' AND id <> ?';
        $args[] = $ignoreId;
    }
    return one($sql . ' LIMIT 1', $args) !== null;
}

/** Recompute path and depth. Runs after any change to the shape of the tree. */
function location_rebuild_paths(): void
{
    q("UPDATE locations SET path = CONCAT('/', id, '/'), depth = 0 WHERE parent_id IS NULL");
    // Deep enough for any room anyone actually has, and it stops rather than
    // looping if somebody has managed to make a cycle.
    for ($level = 1; $level <= 8; $level++) {
        q("UPDATE locations l JOIN locations p ON p.id = l.parent_id
              SET l.path = CONCAT(p.path, l.id, '/'), l.depth = p.depth + 1
            WHERE p.depth = ? AND l.parent_id IS NOT NULL", [$level - 1]);
    }
}

/**
 * What floor a place is on, inherited where it does not say.
 *
 * A shelf inside a basement is in the basement. Making somebody restate that on
 * every shelf is busywork that goes stale the moment a cabinet moves, so the
 * floor is answered by the nearest ancestor that has one.
 *
 * Returns [level, inherited]: the second says whether it came from this place
 * or from something above it, which is worth showing rather than hiding.
 */
function location_floor(int $locationId): array
{
    $row = one('SELECT floor_level, path FROM locations WHERE id = ?', [$locationId]);
    if ($row === null) {
        return [null, false];
    }
    if ($row['floor_level'] !== null) {
        return [(int) $row['floor_level'], false];
    }

    // The path is /1/4/9/, root first. Walk back up it, nearest ancestor first.
    $ids = array_values(array_filter(explode('/', (string) $row['path']), 'strlen'));
    array_pop($ids);                        // this place, already checked
    foreach (array_reverse($ids) as $id) {
        $up = scalar('SELECT floor_level FROM locations WHERE id = ?', [(int) $id]);
        if ($up !== null) {
            return [(int) $up, true];
        }
    }
    return [null, false];
}

/**
 * "Floor 0", "Floor -1", "Floor 2".
 *
 * The number, not a name for it. It used to say "Ground floor" and "Basement",
 * which reads well in English and then stops: a building with two basement
 * levels, a Swedish bottenvaning, a warehouse counting from 1 - each needs a
 * different vocabulary, and the number needs none. It also matches what is in
 * the box, so nobody has to work out that "Basement" means the -1 they typed.
 */
function floor_label(?int $level): string
{
    return $level === null ? '' : 'Floor ' . $level;
}

/** What is on this shelf, and on everything below it. */
function items_at_location(int $locationId, bool $includeChildren = true): array
{
    $ids = $includeChildren ? location_subtree_ids($locationId) : [$locationId];
    if ($ids === []) {
        return [];
    }
    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);
    $in = implode(',', array_fill(0, count($ids), '?'));
    return all(
        "SELECT * FROM v_items WHERE location_id IN ($in) AND $acl
          ORDER BY location_position IS NULL, location_position, COALESCE(sort_title, title)",
        array_merge($ids, $aclP)
    );
}

/** How many entries are filed at each location, for the management screen. */
function location_counts(int $libraryId): array
{
    $rows = all(
        'SELECT location_id, COUNT(*) AS n FROM items
          WHERE library_id = ? AND deleted_at IS NULL AND location_id IS NOT NULL
          GROUP BY location_id',
        [$libraryId]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['location_id']] = (int) $r['n'];
    }
    return $out;
}

/**
 * Would making $parentId the parent of $locationId close a loop?
 *
 * A room cannot be inside one of its own shelves. Nothing in the schema
 * prevents somebody trying, and the result is a path rebuild that never
 * terminates.
 */
function location_would_loop(int $locationId, ?int $parentId): bool
{
    if ($parentId === null || $parentId === 0) {
        return false;
    }
    if ($locationId === $parentId) {
        return true;
    }
    return in_array($parentId, location_subtree_ids($locationId), true);
}

// ---------------------------------------------------------------------------
// Money
// ---------------------------------------------------------------------------

/**
 * Currencies offered in the entry form.
 *
 * A short list rather than every ISO code: this is a retro collection, and a
 * select of 180 entries is worse than a select of twelve. The configured
 * default is always included, so setting APP_CURRENCY to something not listed
 * here still works.
 */
function currency_options(): array
{
    $common = [
        'SEK' => 'SEK — Swedish krona',
        'EUR' => 'EUR — Euro',
        'USD' => 'USD — US dollar',
        'GBP' => 'GBP — Pound sterling',
        'NOK' => 'NOK — Norwegian krone',
        'DKK' => 'DKK — Danish krone',
        'PLN' => 'PLN — Polish złoty',
        'CHF' => 'CHF — Swiss franc',
        'CAD' => 'CAD — Canadian dollar',
        'AUD' => 'AUD — Australian dollar',
        'JPY' => 'JPY — Japanese yen',
    ];

    $configured = strtoupper((string) config('currency', 'SEK'));
    if ($configured !== '' && !isset($common[$configured])) {
        $common = [$configured => $configured] + $common;
    }
    return $common;
}

/**
 * Everything a library holds, counted.
 *
 * The number an administrator needs before deciding to destroy one, and the
 * backing for the contents screen. Kept in one place so the confirmation and the
 * listing cannot disagree about what is in there.
 *
 * @return array<string,int>
 */
function library_contents_summary(int $libraryId): array
{
    $n = fn(string $sql) => (int) scalar($sql, [$libraryId]);

    return [
        'entries'    => $n('SELECT COUNT(*) FROM items WHERE library_id = ? AND deleted_at IS NULL'),
        'images'     => $n('SELECT COUNT(*) FROM item_images im
                              JOIN items i ON i.id = im.item_id WHERE i.library_id = ?'),
        'platforms'  => $n('SELECT COUNT(*) FROM platforms WHERE library_id = ?'),
        'categories' => $n('SELECT COUNT(*) FROM categories WHERE library_id = ?'),
        'companies'  => $n('SELECT COUNT(*) FROM companies WHERE library_id = ?'),
        'hardware'   => $n('SELECT COUNT(*) FROM hardware_models WHERE library_id = ?'),
        'software'   => $n('SELECT COUNT(*) FROM software_models WHERE library_id = ?'),
        'locations'  => $n('SELECT COUNT(*) FROM locations WHERE library_id = ?'),
        'members'    => $n('SELECT COUNT(*) FROM library_members WHERE library_id = ?'),
        // Shared works that only this library's machines give a home to.
        'titles'     => $n('SELECT COUNT(*) FROM titles WHERE platform_id IN
                              (SELECT id FROM platforms WHERE library_id = ?)'),
    ];
}

/**
 * The upload filenames a library owns, every variant included.
 *
 * Rows cascade and files do not. `items.library_id` is ON DELETE RESTRICT so a
 * library holding anything cannot be deleted at all, and on the paths that empty
 * one first the photographs were being left in public/uploads with nothing left
 * in the database pointing at them - undeletable by any screen, and still served
 * to anyone who had kept the URL.
 *
 * Collected before the rows go, because afterwards there is nothing to ask.
 *
 * @return list<string> paths, absolute
 */
function library_upload_paths(int $libraryId): array
{
    $dir   = uploads_dir();
    $paths = [];

    // Item photographs: original, thumbnail, display copy.
    foreach (all('SELECT im.filename FROM item_images im
                    JOIN items i ON i.id = im.item_id
                   WHERE i.library_id = ?', [$libraryId]) as $r) {
        foreach (['', 'thumb_', 'disp_'] as $prefix) {
            $paths[] = $dir . '/' . $prefix . $r['filename'];
        }
    }

    // Company logos. These belong to the library's own copy of a firm, so they
    // go with it; the template company's logo is a different file and stays.
    foreach (all('SELECT logo_filename FROM companies
                   WHERE library_id = ? AND logo_filename IS NOT NULL AND logo_filename <> \'\'',
                 [$libraryId]) as $r) {
        foreach (['', 'thumb_'] as $prefix) {
            $paths[] = $dir . '/' . $prefix . $r['logo_filename'];
        }
    }

    return array_values(array_unique($paths));
}

/**
 * Delete a library and everything in it, photographs included.
 *
 * Distinct from delete_row('libraries', ...), which the ON DELETE RESTRICT on
 * items refuses the moment a library holds anything. This is the deliberate
 * version: the entries go first, so the restriction has nothing to protect, and
 * the files go with them.
 *
 * Files are removed after the transaction commits. A rolled-back delete that had
 * already unlinked the photographs would leave rows pointing at nothing, which is
 * worse than the reverse: an orphaned file wastes space, an orphaned row breaks
 * the page that shows it.
 *
 * @return array{0:bool,1:string,2:array<string,int>} ok, message, what was removed
 */
function library_purge(int $libraryId): array
{
    $lib = one('SELECT * FROM libraries WHERE id = ?', [$libraryId]);
    if ($lib === null) {
        return [false, 'No such library.', []];
    }
    if ((int) ($lib['is_personal'] ?? 0) === 1) {
        return [false, 'A personal shelf belongs to the account that owns it and is not '
                     . 'deleted from here.', []];
    }
    if ((int) scalar('SELECT COUNT(*) FROM libraries') <= 1) {
        return [false, 'That is the only library. Create another before deleting this one.', []];
    }

    $held  = library_contents_summary($libraryId);
    $files = library_upload_paths($libraryId);

    db()->beginTransaction();
    try {
        // Entries first and by hand, because of the RESTRICT. Their images,
        // links, fields and box contents all cascade from the item.
        q('DELETE FROM items WHERE library_id = ?', [$libraryId]);

        // Then the works filed against machines only this library knew about.
        //
        // `titles` carries no library_id - it is the shared catalogue of what was
        // released - but it points at `platforms`, which are library-scoped, with no
        // ON DELETE action. So a library that defined its own Sharp MZ-2500 and
        // catalogued titles for it could not be deleted at all: the platform delete
        // that cascades from the library hit fk_titles_platform and the whole thing
        // rolled back, reporting a constraint on a table the administrator had never
        // heard of. A title whose only machine is going with the library has nothing
        // left to be about.
        q('DELETE FROM titles WHERE platform_id IN
             (SELECT id FROM platforms WHERE library_id = ?)', [$libraryId]);

        // Then the library, which cascades its platforms, categories, companies,
        // models, locations and membership.
        delete_row('libraries', $libraryId);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('[retrohive] library purge failed (' . $libraryId . '): ' . $e->getMessage());
        return [false, 'Nothing was deleted: ' . $e->getMessage(), []];
    }

    $gone = 0;
    foreach ($files as $path) {
        if (is_file($path) && @unlink($path)) {
            $gone++;
        }
    }
    $held['files'] = $gone;

    $GLOBALS['__membership_cache'] = [];

    return [true, (string) $lib['name'] . ' and everything in it was deleted.', $held];
}

/**
 * What each software model says a release of that shape holds.
 *
 * Keyed by model id, so the form can hand the whole lot to the browser and fill
 * the box list in the moment a model is chosen rather than only on save. Two
 * queries for the set rather than two per model: a library with forty models on
 * a form was eighty round trips to fill in a select nobody had touched yet.
 *
 * @return array<int,array{contents:list<array{label:string,note:?string}>,fields:list<array{label:string,value:?string}>}>
 */
function software_model_presets(?int $libraryId = null): array
{
    if ($libraryId === null) {
        $lib       = working_library();
        $libraryId = $lib === null ? 0 : (int) $lib['id'];
    }

    $out = [];
    foreach (all('SELECT k.model_id, k.label, k.note
                    FROM software_model_contents k
                    JOIN software_models m ON m.id = k.model_id
                   WHERE m.library_id <=> ?
                ORDER BY k.model_id, k.sort_order, k.id', [$libraryId]) as $r) {
        $out[(int) $r['model_id']]['contents'][] = [
            'label' => (string) $r['label'],
            'note'  => $r['note'] === null ? null : (string) $r['note'],
        ];
    }
    foreach (all('SELECT f.model_id, f.label, f.default_value
                    FROM software_model_fields f
                    JOIN software_models m ON m.id = f.model_id
                   WHERE m.library_id <=> ?
                ORDER BY f.model_id, f.sort_order, f.id', [$libraryId]) as $r) {
        $out[(int) $r['model_id']]['fields'][] = [
            'label' => (string) $r['label'],
            'value' => $r['default_value'] === null ? null : (string) $r['default_value'],
        ];
    }

    foreach ($out as $id => $row) {
        $out[$id] = ['contents' => $row['contents'] ?? [], 'fields' => $row['fields'] ?? []];
    }
    return $out;
}

/**
 * Documents an entry points at.
 *
 * Links, not files. A scanned service manual is tens of megabytes and is already
 * hosted by somebody who curates it; what is worth keeping is that it exists and
 * where.
 */
function item_documents(int $itemId): array
{
    return all('SELECT * FROM item_documents WHERE item_id = ? ORDER BY label, id', [$itemId]);
}

/**
 * Add one, ignoring a repeat.
 *
 * INSERT IGNORE against the unique key rather than a check first: two people
 * applying the same lookup at once should end with one row, not an error.
 *
 * @return bool whether a row was written
 */
function add_item_document(int $itemId, string $label, string $url, ?string $source = null): bool
{
    $label = trim($label) === '' ? 'Document' : mb_substr(trim($label), 0, 200);
    $url   = trim($url);
    // The same check any scraped URL gets. A document link is somewhere a person
    // will click, and "http://" and "https://" are the only two things it may be.
    if (!filter_var($url, FILTER_VALIDATE_URL) || preg_match('#^https?://#i', $url) !== 1) {
        return false;
    }
    $done = q('INSERT IGNORE INTO item_documents (item_id, label, url, source) VALUES (?, ?, ?, ?)',
              [$itemId, $label, mb_substr($url, 0, 1000), $source]);
    return $done->rowCount() > 0;
}

function delete_item_document(int $itemId, int $docId): bool
{
    return q('DELETE FROM item_documents WHERE id = ? AND item_id = ?',
             [$docId, $itemId])->rowCount() > 0;
}

/**
 * What to call one of an entry's fields, on the side of the shop it is on.
 *
 * The columns are shared between software and hardware; the words for them are
 * not. A machine is not developed - Commodore *made* the Amiga 2000 - and the
 * hardware form has always called that field the Company. The metadata review
 * screen called it the Developer, so the same value had two names one click
 * apart and the person had to work out they were the same field.
 *
 * A function rather than an array in a template, because two screens need the
 * same answer and a second copy is a second thing to keep in step.
 */
function item_field_label(string $field, ?string $domain = null): string
{
    $hardware = $domain === 'hardware';
    return match ($field) {
        'title'          => 'Title',
        // Not "Made in": the hardware detail block already has a field by that
        // name, from `manufactured_year`, and the same source fills both from one
        // date - so the review screen would have shown two rows called Made in
        // with 1992 in each. A card is introduced rather than released.
        'release_year'   => $hardware ? 'Introduced' : 'Release year',
        'release_date'   => 'Exact release date',
        'developer_name' => $hardware ? 'Company' : 'Developer',
        // Hardware has no publisher. Something can still have been sold by
        // somebody other than its maker - a badge-engineered drive, a card sold
        // on by a distributor - so the field is kept and named for what it is.
        'publisher_name' => $hardware ? 'Sold by' : 'Publisher',
        'external_url'   => 'Reference link',
        'description'    => 'Description',
        'notes'          => 'Notes',
        default          => ucfirst(str_replace('_', ' ', $field)),
    };
}

/**
 * Replace an entry's document links with what the form posted.
 *
 * Whole-list, like the specification rows and the box contents: the form shows
 * every row, so what comes back is the complete answer and a row somebody deleted
 * is a row they meant to delete.
 *
 * @return int how many are on the entry afterwards
 */
function set_item_documents(int $itemId, array $labels, array $urls): int
{
    q('DELETE FROM item_documents WHERE item_id = ?', [$itemId]);

    $kept = 0;
    foreach ($labels as $i => $label) {
        $url = trim((string) ($urls[$i] ?? ''));
        if ($url === '') {
            continue;   // an empty row is the blank one at the end
        }
        // A label is optional; the address is the point. Falling back to the host
        // rather than "Document" gives a list of links something to be told apart
        // by.
        $text = trim((string) $label);
        if ($text === '') {
            $text = (string) (parse_url($url, PHP_URL_HOST) ?: 'Document');
        }
        if (add_item_document($itemId, $text, $url)) {
            $kept++;
        }
    }
    return $kept;
}

/**
 * Apply what the editor said about the photographs already on an entry.
 *
 * Removal, the main picture and captions. The form used to offer a dropzone and
 * nothing else, so a picture could be added and never taken away from that
 * screen - while the API had been able to delete one since it was written.
 *
 * Every id is checked against this entry before anything happens to it. A form
 * field naming a row the server will delete is a form field somebody can put any
 * number in.
 *
 * @return int how many were removed
 */
function apply_item_image_edits(int $itemId, array $remove, ?int $primary, array $captions,
                               array $sections = [], string $domain = 'software'): int
{
    $mine = [];
    foreach (item_images($itemId) as $img) {
        $mine[(int) $img['id']] = $img;
    }

    $gone = 0;
    foreach ($remove as $id) {
        $id = (int) $id;
        if (isset($mine[$id])) {
            delete_image($id);
            unset($mine[$id]);
            $gone++;
        }
    }

    foreach ($captions as $id => $caption) {
        $id = (int) $id;
        if (!isset($mine[$id])) {
            continue;
        }
        $caption = mb_substr(trim((string) $caption), 0, 200);
        if ($caption !== (string) ($mine[$id]['caption'] ?? '')) {
            update_row('item_images', $id, ['caption' => $caption === '' ? null : $caption]);
        }
    }

    // Moving a picture between sections.
    //
    // The claim in the notes was that a mislabelled photograph is "one click
    // away" from the right place. It was not: a stored picture had a caption, a
    // primary radio and a remove tick, and no way to say which set it belonged
    // to - so the fallback for a wrongly-guessed provenance was to delete it and
    // upload it again.
    //
    // The section carries the provenance; the kind follows only when the one it
    // has does not belong to the section chosen, so moving a manual photograph
    // between personal sets does not turn it into a box front.
    $known = image_sections($domain);
    foreach ($sections as $id => $key) {
        $id  = (int) $id;
        $key = (string) $key;
        if (!isset($mine[$id], $known[$key])) {
            continue;
        }
        $want    = $known[$key];
        $changes = [];
        if ((string) ($mine[$id]['provenance'] ?? 'personal') !== $want['provenance']) {
            $changes['provenance'] = $want['provenance'];
        }
        if (!in_array((string) ($mine[$id]['kind'] ?? 'other'), $want['kinds'], true)) {
            $changes['kind'] = $want['kinds'][0];
        }
        if ($changes !== []) {
            update_row('item_images', $id, $changes);
        }
    }

    // Only if it is still here: choosing a picture and removing it in the same
    // save is somebody changing their mind, not an error.
    if ($primary !== null && isset($mine[$primary])) {
        set_primary_image($itemId, $primary);
    }

    // Something has to be first. Removing the main picture leaves the entry with
    // none marked, and every listing falls back to nothing.
    if ($gone > 0) {
        ensure_primary_image($itemId);
    }
    sync_item_image_columns($itemId);
    return $gone;
}

/**
 * What a copy came on, as rows.
 *
 * `items.media_type` and `media_count` stay in step with the first row: the entry
 * page, the search and the API all read them, and a second place to look for the
 * same fact is how the two drift apart.
 */
function item_media(int $itemId): array
{
    return all('SELECT * FROM item_media WHERE item_id = ? ORDER BY sort_order, id', [$itemId]);
}

/**
 * Replace the list with what the form posted.
 *
 * @return int how many rows the entry has afterwards
 */
function set_item_media(int $itemId, array $media, array $quantities): int
{
    q('DELETE FROM item_media WHERE item_id = ?', [$itemId]);

    $order = 0;
    $first = null;
    $count = 0;
    foreach ($media as $i => $medium) {
        $medium = trim((string) $medium);
        if ($medium === '') {
            continue;
        }
        $qty = max(1, min(999, (int) ($quantities[$i] ?? 1)));
        insert_row('item_media', ['item_id' => $itemId, 'medium' => mb_substr($medium, 0, 60),
                                  'quantity' => $qty, 'sort_order' => $order++]);
        $first ??= ['medium' => $medium, 'quantity' => $qty];
        $count++;
    }

    // The old columns, from the first row. Not dropped: too much reads them, and
    // a list of one is what they were always holding anyway.
    update_row('items', $itemId, [
        'media_type'  => $first['medium'] ?? null,
        'media_count' => $first['quantity'] ?? 1,
    ]);
    return $count;
}

/**
 * The photograph sections, per side of the shop.
 *
 * Sections are combinations of two axes rather than a column of their own:
 * `provenance` (the publisher's artwork, or a photograph of your copy) and
 * `kind` (what it shows). That is why hardware and software show different
 * sections without either needing a schema change, and why "a scraper may not
 * write here" is one rule about provenance rather than three rules about
 * sections.
 *
 * `scrapable` is the whole point of the split: exactly one section per domain
 * accepts imported pictures, and it is never one holding somebody's own photos.
 *
 * @return array<string,array{title:string,blurb:string,provenance:string,kinds:list<string>,scrapable:bool,needs_box?:bool}>
 */
function image_sections(string $domain): array
{
    $boxKinds = ['box_front', 'box_back', 'box_spine'];

    if ($domain === 'hardware') {
        return [
            'stock' => [
                // "Stock images", on both domains and in both clients.
                //
                // One name for the thing a metadata source downloads. It has
                // been called Artwork here, Stock photos in one select, Official
                // box art in another and "official" in the API - four names for
                // one idea, and the field a person sees should not change its
                // name depending on which screen they are looking at.
                //
                // "Artwork" was also wrong for hardware: a photograph of an
                // Amiga 2000 from a database is not artwork, it is stock.
                'title' => 'Stock images',
                'blurb' => 'The machine or card as the maker showed it, or the best picture '
                         . 'anybody has. A lookup adds pictures here.',
                'provenance' => 'official',
                'kinds' => ['unit', 'other'],
                'scrapable' => true,
            ],
            'box' => [
                'title' => 'Photographs of the box',
                'blurb' => 'The packaging this one came in, as it is now.',
                'provenance' => 'personal',
                'kinds' => $boxKinds,
                'scrapable' => false,
                // Hidden when the entry says there was no box: a section asking
                // for photographs of a thing that does not exist is a section
                // that reads as missing data.
                'needs_box' => true,
            ],
            'unit' => [
                'title' => 'Photographs of the hardware',
                'blurb' => 'This one, inside and out — wear, repairs, board revision, '
                         . 'whatever is worth seeing.',
                'provenance' => 'personal',
                'kinds' => ['unit', 'media', 'manual', 'extras', 'other'],
                'scrapable' => false,
            ],
        ];
    }

    return [
        'art' => [
            // "Artwork" here and "Stock images" on hardware: box art is
            // artwork, a manufacturer's photograph of a machine is not.
            'title' => 'Artwork',
            'blurb' => 'The published artwork, or the best picture anybody has. '
                     . 'A lookup adds pictures here.',
            'provenance' => 'official',
            'kinds' => array_merge($boxKinds, ['screenshot', 'other']),
            'scrapable' => true,
        ],
        'box' => [
            'title' => 'Photographs of the box',
            'blurb' => 'Your copy, as it is — so the condition can be seen rather than graded.',
            'provenance' => 'personal',
            'kinds' => $boxKinds,
            'scrapable' => false,
            'needs_box' => true,
        ],
        'contents' => [
            'title' => 'Photographs of what is inside',
            'blurb' => 'Disks, manual, registration card, poster, the t-shirt — whatever '
                     . 'came in it.',
            'provenance' => 'personal',
            'kinds' => ['media', 'manual', 'extras', 'other'],
            'scrapable' => false,
        ],
    ];
}

/** Which section a stored picture belongs to. */
function image_section_for(array $image, string $domain): string
{
    $sections = image_sections($domain);
    foreach ($sections as $key => $section) {
        if ((string) ($image['provenance'] ?? 'personal') !== $section['provenance']) {
            continue;
        }
        if (in_array((string) ($image['kind'] ?? 'other'), $section['kinds'], true)) {
            return $key;
        }
    }
    // Anything that matches no section still has to be reachable, or a picture
    // becomes invisible because its kind moved.
    return array_key_last($sections);
}

/**
 * An entry's pictures, grouped into the sections that apply to it.
 *
 * @return array<string,array{section:array,images:list<array>}>
 */
function item_images_by_section(int $itemId, string $domain, bool $hasBox = true): array
{
    $out = [];
    foreach (image_sections($domain) as $key => $section) {
        if (!empty($section['needs_box']) && !$hasBox) {
            continue;
        }
        $out[$key] = ['section' => $section, 'images' => []];
    }
    // A picture whose section is not being shown still has to appear somewhere.
    //
    // Dropping it is silent data loss on the page: an entry marked as having no
    // box, with photographs of a box, showed neither them nor any sign they
    // existed. Whether there is a box is a checkbox somebody can have got wrong,
    // and a photograph is evidence about that - so it goes in the last section
    // rather than being hidden by an answer it contradicts.
    $fallback = array_key_last($out);
    foreach (item_images($itemId) as $img) {
        $key = image_section_for($img, $domain);
        if (!isset($out[$key])) {
            $key = $fallback;
        }
        if ($key !== null) {
            $out[$key]['images'][] = $img;
        }
    }
    return $out;
}

/**
 * The company a slug means, preferring the one that entries actually point at.
 *
 * A starter studio exists twice: a template row with library_id NULL, and a copy
 * per library with its own id. Entries point at the copy. Taking the first row
 * by slug took the template - whose id nothing references - so a studio with a
 * shelf full of games reported "nothing filed under this name yet", which is
 * true of that row and useless to read.
 *
 * Order: the working library's own row, then any library the person can see (so
 * a link from a shared library's entry works), then the template as a last
 * resort - a studio nobody has copied yet still has a page.
 */
function company_for_slug(string $slug): ?array
{
    $mine = working_library();
    if ($mine !== null) {
        $found = one('SELECT * FROM companies WHERE slug = ? AND library_id = ?',
                     [$slug, (int) $mine['id']]);
        if ($found !== null) {
            return $found;
        }
    }

    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);
    $found = one('SELECT * FROM companies WHERE slug = ? AND library_id IS NOT NULL AND ' . $acl
                 . ' ORDER BY id LIMIT 1', array_merge([$slug], $aclP));
    if ($found !== null) {
        return $found;
    }

    return one('SELECT * FROM companies WHERE slug = ?', [$slug]);
}

/**
 * What kind of thing an entry is, in one word.
 *
 * Hardware says so itself - `categories.role` is machine or peripheral. Software
 * has no role of its own, so the answer is whether any branch above it is the
 * Games one, which is a fact about the ancestry rather than about the leaf:
 * "Amiga › Software › Games › Platformer" is a game and "… › Applications ›
 * Paint" is not, and the leaf names alone say neither.
 *
 * The whole tree of names is read once per request and kept, because this is
 * called for every row on a page of forty and a query each would be forty.
 */
function item_kind_label(array $item): string
{
    // What the branch says it holds, first.
    //
    // The tree can now declare it - machines, peripherals, games, applications -
    // and a declaration beats an inference. The ancestry walk below is the
    // fallback for branches nobody has said anything about yet, which is every
    // branch on a library built before this and any built in a hurry since.
    $role = (string) ($item['category_role'] ?? 'other');
    // Inherited when the branch has not said, which is now the ordinary case: the
    // structure data declares a kind at the top of each branch and lets the rest
    // inherit, so a leaf's own role is usually 'other' and its kind comes from
    // above.
    if ($role === 'other' && !empty($item['category_id'])) {
        $role = (string) (category_effective_role((int) $item['category_id']) ?? 'other');
    }
    if (in_array($role, ['machine', 'peripheral', 'game', 'movie', 'tv_show', 'music'], true)) {
        return $role;
    }
    if ($role === 'application') {
        return 'software';
    }
    if ((string) ($item['domain'] ?? '') !== 'software') {
        return '';
    }

    if (!isset($GLOBALS['__category_names'])) {
        $GLOBALS['__category_names'] = [];
        foreach (all('SELECT id, name FROM categories') as $row) {
            $GLOBALS['__category_names'][(int) $row['id']] = mb_strtolower((string) $row['name']);
        }
    }

    // The path holds ids, so the names come from the map rather than from more
    // queries.
    foreach (array_filter(explode('/', (string) ($item['category_path'] ?? '')), 'strlen') as $pid) {
        if (($GLOBALS['__category_names'][(int) $pid] ?? '') === 'games') {
            return 'game';
        }
    }
    return 'software';
}

/**
 * A human-readable version of item_kind_label()'s own raw value -
 * ucfirst() alone is correct for every single-word kind (Game,
 * Machine, Movie, Music...) but leaves a literal underscore in
 * "tv_show", the one kind that isn't a single word. A small, explicit
 * map rather than a generic transform, so "TV" reads as the acronym
 * it is rather than "Tv".
 */
function item_kind_display_label(string $kind): string
{
    static $labels = ['tv_show' => 'TV show'];
    return $labels[$kind] ?? ucfirst($kind);
}

/**
 * Say what the shipped branches hold, for a library that has just been seeded.
 *
 * The migration that added `game` and `application` backfills an existing
 * database, but a fresh install never runs it - the schema is loaded whole and
 * the migration is recorded as already applied. So a new library got the tree
 * with three thousand branches saying "other", and the browsers were back to
 * guessing.
 *
 * Only branches nobody has spoken for: a kind set by hand is never overwritten,
 * whether that happened before or after the seeding.
 *
 * @return int how many were filled in
 */
function category_declare_kinds(int $libraryId): int
{
    // Under a branch called "Games", by ancestry rather than by leaf name -
    // "Amiga > Software > Games > Platformer" is a game and its leaf is called
    // Platformer.
    // The Games branches first - there are a few dozen - and then one update per
    // branch against an indexed prefix match.
    //
    // The obvious version joins categories to categories on
    // LOCATE(anc.id, c.path), which is every branch compared against every
    // branch: 3,600 x 3,600 on the shipped tree, run on every seed. It made the
    // whole test suite time out, which is a fair warning about what it would
    // have done to somebody's first sign-in.
    // Only where the template said nothing at all.
    //
    // The structure data now declares a kind at the top of each branch and lets the
    // rest inherit - six declarations a side instead of forty-nine - so declaring
    // every software branch here would undo that and put a role back on all 2,757
    // of them. This is the repair for a tree built before any of that, not a
    // second opinion about a tree that arrived declared.
    if ((int) scalar('SELECT COUNT(*) FROM categories
                       WHERE library_id = ? AND role IN ("game", "application")',
                     [$libraryId]) > 0
        // Both sides, or there is still something to do.
        //
        // Asking only about software let a library with a declared software tree
        // and an undeclared hardware one fall straight through - which is exactly
        // what an instance fetching templates published before the kinds existed
        // has, and it ends with nowhere to file a machine.
        && (int) scalar('SELECT COUNT(*) FROM categories
                          WHERE library_id = ? AND role IN ("machine", "peripheral")',
                        [$libraryId]) > 0) {
        return 0;
    }

    $softwareSectionId = (int) scalar("SELECT id FROM sections WHERE slug = 'software'");
    $hardwareSectionId = (int) scalar("SELECT id FROM sections WHERE slug = 'hardware'");

    $gameRoots = all('SELECT id FROM categories
                       WHERE library_id = ? AND section_id = ? AND LOWER(name) = "games"',
                     [$libraryId, $softwareSectionId]);
    $games = 0;
    foreach ($gameRoots as $root) {
        $games += q('UPDATE categories SET role = "game"
                      WHERE library_id = ? AND section_id = ? AND role = "other"
                        AND path LIKE ?',
                    [$libraryId, $softwareSectionId, '%/' . (int) $root['id'] . '/%'])->rowCount();
    }

    // Everything else on the software side that is not a root: a root is a
    // machine and holds nothing directly.
    $apps = q('UPDATE categories SET role = "application"
                WHERE library_id = ? AND section_id = ? AND role = "other"
                  AND parent_id IS NOT NULL',
              [$libraryId, $softwareSectionId])->rowCount();

    // The hardware side, by the names the shipped tree uses.
    //
    // This only ever repaired software, because the hardware template has always
    // carried its kinds - true until an instance fetches a copy published before
    // the kinds existed. Then nothing on the hardware side declares anything: no
    // machine branches, no peripheral branches, no way to file a machine, and no
    // hardware source ever switched on. All of it silent.
    //
    // By name, because there is nothing else to go on, and only where the branch
    // has said nothing itself.
    $hardware = 0;
    foreach ([
        'machine'    => ['computers', 'consoles', 'console', 'handhelds', 'handheld'],
        // 'expansions' is kept although the shipped tree no longer has that
        // branch - it was merged into Peripherals, and this list exists precisely
        // to repair instances whose feed predates the current shape. An instance
        // carrying the old two-branch tree still needs its Expansions node given
        // a kind, and a name that matches nothing costs one comparison.
        'peripheral' => ['peripherals', 'expansions', 'cartridges'],
    ] as $role => $names) {
        $in = implode(',', array_fill(0, count($names), '?'));
        $hardware += q("UPDATE categories SET role = ?
                         WHERE library_id = ? AND section_id = ? AND role = 'other'
                           AND parent_id IS NOT NULL AND LOWER(name) IN ($in)",
                       array_merge([$role, $libraryId, $hardwareSectionId], $names))->rowCount();
    }

    return $games + $apps + $hardware;
}

/**
 * The category branch that goes with a machine.
 *
 * A root is a platform - sixty-three roots, sixty-three platforms, each carrying
 * its platform_id - so making one without the other leaves either a machine
 * nothing can be filed under, or a branch that claims to be a machine and is not.
 *
 * In one function because there are two ways to make a platform: the generic
 * taxonomy screen and /manage/platforms, which has its own handler. I put this in
 * the first and the real screen is the second, so creating a platform still left
 * the catalogue editor without a branch - the fix was written and never ran.
 *
 * Idempotent: a library that already has a branch for this machine keeps the one
 * it has.
 */
function platform_ensure_root(int $platformId, int $libraryId, string $name): ?int
{
    if ($platformId <= 0 || $libraryId <= 0) {
        return null;
    }
    $existing = one('SELECT id FROM categories
                      WHERE library_id = ? AND platform_id = ? AND parent_id IS NULL',
                    [$libraryId, $platformId]);
    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $rootId = (int) insert_row('categories', [
        'library_id'  => $libraryId,
        'platform_id' => $platformId,
        'parent_id'   => null,
        'section_id'  => (int) scalar("SELECT id FROM sections WHERE slug = 'hardware'"),
        'name'        => $name,
        'slug'        => unique_slug('categories', slugify($name)),
        'sort_order'  => 100,
        'depth'       => 0,
        'path'        => '/',
    ]);
    category_rebuild_paths();
    return $rootId;
}

/**
 * The branch an entry may be filed under, or null.
 *
 * A category belongs to a library. Template categories belong to none - they
 * exist to be copied - so an entry pointing at one is filed under a branch that
 * is invisible in the tree it appears to be in, and that moves the next time the
 * templates are imported.
 *
 * Nothing refused it: the form posts an id and every write path trusted it. This
 * is that check, in one function, because there are three places an entry is
 * created and a fourth will be written eventually.
 *
 * Returns the id when it is filed correctly, and null when it is not - the
 * caller decides whether that is an error or a reason to fall back, because a
 * form should complain and an import should keep going.
 */
function category_for_library(?int $categoryId, int $libraryId): ?int
{
    if ($categoryId === null || $categoryId <= 0 || $libraryId <= 0) {
        return null;
    }
    $row = one('SELECT library_id FROM categories WHERE id = ?', [$categoryId]);
    if ($row === null) {
        return null;
    }
    // Strictly this library's own. Not "belongs to some library", because an
    // entry filed under another shelf's branch is the same problem wearing a
    // different hat.
    return (int) ($row['library_id'] ?? 0) === $libraryId ? $categoryId : null;
}

/**
 * Why this entry cannot be deleted yet, or null if it can.
 *
 * Both directions of the same rule: a card fitted to a machine, and a machine
 * with cards in it. Deleting either leaves the other describing a relationship
 * to something that is gone.
 *
 * A function rather than a few lines inside the delete handler, because the
 * handler ends in a redirect and cannot be asked a question - so the only way to
 * check the rule was to read the source and hope, which is what the tests were
 * doing.
 */
function item_delete_blocker(int $itemId, string $title): ?string
{
    $fittedTo = item_parents($itemId);
    if ($fittedTo !== []) {
        $names = array_slice(array_column($fittedTo, 'title'), 0, 3);
        return sprintf('%s is fitted to %s. Take it out first, then delete it.',
                       $title, implode(' and ', $names));
    }

    $holding = item_children($itemId);
    if ($holding !== []) {
        $names = array_slice(array_column($holding, 'title'), 0, 3);
        return sprintf('%s still holds %s. Take %s out first, then delete it.',
                       $title, implode(' and ', $names),
                       count($names) === 1 ? 'it' : 'them');
    }

    return null;
}

/**
 * Switch on the sources a library's branches are worth asking, out of the box.
 *
 * Nothing answers until somebody says so, which is right for a decision and
 * wrong as a starting position: a fresh library would fetch nothing anywhere
 * until every branch had been visited by hand.
 *
 * One row per source per **topmost branch of each kind**, not per branch. The
 * kind inherits downward and so does the source, so `Amiga › Software › Games`
 * covers every kind of game beneath it - about four rows per machine instead of
 * sixty, and the same answer.
 *
 * Only where nothing has been said: a branch somebody has already decided about
 * is left exactly as they left it, whether that decision was on or off.
 *
 * @return int how many were switched on
 */
function seed_library_provider_scopes(int $libraryId): int
{
    // The definitions live in metadata.php, which not every entry point loads -
    // a suite that only wants a seeded library should not have to know that
    // seeding consults metadata definitions, and a fatal there is a strange way
    // to find out.
    if (!function_exists('metadata_provider_definition')) {
        return 0;
    }

    $providers = all('SELECT id, type FROM metadata_providers WHERE is_enabled = 1');
    if ($providers === []) {
        return 0;
    }

    // What each source is worth switching on for. The domains it answers about
    // say what it *can* do; this says what it is actually good for - Wikidata
    // answers about anything and is a poor first choice for a game, so it is
    // offered rather than turned on.
    $wanted = [];
    foreach ($providers as $p) {
        $def = metadata_provider_definition((string) $p['type']);
        // A configured source of a type this build no longer defines: it can
        // still be scoped by hand, it simply has no opinion about defaults.
        if ($def === null) {
            continue;
        }
        foreach ((array) ($def['default_for_kinds'] ?? []) as $kind) {
            $wanted[$kind][] = (int) $p['id'];
        }
    }
    if ($wanted === []) {
        return 0;
    }

    // Every kind a category can declare, not the four the hardware and software
    // sections happen to use.
    //
    // This is the same gap category_effective_role() had and had fixed: movie,
    // tv_show and music were added to the template data, and the lists that
    // enumerate kinds were not extended to match. The consequence here was
    // total and silent. A video branch got no provider_scopes row, so
    // providers_for() returned an empty set for it, so metadata_search_all()
    // asked nobody at all - and the screen said "No source recognised that
    // title", which is a claim about the sources' answer rather than about
    // there having been no question. Looking up The Big Lebowski against an
    // instance with TMDB configured and working found nothing, logged nothing,
    // and gave no hint that nothing had been asked.
    $kinds = ['machine', 'peripheral', 'game', 'application', 'movie', 'tv_show', 'music'];
    $rows  = all('SELECT id, role, path FROM categories WHERE library_id = ? AND role IN ('
                 . implode(',', array_fill(0, count($kinds), '?')) . ')',
                 array_merge([$libraryId], $kinds));

    // The topmost of each kind: a branch whose kind is already declared by
    // something above it needs no row of its own.
    $roleById = [];
    foreach ($rows as $r) {
        $roleById[(int) $r['id']] = (string) $r['role'];
    }

    $added = 0;
    foreach ($rows as $r) {
        $ids = array_map('intval', array_values(array_filter(
            explode('/', (string) $r['path']), 'strlen')));
        array_pop($ids);   // itself
        $covered = false;
        foreach ($ids as $ancestor) {
            if (($roleById[$ancestor] ?? '') === (string) $r['role']) {
                $covered = true;
                break;
            }
        }
        if ($covered) {
            continue;
        }

        foreach ($wanted[(string) $r['role']] ?? [] as $providerId) {
            // INSERT IGNORE, so a decision already made here survives: the unique
            // key is (provider, category, platform).
            $before = (int) scalar('SELECT COUNT(*) FROM provider_scopes
                                     WHERE provider_id = ? AND category_id = ? AND platform_id = 0',
                                   [$providerId, (int) $r['id']]);
            if ($before > 0) {
                continue;
            }
            insert_row('provider_scopes', ['provider_id' => $providerId,
                'category_id' => (int) $r['id'], 'platform_id' => 0, 'enabled' => 1]);
            $added++;
        }
    }
    return $added;
}
