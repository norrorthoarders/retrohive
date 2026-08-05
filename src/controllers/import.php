<?php
declare(strict_types=1);

/**
 * CSV import.
 *
 * There has been an export since the beginning and no way back in, which makes
 * the round trip - export, fix a hundred rows in a spreadsheet, put them back -
 * impossible, and makes getting an existing collection *into* the catalogue a
 * typing job. That was the thing standing between this and being usable with a
 * real collection in it.
 *
 * Two rules shape the whole file:
 *
 *   1. Nothing is written until the whole file has been read and understood.
 *      An import that fails on row 400 having already written 399 is worse than
 *      one that fails on row 1, because now you do not know what state you are
 *      in. Everything runs inside a transaction and a dry run is the default.
 *
 *   2. A row carrying an ID updates that entry; a row without one creates.
 *      That is what makes an exported file editable and re-importable rather
 *      than a way to duplicate your collection.
 */

const IMPORT_MAX_ROWS = 20000;

function import_index(): void
{
    require_edit();
    render('items/import', [
        'pageTitle' => 'Import CSV',
        'libraries' => readable_libraries(ACCESS_CONTRIBUTOR),
        'report'    => $_SESSION['import_report'] ?? null,
        'columns'   => csv_columns(),
    ]);
    unset($_SESSION['import_report']);
}

function import_run(): void
{
    require_edit();
    csrf_verify();

    $libraryId = (int) input_int('library_id', 0);
    // Default library for rows that do not name one. Checked up front, because
    // discovering it on row 900 is not a useful place to find out.
    if ($libraryId <= 0 || !can_add_to_library($libraryId)) {
        flash('error', 'Choose a library you can write to.');
        redirect('/import');
    }

    $file = $_FILES['csv'] ?? null;
    if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('error', 'Choose a CSV file to upload.');
        redirect('/import');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        flash('error', 'That was not an upload.');
        redirect('/import');
    }

    $dryRun       = input('commit') !== '1';
    $createTitles = input_bool('create_titles') === 1;

    $report = import_parse((string) $file['tmp_name'], $libraryId, $createTitles);

    if ($report['fatal'] !== null) {
        flash('error', $report['fatal']);
        redirect('/import');
    }

    if (!$dryRun && $report['errors'] === []) {
        import_commit($report);
        flash('ok', sprintf(
            'Imported %d new %s and updated %d.',
            $report['create_count'],
            $report['create_count'] === 1 ? 'entry' : 'entries',
            $report['update_count']
        ));
    } elseif (!$dryRun) {
        flash('error', 'Nothing was written: fix the rows listed below and try again.');
    }

    $_SESSION['import_report'] = $report;
    redirect('/import');
}

/**
 * Read the file and work out what it would do. Writes nothing.
 *
 * Returns a report the template renders and import_commit() can act on, so what
 * you are shown in the dry run is literally what will be applied.
 */
function import_parse(string $path, int $defaultLibraryId, bool $createTitles): array
{
    $report = [
        'fatal'        => null,
        'errors'       => [],
        'warnings'     => [],
        'rows'         => [],
        'create_count' => 0,
        'update_count' => 0,
        'library_id'   => $defaultLibraryId,
        'create_titles' => $createTitles,
    ];

    $fh = fopen($path, 'r');
    if ($fh === false) {
        $report['fatal'] = 'The uploaded file could not be read.';
        return $report;
    }

    $header = fgetcsv($fh);
    if ($header === false || $header === [null]) {
        fclose($fh);
        $report['fatal'] = 'That file has no header row.';
        return $report;
    }

    // Excel writes a BOM; left in place it makes the first header 'ï»¿ID',
    // which then matches nothing and every row looks like it has no id.
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? $header[0];
    }

    $columns = csv_columns();
    $lookup  = [];
    foreach ($header as $i => $name) {
        $key = strtolower(trim((string) $name));
        foreach ($columns as $label => $field) {
            if (strtolower($label) === $key) {
                $lookup[$field] = $i;
            }
        }
    }

    if (!isset($lookup['title'])) {
        fclose($fh);
        $report['fatal'] = 'No "Title" column found. Export a file first to see the expected headers.';
        return $report;
    }

    // Resolved once rather than per row: a 5000-row file otherwise spends its
    // time asking the database the same question about the same platform.
    $cache = [
        'library'  => import_slug_map('libraries'),
        'platform' => import_slug_map('platforms'),
        'category' => import_slug_map('categories'),
    ];

    $line = 1;
    while (($row = fgetcsv($fh)) !== false) {
        $line++;
        if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
            continue;   // blank line
        }
        if (count($report['rows']) >= IMPORT_MAX_ROWS) {
            $report['fatal'] = 'That file has more than ' . IMPORT_MAX_ROWS
                             . ' rows. Split it and import in parts.';
            fclose($fh);
            return $report;
        }

        $get = function (string $field) use ($row, $lookup): ?string {
            if (!isset($lookup[$field])) {
                return null;
            }
            $v = $row[$lookup[$field]] ?? null;
            if ($v === null) {
                return null;
            }
            $v = trim((string) $v);
            // Undo the leading apostrophe csv_safe() added on the way out.
            if ($v !== '' && $v[0] === "'") {
                $v = substr($v, 1);
            }
            return $v === '' ? null : $v;
        };

        [$parsed, $rowErrors] = import_row($get, $line, $cache, $defaultLibraryId, $createTitles);

        if ($rowErrors !== []) {
            foreach ($rowErrors as $err) {
                $report['errors'][] = "Row $line: $err";
            }
            continue;
        }

        $report['rows'][] = $parsed;
        if ($parsed['mode'] === 'create') {
            $report['create_count']++;
        } else {
            $report['update_count']++;
        }
        foreach ($parsed['warnings'] as $w) {
            $report['warnings'][] = "Row $line: $w";
        }
    }
    fclose($fh);

    if ($report['rows'] === [] && $report['errors'] === []) {
        $report['fatal'] = 'That file has a header but no data rows.';
    }

    // A long list of identical complaints helps nobody read the first one.
    if (count($report['errors']) > 100) {
        $extra = count($report['errors']) - 100;
        $report['errors'] = array_slice($report['errors'], 0, 100);
        $report['errors'][] = "... and $extra more.";
    }

    return $report;
}

/** name-or-slug => id, for resolving a text column to a foreign key. */
function import_slug_map(string $table): array
{
    $map = [];
    foreach (all("SELECT id, name, slug FROM `$table`") as $row) {
        $map[mb_strtolower((string) $row['name'])] = (int) $row['id'];
        $map[mb_strtolower((string) $row['slug'])] = (int) $row['id'];
    }
    return $map;
}

/** Turn one CSV line into item columns. Returns [parsed, errors]. */
function import_row(callable $get, int $line, array $cache, int $defaultLibraryId, bool $createTitles): array
{
    $errors   = [];
    $warnings = [];

    $title = $get('title');
    if ($title === null) {
        return [null, ['no title.']];
    }

    // An ID means "this is that entry"; no ID means a new one.
    $id   = $get('id');
    $mode = 'create';
    $existing = null;
    if ($id !== null && ctype_digit($id)) {
        $existing = one('SELECT * FROM items WHERE id = ? AND deleted_at IS NULL', [(int) $id]);
        if ($existing === null) {
            $warnings[] = "no entry with id $id, so it will be created instead.";
        } else {
            if (!can_write_item($existing)) {
                return [null, ["entry $id is in a library you cannot change."]];
            }
            $mode = 'update';
        }
    }

    // Library
    $libraryId = $defaultLibraryId;
    $libName   = $get('library_name');
    if ($libName !== null) {
        $found = $cache['library'][mb_strtolower($libName)] ?? null;
        if ($found === null) {
            $errors[] = "no library called \"$libName\".";
        } else {
            $libraryId = $found;
        }
    } elseif ($existing !== null) {
        $libraryId = (int) $existing['library_id'];
    }
    if ($errors === [] && !can_add_to_library($libraryId)) {
        $errors[] = 'you do not have write access to that library.';
    }

    // Platform and category are required for a new entry; an update keeps
    // whatever it already had.
    $platformId = import_resolve($cache['platform'], $get('platform_name'));
    $categoryId = import_resolve($cache['category'], $get('category_name'));

    if ($mode === 'update') {
        $platformId ??= (int) $existing['platform_id'];
        $categoryId ??= (int) $existing['category_id'];
    }
    if ($platformId === null) {
        $errors[] = 'no platform given, and no existing entry to take one from.';
    }
    if ($categoryId === null) {
        $errors[] = 'no type given, and no existing entry to take one from.';
    }

    if ($errors !== []) {
        return [null, $errors];
    }

    $data = [
        'library_id'  => $libraryId,
        'platform_id' => $platformId,
        'category_id' => $categoryId,
        'title'       => mb_substr($title, 0, 220),
    ];

    foreach (['subtitle' => 220, 'sort_title' => 220, 'media_type' => 60, 'catalog_number' => 80,
              'barcode' => 40, 'language' => 80, 'region' => 80,
              'external_url' => 500] as $field => $max) {
        $v = $get($field);
        if ($v !== null) {
            $data[$field] = mb_substr($v, 0, $max);
        }
    }
    $notes = $get('notes');
    if ($notes !== null) {
        $data['notes'] = $notes;
    }

    foreach (['developer' => 'developer_id', 'publisher' => 'publisher_id'] as $role => $column) {
        $name = $get($role . '_name');
        if ($name !== null) {
            $data[$column] = company_id_for_name($name);
        }
    }

    // Numbers
    foreach (['release_year', 'rating', 'media_count', 'copies'] as $field) {
        $v = $get($field);
        if ($v !== null) {
            if (!is_numeric($v)) {
                $errors[] = "$field is not a number.";
            } else {
                $data[$field] = (int) $v;
            }
        }
    }
    if (isset($data['rating']) && ($data['rating'] < 1 || $data['rating'] > 10)) {
        $errors[] = 'rating runs from 1 to 10.';
    }
    if (isset($data['release_year'])
        && ($data['release_year'] < 1950 || $data['release_year'] > (int) date('Y') + 1)) {
        $errors[] = 'release year looks wrong.';
    }

    // Money. A spreadsheet will happily hand back "1 299,50".
    foreach (['acquired_price', 'current_value', 'sold_price'] as $field) {
        $v = $get($field);
        if ($v !== null) {
            $clean = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $v);
            if (!is_numeric($clean)) {
                $errors[] = "$field is not a number.";
            } else {
                $data[$field] = (float) $clean;
            }
        }
    }
    $currency = $get('currency');
    if ($currency !== null) {
        $data['currency'] = mb_substr($currency, 0, 3);
    }

    // Dates
    foreach (['release_date', 'acquired_on', 'sold_on'] as $field) {
        $v = $get($field);
        if ($v !== null) {
            $iso = import_date($v);
            if ($iso === null) {
                $errors[] = "$field is not a date I can read (use YYYY-MM-DD).";
            } else {
                $data[$field] = $iso;
            }
        }
    }

    // Labelled enums, accepted as either the label or the stored value.
    $enums = [
        'condition_grade'  => ['options' => condition_options(),           'label' => 'condition_label'],
        'completeness'     => ['options' => completeness_options(),        'label' => 'completeness_label'],
        'status'           => ['options' => status_options(),              'label' => 'status_label'],
        'condition_box'    => ['options' => component_condition_options(), 'label' => 'condition_label'],
        'condition_manual' => ['options' => component_condition_options(), 'label' => 'condition_label'],
        'condition_media'  => ['options' => component_condition_options(), 'label' => 'condition_label'],
    ];
    foreach ($enums as $field => $spec) {
        $v = $get($field);
        if ($v === null) {
            continue;
        }
        $resolved = import_enum($v, $spec['options'], $spec['label']);
        if ($resolved === null) {
            $errors[] = "\"$v\" is not a value $field accepts.";
        } else {
            $data[$field] = $resolved;
        }
    }

    $original = $get('is_original');
    if ($original !== null) {
        $data['is_original'] = in_array(mb_strtolower($original), ['yes', 'y', '1', 'true', 'ja'], true) ? 1 : 0;
    }

    if ($errors !== []) {
        return [null, $errors];
    }

    // Titles. Only when asked for: creating one per row silently would double
    // the size of the titles table on a first import of a messy file.
    $titleId = null;
    if ($createTitles) {
        $titleId = title_id_for($title, (int) $platformId, $data['release_year'] ?? null, [
            'category_id' => $categoryId,
            'developer'   => $get('developer_name'),
            'publisher'   => $get('publisher_name'),
        ]);
        if ($titleId !== null) {
            $data['title_id'] = $titleId;
        }
    }

    // A copy you may already have. Not an error - two copies in different
    // condition is the normal case and the reason items and titles are
    // separate - but worth saying out loud before it happens by accident.
    if ($mode === 'create') {
        $dupes = existing_copies($titleId, $data['barcode'] ?? null, $title, (int) $platformId);
        if ($dupes !== []) {
            $warnings[] = sprintf(
                '"%s" already has %d cop%s on the shelf; this adds another.',
                $title, count($dupes), count($dupes) === 1 ? 'y' : 'ies'
            );
        }
    }

    return [[
        'mode'     => $mode,
        'id'       => $existing === null ? null : (int) $existing['id'],
        'existing' => $existing,
        'data'     => $data,
        'title'    => $title,
        'warnings' => $warnings,
    ], []];
}

function import_resolve(array $map, ?string $value): ?int
{
    if ($value === null) {
        return null;
    }
    return $map[mb_strtolower($value)] ?? null;
}

/** Accept the label shown in the export, or the value stored in the column. */
function import_enum(string $value, array $options, string $labeller): ?string
{
    $needle = mb_strtolower(trim($value));
    foreach ($options as $option) {
        if (mb_strtolower($option) === $needle) {
            return $option;
        }
        if (mb_strtolower($labeller($option)) === $needle) {
            return $option;
        }
    }
    return null;
}

/**
 * Dates, as a spreadsheet is likely to have mangled them.
 *
 * Excel writes whatever the machine's locale says, so an export edited on a
 * Swedish desktop comes back as 2019-04-03 and one edited elsewhere as
 * 03/04/2019. Guessing between the last two is impossible, so the ambiguous
 * form is refused rather than silently filed six months out.
 */
function import_date(string $value): ?string
{
    $value = trim($value);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }
    // 2019/04/03 and 2019.04.03 are unambiguous too.
    if (preg_match('#^(\d{4})[./](\d{1,2})[./](\d{1,2})$#', $value, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
    }
    // A bare year is a reasonable thing to have typed.
    if (preg_match('/^\d{4}$/', $value)) {
        return $value . '-01-01';
    }
    return null;
}

/**
 * Apply a parsed report. One transaction: either the whole file lands or none
 * of it does, so a failure halfway leaves nothing to reconcile by hand.
 */
function import_commit(array $report): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($report['rows'] as $row) {
            if ($row['mode'] === 'update') {
                record_value_change((int) $row['id'], $row['existing'], $row['data']);
                update_row('items', (int) $row['id'], $row['data']);
            } else {
                // acting_user(), not current_user(): this same function is
                // about to be called from a token-authenticated API request
                // as well as the session-based web form, and current_user()
                // only ever checks the session - every imported item would
                // have silently recorded no creator at all from an API
                // caller, the same class of bug is_admin() vs
                // is_admin_user(acting_user()) already turned out to be
                // earlier this session.
                $user = acting_user();
                $data = $row['data'] + ['currency' => config('currency')];
                $data['created_by'] = $user === null ? null : (int) $user['id'];
                $newId = insert_row('items', $data);
                record_acquisition_event((int) $newId, $data);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[retrohive] import failed, rolled back: ' . $e->getMessage());
        throw $e;
    }
}
