<?php
declare(strict_types=1);

/**
 * REST API plumbing: authentication, JSON envelopes, CORS, and the serialisers
 * that decide exactly what a native client receives.
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
        $user = one('SELECT id, username, display_name, avatar_filename, email, role, auth_method_id, is_active FROM users WHERE id = ? AND is_active = 1', [(int) $token['user_id']]);
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

/** Authenticated, allowed to write, and not using a read-only token. */
function api_require_write(): array
{
    [$user, $token] = api_require_auth();

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

function api_require_admin(): array
{
    [$user, $token] = api_require_write();
    if ($user['role'] !== 'admin') {
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
        'cover'       => [
            'thumb'   => absolute_url(image_url($r['cover_filename'] ?? null, 'thumb')),
            'display' => absolute_url(image_url($r['cover_filename'] ?? null, 'display')),
        ],

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
        'is_personal' => (bool) (int) ($r['is_personal'] ?? 0),
        'sort_order'  => (int) $r['sort_order'],
        'item_count'  => isset($r['n']) ? (int) $r['n'] : null,
        'access'      => library_access(acting_user(), (int) $r['id']),
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
        'path'        => category_breadcrumb((int) $r['id']),
        'description' => $r['description'] ?? null,
        'sort_order'  => (int) ($r['sort_order'] ?? 0),
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

function user_to_api(array $u): array
{
    return [
        'id'           => (int) $u['id'],
        'username'     => $u['username'],
        'display_name' => $u['display_name'],
        'email'        => $u['email'] ?? null,
        'avatar'       => absolute_url(image_url($u['avatar_filename'] ?? null, 'thumb')),
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

    return $errors;
}
