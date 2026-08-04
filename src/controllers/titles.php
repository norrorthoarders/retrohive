<?php
declare(strict_types=1);

/**
 * Titles: the software half of "the thing that exists in the world".
 *
 * The counterpart to /manage/models, which has always done this for hardware.
 * A title is not owned by anybody and is not in a library, so there is no
 * access control on reading one - what is controlled is the *copies*, and every
 * count shown here is filtered to what the viewer can actually see.
 *
 * Editing one needs write access somewhere, on the same reasoning that lets
 * anyone with a shelf add a company name: shared reference data is edited by
 * the people cataloguing against it.
 */

function titles_index(): void
{
    $q          = (string) (input('q') ?? '');
    $platformId = input_int('platform');

    $rows = search_titles($q, $platformId, 200);

    // Only copies the viewer can see are counted, so a shared instance does not
    // tell you how many of something somebody else has.
    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);
    // Which model each was made from. Fetched once and matched in PHP rather than
    // joined per row: search_titles() is shared with the typeahead, and widening its
    // query for a column only this page shows would slow that down too.
    $modelNames = [];
    foreach (all('SELECT id, name FROM software_models') as $m) {
        $modelNames[(int) $m['id']] = (string) $m['name'];
    }

    foreach ($rows as $i => $row) {
        $rows[$i]['model_name'] = $modelNames[(int) ($row['software_model_id'] ?? 0)] ?? null;
        $rows[$i]['visible_copies'] = (int) scalar(
            "SELECT COUNT(*) FROM v_items WHERE title_id = ? AND $acl",
            array_merge([(int) $row['id']], $aclP)
        );
    }

    render('titles/index', [
        'pageTitle' => 'Titles',
        'rows'      => $rows,
        'platforms' => all_platforms(),
        'q'         => $q,
        'platform'  => $platformId,
    ]);
}

function titles_show(int $id): void
{
    $title = find_title($id);
    if ($title === null) {
        not_found('No such title on file.');
    }

    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);

    render('titles/show', [
        'pageTitle' => (string) $title['name'],
        'title'     => $title,
        // Your copies of it. Two rows here is the normal, intended state: they
        // differ in condition, completeness, what is missing from the box.
        'copies'    => all(
            "SELECT * FROM v_items WHERE title_id = ? AND $acl ORDER BY status, condition_grade, id",
            array_merge([$id], $aclP)
        ),
        // The same game on other machines.
        'siblings'  => title_siblings((string) $title['work_key'], $id),
    ]);
}

function titles_form(?int $id): void
{
    $title = $id === null ? null : find_title($id);
    if ($id !== null && $title === null) {
        not_found('No such title on file.');
    }

    render('titles/form', [
        'pageTitle'  => $title === null ? 'New title' : 'Edit ' . $title['name'],
        'title'      => $title,
        'platforms'  => all_platforms(),
        'categories' => filing_options('software'),
        'companies'  => all_companies('software'),
        // The templates a title can be made from, and what the chosen one says. A title
        // with no model is still a perfectly good title; this only saves the typing.
        'swModels'   => software_models(),
        'modelContents' => (function () use ($title) {
            $mid = (int) ($title['software_model_id'] ?? input_int('software_model_id') ?? 0);
            return $mid > 0 ? software_model_contents($mid) : [];
        })(),
        // Releases already on file, so this one can be linked to the same work rather
        // than relying on the two being spelled identically.
        'works'      => all(
            'SELECT t.id, t.name, t.work_key, p.name AS platform_name, t.release_year
               FROM titles t JOIN platforms p ON p.id = t.platform_id
              WHERE t.id <> ? ORDER BY t.name, p.name LIMIT 500',
            [(int) ($id ?? 0)]
        ),
        // What it is currently linked to.
        'siblings'   => $title === null ? [] : title_siblings((string) $title['work_key'], (int) $title['id']),
        'contents'   => $title === null ? [] : title_contents((int) $title['id']),
    ]);
}

function titles_store(): void
{
    require_edit();
    csrf_verify();

    [$id, $errors] = save_title(null, titles_payload());
    if ($errors === [] && $id) {
        // Only when the form actually carried the section. Without this guard a save
        // from anywhere else - the API, or a create that inherited its contents from a
        // model seconds earlier - deleted a list it never showed.
        if (isset($_POST['content_label'])) {
        set_title_contents((int) $id, (array) ($_POST['content_label'] ?? []),
                           (array) ($_POST['content_note'] ?? []));
        }
    }
    if ($errors !== []) {
        foreach ($errors as $err) {
            flash('error', $err);
        }
        redirect('/titles/new');
    }

    flash('ok', 'Title added.');
    redirect('/titles/' . (int) $id);
}

function titles_update(int $id): void
{
    require_edit();
    csrf_verify();

    if (find_title($id) === null) {
        not_found('No such title on file.');
    }

    if (input('action') === 'delete') {
        // Copies keep working: items.title_id is ON DELETE SET NULL, so the
        // entries fall back to their own columns rather than vanishing.
        $copies = (int) scalar('SELECT COUNT(*) FROM items WHERE title_id = ?', [$id]);
        delete_row('titles', $id);
        flash('ok', $copies === 0
            ? 'Title removed.'
            : "Title removed. $copies " . ($copies === 1 ? 'entry keeps' : 'entries keep')
              . ' its own details.');
        redirect('/titles');
    }

    [, $errors] = save_title($id, titles_payload());
    if ($errors === []) {
        set_title_contents($id, (array) ($_POST['content_label'] ?? []),
                           (array) ($_POST['content_note'] ?? []));
    }
    if ($errors !== []) {
        foreach ($errors as $err) {
            flash('error', $err);
        }
        redirect('/titles/' . $id . '/edit');
    }

    flash('ok', 'Title saved.');
    redirect('/titles/' . $id);
}

function titles_payload(): array
{
    return [
        'name'         => (string) input('name', ''),
        'subtitle'     => input('subtitle'),
        'sort_name'    => input('sort_name'),
        'platform_id'  => (int) input_int('platform_id', 0),
        'category_id'  => input_int('category_id'),
        'developer'    => input('developer_name'),
        'publisher'    => input('publisher_name'),
        'release_year' => input_int('release_year'),
        'release_date' => input('release_date'),
        'language'     => input('language'),
        'region'       => input('region'),
        'external_url' => input('external_url'),
        'synopsis'     => $_POST['synopsis'] ?? null,
        // Which work this is a release of: another title to join, or a key typed by
        // hand. Absent means keep deriving it from the name.
        'same_work_as' => input_int('same_work_as'),
        'software_model_id' => input_int('software_model_id'),
        'work_key'     => input('work_key'),
    ];
}

/**
 * JSON for the type-ahead on the entry form.
 *
 * Deliberately a page rather than an API route: it is authenticated by the web
 * session like everything else on the form, and putting it under /api/v1 would
 * mean a browser fetch needing a bearer token it does not have.
 */
function titles_search(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $rows = search_titles((string) (input('q') ?? ''), input_int('platform_id'), 20);

    echo json_encode(array_map(fn($r) => [
        'id'        => (int) $r['id'],
        'name'      => $r['name'],
        'subtitle'  => $r['subtitle'],
        'year'      => $r['release_year'] === null ? null : (int) $r['release_year'],
        'platform'  => $r['platform_name'],
        'developer' => $r['developer_name'],
        'copies'    => (int) $r['copy_count'],
    ], $rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
