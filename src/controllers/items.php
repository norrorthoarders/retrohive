<?php
declare(strict_types=1);

/** Everything, or one half of it. */
function items_index(?string $domain = null): void
{
    if ($domain !== null) {
        $_GET['domain'] = $domain;
    }

    // Open on your own shelf unless told otherwise. 'all' is how you say you
    // meant everything, and it is remembered across the section links, so
    // switching Software to Hardware does not quietly change the scope back.
    if (!array_key_exists('library', $_GET)) {
        $mine = working_library();
        if ($mine !== null) {
            $_GET['library'] = $mine['slug'];
        }
    } elseif (trim((string) $_GET['library']) === 'all') {
        unset($_GET['library']);
    }
    $perPage = max(6, min(96, (int) (input('per_page') ?? config('per_page'))));
    $page    = max(1, (int) (input_int('page') ?? 1));
    $sort    = input('sort', 'title');

    [$where, $params, $active] = build_item_filters($_GET);
    $order = item_sort_clause($sort);

    $total = (int) scalar("SELECT COUNT(*) FROM v_items WHERE $where", $params);
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $items = all("SELECT * FROM v_items WHERE $where ORDER BY $order LIMIT $perPage OFFSET $offset", $params);

    render($domain === 'hardware' ? 'items/hardware' : 'items/index', [
        'pageTitle'  => 'Browse',
        'items'      => $items,
        'total'      => $total,
        'page'       => $page,
        'pages'      => $pages,
        'perPage'    => $perPage,
        'sort'       => $sort,
        'active'     => $active,
        'libraries'  => readable_libraries(),
        'library'    => trim((string) ($_GET['library'] ?? '')),
        // The hardware browser's Platform filter reads this, so it is not a list
        // nothing reads: dropping it left that select with only "Any" in it, and a
        // filter you cannot choose a value in looks like a filter that does not
        // work. The software browser gets its platforms from $nodes instead.
        // The filter dropdown wants the same labelled tree the entry form uses:
        // six branches called "Adapters" are indistinguishable without a path.
        'nodes'      => filing_options(),
        // This library's platforms. all_platforms() spans every library the account can
        // reach, so with two libraries the filter listed "Amiga" twice - the same name
        // from two shelves, indistinguishable in a dropdown.
        'platforms'  => (function () {
            $lib = working_library();
            return $lib === null ? [] : array_values(array_filter(
                all_platforms(),
                fn($p) => (int) ($p['library_id'] ?? 0) === (int) $lib['id']
            ));
        })(),
        // Only the values that are actually on a shelf. A filter offering all
        // sixty-three platforms when the collection holds Amigas is sixty-two
        // choices that return nothing, which reads as a broken filter rather than
        // an empty result. Computed for the hardware browser only, where the two
        // selects live.
        'inUse'      => $domain !== 'hardware' ? null : [
            'platforms'  => array_column(all(
                "SELECT DISTINCT platform_slug FROM v_items WHERE $where AND platform_slug IS NOT NULL",
                $params
            ), 'platform_slug'),
            'categories' => array_column(all(
                "SELECT DISTINCT category_slug FROM v_items WHERE $where AND category_slug IS NOT NULL",
                $params
            ), 'category_slug'),
        ],
        'tags'       => all_tags(),
        // Cards or table, on either browser - both listings read v_items, so
        // both can be drawn either way. The default differs on purpose: boxed
        // software is recognised by its cover, and a list of machines is read by
        // model and type, where a column of identical grey rectangles helps
        // nobody. 'list' is still accepted, because it was a working URL.
        'view'       => (function () use ($domain): string {
            $asked = (string) input('view', '');
            if ($asked === 'list') {
                $asked = 'table';
            }
            if (in_array($asked, ['cards', 'table'], true)) {
                return $asked;
            }
            return $domain === 'hardware' ? 'table' : 'cards';
        })(),
        'domain'     => $domain,
    ]);
}

function software_index(): void { items_index('software'); }
function hardware_index(): void { items_index('hardware'); }

function items_show(int $id): void
{
    $item = find_item($id);
    if ($item === null || !can_read_item($item)) {
        not_found('That entry is not in the catalogue, or not in a library you can see.');
    }
    $siblings = all(
        'SELECT id, title, release_year FROM v_items
         WHERE library_id = ? AND platform_id = ? AND id <> ?
           AND (developer_id = ? OR category_id = ?)
         ORDER BY COALESCE(sort_title, title) LIMIT 8',
        // Same shelf, same machine, and either the same maker or the same kind. It
        // used to say "same genre", which was the only thing genre_id was still doing.
        [(int) $item['library_id'], (int) $item['platform_id'], $id,
         $item['developer_id'], $item['category_id']]
    );

    render('items/show', [
        // Manuals and schematics archived elsewhere, kept as links. Same reason
        // as the fitting below: recorded and never shown is worth nothing.
        'documents' => item_documents($id),
        // What is fitted to what. Recorded since the hardware schema landed and
        // never shown until now, which made it worth nothing.
        'parents'   => item_parents($id),
        'children'  => item_children($id),
        'chain'     => item_ancestry_chain($id),
        'goesWith'  => item_goes_with($id),
        'linkable'  => linkable_items($id, (int) $item['library_id']),
        // hardware_detail(), not the raw row.
        //
        // It resolves "the entry's own value, or the model's if the entry has
        // none" - the rule its own doc comment says exists to stop two pages
        // disagreeing about which one wins. Nothing called it, so this page
        // showed a blank field wherever the value lived on the model rather
        // than the entry: an Amiga 2000 with a model set but no interface typed
        // onto the entry itself showed no interface at all, when the model had
        // one all along.
        'hardware'  => $item['model_id'] === null
            && one('SELECT 1 FROM item_hardware WHERE item_id = ?', [$id]) === null
                ? null : hardware_detail($item + ['id' => $id]),
        'pageTitle' => $item['title'],
        'item'      => $item,
        'images'    => item_images($id),
        'tags'      => item_tags($id),
        'siblings'  => $siblings,
    ]);
}

function items_form(?int $id = null): void
{
    $item = null;
    if ($id !== null) {
        $item = find_item($id);
        if ($item === null || !can_read_item($item)) {
            not_found();
        }
        if (!can_write_item($item)) {
            flash('error', 'That library is read-only for your account.');
            redirect(items_return_to($id));
        }
    }
    // Two forms rather than one that hides half of itself. A network card has
    // no genre and no developer, and asking for them - even greyed out - makes
    // the form about what the thing is not.
    $domain = $item !== null
        ? (string) (scalar('SELECT s.slug FROM categories c JOIN sections s ON s.id = c.section_id
                             WHERE c.id = ?', [(int) $item['category_id']]) ?? 'software')
        : (input('domain') === 'hardware' ? 'hardware' : 'software');

    $editingModelId = $item !== null ? (int) ($item['model_id'] ?? 0) : 0;

    // Two ways in, because the questions differ. A machine has slots to fill;
    // a part occupies one and needs to say which machines it suits. One form
    // that reconfigures itself expects people to work out which mode they are
    // in, and they should not have to.
    $adding = input('as') === 'part' ? 'part' : 'machine';
    if ($item !== null) {
        $adding = is_machine_category((int) ($item['category_id'] ?? 0)) ? 'machine' : 'part';
    }

    // Which library this form is filing into: the entry's own when editing, otherwise
    // whatever the header switcher has selected, falling back to the default. Every
    // list on the page is scoped to it, so a maker or a model appears once.
    //
    // On the Add form there is no entry yet, so these lists used to fall back to
    // "every library you can reach" - which on an instance with two libraries offered
    // Acorn, Acorn, Amstrad, Amstrad, and an Amiga 500 twice.
    $formLib = (int) ($item['library_id'] ?? 0);
    if ($formLib <= 0) {
        $want = trim((string) ($_GET['library'] ?? ''));
        if ($want !== '') {
            $hit = one('SELECT id FROM libraries WHERE slug = ? OR id = ?', [$want, (int) $want]);
            if ($hit !== null && can_add_to_library((int) $hit['id'])) {
                $formLib = (int) $hit['id'];
            }
        }
    }
    if ($formLib <= 0) {
        $mine    = working_library();
        $formLib = $mine === null ? 0 : (int) $mine['id'];
    }

    // A new entry can arrive with some of its answers already known - "add a peripheral
    // for this machine" knows the machine. The form reads its values from $item, which
    // is null when nothing is being edited, so the prefill goes there rather than being
    // read from the query in the template.
    // Kept apart from $item on purpose: the form decides "editing" from whether $item
    // exists, so filling it in to carry a prefill turned "Add a peripheral" into "Edit"
    // with no title at all.
    $prefill = $item !== null ? [] : array_filter([
        'platform_id' => input_int('platform_id'),
        'category_id' => input_int('category_id'),
        'title_id'    => input_int('title_id'),
    ], fn($v) => $v !== null && $v > 0);

    render($domain === 'hardware' ? 'items/form_hardware' : 'items/form', [
        'adding'           => $adding,
        // The entry's links. This went to the *show* render and not this one, so
        // the section rendered its empty row on a machine that had four of them -
        // the place existed and what belonged in it did not arrive.
        'documents'        => $item === null ? [] : item_documents((int) $item['id']),
        // What this copy came on, as rows. One free-text box and a count could
        // not say "a cartridge and a manual disk".
        'itemMedia'        => $item === null ? [] : item_media((int) $item['id']),
        'libraryHere'      => $formLib,
        // Answers already known for a new entry, from the link that led here.
        'prefill'          => $prefill,
        // What this copy has, or the release's list as a starting point.
        'boxContents'      => item_contents_for_form(
            $item === null ? null : (int) $item['id'],
            (int) ($item['title_id'] ?? input_int('title_id') ?? 0) ?: null
        ),
        // Only a same-site path, for the same reason items_return_to() checks: this
        // ends up in an href.
        'returnTo'         => (function () {
            $want = trim((string) (input('return', '') ?: ''));
            return ($want !== '' && str_starts_with($want, '/') && !str_starts_with($want, '//'))
                ? $want : null;
        })(),
        // The kinds this entry may be filed under: its own library's, never a template,
        // and only its own side of the shop.
        //
        // This passed no domain, so the software form offered every hardware branch and
        // the hardware form every software one. Filing a boxed game under "Amiga >
        // Hardware > Peripherals" is not a mistake somebody should be able to make from
        // a dropdown.
        'nodes'            => filing_options($domain === 'hardware' ? 'hardware' : 'software',
                                             $formLib ?: null),
        // The parts half, for the picker on the card form. Removed with the
        // table merge and not put back, which left the branch reading an
        // undefined variable - a warning here, a fatal wherever notices are
        // errors.
        'parts'            => hardware_models(null, false, $formLib ?: null),
        // Studios and publishers, for the developer and publisher pickers.
        //
        // The form has iterated $companies for its datalist since it was written and was
        // never given one - an undefined variable, so the list was silently empty and
        // every name had to be typed from memory. Scoped to this library and to firms
        // that make software, like every other company picker.
        'companies'        => all_companies('software', $formLib ?: null),
        // The shapes of release this library knows about, for the software form's
        // model picker - the counterpart to the hardware form's Machine model. Picking
        // one says what the box generally holds, which is what saves the typing.
        'swModels'         => software_models($formLib ?: null),
        // What each of those says a release of its shape holds, so choosing one
        // fills the box list then and there rather than at some later save.
        'swPresets'        => software_model_presets($formLib ?: null),
        // What this card fits, and whether that came from its model or from the
        // card itself. The form shows one read only and the other editable.
        'fits'             => effective_compatibility(
            $item === null ? null : (int) $item['id'],
            $item === null ? null : (int) ($item['model_id'] ?? 0)
        ),
        // Machines this card could go in, and the one it is in now. Only for an
        // entry that exists: the choice is a link between two rows, so there has
        // to be a row on this end before it can be made.
        'installableMachines' => ($item !== null && !is_machine_category((int) ($item['category_id'] ?? 0)))
            ? installable_machines(
                (int) $item['id'],
                (int) ($item['platform_id'] ?? 0),
                effective_compatibility((int) $item['id'], (int) ($item['model_id'] ?? 0))['ids']
            )
            : [],
        'currentHost'      => $item === null ? null : current_host_machine((int) $item['id']),
        // Machine models this entry could be marked as fitting: its own library's.
        // Models are per library, so listing every library's machines would offer a
        // private card the choice of somebody else's A2000.
        'compatibleModels'       => (function () use ($formLib) {
            $lib = $formLib;
            if ($lib <= 0) {
                $reach = accessible_library_ids(acting_user(), ACCESS_CONTRIBUTOR);
                if ($reach === []) {
                    return [];
                }
                $in = implode(',', array_fill(0, count($reach), '?'));
                return all("SELECT hm.id, hm.name, hm.platform_id, p.slug AS platform_slug
                              FROM hardware_models hm
                         LEFT JOIN platforms p  ON p.id = hm.platform_id
                              JOIN categories c ON c.id = hm.category_id AND c.role = 'machine'
                             WHERE hm.library_id IN ($in)
                          ORDER BY p.name, hm.name", $reach);
            }
            return all("SELECT hm.id, hm.name, hm.platform_id, p.slug AS platform_slug
                          FROM hardware_models hm
                     LEFT JOIN platforms p  ON p.id = hm.platform_id
                          JOIN categories c ON c.id = hm.category_id AND c.role = 'machine'
                         WHERE hm.library_id = ?
                      ORDER BY p.name, hm.name", [$lib]);
        })(),
        // Filtered rather than re-queried, so the row shape is unchanged.
        'vendors'          => array_values(array_filter(all_vendors(),
            fn($v) => (int) ($v['library_id'] ?? 0) === $formLib)),
        'models'           => hardware_models(null, $adding !== 'part', $formLib ?: null),
        // Straight off the model. It used to be matched across from the template
        // maker by slug, because a model was template data pointing at a template
        // vendor; a model in a library points at that library's vendor already.
        'selectedVendorId' => $editingModelId > 0
            ? (int) (scalar('SELECT vendor_id FROM hardware_models WHERE id = ?',
                            [$editingModelId]) ?? 0)
            : null,
        'domain'     => $domain,
        'hardware'   => $item ? one('SELECT * FROM item_hardware WHERE item_id = ?', [(int) $item['id']]) : null,
        // Named for what it adds. "Add to the collection" is the same words the hardware
        // form uses, so the two pages were indistinguishable from their headings.
        'pageTitle'  => $item
            ? 'Edit ' . $item['title']
            : ($domain === 'hardware' ? 'Add hardware' : 'Add a software title'),
        'item'       => $item,
        'images'     => $item ? item_images((int) $item['id']) : [],
        'tagCsv'     => $item ? implode(', ', array_column(item_tags((int) $item['id']), 'name')) : '',
        'libraries'  => readable_libraries(ACCESS_CONTRIBUTOR),
        // Which environment, for software. A PC is one machine whether it boots
        // MS-DOS or OS/2, so this is a separate question from the machine.
        // This library's, not the templates'. An entry names a platform from its own
        // library, so listing the template rows offered environments that could never
        // match - which is why the select was always empty.
        'operatingSystems' => all(
            'SELECT o.*, p.slug AS platform_slug, p.id AS platform_id
               FROM operating_systems o
               JOIN platforms p ON p.id = o.platform_id
              WHERE o.library_id = ?
              ORDER BY p.name, o.sort_order',
            [$formLib]
        ),
        // No second 'nodes' or 'companies' here. Both were already set above,
        // scoped to this form's domain and library, and PHP keeps the last key
        // of a duplicate pair - so these unscoped copies silently reinstated the
        // two bugs the comments up there describe: every hardware branch offered
        // on the software form, and a company picker listing every library's
        // firms plus the templates'.
        //
        // The machines this can be filed against. Absent entirely until now, so the
        // Platform select on the software form had nothing in it but "Choose…" - and
        // it is required, so nothing could be saved.
        'platforms'  => (function () {
            $lib = working_library();
            return $lib === null ? all_platforms() : array_values(array_filter(
                all_platforms(),
                fn($p) => (int) ($p['library_id'] ?? 0) === (int) $lib['id']
            ));
        })(),
    ]);
}

/** Shared field mapping for create and update. */
function items_payload(): array
{
    // Read from the kind this entry is being filed under, which is where the
    // domain lives - the same place the platform is derived from.
    $companyMakes = (string) (scalar('SELECT s.slug FROM categories c JOIN sections s ON s.id = c.section_id
                                      WHERE c.id = ?', [derived_category_id()]) ?: 'software') === 'hardware'
        ? 'hardware' : 'software';

    // Worked out once, above the array, because the two halves have to agree.
    //
    // `has_box_declared` is a hidden field the forms that carry a box checkbox
    // post; its absence means "this form has no opinion", which is a different
    // answer from "no box" and the reason rule_box_state() takes a nullable.
    $boxState = rule_box_state(
        isset($_POST['has_box_declared']) ? input_bool('has_box') === 1 : null,
        input('condition_box')
    );

    return [
        'library_id'       => (int) input_int('library_id', 0),
        'title_id'         => valid_title_id(),
        'platform_id'      => derived_platform_id(),
        'category_id'      => derived_category_id(),
        // Which side of the shop, so a machine's maker is not created as a
        // software house. On the hardware form these two fields are labelled
        // Company and Sold by.
        //
        // Two forms, two fields, one column. The software form types a name into
        // `developer_name`; the hardware form picks an existing company from a
        // select called `vendor_id`. This read only the first, so choosing a
        // company on the hardware form did nothing at all - the lookup created
        // Commodore, the select offered Commodore, and the entry stayed blank
        // however many times you saved it.
        //
        // The id wins where it is given: it names a row rather than a spelling,
        // and the select cannot offer a company that is not there.
        'developer_id'     => ($vid = input_int('vendor_id')) !== null && $vid > 0
                                ? $vid
                                : company_id_for_name(input('developer_name'), $companyMakes),
        'publisher_id'     => company_id_for_name(input('publisher_name'), $companyMakes),
        'title'            => mb_substr((string) input('title', ''), 0, 220),
        'subtitle'         => nullify(input('subtitle')),
        'sort_title'       => nullify(input('sort_title')),
        'release_year'     => input_int('release_year'),
        'release_date'     => nullify(input('release_date')),
        'rating'           => input_int('rating'),
        // The rules from src/rules.php, with this form's own policy on a bad
        // value: fall back to "unknown" rather than refuse. Somebody halfway
        // through a page should not lose it to a select that cannot be wrong
        // anyway; the API answers 422 for the same input, deliberately.
        'condition_grade'  => rule_condition_grade(input('condition_grade')) ?? 'unknown',
        'completeness'     => rule_completeness(input('completeness')) ?? 'unknown',
        // Only when the form still posts them as single values - the API and the
        // importer do. The entry form posts media_type[] instead, and
        // set_item_media() owns both columns then, writing them from the first
        // row; taking a scalar off an array here would have written "Array".
        // Placeholders when the form posts media_type[] - the entry form does -
        // because set_item_media() writes both of these from the first row a
        // moment later. Reading a scalar off an array here would have stored the
        // word "Array"; reaching for $existing would have been an undefined
        // variable, since it belongs to the save function rather than this one.
        'media_type'       => is_array($_POST['media_type'] ?? null)
            ? null : nullify(input('media_type')),
        'media_count'      => is_array($_POST['media_type'] ?? null)
            ? 1 : max(1, (int) (input_int('media_count') ?? 1)),
        'catalog_number'   => nullify(input('catalog_number')),
        'barcode'          => nullify(input('barcode')),
        'language'         => nullify(input('language')),
        'region'           => nullify(input('region')),
        // A section switched off is cleared, not merely left as posted. Hiding the
        // inputs stops the browser sending them anyway, but an unticked box has to
        // erase what was there before or turning the section off would leave the
        // old figures in the database with nothing on screen showing them.
        //
        // Only a form that carries the controls may decide this: the software form
        // has no such toggles, so where the marker is absent the fields are taken
        // at face value exactly as before.
        'acquired_on'      => provenance_kept('acquire') ? nullify(input('acquired_on'))   : null,
        'acquired_from'    => provenance_kept('acquire') ? nullify(input('acquired_from')) : null,
        'acquired_price'   => provenance_kept('acquire') ? nullify(input('acquired_price')) : null,
        'acquired_note'    => provenance_kept('acquire') ? nullify(input('acquired_note')) : null,
        'currency'         => strtoupper(mb_substr((string) input('currency', (string) config('currency')), 0, 3)),
        'location_id'      => valid_location_id(),
        'location_position' => nullify(input('location_position')),
        'is_original'      => input_bool('is_original'),
        // A sale date settles the status. Server side rather than in the browser,
        // because the fact has to hold whether or not any script ran - and an
        // entry with a sale date and a status of "owned" is a contradiction the
        // dashboard would then report as truth.
        'status'           => (function () {
            // Where the form carries the provenance controls, status is the single
            // answer and is taken as posted: the Sale block is revealed by it, so
            // inferring it back from a sale date would let two controls disagree.
            //
            // Everywhere else - the software form, the API - a sale date still
            // settles it, because those have no such block and an entry with a sale
            // date and a status of "owned" is a contradiction the dashboard would
            // report as truth.
            $want = rule_status(input('status', 'owned')) ?? 'owned';
            if (isset($_POST['provenance_declared'])) {
                return $want;
            }
            return trim((string) input('sold_on', '')) !== '' ? 'sold' : $want;
        })(),
        'sold_on'          => provenance_kept('sale') ? nullify(input('sold_on'))    : null,
        'sold_to'          => provenance_kept('sale') ? nullify(input('sold_to'))    : null,
        'sold_price'       => provenance_kept('sale') ? nullify(input('sold_price')) : null,
        // Its own currency, because bought in SEK and sold in EUR is ordinary.
        // NULL unless there is a figure to attach it to, so an unsold entry does
        // not carry a currency that implies a sale happened.
        'sold_currency'    => (!provenance_kept('sale') || nullify(input('sold_price')) === null)
            ? null
            : strtoupper(mb_substr((string) input('sold_currency', (string) input('currency', (string) config('currency'))), 0, 3)),
        'sold_note'        => provenance_kept('sale') ? nullify(input('sold_note')) : null,
        // Only a form that carries the box controls may decide these. The
        // software form posts condition_box but no has_box, so reading the
        // checkbox unconditionally would clear the box grade off every boxed game
        // the moment somebody saved it from that form. Where the controls are
        // absent, has_box is inferred from the grade instead.
        // The box, and the grade that only means something while there is one.
        //
        // rule_box_state() holds both halves - including the inference for a
        // form that posts a grade and no checkbox, which is what the software
        // form does. Reading the checkbox unconditionally would clear the box
        // grade off every boxed game the moment somebody saved it from there.
        'has_box'          => $boxState['has_box'],
        'condition_box'    => $boxState['condition_box'],
        'condition_manual' => rule_component_grade(input('condition_manual')) ?? 'unknown',
        'condition_media'  => rule_component_grade(input('condition_media')) ?? 'unknown',
        'current_value'    => nullify(input('current_value')),
        'valued_on'        => nullify(input('valued_on')),
        'copies'           => max(1, (int) (input_int('copies') ?? 1)),
        'external_url'     => nullify(input('external_url')),
        'notes'            => nullify($_POST['notes'] ?? null),
        // What the release is, as against what you think of your copy. A lookup
        // writes here now; notes are left to the person.
        'description'      => nullify($_POST['description'] ?? null),
    ];
}

function items_validate(array $data): array
{
    $errors = [];
    if (trim($data['title']) === '') {
        $errors[] = 'Give the title a name.';
    }
    if ($data['library_id'] <= 0) {
        $errors[] = 'Choose which library this belongs to.';
    } else {
        // A library may be pinned to one machine, which is what stops a C64
        // disk landing on the club's Amiga shelf.
        $library = one('SELECT restrict_to_platform_id FROM libraries WHERE id = ?', [$data['library_id']]);
        if ($library !== null
            && $library['restrict_to_platform_id'] !== null
            && (int) $library['restrict_to_platform_id'] !== $data['platform_id']) {
            $errors[] = 'That library only accepts entries for one platform.';
        }
    }
    if ($data['platform_id'] <= 0) {
        $errors[] = 'Pick which machine this is for.';
    }
    if ($data['category_id'] <= 0) {
        $errors[] = 'Pick a software type.';
    }
    if ($data['rating'] !== null && ($data['rating'] < 1 || $data['rating'] > 10)) {
        $errors[] = 'Rating runs from 1 to 10.';
    }
    if ($data['release_year'] !== null && ($data['release_year'] < 1950 || $data['release_year'] > (int) date('Y') + 1)) {
        $errors[] = 'Release year looks wrong.';
    }
    if ($data['release_date'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['release_date'])) {
        $errors[] = 'Release date must be YYYY-MM-DD.';
    }
    if ($data['acquired_on'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['acquired_on'])) {
        $errors[] = 'Acquired date must be YYYY-MM-DD.';
    }
    foreach (['acquired_price' => 'Price paid', 'current_value' => 'Current value', 'sold_price' => 'Sold for'] as $key => $label) {
        if ($data[$key] !== null && !is_numeric($data[$key])) {
            $errors[] = "$label must be a number.";
        }
    }
    foreach (['sold_on' => 'Sold on', 'valued_on' => 'Valued on'] as $key => $label) {
        if ($data[$key] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data[$key])) {
            $errors[] = "$label must be YYYY-MM-DD.";
        }
    }
    if ($data['external_url'] !== null && !filter_var($data['external_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Reference link must be a full URL starting with https://.';
    }
    return $errors;
}

function items_store(): void
{
    require_edit();
    csrf_verify();

    $data   = items_payload();
    $errors = items_validate($data);

    // Unconditional. This used to be guarded by library_id > 0, so a POST that
    // simply omitted the field skipped the check entirely and - with no foreign
    // key on the column - wrote an entry into library 0, which exists in no
    // membership row and so was invisible to everyone including its author.
    if (!can_add_to_library((int) $data['library_id'])) {
        $errors[] = 'You do not have write access to that library.';
    }
    if ($errors !== []) {
        foreach ($errors as $err) {
            flash('error', $err);
        }
        redirect('/items/new');
    }

    $user = current_user();
    $data['created_by'] = $user === null ? null : (int) $user['id'];

    // The hardware half, when the form sent one. Kept out of $data because it
    // belongs to a different table.
    // The exact machine, when one was named. Checked against the maker so a
    // hand-made request cannot file an Atari under Commodore.
    $modelId  = input_int('model_id');
    $vendorId = input_int('vendor_id');
    if ($modelId !== null && $modelId > 0) {
        $model = one('SELECT * FROM hardware_models WHERE id = ?', [$modelId]);
        // By slug. A library vendor id compared against the model's template one
        // could only ever fail, so naming a manufacturer silently stopped the
        // model being linked at all - which is why the imported card lost both.
        $sameMaker = $vendorId === null || (int) scalar(
            "SELECT COUNT(*) FROM companies a JOIN companies b ON b.slug = a.slug
              WHERE a.id = ? AND b.id = ?", [$vendorId, (int) $model['vendor_id']]
        ) > 0;
        if ($model !== null && $sameMaker) {
            $data['model_id'] = $modelId;
            // The machine settles the family. Taking it from the model rather
            // than from the form means the two can never disagree, whatever a
            // hand-made request claims.
            $data['platform_id'] = (int) $model['platform_id'];
        }
    }

    // Anything the form left blank, the chosen title supplies.
    if (($data['title_id'] ?? null) !== null) {
        $title = one('SELECT * FROM titles WHERE id = ?', [(int) $data['title_id']]);
        if ($title !== null) {
            $data += title_defaults_for_item($title, $data);
        }
    }

    // The branch has to belong to the library the entry is going into.
    //
    // The form posts an id and this trusted it, so an entry could be filed under
    // a *template* branch - one that belongs to no library, is invisible in the
    // tree it appears to be in, and moves when the templates are next imported.
    // Refused rather than corrected: quietly refiling somebody's entry somewhere
    // else is worse than telling them the branch was not one of theirs.
    if (($data['category_id'] ?? null) !== null
        && category_for_library((int) $data['category_id'], (int) $data['library_id']) === null) {
        flash('error', 'That kind belongs to another library, so the entry was not saved. '
                     . 'Pick one from this library\'s own tree.');
        redirect('/items/new');
    }

    $GLOBALS['__hw_payload'] = hardware_payload();

    $id = insert_row('items', $data);
    record_acquisition_event((int) $id, $data);
    apply_software_model((int) $id, $data);

    $hwPayload = $GLOBALS['__hw_payload'] ?? [];
    if ($hwPayload !== []) {
        save_item_hardware((int) $id, $hwPayload);
    }
    sync_item_tags($id, (string) input('tags', ''));
    sync_item_environments((int) $id, (int) ($data['library_id'] ?? 0) ?: null);

    // What is in the box. Only when the form actually carried the section, so a save
    // from somewhere else - the API, an import - does not wipe a list it never showed.
    if (isset($_POST['content_label'])) {
        set_item_contents($id, (array) $_POST['content_label'],
                          (array) ($_POST['content_present'] ?? []),
                          (array) ($_POST['content_note'] ?? []));
    }

    // Links to documents held elsewhere, the same way: only when the form carried
    // the section, so a save from the API or an import does not wipe a list it
    // never showed.
    if (isset($_POST['document_url'])) {
        set_item_documents($id, (array) ($_POST['document_label'] ?? []),
                                (array) $_POST['document_url']);
    }

    // The media rows, when the form carried them. media_type and media_count on
    // the entry are kept in step with the first row inside set_item_media().
    if (isset($_POST['media_type']) && is_array($_POST['media_type'])) {
        set_item_media($id, (array) $_POST['media_type'], (array) ($_POST['media_qty'] ?? []));
    }

    // Only when the model has no answer of its own. A copy of a BigRAM 2008
    // cannot fit something a BigRAM 2008 does not, so a posted list is ignored
    // rather than allowed to disagree - and the existing rows are left alone, so
    // detaching the model later gives them back.
    $modelNow = input_int('model_id');
    if ($modelNow === null || $modelNow <= 0 || model_compatibility_ids((int) $modelNow) === []) {
        set_item_compatibility($id, (array) ($_POST['item_compatibility'] ?? []));
    }

    // Where this card is fitted, set from the card's own form. The relationship
    // has only ever been editable from the machine end, which meant opening the
    // machine and finding the card in a list to answer a question about the card.
    // Same link, same rules - fit_peripheral() still refuses a second machine, a
    // different platform, and a loop.
    if (array_key_exists('installed_in_item_id', $_POST)) {
        apply_installed_in((int) $id, input_int('installed_in_item_id'));
    }

    // What the editor said about the pictures already there, before adding any:
    // removing four and adding one should end with one, not five.
    //
    // Guarded on the field being present, so a save from the API or an import -
    // neither of which renders the gallery - does not read an absent list as
    // "remove nothing, and unset the main picture".
    if (isset($_POST['image_primary']) || isset($_POST['image_remove'])
        || isset($_POST['image_caption']) || isset($_POST['image_section_of'])) {
        apply_item_image_edits($id,
            (array) ($_POST['image_remove'] ?? []),
            input_int('image_primary'),
            (array) ($_POST['image_caption'] ?? []),
            (array) ($_POST['image_section_of'] ?? []),
            (string) (scalar('SELECT s.slug FROM items i
                               JOIN categories c ON c.id = i.category_id
                               JOIN sections s ON s.id = c.section_id
                               WHERE i.id = ?', [$id]) ?: 'software'));
    }

    // The section names the provenance; the kind is what the picture shows. A
    // section the form did not offer cannot be posted into: the lookup section is
    // the only official one, and it is reachable from a form only by choosing it.
    $upSection  = (string) input('image_section', '');
    // The entry's own side of the shop, read from the row rather than from a
    // variable that is not in scope here. `$domain ?? 'software'` would have been
    // the software sections on every hardware upload, silently.
    $upSections = image_sections((string) (scalar(
        'SELECT s.slug FROM items i JOIN categories c ON c.id = i.category_id
         JOIN sections s ON s.id = c.section_id WHERE i.id = ?',
        [$id]) ?: 'software'));
    $upProv     = ($upSections[$upSection]['provenance'] ?? 'personal') === 'official'
        ? 'official' : 'personal';
    [$stored, $imgErrors] = store_item_images($id, 'images',
        (string) input('image_kind', 'box_front'), $upProv);
    foreach ($imgErrors as $err) {
        flash('error', $err);
    }

    flash('ok', sprintf('Added %s%s.', $data['title'], $stored ? " with $stored photo" . ($stored > 1 ? 's' : '') : ''));
    log_server('item.created', 'Added "' . $data['title'] . '"', LOG_INFO,
               ['subject_type' => 'item', 'subject_id' => $id]);
    redirect(items_after_save($id));
}

/**
 * Where a save goes next.
 *
 * Normally back where you came from. But there is a second button on the form -
 * "Save and look up" - and it means: file this, then show me what the sources say
 * about it.
 *
 * Saving first is what makes that simple. A lookup applies its answer to a row,
 * so the version that ran before saving needed a draft to stand in for one:
 * somewhere to keep half a form, a shape for it, a way to throw it away, and an
 * apply path that wrote to a session instead of a table. None of that is needed
 * once the entry exists - and hardware works too, because its detail goes through
 * save_item_hardware(), which needs a row and now has one.
 */
function items_after_save(int $id): string
{
    if (input('after') === 'lookup') {
        return url('/metadata/lookup', ['item' => $id]);
    }
    return items_return_to($id);
}

/**
 * Where to go after saving or cancelling an entry.
 *
 * The browser's per-row Edit link passes ?return=, so you land back in the list you
 * were working down rather than on the entry's own page. Only same-site paths are
 * honoured - a redirect target that came in on the query string is somewhere an
 * attacker can put anything.
 */
function items_return_to(int $id): string
{
    $want = trim((string) (input('return', '') ?: ''));
    if ($want !== '' && str_starts_with($want, '/') && !str_starts_with($want, '//')) {
        return $want;
    }
    return '/items/' . $id;
}

function items_update(int $id): void
{
    require_edit();
    csrf_verify();

    $existing = one('SELECT * FROM items WHERE id = ? AND deleted_at IS NULL', [$id]);
    if ($existing === null) {
        not_found();
    }
    if (!can_write_item($existing)) {
        flash('error', 'You do not have write access to that library.');
        redirect('/items');
    }

    $data   = items_payload();
    $errors = items_validate($data);

    // Moving an entry between libraries needs write access at both ends.
    if (!can_add_to_library((int) $data['library_id'])) {
        $errors[] = 'You do not have write access to the library you are moving this into.';
    }
    if ($errors !== []) {
        foreach ($errors as $err) {
            flash('error', $err);
        }
        redirect('/items/' . $id . '/edit');
    }

    if (($data['title_id'] ?? null) !== null) {
        $title = one('SELECT * FROM titles WHERE id = ?', [(int) $data['title_id']]);
        if ($title !== null) {
            $data += title_defaults_for_item($title, $data);
        }
    }

    $stored = items_persist($id, $existing, $data);

    log_server('item.updated', 'Changed "' . $data['title'] . '"', LOG_INFO,
               ['subject_type' => 'item', 'subject_id' => $id]);
    flash('ok', 'Saved ' . $data['title'] . ($stored ? " and added $stored photo" . ($stored > 1 ? 's' : '') : '') . '.');
    redirect(items_after_save($id));
}

/**
 * Write a submitted entry over an existing one. Returns how many photos landed.
 *
 * Split out of items_update() so the fit and unfit buttons can use it. Those
 * buttons sit inside the entry form and carry formaction, so pressing one submits
 * every field on the page to /items/{id}/fitted - which handled the fit and
 * redirected, throwing away whatever else had been typed. Set a location, press
 * "Install the peripheral", and the location was gone with nothing to say so.
 */
function items_persist(int $id, array $existing, array $data): int
{
    // Before the overwrite, so a revaluation or a loan leaves a trace rather
    // than replacing what was there.
    record_value_change($id, $existing, $data);
    update_row('items', $id, $data);

    $hwPayload = hardware_payload();
    if ($hwPayload !== []) {
        save_item_hardware($id, $hwPayload);
    }
    sync_item_tags($id, (string) input('tags', ''));
    sync_item_environments((int) $id, (int) ($data['library_id'] ?? 0) ?: null);

    // What is in the box. Only when the form actually carried the section, so a save
    // from somewhere else - the API, an import - does not wipe a list it never showed.
    if (isset($_POST['content_label'])) {
        set_item_contents($id, (array) $_POST['content_label'],
                          (array) ($_POST['content_present'] ?? []),
                          (array) ($_POST['content_note'] ?? []));
    }

    // Links to documents held elsewhere, the same way: only when the form carried
    // the section, so a save from the API or an import does not wipe a list it
    // never showed.
    if (isset($_POST['document_url'])) {
        set_item_documents($id, (array) ($_POST['document_label'] ?? []),
                                (array) $_POST['document_url']);
    }

    // The media rows, when the form carried them. media_type and media_count on
    // the entry are kept in step with the first row inside set_item_media().
    if (isset($_POST['media_type']) && is_array($_POST['media_type'])) {
        set_item_media($id, (array) $_POST['media_type'], (array) ($_POST['media_qty'] ?? []));
    }

    // Only when the model has no answer of its own. A copy of a BigRAM 2008
    // cannot fit something a BigRAM 2008 does not, so a posted list is ignored
    // rather than allowed to disagree - and the existing rows are left alone, so
    // detaching the model later gives them back.
    $modelNow = input_int('model_id');
    if ($modelNow === null || $modelNow <= 0 || model_compatibility_ids((int) $modelNow) === []) {
        set_item_compatibility($id, (array) ($_POST['item_compatibility'] ?? []));
    }

    // Where this card is fitted, set from the card's own form. The relationship
    // has only ever been editable from the machine end, which meant opening the
    // machine and finding the card in a list to answer a question about the card.
    // Same link, same rules - fit_peripheral() still refuses a second machine, a
    // different platform, and a loop.
    if (array_key_exists('installed_in_item_id', $_POST)) {
        apply_installed_in($id, input_int('installed_in_item_id'));
    }

    // What the editor said about the pictures already there, before adding any:
    // removing four and adding one should end with one, not five.
    //
    // Guarded on the field being present, so a save from the API or an import -
    // neither of which renders the gallery - does not read an absent list as
    // "remove nothing, and unset the main picture".
    if (isset($_POST['image_primary']) || isset($_POST['image_remove'])
        || isset($_POST['image_caption']) || isset($_POST['image_section_of'])) {
        apply_item_image_edits($id,
            (array) ($_POST['image_remove'] ?? []),
            input_int('image_primary'),
            (array) ($_POST['image_caption'] ?? []),
            (array) ($_POST['image_section_of'] ?? []),
            (string) (scalar('SELECT s.slug FROM items i
                               JOIN categories c ON c.id = i.category_id
                               JOIN sections s ON s.id = c.section_id
                               WHERE i.id = ?', [$id]) ?: 'software'));
    }

    // The section names the provenance; the kind is what the picture shows. A
    // section the form did not offer cannot be posted into: the lookup section is
    // the only official one, and it is reachable from a form only by choosing it.
    $upSection  = (string) input('image_section', '');
    // The entry's own side of the shop, read from the row rather than from a
    // variable that is not in scope here. `$domain ?? 'software'` would have been
    // the software sections on every hardware upload, silently.
    $upSections = image_sections((string) (scalar(
        'SELECT s.slug FROM items i JOIN categories c ON c.id = i.category_id
         JOIN sections s ON s.id = c.section_id WHERE i.id = ?',
        [$id]) ?: 'software'));
    $upProv     = ($upSections[$upSection]['provenance'] ?? 'personal') === 'official'
        ? 'official' : 'personal';
    [$stored, $imgErrors] = store_item_images($id, 'images',
        (string) input('image_kind', 'box_front'), $upProv);
    foreach ($imgErrors as $err) {
        flash('error', $err);
    }
    return (int) $stored;
}

function items_destroy(int $id): void
{
    csrf_verify();
    $item = find_item($id);
    if ($item === null || !can_write_item($item)) {
        flash('error', 'That entry is not yours to remove.');
        redirect('/items');
    }

    // Not while it is fitted to something, in either direction.
    //
    // The rule lives in item_delete_blocker() so it can be asked as well as
    // obeyed: this handler ends in a redirect, so a test could only read the
    // source and hope.
    $blocker = item_delete_blocker($id, (string) $item['title']);
    if ($blocker !== null) {
        flash('error', $blocker);
        redirect('/items/' . $id);
    }

    // Gone, and its photographs with it.
    //
    // It used to be set aside: hidden from every list while the row stayed, which
    // made a deleted entry go on being counted on the library screen and go on
    // holding the company it pointed at, so that company could not be deleted
    // either. A delete that leaves the thing behind is a different feature
    // wearing the same word.
    //
    // Files first: the rows cascade when the entry goes, and afterwards there is
    // nothing left to ask which files were involved.
    $files = delete_item_image_files($id);

    // A tombstone, so a sync or an export can tell "deleted" from "never seen".
    q('INSERT IGNORE INTO tombstones (entity, entity_id, deleted_at) VALUES (?, ?, NOW())',
      ['item', $id]);
    q('DELETE FROM items WHERE id = ?', [$id]);

    log_server('item.deleted', 'Deleted "' . $item['title'] . '"', LOG_NOTICE,
               ['subject_type' => 'item', 'subject_id' => $id, 'files' => $files]);

    flash('ok', $item['title'] . ' deleted'
        . ($files > 0 ? ', with ' . $files . ' image file' . ($files === 1 ? '' : 's') . '.' : '.'));
    redirect('/items');
}

// No trash.
//
// Deleting deletes now, so there is nothing to list, restore or purge - and a
// screen that can only ever be empty is a promise that something is recoverable
// when it is not.

// --- Image sub-routes -------------------------------------------------------

function images_update(int $imageId): void
{
    require_edit();
    csrf_verify();

    $img = one('SELECT * FROM item_images WHERE id = ?', [$imageId]);
    if ($img === null) {
        not_found();
    }
    $itemId = (int) $img['item_id'];
    $parent = one('SELECT library_id, platform_id, created_by FROM items WHERE id = ?', [$itemId]);
    if ($parent === null || !can_write_item($parent)) {
        flash('error', 'You do not have write access to that library.');
        redirect('/items');
    }
    $action = input('action', 'save');

    if ($action === 'delete') {
        delete_image($imageId);
        record_tombstone('item_images', $imageId, (int) $parent['library_id']);
        flash('ok', 'Photo deleted.');
    } elseif ($action === 'primary') {
        set_primary_image($itemId, $imageId);
        flash('ok', 'Cover photo updated.');
    } else {
        update_row('item_images', $imageId, [
            'kind'       => in_array(input('kind', 'other'), image_kind_options(), true) ? input('kind', 'other') : 'other',
            'caption'    => nullify(input('caption')),
            'sort_order' => (int) (input_int('sort_order') ?? 0),
        ]);
        flash('ok', 'Photo details saved.');
    }
    redirect('/items/' . $itemId . '/edit');
}

// --- CSV export -------------------------------------------------------------

/**
 * The columns of the CSV, in order, mapping header text to how the value is
 * produced. One list, used by both export and import, so a round trip through
 * a spreadsheet lines up rather than nearly lining up.
 */
function csv_columns(): array
{
    return [
        'ID'             => 'id',
        'Library'        => 'library_name',
        'Platform'       => 'platform_name',
        'Type'           => 'category_name',

        'Title'          => 'title',
        'Subtitle'       => 'subtitle',
        'Sort title'     => 'sort_title',
        'Developer'      => 'developer_name',
        'Publisher'      => 'publisher_name',
        'Year'           => 'release_year',
        'Release date'   => 'release_date',
        'Rating'         => 'rating',
        'Condition'      => 'condition_grade',
        'Completeness'   => 'completeness',
        'Media'          => 'media_type',
        'Media count'    => 'media_count',
        'Catalog no'     => 'catalog_number',
        'Barcode'        => 'barcode',
        'Language'       => 'language',
        'Region'         => 'region',
        'Acquired'       => 'acquired_on',
        'Price'          => 'acquired_price',
        'Currency'       => 'currency',
        'Current value'  => 'current_value',
        'Copies'         => 'copies',
        'Status'         => 'status',
        'Sold on'        => 'sold_on',
        'Sold for'       => 'sold_price',
        'Sold currency'  => 'sold_currency',
        'Has box'        => 'has_box',
        'Box'            => 'condition_box',
        'Manual'         => 'condition_manual',
        'Media condition' => 'condition_media',
        'Location'       => 'location_name',
        'Original'       => 'is_original',
        'Photos'         => 'image_count',
        'Reference URL'  => 'external_url',
        'Notes'          => 'notes',
    ];
}

/**
 * Neutralise a cell that a spreadsheet would treat as a formula.
 *
 * Excel and LibreOffice execute anything beginning =, +, -, @, tab or CR. Retro
 * titles legitimately start with '+' - the cracking scene is full of them - so
 * this is not a theoretical concern, and a leading apostrophe is the standard
 * way to say "this is text".
 */
function csv_safe($value): string
{
    $s = (string) $value;
    if ($s !== '' && str_contains("=+-@\t\r", $s[0])) {
        return "'" . $s;
    }
    return $s;
}

function items_export_csv(): void
{
    [$where, $params] = build_item_filters($_GET);
    $columns = csv_columns();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="retrohive-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
    fputcsv($out, array_keys($columns));

    // Streamed a page at a time rather than loaded whole. all() on an
    // unfiltered export means the entire collection in memory at once, which is
    // fine at a thousand entries and not at fifty thousand.
    $offset = 0;
    $chunk  = 500;
    do {
        $rows = all(
            "SELECT * FROM v_items WHERE $where
             ORDER BY library_name, platform_name, COALESCE(sort_title, title)
             LIMIT $chunk OFFSET $offset",
            $params
        );
        foreach ($rows as $r) {
            $line = [];
            foreach ($columns as $field) {
                $value = $r[$field] ?? null;
                $value = match ($field) {
                    'condition_grade'  => condition_label($r['condition_grade']),
                    'completeness'     => completeness_label($r['completeness']),
                    'status'           => status_label($r['status']),
                    'condition_box',
                    'condition_manual',
                    'condition_media'  => condition_label($value),
                    'is_original'      => $value ? 'yes' : 'no',
                    default            => $value,
                };
                $line[] = csv_safe($value);
            }
            fputcsv($out, $line);
        }
        $offset += $chunk;
        flush();
    } while (count($rows) === $chunk);

    fclose($out);
    exit;
}

/**
 * Record or remove "this is fitted to that".
 *
 * Guarded against making a loop: a card installed in a machine that is installed
 * in the card would make item_goes_with() recurse until the page died, and
 * nothing in the schema prevents somebody trying.
 */
/**
 * Fit a peripheral to a machine, or take one out.
 *
 * Separate from item_link_save(), which handles the general "this goes with
 * that" relations. Fitting is the exclusive one: a physical object is inside
 * one machine or none, so the rules are stricter and the refusals are worth
 * spelling out rather than reporting as a constraint violation.
 */
function items_fitted_save(int $machineId): void
{
    require_edit();
    csrf_verify();

    $machine = find_item($machineId);
    if ($machine === null) {
        not_found();
    }
    if (!can_write_item($machine)) {
        flash('error', 'That machine is not yours to change.');
        redirect('/items/' . $machineId);
    }

    // Save the rest of the form first.
    //
    // These buttons live inside the entry form and carry formaction, so pressing
    // one submits every field on the page here. Handling only the fit and
    // redirecting discarded the lot: change the location, press "Install the
    // peripheral", and the location was silently gone. A button inside a form full
    // of edits has to keep them.
    //
    // Recognised by the form's own hidden marker rather than by guessing from the
    // field list, and refused rather than half-applied if the entry does not
    // validate - the person is sent back to the form with the reason, exactly as a
    // plain Save would do.
    if (isset($_POST['domain']) && input('title') !== null) {
        $data   = items_payload();
        $errors = items_validate($data);
        if (!can_add_to_library((int) $data['library_id'])) {
            $errors[] = 'You do not have write access to the library you are moving this into.';
        }
        if ($errors !== []) {
            foreach ($errors as $err) {
                flash('error', $err);
            }
            flash('error', 'Nothing was fitted, because the entry itself could not be saved.');
            redirect('/items/' . $machineId . '/edit');
        }
        items_persist($machineId, $machine, $data);
    }

    $remove = input_int('remove');
    if ($remove !== null && $remove > 0) {
        $part = find_item($remove);
        unfit_peripheral($machineId, $remove);
        flash('ok', ($part['title'] ?? 'It') . ' is no longer fitted, and is free to go in something else.');
        redirect('/items/' . $machineId . '/edit');
    }

    $fit = input_int('fit');
    if ($fit === null || $fit <= 0) {
        redirect('/items/' . $machineId . '/edit');
    }

    [$ok, $message] = fit_peripheral($machineId, $fit);
    flash($ok ? 'ok' : 'error', $message);
    redirect('/items/' . $machineId . '/edit');
}

function item_link_save(int $id): void
{
    csrf_verify();
    $item = one('SELECT * FROM items WHERE id = ?', [$id]);
    if ($item === null || !can_write_item($item)) {
        flash('error', 'That entry is not yours to change.');
        redirect(items_return_to($id));
    }

    if (input('action') === 'unlink') {
        q('DELETE FROM item_links WHERE id = ?', [input_int('link_id')]);
        flash('ok', 'Unlinked.');
        redirect(items_return_to($id));
    }

    $otherId  = input_int('other_id');
    $relation = (string) input('relation', 'installed_in');
    if ($otherId === null || !array_key_exists($relation, link_relations())) {
        redirect(items_return_to($id));
    }

    $other = one('SELECT * FROM items WHERE id = ?', [$otherId]);
    if ($other === null || !can_read_item($other)) {
        flash('error', 'No such entry.');
        redirect(items_return_to($id));
    }

    // The form reads "this is installed in that", so the entry being viewed is
    // the child and the one chosen is the parent.
    $parent = $otherId;
    $child  = $id;
    if (input('direction') === 'contains') {
        $parent = $id;
        $child  = $otherId;
    }

    if ($parent === $child) {
        flash('error', 'An entry cannot be fitted to itself.');
        redirect(items_return_to($id));
    }
    // Any relation, any depth. Checking only the 'installed_in' breadcrumb, as
    // this used to, missed both mixed-relation loops and anything more than six
    // links deep.
    if (item_link_would_loop($parent, $child)) {
        flash('error', 'That would make a loop: ' . $other['title'] . ' already sits inside this one, directly or through something else.');
        redirect(items_return_to($id));
    }

    q('INSERT IGNORE INTO item_links (parent_item_id, child_item_id, relation, note)
       VALUES (?, ?, ?, ?)',
      [$parent, $child, $relation, nullify(input('note'))]);

    flash('ok', 'Linked.');
    redirect(items_return_to($id));
}

/** The item_hardware half of a submitted hardware form. */
/**
 * The item_hardware half of a submitted hardware form.
 *
 * The specification is one ordered list of label/value rows rather than three
 * mechanisms that all answered the same question. Whatever the person typed is
 * what gets stored, in the order they put it in.
 */
function hardware_payload(): array
{
    if (input('domain') !== 'hardware') {
        return [];
    }

    $out = [];
    foreach (['model', 'board_revision', 'firmware', 'serial_number', 'interface', 'provides',
              'fits', 'modifications'] as $f) {
        $v = nullify(input('hw_' . $f));
        if ($v !== null) {
            $out[$f] = $v;
        }
    }

    $year = input_int('hw_manufactured_year');
    if ($year !== null && $year > 0) {
        $out['manufactured_year'] = $year;
    }

    $state = (string) input('hw_working_state', 'untested');
    $out['working_state'] = in_array($state, ['untested', 'working', 'intermittent', 'not_working', 'restored'], true)
        ? $state : 'untested';

    // Region, not "video standard", and it is not a condition: a PAL A500 is
    // not in worse shape than an NTSC one, it is a different machine.
    $region = (string) input('hw_region', 'unknown');
    $out['region'] = in_array($region, ['unknown', 'PAL', 'NTSC', 'both'], true) ? $region : 'unknown';

    // Two dates, same rule: a malformed one is dropped rather than written, because a
    // date column will take "2019" and turn it into something nobody typed.
    foreach (['recapped_on' => 'hw_recapped_on', 'serviced_on' => 'hw_serviced_on'] as $col => $field) {
        $when = nullify(input($field));
        $out[$col] = ($when !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $when))
            ? $when : null;
    }

    $out['psu_included'] = empty($_POST['hw_psu_included']) ? 0 : 1;

    // Known interfaces are recorded by id as well as by name, which is what
    // makes "what do I own that fits a Zorro III slot?" an exact query.
    $out['interface_vocab_id'] = hardware_interface_vocab_id(
        $out['interface'] ?? null,
        (int) input_int('platform_id', 0)
    );

    $out['specs'] = specs_payload();

    return $out;
}

/**
 * The specification rows, as submitted.
 *
 * Paired arrays rather than an associative one: two rows may legitimately share
 * a label (two SIMM sockets, two drives), and the order the person chose is
 * part of what they wrote.
 */
function specs_payload(): ?string
{
    $labels = $_POST['hw_spec_label'] ?? null;
    $values = $_POST['hw_spec_value'] ?? null;

    if (!is_array($labels)) {
        return null;
    }

    $rows = [];
    foreach ($labels as $i => $label) {
        $label = trim((string) $label);
        $value = trim((string) ($values[$i] ?? ''));

        // A row with neither is the empty one at the bottom of the form.
        // A label with no value is someone who meant to come back to it, and
        // is worth keeping; a value with no label has nothing to file it under.
        if ($label === '') {
            continue;
        }
        $rows[] = [
            'label' => mb_substr($label, 0, 80),
            'value' => mb_substr($value, 0, 400),
        ];
        if (count($rows) >= 60) {
            break;
        }
    }

    return $rows === [] ? null : json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}


/**
 * Record which shape of release this is, and take what it implies.
 *
 * The model lives on the title, because it is a fact about the release rather than
 * about one copy of it - so a second copy of the same game inherits it. Where the entry
 * has no title yet, one is made: typing a name into the form is how titles come into
 * existence, and a model chosen with no title to hang it on would be discarded.
 *
 * The box contents come with it, which is the point: a model says a boxed Amiga game
 * holds a manual, disks and a registration card, and copying that saves ticking them
 * off by hand every time.
 */
function apply_software_model(int $itemId, array $data): void
{
    $modelId = input_int('software_model_id');
    if ($modelId === null || $modelId <= 0) {
        return;
    }
    $libraryId = (int) ($data['library_id'] ?? 0);

    // This library's model, never another's.
    $model = one('SELECT * FROM software_models WHERE id = ? AND library_id = ?',
                 [$modelId, $libraryId]);
    if ($model === null) {
        return;
    }

    $titleId = (int) ($data['title_id'] ?? 0);
    if ($titleId <= 0) {
        $name = trim((string) ($data['title'] ?? ''));
        if ($name === '') {
            return;
        }
        $titleId = (int) insert_row('titles', [
            'platform_id'       => (int) ($data['platform_id'] ?? 0) ?: null,
            'category_id'       => (int) ($data['category_id'] ?? 0) ?: null,
            'developer_id'      => (int) ($data['developer_id'] ?? 0) ?: null,
            'software_model_id' => $modelId,
            'name'              => mb_substr($name, 0, 220),
            'slug'              => unique_slug('titles', slugify($name)),
            'work_key'          => slugify($name),
            'release_year'      => (int) ($data['release_year'] ?? 0) ?: null,
        ]);
        update_row('items', $itemId, ['title_id' => $titleId]);
    } else {
        update_row('titles', $titleId, ['software_model_id' => $modelId]);
    }

    // What the box should hold, copied onto the copy - but only where the person has
    // not already said. Overwriting a list somebody filled in is not help.
    if ((int) scalar('SELECT COUNT(*) FROM item_contents WHERE item_id = ?', [$itemId]) > 0) {
        return;
    }
    $order = 0;
    foreach (all('SELECT label, note FROM software_model_contents WHERE model_id = ? ORDER BY sort_order, id',
                 [$modelId]) as $c) {
        $order += 10;
        insert_row('item_contents', [
            'item_id'    => $itemId,
            'label'      => (string) $c['label'],
            'present'    => 'unknown',
            'sort_order' => $order,
        ]);
    }
}

/** Match a typed interface against the controlled vocabulary, if it is in it. */
function hardware_interface_vocab_id(?string $interface, int $platformId): ?int
{
    if ($interface === null || trim($interface) === '') {
        return null;
    }
    $row = one(
        'SELECT id FROM hardware_vocab
          WHERE kind = \'interface\' AND platform_id IN (0, ?)
            AND (code = ? OR name = ?)
          ORDER BY platform_id DESC LIMIT 1',
        [$platformId, trim($interface), trim($interface)]
    );
    return $row === null ? null : (int) $row['id'];
}
function valid_title_id(): ?int
{
    $titleId = input_int('title_id');
    if ($titleId !== null && $titleId > 0) {
        return one('SELECT id FROM titles WHERE id = ?', [$titleId]) === null ? null : $titleId;
    }

    // Only offered for software; a machine is described by its model.
    if (input('as') === 'machine' || input('as') === 'part') {
        return null;
    }
    $typed = input('title_name');
    if ($typed === null || trim($typed) === '') {
        return null;
    }
    $platformId = (int) input_int('platform_id', 0);
    if ($platformId <= 0) {
        return null;
    }
    return title_id_for(trim($typed), $platformId, input_int('release_year'), [
        'category_id' => input_int('category_id'),
        'developer'   => input('developer_name'),
        'publisher'   => input('publisher_name'),
    ]);
}

function derived_platform_id(): int
{
    // A chosen title already knows which machine it is for, and a title is
    // per platform, so it settles the question the same way a model does.
    $titleId = input_int('title_id');
    if ($titleId !== null && $titleId > 0) {
        $t = scalar('SELECT platform_id FROM titles WHERE id = ?', [$titleId]);
        if ($t !== null) {
            return (int) $t;
        }
    }

    // A known card names its machine, and knows better than the form.
    $modelId = input_int('model_id');
    if ($modelId !== null && $modelId > 0) {
        $vendorId = input_int('vendor_id');
        $model = one('SELECT vendor_id, platform_id FROM hardware_models WHERE id = ?', [$modelId]);
        if ($model !== null && ($vendorId === null || $vendorId <= 0 || (int) $model['vendor_id'] === $vendorId)) {
            return (int) $model['platform_id'];
        }
    }
    return (int) input_int('platform_id', 0);
}


/**
 * The kind of thing this is.
 *
 * A known card says what it is, so the form need not ask - and when it does say,
 * its answer wins over anything posted, because the card is the authority on
 * what a card is.
 */
/**
 * What kind of thing this is.
 *
 * Never asked on the machine form any more, because nothing there needs to
 * answer it: the model says what it is, and failing a model the platform's
 * class does - a Mega Drive is a console whether or not you named a model.
 * Asking anyway invited a Blizzard 1230 to be filed as a computer.
 */
function derived_category_id(): int
{
    // A chosen model is the authority: it was decided once, when the model was
    // defined, and every unit inherits it.
    $modelId = input_int('model_id');
    if ($modelId !== null && $modelId > 0) {
        $c = scalar('SELECT category_id FROM hardware_models WHERE id = ?', [$modelId]);
        if ($c !== null) {
            return (int) $c;
        }
    }

    // What the form was told, whichever kind it is.
    //
    // A machine's branch used to be derived and a posted one ignored - the form
    // did not ask, so anything arriving was assumed to be noise. It asks now, and
    // ignoring the answer would be worse than not asking: somebody would choose a
    // branch and watch the entry appear somewhere else.
    //
    // Checked against the tree rather than trusted: a posted id has to be a
    // branch in this library that says it holds this kind of thing.
    $posted = (int) input_int('category_id', 0);
    if ($posted > 0) {
        $wantRole = input('as') === 'machine' ? 'machine' : 'peripheral';
        $row = one('SELECT c.role, s.slug AS domain FROM categories c
                    JOIN sections s ON s.id = c.section_id WHERE c.id = ?', [$posted]);
        if ($row !== null && (string) $row['domain'] === 'hardware'
            && (string) $row['role'] === $wantRole) {
            return $posted;
        }
    }

    // No model, and it is a machine: the platform's class settles it. Console
    // platforms produce console entries, computers produce computers.
    if (input('as') === 'machine') {
        $platformId = (int) input_int('platform_id', 0);
        if ($platformId > 0) {
            // What the machine models on that platform say it is. The platform
            // used to carry a class of its own, which was the same fact written
            // twice and free to disagree with the models under it.
            $slug = scalar('SELECT slug FROM platforms WHERE id = ?', [$platformId]);
            $kind = $slug === null ? null : (platform_kinds()[(string) $slug] ?? null);
            if ($kind !== null) {
                return (int) $kind['id'];
            }
        }
        // Still nothing: the first machine kind in *this entry's library*, rather than
        // refusing to save over a question nobody was asked.
        //
        // The library matters now. Unscoped, this took the first machine kind on the
        // instance - so an entry could be filed under another library's category, which
        // is a row it has no business pointing at and which vanishes if that library is
        // deleted. And on the platform's own branch where one exists, because a machine
        // filed under "Amiga > Hardware > Computers" is the answer, not "Computers" from
        // whichever tree sorted first.
        $lib = (int) (input_int('library_id') ?? 0);
        if ($lib <= 0) {
            $mine = working_library();
            $lib  = $mine === null ? 0 : (int) $mine['id'];
        }
        $fallback = scalar(
            "SELECT c.id FROM categories c JOIN sections s ON s.id = c.section_id
              WHERE s.slug = 'hardware' AND c.role = 'machine' AND c.library_id = ?
              ORDER BY (c.platform_id <=> ?) DESC, c.depth, c.sort_order, c.id LIMIT 1",
            [$lib, $platformId > 0 ? $platformId : null]
        );
        if ($fallback !== null) {
            return (int) $fallback;
        }
    }

    return $posted;
}

function valid_location_id(): ?int
{
    $id = input_int('location_id');
    if ($id === null || $id <= 0) {
        return null;
    }
    $libraryId = (int) input_int('library_id', 0);
    $row = one('SELECT id FROM locations WHERE id = ? AND library_id = ?', [$id, $libraryId]);
    return $row === null ? null : $id;
}

/**
 * Which environments this copy runs under.
 *
 * Replace, not merge: the ticks on the form are the whole answer, so anything unticked
 * is meant to be gone. Same shape as sync_item_tags() and the fits list.
 *
 * Only environments belonging to this library's platforms are accepted - a posted id
 * from somewhere else is dropped rather than refused, because it can only come from a
 * stale form or a crafted request and neither wants an error page.
 */
function sync_item_environments(int $itemId, ?int $libraryId): void
{
    $want = array_values(array_unique(array_map('intval', (array) ($_POST['item_environments'] ?? []))));

    q('DELETE FROM item_environments WHERE item_id = ?', [$itemId]);
    if ($want === [] || $libraryId === null) {
        return;
    }

    $in = implode(',', array_fill(0, count($want), '?'));
    q("INSERT IGNORE INTO item_environments (item_id, os_id)
       SELECT ?, o.id
         FROM operating_systems o
        WHERE o.id IN ($in) AND o.library_id = ?",
      array_merge([$itemId], $want, [$libraryId]));
}


/**
 * Move a peripheral into a machine, out of one, or leave it where it is.
 *
 * $target is the machine entry chosen on the peripheral's form: an id to install
 * it in, or null for "not installed". Refusals are flashed rather than thrown,
 * because getting this wrong is an ordinary mistake and the rest of the save has
 * already succeeded.
 */
function apply_installed_in(int $peripheralId, ?int $target): void
{
    $host = current_host_machine($peripheralId);
    $now  = $host === null ? null : (int) $host['id'];
    $want = ($target === null || $target <= 0) ? null : $target;

    if ($now === $want) {
        return;
    }

    // Out of wherever it was first, or fit_peripheral() refuses it for being
    // fitted somewhere already - which is true, and unhelpful when the person
    // has just asked to move it.
    if ($now !== null) {
        unfit_peripheral($now, $peripheralId);
    }

    if ($want === null) {
        flash('ok', $host === null ? 'Not installed.' : 'Taken out of ' . $host['title'] . '.');
        return;
    }

    [$ok, $message] = fit_peripheral($want, $peripheralId);
    if (!$ok) {
        // Put it back where it was, so a refused move does not quietly become a
        // removal. Nothing else in the save is rolled back, but this half is the
        // half that would otherwise lose information.
        if ($now !== null) {
            fit_peripheral($now, $peripheralId);
        }
        flash('error', $message);
        return;
    }
    flash('ok', $message);
}

/**
 * Is this provenance section switched on?
 *
 * The hardware form carries a tick per section - Acquire and Sale - so that "I do
 * not know when I got this" and "it has not been sold" can be said rather than
 * left as an ambiguous row of empty boxes. Unticked clears the section.
 *
 * Where the marker is absent the answer is yes, so the software form and the API,
 * which have no such controls, behave exactly as they did.
 */
function provenance_kept(string $section): bool
{
    if (!isset($_POST['provenance_declared'])) {
        return true;
    }
    // Acquire has a tick of its own. Sale does not: it follows the status, because
    // a sale is not a separate fact from having sold something, and two controls for
    // one fact can contradict each other.
    if ($section === 'sale') {
        return (string) input('status', 'owned') === 'sold';
    }
    return input_bool('has_acquire') === 1;
}
