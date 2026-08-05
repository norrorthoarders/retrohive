<?php
declare(strict_types=1);

/**
 * REST API, version 1. Every route lives under /api/v1.
 *
 * Conventions:
 *   - Success returns {"data": ...} and, for collections, {"meta": {...}}
 *   - Failure returns {"error": {"code": "...", "message": "...", "details": {}}}
 *   - Writes require write access to the library in question, and a
 *     write-scoped token. The instance role does not enter into it.
 */

// --- Meta and authentication -----------------------------------------------

function api_meta(): void
{
    api_ok([
        'name'            => config('app_name'),
        // The server's own version, which is not the API's and not any client's.
        // A bug report that says "0.5" without saying which 0.5 is half a report.
        'app_version'     => APP_VERSION,
        'api_version'     => API_VERSION,
        'currency'        => config('currency'),
        'timezone'        => config('timezone'),
        'server_time'     => gmdate('Y-m-d\TH:i:s\Z'),
        'authenticated'   => api_identify() !== null,
        'max_upload_bytes' => (int) config('uploads.max_bytes'),
        'image_kinds'     => array_map(
            fn($k) => ['value' => $k, 'label' => image_kind_label($k)],
            image_kind_options()
        ),
        'conditions' => array_map(
            fn($k) => ['value' => $k, 'label' => condition_label($k)],
            condition_options()
        ),
        'completeness' => array_map(
            fn($k) => ['value' => $k, 'label' => completeness_label($k)],
            completeness_options()
        ),
        'component_conditions' => array_map(
            fn($k) => ['value' => $k, 'label' => condition_label($k)],
            component_condition_options()
        ),
        'statuses' => array_map(
            fn($k) => ['value' => $k, 'label' => status_label($k)],
            status_options()
        ),
    ]);
}

/**
 * Exchange a username and password for a long-lived token.
 * This is what a native client calls on its sign-in screen.
 */
function api_login(): void
{
    $in = api_body();
    $username = trim((string) ($in['username'] ?? ''));
    $password = (string) ($in['password'] ?? '');
    $device   = trim((string) ($in['device_name'] ?? 'API client'));
    $platform = trim((string) ($in['platform'] ?? ''));
    $scope    = ($in['scope'] ?? 'write') === 'read' ? 'read' : 'write';

    if ($username === '' || $password === '') {
        api_error('validation_failed', 'Both username and password are required.', 422);
    }

    // The same limit as the web form. Without this the API would be a way
    // straight past it.
    [$allowed, $wait, $why] = throttle_check($username);
    if (!$allowed) {
        log_auth_attempt($username, null, false, 'throttled: ' . (string) $why);
        header('Retry-After: ' . $wait);
        api_error('rate_limited', throttle_message($wait), 429);
    }

    // Same resolution as the web sign-in: local password, or whichever
    // directory owns the account. Directory users have no password_hash at all,
    // so this must never call password_verify() directly.
    $row = verify_credentials($username, $password);
    if ($row === null) {
        usleep(random_int(150000, 400000)); // blunt the edge off online guessing
        api_error('invalid_credentials', 'That username and password do not match.', 401);
    }

    // An account with nothing it may change can never hold a write token,
    // whatever it asked for. That is a membership question, not a role one.
    set_acting_user($row);
    if (!can_edit_anything()) {
        $scope = 'read';
    }

    $days = (int) config('api.token_days', 0);
    $expires = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;

    [$tokenId, $plain] = create_api_token(
        (int) $row['id'],
        $device !== '' ? $device : 'API client',
        $scope,
        $platform !== '' ? mb_substr($platform, 0, 40) : null,
        $expires
    );

    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [(int) $row['id']]);

    // Not log_auth_attempt(): verify_credentials() already writes auth_log for
    // both outcomes, and a second call there was two rows for one sign-in.
    //
    // This is the line that was genuinely missing - auth_log is its own table
    // and its own screen, while the log page shows `logs`, where the API had
    // never written anything at all. A device now holds a credential for this
    // account, named, so "which phone was that" has an answer.
    log_security('api.token.issued',
                 sprintf('Token issued to "%s" for %s, %s access',
                         $device !== '' ? $device : 'API client',
                         $username, $scope),
                 LOG_NOTICE, ['subject_type' => 'user', 'subject_id' => (int) $row['id']]);

    $user = one('SELECT id, username, display_name, role, is_active FROM users WHERE id = ?', [(int) $row['id']]);

    api_ok([
        'token'      => $plain,
        'token_id'   => $tokenId,
        'token_type' => 'Bearer',
        'scope'      => $scope,
        'expires_at' => $expires === null ? null : api_datetime($expires),
        'user'       => user_to_api($user),
    ], null, 201);
}

/** Revoke the token used to make this call. */
function api_logout(): void
{
    [, $token] = api_require_auth();
    if ($token === null) {
        api_error('not_applicable', 'This call was authenticated by a web session, not a token.', 400);
    }
    q('UPDATE api_tokens SET revoked_at = NOW() WHERE id = ?', [(int) $token['id']]);
    api_no_content();
}

function api_me(): void
{
    [$user, $token] = api_require_auth();
    api_ok([
        'user'  => user_to_api($user),
        'token' => $token === null ? null : [
            'id'           => (int) $token['id'],
            'name'         => $token['name'],
            'scope'        => $token['scope'],
            'platform'     => $token['platform'],
            'expires_at'   => api_datetime($token['expires_at']),
            'last_used_at' => api_datetime($token['last_used_at']),
        ],
    ]);
}

// --- Token management -------------------------------------------------------

function api_tokens_index(): void
{
    [$user] = api_require_auth();
    $rows = all(
        'SELECT id, name, prefix, scope, platform, last_used_at, last_used_ip, expires_at, revoked_at, created_at
         FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC',
        [(int) $user['id']]
    );
    api_ok(array_map(fn($t) => [
        'id'           => (int) $t['id'],
        'name'         => $t['name'],
        'prefix'       => $t['prefix'],
        'scope'        => $t['scope'],
        'platform'     => $t['platform'],
        'last_used_at' => api_datetime($t['last_used_at']),
        'last_used_ip' => $t['last_used_ip'],
        'expires_at'   => api_datetime($t['expires_at']),
        'revoked_at'   => api_datetime($t['revoked_at']),
        'created_at'   => api_datetime($t['created_at']),
    ], $rows));
}

function api_tokens_create(): void
{
    [$user] = api_require_auth();
    $in = api_body();
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        api_error('validation_failed', 'Give the token a name so you can recognise the device later.', 422);
    }
    $scope = ($in['scope'] ?? 'write') === 'read' ? 'read' : 'write';
    if ($scope === 'write' && !can_edit_anything()) {
        $scope = 'read';
    }
    [$id, $plain] = create_api_token(
        (int) $user['id'],
        $name,
        $scope,
        isset($in['platform']) ? mb_substr((string) $in['platform'], 0, 40) : null
    );
    api_ok([
        'id'    => $id,
        'name'  => $name,
        'scope' => $scope,
        'token' => $plain,
        'note'  => 'Store this now. It is not recoverable.',
    ], null, 201);
}

function api_tokens_revoke(int $id): void
{
    [$user] = api_require_auth();
    $token = one('SELECT id, user_id FROM api_tokens WHERE id = ?', [$id]);
    if ($token === null) {
        api_error('not_found', 'No such token.', 404);
    }
    if ((int) $token['user_id'] !== (int) $user['id'] && $user['role'] !== 'admin') {
        api_error('forbidden', 'That token belongs to another account.', 403);
    }
    q('UPDATE api_tokens SET revoked_at = NOW() WHERE id = ?', [$id]);
    api_no_content();
}

// --- Items ------------------------------------------------------------------

function api_items_index(): void
{
    api_require_auth();

    $perPage = max(1, min(200, api_query_int('per_page', (int) config('per_page')) ?? 24));
    $page    = max(1, api_query_int('page', 1) ?? 1);
    $sort    = isset($_GET['sort']) && is_string($_GET['sort']) ? $_GET['sort'] : 'title';

    [$where, $params] = build_item_filters($_GET);
    $order = item_sort_clause($sort);

    $total  = (int) scalar("SELECT COUNT(*) FROM v_items WHERE $where", $params);
    $pages  = max(1, (int) ceil($total / $perPage));
    $offset = ($page - 1) * $perPage;

    $rows = all("SELECT * FROM v_items WHERE $where ORDER BY $order LIMIT $perPage OFFSET $offset", $params);

    // Cheap validator so clients can skip re-downloading an unchanged page.
    $etag = '"' . md5(implode('|', array_map(
        fn($r) => $r['id'] . ':' . $r['updated_at'],
        $rows
    )) . "|$total|$page|$perPage") . '"';

    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);
        exit;
    }

    $withImages = ($_GET['include'] ?? '') === 'images';

    api_ok(
        array_map(fn($r) => item_to_api($r, $withImages), $rows),
        [
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
            'pages'    => $pages,
            'has_more' => $page < $pages,
        ],
        200,
        ['ETag' => $etag, 'X-Total-Count' => (string) $total]
    );
}

function api_items_show(int $id): void
{
    api_require_auth();
    $item = find_item($id);
    if ($item === null || !can_read_item($item)) {
        api_error('not_found', 'No catalogue entry with that id.', 404);
    }
    $etag = '"' . md5($item['id'] . ':' . $item['updated_at'] . ':' . $item['image_count']) . '"';
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);
        exit;
    }
    api_ok(item_to_api($item, true), null, 200, ['ETag' => $etag]);
}

/**
 * Map an incoming JSON object onto item columns.
 * In partial mode only the supplied keys are touched, which is what PATCH needs.
 */
function api_item_input(array $in, bool $partial, ?array $existing = null): array
{
    $data   = [];
    $errors = [];
    $has    = fn(string $k) => array_key_exists($k, $in);

    $strings = [
        'title' => 220, 'subtitle' => 220, 'sort_title' => 220, 'media_type' => 60,
        'catalog_number' => 80, 'barcode' => 40, 'language' => 80, 'region' => 80,
        'external_url' => 500, 'notes' => 65535,
        // The release's own blurb. Writable, because a client that can set the
        // notes and not this one would have to put a description in the notes -
        // which is the confusion migration 0014 exists to end.
        'description' => 65535,
        // Provenance. The web has written these since it existed and the API
        // never accepted one of them, so an entry created from a phone could
        // record what it cost and not who it came from.
        'acquired_from' => 140, 'acquired_note' => 255,
        'sold_to' => 140, 'sold_note' => 255,
        'location_position' => 40,
    ];
    foreach ($strings as $key => $max) {
        if ($has($key)) {
            $v = $in[$key];
            if ($v !== null && !is_scalar($v)) {
                $errors[$key] = 'Must be a string.';
                continue;
            }
            $v = $v === null ? null : mb_substr(trim((string) $v), 0, $max);
            $data[$key] = ($v === '') ? null : $v;
        }
    }

    // Condition, and the box it did or did not come in.
    //
    // Three fields that only make sense together: a grade for the thing, whether
    // there is a box, and a grade for the box. Grading a box that is not there is
    // meaningless, so clearing has_box clears the box grade with it - the same
    // rule the web form applies, in one place rather than two.
    // Not condition_grade: `condition` already carries it, validated, a few
    // lines below. Two names for one field is two things to keep in step.
    foreach (['condition_box', 'condition_manual', 'condition_media'] as $key) {
        if (!$has($key)) {
            continue;
        }
        $grade = rule_component_grade($in[$key]);
        if ($grade === null) {
            $errors[$key] = 'Not a known grade.';
            continue;
        }
        $data[$key] = $grade;
    }

    // The box rule from src/rules.php, not a second copy of it. The form applies
    // the same one; a client and a person filling in the web form should not be
    // able to leave the catalogue in two different states from the same answer.
    if ($has('has_box')) {
        $box = rule_box_state((bool) $in['has_box'],
                              $data['condition_box'] ?? ($in['condition_box'] ?? 'unknown'));
        $data['has_box']       = $box['has_box'];
        $data['condition_box'] = $box['condition_box'];
    }

    // A maker or publisher by name, made if this library has not got one.
    //
    // `developer_id` remains the way to point at a company that exists; these
    // are for a client holding a name from a metadata source and no id.
    foreach (['developer' => 'developer_id', 'publisher' => 'publisher_id'] as $key => $col) {
        if (!$has($key) || $has($col)) {
            continue;
        }
        $libraryForCompany = (int) ($data['library_id'] ?? ($existing['library_id'] ?? 0));
        if ($libraryForCompany <= 0) {
            $errors[$key] = 'Send library_id too, or a developer_id.';
            continue;
        }
        $data[$col] = $in[$key] === null
            ? null
            : api_company_for_name($libraryForCompany, (string) $in[$key]);
    }

    // Which model this is one of. Nullable on purpose: an entry whose model was
    // guessed wrongly needs a way back to none.
    //
    // Only model_id. software_model_id belongs to the canonical title, not to a
    // copy of it - one person's cartridge does not decide what the release is.
    if ($has('model_id')) {
        $data['model_id'] = $in['model_id'] === null ? null : (int) $in['model_id'];
    }

    // A currency of its own for the sale, because bought in SEK and sold in EUR
    // is ordinary.
    if ($has('sold_currency')) {
        $code = strtoupper(trim((string) $in['sold_currency']));
        $data['sold_currency'] = $code === '' ? null : mb_substr($code, 0, 3);
    }

    // Where it is kept, by path.
    //
    // The API has never handled a location at all - not by id, not by path - so
    // "Where it is kept" on the phone was typed, sent, and silently dropped.
    // The path is the right shape for a client: "Retroway 22 › Basement › Book
    // Shelf 1" is what somebody knows, and an id is what the server knows.
    if ($has('location_path')) {
        $path = trim((string) ($in['location_path'] ?? ''));
        if ($path === '') {
            $data['location_id'] = null;
        } else {
            // Matched on the breadcrumb, not on `locations.path`.
            //
            // That column looks like the answer and is not: it holds an id path
            // - `/1/7/` - for subtree queries, while a client sends what a
            // person reads, "Retroway 22 › Basement › Book Shelf 1". Comparing
            // against it would have matched nothing a phone ever sends, and the
            // field would have gone on being silently dropped with a test
            // saying it was handled.
            //
            // Scoped to the entry's library, because two libraries may both
            // have a Basement. Case-insensitive and separator-tolerant, since
            // the breadcrumb is typed by hand as often as it is copied.
            $libraryForPath = (int) ($data['library_id'] ?? ($existing['library_id'] ?? 0));
            $wanted = preg_replace('/\s*[›>\/]\s*/u', ' › ', $path);
            $id = null;
            foreach (all('SELECT id FROM locations WHERE library_id = ?',
                         [$libraryForPath]) as $candidate) {
                if (strcasecmp(location_breadcrumb((int) $candidate['id']), (string) $wanted) === 0) {
                    $id = (int) $candidate['id'];
                    break;
                }
            }
            if ($id === null) {
                $errors['location_path'] = 'No location with that path.';
            } else {
                $data['location_id'] = $id;
            }
        }
    }

    // The library owns the entry and decides who may see it.
    if ($has('library_id')) {
        $data['library_id'] = (int) $in['library_id'];
        if (one('SELECT id FROM libraries WHERE id = ?', [$data['library_id']]) === null) {
            $errors['library_id'] = 'No library with that id.';
        }
    }
    if ($has('platform_id')) {
        $data['platform_id'] = (int) $in['platform_id'];
        if (one('SELECT id FROM platforms WHERE id = ?', [$data['platform_id']]) === null) {
            $errors['platform_id'] = 'No platform with that id.';
        }
    }
    // Point at a canonical title, and inherit anything the caller did not
    // state. Two copies of one game should not mean sending its metadata
    // twice, and an import running twice should not produce two of it.
    if ($has('title_id')) {
        $data['title_id'] = $in['title_id'] === null ? null : (int) $in['title_id'];
        if ($data['title_id'] !== null) {
            $title = one('SELECT * FROM titles WHERE id = ?', [$data['title_id']]);
            if ($title === null) {
                $errors['title_id'] = 'No title with that id.';
            } else {
                $data += title_defaults_for_item($title, $data);
            }
        }
    }
    if ($has('category_id')) {
        $data['category_id'] = (int) $in['category_id'];
        if (one('SELECT id FROM categories WHERE id = ?', [$data['category_id']]) === null) {
            $errors['category_id'] = 'No software type with that id.';
        }
    }

    // Companies accept either an id or a plain name; a new name is created.
    foreach (['developer', 'publisher'] as $role) {
        if ($has($role . '_id')) {
            $data[$role . '_id'] = $in[$role . '_id'] === null ? null : (int) $in[$role . '_id'];
            if ($data[$role . '_id'] !== null && one('SELECT id FROM companies WHERE id = ?', [$data[$role . '_id']]) === null) {
                $errors[$role . '_id'] = 'No company with that id.';
            }
        } elseif ($has($role . '_name')) {
            $data[$role . '_id'] = company_id_for_name(
                $in[$role . '_name'] === null ? null : (string) $in[$role . '_name']
            );
        }
    }

    if ($has('release_year')) {
        $data['release_year'] = $in['release_year'] === null ? null : (int) $in['release_year'];
        if ($data['release_year'] !== null && ($data['release_year'] < 1950 || $data['release_year'] > (int) date('Y') + 1)) {
            $errors['release_year'] = 'Between 1950 and next year.';
        }
    }
    foreach (['release_date', 'acquired_on', 'sold_on', 'valued_on'] as $dateKey) {
        if ($has($dateKey)) {
            $v = $in[$dateKey];
            $data[$dateKey] = ($v === null || $v === '') ? null : (string) $v;
            if ($data[$dateKey] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[$dateKey])) {
                $errors[$dateKey] = 'Use YYYY-MM-DD.';
            }
        }
    }
    if ($has('rating')) {
        $data['rating'] = $in['rating'] === null ? null : (int) $in['rating'];
        if ($data['rating'] !== null && ($data['rating'] < 1 || $data['rating'] > 10)) {
            $errors['rating'] = 'Between 1 and 10.';
        }
    }
    if ($has('condition')) {
        $grade = rule_condition_grade($in['condition']);
        if ($grade === null) {
            $errors['condition'] = 'Not a known condition grade.';
        } else {
            $data['condition_grade'] = $grade;
        }
    }
    if ($has('completeness')) {
        $value = rule_completeness($in['completeness']);
        if ($value === null) {
            $errors['completeness'] = 'Not a known completeness value.';
        } else {
            $data['completeness'] = $value;
        }
    }
    if ($has('status')) {
        $status = rule_status($in['status']);
        if ($status === null) {
            $errors['status'] = 'Not a known status.';
        } else {
            $data['status'] = $status;
        }
    }
    // Component grades arrive either nested under "components" or flattened.
    $components = is_array($in['components'] ?? null) ? $in['components'] : [];
    foreach (['box', 'manual', 'media'] as $part) {
        $value = $components[$part] ?? ($in['condition_' . $part] ?? null);
        if ($value === null) {
            continue;
        }
        $value = (string) $value;
        if (!in_array($value, component_condition_options(), true)) {
            $errors['condition_' . $part] = 'Not a known grade.';
            continue;
        }
        $data['condition_' . $part] = $value;
    }
    if ($has('copies')) {
        $data['copies'] = max(1, min(255, (int) $in['copies']));
    }
    if ($has('media_count')) {
        $data['media_count'] = max(1, min(255, (int) $in['media_count']));
    }
    foreach (['acquired_price', 'current_value', 'sold_price'] as $moneyKey) {
        if ($has($moneyKey)) {
            $v = $in[$moneyKey];
            $data[$moneyKey] = ($v === null || $v === '') ? null : $v;
            if ($data[$moneyKey] !== null && !is_numeric($data[$moneyKey])) {
                $errors[$moneyKey] = 'Must be a number.';
            }
        }
    }
    if ($has('currency')) {
        $data['currency'] = mb_substr((string) $in['currency'], 0, 3);
    }
    if ($has('is_original')) {
        $data['is_original'] = filter_var($in['is_original'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
    // Older clients still send is_wishlist. It is no longer a column - status
    // is the only truth - so it is translated and then forgotten.
    if ($has('is_wishlist') && !$has('status')) {
        $data['status'] = filter_var($in['is_wishlist'], FILTER_VALIDATE_BOOLEAN) ? 'wishlist' : 'owned';
    }
    if (isset($data['external_url']) && $data['external_url'] !== null
        && !filter_var($data['external_url'], FILTER_VALIDATE_URL)) {
        $errors['external_url'] = 'Must be a full URL.';
    }

    if (!$partial) {
        foreach (['title', 'library_id', 'platform_id', 'category_id'] as $required) {
            if (!isset($data[$required]) || $data[$required] === null || $data[$required] === '' || $data[$required] === 0) {
                $errors[$required] = 'This field is required.';
            }
        }
    } elseif (array_key_exists('title', $data) && ($data['title'] === null || $data['title'] === '')) {
        $errors['title'] = 'Title cannot be emptied.';
    }

    return [$data, $errors];
}

/**
 * The hardware half, which lives in its own table.
 *
 * item_hardware is a side table keyed by item_id, not columns on items - so
 * these cannot go through api_item_input with the rest. Five strings and
 * nothing clever: whatever a client sends is what the web form would have
 * posted as hw_*.
 *
 * Sending an empty string clears a field, because "the serial number I typed
 * was wrong" needs a way to say so.
 */
function api_apply_item_hardware(int $itemId, array $in): void
{
    $fields = [];

    // Whether it works, which is the first thing anybody asks about a machine
    // and the one field here that is not free text.
    if (array_key_exists('working_state', $in)) {
        $state = (string) $in['working_state'];
        if (in_array($state, ['working', 'intermittent', 'not_working', 'untested', 'restored'], true)) {
            $fields['working_state'] = $state;
        }
    }

    // The rest of what item_hardware holds. `interface`, `provides` and `fits`
    // are free text on this table - the vocabulary id beside them is the web's
    // autocomplete, not a constraint - and `recapped_on` is the date somebody
    // last had the lid off, which is the question a twenty-year-old machine
    // raises first.
    foreach (['model' => 160, 'board_revision' => 80, 'firmware' => 80,
              'serial_number' => 120, 'modifications' => 65535,
              'interface' => 80, 'provides' => 120, 'fits' => 255] as $key => $max) {
        if (!array_key_exists($key, $in)) {
            continue;
        }
        $value = $in[$key];
        if ($value !== null && !is_scalar($value)) {
            continue;
        }
        $value = $value === null ? null : mb_substr(trim((string) $value), 0, $max);
        $fields[$key] = ($value === '') ? null : $value;
    }

    foreach (['recapped_on', 'serviced_on'] as $key) {
        if (array_key_exists($key, $in)) {
            $date = trim((string) ($in[$key] ?? ''));
            $fields[$key] = $date === '' ? null : $date;
        }
    }

    if (array_key_exists('manufactured_year', $in)) {
        $year = (int) $in['manufactured_year'];
        $fields['manufactured_year'] = $year > 0 ? $year : null;
    }

    // The specification rows: Processor, Memory, Expansion, Storage, whatever
    // this machine has. A JSON column of {label, value} rather than columns,
    // because an Amiga has a chipset and a PC has a bus and neither list is
    // finite - and the web already writes it in exactly this shape.
    if (array_key_exists('specs', $in)) {
        if (!is_array($in['specs'])) {
            api_error('validation_failed', 'Specification must be a list of {label, value}.',
                      422, ['specs' => 'Must be an array.']);
        }
        $rows = [];
        foreach ($in['specs'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = mb_substr(trim((string) ($row['label'] ?? '')), 0, 80);
            $value = mb_substr(trim((string) ($row['value'] ?? '')), 0, 255);
            // A row with no label is not a row. A row with a label and no value
            // is somebody saying "this machine has one of these and I do not
            // know which", which is worth keeping.
            if ($label !== '') {
                $rows[] = ['label' => $label, 'value' => $value];
            }
        }
        $fields['specs'] = $rows === [] ? null : json_encode($rows);
    }

    if ($fields !== []) {
        save_item_hardware($itemId, $fields);
    }
}

function api_items_create(): void
{
    api_require_write();
    $in = api_body();
    [$data, $errors] = api_item_input($in, false);

    if ($errors !== []) {
        api_error('validation_failed', 'Some fields need attention.', 422, $errors);
    }
    if (!can_add_to_library((int) $data['library_id'])) {
        api_error('forbidden', 'You do not have write access to that library.', 403);
    }

    [$user] = api_require_auth();
    $data += ['currency' => config('currency'), 'is_original' => 1, 'media_count' => 1];
    $data['created_by'] = (int) $user['id'];
    // The same rule the form gets: a branch belongs to a library, and an entry
    // filed under another one - or under a template branch, which belongs to
    // none - is invisible in the tree it claims to be in. 422 rather than a
    // silent correction: a client that sent the wrong id wants to know.
    if (($data['category_id'] ?? null) !== null
        && category_for_library((int) $data['category_id'], (int) $data['library_id']) === null) {
        api_error('bad_category', 'That category does not belong to that library.', 422);
    }
    $id = insert_row('items', $data);
    record_acquisition_event($id, $data);

    if (isset($in['tags']) && is_array($in['tags'])) {
        sync_item_tags($id, implode(',', array_map('strval', $in['tags'])));
    }

    // The lists that live in their own tables. Reported rather than swallowed:
    // a client that sends a malformed media array should be told, not left
    // wondering why the entry came back without one.
    api_apply_item_hardware($id, $in);

    $listErrors = api_apply_item_lists($id, $in);
    if ($listErrors !== []) {
        api_error('validation_failed', 'Some fields need attention.', 422, $listErrors);
    }

    api_ok(item_to_api(find_item($id), true), null, 201, [
        'Location' => base_url() . '/api/v1/items/' . $id,
    ]);
}

function api_items_update(int $id): void
{
    api_require_write();
    $existing = find_item($id);
    if ($existing === null || !can_read_item($existing)) {
        api_error('not_found', 'No catalogue entry with that id.', 404);
    }
    if (!can_write_item($existing)) {
        api_error('forbidden', 'That library is read-only for your account.', 403);
    }
    $in = api_body();
    [$data, $errors] = api_item_input($in, true, $existing);

    if ($errors !== []) {
        api_error('validation_failed', 'Some fields need attention.', 422, $errors);
    }
    if (isset($data['library_id']) && !can_add_to_library((int) $data['library_id'])) {
        api_error('forbidden', 'You do not have write access to the library you are moving this into.', 403);
    }
    if ($data !== []) {
        record_value_change($id, $existing, $data);
        update_row('items', $id, $data);
    }
    if (isset($in['tags']) && is_array($in['tags'])) {
        sync_item_tags($id, implode(',', array_map('strval', $in['tags'])));
    }

    // The lists that live in their own tables. Reported rather than swallowed:
    // a client that sends a malformed media array should be told, not left
    // wondering why the entry came back without one.
    api_apply_item_hardware($id, $in);

    $listErrors = api_apply_item_lists($id, $in);
    if ($listErrors !== []) {
        api_error('validation_failed', 'Some fields need attention.', 422, $listErrors);
    }

    api_ok(item_to_api(find_item($id), true));
}

function api_items_delete(int $id): void
{
    api_require_write();
    $item = one('SELECT id, library_id, created_by FROM items WHERE id = ? AND deleted_at IS NULL', [$id]);
    if ($item === null || !can_read_library((int) $item['library_id'])) {
        api_error('not_found', 'No catalogue entry with that id.', 404);
    }
    if (!can_delete_item($item)) {
        api_error('forbidden', 'That library is read-only for your account.', 403);
    }
    $libraryId = (int) $item['library_id'];
    foreach (all('SELECT id FROM item_images WHERE item_id = ?', [$id]) as $img) {
        record_tombstone('item_images', (int) $img['id'], $libraryId);
    }
    delete_all_item_images($id);
    delete_row('items', $id);
    record_tombstone('items', $id, $libraryId);
    api_no_content();
}

// --- Images -----------------------------------------------------------------

function api_item_images_index(int $itemId): void
{
    api_require_auth();
    $parent = one('SELECT library_id FROM items WHERE id = ? AND deleted_at IS NULL', [$itemId]);
    if ($parent === null || !can_read_library((int) $parent['library_id'])) {
        api_error('not_found', 'No catalogue entry with that id.', 404);
    }
    api_ok(array_map('image_to_api', item_images($itemId)));
}

/**
 * Upload one or more photos. Accepts multipart with "file" or "images[]",
 * or a JSON body with base64 payloads, which is easier from a mobile client
 * that already holds the image in memory.
 */
function api_item_images_upload(int $itemId): void
{
    api_require_write();
    api_guard_image_write($itemId);

    $kind = $_POST['kind'] ?? $_GET['kind'] ?? 'other';
    $before = array_column(item_images($itemId), 'id');

    $stored = 0;
    $errors = [];

    if (!empty($_FILES)) {
        $field = isset($_FILES['file']) ? 'file' : (isset($_FILES['images']) ? 'images' : null);
        if ($field === null) {
            api_error('validation_failed', 'Send the photo as a multipart field named "file".', 422);
        }
        [$stored, $errors] = store_item_images($itemId, $field, (string) $kind);
    } else {
        $in = api_body();
        if (!isset($in['file_base64'])) {
            api_error('validation_failed', 'Send multipart form data with a "file" field, or JSON with "file_base64".', 422);
        }
        [$stored, $errors] = api_store_base64_image(
            $itemId,
            (string) $in['file_base64'],
            (string) ($in['kind'] ?? $kind),
            isset($in['filename']) ? (string) $in['filename'] : null,
            isset($in['caption']) ? (string) $in['caption'] : null
        );
    }

    if ($stored === 0) {
        api_error('upload_failed', $errors[0] ?? 'Nothing was stored.', 422, ['errors' => $errors]);
    }

    $new = array_values(array_filter(
        item_images($itemId),
        fn($img) => !in_array($img['id'], $before, true)
    ));

    api_ok(array_map('image_to_api', $new), $errors === [] ? null : ['warnings' => $errors], 201);
}

/** Decode and store a base64 photo. Returns [storedCount, errors]. */
function api_store_base64_image(int $itemId, string $b64, string $kind, ?string $filename, ?string $caption): array
{
    // Tolerate a data: URL prefix.
    if (preg_match('#^data:[^;]+;base64,#', $b64)) {
        $b64 = (string) preg_replace('#^data:[^;]+;base64,#', '', $b64);
    }
    $binary = base64_decode(strtr(trim($b64), ' ', '+'), true);
    if ($binary === false || $binary === '') {
        return [0, ['file_base64 is not valid base64.']];
    }
    $max = (int) config('uploads.max_bytes');
    if (strlen($binary) > $max) {
        return [0, [sprintf('Image is %.1f MB, over the %.0f MB limit.', strlen($binary) / 1048576, $max / 1048576)]];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'rv');
    if ($tmp === false || file_put_contents($tmp, $binary) === false) {
        return [0, ['Could not buffer the upload on the server.']];
    }

    $info = @getimagesize($tmp);
    $allowed = config('uploads.allowed');
    if ($info === false || !isset($allowed[$info['mime']])) {
        @unlink($tmp);
        return [0, ['Not a supported image. Use JPEG, PNG, WebP or GIF.']];
    }

    // Same shot twice is the normal case from a phone, not an error worth
    // spending disk on.
    $hash = hash('sha256', $binary);
    if (one('SELECT id FROM item_images WHERE item_id = ? AND content_hash = ?', [$itemId, $hash]) !== null) {
        @unlink($tmp);
        return [0, ['That photo is already attached to this entry.']];
    }

    $ext      = $allowed[$info['mime']];
    $basename = $itemId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target   = uploads_dir() . '/' . $basename;

    if (!rename($tmp, $target)) {
        @unlink($tmp);
        return [0, ['Could not write to the uploads directory. Check permissions.']];
    }
    @chmod($target, 0644);
    make_variants($target, $basename, $info['mime']);

    $count = (int) scalar('SELECT COUNT(*) FROM item_images WHERE item_id = ?', [$itemId]);
    insert_row('item_images', [
        'item_id'       => $itemId,
        'filename'      => $basename,
        'content_hash'  => hash('sha256', $binary),
        'original_name' => $filename === null ? null : mb_substr($filename, 0, 255),
        'kind'          => in_array($kind, image_kind_options(), true) ? $kind : 'other',
        'caption'       => $caption === null || $caption === '' ? null : mb_substr($caption, 0, 255),
        'width'         => (int) $info[0],
        'height'        => (int) $info[1],
        'filesize'      => strlen($binary),
        'is_primary'    => $count === 0 ? 1 : 0,
        'sort_order'    => ($count + 1) * 10,
    ]);
    ensure_primary_image($itemId);

    return [1, []];
}

function api_images_update(int $imageId): void
{
    api_require_write();
    $img = one('SELECT * FROM item_images WHERE id = ?', [$imageId]);
    if ($img === null) {
        api_error('not_found', 'No photo with that id.', 404);
    }
    api_guard_image_write((int) $img['item_id']);
    $in = api_body();
    $data = [];

    if (array_key_exists('kind', $in)) {
        $kind = (string) $in['kind'];
        if (!in_array($kind, image_kind_options(), true)) {
            api_error('validation_failed', 'Unknown photo kind.', 422, ['kind' => 'Not a known value.']);
        }
        $data['kind'] = $kind;
    }
    if (array_key_exists('caption', $in)) {
        $data['caption'] = $in['caption'] === null || $in['caption'] === '' ? null : mb_substr((string) $in['caption'], 0, 255);
    }
    if (array_key_exists('sort_order', $in)) {
        $data['sort_order'] = (int) $in['sort_order'];
    }
    if ($data !== []) {
        update_row('item_images', $imageId, $data);
    }
    if (!empty($in['is_primary'])) {
        set_primary_image((int) $img['item_id'], $imageId);
    }

    api_ok(image_to_api(one('SELECT * FROM item_images WHERE id = ?', [$imageId])));
}

function api_images_delete(int $imageId): void
{
    api_require_write();
    $img = one('SELECT item_id FROM item_images WHERE id = ?', [$imageId]);
    if ($img === null) {
        api_error('not_found', 'No photo with that id.', 404);
    }
    $libraryId = api_guard_image_write((int) $img['item_id']);
    delete_image($imageId);
    record_tombstone('item_images', $imageId, $libraryId);
    api_no_content();
}

/** Shared guard for photo writes; returns the parent library id. */
function api_guard_image_write(int $itemId): int
{
    $parent = one('SELECT id, library_id, created_by FROM items WHERE id = ? AND deleted_at IS NULL', [$itemId]);
    if ($parent === null || !can_read_library((int) $parent['library_id'])) {
        api_error('not_found', 'No photo with that id.', 404);
    }
    if (!can_write_item($parent)) {
        api_error('forbidden', 'That library is read-only for your account.', 403);
    }
    return (int) $parent['library_id'];
}

// --- Taxonomy ---------------------------------------------------------------

/**
 * Platforms, with a count of what the caller can actually see on each.
 *
 * Platforms themselves are not access-controlled - filtering the table by
 * library membership was nonsense - but the counts hanging off them are.
 */
function api_platforms_index(): void
{
    api_require_auth();
    [$acl, $aclP] = library_filter_sql('i.library_id', ACCESS_VIEWER);

    // The ACL was applied to the item *count* only, never to which platforms
    // came back: 'FROM platforms p' with no scope returned every row on the
    // instance - template rows, and every other library's custom machines by
    // name. platforms_index() on the web side gets this right and says why
    // ("Somebody else's Sharp MZ-2500 is not anybody's business"); this did not.
    $mine = accessible_library_ids(acting_user(), ACCESS_VIEWER);
    if ($mine === []) {
        api_ok([]);
    }
    $in = implode(',', array_fill(0, count($mine), '?'));

    $rows = all(
        'SELECT p.*, v.name AS manufacturer,
                (SELECT COUNT(*) FROM items i
                  WHERE i.platform_id = p.id AND i.deleted_at IS NULL
                    AND i.status = \'owned\' AND ' . $acl . ') AS n
           FROM platforms p
      LEFT JOIN companies v ON v.id = p.vendor_id
          WHERE p.library_id IN (' . $in . ')
       ORDER BY p.name',
        array_merge($aclP, $mine)
    );
    api_ok(array_map('platform_to_api', $rows));
}

/**
 * Create, update, delete - owner-or-better on the library, not curator,
 * matching can_edit_platform() exactly rather than approximating it.
 * A platform is the root a whole branch of the filing tree hangs from;
 * the web screen already treats that as a step above ordinary curation.
 *
 * Deleting one cascades into the category tree it grew - reusing
 * category_subtree_ids(), a real, general, path-based function already in
 * this codebase, rather than the hardcoded two-level nested subquery
 * platforms_manage_save() uses. That version only ever checks and deletes
 * two levels down; a category tree can go deeper than that, and copying it
 * verbatim would have carried a real bug into the API - items sitting at
 * the third level or below would neither block the delete nor be cleaned
 * up with it, left pointing at a now-orphaned branch under nothing.
 */
function api_platforms_create(): void
{
    api_require_write();
    $in = api_body();

    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Give the platform a name.']);
    }

    $libraryId = isset($in['library_id']) ? (int) $in['library_id'] : 0;
    if ($libraryId <= 0) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['library_id' => 'Choose which library this machine belongs to.']);
    }
    if (!can_own_library($libraryId)) {
        api_error('forbidden', 'That library is not yours.', 403);
    }

    $clash = one('SELECT id FROM platforms WHERE library_id = ? AND slug = ?', [$libraryId, slugify($name)]);
    if ($clash !== null) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['name' => 'That library already has a machine by that name.']);
    }

    $data = api_platform_payload($in, $libraryId);
    $data['name']       = mb_substr($name, 0, 120);
    $data['library_id'] = $libraryId;
    $data['slug']       = unique_slug('platforms', slugify($name));

    $id = insert_row('platforms', $data);
    // And its branch in the catalogue editor, or the machine exists with
    // nowhere to file anything under it.
    platform_ensure_root((int) $id, $libraryId, $name);
    log_server('platform.created', 'Platform "' . $name . '" added', LOG_INFO,
               ['subject_type' => 'platform', 'subject_id' => $id]);

    api_ok(platform_to_api(one('SELECT p.*, v.name AS manufacturer FROM platforms p
                                 LEFT JOIN companies v ON v.id = p.vendor_id WHERE p.id = ?', [$id])), null, 201);
}

function api_platforms_update(int $id): void
{
    api_require_write();
    $existing = one('SELECT * FROM platforms WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No platform with that id.', 404);
    }
    if (!can_edit_platform($existing)) {
        api_error('forbidden', 'That machine is not yours to change.', 403);
    }

    $in = api_body();
    $libraryId = (int) $existing['library_id'];
    $name = array_key_exists('name', $in) ? trim((string) $in['name']) : (string) $existing['name'];
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Give the platform a name.']);
    }

    $clash = one('SELECT id FROM platforms WHERE library_id = ? AND slug = ? AND id <> ?',
                 [$libraryId, slugify($name), $id]);
    if ($clash !== null) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['name' => 'That library already has a machine by that name.']);
    }

    $data = api_platform_payload($in, $libraryId, $existing);
    $data['name'] = mb_substr($name, 0, 120);
    $data['slug'] = unique_slug('platforms', slugify($name), $id);

    update_row('platforms', $id, $data);
    log_server('platform.updated', 'Platform "' . $name . '" changed', LOG_INFO,
               ['subject_type' => 'platform', 'subject_id' => $id]);

    api_ok(platform_to_api(one('SELECT p.*, v.name AS manufacturer FROM platforms p
                                 LEFT JOIN companies v ON v.id = p.vendor_id WHERE p.id = ?', [$id])));
}

/** Shared by create and update - $existing null on create, nothing to fall back to yet. */
function api_platform_payload(array $in, int $libraryId, ?array $existing = null): array
{
    $field = fn(string $k) => array_key_exists($k, $in) ? $in[$k] : ($existing[$k] ?? null);

    $data = [];

    $vendorId = $field('vendor_id');
    $vendorId = $vendorId !== null && (int) $vendorId > 0 ? (int) $vendorId : null;
    if ($vendorId !== null) {
        $vendor = one('SELECT id, library_id FROM companies WHERE id = ?', [$vendorId]);
        if ($vendor === null || (int) $vendor['library_id'] !== $libraryId) {
            api_error('validation_failed', 'Some fields need attention.', 422,
                       ['vendor_id' => 'Choose a maker from this library.']);
        }
    }
    $data['vendor_id'] = $vendorId;

    $year = $field('year_introduced');
    $year = ($year === null || $year === '') ? null : (int) $year;
    if ($year !== null && ($year < 1940 || $year > (int) date('Y') + 1)) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['year_introduced' => 'A year between 1940 and next year.']);
    }
    $data['year_introduced'] = $year;

    $color = (string) ($field('accent_color') ?? '');
    $data['accent_color'] = preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '#a6adc8';

    return $data;
}

/**
 * Refused while any entry is filed under this machine. Once genuinely
 * empty, the branch it grew in the category tree is removed with it -
 * every root that branch has, each walked with category_subtree_ids()
 * rather than a fixed depth, and only removed if that specific subtree is
 * itself empty (a second, narrower check than the platform-wide one
 * above, since the two count different things and could in principle
 * drift apart).
 */
function api_platforms_delete(int $id): void
{
    api_require_write();
    $existing = one('SELECT * FROM platforms WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No platform with that id.', 404);
    }
    if (!can_edit_platform($existing)) {
        api_error('forbidden', 'That machine is not yours to change.', 403);
    }

    $used = (int) scalar('SELECT COUNT(*) FROM items WHERE platform_id = ?', [$id]);
    if ($used > 0) {
        api_error('validation_failed', sprintf(
            '%d %s filed under %s. Move them first.',
            $used, $used === 1 ? 'entry is' : 'entries are', $existing['name']
        ), 422);
    }

    $roots = all('SELECT id FROM categories WHERE platform_id = ? AND parent_id IS NULL', [$id]);
    foreach ($roots as $root) {
        $subtree = category_subtree_ids((int) $root['id']);
        if ($subtree === []) {
            continue;
        }
        $in   = implode(',', array_fill(0, count($subtree), '?'));
        $held = (int) scalar("SELECT COUNT(*) FROM items WHERE category_id IN ($in)", $subtree);
        if ($held === 0) {
            q("DELETE FROM categories WHERE id IN ($in)", $subtree);
        }
    }

    delete_row('platforms', $id);
    log_server('platform.deleted', 'Platform "' . $existing['name'] . '" removed', LOG_NOTICE);
    api_no_content();
}

/** The libraries this account may read, which is what access is decided on. */
function api_libraries_index(): void
{
    api_require_auth();
    [$acl, $aclP] = library_filter_sql('i.library_id', ACCESS_VIEWER);
    $rows = all(
        'SELECT l.*, (SELECT COUNT(*) FROM items i
                       WHERE i.library_id = l.id AND i.deleted_at IS NULL AND ' . $acl . ') AS n
         FROM libraries l ORDER BY l.sort_order, l.name',
        $aclP
    );
    $readable = array_flip(accessible_library_ids(acting_user(), ACCESS_VIEWER));
    $rows = array_values(array_filter($rows, fn($r) => isset($readable[(int) $r['id']])));
    api_ok(array_map('library_to_api', $rows));
}

/** Canonical titles, for a client building an entry form. */
function api_titles_index(): void
{
    api_require_auth();
    $q          = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
    $platformId = api_query_int('platform_id');
    api_ok(array_map('title_to_api', search_titles($q, $platformId, 100)));
}

function api_titles_create(): void
{
    api_require_write();
    $in = api_body();
    [$id, $errors] = save_title(null, [
        'name'         => (string) ($in['name'] ?? ''),
        'subtitle'     => $in['subtitle'] ?? null,
        'sort_name'    => $in['sort_name'] ?? null,
        'platform_id'  => (int) ($in['platform_id'] ?? 0),
        'category_id'  => isset($in['category_id']) ? (int) $in['category_id'] : null,
        'developer'    => $in['developer'] ?? ($in['developer_name'] ?? null),
        'publisher'    => $in['publisher'] ?? ($in['publisher_name'] ?? null),
        'release_year' => isset($in['release_year']) ? (int) $in['release_year'] : null,
        'release_date' => $in['release_date'] ?? null,
        'language'     => $in['language'] ?? null,
        'region'       => $in['region'] ?? null,
        'external_url' => $in['external_url'] ?? null,
        'synopsis'     => $in['synopsis'] ?? null,
    ]);
    if ($errors !== []) {
        api_error('validation_failed', 'Some fields need attention.', 422, $errors);
    }
    api_ok(title_to_api(find_title((int) $id)), null, 201);
}

/**
 * A title is shared reference data, not owned by any library - so unlike
 * item_to_api(), there is no can_read_item() check here. What is controlled
 * is the copies, not the fact that a work called Superfrog exists.
 */
function api_titles_show(int $id): void
{
    api_require_auth();
    $title = find_title($id);
    if ($title === null) {
        api_error('not_found', 'No such title on file.', 404);
    }
    api_ok(title_to_api($title));
}

/**
 * save_title() always validates name and platform_id, whichever id is passed -
 * there is no partial-update mode in the model layer the way item_to_api() has
 * for items. A field the client omitted is read from the existing row instead
 * of being treated as "clear this", which is what PATCH is supposed to mean.
 */
function api_titles_update(int $id): void
{
    api_require_write();
    $existing = find_title($id);
    if ($existing === null) {
        api_error('not_found', 'No such title on file.', 404);
    }
    $in = api_body();
    $merge = fn(string $key, ?string $fallbackKey = null) => array_key_exists($key, $in)
        ? $in[$key]
        : ($existing[$fallbackKey ?? $key] ?? null);

    [, $errors] = save_title($id, [
        'name'         => (string) $merge('name'),
        'subtitle'     => $merge('subtitle'),
        'sort_name'    => $merge('sort_name'),
        'platform_id'  => (int) $merge('platform_id'),
        'category_id'  => $merge('category_id'),
        'developer'    => array_key_exists('developer', $in) ? $in['developer']
            : (array_key_exists('developer_name', $in) ? $in['developer_name'] : ($existing['developer_name'] ?? null)),
        'publisher'    => array_key_exists('publisher', $in) ? $in['publisher']
            : (array_key_exists('publisher_name', $in) ? $in['publisher_name'] : ($existing['publisher_name'] ?? null)),
        'release_year' => $merge('release_year'),
        'release_date' => $merge('release_date'),
        'language'     => $merge('language'),
        'region'       => $merge('region'),
        'external_url' => $merge('external_url'),
        'synopsis'     => $merge('synopsis'),
        'software_model_id' => $merge('software_model_id'),
        'same_work_as' => $in['same_work_as'] ?? null,
        'work_key'     => $merge('work_key'),
    ]);
    if ($errors !== []) {
        api_error('validation_failed', 'Some fields need attention.', 422, $errors);
    }
    api_ok(title_to_api(find_title($id)));
}

/**
 * Copies keep working: items.title_id is ON DELETE SET NULL, so removing a
 * title falls the entries it named back to their own columns rather than
 * losing them - the same rule titles_update()'s delete branch already relies
 * on in the web controller.
 */
function api_titles_delete(int $id): void
{
    api_require_write();
    if (find_title($id) === null) {
        api_error('not_found', 'No such title on file.', 404);
    }
    delete_row('titles', $id);
    api_no_content();
}

/**
 * Where things physically are - the API side of what the web manage screen
 * already does through locations_save()'s single multiplexed action. Real
 * REST verbs here instead, matching every other resource in this API; the
 * business rules themselves are the same functions the web controller
 * already calls, not reimplemented for a second time.
 */
function api_locations_index(): void
{
    api_require_auth();
    $libraryId = api_query_int('library_id');
    if ($libraryId === null || !can_read_library($libraryId)) {
        api_error('forbidden', 'That library is not one you may read.', 403);
    }
    api_ok(array_map('location_to_api', location_tree($libraryId)));
}

function api_locations_create(): void
{
    api_require_write();
    $in = api_body();
    $libraryId = isset($in['library_id']) ? (int) $in['library_id'] : 0;
    if ($libraryId <= 0 || !can_add_to_library($libraryId)) {
        api_error('forbidden', 'That library is not yours to arrange.', 403);
    }

    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Give the place a name.']);
    }

    $parentId = isset($in['parent_id']) && (int) $in['parent_id'] > 0 ? (int) $in['parent_id'] : null;
    if ($parentId !== null) {
        $parent = one('SELECT id FROM locations WHERE id = ? AND library_id = ?', [$parentId, $libraryId]);
        if ($parent === null) {
            api_error('validation_failed', 'Some fields need attention.', 422,
                       ['parent_id' => 'That parent is in another library.']);
        }
    }

    if (location_name_taken($libraryId, $parentId, $name)) {
        $where = $parentId === null ? 'at the top level' : 'in ' . location_breadcrumb($parentId);
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['name' => 'There is already a "' . $name . '" ' . $where . '.']);
    }

    $floor = api_location_floor($in['floor_level'] ?? null);

    $id = (int) insert_row('locations', [
        'library_id'  => $libraryId,
        'parent_id'   => $parentId,
        'name'        => mb_substr($name, 0, 120),
        'floor_level' => $floor,
        'notes'       => nullify($in['notes'] ?? null),
    ]);
    location_rebuild_paths();

    api_ok(location_to_api(one('SELECT * FROM locations WHERE id = ?', [$id])), null, 201);
}

function api_locations_update(int $id): void
{
    api_require_write();
    $existing = one('SELECT * FROM locations WHERE id = ?', [$id]);
    if ($existing === null || !can_read_library((int) $existing['library_id'])) {
        api_error('not_found', 'No such location.', 404);
    }
    $libraryId = (int) $existing['library_id'];
    if (!can_add_to_library($libraryId)) {
        api_error('forbidden', 'That library is not yours to arrange.', 403);
    }

    $in = api_body();
    // Same shape as titles_update(): the model layer here (location_name_taken(),
    // location_would_loop()) has no partial mode, so an omitted field is read
    // back from the existing row rather than being treated as "clear this."
    $name = array_key_exists('name', $in) ? trim((string) $in['name']) : (string) $existing['name'];
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Give the place a name.']);
    }

    $parentId = $existing['parent_id'] === null ? null : (int) $existing['parent_id'];
    if (array_key_exists('parent_id', $in)) {
        $parentId = (int) ($in['parent_id'] ?? 0) > 0 ? (int) $in['parent_id'] : null;
        if ($parentId !== null) {
            $parent = one('SELECT id FROM locations WHERE id = ? AND library_id = ?', [$parentId, $libraryId]);
            if ($parent === null) {
                api_error('validation_failed', 'Some fields need attention.', 422,
                           ['parent_id' => 'That parent is in another library.']);
            }
        }
    }

    if (location_would_loop($id, $parentId)) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['parent_id' => 'A place cannot be inside itself, or inside something it contains.']);
    }
    if (location_name_taken($libraryId, $parentId, $name, $id)) {
        $where = $parentId === null ? 'at the top level' : 'in ' . location_breadcrumb($parentId);
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['name' => 'There is already a "' . $name . '" ' . $where . '.']);
    }

    $floor = array_key_exists('floor_level', $in)
        ? api_location_floor($in['floor_level'])
        : ($existing['floor_level'] === null ? null : (int) $existing['floor_level']);

    update_row('locations', $id, [
        'name'        => mb_substr($name, 0, 120),
        'parent_id'   => $parentId,
        'floor_level' => $floor,
        'notes'       => array_key_exists('notes', $in) ? nullify($in['notes']) : $existing['notes'],
    ]);
    location_rebuild_paths();

    api_ok(location_to_api(one('SELECT * FROM locations WHERE id = ?', [$id])));
}

/**
 * Refused while anything is filed here, through the subtree - the same
 * guard locations_save()'s delete branch already enforces, so a room does
 * not silently take an A500 to null when its cabinet goes with it.
 */
function api_locations_delete(int $id): void
{
    api_require_write();
    $existing = one('SELECT * FROM locations WHERE id = ?', [$id]);
    if ($existing === null || !can_read_library((int) $existing['library_id'])) {
        api_error('not_found', 'No such location.', 404);
    }
    if (!can_add_to_library((int) $existing['library_id'])) {
        api_error('forbidden', 'That library is not yours to arrange.', 403);
    }

    $subtree = location_subtree_ids($id);
    $in      = implode(',', array_fill(0, count($subtree), '?'));
    $held    = (int) scalar("SELECT COUNT(*) FROM items WHERE location_id IN ($in)", $subtree);
    if ($held > 0) {
        api_error('validation_failed', sprintf(
            '%d %s filed in %s or inside it. Move %s first.',
            $held, $held === 1 ? 'entry is' : 'entries are', $existing['name'],
            $held === 1 ? 'it' : 'them'
        ), 422);
    }

    delete_row('locations', $id);
    location_rebuild_paths();
    api_no_content();
}

/** Signed and small, same bound the web form enforces - out of range is a slightly odd
 *  answer rather than a rejected one, so a typo does not block saving the rest of the row. */
function api_location_floor($value): ?int
{
    if ($value === null || trim((string) $value) === '') {
        return null;
    }
    $floor = (int) $value;
    return ($floor < -9 || $floor > 99) ? null : $floor;
}

function location_to_api(array $r): array
{
    return [
        'id'          => (int) $r['id'],
        'library_id'  => (int) $r['library_id'],
        'parent_id'   => $r['parent_id'] === null ? null : (int) $r['parent_id'],
        'name'        => $r['name'],
        'path'        => $r['path'],
        'depth'       => (int) $r['depth'],
        'floor_level' => $r['floor_level'] === null ? null : (int) $r['floor_level'],
        'notes'       => $r['notes'],
        'created_at'  => api_datetime($r['created_at'] ?? null),
    ];
}

function api_categories_index(): void
{
    api_require_auth();

    // Filters, because the tree is thousands of rows: one per kind per machine. A
    // client asking "what can I file an Amiga game under" should not have to fetch
    // every branch of every platform and sort it out itself.
    //
    //   ?domain=software   the software side
    //   ?parent_id=17      the children of one node - a genre list, among other things
    //   ?platform_id=4     one machine's branches
    //   ?role=machine      machine kinds, peripheral kinds, or neither
    $rows = all_categories();

    $domain = (string) ($_GET['domain'] ?? '');
    if (in_array($domain, ['hardware', 'software', 'video', 'music'], true)) {
        $rows = array_values(array_filter($rows, fn($c) => (string) $c['domain'] === $domain));
    }
    if (isset($_GET['parent_id'])) {
        $pid  = (int) $_GET['parent_id'];
        $rows = array_values(array_filter(
            $rows,
            fn($c) => (int) ($c['parent_id'] ?? 0) === $pid
        ));
    }
    if (isset($_GET['platform_id'])) {
        $plid = (int) $_GET['platform_id'];
        $rows = array_values(array_filter(
            $rows,
            fn($c) => (int) ($c['platform_id'] ?? 0) === $plid
        ));
    }
    $role = (string) ($_GET['role'] ?? '');
    if (in_array($role, ['machine', 'peripheral', 'other'], true)) {
        $rows = array_values(array_filter($rows, fn($c) => (string) $c['role'] === $role));
    }

    api_ok(array_map('category_to_api', $rows));
}

/**
 * Create, rename, move, delete - curator-or-better on the library a
 * category belongs to, matched to require_tree_access() exactly rather
 * than approximated. The older, generic api_taxonomy_create() claims
 * categories need an administrator - that comment says
 * "/manage/tree is require_admin", which is not what require_tree_access()
 * actually checks and has not been since it was written; the same class
 * of drift already found and fixed for companies and tags. Shadowed here
 * the same way, registered ahead of that route.
 *
 * Deliberately narrower than the real screen in what remains: no
 * reordering (sibling position is a display nicety, not data), no
 * copy-subtree. Rename's role/section-switch cascade - once deferred here
 * as separate, higher-stakes work - is now built, matched to the real
 * screen's own hardware/software-only scope: the schema's role enum and
 * the sections table both go further (movie, tv_show, music), but the
 * real web app has never offered any of that through its own tree editor
 * either, in create or rename, so this does not add a capability the
 * original never had.
 */
function api_require_curates_library(int $libraryId): array
{
    [$user, $token] = api_require_write();
    if (!is_admin_user(acting_user()) && !can_structure_library($libraryId)) {
        api_error('forbidden', 'You can arrange the tree of a library you curate. This is not one of them.', 403);
    }
    return [$user, $token];
}

function api_categories_create(): void
{
    $in = api_body();

    $parentId = isset($in['parent_id']) ? (int) $in['parent_id'] : 0;
    if ($parentId <= 0) {
        // A root is a machine's own branch, made by platform_ensure_root()
        // when the platform itself is created - the same refusal the web
        // form gives, for the same reason: a root added here would say
        // "platform" with no machine behind it.
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['parent_id' => 'A top-level branch is a machine. Create it through /platforms.']);
    }
    $parent = one('SELECT * FROM categories WHERE id = ?', [$parentId]);
    if ($parent === null) {
        api_error('validation_failed', 'Some fields need attention.', 422, ['parent_id' => 'No such branch.']);
    }
    $libraryId = (int) $parent['library_id'];
    api_require_curates_library($libraryId);

    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Give the new node a name.']);
    }

    $platformId = isset($in['platform_id']) ? (int) $in['platform_id'] : 0;

    $id = insert_row('categories', [
        'library_id'  => $libraryId,
        'section_id'  => (int) $parent['section_id'],
        'parent_id'   => $parentId,
        'platform_id' => $platformId > 0 ? $platformId : null,
        'name'        => mb_substr($name, 0, 120),
        'slug'        => unique_slug('categories', slugify($parent['slug'] . '-' . $name)),
        'sort_order'  => isset($in['sort_order']) ? (int) $in['sort_order'] : 100,
    ]);
    category_rebuild_paths();

    api_ok(category_to_api(one(
        'SELECT c.*, s.slug AS domain FROM categories c JOIN sections s ON s.id = c.section_id WHERE c.id = ?',
        [$id]
    )), null, 201);
}

/**
 * Name, and now the role/section-switch cascade the web form's rename
 * also performs - deferred earlier this session as real, separate,
 * higher-stakes work, now built to match. A root has no kind, whatever
 * the request says, the same refusal the web form gives; the mapping
 * from role to section is the exact match() the web form uses, not a
 * reimplementation of it, and 'other' leaves the section untouched -
 * "nothing directly" says nothing about which side of the shop a branch
 * is on.
 */
function api_categories_update(int $id): void
{
    $existing = one('SELECT c.*, s.slug AS domain FROM categories c JOIN sections s ON s.id = c.section_id WHERE c.id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No such category.', 404);
    }
    api_require_curates_library((int) $existing['library_id']);

    $in = api_body();
    $name = array_key_exists('name', $in) ? trim((string) $in['name']) : (string) $existing['name'];
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Give the node a name.']);
    }

    $fields = [
        'name'       => mb_substr($name, 0, 120),
        'sort_order' => array_key_exists('sort_order', $in) ? (int) $in['sort_order'] : (int) $existing['sort_order'],
    ];

    if (array_key_exists('role', $in) && $existing['parent_id'] !== null) {
        $wantRole = (string) $in['role'];
        if (!in_array($wantRole, ['other', 'machine', 'peripheral', 'game', 'application'], true)) {
            api_error('validation_failed', 'Some fields need attention.', 422,
                       ['role' => 'Not a real kind.']);
        }
        $fields['role'] = $wantRole;
        $sideSlug = match ($wantRole) {
            'machine', 'peripheral' => 'hardware',
            'game', 'application'   => 'software',
            default                 => null,
        };
        $newSectionId = $sideSlug !== null
            ? (int) scalar('SELECT id FROM sections WHERE slug = ?', [$sideSlug])
            : (int) $existing['section_id'];
        $fields['section_id'] = $newSectionId;

        if ($newSectionId !== (int) $existing['section_id']) {
            foreach (category_subtree_ids($id) as $descendant) {
                if ($descendant !== $id) {
                    update_row('categories', $descendant, ['section_id' => $newSectionId]);
                }
            }
        }
    } elseif (array_key_exists('role', $in) && $existing['parent_id'] === null) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['role' => 'A root has no kind - it is the machine itself.']);
    }

    update_row('categories', $id, $fields);

    api_ok(category_to_api(one(
        'SELECT c.*, s.slug AS domain FROM categories c JOIN sections s ON s.id = c.section_id WHERE c.id = ?',
        [$id]
    )));
}

/**
 * Reparent a branch. Loop-prevention and the subtree's section_id both
 * reused exactly as the web form's move already does - a node cannot move
 * inside itself or its own descendants, and the whole branch's section
 * follows its new parent's, since there is no sense in which the children
 * stayed on the old side of the shop while their parent moved to the new
 * one.
 */
function api_categories_move(int $id): void
{
    $node = one('SELECT c.*, s.slug AS domain FROM categories c JOIN sections s ON s.id = c.section_id WHERE c.id = ?', [$id]);
    if ($node === null) {
        api_error('not_found', 'No such category.', 404);
    }
    api_require_curates_library((int) $node['library_id']);

    $in = api_body();
    $newParentId = isset($in['parent_id']) ? (int) $in['parent_id'] : 0;
    if ($newParentId <= 0) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['parent_id' => 'A branch always has a parent - move it under another node, not to the top level.']);
    }
    if (in_array($newParentId, category_subtree_ids($id), true)) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['parent_id' => 'A node cannot be moved inside itself.']);
    }
    $parent = one('SELECT * FROM categories WHERE id = ?', [$newParentId]);
    if ($parent === null || (int) $parent['library_id'] !== (int) $node['library_id']) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['parent_id' => 'That branch is in another library.']);
    }

    $newSectionId = (int) $parent['section_id'];
    update_row('categories', $id, ['parent_id' => $newParentId, 'section_id' => $newSectionId]);
    foreach (category_subtree_ids($id) as $descendant) {
        if ($descendant !== $id) {
            update_row('categories', $descendant, ['section_id' => $newSectionId]);
        }
    }
    category_rebuild_paths();

    api_ok(category_to_api(one(
        'SELECT c.*, s.slug AS domain FROM categories c JOIN sections s ON s.id = c.section_id WHERE c.id = ?',
        [$id]
    )));
}

/**
 * Three real guards, all reused rather than re-derived: a root or the
 * library's last software-filing branch refuses outright
 * (category_protected_reason()); a branch still holding entries refuses;
 * a branch hardware models are still classified under refuses, since that
 * foreign key is ON DELETE SET NULL and would otherwise silently orphan
 * them with nothing in the interface showing it happened.
 */
function api_categories_delete(int $id): void
{
    $existing = one('SELECT * FROM categories WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No such category.', 404);
    }
    api_require_curates_library((int) $existing['library_id']);

    $protected = category_protected_reason($id);
    if ($protected !== null) {
        api_error('validation_failed', $protected, 422);
    }

    $subtree = category_subtree_ids($id);
    $ph = implode(',', array_fill(0, count($subtree), '?'));

    $held = (int) scalar("SELECT COUNT(*) FROM items WHERE category_id IN ($ph)", $subtree);
    if ($held > 0) {
        api_error('validation_failed', sprintf(
            'That branch still holds %d %s. Move them first - deleting a branch should never be a way to lose things by accident.',
            $held, $held === 1 ? 'entry' : 'entries'
        ), 422);
    }

    $models = (int) scalar("SELECT COUNT(*) FROM hardware_models WHERE category_id IN ($ph)", $subtree);
    if ($models > 0) {
        api_error('validation_failed', sprintf(
            'That branch is still the kind of %d hardware %s. Refile them first - deleting it would leave them as neither a machine nor a part.',
            $models, $models === 1 ? 'model' : 'models'
        ), 422);
    }

    delete_row('categories', $id);
    category_rebuild_paths();
    api_no_content();
}

function api_companies_index(): void
{
    api_require_auth();
    $q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
    if ($q !== '') {
        $rows = all('SELECT * FROM companies WHERE name LIKE ? ORDER BY name LIMIT 100', ['%' . $q . '%']);
    } else {
        $rows = all_companies();
    }
    api_ok(array_map('company_to_api', $rows));
}

function api_companies_show(int $id): void
{
    api_require_auth();
    $c = one('SELECT * FROM companies WHERE id = ?', [$id]);
    if ($c === null) {
        api_error('not_found', 'No company with that id.', 404);
    }
    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);
    $out = company_to_api($c);
    $out['developed'] = array_map(
        fn($r) => item_to_api($r),
        all('SELECT * FROM v_items WHERE developer_id = ? AND ' . $acl . ' ORDER BY release_year, title', array_merge([$id], $aclP))
    );
    $out['published'] = array_map(
        fn($r) => item_to_api($r),
        all('SELECT * FROM v_items WHERE publisher_id = ? AND (developer_id IS NULL OR developer_id <> ?) AND ' . $acl . ' ORDER BY release_year, title',
            array_merge([$id, $id], $aclP))
    );
    api_ok($out);
}

/**
 * Create, update, delete - the API side of the generic taxonomy manage
 * screen's companies branch. Curator-or-better on the library a company
 * belongs to, not just "can write something somewhere" - companies are
 * shared reference data every entry in a library points at, the same
 * reasoning that already gates the web screen with require_manage()
 * rather than require_edit().
 *
 * Deliberately narrower than that screen on purpose: no logo upload here,
 * the same restraint titles' own create/edit already applied to
 * software-model templates and box contents - a real feature the original
 * has that this API has nowhere to receive yet.
 */
function api_companies_create(): void
{
    [$user, $token] = api_require_write();
    $in = api_body();

    $libraryId = isset($in['library_id']) ? (int) $in['library_id'] : 0;
    if ($libraryId <= 0 || (!is_admin_user(acting_user()) && !can_structure_library($libraryId))) {
        api_error('forbidden', 'You can arrange a library you curate. This is not one of them.', 403);
    }

    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Name is required.']);
    }

    $data = api_company_payload($in);
    $data['name']       = mb_substr($name, 0, 255);
    $data['library_id'] = $libraryId;
    $data['slug']       = unique_slug('companies', slugify($name));

    $id = insert_row('companies', $data);
    api_ok(company_to_api(one('SELECT * FROM companies WHERE id = ?', [$id])), null, 201);
}

function api_companies_update(int $id): void
{
    api_require_write();
    $existing = one('SELECT * FROM companies WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No company with that id.', 404);
    }
    $libraryId = (int) $existing['library_id'];
    if (!is_admin_user(acting_user()) && !can_structure_library($libraryId)) {
        api_error('forbidden', 'You can arrange a library you curate. This is not one of them.', 403);
    }

    $in = api_body();
    $name = array_key_exists('name', $in) ? trim((string) $in['name']) : (string) $existing['name'];
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Name is required.']);
    }

    $data = api_company_payload($in, $existing);
    $data['name'] = mb_substr($name, 0, 255);
    $data['slug'] = unique_slug('companies', slugify($name), $id);

    update_row('companies', $id, $data);
    api_ok(company_to_api(one('SELECT * FROM companies WHERE id = ?', [$id])));
}

/**
 * Shared by create and update. $existing is null on create, in which case
 * an omitted field is simply not set rather than read back from a row that
 * does not exist yet - the same "omitted keeps its current value" contract
 * titles_update() already uses, just with nothing to fall back to the
 * first time.
 */
function api_company_payload(array $in, ?array $existing = null): array
{
    $data = [];

    $field = fn(string $k) => array_key_exists($k, $in) ? $in[$k] : ($existing[$k] ?? null);

    foreach (['country', 'website', 'wikipedia_url', 'notes'] as $k) {
        $v = $field($k);
        $data[$k] = $v === null || trim((string) $v) === '' ? null : trim((string) $v);
    }
    foreach (['founded_year', 'defunct_year'] as $k) {
        $v = $field($k);
        $data[$k] = ($v === null || $v === '') ? null : (int) $v;
    }
    foreach (['website', 'wikipedia_url'] as $k) {
        if ($data[$k] !== null && !filter_var($data[$k], FILTER_VALIDATE_URL)) {
            api_error('validation_failed', 'Some fields need attention.', 422,
                       [$k => 'Must be a full URL starting with https://.']);
        }
    }

    // A set of ticks, stored as the SET column it is - present as an array
    // of zero, one or two values: ['hardware'], ['software'], both, or
    // neither. Only reset when the key was actually sent, matching the
    // omitted-keeps-current-value contract every other field here follows.
    if (array_key_exists('makes', $in)) {
        $picked = is_array($in['makes']) ? $in['makes'] : [];
        $picked = array_values(array_intersect(['hardware', 'software'], $picked));
        $data['makes'] = implode(',', $picked);
    }

    return $data;
}

/**
 * Refused while a live entry still points at this - the same distinction
 * the web screen's delete already makes between "in active use" and "only
 * pointed at from the trash", so the message says which is true rather
 * than a generic "still in use".
 */
function api_companies_delete(int $id): void
{
    api_require_write();
    $existing = one('SELECT * FROM companies WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No company with that id.', 404);
    }
    if (!is_admin_user(acting_user()) && !can_structure_library((int) $existing['library_id'])) {
        api_error('forbidden', 'You can arrange a library you curate. This is not one of them.', 403);
    }

    $live = (int) scalar('SELECT COUNT(*) FROM items
                           WHERE (developer_id = ? OR publisher_id = ?) AND deleted_at IS NULL', [$id, $id]);
    $binned = (int) scalar('SELECT COUNT(*) FROM items
                             WHERE (developer_id = ? OR publisher_id = ?) AND deleted_at IS NOT NULL', [$id, $id]);

    if ($live > 0 || $binned > 0) {
        $message = match (true) {
            $live > 0 && $binned > 0 => sprintf(
                '%d entr%s still %s this, and %d more in the trash. Reassign the first, '
                . 'then empty the trash.', $live, $live === 1 ? 'y' : 'ies',
                $live === 1 ? 'uses' : 'use', $binned),
            $binned > 0 => sprintf(
                '%d deleted entr%s still points at this. It is in the trash, which keeps '
                . 'what it referred to - empty the trash and this can go.',
                $binned, $binned === 1 ? 'y' : 'ies'),
            default => 'Still in use by catalogue entries, so it was kept. Reassign those entries first.',
        };
        api_error('validation_failed', $message, 422);
    }

    delete_row('companies', $id);
    api_no_content();
}

/**
 * People - directors, artists, authors. Curator-or-better on the library,
 * the same bar companies already sets, since a person is shared reference
 * data every credit on that library's titles can point at.
 */
function api_people_index(): void
{
    [$user, $token] = api_require_auth();
    $libraryId = isset($_GET['library_id']) ? (int) $_GET['library_id'] : 0;
    $q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
    if ($libraryId <= 0) {
        $lib = working_library();
        $libraryId = $lib === null ? 0 : (int) $lib['id'];
    }
    if ($q !== '') {
        api_ok(array_map('person_to_api', all(
            'SELECT * FROM people WHERE library_id = ? AND name LIKE ? ORDER BY name LIMIT 100',
            [$libraryId, '%' . $q . '%']
        )));
        return;
    }
    api_ok(array_map('person_to_api', all_people($libraryId)));
}

function api_people_create(): void
{
    $in = api_body();
    $libraryId = isset($in['library_id']) ? (int) $in['library_id'] : 0;
    if ($libraryId <= 0) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['library_id' => 'Choose which library this person belongs to.']);
    }
    api_require_curates_library($libraryId);

    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Name is required.']);
    }

    $data = api_person_payload($in);
    $data['name']       = mb_substr($name, 0, 160);
    $data['library_id'] = $libraryId;
    $data['slug']       = unique_slug('people', slugify($name));

    $id = insert_row('people', $data);
    api_ok(person_to_api(one('SELECT * FROM people WHERE id = ?', [$id])), null, 201);
}

function api_people_update(int $id): void
{
    $existing = one('SELECT * FROM people WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No person with that id.', 404);
    }
    api_require_curates_library((int) $existing['library_id']);

    $in = api_body();
    $name = array_key_exists('name', $in) ? trim((string) $in['name']) : (string) $existing['name'];
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Name is required.']);
    }

    $data = api_person_payload($in, $existing);
    $data['name'] = mb_substr($name, 0, 160);
    $data['slug'] = unique_slug('people', slugify($name), $id);

    update_row('people', $id, $data);
    api_ok(person_to_api(one('SELECT * FROM people WHERE id = ?', [$id])));
}

/** Shared by create and update - $existing null on create, nothing to fall back to yet. */
function api_person_payload(array $in, ?array $existing = null): array
{
    $field = fn(string $k) => array_key_exists($k, $in) ? $in[$k] : ($existing[$k] ?? null);
    $data = [];
    foreach (['website', 'wikipedia_url', 'notes'] as $k) {
        $v = $field($k);
        $data[$k] = $v === null || trim((string) $v) === '' ? null : trim((string) $v);
    }
    foreach (['born_year', 'died_year'] as $k) {
        $v = $field($k);
        $data[$k] = ($v === null || $v === '') ? null : (int) $v;
    }
    foreach (['website', 'wikipedia_url'] as $k) {
        if ($data[$k] !== null && !filter_var($data[$k], FILTER_VALIDATE_URL)) {
            api_error('validation_failed', 'Some fields need attention.', 422,
                       [$k => 'Must be a full URL starting with https://.']);
        }
    }
    return $data;
}

/** Refused while any credit still names this person - the same "never silently lose data" guard every other delete here carries. */
function api_people_delete(int $id): void
{
    $existing = one('SELECT * FROM people WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No person with that id.', 404);
    }
    api_require_curates_library((int) $existing['library_id']);

    $used = (int) scalar('SELECT COUNT(*) FROM credits WHERE person_id = ?', [$id]);
    if ($used > 0) {
        api_error('validation_failed', sprintf(
            'Still credited on %d %s, so it was kept. Remove those credits first.',
            $used, $used === 1 ? 'title' : 'titles'
        ), 422);
    }

    delete_row('people', $id);
    api_no_content();
}

/**
 * Credit roles - Director, Composer - each tagged with which domain(s) it
 * makes sense in, the same domains set platforms and companies already
 * carry. Curator-or-better, the same bar people and companies both set.
 */
function api_credit_roles_index(): void
{
    api_require_auth();
    $libraryId = isset($_GET['library_id']) ? (int) $_GET['library_id'] : 0;
    $domain    = isset($_GET['domain']) && is_string($_GET['domain']) ? $_GET['domain'] : null;
    if ($libraryId <= 0) {
        $lib = working_library();
        $libraryId = $lib === null ? 0 : (int) $lib['id'];
    }
    api_ok(array_map('credit_role_to_api', all_credit_roles($libraryId, $domain)));
}

function api_credit_roles_create(): void
{
    $in = api_body();
    $libraryId = isset($in['library_id']) ? (int) $in['library_id'] : 0;
    if ($libraryId <= 0) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['library_id' => 'Choose which library this role belongs to.']);
    }
    api_require_curates_library($libraryId);

    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Name is required.']);
    }

    $picked = is_array($in['domains'] ?? null) ? $in['domains'] : [];
    $picked = array_values(array_intersect(['hardware', 'software', 'video', 'music'], $picked));
    if ($picked === []) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['domains' => 'Choose at least one domain this role applies to.']);
    }

    $id = insert_row('credit_roles', [
        'library_id' => $libraryId,
        'name'       => mb_substr($name, 0, 80),
        'slug'       => unique_slug('credit_roles', slugify($name)),
        'domains'    => implode(',', $picked),
        'sort_order' => isset($in['sort_order']) ? (int) $in['sort_order'] : 100,
    ]);
    api_ok(credit_role_to_api(one('SELECT * FROM credit_roles WHERE id = ?', [$id])), null, 201);
}

function api_credit_roles_update(int $id): void
{
    $existing = one('SELECT * FROM credit_roles WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No role with that id.', 404);
    }
    api_require_curates_library((int) $existing['library_id']);

    $in = api_body();
    $name = array_key_exists('name', $in) ? trim((string) $in['name']) : (string) $existing['name'];
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Name is required.']);
    }

    $data = ['name' => mb_substr($name, 0, 80), 'slug' => unique_slug('credit_roles', slugify($name), $id)];
    if (array_key_exists('domains', $in)) {
        $picked = is_array($in['domains']) ? $in['domains'] : [];
        $picked = array_values(array_intersect(['hardware', 'software', 'video', 'music'], $picked));
        if ($picked === []) {
            api_error('validation_failed', 'Some fields need attention.', 422,
                       ['domains' => 'Choose at least one domain this role applies to.']);
        }
        $data['domains'] = implode(',', $picked);
    }
    if (array_key_exists('sort_order', $in)) {
        $data['sort_order'] = (int) $in['sort_order'];
    }

    update_row('credit_roles', $id, $data);
    api_ok(credit_role_to_api(one('SELECT * FROM credit_roles WHERE id = ?', [$id])));
}

/** Refused while any credit still uses this role - matches the database's own ON DELETE RESTRICT, checked first for the real message. */
function api_credit_roles_delete(int $id): void
{
    $existing = one('SELECT * FROM credit_roles WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No role with that id.', 404);
    }
    api_require_curates_library((int) $existing['library_id']);

    $used = (int) scalar('SELECT COUNT(*) FROM credits WHERE role_id = ?', [$id]);
    if ($used > 0) {
        api_error('validation_failed', sprintf(
            'Still used on %d credit%s, so it was kept. Reassign those credits first.',
            $used, $used === 1 ? '' : 's'
        ), 422);
    }

    delete_row('credit_roles', $id);
    api_no_content();
}

/**
 * Credits - who did what on a title. One holder per credit, a person or a
 * company never both, the same rule the database's own CHECK constraint
 * enforces regardless of what this layer does - checked here too, so a bad
 * request gets a real message instead of a raw constraint failure.
 */
function api_credits_index(): void
{
    api_require_auth();
    $titleId = isset($_GET['title_id']) ? (int) $_GET['title_id'] : 0;
    if ($titleId <= 0) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['title_id' => 'Which title to list credits for.']);
    }
    $rows = all(
        "SELECT c.*, r.name AS role_name, r.slug AS role_slug,
                COALESCE(p.name, co.name) AS holder_name
           FROM credits c
           JOIN credit_roles r ON r.id = c.role_id
      LEFT JOIN people p       ON p.id = c.person_id
      LEFT JOIN companies co   ON co.id = c.company_id
          WHERE c.title_id = ?
       ORDER BY c.sort_order, r.sort_order",
        [$titleId]
    );
    api_ok(array_map('credit_to_api', $rows));
}

function api_credits_create(): void
{
    $in = api_body();
    $titleId = isset($in['title_id']) ? (int) $in['title_id'] : 0;
    $title = one('SELECT * FROM titles t JOIN platforms p ON p.id = t.platform_id WHERE t.id = ?', [$titleId]);
    if ($title === null) {
        api_error('validation_failed', 'Some fields need attention.', 422, ['title_id' => 'No such title.']);
    }
    $libraryId = (int) $title['library_id'];
    api_require_curates_library($libraryId);

    $roleId = isset($in['role_id']) ? (int) $in['role_id'] : 0;
    $role = one('SELECT * FROM credit_roles WHERE id = ? AND library_id = ?', [$roleId, $libraryId]);
    if ($role === null) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['role_id' => 'No such role in this library.']);
    }

    $personId  = isset($in['person_id'])  && (int) $in['person_id']  > 0 ? (int) $in['person_id']  : null;
    $companyId = isset($in['company_id']) && (int) $in['company_id'] > 0 ? (int) $in['company_id'] : null;
    if (($personId === null) === ($companyId === null)) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['person_id' => 'Credit exactly one person or one company, not both and not neither.']);
    }
    if ($personId !== null) {
        $ok = one('SELECT id FROM people WHERE id = ? AND library_id = ?', [$personId, $libraryId]);
        if ($ok === null) {
            api_error('validation_failed', 'Some fields need attention.', 422,
                       ['person_id' => 'No such person in this library.']);
        }
    } else {
        $ok = one('SELECT id FROM companies WHERE id = ? AND library_id = ?', [$companyId, $libraryId]);
        if ($ok === null) {
            api_error('validation_failed', 'Some fields need attention.', 422,
                       ['company_id' => 'No such company in this library.']);
        }
    }

    $id = insert_row('credits', [
        'library_id' => $libraryId,
        'title_id'   => $titleId,
        'role_id'    => $roleId,
        'person_id'  => $personId,
        'company_id' => $companyId,
        'sort_order' => isset($in['sort_order']) ? (int) $in['sort_order'] : 100,
    ]);
    $row = one(
        "SELECT c.*, r.name AS role_name, r.slug AS role_slug,
                COALESCE(p.name, co.name) AS holder_name
           FROM credits c
           JOIN credit_roles r ON r.id = c.role_id
      LEFT JOIN people p       ON p.id = c.person_id
      LEFT JOIN companies co   ON co.id = c.company_id
          WHERE c.id = ?",
        [$id]
    );
    api_ok(credit_to_api($row), null, 201);
}

/** Refused, deliberately, on a title moving to another platform's library - a credit belongs to the title it names, not the other way round; delete and re-add rather than re-point one across libraries. */
function api_credits_delete(int $id): void
{
    $existing = one('SELECT * FROM credits WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No credit with that id.', 404);
    }
    api_require_curates_library((int) $existing['library_id']);

    delete_row('credits', $id);
    api_no_content();
}

function api_tags_index(): void
{
    api_require_auth();
    api_ok(array_map(
        fn($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'slug' => $t['slug']],
        all_tags()
    ));
}

/**
 * Create, update, delete - tags have no library_id at all, unlike
 * companies, so there is no specific library to check ownership against.
 * The real web screen's require_manage() runs unconditionally before its
 * type branches even start, so every type it covers - tags included -
 * genuinely needs curator-or-better on some library, not merely the
 * ability to write something somewhere. Checked here as "curates at least
 * one library", the closest real equivalent to a check that on the web
 * side is anchored to whichever library happens to be the working one.
 *
 * Replaces this type's case in the older, generic api_taxonomy_create() -
 * that function's own comment claims tags only need write access, which
 * does not match what taxonomy_save() actually enforces. Registered ahead
 * of the generic route, the same way api_companies_create() already
 * shadows that function's companies case for the identical reason.
 */
function api_require_curates_any(): array
{
    [$user, $token] = api_require_write();
    if (!is_admin_user(acting_user()) && accessible_library_ids($user, ACCESS_CURATOR) === []) {
        api_error('forbidden', 'You can arrange a library you curate. This is not one of them.', 403);
    }
    return [$user, $token];
}

function api_tags_create(): void
{
    api_require_curates_any();
    $in = api_body();
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Name is required.']);
    }
    $id = insert_row('tags', ['name' => mb_substr($name, 0, 80), 'slug' => unique_slug('tags', slugify($name))]);
    $row = one('SELECT * FROM tags WHERE id = ?', [$id]);
    api_ok(['id' => (int) $row['id'], 'name' => $row['name'], 'slug' => $row['slug']], null, 201);
}

function api_tags_update(int $id): void
{
    api_require_curates_any();
    $existing = one('SELECT * FROM tags WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No tag with that id.', 404);
    }
    $in = api_body();
    $name = array_key_exists('name', $in) ? trim((string) $in['name']) : (string) $existing['name'];
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Name is required.']);
    }
    update_row('tags', $id, ['name' => mb_substr($name, 0, 80), 'slug' => unique_slug('tags', slugify($name), $id)]);
    $row = one('SELECT * FROM tags WHERE id = ?', [$id]);
    api_ok(['id' => (int) $row['id'], 'name' => $row['name'], 'slug' => $row['slug']]);
}

/**
 * Refused while any item still carries this tag - the same rule the web
 * screen's generic delete already applies to every taxonomy type via a
 * caught foreign-key violation; checked directly here rather than caught,
 * since item_tags has no soft-delete/trash distinction the way items
 * themselves do, so there is only the one real answer to give.
 */
function api_tags_delete(int $id): void
{
    api_require_curates_any();
    $existing = one('SELECT * FROM tags WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No tag with that id.', 404);
    }
    $used = (int) scalar('SELECT COUNT(*) FROM item_tags WHERE tag_id = ?', [$id]);
    if ($used > 0) {
        api_error('validation_failed', sprintf(
            'Still on %d catalogue entr%s, so it was kept. Remove it from those first.',
            $used, $used === 1 ? 'y' : 'ies'
        ), 422);
    }
    delete_row('tags', $id);
    api_no_content();
}

/** Create a lookup row. Handy for a client that lets you add a library on the fly. */
function api_taxonomy_create(string $type): void
{
    [$user] = api_require_write();
    // No 'genres': a genre is a category, created through /api/v1/categories with a
    // parent. One collection, because there is one mechanism.
    $tables = ['platforms' => 'platforms', 'categories' => 'categories', 'companies' => 'companies', 'tags' => 'tags'];
    // Creating a library is a membership-bearing act and goes through
    // /libraries, not through the generic taxonomy endpoint.
    if ($type === 'libraries') {
        api_error('not_found', 'Create libraries through the web interface; they carry membership.', 404);
    }
    if (!isset($tables[$type])) {
        api_error('not_found', 'No such collection.', 404);
    }

    // The same bar the browser has to clear. Contributor was enough here for
    // everything, while the web insists on more for two of these - so a token
    // scoped to write could reshape the filing tree that every library shares,
    // which no account can do through the interface. The two surfaces are the
    // same application and must not disagree about who may do what.
    //
    //   categories, genres   the shared tree: /manage/tree is require_admin
    //   platforms            library-scoped: /manage/platforms needs ownership
    //   companies, tags      /manage/<t> is require_edit, which is this
    if ($type === 'categories' && ($user['role'] ?? '') !== 'admin') {
        api_error(
            'forbidden',
            'The filing tree is shared by every library, so only an administrator may add to it.',
            403
        );
    }

    $in = api_body();
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        api_error('validation_failed', 'A name is required.', 422, ['name' => 'Required.']);
    }

    $data = ['name' => mb_substr($name, 0, 160), 'slug' => unique_slug($type, slugify($name))];

    if ($type === 'platforms') {
        // A library, always, and one this account owns - the same rule
        // platforms_manage_save() applies. Without it this wrote library_id NULL,
        // which since the redesign means "template": a row copied into libraries
        // when they are created and visible in none of them. The endpoint
        // reported 201 and the platform appeared nowhere.
        $libraryId = isset($in['library_id']) ? (int) $in['library_id'] : 0;
        if ($libraryId <= 0) {
            api_error('validation_failed', 'Say which library the machine belongs to.', 422,
                      ['library_id' => 'Required.']);
        }
        if (!can_own_library($libraryId)) {
            api_error('forbidden', 'That library is not yours to add machines to.', 403);
        }
        $data['library_id'] = $libraryId;

        // 'manufacturer' is a read alias built by LEFT JOIN companies, and
        // sort_order went in migration 0005. Writing either threw an uncaught
        // PDOException, so this endpoint could never create a platform.
        $vendorId = isset($in['vendor_id']) ? (int) $in['vendor_id'] : 0;
        if ($vendorId > 0) {
            $vendor = one('SELECT id, library_id FROM companies WHERE id = ?', [$vendorId]);
            if ($vendor === null || (int) $vendor['library_id'] !== $libraryId) {
                api_error('validation_failed', 'That maker is not one you can use.', 422,
                          ['vendor_id' => 'Unknown maker.']);
            }
            $data['vendor_id'] = $vendorId;
        }
        $data['year_introduced'] = isset($in['year_introduced']) ? (int) $in['year_introduced'] : null;
        $data['accent_color']    = isset($in['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $in['color'])
            ? (string) $in['color'] : '#cba6f7';
    } elseif ($type === 'categories') {
        // parent_id is what makes a genre: a category under Games is a genre, one
        // under Applications › Productivity is a kind of application. Same field.
        $parent = isset($in['parent_id']) ? (int) $in['parent_id'] : 0;
        if ($parent > 0) {
            $row = one('SELECT id, section_id FROM categories WHERE id = ?', [$parent]);
            if ($row === null) {
                api_error('validation_failed', 'No category with that parent id.', 422,
                          ['parent_id' => 'Unknown category.']);
            }
            $data['parent_id']  = $parent;
            $data['section_id'] = (int) $row['section_id'];
        } else {
            $sectionSlug = in_array((string) ($in['domain'] ?? 'software'),
                                    ['software', 'hardware', 'video', 'music'], true)
                ? (string) $in['domain'] : 'software';
            $data['section_id'] = (int) scalar('SELECT id FROM sections WHERE slug = ?', [$sectionSlug]);
        }
        $data['role']       = 'other';
        $data['sort_order'] = isset($in['sort_order']) ? (int) $in['sort_order'] : 0;
    } elseif ($type === 'companies') {
        foreach (['country', 'website', 'wikipedia_url', 'notes'] as $k) {
            $data[$k] = isset($in[$k]) ? (string) $in[$k] : null;
        }
        $data['founded_year'] = isset($in['founded_year']) ? (int) $in['founded_year'] : null;
    }

    $id = insert_row($type, $data);
    $row = $type === 'categories'
        ? one('SELECT c.*, s.slug AS domain FROM categories c JOIN sections s ON s.id = c.section_id WHERE c.id = ?', [$id])
        : one("SELECT * FROM `$type` WHERE id = ?", [$id]);

    $serialiser = [
        'platforms'  => 'platform_to_api',
        'categories' => 'category_to_api',
        'companies'  => 'company_to_api',
    ][$type] ?? null;

    api_ok($serialiser ? $serialiser($row) : $row, null, 201);
}

// --- Stats and sync ---------------------------------------------------------

function api_stats(): void
{
    api_require_auth();
    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);
    [$aclI, $iP]  = library_filter_sql('i.library_id', ACCESS_VIEWER);

    $totals = one('SELECT COUNT(*) AS items,
                          SUM(status = \'owned\') AS owned,
                          SUM(status = \'wishlist\') AS wanted,
                          SUM(status = \'sold\') AS sold,
                          SUM(acquired_price) AS spend,
                          SUM(current_value) AS value,
                          SUM(sold_price) AS recouped,
                          AVG(NULLIF(rating,0)) AS avg_rating,
                          MIN(NULLIF(release_year,0)) AS earliest, MAX(release_year) AS latest
                   FROM items WHERE deleted_at IS NULL AND ' . $acl, $aclP) ?? [];

    api_ok([
        'items'          => (int) ($totals['items'] ?? 0),
        'owned'          => (int) ($totals['owned'] ?? 0),
        'wishlist'       => (int) ($totals['wanted'] ?? 0),
        'sold'           => (int) ($totals['sold'] ?? 0),
        'photos'         => (int) scalar('SELECT COUNT(*) FROM item_images img JOIN items i ON i.id = img.item_id
                                          WHERE i.deleted_at IS NULL AND ' . $aclI, $iP),
        'total_spend'    => $totals['spend'] === null ? null : (float) $totals['spend'],
        'total_value'    => $totals['value'] === null ? null : (float) $totals['value'],
        'total_recouped' => $totals['recouped'] === null ? null : (float) $totals['recouped'],
        'currency'       => config('currency'),
        'average_rating' => $totals['avg_rating'] === null ? null : round((float) $totals['avg_rating'], 2),
        'year_range'     => [
            'from' => $totals['earliest'] === null ? null : (int) $totals['earliest'],
            'to'   => $totals['latest'] === null ? null : (int) $totals['latest'],
        ],
        'by_library' => all('SELECT l.id, l.name, l.slug, l.accent_color AS color, COUNT(i.id) AS count,
                                    SUM(i.current_value) AS value
                             FROM libraries l
                             LEFT JOIN items i ON i.library_id = l.id AND i.deleted_at IS NULL AND i.status = \'owned\'
                             WHERE ' . str_replace('i.library_id', 'l.id', $aclI) . '
                             GROUP BY l.id ORDER BY count DESC, l.name', $iP),
        'by_platform' => all('SELECT p.id, p.name, p.slug, p.accent_color AS color, COUNT(i.id) AS count,
                                     SUM(i.current_value) AS value
                              FROM platforms p
                              LEFT JOIN items i ON i.platform_id = p.id AND i.deleted_at IS NULL
                                               AND i.status = \'owned\' AND ' . $aclI . '
                              GROUP BY p.id HAVING count > 0 ORDER BY count DESC, p.name', $iP),
        'by_category' => all('SELECT c.id, c.name, c.slug, s.slug AS domain, COUNT(i.id) AS count
                              FROM categories c
                              JOIN sections s ON s.id = c.section_id
                              LEFT JOIN items i ON i.category_id = c.id AND i.deleted_at IS NULL
                                               AND i.status = \'owned\' AND ' . $aclI . '
                              GROUP BY c.id HAVING count > 0 ORDER BY count DESC', $iP),
        'by_decade' => all('SELECT FLOOR(release_year/10)*10 AS decade, COUNT(*) AS count
                            FROM items WHERE deleted_at IS NULL AND release_year IS NOT NULL
                              AND status = \'owned\' AND ' . $acl . '
                            GROUP BY decade ORDER BY decade', $aclP),
        'missing' => [
            'photos'    => (int) scalar('SELECT COUNT(*) FROM v_items WHERE image_count = 0 AND ' . $acl, $aclP),
            'year'      => (int) scalar('SELECT COUNT(*) FROM v_items WHERE release_year IS NULL AND ' . $acl, $aclP),
            'developer' => (int) scalar('SELECT COUNT(*) FROM v_items WHERE developer_id IS NULL AND ' . $acl, $aclP),
            'value'     => (int) scalar('SELECT COUNT(*) FROM v_items WHERE current_value IS NULL AND status = \'owned\' AND ' . $acl, $aclP),
        ],
    ]);
}

/**
 * Barcode lookup, so a phone can scan a box and jump straight to the entry.
 * Returns the matches rather than a single item: duplicates and regional
 * variants legitimately share a barcode.
 */
function api_barcode_lookup(string $barcode): void
{
    api_require_auth();
    $barcode = trim($barcode);
    if ($barcode === '') {
        api_error('validation_failed', 'Send a barcode to look up.', 422);
    }
    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);
    $rows = all("SELECT * FROM v_items WHERE barcode = ? AND $acl ORDER BY title", array_merge([$barcode], $aclP));

    api_ok([
        'barcode' => $barcode,
        'found'   => $rows !== [],
        'items'   => array_map(fn($r) => item_to_api($r, true), $rows),
    ]);
}

/** One entry at random from what the caller can see. Good for a "play this" button. */
function api_items_random(): void
{
    api_require_auth();
    [$where, $params] = build_item_filters($_GET);
    $row = one("SELECT * FROM v_items WHERE $where ORDER BY RAND() LIMIT 1", $params);
    if ($row === null) {
        api_error('not_found', 'Nothing matches those filters.', 404);
    }
    api_ok(item_to_api($row, true));
}

/**
 * Create several entries in one request. Bulk-adding from a barcode scanning
 * session over a mobile connection is painful one round trip at a time.
 * Partial success is normal, so each result reports its own outcome.
 */
function api_items_bulk(): void
{
    [$bulkUser] = api_require_write();
    $in = api_body();
    $rows = $in['items'] ?? null;
    if (!is_array($rows) || $rows === []) {
        api_error('validation_failed', 'Send an "items" array.', 422);
    }
    if (count($rows) > 100) {
        api_error('validation_failed', 'Send at most 100 entries per request.', 422);
    }

    $results  = [];
    $created  = 0;
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            $results[] = ['index' => $index, 'ok' => false, 'error' => 'Not an object.'];
            continue;
        }
        [$data, $errors] = api_item_input($row, false);
        if ($errors !== []) {
            $results[] = ['index' => $index, 'ok' => false, 'error' => 'validation_failed', 'details' => $errors];
            continue;
        }
        if (!can_add_to_library((int) $data['library_id'])) {
            $results[] = ['index' => $index, 'ok' => false, 'error' => 'forbidden'];
            continue;
        }
        // Per row, not per batch: one bad category should cost that row and not
        // the nine good ones beside it.
        if (($data['category_id'] ?? null) !== null
            && category_for_library((int) $data['category_id'], (int) $data['library_id']) === null) {
            $results[] = ['index' => $index, 'ok' => false, 'error' => 'bad_category'];
            continue;
        }
        $data += ['currency' => config('currency'), 'is_original' => 1, 'media_count' => 1];
        $data['created_by'] = (int) $bulkUser['id'];
        $id = insert_row('items', $data);
        record_acquisition_event($id, $data);
        if (isset($row['tags']) && is_array($row['tags'])) {
            sync_item_tags($id, implode(',', array_map('strval', $row['tags'])));
        }
        $created++;
        $results[] = ['index' => $index, 'ok' => true, 'id' => $id, 'title' => $data['title']];
    }

    api_ok($results, ['created' => $created, 'failed' => count($rows) - $created], $created > 0 ? 201 : 422);
}

/**
 * Delta sync for offline clients.
 *
 * Pass the server_time from the previous response back as ?since=. The first
 * call omits it and receives everything. Deletions come back as tombstones,
 * because a client cannot infer them from a list of changed rows.
 */
function api_sync(): void
{
    api_require_auth();

    $since = isset($_GET['since']) && is_string($_GET['since']) ? trim($_GET['since']) : '';
    $sinceSql = null;
    if ($since !== '') {
        $ts = api_parse_datetime($since);
        if ($ts === null) {
            api_error('validation_failed', 'since must be an ISO 8601 timestamp, for example 2026-07-25T09:30:00Z.', 422);
        }
        $sinceSql = date('Y-m-d H:i:s', $ts);
    }

    // Captured before the reads, so anything written mid-request is picked up
    // by the next sync rather than being missed entirely.
    $serverTime = gmdate('Y-m-d\TH:i:s\Z');

    [$acl, $aclP]      = library_filter_sql('library_id', ACCESS_VIEWER);
    [$tombAcl, $tombP] = library_filter_sql('library_id', ACCESS_VIEWER);

    if ($sinceSql === null) {
        $changed = all("SELECT * FROM v_items WHERE $acl ORDER BY id", $aclP);
        $deleted = ['items' => [], 'item_images' => []];
    } else {
        $changed = all("SELECT * FROM v_items WHERE updated_at > ? AND $acl ORDER BY id", array_merge([$sinceSql], $aclP));
        // A tombstone with no library recorded predates access control, so it
        // is only reported to users who can see everything.
        $rows = all(
            "SELECT entity, entity_id FROM tombstones
             WHERE deleted_at > ? AND (library_id IS NOT NULL AND $tombAcl)",
            array_merge([$sinceSql], $tombP)
        );
        $deleted = ['items' => [], 'item_images' => []];
        foreach ($rows as $r) {
            if (isset($deleted[$r['entity']])) {
                $deleted[$r['entity']][] = (int) $r['entity_id'];
            }
        }
    }

    api_ok([
        'server_time' => $serverTime,
        'since'       => $since === '' ? null : api_datetime($sinceSql),
        'full_sync'   => $sinceSql === null,
        'items'       => array_map(fn($r) => item_to_api($r, true), $changed),
        'deleted'     => $deleted,
        'libraries'   => array_map('library_to_api', readable_libraries()),
        'platforms'   => array_map('platform_to_api', all_platforms()),
        'categories'  => array_map('category_to_api', all_categories()),
        'companies'   => array_map('company_to_api', all_companies()),
        // Titles the caller's entries actually point at. Sending the whole
        // table would grow without bound; this is exactly what a client needs
        // to render what it just received.
        'titles'      => array_map('title_to_api', titles_for_items(array_column($changed, 'title_id'))),
    ], [
        'items_changed' => count($changed),
        'items_deleted' => count($deleted['items']),
    ]);
}

/**
 * Metadata lookup for native clients: same providers, same normalised shape.
 * Read-only - applying a suggestion goes through the ordinary item update.
 */
function api_metadata_search(): void
{
    api_require_write();
    $title = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
    if ($title === '') {
        api_error('validation_failed', 'Pass ?q= with a title to search for.', 422);
    }
    // A platform is not an access boundary, so there is nothing to check here
    // beyond it existing. What the caller may then do with a result is decided
    // when they write it to a library.
    $platformId = api_query_int('platform_id');
    if ($platformId !== null && one('SELECT id FROM platforms WHERE id = ?', [$platformId]) === null) {
        api_error('validation_failed', 'No platform with that id.', 422);
    }

    $out = metadata_search_all($title, $platformId);
    api_ok($out['results'], [
        'query'    => $title,
        'count'    => count($out['results']),
        // An object, always.
        //
        // PHP encodes an empty associative array as [] and a populated one as
        // {...}, so this field changed shape depending on whether any source had
        // failed - and a client that decoded one could not decode the other. It
        // cost an evening: with every source working the answer was an array, and
        // disabling a single provider turned it into an object.
        'errors'   => (object) $out['errors'],
        'providers' => array_map(
            fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'type' => $p['type']],
            enabled_metadata_providers()
        ),
    ]);
}

// ---------------------------------------------------------------------------
// Notifications, for native clients
//
// Written for a phone that has been in a pocket for a week: it holds the
// timestamp of the last notice it saw, asks for everything after it, and gets
// back rows it can render without a second call. `unread` comes with every
// response so a badge can be drawn from one request.
//
// Reading is not writing, so a read-only token can poll this; marking things
// read is a write, because it changes what other clients will see.
// ---------------------------------------------------------------------------

function api_notifications_index(): void
{
    // api_identify() hands back [$user, $token] and may hand back null, so it
    // cannot stand in for the check: reading it as a user row looked right and
    // asked the database for $user['id'] on a two-element list. There is no
    // api_require_read() either - reading is what api_require_auth() allows,
    // and a read-only token is refused only by api_require_write().
    [$user] = api_require_auth();

    $since  = trim((string) ($_GET['since'] ?? ''));
    $unread = isset($_GET['unread']) && $_GET['unread'] !== '0';
    $limit  = max(1, min(200, (int) ($_GET['limit'] ?? 50)));

    if ($since !== '' && strtotime($since) === false) {
        api_error('validation_failed', 'since must be a timestamp the server can read.', 422);
    }

    $rows = notifications_for((int) $user['id'], $limit, $since ?: null, $unread);

    // api_ok($data, $meta) builds the envelope itself. Passing an envelope to it
    // wrapped a second one round the first, so this endpoint alone answered
    // {"data":{"data":[...],"meta":{...}}} while every other one answers
    // {"data":[...],"meta":{...}} - which is also what docs/openapi.yaml says.
    api_ok(
        array_map('notification_to_api', $rows),
        [
            'unread' => unread_notification_count((int) $user['id']),
            // What to send as `since` next time. Taken from the newest row
            // rather than from the clock, so nothing written during this
            // request is skipped.
            'cursor' => $rows === [] ? ($since ?: null) : $rows[0]['created_at'],
        ]
    );
}

function api_notifications_read(): void
{
    [$user] = api_require_write();

    // api_body(), not api_json_body(): the body is read the same way whether it
    // arrived as JSON or as form fields, and a native client that posts a form
    // should be able to mark a notice read like any other.
    $payload = api_body();
    $ids     = $payload['ids'] ?? null;

    if ($ids === 'all' || (is_array($ids) && $ids === [])) {
        $n = mark_all_notifications_read((int) $user['id']);
        api_ok(['marked' => $n, 'unread' => 0]);
    }

    if (!is_array($ids)) {
        api_error('validation_failed', 'Send ids as an array, or "all".', 422);
    }

    $marked = 0;
    foreach (array_slice($ids, 0, 200) as $id) {
        if ((int) $id > 0) {
            mark_notification_read((int) $user['id'], (int) $id);
            $marked++;
        }
    }

    api_ok([
        'marked' => $marked,
        'unread' => unread_notification_count((int) $user['id']),
    ]);
}


/**
 * Fetch a picture from a metadata source and attach it.
 *
 * The web has had this since metadata lookup existed; the API never did, so a
 * phone could find the box art and not keep it. The server does the fetching,
 * not the client: it already knows how to check what came back is an image, how
 * to resize it, and how to notice the same picture arriving twice.
 *
 * `provenance` is official, always. This is the publisher's artwork by
 * definition - a scraped picture is not somebody's photograph of their own copy,
 * and the two answer different questions.
 */
function api_item_images_import(int $itemId): void
{
    api_require_write();
    $item = find_item($itemId);
    if ($item === null || !can_write_item($item)) {
        api_error('not_found', 'No catalogue entry with that id.', 404);
    }

    $in  = api_body();
    $url = trim((string) ($in['url'] ?? ''));
    if ($url === '') {
        api_error('validation_failed', 'Send the address of the picture.', 422,
                  ['url' => 'Required.']);
    }

    $kind = (string) ($in['kind'] ?? 'box_front');
    if (!in_array($kind, image_kind_options(), true)) {
        api_error('validation_failed', 'Unknown photo kind.', 422,
                  ['kind' => 'Not a known value.']);
    }

    $caption = isset($in['caption']) ? mb_substr(trim((string) $in['caption']), 0, 255) : null;

    [$ok, $why, $dupe] = array_pad(metadata_import_image($itemId, $url, $kind, $caption), 3, null);

    // Already here is not a failure. Somebody who taps the same artwork twice
    // has not made a mistake worth an error, and the picture they wanted is on
    // the entry either way.
    if (!$ok && !$dupe) {
        api_error('upload_failed', (string) $why, 422);
    }

    api_ok(['imported' => (bool) $ok, 'already_here' => (bool) $dupe]);
}

/**
 * Make a library of your own.
 *
 * Not the admin route. `POST /admin/libraries` administers an instance and needs
 * an administrator; this is the thing any signed-in person may do, and the web
 * has always let them - `library_create()` checks only that somebody is signed
 * in. The API being stricter than the web for the same action is a difference
 * nobody could have predicted from either.
 *
 * The caller owns it, which is what makes it theirs to fill.
 */
function api_libraries_create(): void
{
    [$user] = api_require_write();

    $in   = api_body();
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        api_error('validation_failed', 'Give the library a name.', 422,
                  ['name' => 'Required.']);
    }
    $name = mb_substr($name, 0, 120);

    $kind = (string) ($in['kind'] ?? 'private');
    if (!in_array($kind, ['private', 'shared'], true)) {
        api_error('validation_failed', 'Must be private or shared.', 422,
                  ['kind' => 'Not a known value.']);
    }

    $colour = trim((string) ($in['color'] ?? '#cba6f7'));
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $colour) !== 1) {
        api_error('validation_failed', 'A colour looks like #cba6f7.', 422,
                  ['color' => 'Six hex digits behind a hash.']);
    }

    $id = (int) insert_row('libraries', [
        'name'         => $name,
        'slug'         => unique_slug('libraries', slugify($name)),
        'description'  => mb_substr(trim((string) ($in['description'] ?? '')), 0, 500) ?: null,
        'owner_id'     => (int) $user['id'],
        'kind'         => $kind,
        'accent_color' => strtolower($colour),
        'is_active'    => 1,
    ]);

    // Owning it is not the same as being a member of it.
    //
    // `libraries.owner_id` says whose it is; `library_members` decides who may
    // see it, and accessible_library_ids() reads the second. Without this row
    // the library existed, appeared under library management - which asks the
    // server for everything - and was invisible in the caller's own list and
    // every picker built from it. The web has always written both.
    q('INSERT IGNORE INTO library_members (library_id, user_id, access, granted_by)
       VALUES (?, ?, ?, ?)',
      [$id, (int) $user['id'], ACCESS_OWNER, (int) $user['id']]);
    // The cache was filled before the row existed, and this request still has
    // work to do with it.
    $GLOBALS['__membership_cache'] = [];

    log_security('library.created', sprintf('Created library "%s"', $name), LOG_NOTICE,
                 ['subject_type' => 'library', 'subject_id' => $id]);

    $row = one('SELECT l.*, 0 AS n FROM libraries l WHERE l.id = ?', [$id]);
    api_ok(library_to_api($row), null, 201);
}

/**
 * What is fitted to an entry, and what it is fitted to.
 *
 * The catalogue's one genuinely relational idea: a Blizzard 1230 is installed in
 * an A1200, a SIMM is installed in the Blizzard, a monitor was bundled with the
 * machine. The web has had this since item_links existed and the API had nothing
 * at all, so a phone could see "Installed peripherals" on the web and not know
 * the relationship exists.
 */
function api_item_links_index(int $itemId): void
{
    api_require_auth();
    $item = find_item($itemId);
    if ($item === null || !can_read_library((int) $item['library_id'])) {
        api_error('not_found', 'No catalogue entry with that id.', 404);
    }

    api_ok([
        // Both directions, because "what is in this machine" and "what is this
        // card sitting in" are the same table read two ways, and a client that
        // gets one of them has half an answer.
        'contains' => array_map('api_item_link_row', item_children($itemId)),
        'inside'   => array_map('api_item_link_row', item_parents($itemId)),
    ], ['relations' => [
        'installed_in' => 'Installed in',
        'bundled_with' => 'Bundled with',
        'spare_for'    => 'Spare for',
        'connects_to'  => 'Connects to',
    ]]);
}

function api_item_link_row(array $r): array
{
    return [
        'link_id'  => (int) $r['link_id'],
        'id'       => (int) $r['id'],
        'title'    => $r['title'],
        'relation' => $r['relation'],
        'note'     => $r['note'],
    ];
}

/**
 * Fit one entry to another.
 *
 * `direction` decides which way round: `contains` means the entry in the path is
 * the machine and `other_id` is the card, `inside` means the reverse. Without it
 * a client would have to know which of two entries is the parent before it can
 * say they are related, which is a question about the API rather than about the
 * things.
 *
 * The loop check is item_link_would_loop(), the same one the web calls. SQL
 * cannot express "and no path from child back to parent", so it is a walk - and
 * a catalogue that lets a machine sit inside itself is no longer describing
 * anything.
 */
function api_item_links_create(int $itemId): void
{
    api_require_write();
    $item = find_item($itemId);
    if ($item === null || !can_write_item($item)) {
        api_error('not_found', 'No catalogue entry with that id.', 404);
    }

    $in      = api_body();
    $otherId = (int) ($in['other_id'] ?? 0);
    $other   = $otherId > 0 ? find_item($otherId) : null;
    if ($other === null || !can_read_library((int) $other['library_id'])) {
        api_error('validation_failed', 'No entry with that id.', 422,
                  ['other_id' => 'Not found, or not yours to see.']);
    }

    $relation = (string) ($in['relation'] ?? 'installed_in');
    if (!in_array($relation, ['installed_in', 'bundled_with', 'spare_for', 'connects_to'], true)) {
        api_error('validation_failed', 'Unknown relation.', 422,
                  ['relation' => 'Not a known value.']);
    }

    $contains = ($in['direction'] ?? 'contains') === 'contains';
    $parent   = $contains ? $itemId : $otherId;
    $child    = $contains ? $otherId : $itemId;

    if ($parent === $child) {
        api_error('validation_failed', 'An entry cannot be fitted to itself.', 422);
    }

    // The rules, checked here rather than trusted to the client.
    $machineRow = $parent === $itemId ? $item : $other;
    $partRow    = $parent === $itemId ? $other : $item;
    if ($relation === 'installed_in') {
        $why = api_link_refusal($machineRow, $partRow);
        if ($why !== null) {
            api_error('validation_failed', $why, 422);
        }
    }
    if (item_link_would_loop($parent, $child)) {
        api_error('validation_failed',
                  sprintf('That would make a loop: %s already sits inside this one, '
                        . 'directly or through something else.', (string) $other['title']), 422);
    }

    q('INSERT IGNORE INTO item_links (parent_item_id, child_item_id, relation, note)
       VALUES (?, ?, ?, ?)',
      [$parent, $child, $relation,
       isset($in['note']) ? mb_substr(trim((string) $in['note']), 0, 255) : null]);

    api_ok([
        'contains' => array_map('api_item_link_row', item_children($itemId)),
        'inside'   => array_map('api_item_link_row', item_parents($itemId)),
    ], null, 201);
}

/** Take one apart again. */
function api_item_links_delete(int $itemId, int $linkId): void
{
    api_require_write();
    $item = find_item($itemId);
    if ($item === null || !can_write_item($item)) {
        api_error('not_found', 'No catalogue entry with that id.', 404);
    }

    // Checked against this entry, so a link id from somewhere else cannot be
    // used to unpick a machine the caller may not touch.
    $link = one('SELECT id FROM item_links
                  WHERE id = ? AND (parent_item_id = ? OR child_item_id = ?)',
                [$linkId, $itemId, $itemId]);
    if ($link === null) {
        api_error('not_found', 'No such link on this entry.', 404);
    }

    q('DELETE FROM item_links WHERE id = ?', [$linkId]);
    api_no_content();
}

/**
 * The canonical models a client can file an entry under.
 *
 * `items.model_id` has been writable through the API for a while and there was
 * no way to discover an id to put in it - which makes a writable field a field
 * nobody can use.
 *
 * Narrowed by `category_id` when one is given, because that is what the web's
 * picker does: a model belongs to a branch of the tree, and a list of every
 * model on the instance is not a picker, it is a haystack. `platform_id`
 * narrows it the coarser way for a client that has a machine but not yet a
 * branch.
 */
function api_models_index(): void
{
    api_require_auth();

    $libraryId  = api_query_int('library_id');
    $categoryId = api_query_int('category_id');
    $platformId = api_query_int('platform_id');

    // A library the caller cannot read is not one to list models from.
    if ($libraryId !== null && !can_read_library($libraryId)) {
        api_error('not_found', 'No library with that id.', 404);
    }

    $models = $categoryId !== null
        ? models_for_category($categoryId, $libraryId)
        : hardware_models($platformId, null, $libraryId);

    $q = isset($_GET['q']) && is_string($_GET['q']) ? mb_strtolower(trim($_GET['q'])) : '';
    if ($q !== '') {
        $models = array_values(array_filter(
            $models,
            fn(array $m) => str_contains(mb_strtolower((string) $m['name']), $q)
        ));
    }

    api_ok(array_map(static fn(array $m): array => [
        'id'        => (int) $m['id'],
        'name'      => $m['name'],
        'slug'      => $m['slug'] ?? null,
        'year_from' => isset($m['year_from']) && $m['year_from'] !== null
            ? (int) $m['year_from'] : null,
        // Named, not an id: "category 41" means nothing in a picker.
        'platform'  => $m['platform_name'] ?? null,
        'category'  => $m['category_name'] ?? null,
        'vendor'    => $m['vendor_name'] ?? null,
    ], array_slice($models, 0, 200)));
}

/**
 * Whether one entry may be fitted inside another, and why not.
 *
 * Three rules, all of them the web's:
 *
 *  1. Only a peripheral goes into a machine. A machine does not go inside a
 *     cartridge and a game does not go inside anything - the app offered both
 *     directions to everything, which let somebody record an Amiga 2000
 *     installed in a copy of Superfrog.
 *  2. Software is not fitted at all. It has no physical inside.
 *  3. A peripheral that declares what it fits must fit *this* machine: the
 *     machine's model has to be in the peripheral's list, and the platforms have
 *     to agree. A Zorro card does not go in a C64, and a catalogue that says it
 *     does is worth less than no catalogue.
 *
 * Returns null when it may, or the sentence to refuse with.
 */
function api_link_refusal(array $machine, array $part): ?string
{
    if (($machine['domain'] ?? '') !== 'hardware' || ($part['domain'] ?? '') !== 'hardware') {
        return 'Only hardware can be fitted together. Software has no inside.';
    }

    if (($machine['category_role'] ?? '') !== 'machine') {
        return sprintf('%s is not a machine, so nothing can be fitted into it.',
                       (string) $machine['title']);
    }
    if (($part['category_role'] ?? '') !== 'peripheral') {
        return sprintf('%s is not a peripheral. Only peripherals are fitted into machines.',
                       (string) $part['title']);
    }

    // What the peripheral says it fits, by model.
    //
    // Two silences, and both mean "cannot tell", not "no".
    //
    // A peripheral that names nothing goes anywhere - refusing on silence would
    // make the catalogue harder to fill in than to leave wrong. And a machine
    // with no model cannot be checked against a list of models at all: this
    // compared the machine's model_id to the list and, finding NULL, read it as
    // 0 and refused. Every machine in a fresh catalogue has no model, so every
    // peripheral that had been filed properly was refused by every machine -
    // the better the data, the worse the answer.
    // effective_compatibility(), not model_compatibility_ids().
    //
    // Compatibility is declared in two places and this only read one. A model
    // may name the machines it fits, and a single card may name them itself
    // through item_compatibility - the "Compatible hardware" checkboxes on the web form.
    // effective_compatibility() is the function that already knows the precedence: the
    // model's list when it has one, the card's own otherwise. Reading only the
    // model meant a peripheral whose compatibility had been recorded by hand
    // looked like one that had said nothing, and the answer came out right for
    // the wrong reason - until somebody set a model, at which point it came out
    // wrong.
    $machineModel = (int) ($machine['model_id'] ?? 0);
    $compatible = effective_compatibility((int) $part['id'], (int) ($part['model_id'] ?? 0))['ids'];
    if ($compatible !== [] && $machineModel > 0 && !in_array($machineModel, $compatible, true)) {
        // "fits" used to appear here, in a sentence a real client could show
        // somebody - the last place the old name would have leaked past the
        // rename entirely, into text a person actually reads.
        return sprintf('%s is not listed as compatible with %s.',
                       (string) $part['title'], (string) $machine['title']);
    }

    // And the platform, which catches the case where neither has a model: an
    // Amiga card in a PC is wrong even when nobody has filed either one.
    $machinePlatform = (int) ($machine['platform_id'] ?? 0);
    $partPlatform    = (int) ($part['platform_id'] ?? 0);
    if ($machinePlatform > 0 && $partPlatform > 0 && $machinePlatform !== $partPlatform) {
        return sprintf('%s is for a different machine family.', (string) $part['title']);
    }

    return null;
}

/**
 * What may be fitted into this entry.
 *
 * The picker used to list the whole collection, so somebody could choose a game
 * and be refused afterwards - which is a worse way to learn a rule than not
 * being offered it.
 */
function api_item_links_candidates(int $itemId): void
{
    api_require_auth();
    $machine = find_item($itemId);
    if ($machine === null || !can_read_library((int) $machine['library_id'])) {
        api_error('not_found', 'No catalogue entry with that id.', 404);
    }

    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);
    $rows = all("SELECT * FROM v_items
                  WHERE id <> ? AND deleted_at IS NULL AND $acl
               ORDER BY title", array_merge([$itemId], $aclP));

    $out = [];
    foreach ($rows as $row) {
        if (api_link_refusal($machine, $row) === null) {
            $out[] = item_to_api($row);
        }
    }

    api_ok($out);
}

/**
 * The company with that name in that library, made if it is not there.
 *
 * A metadata source knows a developer's name and this catalogue knows companies
 * by id, so a lookup that found "Team17 Software Limited" against a library that
 * has "Team17" could do nothing with it: the app said "no company here is called
 * that, add it on the web", which is a phone telling somebody to go and find a
 * computer.
 *
 * Matched case-insensitively by name first, then by slug, before anything is
 * created - a library with "team17" should not gain "Team17" beside it. The
 * decision to create is the caller's; this only carries it out.
 */
function api_company_for_name(int $libraryId, string $name, string $makes = 'software'): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $existing = one('SELECT id FROM companies
                      WHERE library_id = ? AND LOWER(name) = LOWER(?) LIMIT 1',
                    [$libraryId, $name]);
    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $slug = slugify($name);
    $bySlug = one('SELECT id FROM companies WHERE library_id = ? AND slug = ? LIMIT 1',
                  [$libraryId, $slug]);
    if ($bySlug !== null) {
        return (int) $bySlug['id'];
    }

    $id = (int) insert_row('companies', [
        'library_id' => $libraryId,
        'makes'      => in_array($makes, ['hardware', 'software', 'both'], true) ? $makes : 'software',
        'name'       => mb_substr($name, 0, 160),
        'slug'       => unique_slug('companies', $slug),
    ]);

    // Logged, and louder when something like it is already here.
    //
    // A source that answers "Team17 Software Limited" to a library holding
    // "Team17" is describing the same firm, and no rule this end can be sure of
    // that: matching on one name containing the other would merge Sega and Sega
    // Europe, which are not the same company at all. So it is created as asked
    // and the near-match is named in the log, where somebody can merge the two
    // deliberately. Silent duplication is how a catalogue ends up with four
    // Team17s and no way to know which is right.
    $similar = all('SELECT name FROM companies
                     WHERE library_id = ? AND id <> ?
                       AND (LOWER(name) LIKE LOWER(?) OR LOWER(?) LIKE CONCAT(LOWER(name), \'%\'))
                     LIMIT 3',
                   [$libraryId, $id, $name . '%', $name]);

    log_security('company.created',
        $similar === []
            ? sprintf('Created company "%s" from a name sent by a client', $name)
            : sprintf('Created company "%s" - this library already has %s, which may be the same firm',
                      $name, implode(', ', array_map(fn($r) => '"' . $r['name'] . '"', $similar))),
        LOG_NOTICE, ['subject_type' => 'company', 'subject_id' => $id]);

    return $id;
}
