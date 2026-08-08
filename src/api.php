<?php
declare(strict_types=1);

/**
 * REST API plumbing: authentication, JSON envelopes, CORS, and the serialisers
 * that decide exactly what a native client receives.
 *
 * A note on "the real form", "the real screen" and "the engine's own screen".
 *
 * Those phrases appear throughout this file and mean one thing: the web
 * interface this application used to serve itself, which the API was extracted
 * from and which has since been deleted. They are worth reading as history - a
 * rule described as matching "what the real form does" was checked against that
 * form when it was written, and the form is no longer there to check against.
 *
 * Left rather than rewritten because each one still says something true about
 * why the rule is what it is. Renamed in place, they would have said less.
 */

const API_VERSION = '1.0.0';

// --- URLs -------------------------------------------------------------------

/**
 * Absolute base URL. Native clients cannot resolve relative image paths, so
 * every URL the API emits is absolute.
 */
function base_url(): string
{
    $configured = config('base_url');
    if (is_string($configured) && $configured !== '') {
        return rtrim($configured, '/');
    }
    return (request_is_https() ? 'https://' : 'http://') . request_host() . BASE_PATH;
}

function absolute_url(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    return base_url() . '/' . ltrim(substr($path, strlen(BASE_PATH)), '/');
}

// --- Responses --------------------------------------------------------------

function api_send($payload, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Api-Version: ' . API_VERSION);
    header('Cache-Control: no-store');
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

/** Success envelope. Collections also carry a meta block. */
function api_ok($data, ?array $meta = null, int $status = 200, array $headers = []): never
{
    $body = ['data' => $data];
    if ($meta !== null) {
        $body['meta'] = $meta;
    }
    api_send($body, $status, $headers);
}

function api_error(string $code, string $message, int $status = 400, array $details = []): never
{
    $error = ['code' => $code, 'message' => $message];
    if ($details !== []) {
        $error['details'] = $details;
    }

    // Recorded, because until now the API was invisible.
    //
    // Every refusal the app ever received - a bad token, a field the server did
    // not like, an entry that is not there - happened without a line anywhere.
    // An operator watching the log while somebody said "the app will not save"
    // saw nothing at all, which is the worst possible answer to that sentence.
    api_log_refusal($code, $message, $status, $details);

    api_send(['error' => $error], $status);
}

/**
 * One line per refusal.
 *
 * Refusals about who you are go in the security stream, because that is where
 * somebody looks after "why can this phone not sign in". Everything else is the
 * server stream. Severity follows how much it matters: a 5xx is the server's
 * fault, a 401 or 403 is worth noticing, a 422 is somebody typing.
 */
function api_log_refusal(string $code, string $message, int $status, array $details = []): void
{
    if (!function_exists('log_security')) {
        return;
    }

    $security = in_array($status, [401, 403, 429], true);
    $severity = $status >= 500 ? LOG_ERR : ($security ? LOG_WARNING : LOG_NOTICE);

    $path   = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '?');

    // The fields it complained about, named. "Some fields need attention" in a
    // log is the same non-answer it is on a screen.
    $fields = '';
    if ($details !== []) {
        $named = array_slice(array_keys($details), 0, 6);
        $fields = ' (' . implode(', ', array_map('strval', $named)) . ')';
    }

    $line = sprintf('%s %s refused %d %s: %s%s',
                    $method, $path, $status, $code, $message, $fields);

    if ($security) {
        log_security('api.refused', $line, $severity);
    } else {
        log_server('api.refused', $line, $severity);
    }
}

function api_no_content(): never
{
    http_response_code(204);
    header('X-Api-Version: ' . API_VERSION);
    exit;
}

// --- Request parsing --------------------------------------------------------

/** Body as an array, whether it arrived as JSON or as form fields. */
function api_body(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($type, 'application/json') !== false) {
        $raw = file_get_contents('php://input') ?: '';
        if (trim($raw) === '') {
            return $cached = [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            api_error('invalid_json', 'The request body is not valid JSON.', 400);
        }
        return $cached = $decoded;
    }
    return $cached = $_POST;
}

function api_query_int(string $key, ?int $default = null): ?int
{
    $v = $_GET[$key] ?? null;
    return (is_string($v) && is_numeric($v)) ? (int) $v : $default;
}

// --- CORS -------------------------------------------------------------------

/**
 * Native apps ignore CORS entirely; this exists for browser-based clients and
 * for local development against a separate front end.
 */
function api_cors(): void
{
    // Default empty, not ['*']: the fallback here is what applies when the key is
    // absent from an older config.local.php, so leaving '*' as the default would
    // have quietly kept the wildcard for every existing install.
    $allowed = (array) config('api.cors_origins', []);
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (in_array('*', $allowed, true)) {
        header('Access-Control-Allow-Origin: *');
    } elseif ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
    // Always, not only on a match. The response body differs by Origin, so a
    // cache that stored the no-header version could otherwise serve it to an
    // allowed origin, and the reverse.
    header('Vary: Origin');

    header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, If-None-Match, X-Requested-With');
    header('Access-Control-Expose-Headers: ETag, X-Total-Count, X-Api-Version');
    header('Access-Control-Max-Age: 86400');
}

// --- Tokens -----------------------------------------------------------------

function generate_token(): string
{
    return 'rvt_' . bin2hex(random_bytes(24));
}

function token_hash(string $plaintext): string
{
    return hash('sha256', $plaintext);
}

/**
 * Create a token and return [id, plaintext]. The plaintext is never stored and
 * is the only time it can be shown.
 */
function create_api_token(int $userId, string $name, string $scope = 'write', ?string $platform = null, ?string $expiresAt = null): array
{
    $plain = generate_token();
    $id = insert_row('api_tokens', [
        'user_id'    => $userId,
        'name'       => mb_substr($name, 0, 120),
        'token_hash' => token_hash($plain),
        'prefix'     => substr($plain, 0, 12),
        'scope'      => in_array($scope, ['read', 'write'], true) ? $scope : 'write',
        'platform'   => $platform,
        'expires_at' => $expiresAt,
    ]);
    return [$id, $plain];
}

/** Pull the bearer token out of the request, coping with awkward SAPIs. */
function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $header = $v;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(\S+)$/i', trim((string) $header), $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Resolve the caller. A bearer token wins; otherwise an existing web session is
 * accepted, so the same endpoints serve the browser without a second auth path.
 *
 * Returns [user, tokenRow|null] or null.
 */
/**
 * Who is calling, and when nobody is, why not.
 *
 * `$why` exists because five different failures used to produce one sentence:
 * no header at all, a token nobody has heard of, a revoked one, an expired one,
 * and an account since disabled. "Send a valid bearer token in the Authorization
 * header" is true of all five and useful for none - it sends somebody to check
 * the header when the header was fine and the token was revoked.
 *
 * The distinction is safe to publish. It says nothing about which token exists,
 * only what happened to the one presented, which the presenter already holds.
 */
function api_identify(?string &$why = null): ?array
{
    $plain = bearer_token();
    if ($plain !== null) {
        $token = one(
            'SELECT * FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL',
            [token_hash($plain)]
        );
        if ($token === null) {
            $why = 'That token is not recognised. It may have been revoked - '
                 . 'check App access on the web.';
            return null;
        }
        if ($token['expires_at'] !== null && strtotime((string) $token['expires_at']) < time()) {
            $why = 'That token expired on '
                 . date('j M Y', (int) strtotime((string) $token['expires_at'])) . '.';
            return null;
        }
        $user = one('SELECT id, username, display_name, avatar_filename, avatar_pending_filename, email, role, auth_method_id, is_active FROM users WHERE id = ? AND is_active = 1', [(int) $token['user_id']]);
        if ($user === null) {
            $why = 'The account that token belongs to is closed or disabled.';
            return null;
        }
        // Worth recording for spotting a lost device, but a syncing phone makes
        // several calls a minute and this only matters at minute granularity.
        $lastSeen = $token['last_used_at'] === null ? 0 : (int) strtotime((string) $token['last_used_at']);
        if (time() - $lastSeen > 60) {
            q('UPDATE api_tokens SET last_used_at = NOW(), last_used_ip = ? WHERE id = ?', [
                substr(client_ip(), 0, 45),
                (int) $token['id'],
            ]);
        }
        set_acting_user($user);
        return [$user, $token];
    }

    $user = current_user();
    if ($user === null) {
        // Nothing arrived. Naming the proxy is not idle: this instance sits
        // behind one, and a header that leaves the client and does not arrive is
        // the hardest of these to reason about from either end.
        $why = 'No Authorization header reached the server, and there is no session '
             . 'either. If a proxy sits in front of this instance, it may be dropping it.';
        return null;
    }
    return [$user, null];
}

function api_require_auth(): array
{
    $why = null;
    $identity = api_identify($why);
    if ($identity === null) {
        api_error('unauthenticated',
                  $why ?? 'Send a valid bearer token in the Authorization header.', 401);
    }
    return $identity;
}

/**
 * The two checks that apply to any state-changing call, whoever makes it.
 *
 * Split out of api_require_write() so the administrator gate can apply exactly
 * these and not the library-membership check, which has nothing to do with
 * administering an instance. See api_require_admin().
 */
function api_guard_mutation(?array $token): void
{
    // A session-authenticated write is a browser request, and a browser
    // request that carries no proof of intent is a CSRF. SameSite=Lax happens
    // to block the form-post case today, but that is a browser default rather
    // than something this application decided, so say it here instead.
    if ($token === null && !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) {
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $sent   = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        // An Origin header is scheme://host[:port] and never carries a path,
        // while base_url() ends with BASE_PATH. Comparing them whole meant that
        // on any install in a subdirectory this could not match, so the branch
        // was dead and every session write fell through to the token check.
        $expected   = (request_is_https() ? 'https://' : 'http://') . request_host();
        $sameOrigin = $origin !== '' && rtrim($origin, '/') === $expected;
        $goodToken  = is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
        if (!$sameOrigin && !$goodToken) {
            api_error(
                'forbidden',
                'A write authenticated by session cookie needs a CSRF token in X-Csrf-Token, or a same-origin request.',
                403
            );
        }
    }

    // A read-only token may read anything its holder may read. It is only a
    // write it cannot do - which is why this is asked per method rather than
    // per endpoint. Asking it of every admin call meant the admin *pages*, all
    // of them GETs, were refused to a read token that was entitled to them.
    if ($token !== null && $token['scope'] === 'read'
        && !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) {
        api_error('forbidden', 'This token was issued with read-only scope.', 403);
    }
}

/** Authenticated, allowed to write, and not using a read-only token. */
function api_require_write(): array
{
    [$user, $token] = api_require_auth();

    api_guard_mutation($token);

    // The role no longer decides this. Membership does, and can_edit_anything()
    // reads it from library_members.
    if (!can_edit_anything()) {
        api_error('forbidden', 'This account has no library it is allowed to change.', 403);
    }
    if ($token !== null && $token['scope'] === 'read') {
        api_error('forbidden', 'This token was issued with read-only scope.', 403);
    }
    return [$user, $token];
}

/**
 * Administering the instance, which is not the same thing as writing to a
 * library and must not be gated on it.
 *
 * This used to call api_require_write() first, and that was wrong in a way that
 * only showed up once accounts started arriving from a directory. Library access
 * is membership and nothing else - acl.php says so deliberately, so that an
 * administrator does not silently acquire the ability to read everybody's
 * private shelves. But an account promoted to administrator by an LDAP group
 * mapping has no membership anywhere, so can_edit_anything() was false, so every
 * instance-level endpoint - users, maintenance, settings, the log - answered 403
 * "This account has no library it is allowed to change" to a genuine
 * administrator. The web interface showed the admin menus, because it asks the
 * role; the API refused them, because it asked membership. The two disagreed and
 * the message named the wrong thing entirely.
 *
 * So: authenticated, an administrator, and subject to the same CSRF and
 * write-scope rules as any other call. Membership is not consulted, because none
 * of these endpoints touch a library's contents.
 */
function api_require_admin(): array
{
    [$user, $token] = api_require_auth();
    api_guard_mutation($token);
    if (!is_admin_user($user)) {
        api_error('forbidden', 'Administrator access is required.', 403);
    }
    return [$user, $token];
}

// --- Tombstones -------------------------------------------------------------

/**
 * Leave a marker so an offline client learns about a deletion.
 *
 * $libraryId decides who is told: sync withholds tombstones for libraries the
 * caller cannot read, so a phone never even learns that a hidden entry existed.
 */
function record_tombstone(string $entity, int $id, ?int $libraryId = null): void
{
    insert_row('tombstones', [
        'entity'     => $entity,
        'entity_id'  => $id,
        'library_id' => $libraryId,
    ]);
}

// --- Serialisers ------------------------------------------------------------

/**
 * ISO 8601 in UTC with a Z suffix.
 *
 * Deliberately not date('c'): that emits "+02:00", and a client that drops the
 * value straight into a query string without encoding it gets "%20" for the
 * plus, which no date parser accepts. Z has nothing to mangle.
 */
function api_datetime(?string $value): ?string
{
    if ($value === null || $value === '' || str_starts_with($value, '0000')) {
        return null;
    }
    $ts = strtotime($value);
    return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
}

/** Parse a client-supplied timestamp, repairing the unencoded-plus case. */
function api_parse_datetime(string $value): ?int
{
    $value = trim($value);
    $ts = strtotime($value);
    if ($ts === false && preg_match('/^(.*\d{2}:\d{2}:\d{2}) (\d{2}:\d{2})$/', $value, $m)) {
        $ts = strtotime($m[1] . '+' . $m[2]);   // "+02:00" arrived as " 02:00"
    }
    return $ts === false ? null : $ts;
}

function image_to_api(array $row): array
{
    return [
        'id'            => (int) $row['id'],
        'item_id'       => (int) $row['item_id'],
        'kind'          => $row['kind'],
        'kind_label'    => image_kind_label($row['kind']),
        // Where it came from, which is the other half of what a picture is. A
        // client with only `kind` cannot tell the publisher's box art from a
        // photograph somebody took of their own shelf - and those answer
        // different questions.
        'provenance'    => $row['provenance'] ?? 'personal',
        // Whether anybody but a curator can see it yet. 'approved' on every row
        // of a library that has never switched approval on, which is the column
        // default, so a client that ignores this key is right by default.
        'approval_state' => $row['approval_state'] ?? 'approved',
        'uploaded_by'   => ($row['uploaded_by'] ?? null) === null ? null : (int) $row['uploaded_by'],
        'caption'       => $row['caption'],
        'is_primary'    => (bool) (int) $row['is_primary'],
        'sort_order'    => (int) $row['sort_order'],
        'width'         => $row['width'] === null ? null : (int) $row['width'],
        'height'        => $row['height'] === null ? null : (int) $row['height'],
        'filesize'      => $row['filesize'] === null ? null : (int) $row['filesize'],
        'original_name' => $row['original_name'] ?? null,
        'urls'          => [
            'thumb'    => absolute_url(image_url($row['filename'], 'thumb')),
            'display'  => absolute_url(image_url($row['filename'], 'display')),
            'original' => absolute_url(image_url($row['filename'], 'orig')),
        ],
        'created_at'    => api_datetime($row['created_at'] ?? null),
    ];
}

/**
 * A catalogue entry as clients see it. Nested objects rather than bare foreign
 * keys, because a phone showing a list should not need five extra requests.
 */
function item_to_api(array $r, bool $withImages = false): array
{
    // The hardware half, from its own table.
    //
    // item_hardware is keyed by item_id and v_items does not carry it, so this
    // is a query rather than a column read - and only on the detailed view,
    // because a list of two hundred entries does not need two hundred extra
    // round trips for fields no list shows.
    $hardware = null;
    if ($withImages) {
        $hw = one('SELECT * FROM item_hardware WHERE item_id = ?', [(int) $r['id']]);
        $hardware = $hw === null ? null : [
            'model'          => $hw['model'],
            'board_revision' => $hw['board_revision'],
            'firmware'       => $hw['firmware'],
            'serial_number'  => $hw['serial_number'],
            'modifications'  => $hw['modifications'],
            'working_state'  => $hw['working_state'],
            'interface'      => $hw['interface'],
            'provides'       => $hw['provides'],
            'fits'           => $hw['fits'],
            'recapped_on'    => $hw['recapped_on'],
            'serviced_on'    => $hw['serviced_on'],
            'manufactured_year' => $hw['manufactured_year'] === null
                ? null : (int) $hw['manufactured_year'],
            // Decoded, not handed over as a string. A client that has to parse
            // JSON out of a JSON field is being asked to do the same work twice.
            'specs'          => $hw['specs'] === null
                ? [] : (json_decode((string) $hw['specs'], true) ?: []),
        ];
    }

    $out = [
        'id'       => (int) $r['id'],
        'title'    => $r['title'],
        'subtitle' => $r['subtitle'],
        'sort_title' => $r['sort_title'],

        // The library owns the entry and decides who may see it. The platform
        // is what the entry runs on. These used to be the same key, which is
        // how the ACL ended up filtering on the wrong column.
        'library' => [
            'id'    => (int) $r['library_id'],
            'name'  => $r['library_name'],
            'slug'  => $r['library_slug'],
            'color' => $r['library_color'],
        ],
        'platform' => [
            'id'    => (int) $r['platform_id'],
            'name'  => $r['platform_name'],
            'slug'  => $r['platform_slug'],
            'color' => $r['platform_color'],
        ],
        'title_ref' => $r['title_id'] === null ? null : [
            'id'       => (int) $r['title_id'],
            'name'     => $r['title_name'],
            'slug'     => $r['title_slug'],
            'work_key' => $r['title_work_key'],
            'synopsis' => $r['title_synopsis'],
        ],
        'model' => $r['model_id'] === null ? null : [
            'id'   => (int) $r['model_id'],
            'name' => $r['model_name'],
            'slug' => $r['model_slug'],
        ],
        'domain' => $r['domain'],
        'category' => [
            'id'   => (int) $r['category_id'],
            'name' => $r['category_name'],
            'slug' => $r['category_slug'],
        ],
        // What kind of thing this is - a game, an application, a
        // machine, a peripheral - the same real distinction the table
        // view's own Kind column needs, not otherwise derivable from
        // what this response already carries: it depends on the
        // category's own role, inherited up the tree when a leaf
        // hasn't declared one itself.
        'kind'       => item_kind_label($r),
        'kind_label' => item_kind_display_label(item_kind_label($r)),
        // No 'genre' key. A genre is a category - "Games > Racing" is a leaf like any
        // other - so it is reported once, under 'category', with its full path.
        'developer' => $r['developer_id'] === null ? null : [
            'id'      => (int) $r['developer_id'],
            'name'    => $r['developer_name'],
            'slug'    => $r['developer_slug'],
            'website' => $r['developer_website'] ?? null,
            'logo'    => absolute_url(image_url($r['developer_logo'] ?? null, 'thumb')),
        ],
        'publisher' => $r['publisher_id'] === null ? null : [
            'id'   => (int) $r['publisher_id'],
            'name' => $r['publisher_name'],
            'slug' => $r['publisher_slug'],
        ],

        'release_year' => $r['release_year'] === null ? null : (int) $r['release_year'],
        'release_date' => $r['release_date'],

        'rating'               => $r['rating'] === null ? null : (int) $r['rating'],
        'condition'            => $r['condition_grade'],
        'condition_label'      => condition_label($r['condition_grade']),
        'components'           => [
            'box'    => ['value' => $r['condition_box'],    'label' => condition_label($r['condition_box'])],
            'manual' => ['value' => $r['condition_manual'], 'label' => condition_label($r['condition_manual'])],
            'media'  => ['value' => $r['condition_media'],  'label' => condition_label($r['condition_media'])],
        ],
        'completeness'         => $r['completeness'],
        'completeness_label'   => completeness_label($r['completeness']),
        // Whether there is a box at all, which is the question the box grade
        // assumes an answer to. A client with only the grade cannot tell "no box"
        // from "a box nobody has graded".
        'has_box'              => (bool) (int) ($r['has_box'] ?? 0),
        // Null on software, and on any entry nobody has filled these in for -
        // which the client should read as "hide the section" rather than as five
        // empty rows on a cartridge.
        'hardware'             => $hardware,


        'media_type'     => $r['media_type'],
        'media_count'    => (int) $r['media_count'],
        'catalog_number' => $r['catalog_number'],
        'barcode'        => $r['barcode'],
        'language'       => $r['language'],
        'region'         => $r['region'],
        // The non-hardware counterpart to $hardware['specs'] above - same
        // shape, same reasoning, its own column since a hardware detail
        // row is a genuinely separate record from the item itself.
        'specs'          => $r['specs'] === null
            ? [] : (json_decode((string) $r['specs'], true) ?: []),

        'acquired_on'      => $r['acquired_on'],
        // Who it came from, and what was noted at the time. Both writable and
        // neither returned, so a client could set them and never read them back.
        'acquired_from'    => $r['acquired_from'],
        'acquired_note'    => $r['acquired_note'],
        'acquired_price'   => $r['acquired_price'] === null ? null : (float) $r['acquired_price'],
        'currency'         => $r['currency'],
        'location'         => $r['location_id'] === null ? null : [
            'id'   => (int) $r['location_id'],
            'name' => $r['location_name'],
            'path' => location_breadcrumb((int) $r['location_id']),
        ],

        'is_original' => (bool) (int) $r['is_original'],
        'status'       => $r['status'],
        'status_label' => status_label($r['status']),
        // Derived, never stored. The column that used to back this was
        // maintained by hand in four places and drifted from status.
        'is_wishlist'  => $r['status'] === 'wishlist',
        'copies'       => (int) $r['copies'],
        'sold_on'      => $r['sold_on'],
        'sold_to'      => $r['sold_to'],
        'sold_note'    => $r['sold_note'],
        'sold_currency' => $r['sold_currency'],
        'sold_price'   => $r['sold_price'] === null ? null : (float) $r['sold_price'],
        'current_value' => $r['current_value'] === null ? null : (float) $r['current_value'],
        'valued_on'     => $r['valued_on'],

        'external_url' => $r['external_url'],
        // What the release is, as against what the owner thinks of their copy.
        // Two columns since migration 0014, and a client reading only `notes`
        // would have lost every imported blurb the day they were separated.
        'description'  => $r['description'] ?? null,
        'notes'        => $r['notes'],

        // What this copy came on. `media_type` and `media_count` are still here
        // and still follow the first row, so a client written against them keeps
        // working; `media` is the whole list, which is what they could never say.
        'media_type'   => $r['media_type'] ?? null,
        'media_count'  => (int) ($r['media_count'] ?? 1),

        'image_count' => (int) ($r['image_count'] ?? 0),
        'cover'       => (function () use ($r): array {
            $filename = $r['cover_filename'] ?? null;
            $source   = $filename === null || $filename === '' ? null : 'photo';

            // Three steps, tried in this order, and the order is the whole
            // design: a real photograph, then whatever this branch of the
            // category tree was given, then a generic picture of the format.
            //
            // A category's own default only stands in when there is
            // genuinely no photograph of the entry's own - the walk up
            // the branch this entry's own category sits in, the same
            // inheritance kind already uses, nearest ancestor with an
            // answer wins. Never shown ahead of a real photo, whatever
            // was uploaded or brought in by a metadata agent.
            if ($source === null && !empty($r['category_id'])) {
                $filename = category_effective_default_image((int) $r['category_id']);
                $source   = $filename === null ? null : 'category';
            }

            // And last, one of the pictures that ship with the package,
            // chosen from what the entry says it is and what it comes on.
            // Below the category default rather than above it, because
            // setting a picture on a branch is a deliberate act by
            // somebody who looked at that branch, and this is an
            // automatic guess - the deliberate one should win, or it
            // would be an override that overrides nothing.
            if ($source === null) {
                $filename = stock_image_for_item($r);
                $source   = $filename === null ? null : 'stock';
            }

            return [
                'thumb'      => absolute_url(image_url($filename, 'thumb')),
                'display'    => absolute_url(image_url($filename, 'display')),
                // Kept as it was: true whenever what is shown is not a
                // photograph of this object. A client that only wants to
                // know "is this real" needs no change.
                'is_default' => $source !== null && $source !== 'photo',
                // Which of the three it came from, for a client that wants
                // to caption it. Null when there is nothing to show at all.
                'source'     => $source,
            ];
        })(),

        'created_at' => api_datetime($r['created_at']),
        'updated_at' => api_datetime($r['updated_at']),
        'url'        => base_url() . '/items/' . (int) $r['id'],
        'can_edit'   => can_write_item($r),
    ];

    if ($withImages) {
        $out['images'] = array_map('image_to_api', item_images((int) $r['id']));
        $out['tags']   = array_column(item_tags((int) $r['id']), 'name');

        // The lists that arrived with their own tables. Behind the same flag as
        // images, because they are per-row queries: a collection page asking for
        // two hundred entries should not run six hundred of them.
        $out['media'] = array_map(static fn(array $m): array => [
            'medium'   => (string) $m['medium'],
            'quantity' => (int) $m['quantity'],
        ], item_media((int) $r['id']));

        $out['links'] = array_map(static fn(array $d): array => [
            'label'  => (string) $d['label'],
            'url'    => (string) $d['url'],
            // Which lookup found it, or null when somebody typed it in.
            'source' => $d['source'] === null ? null : (string) $d['source'],
        ], item_documents((int) $r['id']));

        // What this runs on, and what it runs under.
        //
        // Both tables have existed throughout and both are already enforced -
        // api_link_refusal() consults effective_compatibility() before letting a
        // card into a machine. What was missing was any way to read or set them
        // from outside the engine's own interface, so the fitting rules were
        // running against lists no client could supply. A rule enforced on data
        // nobody can edit is worse than no rule: it refuses, and the person
        // refused has nowhere to go and correct it.
        //
        // `from` says which of the two answered - see effective_compatibility().
        // A model's list wins over a card's own, because a copy of a BigRAM 2008
        // cannot fit something a BigRAM 2008 does not; the card's own list is
        // kept either way, so detaching the model does not lose what was typed.
        $compat = effective_compatibility((int) $r['id'], (int) ($r['model_id'] ?? 0));
        $out['compatibility'] = [
            'model_ids' => $compat['ids'],
            'names'     => $compat['names'],
            'from'      => $compat['from'],
            // What this entry itself says, as against what it inherits. A client
            // drawing tick boxes needs this one: the boxes edit the item's list,
            // and showing the model's inherited answer as though it were ticked
            // here would make clearing a box appear to do nothing.
            'own_model_ids' => item_compatibility_ids((int) $r['id']),
        ];

        $out['environments'] = array_map(static fn(array $e): array => [
            'id'   => (int) $e['id'],
            'name' => (string) $e['name'],
        ], all(
            'SELECT o.id, o.name FROM item_environments e
               JOIN operating_systems o ON o.id = e.os_id
              WHERE e.item_id = ? ORDER BY o.name',
            [(int) $r['id']]
        ));
    }

    return $out;
}

/**
 * A platform. Note the absence of an 'access' field: a platform is not an
 * access boundary, and reporting one here was the serialiser end of the same
 * confusion that had the ACL filtering on platform_id.
 */
function platform_to_api(array $r): array
{
    return [
        'id'              => (int) $r['id'],
        'name'            => $r['name'],
        'slug'            => $r['slug'],
        // A read alias from LEFT JOIN companies, not a column, so it is absent
        // whenever the caller's query did not join. sort_order is gone from this
        // table altogether since migration 0005; it was read here regardless.
        'manufacturer'    => $r['manufacturer'] ?? null,
        'vendor_id'       => isset($r['vendor_id']) && $r['vendor_id'] !== null ? (int) $r['vendor_id'] : null,
        'year_introduced' => $r['year_introduced'] === null ? null : (int) $r['year_introduced'],
        'color'           => $r['accent_color'],
        'description'     => $r['description'],
        'item_count'      => isset($r['n']) ? (int) $r['n'] : null,
    ];
}

/** A library, which *is* an access boundary. */
function library_to_api(array $r): array
{
    return [
        'id'          => (int) $r['id'],
        'name'        => $r['name'],
        'slug'        => $r['slug'],
        'description' => $r['description'] ?? null,
        'color'       => $r['accent_color'],
        'kind'         => $r['kind'],
        'public_read'  => (bool) (int) ($r['public_read'] ?? 0),
        'public_write' => (bool) (int) ($r['public_write'] ?? 0),
        // Whether a photograph uploaded here waits for a decision, and how many
        // are waiting now. The count only for somebody who could act on it -
        // for anybody else it is a number about pictures they cannot see.
        'photo_approval' => (bool) (int) ($r['photo_approval'] ?? 0),
        // An outstanding offer of ownership. Reported so a client can say the
        // offer is standing and let it be withdrawn - without it, the only sign
        // is the person at the other end being asked something.
        'pending_owner_id' => ($r['pending_owner_id'] ?? null) === null
            ? null : (int) $r['pending_owner_id'],
        // The levels an invitation here may grant, so a client offers what is
        // allowed rather than a full list the server then refuses. A personal
        // shelf caps at viewer; every other library goes to admin.
        'invitable_levels' => function_exists('library_invitable_levels')
            ? library_invitable_levels((int) $r['id']) : [],
        'pending_images' => can_structure_library((int) $r['id'])
            ? (int) scalar("SELECT COUNT(*) FROM item_images img
                              JOIN items i ON i.id = img.item_id AND i.deleted_at IS NULL
                             WHERE i.library_id = ? AND img.approval_state = 'pending'",
                           [(int) $r['id']])
            : null,
        'is_personal' => (bool) (int) ($r['is_personal'] ?? 0),
        'sort_order'  => (int) $r['sort_order'],
        'item_count'  => isset($r['n']) ? (int) $r['n'] : null,
        'access'      => library_access(acting_user(), (int) $r['id']),
        'access_label' => access_label(library_access(acting_user(), (int) $r['id'])),
    ];
}

/** A canonical software title, as against a copy of one. */
function title_to_api(array $r): array
{
    return [
        'id'          => (int) $r['id'],
        'name'        => $r['name'],
        'subtitle'    => $r['subtitle'],
        'sort_name'   => $r['sort_name'],
        'slug'        => $r['slug'],
        'work_key'    => $r['work_key'],
        'platform'    => [
            'id'   => (int) $r['platform_id'],
            'name' => $r['platform_name'] ?? null,
            'slug' => $r['platform_slug'] ?? null,
        ],
        'category_id'  => $r['category_id'] === null ? null : (int) $r['category_id'],
        'developer'    => $r['developer_name'] ?? null,
        'publisher'    => $r['publisher_name'] ?? null,
        'release_year' => $r['release_year'] === null ? null : (int) $r['release_year'],
        'release_date' => $r['release_date'],
        'language'     => $r['language'],
        'region'       => $r['region'],
        'external_url' => $r['external_url'],
        'synopsis'     => $r['synopsis'],
        'copy_count'   => isset($r['copy_count']) ? (int) $r['copy_count'] : null,
        'created_at'   => api_datetime($r['created_at'] ?? null),
        'updated_at'   => api_datetime($r['updated_at'] ?? null),
    ];
}

function category_to_api(array $r): array
{
    // The tree, not a flat list.
    //
    // This reported a name and a slug and nothing else, so a client could not tell
    // "Games" from "Amiga > Software > Games > Racing", nor which machine either
    // belonged to. Everything the catalogue files against is in here now: where a node
    // sits, which side of the shop it is on, and whether it is a machine kind.
    return [
        'id'          => (int) $r['id'],
        'name'        => $r['name'],
        'slug'        => $r['slug'],
        'parent_id'   => ($r['parent_id'] ?? null) === null ? null : (int) $r['parent_id'],
        'domain'      => $r['domain'] ?? null,
        'role'        => $r['role'] ?? null,
        'depth'       => (int) ($r['depth'] ?? 0),
        'platform_id' => ($r['platform_id'] ?? null) === null ? null : (int) $r['platform_id'],
        'library_id'  => ($r['library_id'] ?? null) === null ? null : (int) $r['library_id'],
        // Which template branch this copy came from, null for a branch somebody
        // added by hand. It is what ties the sixty-odd copies of "Action" to
        // each other, and so what a client needs to offer a genre once rather
        // than once per platform. See the `genre` filter in build_item_filters().
        'source_slug' => ($r['source_slug'] ?? null) === null || $r['source_slug'] === ''
            ? null : (string) $r['source_slug'],
        'path'        => category_breadcrumb((int) $r['id']),
        'description' => $r['description'] ?? null,
        'sort_order'  => (int) ($r['sort_order'] ?? 0),
        // The picture this branch's own entries get with no photograph
        // of their own. own_image is only ever this node's own upload,
        // never inherited - a client offering "remove" needs to know
        // whether there is genuinely a file on this row to remove, not
        // whether entries here currently show something borrowed from
        // above. effective_image is what an item filed here would
        // actually show, walking up the branch the same way a real
        // entry's own cover falls back - present so a client can show
        // "inherited from Peripherals" without a second request.
        'own_image' => empty($r['default_image_filename']) ? null : [
            'thumb'   => absolute_url(image_url($r['default_image_filename'], 'thumb')),
            'display' => absolute_url(image_url($r['default_image_filename'], 'display')),
        ],
        'effective_image' => (function () use ($r) {
            // The same three steps an entry filed here would take, in the same
            // order - see item_to_api(). This reported only the first two, so a
            // branch relying on the shipped pictures came back with nothing at
            // all while entries filed under it plainly showed one. A screen
            // asking "what do things here look like" was told "nothing" and the
            // shelf disagreed.
            $filename = !empty($r['default_image_filename'])
                ? (string) $r['default_image_filename']
                : category_effective_default_image((int) $r['id']);
            $source = $filename === null ? null
                : (!empty($r['default_image_filename']) ? 'own' : 'inherited');

            if ($filename === null) {
                $declared = category_effective_stock_image((int) $r['id']);
                if ($declared !== null) {
                    $filename = STOCK_REF_PREFIX . $declared;
                    $source   = 'branch';
                }
            }
            if ($filename === null) {
                // Last, and only a guess: a branch has no format of its own, so
                // this is what the kind alone would give. Named 'kind' rather
                // than passed off as the branch's answer, because an entry that
                // says what it comes on may well get something else.
                $filename = stock_image_for_item(['category_id' => (int) $r['id']]);
                $source   = $filename === null ? null : 'kind';
            }

            return $filename === null ? null : [
                'thumb'   => absolute_url(image_url($filename, 'thumb')),
                'display' => absolute_url(image_url($filename, 'display')),
                // Which of the four answered, so a screen can say where it came
                // from rather than implying every picture was chosen here.
                'source'  => $source,
            ];
        })(),
        // What the structure feed declares for this branch, if anything - the
        // slug, so a picker can show which of the shipped pictures is current.
        'stock_image' => ($r['stock_image'] ?? null) === null || $r['stock_image'] === ''
            ? null : (string) $r['stock_image'],
    ];
}


function company_to_api(array $r): array
{
    return [
        'id'            => (int) $r['id'],
        'name'          => $r['name'],
        'slug'          => $r['slug'],
        'library_id'    => isset($r['library_id']) && $r['library_id'] !== null ? (int) $r['library_id'] : null,
        // Comma-stored, exposed as an array - the same shape a form posts
        // back, so a client never has to know it is a SET column underneath.
        'makes'         => ($r['makes'] ?? '') === '' ? [] : explode(',', (string) $r['makes']),
        'country'       => $r['country'],
        'founded_year'  => $r['founded_year'] === null ? null : (int) $r['founded_year'],
        'defunct_year'  => $r['defunct_year'] === null ? null : (int) $r['defunct_year'],
        'website'       => $r['website'],
        'wikipedia_url' => $r['wikipedia_url'],
        'notes'         => $r['notes'],
        'logo'          => [
            'thumb' => absolute_url(image_url($r['logo_filename'] ?? null, 'thumb')),
            'full'  => absolute_url(image_url($r['logo_filename'] ?? null, 'orig')),
        ],
    ];
}

/** A director, an artist, an author - a person, not a company, and nothing here pretends otherwise. */
function person_to_api(array $r): array
{
    return [
        'id'            => (int) $r['id'],
        'name'          => $r['name'],
        'slug'          => $r['slug'],
        'library_id'    => isset($r['library_id']) && $r['library_id'] !== null ? (int) $r['library_id'] : null,
        'born_year'     => $r['born_year'] === null ? null : (int) $r['born_year'],
        'died_year'     => $r['died_year'] === null ? null : (int) $r['died_year'],
        'website'       => $r['website'],
        'wikipedia_url' => $r['wikipedia_url'],
        'notes'         => $r['notes'],
    ];
}

/** What a credit can be - Director, Composer - tagged with which domain(s) it makes sense in. */
function credit_role_to_api(array $r): array
{
    return [
        'id'         => (int) $r['id'],
        'name'       => $r['name'],
        'slug'       => $r['slug'],
        'library_id' => isset($r['library_id']) && $r['library_id'] !== null ? (int) $r['library_id'] : null,
        'domains'    => ($r['domains'] ?? '') === '' ? [] : explode(',', (string) $r['domains']),
        'sort_order' => (int) ($r['sort_order'] ?? 100),
    ];
}

/** What a release runs under - Workbench 1.x, DOS, a specific console's BIOS. Per platform, since the answer only ever makes sense against one machine. */
function environment_to_api(array $r): array
{
    return [
        'id'         => (int) $r['id'],
        'name'       => $r['name'],
        'slug'       => $r['slug'],
        'library_id' => isset($r['library_id']) && $r['library_id'] !== null ? (int) $r['library_id'] : null,
        'platform_id' => (int) $r['platform_id'],
        'platform'   => isset($r['platform_name']) ? [
            'id'   => (int) $r['platform_id'],
            'name' => $r['platform_name'],
            'slug' => $r['platform_slug'] ?? null,
        ] : null,
    ];
}

/**
 * A machine or a part - one table, since the category it is filed under
 * already says which. Deliberately narrower than the real form: no
 * interface_vocab_id (a controlled vocabulary, real, separate work),
 * no model_compatibility (which parts fit which machines, a genuine
 * many-to-many, also separate work) - `interface` and `fits_note` stay
 * free text here, the same fallback the real schema itself keeps for
 * exactly the cases those two features do not yet cover.
 */
function hardware_model_to_api(array $r): array
{
    return [
        'id'          => (int) $r['id'],
        'name'        => $r['name'],
        'slug'        => $r['slug'],
        'library_id'  => isset($r['library_id']) && $r['library_id'] !== null ? (int) $r['library_id'] : null,
        'category_id' => (int) $r['category_id'],
        'category'    => isset($r['category_name']) ? [
            'id'   => (int) $r['category_id'],
            'name' => $r['category_name'],
            'slug' => $r['category_slug'] ?? null,
            'role' => $r['category_role'] ?? null,
        ] : null,
        'platform_id' => $r['platform_id'] === null ? null : (int) $r['platform_id'],
        'platform'    => isset($r['platform_name']) && $r['platform_name'] !== null ? [
            'id'   => (int) $r['platform_id'],
            'name' => $r['platform_name'],
            'slug' => $r['platform_slug'] ?? null,
        ] : null,
        'vendor_id'   => $r['vendor_id'] === null ? null : (int) $r['vendor_id'],
        'vendor'      => isset($r['vendor_name']) && $r['vendor_name'] !== null ? [
            'id'   => (int) $r['vendor_id'],
            'name' => $r['vendor_name'],
        ] : null,
        'year_from'   => $r['year_from'] === null ? null : (int) $r['year_from'],
        'fits_note'   => $r['fits_note'],
        'interface'   => $r['interface'],
        'notes'       => $r['notes'],
        'sort_order'  => (int) ($r['sort_order'] ?? 0),
        // Which machines a peripheral model fits. The authoritative half of
        // compatibility: effective_compatibility() prefers this over anything a
        // single card says about itself, because a copy of a part cannot fit
        // something the part does not. Always present, and empty for a machine -
        // a machine does not fit into anything, it is what things fit into.
        'compatible_model_ids' => model_compatibility_ids((int) $r['id']),
        // What a machine or part made from this model is asked.
        //
        // `model_fields` has existed and been written by the engine's own screen
        // throughout, and was never reported here - so a client could pick an
        // Amiga 2000 and get its name, its interface and nothing else, while the
        // model itself knew its processor, its memory and its chipset. The
        // autofill on the entry form filled three fields because three were all
        // it could see.
        'fields' => array_map(static fn(array $f): array => [
            'label'         => (string) $f['label'],
            'default_value' => $f['default_value'],
            'hint'          => $f['hint'],
        ], model_fields((int) $r['id'])),
    ];
}

/**
 * A boxed release template - what titles made from it start out already
 * filled in with.
 *
 * All three child lists are here: the fields a title is asked
 * (software_model_fields), what the box should hold (software_model_contents),
 * and what it comes on (software_model_media). Only when `$withLists`, because
 * they are three queries per row and an index of forty models would otherwise
 * run a hundred and twenty of them to draw a table showing none of it - the
 * counts come down with the list instead.
 */
function software_model_to_api(array $r, bool $withLists = false): array
{
    $out = [
        'id'          => (int) $r['id'],
        'name'        => $r['name'],
        'slug'        => $r['slug'],
        'library_id'  => isset($r['library_id']) && $r['library_id'] !== null ? (int) $r['library_id'] : null,
        'category_id' => $r['category_id'] === null ? null : (int) $r['category_id'],
        'category'    => isset($r['category_name']) && $r['category_name'] !== null ? [
            'id'   => (int) $r['category_id'],
            'name' => $r['category_name'],
            'slug' => $r['category_slug'] ?? null,
        ] : null,
        'platform_id' => $r['platform_id'] === null ? null : (int) $r['platform_id'],
        'platform'    => isset($r['platform_name']) && $r['platform_name'] !== null ? [
            'id'   => (int) $r['platform_id'],
            'name' => $r['platform_name'],
            'slug' => $r['platform_slug'] ?? null,
        ] : null,
        'notes'       => $r['notes'],
        // The old single string, kept readable. `media` the list is the answer
        // now; this column is whatever was in it before the list existed and is
        // never written any more.
        'media'       => $r['media'] ?? null,
        'year_from'   => isset($r['year_from']) ? (int) $r['year_from'] : null,
    ];

    // How many of each, always - a list screen wants to say "4 fields, 3 items"
    // without fetching three child tables per row.
    $id = (int) $r['id'];
    $out['field_count']   = (int) ($r['field_count']   ?? scalar('SELECT COUNT(*) FROM software_model_fields WHERE model_id = ?', [$id]));
    $out['content_count'] = (int) ($r['content_count'] ?? scalar('SELECT COUNT(*) FROM software_model_contents WHERE model_id = ?', [$id]));
    $out['media_count']   = (int) ($r['media_count']   ?? scalar('SELECT COUNT(*) FROM software_model_media WHERE model_id = ?', [$id]));

    // The lists themselves only when asked, because they are three queries per
    // row and an index of forty models would otherwise run a hundred and twenty
    // of them to draw a table that shows none of it.
    if ($withLists) {
        $out['fields'] = array_map(static fn(array $f): array => [
            'label'         => (string) $f['label'],
            'default_value' => $f['default_value'],
            'hint'          => $f['hint'],
        ], software_model_fields($id));

        $out['contents'] = array_map(static fn(array $c): array => [
            'label' => (string) $c['label'],
            'note'  => $c['note'],
        ], software_model_contents($id));

        $out['media_list'] = array_map(static fn(array $m): array => [
            'medium'   => (string) $m['medium'],
            'quantity' => (int) $m['quantity'],
        ], software_model_media($id));
    }

    return $out;
}

/**
 * One credit - a title, a role, and exactly one of a person or a company,
 * matching the CHECK constraint the database itself enforces. `holder`
 * carries whichever one it actually is, tagged so a client never has to
 * guess from which of the two ids is non-null.
 */
function credit_to_api(array $r): array
{
    $isPerson = $r['person_id'] !== null;
    return [
        'id'         => (int) $r['id'],
        'title_id'   => (int) $r['title_id'],
        'role'       => [
            'id'   => (int) $r['role_id'],
            'name' => $r['role_name'] ?? null,
            'slug' => $r['role_slug'] ?? null,
        ],
        'holder'     => [
            'type' => $isPerson ? 'person' : 'company',
            'id'   => (int) ($isPerson ? $r['person_id'] : $r['company_id']),
            'name' => $r['holder_name'] ?? null,
        ],
        'sort_order' => (int) ($r['sort_order'] ?? 100),
    ];
}

/**
 * The instance's own logos, as URLs a client can use directly.
 *
 * Null when none is set, which every client reads as "draw the built-in mark" -
 * so an instance that has uploaded nothing looks exactly as it did.
 */
function instance_logos(): array
{
    $small = (string) setting('logo_small', '');
    $large = (string) setting('logo_large', '');
    return [
        // The header mark is only ever shown small, so the thumbnail is what it
        // wants. The sign-in one is used at its own size - scaling it down and
        // then displaying it large would throw away the reason for uploading it.
        'small' => $small === '' ? null : absolute_url(image_url($small, 'thumb')),
        'large' => $large === '' ? null : absolute_url(image_url($large, 'orig')),
    ];
}

function user_to_api(array $u): array
{
    return [
        'id'           => (int) $u['id'],
        'username'     => $u['username'],
        'display_name' => $u['display_name'],
        'email'        => $u['email'] ?? null,
        'avatar'       => absolute_url(image_url($u['avatar_filename'] ?? null, 'thumb')),
        // A new picture waiting for an administrator. The one above is
        // unaffected and stays what is shown: what is pending is the change,
        // not the removal.
        'avatar_pending' => absolute_url(image_url($u['avatar_pending_filename'] ?? null, 'thumb')),
        'role'         => $u['role'],
        'can_edit'     => can_edit_anything(),
        'is_admin'     => $u['role'] === 'admin',
        // What the account can reach is per library, reported as a list rather
        // than as one global level. There used to be a second 'libraries' key
        // below this one built from all_platforms(), which PHP silently let win
        // - so this returned every platform on the instance instead.
        'libraries'    => array_map(
            fn($l) => [
                'id'     => (int) $l['id'],
                'name'   => $l['name'],
                'slug'   => $l['slug'],
                'color'  => $l['accent_color'],
                'access' => library_access($u, (int) $l['id']),
            ],
            readable_libraries(ACCESS_VIEWER)
        ),
        'platforms'    => array_map(
            fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'slug' => $p['slug']],
            all_platforms()
        ),
    ];
}

/**
 * The media and link lists, as a JSON client sends them.
 *
 * Absent means "leave alone", an empty array means "empty it" - the difference
 * a PATCH has to be able to express, and the reason these are not folded into
 * the field map above, which cannot say the second thing.
 *
 * @return list<string> what went wrong, empty when nothing did
 */
function api_apply_item_lists(int $itemId, array $in): array
{
    $errors = [];

    if (array_key_exists('media', $in)) {
        if (!is_array($in['media'])) {
            $errors['media'] = 'Must be an array of {medium, quantity}.';
        } else {
            $media = [];
            $qty   = [];
            foreach ($in['media'] as $row) {
                if (!is_array($row) || !isset($row['medium'])) {
                    $errors['media'] = 'Each entry needs a medium.';
                    break;
                }
                $media[] = (string) $row['medium'];
                $qty[]   = (int) ($row['quantity'] ?? 1);
            }
            if (!isset($errors['media'])) {
                set_item_media($itemId, $media, $qty);
            }
        }
    }

    if (array_key_exists('links', $in)) {
        if (!is_array($in['links'])) {
            $errors['links'] = 'Must be an array of {label, url}.';
        } else {
            $labels = [];
            $urls   = [];
            foreach ($in['links'] as $row) {
                if (!is_array($row) || !isset($row['url'])) {
                    $errors['links'] = 'Each entry needs a url.';
                    break;
                }
                $labels[] = (string) ($row['label'] ?? '');
                $urls[]   = (string) $row['url'];
            }
            if (!isset($errors['links'])) {
                // set_item_documents() drops anything that is not an http(s)
                // address, so a client cannot store a javascript: link by
                // calling the API instead of using the form.
                set_item_documents($itemId, $labels, $urls);
            }
        }
    }

    // Which machine models this card fits. Ids, replaced wholesale - the form
    // that posts these shows every box, so what comes back is the whole answer
    // and a box somebody cleared has to actually clear.
    //
    // set_item_compatibility() does the checking that matters: a model must
    // exist, be a machine, and belong to the same library as the entry. Ids that
    // fail are dropped rather than refused, because the only way to send one is
    // a stale form or a crafted request and neither wants an error page - but
    // sending something that is not a list at all is a client bug worth naming.
    if (array_key_exists('compatibility', $in)) {
        if (!is_array($in['compatibility'])) {
            $errors['compatibility'] = 'Must be an array of hardware model ids.';
        } else {
            set_item_compatibility($itemId, array_map('intval', array_values($in['compatibility'])));
        }
    }

    // What it runs under - MS-DOS and Windows 3.x both, for a 1995 PC release.
    // Same shape, and the reason it is a list at all: this was one column once,
    // and whichever environment somebody picked, the catalogue then implied the
    // others were untrue.
    if (array_key_exists('environments', $in)) {
        if (!is_array($in['environments'])) {
            $errors['environments'] = 'Must be an array of environment ids.';
        } else {
            $want = array_values(array_unique(array_filter(
                array_map('intval', array_values($in['environments'])),
                static fn(int $v): bool => $v > 0
            )));
            $libraryId = (int) (scalar('SELECT library_id FROM items WHERE id = ?', [$itemId]) ?? 0);
            q('DELETE FROM item_environments WHERE item_id = ?', [$itemId]);
            if ($want !== [] && $libraryId > 0) {
                // Only this library's own environments, the same rule
                // sync_item_environments() enforces for the web form.
                $ph = implode(',', array_fill(0, count($want), '?'));
                q("INSERT IGNORE INTO item_environments (item_id, os_id)
                   SELECT ?, o.id FROM operating_systems o
                    WHERE o.id IN ($ph) AND o.library_id = ?",
                  array_merge([$itemId], $want, [$libraryId]));
            }
        }
    }

    return $errors;
}

/**
 * One request, folded into its own hour's bucket rather than kept as a
 * row of its own - a busy instance answers thousands of these a day,
 * and no question this table exists to answer needs more than an hour's
 * granularity to answer it.
 *
 * The route is the pattern that matched, not the path that was asked
 * for: "/items/482" and "/items/9091" are the same question asked
 * twice, and counting them as two different routes would mean the
 * table never stops growing new rows as ids climb.
 */
function api_record_request_stat(string $method, string $pattern, int $statusCode, float $durationMs): void
{
    // #^/api/v1/items/(\d+)$# -> /items/{id}. Anchors, delimiters, and
    // the version prefix stripped because every route shares them and
    // they add nothing a reader doesn't already know; capture groups
    // collapsed to {id} because every one of them is an identifier in
    // practice, never a value worth breaking out on its own.
    $route = trim($pattern, '#');
    $route = ltrim($route, '^');
    $route = rtrim($route, '$');
    $route = preg_replace('#^/api/v1#', '', $route);
    $route = preg_replace('/\([^)]*\)/', '{id}', (string) $route);
    if ($route === '' || $route === null) {
        $route = '/';
    }

    $statusClass = match (true) {
        $statusCode >= 500 => '5xx',
        $statusCode >= 400 => '4xx',
        $statusCode >= 300 => '3xx',
        default             => '2xx',
    };

    $source = 'unknown';
    $plain = bearer_token();
    if ($plain !== null) {
        $token = one('SELECT platform FROM api_tokens WHERE token_hash = ?', [token_hash($plain)]);
        if ($token !== null) {
            $source = trim((string) ($token['platform'] ?? '')) !== '' ? (string) $token['platform'] : 'token';
        }
    } elseif (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
        $source = 'web';
    }

    $bucket = date('Y-m-d H:00:00');

    try {
        q('INSERT INTO api_request_stats (bucket_hour, method, route, status_class, source, request_count, total_ms, max_ms)
           VALUES (?, ?, ?, ?, ?, 1, ?, ?)
           ON DUPLICATE KEY UPDATE request_count = request_count + 1, total_ms = total_ms + VALUES(total_ms),
                                   max_ms = GREATEST(max_ms, VALUES(max_ms))',
          [$bucket, $method, mb_substr($route, 0, 120), $statusClass, $source,
           (int) round($durationMs), (int) round($durationMs)]);
    } catch (\Throwable $e) {
        // Never let a stats write take an actual request down with it -
        // a table that isn't there yet on an instance mid-upgrade, or a
        // lock timeout under real load, costs a data point, not a
        // response.
    }

    // The same write, rounded to the nearest five minutes rather than
    // the hour, and by source and status only - no route, no method:
    // "how much traffic, from where, was any of it failing" is a
    // question this resolution can answer well; "which endpoint" stays
    // the hourly table's own to answer, where a route breaking down by
    // the hour still has enough calls in each bucket to mean something.
    $bucket5m = date('Y-m-d H:i:00', (int) (floor(strtotime('now') / 300) * 300));
    try {
        q('INSERT INTO api_request_stats_5m (bucket_5m, source, status_class, request_count, total_ms)
           VALUES (?, ?, ?, 1, ?)
           ON DUPLICATE KEY UPDATE request_count = request_count + 1, total_ms = total_ms + VALUES(total_ms)',
          [$bucket5m, $source, $statusClass, (int) round($durationMs)]);
    } catch (\Throwable $e) {
        // Same reasoning as the hourly write above: a stats table
        // problem is never a reason to fail the actual request.
    }
}

/** Old buckets, cleared - kept for a fixed window rather than forever. */
function api_prune_request_stats(int $keepDays = 30): int
{
    return (int) q('DELETE FROM api_request_stats WHERE bucket_hour < DATE_SUB(NOW(), INTERVAL ? DAY)', [$keepDays])->rowCount();
}

/**
 * The 5-minute table's own prune, on a much shorter leash than the
 * hourly one - kept only long enough for the "recent activity" chart
 * that reads it, a handful of hours rather than a month. Nothing past
 * that window is ever shown at this resolution, so nothing past it is
 * worth keeping at this resolution either.
 */
function api_prune_request_stats_5m(int $keepHours = 6): int
{
    return (int) q('DELETE FROM api_request_stats_5m WHERE bucket_5m < DATE_SUB(NOW(), INTERVAL ? HOUR)', [$keepHours])->rowCount();
}
