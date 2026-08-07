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

    // The same rule the web sign-in already enforces, missing here entirely -
    // a correct password issued a working token regardless of whether the
    // account had ever confirmed its address, on an instance that requires
    // exactly that. A client asking for a token has to be told the same way
    // a browser posting the login form already is, or the requirement is
    // real for one path into the same account and not the other.
    if (needs_email_verification($row)) {
        api_error('email_unverified',
                   'Confirm your email address before signing in. Check your inbox for the link, '
                   . 'or ask for another one.', 403, ['username' => $username]);
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

    // Optional, and a real calendar date rather than "expires in N days" -
    // a person picking a date knows what they mean by it; a client is
    // free to still offer a shorter list of presets that resolve to one.
    $expiresAt = null;
    if (!empty($in['expires_at'])) {
        $raw = trim((string) $in['expires_at']);
        $ts = strtotime($raw);
        if ($ts === false) {
            api_error('validation_failed', 'expires_at must be a date the server can read.', 422);
        }
        if ($ts <= time()) {
            api_error('validation_failed', 'expires_at has to be in the future.', 422);
        }
        $expiresAt = date('Y-m-d 23:59:59', $ts);
    }

    [$id, $plain] = create_api_token(
        (int) $user['id'],
        $name,
        $scope,
        isset($in['platform']) ? mb_substr((string) $in['platform'], 0, 40) : null,
        $expiresAt
    );
    api_ok([
        'id'         => $id,
        'name'       => $name,
        'scope'      => $scope,
        'token'      => $plain,
        'expires_at' => $expiresAt === null ? null : api_datetime($expiresAt),
        'note'       => 'Store this now. It is not recoverable.',
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

    // The library owns the entry and decides who may see it. Moved ahead of
    // the developer/publisher block below, which reads $data['library_id']
    // to resolve a company by name - this used to run first, so a create
    // sending both library_id and a bare developer name always failed with
    // "send library_id too" even though it was right there in the same
    // request, just not copied into $data yet by the time that block asked.
    if ($has('library_id')) {
        $data['library_id'] = (int) $in['library_id'];
        if (one('SELECT id FROM libraries WHERE id = ?', [$data['library_id']]) === null) {
            $errors['library_id'] = 'No library with that id.';
        }
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

    // A single library, when asked for one - a picker built for one
    // library's own categories editor has no use for every platform
    // across every library the account can reach, and showing all of
    // them duplicated each name once per library that happened to copy
    // it in. Optional, and additive: nothing that already calls this
    // without the parameter changes behaviour.
    $onlyLibrary = api_query_int('library_id');
    if ($onlyLibrary !== null && !can_read_library($onlyLibrary)) {
        api_error('forbidden', 'That library is not one you may read.', 403);
    }

    // The ACL was applied to the item *count* only, never to which platforms
    // came back: 'FROM platforms p' with no scope returned every row on the
    // instance - template rows, and every other library's custom machines by
    // name. platforms_index() on the web side gets this right and says why
    // ("Somebody else's Sharp MZ-2500 is not anybody's business"); this did not.
    $mine = $onlyLibrary !== null ? [$onlyLibrary] : accessible_library_ids(acting_user(), ACCESS_VIEWER);
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

/**
 * What a library actually holds - a client for library_contents_index()
 * and library_contents_summary(), not a new screen invented alongside
 * them. The real app built this specifically because a bare item count
 * is a poor thing to make a delete decision against: every entry,
 * linked, and the platforms, makers, models and places the library
 * defined for itself - the things people forget a library owns until
 * they have deleted it. Owner or instance administrator, the same
 * check the real screen uses; it was administrator-only originally,
 * which the real app's own comment calls the wrong way round; "what is
 * actually in here" is the first thing an owner wants once a library
 * has grown past what they can hold in their head.
 */
function api_library_contents(int $id): void
{
    [$user] = api_require_auth();
    $library = one('SELECT l.*, o.username AS owner_name
                       FROM libraries l LEFT JOIN users o ON o.id = l.owner_id
                      WHERE l.id = ?', [$id]);
    if ($library === null) {
        api_error('not_found', 'No such library.', 404);
    }
    if (!is_admin() && !can_own_library($id)) {
        api_error('forbidden', 'Only the owner, or an administrator, may see what a library holds.', 403);
    }

    $page    = max(1, api_query_int('page') ?? 1);
    $perPage = 100;

    $entries = all(
        'SELECT i.id, i.title, i.created_at,
                c.name AS category_name, p.name AS platform_name,
                (SELECT COUNT(*) FROM item_images im WHERE im.item_id = i.id) AS images
           FROM items i
      LEFT JOIN categories c ON c.id = i.category_id
      LEFT JOIN platforms  p ON p.id = i.platform_id
          WHERE i.library_id = ? AND i.deleted_at IS NULL
       ORDER BY i.title
          LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
        [$id]
    );

    $summary = library_contents_summary($id);
    $total   = (int) $summary['entries'];

    api_ok([
        'library' => library_to_api($library),
        'summary' => $summary,
        'entries' => array_map(fn($r) => [
            'id'            => (int) $r['id'],
            'title'         => $r['title'],
            'category_name' => $r['category_name'],
            'platform_name' => $r['platform_name'],
            'images'        => (int) $r['images'],
            'created_at'    => api_datetime($r['created_at']),
        ], $entries),
        'platforms' => all('SELECT id, name, slug FROM platforms WHERE library_id = ? ORDER BY name', [$id]),
        'companies' => all('SELECT id, name, slug FROM companies WHERE library_id = ? ORDER BY name', [$id]),
        'locations' => all('SELECT id, name FROM locations WHERE library_id = ? ORDER BY name', [$id]),
        'hardware'  => all('SELECT id, name, slug FROM hardware_models WHERE library_id = ? ORDER BY name', [$id]),
        'software'  => all('SELECT id, name FROM software_models WHERE library_id = ? ORDER BY name', [$id]),
        'members'   => all('SELECT m.access, m.status, u.username, u.display_name
                               FROM library_members m JOIN users u ON u.id = m.user_id
                              WHERE m.library_id = ? ORDER BY u.username', [$id]),
    ], [
        'page'  => $page,
        'pages' => max(1, (int) ceil($total / $perPage)),
        'total' => $total,
    ]);
}

/**
 * Everything the "Library access" page needs in one call - a client for
 * library_admin_index()'s own three-tab data, combined the same way it
 * combines them for one render rather than three separate requests.
 * mine is joined_libraries() with the counts the real page shows; invites
 * and owner_offers are what's waiting on an answer; joinable is a
 * published shelf not yet taken on.
 */
function api_profile_libraries(): void
{
    [$user] = api_require_auth();

    $mine = array_map(fn($l) => library_to_api($l + [
        'n' => (int) scalar('SELECT COUNT(*) FROM items WHERE library_id = ? AND deleted_at IS NULL', [(int) $l['id']]),
    ]) + [
        'member_count' => (int) scalar('SELECT COUNT(*) FROM library_members WHERE library_id = ?', [(int) $l['id']]),
    ], joined_libraries());

    $invites = array_map(fn($r) => [
        'library'     => library_to_api($r),
        'access'      => $r['access'],
        'access_label' => access_label((string) $r['access']),
        'invited_by'  => $r['invited_by'],
        'granted_at'  => api_datetime($r['granted_at']),
    ], all(
        "SELECT l.*, m.access, m.granted_at, g.username AS invited_by
           FROM library_members m
           JOIN libraries l ON l.id = m.library_id
      LEFT JOIN users g ON g.id = m.granted_by
          WHERE m.user_id = ? AND m.status = 'pending' AND l.is_active = 1
       ORDER BY m.granted_at DESC",
        [(int) $user['id']]
    ));

    $ownerOffers = array_map(fn($r) => [
        'library'     => library_to_api($r),
        'offered_by'  => $r['offered_by'],
    ], all(
        "SELECT l.*, o.username AS offered_by
           FROM libraries l
      LEFT JOIN users o ON o.id = l.owner_id
          WHERE l.pending_owner_id = ? AND l.is_active = 1
       ORDER BY l.pending_owner_at DESC",
        [(int) $user['id']]
    ));

    $joinable = array_map(fn($l) => library_to_api($l + [
        'n' => (int) scalar('SELECT COUNT(*) FROM items WHERE library_id = ? AND deleted_at IS NULL', [(int) $l['id']]),
    ]) + [
        // What joining would grant - access_label() on a value nobody has
        // yet, so a client can say what "Join" actually means before it's
        // pressed, the same way the real page's own column does.
        'would_get' => access_label((int) $l['public_write'] === 1 ? ACCESS_CONTRIBUTOR : ACCESS_VIEWER),
    ], joinable_libraries());

    api_ok([
        'mine'         => $mine,
        'invites'      => $invites,
        'owner_offers' => $ownerOffers,
        'joinable'     => $joinable,
    ]);
}

/**
 * A client for library_join() - taking on a published shelf. Only ever
 * grants what the library itself offers (contributor where it's open
 * to write, viewer otherwise), and never downgrades somebody already
 * holding more, the same as the real handler.
 */
function api_libraries_join(int $id): void
{
    [$user] = api_require_write();
    $lib = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($lib === null || (string) $lib['kind'] !== 'shared'
        || ((int) $lib['public_read'] !== 1 && (int) $lib['public_write'] !== 1)) {
        api_error('validation_failed', 'That library is not open to join.', 422);
    }

    $access = (int) $lib['public_write'] === 1 ? ACCESS_CONTRIBUTOR : ACCESS_VIEWER;
    $held = one('SELECT access FROM library_members WHERE library_id = ? AND user_id = ?',
                [$id, (int) $user['id']]);
    if ($held === null) {
        q('INSERT INTO library_members (library_id, user_id, access, note)
           VALUES (?, ?, ?, ?)',
          [$id, (int) $user['id'], $access, 'Joined a published library']);
        $GLOBALS['__membership_cache'] = [];
    }

    api_ok(library_to_api($lib));
}

/**
 * A client for library_leave(). Refused on a personal library or on
 * the library's own owner - the same two cases the real handler
 * refuses, since leaving either one is not a thing that makes sense.
 */
function api_libraries_leave(int $id): void
{
    [$user] = api_require_write();
    $lib = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($lib === null) {
        api_error('not_found', 'No such library.', 404);
    }
    if ((int) $lib['is_personal'] === 1 || (int) ($lib['owner_id'] ?? 0) === (int) $user['id']) {
        api_error('validation_failed', 'You cannot leave a library you own.', 422);
    }

    q('DELETE FROM library_members WHERE library_id = ? AND user_id = ?', [$id, (int) $user['id']]);
    $GLOBALS['__membership_cache'] = [];
    api_no_content();
}

/**
 * A client for the invitation half of library_admin_save()'s own
 * action=accept/decline - answering an invitation waiting on this
 * account. Whoever sent it is told either way, the same as the real
 * handler.
 */
function api_library_invite_respond(int $id, string $action): void
{
    [$user] = api_require_write();
    if (!in_array($action, ['accept', 'decline'], true)) {
        api_error('validation_failed', 'Not a real answer.', 422);
    }

    $invite = one(
        "SELECT * FROM library_members WHERE library_id = ? AND user_id = ? AND status = 'pending'",
        [$id, (int) $user['id']]
    );
    if ($invite === null) {
        api_error('not_found', 'No invitation waiting for you there.', 404);
    }

    q('UPDATE library_members SET status = ?, responded_at = NOW() WHERE library_id = ? AND user_id = ?',
      [$action === 'accept' ? 'accepted' : 'declined', $id, (int) $user['id']]);
    $GLOBALS['__membership_cache'] = [];

    $name = (string) scalar('SELECT name FROM libraries WHERE id = ?', [$id]);
    if ($invite['granted_by'] !== null) {
        notify((int) $invite['granted_by'], 'library.invite_answered', [
            'subject'      => sprintf('%s %s your invitation to %s',
                                      $user['display_name'] ?: $user['username'],
                                      $action === 'accept' ? 'accepted' : 'declined', $name),
            'link_path'    => '/libraries?edit=' . $id,
            'subject_type' => 'library',
            'subject_id'   => $id,
        ]);
    }

    api_ok(['status' => $action === 'accept' ? 'accepted' : 'declined', 'library_name' => $name]);
}

/**
 * A client for library_ownership_respond() - accepting or declining
 * an offer made to this account, or the owner withdrawing one still
 * in flight. Accepting swaps both the owning column and the two
 * membership rows that follow from it in one step, the same as the
 * real handler; the outgoing owner stays on as an admin rather than
 * being dropped to nothing.
 */
function api_library_ownership_respond(int $id, string $action): void
{
    [$user] = api_require_write();
    if (!in_array($action, ['accept', 'decline', 'withdraw'], true)) {
        api_error('validation_failed', 'Not a real answer.', 422);
    }

    $lib = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($lib === null || (int) ($lib['pending_owner_id'] ?? 0) === 0) {
        api_error('not_found', 'There is no offer outstanding for that library.', 404);
    }

    $offered = (int) $lib['pending_owner_id'];
    $amOwner = is_library_owner($user, $id);
    $amThem  = (int) $user['id'] === $offered;

    if ($action === 'withdraw' && !$amOwner) {
        api_error('forbidden', 'Only the owner can withdraw the offer.', 403);
    }
    if (in_array($action, ['accept', 'decline'], true) && !$amThem) {
        api_error('forbidden', 'That offer was not made to you.', 403);
    }

    if ($action !== 'accept') {
        update_row('libraries', $id, ['pending_owner_id' => null, 'pending_owner_at' => null]);
        api_ok(['status' => $action === 'withdraw' ? 'withdrawn' : 'declined']);
    }

    $wasOwner = (int) ($lib['owner_id'] ?? 0);
    update_row('libraries', $id, [
        'owner_id' => $offered, 'pending_owner_id' => null, 'pending_owner_at' => null,
    ]);
    q("INSERT INTO library_members (library_id, user_id, access, status, note)
       VALUES (?, ?, 'owner', 'accepted', 'Accepted ownership')
       ON DUPLICATE KEY UPDATE access = 'owner', status = 'accepted'", [$id, $offered]);
    if ($wasOwner > 0 && $wasOwner !== $offered) {
        q("UPDATE library_members SET access = 'admin' WHERE library_id = ? AND user_id = ?", [$id, $wasOwner]);
        notify($wasOwner, 'library.ownership_answered', [
            'subject'   => sprintf('"%s" now belongs to somebody else', (string) $lib['name']),
            'body'      => 'They accepted the handover. You are still a curator there.',
            'link_path' => '/profile/access',
        ]);
    }
    $GLOBALS['__membership_cache'] = [];

    api_ok(['status' => 'accepted', 'library_name' => (string) $lib['name']]);
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
    //   ?role=machine      machine kinds, peripheral kinds, game, application,
    //                      movie, tv_show, music, or other
    $rows = all_categories();

    $domain = (string) ($_GET['domain'] ?? '');
    if (in_array($domain, ['hardware', 'software', 'video', 'audio'], true)) {
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
    if (in_array($role, ['machine', 'peripheral', 'game', 'application', 'movie', 'tv_show', 'music', 'other'], true)) {
        // The role a leaf actually holds, not just what it happens to say
        // on its own row - most leaves declare nothing and inherit from
        // a branch above, the same effective_role() walk item_kind_
        // label() already does. Without this, asking "what can I file a
        // game under" found nothing at all on any library whose tree
        // declares a kind once at the top of a branch and lets the rest
        // inherit, which is the ordinary case now, not the exception.
        $rows = array_values(array_filter($rows, function ($c) use ($role) {
            $own = (string) ($c['role'] ?? 'other');
            $effective = $own !== 'other' ? $own : (category_effective_role((int) $c['id']) ?? 'other');
            return $effective === $role;
        }));
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

/** Hardware models need owner-level access, the same bar the real web screen's own require_manage() plus can_own_library() checks set - stricter than the curator level everything else in this taxonomy family uses. */
function api_require_owns_library(int $libraryId): array
{
    [$user, $token] = api_require_write();
    if (!is_admin_user(acting_user()) && !can_own_library($libraryId)) {
        api_error('forbidden', 'You can define hardware for a library you own. This is not one of them.', 403);
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
        if (!in_array($wantRole, ['other', 'machine', 'peripheral', 'game', 'application', 'movie', 'tv_show', 'music'], true)) {
            api_error('validation_failed', 'Some fields need attention.', 422,
                       ['role' => 'Not a real kind.']);
        }
        $fields['role'] = $wantRole;
        $sideSlug = match ($wantRole) {
            'machine', 'peripheral' => 'hardware',
            'game', 'application'   => 'software',
            'movie', 'tv_show'      => 'video',
            'music'                 => 'audio',
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

/**
 * What a category's own entries look like with no photograph of their
 * own - a client for store_category_default_image(), the same upload
 * machinery a user's own avatar already uses. Curator-level, the same
 * permission the tree's own structural edits already need: a picture
 * shown across every entry in a branch is closer to shaping the
 * catalogue than to describing one item in it.
 */
function api_category_image_upload(int $id): void
{
    $existing = one('SELECT * FROM categories WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No such category.', 404);
    }
    api_require_curates_library((int) $existing['library_id']);

    if (!isset($_FILES['image']) || (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        api_error('validation_failed', 'No file arrived.', 422, ['image' => 'Required.']);
    }

    [$filename, $error] = store_category_default_image($id, 'image');
    if ($error !== null) {
        api_error('validation_failed', $error, 422, ['image' => $error]);
    }

    api_ok([
        'id'    => $id,
        'image' => [
            'thumb'   => absolute_url(image_url($filename, 'thumb')),
            'display' => absolute_url(image_url($filename, 'display')),
        ],
    ]);
}

function api_category_image_delete(int $id): void
{
    $existing = one('SELECT * FROM categories WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No such category.', 404);
    }
    api_require_curates_library((int) $existing['library_id']);

    delete_category_default_image($id);
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
    // of zero to four values. Only reset when the key was actually sent,
    // matching the omitted-keeps-current-value contract every other field
    // here follows.
    if (array_key_exists('makes', $in)) {
        $picked = is_array($in['makes']) ? $in['makes'] : [];
        $picked = array_values(array_intersect(['hardware', 'software', 'video', 'audio'], $picked));
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
    $picked = array_values(array_intersect(['hardware', 'software', 'video', 'audio'], $picked));
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
        $picked = array_values(array_intersect(['hardware', 'software', 'video', 'audio'], $picked));
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
 * Environments - what a release runs under, per platform. Curator-or-
 * better, matched to require_manage() exactly - the same bar companies,
 * tags and credit roles all set, not the stricter owner-level hardware
 * models needs.
 */
function api_environments_index(): void
{
    api_require_auth();
    $libraryId = isset($_GET['library_id']) ? (int) $_GET['library_id'] : 0;
    if ($libraryId <= 0) {
        $lib = working_library();
        $libraryId = $lib === null ? 0 : (int) $lib['id'];
    }
    $platformId = isset($_GET['platform_id']) ? (int) $_GET['platform_id'] : 0;

    $sql = 'SELECT o.*, p.name AS platform_name, p.slug AS platform_slug
              FROM operating_systems o
              JOIN platforms p ON p.id = o.platform_id
             WHERE o.library_id = ?';
    $params = [$libraryId];
    if ($platformId > 0) {
        $sql .= ' AND o.platform_id = ?';
        $params[] = $platformId;
    }
    $sql .= ' ORDER BY p.name, o.name';

    api_ok(array_map('environment_to_api', all($sql, $params)));
}

function api_environments_create(): void
{
    $in = api_body();
    $libraryId = isset($in['library_id']) ? (int) $in['library_id'] : 0;
    if ($libraryId <= 0) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['library_id' => 'Choose which library this environment belongs to.']);
    }
    api_require_curates_library($libraryId);

    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Name is required.']);
    }
    $platformId = isset($in['platform_id']) ? (int) $in['platform_id'] : 0;
    $platform = one('SELECT id FROM platforms WHERE id = ? AND library_id = ?', [$platformId, $libraryId]);
    if ($platform === null) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['platform_id' => 'Choose a machine from this library.']);
    }

    $id = insert_row('operating_systems', [
        'library_id'  => $libraryId,
        'platform_id' => $platformId,
        'name'        => mb_substr($name, 0, 120),
        'slug'        => unique_slug('operating_systems', slugify($name)),
    ]);
    $row = one('SELECT o.*, p.name AS platform_name, p.slug AS platform_slug
                  FROM operating_systems o JOIN platforms p ON p.id = o.platform_id WHERE o.id = ?', [$id]);
    api_ok(environment_to_api($row), null, 201);
}

function api_environments_update(int $id): void
{
    $existing = one('SELECT * FROM operating_systems WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No environment with that id.', 404);
    }
    api_require_curates_library((int) $existing['library_id']);

    $in = api_body();
    $name = array_key_exists('name', $in) ? trim((string) $in['name']) : (string) $existing['name'];
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Name is required.']);
    }

    $data = ['name' => mb_substr($name, 0, 120), 'slug' => unique_slug('operating_systems', slugify($name), $id)];
    if (array_key_exists('platform_id', $in)) {
        $platformId = (int) $in['platform_id'];
        $platform = one('SELECT id FROM platforms WHERE id = ? AND library_id = ?',
                        [$platformId, (int) $existing['library_id']]);
        if ($platform === null) {
            api_error('validation_failed', 'Some fields need attention.', 422,
                       ['platform_id' => 'Choose a machine from this library.']);
        }
        $data['platform_id'] = $platformId;
    }

    update_row('operating_systems', $id, $data);
    $row = one('SELECT o.*, p.name AS platform_name, p.slug AS platform_slug
                  FROM operating_systems o JOIN platforms p ON p.id = o.platform_id WHERE o.id = ?', [$id]);
    api_ok(environment_to_api($row));
}

/** Refused while any entry still names it - a title pointed at a deleted environment would silently become "not applicable", a different claim from the one somebody made. */
function api_environments_delete(int $id): void
{
    $existing = one('SELECT * FROM operating_systems WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No environment with that id.', 404);
    }
    api_require_curates_library((int) $existing['library_id']);

    $used = (int) scalar('SELECT COUNT(*) FROM item_environments WHERE os_id = ?', [$id]);
    if ($used > 0) {
        api_error('validation_failed', sprintf(
            '%d entr%s still names it. Change those first.',
            $used, $used === 1 ? 'y' : 'ies'
        ), 422);
    }

    delete_row('operating_systems', $id);
    api_no_content();
}

/**
 * Hardware models - machines and the parts that go in them. Owner-level,
 * matched to the real web screen's own require_manage() plus explicit
 * can_own_library() checks throughout its body - stricter than the
 * curator level everything else in this taxonomy family uses.
 */
function api_hardware_models_index(): void
{
    api_require_auth();
    $libraryId = isset($_GET['library_id']) ? (int) $_GET['library_id'] : 0;
    if ($libraryId <= 0) {
        $lib = working_library();
        $libraryId = $lib === null ? 0 : (int) $lib['id'];
    }
    $role = isset($_GET['role']) && in_array($_GET['role'], ['machine', 'peripheral'], true) ? $_GET['role'] : null;

    $sql = "SELECT m.*, c.name AS category_name, c.slug AS category_slug, c.role AS category_role,
                   p.name AS platform_name, p.slug AS platform_slug,
                   v.name AS vendor_name
              FROM hardware_models m
              JOIN categories c ON c.id = m.category_id
         LEFT JOIN platforms p  ON p.id = m.platform_id
         LEFT JOIN companies v  ON v.id = m.vendor_id
             WHERE m.library_id = ?";
    $params = [$libraryId];
    if ($role !== null) {
        $sql .= ' AND c.role = ?';
        $params[] = $role;
    }
    $sql .= ' ORDER BY p.name, m.sort_order, m.name';

    api_ok(array_map('hardware_model_to_api', all($sql, $params)));
}

/** The enriched row a single hardware model reads as - shared by show, create, and update, each of which sends it with its own correct status code. */
function hardware_model_fetch(int $id): ?array
{
    return one(
        "SELECT m.*, c.name AS category_name, c.slug AS category_slug, c.role AS category_role,
                p.name AS platform_name, p.slug AS platform_slug,
                v.name AS vendor_name
           FROM hardware_models m
           JOIN categories c ON c.id = m.category_id
      LEFT JOIN platforms p  ON p.id = m.platform_id
      LEFT JOIN companies v  ON v.id = m.vendor_id
          WHERE m.id = ?",
        [$id]
    );
}

function api_hardware_models_show(int $id): void
{
    api_require_auth();
    $row = hardware_model_fetch($id);
    if ($row === null || !can_read_library((int) $row['library_id'])) {
        api_error('not_found', 'No hardware model with that id.', 404);
    }
    api_ok(hardware_model_to_api($row));
}

/** Shared by create and update. Validates category role (machine or peripheral, never a bare structural node) and that platform/vendor, when given, belong to the same library. */
function api_hardware_model_payload(array $in, int $libraryId, ?array $existing = null): array
{
    $field = fn(string $k) => array_key_exists($k, $in) ? $in[$k] : ($existing[$k] ?? null);
    $errors = [];

    $categoryId = (int) ($field('category_id') ?? 0);
    $category = one('SELECT id, role FROM categories WHERE id = ? AND library_id = ?', [$categoryId, $libraryId]);
    if ($category === null || !in_array($category['role'], ['machine', 'peripheral'], true)) {
        $errors['category_id'] = 'Choose a machine or peripheral kind from this library.';
    }

    $platformId = $field('platform_id');
    $platformId = ($platformId === null || (int) $platformId <= 0) ? null : (int) $platformId;
    if ($platformId !== null && one('SELECT id FROM platforms WHERE id = ? AND library_id = ?', [$platformId, $libraryId]) === null) {
        $errors['platform_id'] = 'No such platform in this library.';
    }

    $vendorId = $field('vendor_id');
    $vendorId = ($vendorId === null || (int) $vendorId <= 0) ? null : (int) $vendorId;
    if ($vendorId !== null && one('SELECT id FROM companies WHERE id = ? AND library_id = ?', [$vendorId, $libraryId]) === null) {
        $errors['vendor_id'] = 'No such company in this library.';
    }

    if ($errors !== []) {
        api_error('validation_failed', 'Some fields need attention.', 422, $errors);
    }

    $yearFrom = $field('year_from');
    return [
        'category_id' => $categoryId,
        'platform_id' => $platformId,
        'vendor_id'   => $vendorId,
        'year_from'   => ($yearFrom === null || $yearFrom === '') ? null : (int) $yearFrom,
        'fits_note'   => nullify($field('fits_note')),
        'interface'   => nullify($field('interface')),
        'notes'       => nullify($field('notes')),
        'sort_order'  => isset($in['sort_order']) ? (int) $in['sort_order'] : (int) ($existing['sort_order'] ?? 0),
    ];
}

function api_hardware_models_create(): void
{
    $in = api_body();
    $libraryId = isset($in['library_id']) ? (int) $in['library_id'] : 0;
    if ($libraryId <= 0) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['library_id' => 'Choose which library this model belongs to.']);
    }
    api_require_owns_library($libraryId);

    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Name is required.']);
    }

    $data = api_hardware_model_payload($in, $libraryId);
    $data['library_id'] = $libraryId;
    $data['name']       = mb_substr($name, 0, 160);
    $data['slug']       = unique_slug('hardware_models', slugify($name));

    $id = insert_row('hardware_models', $data);
    api_ok(hardware_model_to_api(hardware_model_fetch((int) $id)), null, 201);
}

function api_hardware_models_update(int $id): void
{
    $existing = one('SELECT * FROM hardware_models WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No hardware model with that id.', 404);
    }
    $libraryId = (int) $existing['library_id'];
    api_require_owns_library($libraryId);

    $in = api_body();
    $name = array_key_exists('name', $in) ? trim((string) $in['name']) : (string) $existing['name'];
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Name is required.']);
    }

    $data = api_hardware_model_payload($in, $libraryId, $existing);
    $data['name'] = mb_substr($name, 0, 160);
    $data['slug'] = unique_slug('hardware_models', slugify($name), $id);

    update_row('hardware_models', $id, $data);
    api_ok(hardware_model_to_api(hardware_model_fetch($id)));
}

/** Refused while any owned entry still points at it - the same "never silently lose data" guard every delete in this taxonomy family carries. */
function api_hardware_models_delete(int $id): void
{
    $existing = one('SELECT * FROM hardware_models WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No hardware model with that id.', 404);
    }
    api_require_owns_library((int) $existing['library_id']);

    $used = (int) scalar('SELECT COUNT(*) FROM items WHERE model_id = ? AND deleted_at IS NULL', [$id]);
    if ($used > 0) {
        api_error('validation_failed', sprintf(
            'Still used by %d catalogue entr%s, so it was kept. Reassign those first.',
            $used, $used === 1 ? 'y' : 'ies'
        ), 422);
    }

    delete_row('hardware_models', $id);
    api_no_content();
}

/**
 * Software models - what titles made from it start out already filled
 * in with, not an ongoing reference. Owner-level, matching the direction
 * hardware models just took rather than the real screen's own site-wide
 * admin gate - a deliberate choice for this client, not an oversight.
 */
function api_software_models_index(): void
{
    api_require_auth();
    $libraryId = isset($_GET['library_id']) ? (int) $_GET['library_id'] : 0;
    if ($libraryId <= 0) {
        $lib = working_library();
        $libraryId = $lib === null ? 0 : (int) $lib['id'];
    }
    $where  = 'm.library_id = ?';
    $params = [$libraryId];
    // Optional: the picker on an entry's own form only wants models for
    // the platform already chosen there - Amiga's own boxed-disk shapes,
    // not a VHS clamshell sitting in the same dropdown.
    if (isset($_GET['platform_id']) && (int) $_GET['platform_id'] > 0) {
        $where   .= ' AND m.platform_id = ?';
        $params[] = (int) $_GET['platform_id'];
    }
    $rows = all(
        "SELECT m.*, c.name AS category_name, c.slug AS category_slug,
                p.name AS platform_name, p.slug AS platform_slug
           FROM software_models m
      LEFT JOIN categories c ON c.id = m.category_id
      LEFT JOIN platforms p  ON p.id = m.platform_id
          WHERE $where
       ORDER BY p.name, m.sort_order, m.name",
        $params
    );
    api_ok(array_map('software_model_to_api', $rows));
}

function software_model_fetch(int $id): ?array
{
    return one(
        "SELECT m.*, c.name AS category_name, c.slug AS category_slug,
                p.name AS platform_name, p.slug AS platform_slug
           FROM software_models m
      LEFT JOIN categories c ON c.id = m.category_id
      LEFT JOIN platforms p  ON p.id = m.platform_id
          WHERE m.id = ?",
        [$id]
    );
}

function api_software_models_show(int $id): void
{
    api_require_auth();
    $row = software_model_fetch($id);
    if ($row === null || !can_read_library((int) $row['library_id'])) {
        api_error('not_found', 'No software model with that id.', 404);
    }
    api_ok(software_model_to_api($row));
}

/** Shared by create and update. Platform and category, when given, must belong to the same library - the real screen's own quiet assumption, checked here rather than trusted. */
function api_software_model_payload(array $in, int $libraryId, ?array $existing = null): array
{
    $field = fn(string $k) => array_key_exists($k, $in) ? $in[$k] : ($existing[$k] ?? null);
    $errors = [];

    $platformId = $field('platform_id');
    $platformId = ($platformId === null || (int) $platformId <= 0) ? null : (int) $platformId;
    if ($platformId !== null && one('SELECT id FROM platforms WHERE id = ? AND library_id = ?', [$platformId, $libraryId]) === null) {
        $errors['platform_id'] = 'No such platform in this library.';
    }

    $categoryId = $field('category_id');
    $categoryId = ($categoryId === null || (int) $categoryId <= 0) ? null : (int) $categoryId;
    if ($categoryId !== null && one('SELECT id FROM categories WHERE id = ? AND library_id = ?', [$categoryId, $libraryId]) === null) {
        $errors['category_id'] = 'No such category in this library.';
    }

    if ($errors !== []) {
        api_error('validation_failed', 'Some fields need attention.', 422, $errors);
    }

    return [
        'platform_id' => $platformId,
        'category_id' => $categoryId,
        'notes'       => nullify($field('notes')),
    ];
}

function api_software_models_create(): void
{
    $in = api_body();
    $libraryId = isset($in['library_id']) ? (int) $in['library_id'] : 0;
    if ($libraryId <= 0) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['library_id' => 'Choose which library this model belongs to.']);
    }
    api_require_owns_library($libraryId);

    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Name is required.']);
    }

    $data = api_software_model_payload($in, $libraryId);
    $data['library_id'] = $libraryId;
    $data['name']       = mb_substr($name, 0, 160);
    $data['slug']       = unique_slug('software_models', slugify($name));

    $id = insert_row('software_models', $data);
    api_ok(software_model_to_api(software_model_fetch((int) $id)), null, 201);
}

function api_software_models_update(int $id): void
{
    $existing = one('SELECT * FROM software_models WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No software model with that id.', 404);
    }
    $libraryId = (int) $existing['library_id'];
    api_require_owns_library($libraryId);

    $in = api_body();
    $name = array_key_exists('name', $in) ? trim((string) $in['name']) : (string) $existing['name'];
    if ($name === '') {
        api_error('validation_failed', 'Some fields need attention.', 422, ['name' => 'Name is required.']);
    }

    $data = api_software_model_payload($in, $libraryId, $existing);
    $data['name'] = mb_substr($name, 0, 160);
    $data['slug'] = unique_slug('software_models', slugify($name), $id);

    update_row('software_models', $id, $data);
    api_ok(software_model_to_api(software_model_fetch($id)));
}

/**
 * No usage guard, deliberately, matching the real screen's own choice: a
 * model is where an answer came from, not where it lives, so a title made
 * from one keeps everything it was filled in with and does not go blank
 * or get refused just because the template behind it was later removed.
 */
function api_software_models_delete(int $id): void
{
    $existing = one('SELECT * FROM software_models WHERE id = ?', [$id]);
    if ($existing === null) {
        api_error('not_found', 'No software model with that id.', 404);
    }
    api_require_owns_library((int) $existing['library_id']);

    delete_row('software_models', $id);
    api_no_content();
}

/**
 * CSV import - a real client for the engine's own import_parse()/
 * import_commit(), not a reimplementation. Dry run is the default,
 * matching the real web form's own two rules: nothing writes until the
 * whole file is read and understood, and a row with no id creates while
 * a row naming one updates. `commit=1` actually applies what the dry run
 * already showed - the same report, not a second parse that could
 * disagree with the first.
 */
function api_import_run(): void
{
    api_require_write();

    $libraryId = isset($_POST['library_id']) ? (int) $_POST['library_id'] : (isset($_GET['library_id']) ? (int) $_GET['library_id'] : 0);
    if ($libraryId <= 0 || !can_add_to_library($libraryId)) {
        api_error('validation_failed', 'Some fields need attention.', 422,
                   ['library_id' => 'Choose a library you can write to.']);
    }

    $file = $_FILES['csv'] ?? null;
    if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        api_error('validation_failed', 'Choose a CSV file to upload.', 422, ['csv' => 'Required.']);
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        api_error('validation_failed', 'That was not an upload.', 422, ['csv' => 'Not a real upload.']);
    }

    // Multipart uploads carry one file field plus a query string for
    // everything else - the same pattern item photo uploads already use,
    // since a multipart body built by a single-file helper has nowhere
    // else to put them.
    $commit       = (($_POST['commit'] ?? $_GET['commit'] ?? '')) === '1';
    $createTitles = (($_POST['create_titles'] ?? $_GET['create_titles'] ?? '')) === '1';

    $report = import_parse((string) $file['tmp_name'], $libraryId, $createTitles);

    if ($report['fatal'] !== null) {
        api_error('validation_failed', $report['fatal'], 422);
    }

    $committed = false;
    if ($commit && $report['errors'] === []) {
        import_commit($report);
        $committed = true;
    }

    api_ok([
        'committed'     => $committed,
        'create_count'  => (int) $report['create_count'],
        'update_count'  => (int) $report['update_count'],
        'errors'        => $report['errors'],
        'warnings'      => $report['warnings'],
        'row_count'     => count($report['rows']),
    ]);
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
                                    ['software', 'hardware', 'video', 'audio'], true)
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

    // One library, when asked for one - the real overview's own per-shelf
    // tabs narrow every panel at once by adding this same clause rather
    // than running a different query, so nothing here can fall out of
    // step with what the tabs promise.
    $onlyLibrary = api_query_int('library_id');
    if ($onlyLibrary !== null) {
        if (!can_read_library($onlyLibrary)) {
            api_error('forbidden', 'That library is not one you may read.', 403);
        }
        $acl .= ' AND library_id = ?';
        $aclP[] = $onlyLibrary;
        $aclI .= ' AND i.library_id = ?';
        $iP[] = $onlyLibrary;
    }

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

    // An item to search from, rather than a bare title - the same
    // derivation the real app's own lookup page does: the item's own
    // platform and domain decide what gets asked, and its own title
    // is the default when nothing else was typed. Optional, because
    // the bare search this endpoint already had is still real - not
    // every lookup starts from an existing entry.
    $itemId = api_query_int('item_id');
    $item   = null;
    if ($itemId !== null) {
        $item = find_item($itemId);
        if ($item === null || !can_read_item($item)) {
            api_error('not_found', 'No catalogue entry with that id.', 404);
        }
        if ($title === '') {
            $title = (string) ($item['title'] ?? '');
        }
    }
    if ($title === '') {
        api_error('validation_failed', 'Pass ?q= with a title to search for, or ?item_id= of an entry to search from.', 422);
    }

    $platformId = api_query_int('platform_id');
    if ($item !== null && $platformId === null && !empty($item['platform_id'])) {
        $platformId = (int) $item['platform_id'];
    }
    if ($platformId !== null && one('SELECT id FROM platforms WHERE id = ?', [$platformId]) === null) {
        api_error('validation_failed', 'No platform with that id.', 422);
    }

    // Hardware entries ask hardware sources, software entries ask
    // software ones - the same domain the item's own category already
    // answers, derived here rather than trusted from the caller so a
    // bare search cannot claim to be a hardware lookup it isn't.
    $domain = null;
    $categoryId = null;
    if ($item !== null && !empty($item['category_id'])) {
        $categoryId = (int) $item['category_id'];
        $domain = (string) (scalar('SELECT s.slug FROM categories c JOIN sections s ON s.id = c.section_id
                                     WHERE c.id = ?', [$categoryId]) ?: 'software');
    }

    $out = metadata_search_all($title, $platformId, $domain, $categoryId);
    api_ok($out['results'], [
        'query'    => $title,
        'domain'   => $domain,
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

/**
 * What a candidate would set on an entry, computed rather than left for
 * a client to work out from raw provider data - metadata_to_item_
 * fields(), metadata_to_hardware_fields(), metadata_spec_rows(), and
 * metadata_images_already_here() all already existed in core with
 * nothing exposing them; this is a client for those four functions
 * plus metadata_title_resembles(), not new logic of its own.
 *
 * The candidate itself travels in the request body rather than being
 * looked up by id, the same way the real app's own review form
 * carries it as a hidden field - a search result has no id of its own
 * to look up by, and re-running the search here to get one back would
 * mean two different searches could answer differently between a
 * preview and the apply that follows it.
 */
function api_metadata_preview(): void
{
    api_require_write();
    $in = api_body();

    $candidate = $in['candidate'] ?? null;
    if (!is_array($candidate)) {
        api_error('validation_failed', 'Send the candidate exactly as the search returned it.', 422);
    }

    $itemId = isset($in['item_id']) ? (int) $in['item_id'] : null;
    $item   = null;
    if ($itemId !== null) {
        $item = find_item($itemId);
        if ($item === null || !can_read_item($item)) {
            api_error('not_found', 'No catalogue entry with that id.', 404);
        }
    }

    $isHardware = $item !== null && (string) (scalar(
        'SELECT s.slug FROM categories c JOIN sections s ON s.id = c.section_id WHERE c.id = ?',
        [(int) $item['category_id']]
    ) ?: '') === 'hardware';

    $fields = metadata_to_item_fields($candidate);
    $hwFields = $isHardware
        ? metadata_to_hardware_fields($candidate, $item !== null ? (int) $item['platform_id'] : null)
        : [];
    $specRows = $isHardware ? metadata_spec_rows($candidate, $itemId) : [];
    $alreadyHere = metadata_images_already_here($candidate, $itemId);

    // What the entry currently has, named the same way the fields
    // above are - so a client can show "currently" beside "would
    // become" without knowing the item schema itself.
    $current = [];
    $hwCurrent = [];
    if ($item !== null) {
        $current = [
            'title' => $item['title'], 'release_year' => $item['release_year'],
            'release_date' => $item['release_date'] ?? null,
            'developer_name' => $item['developer_name'] ?? null,
            'publisher_name' => $item['publisher_name'] ?? null,
            'external_url' => $item['external_url'] ?? null,
            'description' => $item['description'] ?? null,
        ];
        if ($isHardware) {
            $hwCurrent = one('SELECT * FROM item_hardware WHERE item_id = ?', [$itemId]) ?? [];
        }
    }

    $domain = $item !== null ? ($isHardware ? 'hardware' : 'software') : null;
    $labelFor = fn(string $f) => item_field_label($f, $domain);
    $hwLabels = hardware_field_labels();

    api_ok([
        'looks_right' => metadata_title_resembles(
            (string) ($in['query'] ?? ($item['title'] ?? ($candidate['title'] ?? ''))),
            (string) ($candidate['title'] ?? '')
        ),
        'fields' => array_map(fn($f, $v) => [
            'field' => $f, 'label' => $labelFor($f), 'value' => $v, 'current' => $current[$f] ?? null,
        ], array_keys($fields), array_values($fields)),
        'hardware_fields' => array_map(fn($f, $v) => [
            'field' => $f, 'label' => $hwLabels[$f] ?? $f, 'value' => $v, 'current' => $hwCurrent[$f] ?? null,
        ], array_keys($hwFields), array_values($hwFields)),
        'spec_rows' => $specRows,
        'documents' => array_values($candidate['documents'] ?? []),
        'images' => array_map(fn($i, $img) => array_merge($img, ['already_here' => !empty($alreadyHere[(int) $i])]),
                              array_keys($candidate['images'] ?? []), array_values($candidate['images'] ?? [])),
    ]);
}

/**
 * The write side - a client for the real app's own metadata_apply(),
 * copied field by field rather than re-derived: developer/publisher
 * resolved through company_id_for_name() on the entry's own side of
 * the shop, hardware detail only for a hardware entry regardless of
 * what was posted, artwork fetched server-side with the thumbnail
 * fallback and duplicate detection the real handler already has,
 * specs merged rather than overwritten, documents kept as links only.
 */
function api_metadata_apply(): void
{
    [$user] = api_require_write();
    $in = api_body();

    $itemId = isset($in['item_id']) ? (int) $in['item_id'] : 0;
    $item = $itemId > 0 ? find_item($itemId) : null;
    if ($item === null) {
        api_error('not_found', 'No catalogue entry with that id.', 404);
    }
    if (!can_write_item($item)) {
        api_error('forbidden', 'That library is read-only for your account.', 403);
    }

    $candidate = $in['candidate'] ?? null;
    if (!is_array($candidate)) {
        api_error('validation_failed', 'Send the candidate exactly as the search returned it.', 422);
    }

    $wanted     = is_array($in['apply'] ?? null) ? array_map('strval', $in['apply']) : [];
    $wantedHw   = is_array($in['apply_hw'] ?? null) ? array_map('strval', $in['apply_hw']) : [];
    $wantedSpec = is_array($in['apply_spec'] ?? null) ? array_map('strval', $in['apply_spec']) : [];
    $wantedDoc  = is_array($in['documents'] ?? null) ? array_map('strval', $in['documents']) : [];
    $wantedArt  = is_array($in['artwork'] ?? null) ? array_map('strval', $in['artwork']) : [];

    if ($wanted === [] && $wantedHw === [] && $wantedSpec === [] && $wantedDoc === [] && $wantedArt === []) {
        api_error('validation_failed', 'Tick at least one field, image, document or hardware detail to import.', 422);
    }

    $isHardware = (string) (scalar(
        'SELECT s.slug FROM categories c JOIN sections s ON s.id = c.section_id WHERE c.id = ?',
        [(int) $item['category_id']]
    ) ?: 'software') === 'hardware';
    $applyMakes = $isHardware ? 'hardware' : 'software';

    $data = [];
    foreach (metadata_to_item_fields($candidate) as $field => $value) {
        if (!in_array($field, $wanted, true)) {
            continue;
        }
        if ($field === 'developer_name') {
            $data['developer_id'] = company_id_for_name((string) $value, $applyMakes);
        } elseif ($field === 'publisher_name') {
            $data['publisher_id'] = company_id_for_name((string) $value, $applyMakes);
        } else {
            $data[$field] = $value;
        }
    }

    $hwFields = [];
    if ($isHardware && $wantedHw !== []) {
        foreach (metadata_to_hardware_fields($candidate, (int) $item['platform_id']) as $field => $value) {
            if (in_array($field, $wantedHw, true)) {
                $hwFields[$field] = $value;
            }
        }
        if ($hwFields !== []) {
            save_item_hardware($itemId, $hwFields);
        }
    }

    if ($data !== []) {
        update_row('items', $itemId, $data);
        record_metadata_import($itemId, $candidate, $data, (int) $user['id']);
    }

    $art = 0;
    $artSame = 0;
    if ($wantedArt !== []) {
        foreach ($candidate['images'] ?? [] as $index => $image) {
            if (!in_array((string) $index, $wantedArt, true)) {
                continue;
            }
            $caption = trim((string) ($image['caption'] ?? '')) !== ''
                ? (string) $image['caption']
                : 'From ' . ($candidate['provider_label'] ?? 'a metadata source');
            [$ok, $artError, $dupe] = array_pad(metadata_import_image(
                $itemId, (string) ($image['url'] ?? ''), (string) ($image['kind'] ?? 'box_front'), $caption
            ), 3, false);
            if ($dupe) {
                $artSame++;
                continue;
            }
            if (!$ok && !empty($image['thumb_url']) && $image['thumb_url'] !== ($image['url'] ?? '')) {
                [$ok, $artError, $dupe] = array_pad(metadata_import_image(
                    $itemId, (string) $image['thumb_url'], (string) ($image['kind'] ?? 'box_front'), $caption
                ), 3, false);
                if ($dupe) {
                    $artSame++;
                    continue;
                }
            }
            if ($ok) {
                $art++;
            }
        }
    }

    $specsAdded = 0;
    if ($isHardware && $wantedSpec !== []) {
        $offered = metadata_spec_rows($candidate, $itemId);
        $chosen = array_values(array_filter($offered, fn($row) => in_array((string) $row['index'], $wantedSpec, true)));
        $specsAdded = metadata_apply_specs($itemId, $chosen);
    }

    $docs = 0;
    if ($wantedDoc !== []) {
        foreach ((array) ($candidate['documents'] ?? []) as $dx => $doc) {
            if (!in_array((string) $dx, $wantedDoc, true)) {
                continue;
            }
            if (add_item_document($itemId, (string) ($doc['name'] ?? 'Document'), (string) ($doc['url'] ?? ''),
                                  (string) ($candidate['provider_label'] ?? 'a metadata source'))) {
                $docs++;
            }
        }
    }

    log_event('metadata', 'import.applied',
        sprintf('entry %d: %d field(s), %d hardware, %d image(s), %d doc(s), %d spec row(s)',
                $itemId, count($data), count($hwFields), $art, $docs, $specsAdded),
        LOG_INFO, ['item' => $itemId, 'source' => (string) ($candidate['provider'] ?? ''),
                   'fields' => count($data), 'hardware' => count($hwFields), 'images' => $art,
                   'images_already_there' => $artSame, 'links' => $docs, 'specs' => $specsAdded]);

    api_ok([
        'fields_applied'   => count($data),
        'hardware_applied' => count($hwFields),
        'images_added'     => $art,
        'images_already_there' => $artSame,
        'documents_added'  => $docs,
        'spec_rows_added'  => $specsAdded,
        'provider_label'   => $candidate['provider_label'] ?? null,
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

    // Starting contents - the same library_populate() the web form's own
    // create page already calls, not a second copy of what it does. "It
    // starts out empty" is a real, valid answer when neither flag is sent,
    // which is why this only runs at all when asked for.
    $note = '';
    if (!empty($in['with_structure']) || !empty($in['with_examples'])) {
        $note = library_populate($id, [
            'structure' => !empty($in['with_structure']),
            'examples'  => !empty($in['with_examples']),
        ]);
    }

    $row = one('SELECT l.*, 0 AS n FROM libraries l WHERE l.id = ?', [$id]);
    api_ok(library_to_api($row), $note === '' ? null : ['note' => $note], 201);
}

/**
 * A library's own settings - name, description, kind, colour. A client
 * for the real form's own save logic, not a new one: the same guards
 * apply, in the same order, for the same reasons.
 *
 * Membership actions (invite, uninvite, changing what a member may do)
 * are not here - that half of the real form's own save handler is a
 * separate, later piece, the same way this round left library editing
 * itself for its own round after creation.
 */
function api_libraries_update(int $id): void
{
    [$user] = api_require_write();
    $library = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($library === null) {
        api_error('not_found', 'No library with that id.', 404);
    }
    if (!can_own_library($id)) {
        api_error('forbidden', 'Only an owner can change that library.', 403);
    }

    $in   = api_body();
    $name = trim((string) ($in['name'] ?? $library['name']));
    if ($name === '') {
        api_error('validation_failed', 'Give the library a name.', 422, ['name' => 'Required.']);
    }

    // A personal library cannot become shared - the one shelf the account
    // is promised, and sharing it would hand somebody else the only place
    // its owner can always write to.
    $personal = (int) ($library['is_personal'] ?? 0) === 1;
    $kindIn   = isset($in['kind']) ? (string) $in['kind'] : (string) $library['kind'];
    if ($personal && $kindIn === 'shared') {
        api_error('validation_failed', 'A personal library cannot be shared.', 422,
                   ['kind' => 'Not allowed on a personal library.']);
    }
    $kind = $personal ? 'private' : ($kindIn === 'shared' ? 'shared' : 'private');

    $visibility = (string) ($in['visibility'] ?? 'members');
    [$publicRead, $publicWrite] = library_visibility_flags($kind, $visibility);

    // Unpublishing turns the joiners out - the same rule the web form's
    // own save applies, for the same reason: "members only" while a dozen
    // uninvited people still read it is a state the library should not be
    // left in. Only the joiners; an accepted invitation always wins over
    // this.
    $wasPublic = (int) ($library['public_read'] ?? 0) === 1 || (int) ($library['public_write'] ?? 0) === 1;
    $turnedOut = 0;
    if ($wasPublic && $publicRead === 0 && $publicWrite === 0) {
        $turnedOut = (int) scalar(
            "SELECT COUNT(*) FROM library_members
              WHERE library_id = ? AND note = 'Joined a published library' AND user_id <> ?",
            [$id, (int) ($library['owner_id'] ?? 0)]
        );
        if ($turnedOut > 0) {
            q("DELETE FROM library_members
                WHERE library_id = ? AND note = 'Joined a published library' AND user_id <> ?",
              [$id, (int) ($library['owner_id'] ?? 0)]);
            $GLOBALS['__membership_cache'] = [];
        }
    }

    // Shared to private demotes anybody who could write - the kind and the
    // membership have to agree, and the membership is what acl.php actually
    // enforces. The owner keeps their own level.
    $demoted = 0;
    if ($kind === 'private' && ($library['kind'] ?? '') === 'shared') {
        $demoted = (int) scalar(
            'SELECT COUNT(*) FROM library_members WHERE library_id = ? AND user_id <> ? AND access <> ?',
            [$id, (int) $library['owner_id'], ACCESS_VIEWER]
        );
        if ($demoted > 0) {
            q('UPDATE library_members SET access = ? WHERE library_id = ? AND user_id <> ? AND access <> ?',
              [ACCESS_VIEWER, $id, (int) $library['owner_id'], ACCESS_VIEWER]);
            $GLOBALS['__membership_cache'] = [];
        }
    }

    $colourIn = trim((string) ($in['color'] ?? ''));
    $colour   = preg_match('/^#[0-9a-fA-F]{6}$/', $colourIn) === 1
                ? strtolower($colourIn) : (string) $library['accent_color'];

    update_row('libraries', $id, [
        'name'         => mb_substr($name, 0, 120),
        'slug'         => unique_slug('libraries', slugify($name), $id),
        'description'  => isset($in['description'])
                           ? (mb_substr(trim((string) $in['description']), 0, 500) ?: null)
                           : $library['description'],
        'kind'         => $kind,
        'public_read'  => $publicRead,
        'public_write' => $publicWrite,
        'accent_color' => $colour,
    ]);

    log_server('library.updated', 'Library "' . $name . '" changed', LOG_INFO,
               ['subject_type' => 'library', 'subject_id' => $id]);

    $notes = [];
    if ($turnedOut > 0) {
        $notes[] = sprintf('%d %s who had joined can no longer reach it.',
                            $turnedOut, $turnedOut === 1 ? 'person' : 'people');
    }
    if ($demoted > 0) {
        $notes[] = sprintf('%d member%s dropped to read-only.', $demoted, $demoted === 1 ? '' : 's');
    }

    $row = one('SELECT l.*, 0 AS n FROM libraries l WHERE l.id = ?', [$id]);
    api_ok(library_to_api($row), $notes === [] ? null : ['note' => implode(' ', $notes)]);
}

/**
 * A client for the owner's own delete, copied field by field from
 * library_admin_save()'s "delete" branch - the same four guards, in
 * the same order: owner only, never a personal library (the one shelf
 * every account is guaranteed, not managed from here), refused while
 * it still holds anything (deleting a library should never be a way
 * to lose a collection by accident), and refused if it's the only
 * library left on the instance. This is deliberately not the
 * administrator's force-delete this session already built elsewhere -
 * that one is a different, separate action with different guards, for
 * a different reason.
 */
function api_libraries_delete(int $id): void
{
    [$user] = api_require_write();
    if (!can_own_library($id)) {
        api_error('forbidden', 'Only the owner can delete a library.', 403);
    }

    $library = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($library === null) {
        api_error('not_found', 'No such library.', 404);
    }
    if ((int) $library['is_personal'] === 1) {
        api_error('validation_failed',
            'A personal library cannot be deleted. It is where your own things live, '
          . 'and every account has exactly one.', 422);
    }

    $count = (int) scalar('SELECT COUNT(*) FROM items WHERE library_id = ? AND deleted_at IS NULL', [$id]);
    if ($count > 0) {
        api_error('validation_failed', sprintf(
            'That library still holds %d %s. Move or delete them first - deleting a library '
          . 'should never be a way to lose a collection by accident.',
            $count, $count === 1 ? 'entry' : 'entries'
        ), 422);
    }

    if ((int) scalar('SELECT COUNT(*) FROM libraries') <= 1) {
        api_error('validation_failed', 'That is the only library. Create another before deleting this one.', 422);
    }

    $name = (string) $library['name'];
    delete_row('libraries', $id);
    log_security('library.deleted', sprintf('Library "%s" removed', $name), LOG_WARNING,
                 ['library' => (string) $library['slug']]);

    api_no_content();
}

/**
 * Add starter structure and/or examples to a library that already
 * exists - a client for the same library_populate() /libraries already
 * calls at creation, made reachable for a library that started out
 * empty. An install that answered "no" to examples, or one made before
 * this client's own create screen offered the choice at all, had no way
 * back to that decision short of reinstalling. This is that way back.
 */
/**
 * What this library holds against what there is to copy - the same
 * comparison the real edit page's own resync panel shows, read here
 * rather than assumed from the counts alone: a client offering the
 * choice needs to say what each part is worth ticking, not just that it
 * exists.
 */

/**
 * Admin-force library actions - disable/enable, forcing ownership onto
 * another account, and purging one entirely. A client for the real
 * library_admin_save()'s own four actions of that name, none of which
 * had an API before now. Deliberately separate from the owner-level
 * PATCH /libraries/{id} this client already uses: an administrator
 * acting on a library they may not even be a member of needs different
 * permission logic than an owner editing their own.
 */
function api_libraries_disable(int $id): void
{
    [$user] = api_require_write();
    $lib = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($lib === null) {
        api_error('not_found', 'No such library.', 404);
    }
    if ((int) ($lib['is_personal'] ?? 0) === 1) {
        api_error('forbidden', 'A personal shelf is not managed from here.', 403);
    }
    // Disabling is the owner's to do as well - what they get instead of
    // deleting when the instance does not allow that. Enabling stays
    // administrator-only: coming back is somebody else's decision,
    // which is what makes disabling safe to offer an owner at all.
    if (!is_library_owner($user, $id)) {
        api_require_admin();
    }
    update_row('libraries', $id, ['is_active' => 0]);
    log_security('library.disabled', sprintf('Library "%s" disabled', (string) $lib['name']),
                 LOG_WARNING, ['library' => (string) $lib['slug']]);
    api_ok(['id' => $id, 'is_active' => false]);
}

function api_libraries_enable(int $id): void
{
    api_require_admin();
    $lib = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($lib === null) {
        api_error('not_found', 'No such library.', 404);
    }
    update_row('libraries', $id, ['is_active' => 1]);
    log_security('library.enabled', sprintf('Library "%s" enabled', (string) $lib['name']),
                 LOG_WARNING, ['library' => (string) $lib['slug']]);
    api_ok(['id' => $id, 'is_active' => true]);
}

/**
 * Hand a library to somebody, without asking them. The owner's own
 * route to this is an offer that waits for acceptance; that is right
 * between two members, and wrong for an administrator sorting out a
 * library whose owner has left, waiting on an acceptance that will
 * never come from an account that has to be invited and accept first.
 * This sets owner_id directly and clears any offer in flight.
 */
function api_libraries_force_owner(int $id): void
{
    [$admin] = api_require_admin();
    $lib = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($lib === null) {
        api_error('not_found', 'No such library.', 404);
    }
    if ((int) ($lib['is_personal'] ?? 0) === 1) {
        api_error('forbidden', 'A personal shelf is not managed from here.', 403);
    }

    $in = api_body();
    $newOwnerId = isset($in['user_id']) ? (int) $in['user_id'] : 0;
    $account = $newOwnerId > 0 ? one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$newOwnerId]) : null;
    if ($account === null) {
        api_error('validation_failed', 'Pick an active account to own it.', 422, ['user_id' => 'Required, must be active.']);
    }

    update_row('libraries', $id, [
        'owner_id'         => (int) $account['id'],
        'pending_owner_id' => null,
        'pending_owner_at' => null,
    ]);
    q("INSERT INTO library_members (library_id, user_id, access, status, granted_by, granted_at)
            VALUES (?, ?, 'owner', 'accepted', ?, NOW())
       ON DUPLICATE KEY UPDATE access = 'owner', status = 'accepted'",
      [$id, (int) $account['id'], (int) $admin['id']]);
    $GLOBALS['__membership_cache'] = [];

    log_security('library.owner_forced', sprintf('Library "%s" owner set to %s by an administrator',
                 (string) $lib['name'], (string) $account['username']), LOG_WARNING, ['library' => (string) $lib['slug']]);

    api_ok(['id' => $id, 'owner_id' => (int) $account['id'], 'owner_username' => $account['username']]);
}

/**
 * Delete, forced - the administrator's version of the same button an
 * owner has, ignoring the instance's own libraries.deletable switch and
 * ignoring whether the library still holds anything. The library's own
 * name has to be typed to confirm it, the one action here that cannot
 * be walked back.
 */
function api_libraries_purge(int $id): void
{
    api_require_admin();
    $lib = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($lib === null) {
        api_error('not_found', 'No such library.', 404);
    }

    $in = api_body();
    $typed = trim((string) ($in['confirm_name'] ?? ''));
    if ($typed !== (string) $lib['name']) {
        api_error('validation_failed',
                  'Send confirm_name matching the library\'s own name exactly. Nothing was changed.',
                  422, ['confirm_name' => 'Must match the library name exactly.']);
    }

    $name = (string) $lib['name'];
    $slug = (string) $lib['slug'];
    [$ok, $message, $gone] = library_purge($id);
    if (!$ok) {
        api_error('forbidden', $message, 403);
    }

    log_security('library.purged', sprintf('Library "%s" deleted with %d entries, %d images and %d files',
                 $name, $gone['entries'] ?? 0, $gone['images'] ?? 0, $gone['files'] ?? 0),
                 LOG_WARNING, ['library' => $slug]);

    api_ok(['name' => $name, 'gone' => $gone]);
}

function api_libraries_structure_status(int $id): void
{
    api_require_write();
    if (!can_own_library($id)) {
        api_error('forbidden', 'Only an owner can change that library.', 403);
    }
    if (one('SELECT id FROM libraries WHERE id = ?', [$id]) === null) {
        api_error('not_found', 'No library with that id.', 404);
    }

    $available = structure_row_counts();
    $mine      = structure_row_counts($id);
    $mineByFile = [];
    foreach ($mine as $r) {
        $mineByFile[$r['file']] = (int) $r['n'];
    }

    api_ok(array_map(static function (array $r) use ($mineByFile): array {
        $n = $mineByFile[$r['file']] ?? 0;
        return [
            'key'       => $r['file'],
            'holds'     => $r['holds'],
            'available' => (int) $r['n'],
            'mine'      => $n,
            'behind'    => $n < (int) $r['n'],
        ];
    }, $available));
}

/**
 * Add starter structure and/or examples to a library that already
 * exists - a client for the same library_populate() /libraries already
 * calls at creation, made reachable for a library that started out
 * empty, and now carrying the full range of choices the real edit
 * page's own resync panel offers rather than the simpler all-or-nothing
 * version this endpoint first shipped with: which parts specifically
 * (makers, platforms, categories, hardware models, software models,
 * environments, locations - a location layout is a guess about
 * somebody's house and stays unticked by default, same as the real
 * form), whether to refresh from the repository first, and whether to
 * overwrite rows this library already edited rather than only add what
 * is missing.
 */
function api_libraries_populate(int $id): void
{
    api_require_write();
    if (!can_own_library($id)) {
        api_error('forbidden', 'Only an owner can change that library.', 403);
    }
    if (one('SELECT id FROM libraries WHERE id = ?', [$id]) === null) {
        api_error('not_found', 'No library with that id.', 404);
    }

    $in = api_body();
    $wantStructure = !empty($in['with_structure']);
    $wantExamples  = !empty($in['with_examples']);
    $wantRefresh   = !empty($in['refresh']);
    if (!$wantStructure && !$wantExamples && !$wantRefresh) {
        api_error('validation_failed', 'Ask for a refresh, structure, examples, or some mix.', 422);
    }

    $parts = null;
    if (isset($in['parts']) && is_array($in['parts'])) {
        $known = array_keys(seed_parts_all());
        $parts = array_fill_keys($known, false);
        foreach ($in['parts'] as $key) {
            if (in_array((string) $key, $known, true)) {
                $parts[(string) $key] = true;
            }
        }
    }

    $note = library_populate($id, [
        'refresh'   => $wantRefresh,
        'structure' => $wantStructure,
        'examples'  => $wantExamples,
        'overwrite' => !empty($in['overwrite']),
        'parts'     => $parts,
    ]);

    $row = one('SELECT l.*, 0 AS n FROM libraries l WHERE l.id = ?', [$id]);
    api_ok(library_to_api($row), ['note' => $note]);
}

/**
 * A shared example library - a client for the same
 * seed_shared_example_library() both installers already call, made
 * reachable after installation for an instance that started without
 * one, whether because the person answered no, used an older installer
 * that never asked, or is bringing up a client against an instance
 * install.php cannot be re-run on. Never touches a personal library:
 * that is the whole reason this exists rather than pointing somebody
 * at the populate endpoint instead. Idempotent, the same way the
 * installers rely on it being: an instance that already has a shared
 * library gets told so rather than a second one.
 */
function api_admin_example_library_create(): void
{
    [$me] = api_require_admin();

    if ((int) scalar("SELECT COUNT(*) FROM libraries WHERE kind = 'shared'") > 0) {
        api_error('validation_failed', 'A shared library already exists on this instance.', 422);
    }

    $libId = seed_shared_example_library((int) $me['id']);
    if ($libId <= 0) {
        api_error('validation_failed', 'Could not create it - a shared library already exists.', 422);
    }

    $row = one('SELECT l.*, 0 AS n FROM libraries l WHERE l.id = ?', [$libId]);
    api_ok(library_to_api($row), null, 201);
}

/**
 * Resend an account's own verification email - unauthenticated
 * deliberately, since the whole point is reaching somebody who cannot
 * sign in yet. A client for the real login page's own resend action,
 * matching its privacy-conscious shape exactly: the same message comes
 * back whether the account exists, needs confirming, or neither, so
 * this cannot be used to check which usernames are real. Rate limited
 * the same way a real sign-in attempt already is.
 */
function api_auth_verify_resend(): void
{
    $in       = api_body();
    $username = trim((string) ($in['username'] ?? ''));

    [$allowed, $wait] = throttle_check($username);
    if (!$allowed) {
        api_error('throttled', throttle_message($wait), 429);
    }

    $user = $username === '' ? null : one('SELECT * FROM users WHERE username = ?', [$username]);
    if ($user !== null && needs_email_verification($user)) {
        send_verification_email((int) $user['id']);
    }

    api_ok(['message' => 'If that account needs confirming, another link is on its way.']);
}

/**
 * Whether a client may show a registration form at all, and under what
 * name - a client for registration_allowed(), the same function the
 * real web sign-up form's own GET handler already calls, exposed here
 * because a client needs to know before it ever shows a username field
 * whether "closed", a wrong secret, or an already-used invitation is
 * what it's actually looking at.
 */
function api_auth_register_status(): void
{
    $route = (string) ($_GET['route'] ?? 'register');
    $token = (string) ($_GET['token'] ?? '');
    if (!in_array($route, ['register', 'join', 'invite'], true)) {
        $route = 'register';
    }

    [$ok, $whatOrWhy] = registration_allowed($route, $token);
    if (!$ok) {
        // The same answer for a wrong secret, a closed instance, and an
        // address nobody ever issued - registration_form()'s own
        // reasoning, carried through here rather than a client being
        // able to tell the three apart by which error came back.
        api_ok(['allowed' => false, 'reason' => (string) $whatOrWhy, 'invite_email' => null]);
        return;
    }
    $invite = is_array($whatOrWhy) ? $whatOrWhy : null;
    api_ok(['allowed' => true, 'reason' => null, 'invite_email' => $invite['email'] ?? null]);
}

/**
 * The account itself - a client for registration_submit()'s own logic,
 * which until now only the web form's session-based POST could reach.
 * Same validation, same create_user() call, same invite_redeem() on an
 * accepted invitation, same registration_apply_approval() afterward -
 * copied rather than re-derived, so a rule changed on one side doesn't
 * quietly drift from the other.
 */
function api_auth_register(): void
{
    $in    = api_body();
    $route = (string) ($in['route'] ?? 'register');
    $token = (string) ($in['token'] ?? '');
    if (!in_array($route, ['register', 'join', 'invite'], true)) {
        $route = 'register';
    }

    [$ok, $whatOrWhy] = registration_allowed($route, $token);
    if (!$ok) {
        api_error('forbidden', (string) $whatOrWhy, 403);
    }
    $invite = is_array($whatOrWhy) ? $whatOrWhy : null;

    $username = trim((string) ($in['username'] ?? ''));
    $password = (string) ($in['password'] ?? '');
    $confirm  = (string) ($in['password_confirm'] ?? '');
    // On an invitation the address is the one that was invited, not one
    // sent in the body - the same reasoning registration_submit() gives:
    // an invitation to one address is not an invitation for whoever
    // holds the token to sign up as somebody else.
    $email = $invite !== null ? (string) $invite['email'] : trim((string) ($in['email'] ?? ''));

    $errors = [];
    if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
        $errors['username'] = 'Username can use letters, numbers, dot, dash and underscore, '
                             . '3 to 64 characters.';
    } elseif (one('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
        $errors['username'] = 'That username is taken.';
    }
    if (strlen($password) < 10) {
        $errors['password'] = 'Use a password of at least 10 characters.';
    } elseif ($password !== $confirm) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }
    if ($invite === null && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $errors['email'] = 'An email address is required, and that one does not look like one.';
    }
    if ($errors !== []) {
        api_error('validation_failed', 'Fix the fields below and try again.', 422, $errors);
    }

    try {
        // 'user', never 'admin' - the same comment registration_submit()
        // carries: the first account on an instance is an administrator
        // because somebody has to be, the twentieth is not, whatever
        // door it came in by.
        $id = create_user($username, $password,
                          (string) ($in['display_name'] ?? $username), 'user', $email);
    } catch (InvalidArgumentException $e) {
        api_error('validation_failed', $e->getMessage(), 422, ['username' => $e->getMessage()]);
    }

    if ($invite !== null) {
        invite_redeem((int) $invite['id'], (int) $id);
    }

    log_security('register.created',
        sprintf('%s created an account by %s (api)', $username, $route), LOG_NOTICE,
        ['user' => $id, 'route' => $route]);

    $waitFor = registration_apply_approval((int) $id);
    if ($waitFor !== '') {
        log_security('register.pending',
            sprintf('%s signed up and is waiting (%s)', $username, registration_approval()),
            LOG_NOTICE, ['user' => $id]);
        notify_admins('registration.pending', [
            'subject'      => sprintf('%s is waiting for approval', $username),
            'body'         => sprintf('Signed up %s. %s', date('j M Y, H:i'), registration_approval()),
            'link_path'    => '/manage/users',
            'subject_type' => 'user',
            'subject_id'   => $id,
        ]);
        api_ok(['status' => 'pending', 'message' => $waitFor, 'token' => null], null, 201);
    }

    // Approved outright: the same token a fresh login would issue, so a
    // client doesn't have to make a second request to turn a new
    // account into a working session.
    $row = one('SELECT * FROM users WHERE id = ?', [$id]);
    set_acting_user($row);
    [$tokenId, $plain] = create_api_token((int) $id, 'API client', 'write', null, null);
    log_security('api.token.issued',
                 sprintf('Token issued to "API client" for %s, write access', $username),
                 LOG_NOTICE, ['subject_type' => 'user', 'subject_id' => $id]);

    api_ok([
        'status'     => 'active',
        'message'    => 'Welcome. Start by adding a library, then your first entry.',
        'token'      => $plain,
        'token_id'   => $tokenId,
        'token_type' => 'Bearer',
        'scope'      => 'write',
        'expires_at' => null,
        'user'       => user_to_api($row),
    ], null, 201);
}

/**
 * Every active account, by name only - who could be invited, not the
 * full administrator's own directory (api_users_index() elsewhere,
 * which needs an administrator and answers a different question). Any
 * signed-in account may see this: choosing who to invite to a library
 * you curate or own is not an administrative act, and the real app's
 * own access page never treated it as one.
 */
function api_directory_index(): void
{
    api_require_auth();
    api_ok(array_map(fn($r) => [
        'id'           => (int) $r['id'],
        'username'     => $r['username'],
        'display_name' => $r['display_name'],
    ], all('SELECT id, username, display_name FROM users WHERE is_active = 1 ORDER BY username')));
}

/**
 * Members - a client for the same three actions the real edit page's own
 * save handler already offers (invite, change access, uninvite), not new
 * ones. Owner or Library Admin, not owner alone: managing who is on the
 * shelf is curator-level work, the same split library_edit_save() itself
 * already makes between this and the library's own settings, which stay
 * owner-only.
 */
function api_library_members_index(int $libraryId): void
{
    api_require_auth();
    if (!can_administer_library($libraryId) && !is_admin()) {
        api_error('forbidden', 'That library is not one you may administer.', 403);
    }
    $rows = all(
        "SELECT m.*, u.username, u.display_name
           FROM library_members m JOIN users u ON u.id = m.user_id
          WHERE m.library_id = ?
       ORDER BY FIELD(m.access,'owner','admin','curator','editor','contributor','viewer'), u.username",
        [$libraryId]
    );
    api_ok(array_map(static fn(array $r): array => [
        'user_id'      => (int) $r['user_id'],
        'username'     => (string) $r['username'],
        'display_name' => (string) $r['display_name'],
        'access'       => (string) $r['access'],
        'status'       => (string) $r['status'],
        'granted_at'   => api_datetime($r['granted_at'] ?? null),
    ], $rows));
}

function api_library_members_invite(int $libraryId): void
{
    [$me] = api_require_write();
    $library = one('SELECT * FROM libraries WHERE id = ?', [$libraryId]);
    if ($library === null) {
        api_error('not_found', 'No library with that id.', 404);
    }
    if (!can_administer_library($libraryId) && !is_admin()) {
        api_error('forbidden', 'That library is not one you may administer.', 403);
    }

    $in    = api_body();
    $who   = isset($in['user_id']) ? (int) $in['user_id'] : null;
    $level = (string) ($in['access'] ?? ACCESS_VIEWER);
    if ($who === null || $who === (int) $me['id']) {
        api_error('validation_failed', 'Choose somebody to invite.', 422, ['user_id' => 'Required.']);
    }
    $account = one('SELECT id, username FROM users WHERE id = ? AND is_active = 1', [$who]);
    if ($account === null) {
        api_error('validation_failed', 'No active account with that id.', 422, ['user_id' => 'Not found.']);
    }
    $allowed = library_grantable_levels($library);
    if (!in_array($level, $allowed, true)) {
        api_error('validation_failed', 'That is not a level this library hands out.', 422,
                   ['access' => 'Not grantable.']);
    }

    q("INSERT INTO library_members (library_id, user_id, access, status, granted_by)
       VALUES (?, ?, ?, 'pending', ?)
       ON DUPLICATE KEY UPDATE access = VALUES(access), granted_by = VALUES(granted_by)",
      [$libraryId, $who, $level, (int) $me['id']]);
    $GLOBALS['__membership_cache'] = [];

    notify($who, 'library.invited', [
        'subject'      => sprintf('%s invited you to %s',
                                  $me['display_name'] ?: $me['username'], $library['name']),
        'body'         => sprintf(
            "You have been invited to the library \"%s\" as %s.\n\n"
            . "Nothing has changed yet - an invitation gives no access until you accept it. "
            . "You can accept or decline it from your profile.",
            $library['name'], access_label($level)
        ),
        'link_path'    => '/profile',
        'subject_type' => 'library',
        'subject_id'   => $libraryId,
        'dedupe_key'   => 'library.invited:' . $libraryId,
    ]);
    log_server('library.invited', sprintf('%s invited to "%s" as %s',
               $account['username'], $library['name'], access_label($level)), LOG_INFO,
               ['subject_type' => 'library', 'subject_id' => $libraryId]);

    api_ok(['user_id' => $who, 'access' => $level, 'status' => 'pending'], null, 201);
}

function api_library_members_update(int $libraryId, int $memberId): void
{
    [$me] = api_require_write();
    $library = one('SELECT * FROM libraries WHERE id = ?', [$libraryId]);
    if ($library === null) {
        api_error('not_found', 'No library with that id.', 404);
    }
    if (!can_administer_library($libraryId) && !is_admin()) {
        api_error('forbidden', 'That library is not one you may administer.', 403);
    }

    $in   = api_body();
    $want = (string) ($in['access'] ?? '');
    if (!in_array($want, access_levels(), true) || $want === ACCESS_NONE) {
        api_error('validation_failed', 'That is not a level this library grants.', 422,
                   ['access' => 'Not a known value.']);
    }
    if ($memberId === (int) $library['owner_id']) {
        api_error('forbidden', "The owner's own access is not set from here.", 403);
    }
    $meIsOwner = (int) $library['owner_id'] === (int) $me['id'] || is_admin();
    if ($want === ACCESS_OWNER && !$meIsOwner) {
        api_error('forbidden', 'Only the owner can hand the library to somebody else.', 403);
    }
    $row = one('SELECT * FROM library_members WHERE library_id = ? AND user_id = ?', [$libraryId, $memberId]);
    if ($row === null || (string) $row['status'] !== 'accepted') {
        api_error('validation_failed', 'That person has not accepted yet, so there is nothing to change.', 422);
    }

    q('UPDATE library_members SET access = ? WHERE library_id = ? AND user_id = ?',
      [$want, $libraryId, $memberId]);
    $GLOBALS['__membership_cache'] = [];
    log_security('library.access', sprintf('access in library %d set to %s', $libraryId, $want),
                 LOG_NOTICE, ['library' => $libraryId, 'user' => $memberId, 'access' => $want]);

    api_ok(['user_id' => $memberId, 'access' => $want]);
}

function api_library_members_delete(int $libraryId, int $memberId): void
{
    api_require_write();
    $library = one('SELECT * FROM libraries WHERE id = ?', [$libraryId]);
    if ($library === null) {
        api_error('not_found', 'No library with that id.', 404);
    }
    if (!can_administer_library($libraryId) && !is_admin()) {
        api_error('forbidden', 'That library is not one you may administer.', 403);
    }
    if ($memberId === (int) $library['owner_id']) {
        api_error('forbidden', 'The owner cannot be removed from their own library.', 403);
    }

    $name = (string) (scalar('SELECT username FROM users WHERE id = ?', [$memberId]) ?? 'They');
    q('DELETE FROM library_members WHERE library_id = ? AND user_id = ?', [$libraryId, $memberId]);
    $GLOBALS['__membership_cache'] = [];
    log_server('library.uninvited', sprintf('%s removed from "%s"', $name, $library['name']),
               LOG_NOTICE, ['subject_type' => 'library', 'subject_id' => $libraryId]);

    api_no_content();
}

/**
 * Metadata sources - configuration only, not the network half. The real
 * screen tests a source before ever adding it, with no override for
 * that check except an explicit "add it without checking" - so this API
 * requires the same flag rather than silently skipping a decision the
 * real form makes the person confirm. Probing several sources at once,
 * asking a source what platforms it knows, and matching them by name
 * all involve a live call to wherever the source actually lives and are
 * a real, separate piece for whenever that can be built against
 * something real to call.
 */
function api_metadata_providers_index(): void
{
    api_require_admin();
    $configured = all('SELECT * FROM metadata_providers ORDER BY priority, id');
    $taken = array_column($configured, 'type');
    $types = [];
    foreach (metadata_provider_types() as $key => $def) {
        $types[$key] = ['label' => (string) ($def['label'] ?? $key), 'configured' => in_array($key, $taken, true)];
    }
    api_ok(array_map(static function (array $r): array {
        return [
            'id'         => (int) $r['id'],
            'type'       => (string) $r['type'],
            'name'       => (string) $r['name'],
            'is_enabled' => (bool) $r['is_enabled'],
            'priority'   => (int) $r['priority'],
            'params'     => metadata_params($r),
            'last_error' => $r['last_error'] ?? null,
        ];
    }, $configured), ['types' => $types]);
}

/** Type default merged with a submitted override, coerced to the default's own type. */
function api_metadata_merge_params(array $defaults, array $submitted): array
{
    $params = $defaults;
    foreach (array_keys($defaults) as $key) {
        if (!array_key_exists($key, $submitted)) {
            continue;
        }
        $value = $submitted[$key];
        $params[$key] = match (true) {
            is_int($defaults[$key])   => (int) $value,
            is_float($defaults[$key]) => (float) $value,
            default                   => (string) $value,
        };
    }
    return $params;
}

function api_metadata_providers_create(): void
{
    api_require_admin();
    $in   = api_body();
    $type = (string) ($in['type'] ?? '');
    $def  = metadata_provider_definition($type);
    if ($def === null) {
        api_error('validation_failed', 'Unknown source type.', 422, ['type' => 'Not recognised.']);
    }
    if (one('SELECT id FROM metadata_providers WHERE type = ?', [$type]) !== null) {
        api_error('validation_failed', ($def['label'] ?? $type) . ' is already configured.', 422,
                   ['type' => 'Already configured.']);
    }
    // The real form always tests first and offers this as the way past a
    // failed or unreachable check; there being no way here to run that
    // check at all, the flag is required outright rather than defaulted.
    if (empty($in['skip_probe'])) {
        api_error('validation_failed',
                   'Adding a source normally tests it first. This API cannot make that call, '
                   . 'so send skip_probe: true to add it unchecked - the same escape hatch the '
                   . 'real form offers when a check fails.', 422, ['skip_probe' => 'Required.']);
    }

    $params = api_metadata_merge_params($def['params'] ?? [], (array) ($in['params'] ?? []));
    $id = (int) insert_row('metadata_providers', [
        'type'       => $type,
        'name'       => (string) $def['label'],
        'params'     => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'is_enabled' => 1,
        'priority'   => isset($in['priority']) ? (int) $in['priority'] : 100,
        'last_error' => null,
    ]);

    foreach (all('SELECT id FROM libraries') as $lib) {
        seed_library_provider_scopes((int) $lib['id']);
    }
    $mapped = metadata_seed_platform_map($id, $type);

    $row = one('SELECT * FROM metadata_providers WHERE id = ?', [$id]);
    api_ok([
        'id' => $id, 'type' => $type, 'name' => (string) $def['label'],
        'is_enabled' => true, 'priority' => (int) $row['priority'], 'params' => metadata_params($row),
    ], ['note' => $def['label'] . ' added without checking it.'
        . ($mapped > 0 ? sprintf(' %d platform mapping%s came with it.', $mapped, $mapped === 1 ? '' : 's') : '')],
       201);
}

function api_metadata_providers_update(int $id): void
{
    api_require_admin();
    $row = one('SELECT * FROM metadata_providers WHERE id = ?', [$id]);
    if ($row === null) {
        api_error('not_found', 'No source with that id.', 404);
    }
    $def = metadata_provider_definition((string) $row['type']);

    $in = api_body();
    $data = [];
    if (array_key_exists('is_enabled', $in)) {
        $data['is_enabled'] = !empty($in['is_enabled']) ? 1 : 0;
    }
    if (array_key_exists('priority', $in)) {
        $data['priority'] = (int) $in['priority'];
    }
    if (array_key_exists('params', $in) && is_array($in['params'])) {
        // The row's own current params, not the type's bare defaults - an
        // update that resends only one key must not silently reset every
        // other custom value back to what the type started with. Matches
        // the real form's own rule: what comes back is authoritative only
        // for the keys actually sent.
        $data['params'] = json_encode(
            api_metadata_merge_params(metadata_params($row), $in['params']),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
    if ($data === []) {
        api_error('validation_failed', 'Nothing to change.', 422);
    }

    update_row('metadata_providers', $id, $data);
    $fresh = one('SELECT * FROM metadata_providers WHERE id = ?', [$id]);
    api_ok([
        'id' => $id, 'type' => (string) $fresh['type'], 'name' => (string) $fresh['name'],
        'is_enabled' => (bool) $fresh['is_enabled'], 'priority' => (int) $fresh['priority'],
        'params' => metadata_params($fresh),
    ]);
}

function api_metadata_providers_delete(int $id): void
{
    api_require_admin();
    if (one('SELECT id FROM metadata_providers WHERE id = ?', [$id]) === null) {
        api_error('not_found', 'No source with that id.', 404);
    }
    delete_row('metadata_providers', $id);
    api_no_content();
}

/**
 * Directory authentication - configuration and group mapping only, the
 * same honest split as metadata sources. Unlike a source, saving a
 * directory method never requires a test at all - the real save handler
 * reaches insert_row()/update_row() with no network step in between, and
 * Test/Inspect are separate, optional actions a person may or may not
 * press. That is what makes this half fully buildable: nothing here was
 * ever gated behind a connection this environment cannot make. Testing
 * a real bind and looking up a real directory entry stay out of scope,
 * needing an actual server to answer either.
 */
function api_auth_methods_index(): void
{
    api_require_admin();
    $rows = all(
        'SELECT m.*, (SELECT COUNT(*) FROM users u WHERE u.auth_method_id = m.id) AS user_count
           FROM auth_methods m ORDER BY m.sort_order, m.id'
    );
    api_ok(array_map(static function (array $r): array {
        return [
            'id'           => (int) $r['id'],
            'type'         => (string) $r['type'],
            'name'         => (string) $r['name'],
            'description'  => $r['description'] ?? null,
            'is_enabled'   => (bool) $r['is_enabled'],
            'is_protected' => (bool) $r['is_protected'],
            'sort_order'   => (int) $r['sort_order'],
            'user_count'   => (int) $r['user_count'],
            'params'       => ldap_params($r),
        ];
    }, $rows));
}

function api_auth_methods_create(): void
{
    api_require_admin();
    $in   = api_body();
    $type = in_array($in['type'] ?? 'ldap', ['ldap', 'ad'], true) ? (string) $in['type'] : 'ldap';
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        api_error('validation_failed', 'Give the directory a name.', 422, ['name' => 'Required.']);
    }

    $params = api_metadata_merge_params(ldap_default_params($type), (array) ($in['params'] ?? []));
    $id = (int) insert_row('auth_methods', [
        'type'        => $type,
        'name'        => mb_substr($name, 0, 120),
        'description' => isset($in['description']) ? (trim((string) $in['description']) ?: null) : null,
        'params'      => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'is_enabled'  => !empty($in['is_enabled']) ? 1 : 0,
        'sort_order'  => isset($in['sort_order']) ? (int) $in['sort_order'] : 10,
    ]);

    log_security('auth.method.created', sprintf('Directory "%s" added (%s)', $name, $type), LOG_WARNING,
                 ['method' => $name, 'type' => $type, 'enabled' => !empty($in['is_enabled']) ? 1 : 0]);

    $row = one('SELECT * FROM auth_methods WHERE id = ?', [$id]);
    api_ok([
        'id' => $id, 'type' => $type, 'name' => $row['name'], 'is_enabled' => (bool) $row['is_enabled'],
        'sort_order' => (int) $row['sort_order'], 'params' => ldap_params($row),
    ], null, 201);
}

function api_auth_methods_update(int $id): void
{
    api_require_admin();
    $row = one('SELECT * FROM auth_methods WHERE id = ?', [$id]);
    if ($row === null) {
        api_error('not_found', 'No directory with that id.', 404);
    }
    $in = api_body();
    if ((int) $row['is_protected'] === 1 && array_key_exists('is_enabled', $in) && empty($in['is_enabled'])) {
        api_error('forbidden', 'The local database method cannot be disabled — it is the way back in.', 403);
    }

    $data = [];
    if (array_key_exists('name', $in)) {
        $name = trim((string) $in['name']);
        if ($name === '') {
            api_error('validation_failed', 'Give the directory a name.', 422, ['name' => 'Required.']);
        }
        $data['name'] = mb_substr($name, 0, 120);
    }
    if (array_key_exists('description', $in)) {
        $data['description'] = trim((string) $in['description']) ?: null;
    }
    if (array_key_exists('is_enabled', $in)) {
        $data['is_enabled'] = !empty($in['is_enabled']) ? 1 : 0;
    }
    if (array_key_exists('sort_order', $in)) {
        $data['sort_order'] = (int) $in['sort_order'];
    }
    if (array_key_exists('params', $in) && is_array($in['params'])) {
        // Blank bind_password means "keep the stored one" - the same rule
        // the real form applies, so saving other fields never blanks a
        // credential nobody retyped.
        $submitted = $in['params'];
        if (array_key_exists('bind_password', $submitted) && trim((string) $submitted['bind_password']) === ''
            && !empty(ldap_params($row)['bind_password'])) {
            unset($submitted['bind_password']);
        }
        $data['params'] = json_encode(
            api_metadata_merge_params(ldap_params($row), $submitted),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
    if ($data === []) {
        api_error('validation_failed', 'Nothing to change.', 422);
    }

    update_row('auth_methods', $id, $data);
    log_security('auth.method.changed', sprintf('Directory "%s" reconfigured', $data['name'] ?? $row['name']),
                 LOG_WARNING, ['method' => $data['name'] ?? $row['name'], 'type' => $row['type']]);

    $fresh = one('SELECT * FROM auth_methods WHERE id = ?', [$id]);
    api_ok([
        'id' => $id, 'type' => $fresh['type'], 'name' => $fresh['name'],
        'is_enabled' => (bool) $fresh['is_enabled'], 'sort_order' => (int) $fresh['sort_order'],
        'params' => ldap_params($fresh),
    ]);
}

/**
 * Test a directory connection without saving anything - the same
 * "prove it before committing to it" the real edit page's own Test
 * button offers, working on whatever is currently on screen rather
 * than what was last saved. An id is optional: testing a brand new,
 * not-yet-created directory works the same as testing an existing
 * one's edited-but-unsaved settings.
 */
function api_auth_methods_test(): void
{
    api_require_admin();

    $in   = api_body();
    $type = in_array($in['type'] ?? 'ldap', ['ldap', 'ad'], true) ? (string) $in['type'] : 'ldap';
    $id   = isset($in['id']) ? (int) $in['id'] : 0;

    $existing = $id > 0 ? one('SELECT * FROM auth_methods WHERE id = ?', [$id]) : null;
    $existingParams = $existing === null ? [] : ldap_params($existing);
    $params = api_metadata_merge_params(
        $existingParams === [] ? ldap_default_params($type) : $existingParams,
        (array) ($in['params'] ?? [])
    );

    [$ok, $message, $details] = ldap_test_connection([
        'id' => $id, 'type' => $type, 'params' => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    api_ok(['ok' => $ok, 'message' => $message, 'details' => $details]);
}

function api_auth_methods_delete(int $id): void
{
    api_require_admin();
    $m = one('SELECT * FROM auth_methods WHERE id = ?', [$id]);
    if ($m === null) {
        api_error('not_found', 'No such method.', 404);
    }
    if ((int) $m['is_protected'] === 1) {
        api_error('forbidden', 'The local database method cannot be removed.', 403);
    }
    if ((int) scalar('SELECT COUNT(*) FROM users WHERE auth_method_id = ?', [$id]) > 0) {
        api_error('forbidden', 'Accounts still use that method. Move or remove them first.', 403);
    }
    delete_row('auth_methods', $id);
    log_security('auth.method.deleted', sprintf('Directory "%s" removed', $m['name']), LOG_WARNING,
                 ['method' => $m['name'], 'type' => $m['type']]);
    api_no_content();
}

/**
 * Group mapping - which directory group confers which role and library
 * access, resolved at the person's own next sign-in. Pure configuration,
 * the same as the method it belongs to.
 */
function api_auth_group_maps_index(int $methodId): void
{
    api_require_admin();
    $rows = all('SELECT * FROM auth_group_map WHERE auth_method_id = ? ORDER BY priority, id', [$methodId]);
    api_ok(array_map(static function (array $r): array {
        $grants = all('SELECT library_id, access FROM auth_group_library_access WHERE group_map_id = ?', [(int) $r['id']]);
        return [
            'id'             => (int) $r['id'],
            'group_name'     => (string) $r['group_name'],
            'role'           => (string) $r['role'],
            'default_access' => (string) $r['default_access'],
            'priority'       => (int) $r['priority'],
            'library_grants' => array_map(static fn(array $g): array => [
                'library_id' => (int) $g['library_id'], 'access' => (string) $g['access'],
            ], $grants),
        ];
    }, $rows));
}

function api_auth_group_maps_create(int $methodId): void
{
    api_require_admin();
    if (one('SELECT id FROM auth_methods WHERE id = ?', [$methodId]) === null) {
        api_error('not_found', 'No directory with that id.', 404);
    }
    $in    = api_body();
    $group = trim((string) ($in['group_name'] ?? ''));
    if ($group === '') {
        api_error('validation_failed', 'Give the directory group name or DN.', 422, ['group_name' => 'Required.']);
    }
    $access = access_levels();
    $default = in_array($in['default_access'] ?? ACCESS_NONE, $access, true) ? (string) $in['default_access'] : ACCESS_NONE;

    $mapId = (int) insert_row('auth_group_map', [
        'auth_method_id' => $methodId,
        'group_name'     => mb_substr($group, 0, 512),
        'role'           => ($in['role'] ?? 'user') === 'admin' ? 'admin' : 'user',
        'default_access' => $default,
        'priority'       => isset($in['priority']) ? (int) $in['priority'] : 100,
    ]);

    foreach ((array) ($in['library_grants'] ?? []) as $libraryId => $level) {
        $libraryId = (int) $libraryId;
        if (!in_array($level, [ACCESS_VIEWER, ACCESS_CONTRIBUTOR, ACCESS_EDITOR, ACCESS_CURATOR, ACCESS_ADMIN], true)) {
            continue;
        }
        if (one('SELECT id FROM libraries WHERE id = ?', [$libraryId]) === null) {
            continue;
        }
        q('INSERT INTO auth_group_library_access (group_map_id, library_id, access)
           VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE access = VALUES(access)',
          [$mapId, $libraryId, (string) $level]);
    }

    api_ok(['id' => $mapId, 'group_name' => $group], null, 201);
}

function api_auth_group_maps_delete(int $methodId, int $mapId): void
{
    api_require_admin();
    $map = one('SELECT auth_method_id FROM auth_group_map WHERE id = ?', [$mapId]);
    if ($map === null || (int) $map['auth_method_id'] !== $methodId) {
        api_error('not_found', 'No such mapping.', 404);
    }
    delete_row('auth_group_map', $mapId);
    api_no_content();
}

/**
 * Software updates - not a stored setting, so not part of
 * settings_schema(): this is derived, read-only status, checked against
 * a real release feed rather than kept as configuration. Auto-checked
 * once a day, the same staleness rule the real settings page's own
 * "updates" tab already applies on load, so a client asking for this
 * does not have to know that rule itself.
 */
function api_admin_update_show(): void
{
    api_require_admin();

    $lastCheck = (string) setting('update_checked_at', '');
    if ($lastCheck === '' || strtotime($lastCheck) < time() - 86400) {
        check_for_update();
    }

    api_admin_update_status();
}

/** Force a check right now, regardless of the daily staleness rule. */
function api_admin_update_check(): void
{
    api_require_admin();
    check_for_update();
    api_admin_update_status();
}

function api_admin_update_status(): void
{
    $latest = setting('update_latest', '');
    api_ok([
        'current_version' => APP_VERSION,
        'latest_version'  => $latest !== '' ? $latest : null,
        'update_available' => $latest !== '' && version_compare($latest, APP_VERSION, '>'),
        'release_url'     => setting('update_url', '') ?: null,
        'checked_at'      => setting('update_checked_at', '') !== ''
            ? api_datetime(setting('update_checked_at', '')) : null,
        'error'           => setting('update_error', '') ?: null,
    ]);
}

/**
 * Instance-wide system status - performance and capacity, not
 * collection content. Administrator-only: disk paths, memory figures,
 * and database size describe the server this instance runs on, not
 * anything a library member has a reason to see.
 *
 * The request timing figure is exactly what it says and no more: how
 * long this one call took to run, measured from PHP's own start to the
 * moment this function executed a real query. It is a genuine sample,
 * not an average or a trend - there is no request log this reads from,
 * so a single slow moment here says nothing about the one before it.
 * A dashboard wanting a trend would need one built on top of this, not
 * assumed to already exist underneath it.
 */
function api_admin_system_status(): void
{
    api_require_admin();

    $requestStarted = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

    // A real query, timed, rather than assuming the connection is fast
    // because it is local. Small on purpose: this is a health check, not
    // a load test.
    $queryStart = microtime(true);
    $itemCount  = (int) scalar('SELECT COUNT(*) FROM items');
    $queryMs    = round((microtime(true) - $queryStart) * 1000, 2);

    $dbInfo = one(
        "SELECT SUM(data_length + index_length) AS bytes, COUNT(*) AS tables
           FROM information_schema.tables WHERE table_schema = DATABASE()"
    ) ?? [];

    // A handful of the tables somebody administering an instance would
    // actually want a count for, not all 54 - the ones that grow with
    // real use rather than staying at the size the starter structure
    // left them.
    $tableCounts = [];
    foreach (['items', 'users', 'libraries', 'item_images', 'titles', 'hardware_models',
              'software_models', 'companies', 'logs', 'api_tokens'] as $t) {
        $tableCounts[$t] = (int) scalar("SELECT COUNT(*) FROM `$t`");
    }

    $diskPath  = APP_ROOT;
    $diskFree  = @disk_free_space($diskPath);
    $diskTotal = @disk_total_space($diskPath);

    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : null;

    $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

    // Last 24 real hours of bucketed request data. Summed and grouped
    // here rather than handed over as raw buckets, so a client draws a
    // chart from numbers already shaped for one rather than reimplementing
    // the same aggregation queries a second time.
    $since = date('Y-m-d H:00:00', strtotime('-23 hours'));

    $totals = one(
        'SELECT SUM(request_count) AS total, SUM(total_ms) AS total_ms,
                SUM(CASE WHEN status_class = "2xx" THEN request_count ELSE 0 END) AS ok2xx,
                SUM(CASE WHEN status_class = "3xx" THEN request_count ELSE 0 END) AS ok3xx,
                SUM(CASE WHEN status_class = "4xx" THEN request_count ELSE 0 END) AS err4xx,
                SUM(CASE WHEN status_class = "5xx" THEN request_count ELSE 0 END) AS err5xx
           FROM api_request_stats WHERE bucket_hour >= ?', [$since]
    ) ?? [];
    $totalRequests = (int) ($totals['total'] ?? 0);

    $bySource = all(
        'SELECT source, SUM(request_count) AS n FROM api_request_stats
          WHERE bucket_hour >= ? GROUP BY source ORDER BY n DESC', [$since]
    );

    $topRoutes = all(
        'SELECT method, route, SUM(request_count) AS n, SUM(total_ms) AS total_ms, MAX(max_ms) AS max_ms
           FROM api_request_stats WHERE bucket_hour >= ?
       GROUP BY method, route ORDER BY n DESC LIMIT 10', [$since]
    );

    // The busiest routes above answer "what does most of the traffic
    // hit" - this answers a genuinely different question, "what's slow
    // when it runs at all." A route with only a handful of calls but a
    // real average worth knowing about would never surface in the
    // volume-sorted list above; min_calls exists so one single slow
    // outlier call doesn't crowd out routes that are consistently slow
    // rather than occasionally spiked.
    $slowRoutes = all(
        'SELECT method, route, SUM(request_count) AS n, SUM(total_ms) AS total_ms, MAX(max_ms) AS max_ms
           FROM api_request_stats WHERE bucket_hour >= ?
       GROUP BY method, route HAVING SUM(request_count) >= 5
       ORDER BY (SUM(total_ms) / SUM(request_count)) DESC LIMIT 10', [$since]
    );

    // One row per hour for the last 24, zero-filled where nothing was
    // recorded - a chart with a gap in it looks like the data went
    // missing rather than that nothing happened that hour.
    $hourly = [];
    foreach (all(
        'SELECT bucket_hour, SUM(request_count) AS n,
                SUM(CASE WHEN status_class IN ("4xx","5xx") THEN request_count ELSE 0 END) AS errors
           FROM api_request_stats WHERE bucket_hour >= ? GROUP BY bucket_hour', [$since]
    ) as $row) {
        $hourly[$row['bucket_hour']] = ['requests' => (int) $row['n'], 'errors' => (int) $row['errors']];
    }
    $timeline = [];
    for ($h = 23; $h >= 0; $h--) {
        $bucket = date('Y-m-d H:00:00', strtotime("-$h hours"));
        $timeline[] = [
            'hour'     => api_datetime($bucket),
            'requests' => $hourly[$bucket]['requests'] ?? 0,
            'errors'   => $hourly[$bucket]['errors'] ?? 0,
        ];
    }

    // The last 3 real hours, five minutes at a time - close enough to
    // watch traffic move rather than only ever seeing it a full hour
    // after it happened. By source rather than by route: the question
    // this resolution answers well is "how much traffic, from where,
    // right now" - which endpoint stays the hourly timeline's own
    // question, where an hour's worth of calls is enough per route to
    // mean something.
    $since5m = date('Y-m-d H:i:00', (int) (floor((strtotime('now') - 3 * 3600) / 300) * 300));
    $recentRows = all(
        'SELECT bucket_5m, source, status_class, SUM(request_count) AS n
           FROM api_request_stats_5m WHERE bucket_5m >= ? GROUP BY bucket_5m, source, status_class',
        [$since5m]
    );
    $recentBuckets = [];
    $sourcesSeen = [];
    foreach ($recentRows as $r) {
        $b = $r['bucket_5m'];
        $recentBuckets[$b]['total'] = ($recentBuckets[$b]['total'] ?? 0) + (int) $r['n'];
        if (in_array($r['status_class'], ['4xx', '5xx'], true)) {
            $recentBuckets[$b]['errors'] = ($recentBuckets[$b]['errors'] ?? 0) + (int) $r['n'];
        }
        $recentBuckets[$b]['by_source'][$r['source']] = ($recentBuckets[$b]['by_source'][$r['source']] ?? 0) + (int) $r['n'];
        $sourcesSeen[$r['source']] = true;
    }
    $recentTimeline = [];
    for ($m = 35; $m >= 0; $m--) {
        $bucket = date('Y-m-d H:i:00', (int) (floor((strtotime('now') - $m * 300) / 300) * 300));
        $row = $recentBuckets[$bucket] ?? ['total' => 0, 'errors' => 0, 'by_source' => []];
        $recentTimeline[] = [
            'bucket'    => api_datetime($bucket),
            'requests'  => (int) $row['total'],
            'errors'    => (int) ($row['errors'] ?? 0),
            'by_source' => $row['by_source'] ?? [],
        ];
    }

    api_ok([
        'php' => [
            'version'          => PHP_VERSION,
            'memory_used_mb'   => round(memory_get_usage(true) / 1048576, 2),
            'memory_peak_mb'   => round(memory_get_peak_usage(true) / 1048576, 2),
            'memory_limit'     => ini_get('memory_limit'),
            'opcache_enabled'  => $opcache !== false && !empty($opcache['opcache_enabled']),
        ],
        'system' => [
            // Null on platforms without a load average at all (Windows;
            // some containers), rather than a made-up zero that reads as
            // "idle" when it actually means "unknown".
            'load_average' => $load === false || $load === null ? null : [
                '1min' => round($load[0], 2), '5min' => round($load[1], 2), '15min' => round($load[2], 2),
            ],
            'disk_free_gb'  => $diskFree === false ? null : round($diskFree / 1073741824, 2),
            'disk_total_gb' => $diskTotal === false ? null : round($diskTotal / 1073741824, 2),
            'disk_used_pct' => ($diskFree === false || $diskTotal === false || $diskTotal <= 0)
                ? null : round((1 - $diskFree / $diskTotal) * 100, 1),
        ],
        'database' => [
            'size_mb'    => isset($dbInfo['bytes']) && $dbInfo['bytes'] !== null ? round((float) $dbInfo['bytes'] / 1048576, 2) : null,
            'tables'     => isset($dbInfo['tables']) ? (int) $dbInfo['tables'] : null,
            'item_count' => $itemCount,
            'table_counts' => $tableCounts,
        ],
        'request' => [
            // From PHP's own start (as close to "the request arrived" as
            // this process can say) to the query above returning -
            // routing, session start, auth, and one real query, the same
            // path every ordinary page takes before it does its own work.
            'sampled_at_ms' => round((microtime(true) - $requestStarted) * 1000, 2),
            'query_ms'      => $queryMs,
        ],
        'app_version' => APP_VERSION,
        'server_time' => api_datetime(date('Y-m-d H:i:s')),
        'requests' => [
            // The last real 24 hours, not calendar-day buckets - "today"
            // means something different at 1am than at 11pm, and a
            // rolling window is the same answer regardless of when the
            // page happens to be opened.
            'window_hours'   => 24,
            'total'          => $totalRequests,
            'avg_ms'         => $totalRequests > 0 ? round(((int) ($totals['total_ms'] ?? 0)) / $totalRequests, 1) : null,
            'by_status' => [
                '2xx' => (int) ($totals['ok2xx'] ?? 0), '3xx' => (int) ($totals['ok3xx'] ?? 0),
                '4xx' => (int) ($totals['err4xx'] ?? 0), '5xx' => (int) ($totals['err5xx'] ?? 0),
            ],
            'by_source' => array_map(fn($r) => ['source' => $r['source'], 'count' => (int) $r['n']], $bySource),
            'top_routes' => array_map(fn($r) => [
                'method' => $r['method'], 'route' => $r['route'], 'count' => (int) $r['n'],
                'avg_ms' => round((int) $r['total_ms'] / max(1, (int) $r['n']), 1),
                'max_ms' => (int) $r['max_ms'],
            ], $topRoutes),
            'slow_routes' => array_map(fn($r) => [
                'method' => $r['method'], 'route' => $r['route'], 'count' => (int) $r['n'],
                'avg_ms' => round((int) $r['total_ms'] / max(1, (int) $r['n']), 1),
                'max_ms' => (int) $r['max_ms'],
            ], $slowRoutes),
            'timeline' => $timeline,
            'recent' => [
                // Not a general-purpose window like the one above -
                // fixed at the last 3 hours, 5-minute buckets, because
                // that is exactly what api_request_stats_5m is pruned
                // down to keep, and this endpoint has never claimed to
                // show a client more than the data behind it actually
                // holds.
                'window_minutes' => 180,
                'bucket_minutes' => 5,
                'sources'        => array_keys($sourcesSeen),
                'timeline'       => $recentTimeline,
            ],
        ],
    ]);
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
    $item = find_item($itemId);
    if ($item === null || !can_read_library((int) $item['library_id'])) {
        api_error('not_found', 'No catalogue entry with that id.', 404);
    }

    // The same asymmetry api_link_refusal() itself enforces: it wants
    // the machine first and the peripheral second, always in that
    // order, so a call from a peripheral's own edit page has to swap
    // which side each row plays rather than the item being edited
    // always standing in as "the machine". Without this, editing a
    // peripheral and asking what it could be installed in refused
    // every real machine outright - the check ran backwards, testing
    // whether each candidate machine was a peripheral fitting into
    // this peripheral, which is never true.
    $direction = ($_GET['direction'] ?? 'contains') === 'inside' ? 'inside' : 'contains';

    [$acl, $aclP] = library_filter_sql('library_id', ACCESS_VIEWER);
    $rows = all("SELECT * FROM v_items
                  WHERE id <> ? AND deleted_at IS NULL AND $acl
               ORDER BY title", array_merge([$itemId], $aclP));

    $out = [];
    foreach ($rows as $row) {
        $machine = $direction === 'contains' ? $item : $row;
        $part    = $direction === 'contains' ? $row : $item;
        if (api_link_refusal($machine, $part) === null) {
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
