<?php
declare(strict_types=1);

/**
 * Directory authentication against LDAP or Active Directory.
 *
 * Nothing here runs until an administrator adds and enables an auth method, so
 * an install that never touches LDAP behaves exactly as before. The shape of
 * the configuration follows phpIPAM's: one row per backend with its settings
 * held as JSON, so adding an option needs no schema change.
 *
 * Requires ext-ldap. Every entry point checks for it and reports plainly
 * rather than fataling, because the extension is not installed by default on
 * most distributions.
 */

function ldap_available(): bool
{
    return function_exists('ldap_connect');
}

/** Settings an LDAP or AD method understands, with sane defaults. */
function ldap_default_params(string $type = 'ldap'): array
{
    $isAd = $type === 'ad';
    return [
        'host'            => '',          // dc01.example.com, or a space separated list
        // These two have to agree. The form offers encrypted or not, and
        // encrypted means LDAPS, so the default port follows from that rather
        // than being a leftover from when STARTTLS was the default.
        'port'            => 636,
        'encryption'      => 'ldaps',     // none | starttls | ldaps
        'verify_cert'     => true,
        'protocol_version' => 3,
        'referrals'       => false,       // AD hands out referrals that usually fail
        'timeout'         => 5,

        'base_dn'         => '',          // dc=example,dc=com
        'users_base_dn'   => '',          // blank = base_dn; narrow it only if you need to
        'bind_dn'         => '',          // service account; blank = anonymous search
        'bind_password'   => '',

        // How a login name maps to a directory entry.
        'uid_attr'        => $isAd ? 'sAMAccountName' : 'uid',
        'user_filter'     => $isAd
            ? '(&(objectClass=user)(sAMAccountName=%s))'
            : '(&(objectClass=inetOrgPerson)(uid=%s))',
        'name_attr'       => $isAd ? 'displayName' : 'cn',
        'mail_attr'       => 'mail',
        'uuid_attr'       => $isAd ? 'objectGUID' : 'entryUUID',

        // Group discovery.
        'group_base_dn'   => '',
        'group_filter'    => $isAd
            ? '(&(objectClass=group)(member=%s))'
            : '(&(objectClass=groupOfNames)(member=%s))',
        'group_name_attr' => 'cn',
        'member_attr'     => $isAd ? 'memberOf' : '',   // AD publishes memberOf on the user

        // Only members of this group may sign in at all. Blank = anyone the
        // directory authenticates.
        // Two groups, because "may sign in" and "may configure the system"
        // are different questions. Admin membership implies user membership,
        // so nobody has to be listed twice.
        'user_group'      => 'access-retrohive',
        'admin_group'     => 'admin-retrohive',

        // Create a RetroHive account on first successful sign-in.
        'autocreate'      => true,
        // Role and access for an autocreated user with no matching group map.
        'default_role'    => 'user',
        // What a new account is granted in the method's default library.
        'default_access'         => 'viewer',
        'default_library_id'     => null,
        // Re-apply group mappings on every sign-in, so directory changes take
        // effect without an administrator touching anything here.
        'sync_on_login'   => true,

        'debug'           => false,
    ];
}

/** Merge stored params over the defaults so old rows survive new options. */
function ldap_params(array $method): array
{
    $stored = [];
    if (!empty($method['params'])) {
        $decoded = json_decode((string) $method['params'], true);
        if (is_array($decoded)) {
            $stored = $decoded;
        }
    }
    return array_replace(ldap_default_params($method['type']), $stored);
}

/**
 * Open and bind a connection using the service account.
 * Returns [connection, null] or [null, errorMessage].
 */
function ldap_open(array $params): array
{
    if (!ldap_available()) {
        return [null, 'The PHP ldap extension is not installed on this server.'];
    }
    $host = trim((string) $params['host']);
    if ($host === '') {
        return [null, 'No directory server configured.'];
    }

    $scheme = $params['encryption'] === 'ldaps' ? 'ldaps://' : 'ldap://';
    $port   = (int) $params['port'];
    $uris   = [];
    foreach (preg_split('/[\s,]+/', $host) as $h) {
        if ($h === '') {
            continue;
        }
        $uris[] = str_contains($h, '://') ? $h : $scheme . $h . ':' . $port;
    }
    if ($uris === []) {
        return [null, 'No directory server configured.'];
    }

    // A mismatched port is the single most common way this fails, and the error
    // it produces - "Can't contact LDAP server" - says nothing about the cause.
    $portMismatch = ldap_port_mismatch((string) $params['encryption'], (int) $params['port']);
    if ($portMismatch !== null) {
        return [null, $portMismatch];
    }

    $strict     = (bool) $params['verify_cert'];
    $encrypted  = $params['encryption'] !== 'none';

    if ($encrypted) {
        $conflict = ldap_tls_policy_conflict($strict);
        if ($conflict !== null) {
            return [null, $conflict];
        }
    }

    $wanted = $strict
        ? (defined('LDAP_OPT_X_TLS_DEMAND') ? LDAP_OPT_X_TLS_DEMAND : 2)
        : (defined('LDAP_OPT_X_TLS_NEVER')  ? LDAP_OPT_X_TLS_NEVER  : 0);

    @putenv('LDAPTLS_REQCERT=' . ($strict ? 'demand' : 'never'));
    @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, $wanted);

    $conn = @ldap_connect(implode(' ', $uris));
    if ($conn === false) {
        return [null, 'Could not build a connection to ' . implode(' ', $uris) . '.'];
    }
    @ldap_set_option($conn, LDAP_OPT_X_TLS_REQUIRE_CERT, $wanted);

    // Rebuilds the TLS context so the option above actually applies. OpenLDAP
    // builds the context once per process and caches it, so without this a
    // policy change is silently ignored. Not every PHP build exposes it - see
    // ldap_tls_policy_conflict() for what happens when it is missing.
    if (defined('LDAP_OPT_X_TLS_NEWCTX')) {
        @ldap_set_option($conn, LDAP_OPT_X_TLS_NEWCTX, 0);
    }
    if ($encrypted) {
        ldap_remember_tls_policy($strict);
    }

    @ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, (int) $params['protocol_version']);
    @ldap_set_option($conn, LDAP_OPT_REFERRALS, $params['referrals'] ? 1 : 0);
    @ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, (int) $params['timeout']);
    @ldap_set_option($conn, LDAP_OPT_TIMELIMIT, (int) $params['timeout']);

    // Used only to make failures legible; the real connection is the LDAP one.
    $firstUri  = parse_url($uris[0]);
    $probeHost = $firstUri['host'] ?? $host;
    $probePort = (int) ($firstUri['port'] ?? ($params['encryption'] === 'ldaps' ? 636 : $port));

    if ($params['encryption'] === 'starttls' && !@ldap_start_tls($conn)) {
        return [null, ldap_explain_failure('STARTTLS was refused: ' . ldap_error($conn), $params, $probeHost, $probePort)];
    }

    $bindDn = trim((string) $params['bind_dn']);
    $ok = $bindDn === ''
        ? @ldap_bind($conn)                                        // anonymous
        : @ldap_bind($conn, $bindDn, (string) $params['bind_password']);

    if (!$ok) {
        $raw = 'The service account could not bind: ' . ldap_error($conn);
        // "Invalid credentials" is already clear; only the vague ones need help.
        $vague = stripos(ldap_error($conn), 'contact') !== false
              || stripos(ldap_error($conn), 'connect') !== false;
        return [null, $vague ? ldap_explain_failure($raw, $params, $probeHost, $probePort) : $raw];
    }

    return [$conn, null];
}

/**
 * LDAPS and STARTTLS are different protocols on different ports, and pointing
 * one at the other's port fails in a way that looks like a network problem.
 */
function ldap_port_mismatch(string $encryption, int $port): ?string
{
    if ($encryption === 'ldaps' && $port === 389) {
        return 'LDAPS is selected but the port is 389, which is the plain and STARTTLS port. '
             . 'LDAPS wraps the connection in TLS from the first byte and normally listens on 636. '
             . 'Either set the port to 636, or switch Encryption to STARTTLS on 389.';
    }
    if ($encryption === 'starttls' && $port === 636) {
        return 'STARTTLS is selected but the port is 636, which is the LDAPS port. '
             . 'STARTTLS begins as a plain connection on 389 and upgrades it. '
             . 'Either set the port to 389, or switch Encryption to LDAPS on 636.';
    }
    if ($encryption === 'none' && $port === 636) {
        return 'No encryption is selected but the port is 636, which only speaks LDAPS. '
             . 'Set the port to 389, or choose LDAPS.';
    }
    return null;
}

/**
 * OpenLDAP builds its TLS context once per process and caches it. Changing the
 * certificate policy afterwards needs LDAP_OPT_X_TLS_NEWCTX to force a rebuild,
 * and not every PHP build exposes that constant.
 *
 * Where it is missing, the first TLS connection a worker makes fixes the policy
 * until the process restarts. With mod_php that means a second directory using
 * the opposite setting fails with nothing more helpful than "Can't contact LDAP
 * server" - and only on some requests, depending which worker answered. Saying
 * so plainly is worth more than a mystery.
 *
 * Returns an explanation when the request cannot be honoured, or null.
 */
function ldap_tls_policy_conflict(bool $strict): ?string
{
    if (defined('LDAP_OPT_X_TLS_NEWCTX')) {
        return null;                       // the policy can be changed freely
    }

    $applied = ldap_remember_tls_policy(null);
    if ($applied === null || $applied === $strict) {
        return null;
    }

    return 'This PHP process has already opened a TLS connection with certificate checking '
         . ($applied ? 'ON' : 'OFF') . ', and this directory wants it '
         . ($strict ? 'ON' : 'OFF') . '. OpenLDAP caches its TLS settings for the life of the '
         . 'process and this PHP build does not expose LDAP_OPT_X_TLS_NEWCTX, so the change '
         . 'cannot be applied until the web server is restarted. Either give every directory the '
         . 'same certificate setting, or restart Apache after changing it.';
}

/** Remember, or read back, the policy this process has already committed to. */
function ldap_remember_tls_policy(?bool $strict): ?bool
{
    static $applied = null;
    if ($strict !== null && $applied === null) {
        $applied = $strict;
    }
    return $applied;
}

/**
 * Is the port even reachable?
 *
 * OpenLDAP reports almost every failure as "Can't contact LDAP server",
 * including a perfectly reachable server whose certificate it will not trust.
 * Probing the socket separately tells those two apart, which is the difference
 * between a firewall problem and a CA problem.
 */
function ldap_probe_tcp(string $host, int $port, int $timeout = 5): array
{
    $errno  = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, max(2, min(5, $timeout)));
    if ($fp === false) {
        return [false, $errstr !== '' ? $errstr : 'no route to the port'];
    }
    fclose($fp);
    return [true, null];
}

/**
 * Look at the certificate the server is actually presenting.
 *
 * OpenLDAP will not tell you why it rejected a certificate, so this opens a
 * plain TLS connection alongside and inspects the peer directly. The difference
 * between "expired three months ago" and "issuing CA not trusted" is the
 * difference between a five minute fix and an afternoon.
 *
 * Returns [summary|null, details[]].
 */
function ldap_probe_tls(string $host, int $port, int $timeout = 5): array
{
    if (!function_exists('stream_socket_client') || !function_exists('openssl_x509_parse')) {
        return [null, []];
    }

    $context = stream_context_create(['ssl' => [
        'capture_peer_cert' => true,
        'verify_peer'       => false,   // inspect first, judge afterwards
        'verify_peer_name'  => false,
        'SNI_enabled'       => true,
        'peer_name'         => $host,
    ]]);

    $client = @stream_socket_client(
        'ssl://' . $host . ':' . $port,
        $errno,
        $errstr,
        max(2, min(6, $timeout)),
        STREAM_CLIENT_CONNECT,
        $context
    );
    if ($client === false) {
        return [null, ['handshake' => $errstr !== '' ? $errstr : 'TLS handshake failed']];
    }

    $params = stream_context_get_params($client);
    fclose($client);

    $certResource = $params['options']['ssl']['peer_certificate'] ?? null;
    if ($certResource === null) {
        return [null, []];
    }
    $cert = @openssl_x509_parse($certResource);
    if (!is_array($cert)) {
        return [null, []];
    }

    $details = [];
    $subject = $cert['subject']['CN'] ?? '(no common name)';
    $details['certificate'] = $subject;
    $details['issuer']      = $cert['issuer']['CN'] ?? '(unknown issuer)';

    $notAfter  = (int) ($cert['validTo_time_t'] ?? 0);
    $notBefore = (int) ($cert['validFrom_time_t'] ?? 0);
    $now       = time();

    if ($notAfter > 0) {
        $details['expires'] = gmdate('Y-m-d', $notAfter);
    }

    if ($notAfter > 0 && $notAfter < $now) {
        $days = (int) floor(($now - $notAfter) / 86400);
        return [
            'The certificate for ' . $host . ' expired on ' . gmdate('Y-m-d', $notAfter)
            . ', ' . $days . ' days ago. Renew it and redeploy it to the directory server.'
            . ' To carry on regardless, set Certificate checking to "Accept any certificate" -'
            . ' reasonable on an internal network you control, but it removes the protection'
            . ' TLS was there to provide.',
            $details,
        ];
    }
    if ($notBefore > 0 && $notBefore > $now) {
        return ['The certificate for ' . $host . ' is not valid until ' . gmdate('Y-m-d', $notBefore)
                . '. Check the clock on this server and on the directory.', $details];
    }

    // Names, so a wildcard mismatch is caught rather than blamed on trust.
    $names = [];
    if (isset($cert['subject']['CN'])) {
        $names[] = (string) $cert['subject']['CN'];
    }
    if (!empty($cert['extensions']['subjectAltName'])) {
        foreach (explode(',', (string) $cert['extensions']['subjectAltName']) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $names[] = substr($entry, 4);
            }
        }
    }
    $matched = false;
    foreach ($names as $name) {
        $pattern = '/^' . str_replace('\*', '[^.]+', preg_quote($name, '/')) . '$/i';
        if (preg_match($pattern, $host)) {
            $matched = true;
            break;
        }
    }
    if ($names !== [] && !$matched) {
        return ['The certificate is for ' . implode(', ', array_slice($names, 0, 3))
                . ', which does not cover ' . $host . '.', $details];
    }

    // Dates and names are fine, so ask whether the chain verifies.
    $strict = stream_context_create(['ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
        'peer_name'        => $host,
    ]]);
    $verified = @stream_socket_client(
        'ssl://' . $host . ':' . $port,
        $e2, $s2, max(2, min(6, $timeout)), STREAM_CLIENT_CONNECT, $strict
    );
    if ($verified === false) {
        return ['The certificate is current and matches ' . $host
                . ', but the chain does not verify on this server - typically a self-signed'
                . ' certificate or a private CA. Add the issuing CA to the system trust store, set'
                . ' TLS_CACERT in /etc/openldap/ldap.conf, or set Certificate checking to'
                . ' "Accept any certificate".', $details];
    }
    fclose($verified);

    return [null, $details];
}

/**
 * Does a TLS handshake complete at all, ignoring whether the certificate is
 * trustworthy? Uses PHP's own TLS stack, so it is unaffected by whatever
 * OpenLDAP has cached.
 */
function ldap_tls_handshake_ok(string $host, int $port, int $timeout = 5): bool
{
    if (!function_exists('stream_socket_client')) {
        return false;
    }
    $context = stream_context_create(['ssl' => [
        'verify_peer' => false, 'verify_peer_name' => false,
        'SNI_enabled' => true, 'peer_name' => $host,
    ]]);
    $client = @stream_socket_client(
        'ssl://' . $host . ':' . $port, $errno, $errstr,
        max(2, min(6, $timeout)), STREAM_CLIENT_CONNECT, $context
    );
    if ($client === false) {
        return false;
    }
    fclose($client);
    return true;
}

/**
 * The advice for when everything about the connection looks fine but OpenLDAP
 * still refuses. Under mod_php this is nearly always the cached TLS context:
 * PHP's per-request state resets, the Apache worker's OpenLDAP state does not.
 */
function ldap_stale_context_hint(): string
{
    return ' If you changed the certificate setting recently, restart the web server:'
         . ' OpenLDAP caches its TLS configuration for the life of a worker process, so'
         . ' workers that already made a connection keep the old setting until they are'
         . ' replaced. On Apache: systemctl restart apache2.';
}

/** Turn a bare LDAP failure into something worth acting on. */
function ldap_explain_failure(string $rawError, array $params, string $host, int $port): string
{
    $encryption = (string) $params['encryption'];
    [$reachable, $why] = ldap_probe_tcp($host, $port, (int) $params['timeout']);

    if (!$reachable) {
        return $rawError . '. Nothing is listening on ' . $host . ':' . $port
             . ' from this server (' . $why . '), so this is a firewall, DNS or port problem'
             . ' rather than anything to do with the settings below.';
    }

    if ($encryption === 'none') {
        return $rawError . '. The port is reachable, so check the service account DN and password.';
    }

    // LDAPS speaks TLS from the first byte, so the certificate can be examined
    // directly and the real reason reported.
    if ($encryption === 'ldaps') {
        if (empty($params['verify_cert'])) {
            // Checking is off, so a bad certificate cannot be the cause. If a
            // TLS handshake works from here, the connection is sound too - and
            // the usual remaining explanation is a stale cached TLS context.
            if (ldap_tls_handshake_ok($host, $port, (int) $params['timeout'])) {
                return $rawError . '. The port is reachable and a TLS handshake succeeds from this'
                     . ' server, so neither the network nor the certificate explains it, and'
                     . ' certificate checking is already off for this directory.'
                     . ldap_stale_context_hint()
                     . ' If it has already been restarted, check the service account DN and password.';
            }
            return $rawError . '. The port is reachable but no TLS handshake completes, so'
                 . ' ' . $host . ':' . $port . ' is probably not speaking LDAPS. Try STARTTLS on 389'
                 . ' instead, or check what the directory is actually listening for.';
        }
        [$verdict, $details] = ldap_probe_tls($host, $port, (int) $params['timeout']);
        if ($verdict !== null) {
            $suffix = $details === [] ? '' : ' (' . implode(', ', array_map(
                fn($k, $v) => $k . ': ' . $v,
                array_keys($details),
                array_values($details)
            )) . ')';
            return $verdict . $suffix;
        }
        return $rawError . '. The port is reachable and the certificate looks sound, so check the'
             . ' service account DN and password.' . ldap_stale_context_hint();
    }

    return $rawError . '. The port is reachable, which usually means the STARTTLS handshake failed'
         . ' rather than the connection. Check the certificate with:'
         . ' openssl s_client -connect ' . $host . ':' . $port . ' -starttls ldap';
}

/** Escape a value for safe use inside an LDAP filter. */
function ldap_escape_filter(string $value): string
{
    if (function_exists('ldap_escape')) {
        return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
    }
    return str_replace(
        ['\\', '*', '(', ')', "\x00"],
        ['\\5c', '\\2a', '\\28', '\\29', '\\00'],
        $value
    );
}

/** First value of an attribute from an ldap_get_entries row. */
function ldap_attr(array $entry, string $attr): ?string
{
    $key = strtolower($attr);
    if (!isset($entry[$key])) {
        return null;
    }
    $v = $entry[$key];
    if (is_array($v)) {
        return isset($v[0]) ? (string) $v[0] : null;
    }
    return (string) $v;
}

/**
 * Break a typed identifier into the forms a directory might recognise.
 *
 * People type what they know: "tommy", "EXAMPLE\\tommy", "tommy@example.com",
 * or their email address. Rather than making them learn which one this
 * particular server wants, work out the candidates and try each.
 */
function ldap_identifier_forms(string $input): array
{
    $input = trim($input);
    $forms = ['bare' => $input, 'upn' => null, 'mail' => null, 'domain' => null];

    // NetBIOS style: DOMAIN\user
    if (preg_match('/^([^\\\\\/]+)[\\\\\/](.+)$/', $input, $m)) {
        $forms['domain'] = $m[1];
        $forms['bare']   = $m[2];
        return $forms;
    }

    // user@domain, which is either a UPN or an email address; both are worth
    // trying, and the part before the @ is usually the sAMAccountName.
    if (str_contains($input, '@')) {
        $forms['upn']  = $input;
        $forms['mail'] = $input;
        $forms['bare'] = substr($input, 0, strpos($input, '@'));
    }

    return $forms;
}

/**
 * Find a user entry by whatever they typed.
 *
 * Tries the configured filter first, then falls back to email and, on Active
 * Directory, userPrincipalName. The bind that follows uses the DN this returns,
 * so the domain never has to be typed: the directory tells us who they are.
 *
 * Returns [entry, null] or [null, reason].
 */
function ldap_find_user($conn, array $params, string $username): array
{
    $base = trim((string) $params['users_base_dn']);
    if ($base === '') {
        $base = (string) $params['base_dn'];
    }
    if ($base === '') {
        return [null, 'No base DN configured.'];
    }

    $attrs = array_values(array_filter([
        'dn',
        (string) $params['uid_attr'],
        (string) $params['name_attr'],
        (string) $params['mail_attr'],
        (string) $params['uuid_attr'],
        'userPrincipalName',
        $params['member_attr'] !== '' ? (string) $params['member_attr'] : null,
    ]));

    $forms   = ldap_identifier_forms($username);
    $configured = (string) $params['user_filter'];

    // In order of confidence. Each is a complete filter.
    $attempts = [];
    $attempts[] = str_contains($configured, '%s')
        ? sprintf($configured, ldap_escape_filter($forms['bare']))
        : '(' . $params['uid_attr'] . '=' . ldap_escape_filter($forms['bare']) . ')';

    if ($forms['upn'] !== null) {
        // The whole string might itself be the login attribute.
        $attempts[] = str_contains($configured, '%s')
            ? sprintf($configured, ldap_escape_filter($forms['upn']))
            : '(' . $params['uid_attr'] . '=' . ldap_escape_filter($forms['upn']) . ')';
        $attempts[] = '(userPrincipalName=' . ldap_escape_filter($forms['upn']) . ')';
    }
    if ($forms['mail'] !== null && (string) $params['mail_attr'] !== '') {
        $attempts[] = '(' . $params['mail_attr'] . '=' . ldap_escape_filter($forms['mail']) . ')';
    }

    $lastError = null;
    foreach (array_unique($attempts) as $filter) {
        $search = @ldap_search($conn, $base, $filter, $attrs, 0, 2, (int) $params['timeout']);
        if ($search === false) {
            $lastError = 'Directory search failed: ' . ldap_error($conn);
            continue;
        }
        $entries = @ldap_get_entries($conn, $search);
        if (!is_array($entries)) {
            continue;
        }
        $count = (int) ($entries['count'] ?? 0);
        if ($count === 1) {
            return [$entries[0], null];
        }
        if ($count > 1) {
            return [null, 'That identifier matches more than one directory entry.'];
        }
    }

    return [null, $lastError ?? 'No directory entry matches that username or email address.'];
}

/**
 * May this person sign in, and as what?
 *
 * Two groups rather than one, because "may use this at all" and "may configure
 * it" are separate questions. Conflating them is how somebody ends up an
 * administrator because they needed to look something up. Membership of the
 * admin group implies the user group, so nobody has to be listed twice.
 *
 * Returns [allowed, role, reason].
 */
function ldap_decide_access(array $params, array $groups): array
{
    $adminGroup = trim((string) ($params['admin_group'] ?? ''));
    $userGroup  = trim((string) ($params['user_group'] ?? ''));

    if ($adminGroup !== '' && ldap_group_matches($adminGroup, $groups)) {
        return [true, 'admin', 'In the administrator group "' . $adminGroup . '".'];
    }
    if ($userGroup !== '' && ldap_group_matches($userGroup, $groups)) {
        return [true, 'user', 'In the user group "' . $userGroup . '".'];
    }
    if ($adminGroup === '' && $userGroup === '') {
        return [true, (string) ($params['default_role'] ?? 'user'),
                'No groups are required, so anyone the directory authenticates gets in.'];
    }

    $wanted = array_values(array_filter([$userGroup, $adminGroup]));
    return [false, 'user', 'not a member of ' . implode(' or ', array_map(
        fn($g) => '"' . $g . '"', $wanted)) . '.'];
}

/**
 * Everything an administrator needs to answer "can this person sign in, and as
 * what?" without making them try it.
 */
function ldap_inspect_user(array $method, string $identifier): array
{
    $report = [
        'ok'         => false,
        'identifier' => $identifier,
        'found'      => false,
        'dn'         => null,
        'username'   => null,
        'name'       => null,
        'email'      => null,
        'groups'     => [],
        'allowed'    => false,
        'reason'     => null,
        'role'       => null,
        'access'     => null,
        'matched_group' => null,
        'local'      => null,
    ];

    $params = ldap_params($method);
    [$conn, $error] = ldap_open($params);
    if ($conn === null) {
        $report['reason'] = $error;
        return $report;
    }

    [$entry, $findError] = ldap_find_user($conn, $params, $identifier);
    if ($entry === null) {
        $report['reason'] = $findError;
        @ldap_unbind($conn);
        return $report;
    }

    $report['ok']       = true;
    $report['found']    = true;
    $report['dn']       = (string) $entry['dn'];
    $report['username'] = ldap_attr($entry, (string) $params['uid_attr']);
    $report['name']     = ldap_attr($entry, (string) $params['name_attr']);
    $report['email']    = ldap_attr($entry, (string) $params['mail_attr']);
    $report['groups']   = ldap_user_groups($conn, $params, $entry);
    @ldap_unbind($conn);

    [$allowed, $groupRole, $why] = ldap_decide_access($params, $report['groups']);
    $report['allowed'] = $allowed;
    $report['reason']  = $why;
    $report['role']    = $groupRole;

    // What the group mappings would grant.
    $mapping = ldap_resolve_mapping((int) $method['id'], $report['groups']);
    $report['role']          = $mapping['role'] ?? $params['default_role'];
    $report['access']        = $mapping['default_access'] ?? $params['default_access'];
    $report['matched_group'] = $mapping['group_name'] ?? null;

    if ($report['username'] !== null) {
        $report['local'] = one(
            'SELECT id, username, role, is_active FROM users WHERE username = ?',
            [$report['username']]
        );
    }

    return $report;
}

/** Group names the user belongs to, as both bare CNs and full DNs. */
function ldap_user_groups($conn, array $params, array $entry): array
{
    $groups = [];

    // Active Directory publishes memberOf directly on the user.
    $memberAttr = (string) $params['member_attr'];
    if ($memberAttr !== '' && isset($entry[strtolower($memberAttr)])) {
        $raw = $entry[strtolower($memberAttr)];
        $count = (int) ($raw['count'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            $groups[] = (string) $raw[$i];
        }
    }

    // OpenLDAP usually needs a reverse search on member.
    $groupBase = trim((string) $params['group_base_dn']);
    if ($groupBase === '') {
        $groupBase = (string) $params['base_dn'];
    }
    if ($groupBase !== '' && !empty($params['group_filter'])) {
        $filter = sprintf((string) $params['group_filter'], ldap_escape_filter((string) $entry['dn']));
        $search = @ldap_search($conn, $groupBase, $filter, [(string) $params['group_name_attr'], 'dn'], 0, 500, (int) $params['timeout']);
        if ($search !== false) {
            $rows = @ldap_get_entries($conn, $search);
            $count = (int) ($rows['count'] ?? 0);
            for ($i = 0; $i < $count; $i++) {
                $groups[] = (string) $rows[$i]['dn'];
                $name = ldap_attr($rows[$i], (string) $params['group_name_attr']);
                if ($name !== null) {
                    $groups[] = $name;
                }
            }
        }
    }

    // Include the bare CN of every DN we collected, so mappings can be written
    // either way round.
    foreach ($groups as $g) {
        if (preg_match('/^cn=([^,]+)/i', $g, $m)) {
            $groups[] = $m[1];
        }
    }

    return array_values(array_unique(array_filter($groups)));
}

/**
 * Authenticate against a directory.
 *
 * Returns ['ok' => bool, 'reason' => string, 'user' => array|null] where user
 * carries the attributes needed to create or refresh a local account.
 */
function ldap_authenticate(array $method, string $username, string $password): array
{
    // A seam for the tests, matching the one metadata_http_get() has. There is
    // no directory in the environment this is developed in, and the interesting
    // behaviour is what the caller does with each answer - accept, reject, or
    // silence - which cannot be exercised without being able to produce all
    // three on demand.
    if (isset($GLOBALS['ldap_authenticate_stub']) && is_callable($GLOBALS['ldap_authenticate_stub'])) {
        return ($GLOBALS['ldap_authenticate_stub'])($method, $username, $password);
    }

    // `reachable` says whether the directory answered at all.
    //
    // "The directory rejected that password" and "the directory is not
    // answering" are different events and only one of them means the person
    // should be turned away - a caller that has to tell them apart by reading
    // the prose will get it wrong the first time somebody rewords a message.
    $fail = fn(string $why, bool $reachable = true)
        => ['ok' => false, 'reason' => $why, 'user' => null, 'reachable' => $reachable];

    if ($password === '') {
        return $fail('Empty passwords are refused.');   // some servers treat this as anonymous
    }

    $params = ldap_params($method);
    [$conn, $err] = ldap_open($params);
    if ($conn === null) {
        return $fail($err ?? 'Could not reach the directory.', false);
    }

    try {
        [$entry, $err] = ldap_find_user($conn, $params, $username);
        if ($entry === null) {
            return $fail($err ?? 'Not found in the directory.');
        }

        $dn = (string) $entry['dn'];

        // The real test: bind as the user with the password they supplied.
        if (!@ldap_bind($conn, $dn, $password)) {
            return $fail('The directory rejected that password.');
        }

        // Re-bind as the service account before reading groups, since the user
        // may not be allowed to search.
        $bindDn = trim((string) $params['bind_dn']);
        if ($bindDn !== '') {
            @ldap_bind($conn, $bindDn, (string) $params['bind_password']);
        }

        $groups = ldap_user_groups($conn, $params, $entry);

        [$allowed, $groupRole, $whyNot] = ldap_decide_access($params, $groups);
        if (!$allowed) {
            return $fail('Authenticated, but ' . lcfirst($whyNot));
        }

        return [
            'ok'     => true,
            'reason' => 'ok',
            'user'   => [
                // The directory's own login attribute, not whatever was typed:
                // signing in with an email address should still produce an
                // account named after the user, not after their inbox.
                'username' => ldap_attr($entry, (string) $params['uid_attr']) ?: $username,
                // Which group let them in, so sync knows the role without
                // resolving the same groups a second time.
                'group_role' => $groupRole,
                'dn'       => $dn,
                'name'     => ldap_attr($entry, (string) $params['name_attr']),
                'email'    => ldap_attr($entry, (string) $params['mail_attr']),
                'uuid'     => ldap_attr($entry, (string) $params['uuid_attr']),
                'groups'   => $groups,
            ],
        ];
    } finally {
        @ldap_unbind($conn);
    }
}

/** Case-insensitive match of a mapping against a user's group list. */
function ldap_group_matches(string $wanted, array $groups): bool
{
    $wanted = strtolower(trim($wanted));
    foreach ($groups as $g) {
        $g = strtolower(trim((string) $g));
        if ($g === $wanted) {
            return true;
        }
        // Allow a bare CN to match a full DN and vice versa.
        if (preg_match('/^cn=([^,]+)/i', $g, $m) && $m[1] === $wanted) {
            return true;
        }
        if (preg_match('/^cn=([^,]+)/i', $wanted, $m) && $m[1] === $g) {
            return true;
        }
    }
    return false;
}

/**
 * Highest-priority group mapping that applies to this user.
 * Returns null when none match.
 */
function ldap_resolve_mapping(int $methodId, array $groups): ?array
{
    $maps = all('SELECT * FROM auth_group_map WHERE auth_method_id = ? ORDER BY priority, id', [$methodId]);
    foreach ($maps as $map) {
        if (ldap_group_matches((string) $map['group_name'], $groups)) {
            return $map;
        }
    }
    return null;
}

/**
 * Create or refresh the local account backing a directory user, then return it.
 *
 * The local row is a cache of the directory, not a second source of truth:
 * role and library access are re-derived from group membership on each sign-in
 * when sync_on_login is set.
 */
/**
 * @param bool $adopting  true when an existing local account is being taken over
 *                        by the directory for the first time. The role comes from
 *                        the directory then whatever `sync_on_login` says: that
 *                        setting governs whether *later* sign-ins keep re-reading
 *                        the directory, and this is not a later sign-in - it is
 *                        the moment the account changes hands. Without it a
 *                        converted account kept the role it had, so somebody in
 *                        the administrators group stayed an ordinary user and the
 *                        conversion looked like it had half worked.
 */
function ldap_sync_user(array $method, array $directoryUser, bool $adopting = false): ?array
{
    $params = ldap_params($method);
    $username = $directoryUser['username'];

    $existing = one('SELECT * FROM users WHERE username = ?', [$username]);

    if ($existing !== null && (int) $existing['auth_method_id'] !== (int) $method['id']) {
        // A local account already owns this name. Refuse rather than silently
        // handing a directory user control of it.
        return null;
    }

    if ($existing === null && empty($params['autocreate'])) {
        return null;
    }

    $mapping = ldap_resolve_mapping((int) $method['id'], $directoryUser['groups'] ?? []);

    $fields = [
        'display_name'    => $directoryUser['name'] ?: $username,
        'email'           => $directoryUser['email'],
        'external_dn'     => mb_substr((string) $directoryUser['dn'], 0, 512),
        'external_uid'    => $directoryUser['uuid'] === null ? null : mb_substr($directoryUser['uuid'], 0, 190),
        'external_groups' => json_encode(array_values($directoryUser['groups'] ?? []), JSON_UNESCAPED_UNICODE),
        'last_sync_at'    => date('Y-m-d H:i:s'),
        'is_active'       => 1,
    ];

    // The instance role is all that lives on the account now. What the person
    // can reach is a membership row per library, granted below.
    // Most specific first: an explicit group mapping beats the two general
    // group fields, which beat the method's default. The other order would make
    // a carefully written mapping silently useless.
    $wantedRole = $mapping['role']
        ?? $directoryUser['group_role']
        ?? (string) $params['default_role'];
    // The enum has two values; anything else - a mapping written under the old
    // three-role model, say - must not reach the column and abort the sign-in.
    if (!in_array($wantedRole, ['admin', 'user'], true)) {
        $wantedRole = 'user';
    }
    $wantedAccess = (string) ($mapping['default_access'] ?? $params['default_access'] ?? ACCESS_NONE);
    if (!in_array($wantedAccess, [ACCESS_NONE, ACCESS_VIEWER, ACCESS_CONTRIBUTOR, ACCESS_EDITOR, ACCESS_CURATOR, ACCESS_ADMIN], true)) {
        $wantedAccess = ACCESS_NONE;
    }

    if ($existing === null) {
        $fields += [
            'username'       => $username,
            'auth_method_id' => (int) $method['id'],
            'password_hash'  => null,
            'auto_created'   => 1,
            'role'           => $wantedRole,
        ];
        $userId = insert_row('users', $fields);
    } else {
        $userId = (int) $existing['id'];
        if ($adopting || !empty($params['sync_on_login'])) {
            $fields['role'] = $wantedRole;
        }
        update_row('users', $userId, $fields);
    }

    // Membership of the directory's default library. Without this a directory
    // user signs in successfully and then finds an empty catalogue, which looks
    // exactly like a broken install.
    $defaultLibraryId = (int) ($params['default_library_id'] ?? 0);

    // No fallback at all.
    //
    // This has been wrong twice. It began as "the first library on the instance", which
    // is the administrator's personal shelf - so every directory user was quietly given
    // somebody else's collection. Narrowing it to shared libraries fixed the privacy
    // problem and left a subtler one: the first shared library is usually the example
    // club shelf, so directory users were still joined to something they never asked
    // for - which is exactly what opt-in joining exists to prevent.
    //
    // A configured default_library_id is still honoured, because that is an
    // administrator deciding where their directory's people belong. Absent one, nobody
    // is added to anything: the account gets its own shelf from ensure_first_library(),
    // and anything published is offered under "Open to join" for them to take or leave.
    if ($defaultLibraryId > 0) {
        // Never a personal shelf, whoever typed the id: that is somebody's private
        // collection, not a landing place.
        $named = one('SELECT id, is_personal FROM libraries WHERE id = ?', [$defaultLibraryId]);
        if ($named === null || (int) $named['is_personal'] === 1) {
            $defaultLibraryId = 0;
        }
    }
    if ($defaultLibraryId > 0 && $wantedAccess !== 'none') {
        q('INSERT INTO library_members (library_id, user_id, access, note)
           VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE access = VALUES(access)',
          [$defaultLibraryId, $userId, $wantedAccess, 'Granted by directory sign-in']);
    }

    // Per-library grants attached to the matched group. These are the point of
    // enabling directory sign-in at all: an AD group decides which shelves
    // somebody lands in, so access is managed where the users already are.
    //
    // Only rewritten when sync_on_login is set, or on first creation. Otherwise
    // a grant an administrator added by hand would be wiped on the next login.
    if ($mapping !== null && ($existing === null || !empty($params['sync_on_login']))) {
        $granted = all(
            'SELECT library_id, access FROM auth_group_library_access WHERE group_map_id = ?',
            [(int) $mapping['id']]
        );
        foreach ($granted as $grant) {
            q('INSERT INTO library_members (library_id, user_id, access, note)
               VALUES (?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE access = VALUES(access)',
              [(int) $grant['library_id'], $userId, (string) $grant['access'],
               'Granted by directory group ' . mb_substr((string) $mapping['group_name'], 0, 180)]);
        }
    }

    // Memberships are memoised per request; this account's just changed.
    $GLOBALS['__membership_cache'] = [];
    unset($GLOBALS['retrovault_access_cache']);

    return one('SELECT * FROM users WHERE id = ?', [$userId]);
}

/**
 * Connection test for the admin screen: does the service account bind, and can
 * it see anything? Returns [ok, message, details].
 */
function ldap_test_connection(array $method): array
{
    if (!ldap_available()) {
        return [false, 'The PHP ldap extension is not installed. Install php-ldap and restart Apache.', []];
    }
    $params = ldap_params($method);
    [$conn, $err] = ldap_open($params);
    if ($conn === null) {
        return [false, $err ?? 'Could not connect.', []];
    }

    $details = ['bind' => 'Service account bound successfully.'];

    try {
        $base = trim((string) $params['users_base_dn']) ?: (string) $params['base_dn'];
        $search = @ldap_search($conn, $base, '(' . $params['uid_attr'] . '=*)', ['dn'], 0, 5, (int) $params['timeout']);
        if ($search === false) {
            return [false, 'Bound, but searching ' . $base . ' failed: ' . ldap_error($conn), $details];
        }
        $rows = @ldap_get_entries($conn, $search);
        $details['users_visible'] = (int) ($rows['count'] ?? 0) . ' user entries visible (showing at most 5).';


        return [true, 'Connection works.', $details];
    } finally {
        @ldap_unbind($conn);
    }
}
