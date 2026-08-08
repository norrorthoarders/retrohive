<?php
declare(strict_types=1);

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // request_is_https() honours the proxy headers, but only from a trusted
    // proxy, so a cookie never loses Secure just because TLS ended upstream.
    $secure = request_is_https();

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_PATH === '' ? '/' : BASE_PATH,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);
    session_name('retrohive');
    session_start();
}

function current_user(bool $forget = false): ?array
{
    static $cached = false;
    static $user = null;
    // Signing in is the one moment the answer changes mid-request: the page was
    // resolved as nobody before the session existed, and the memo would keep saying so
    // for the rest of the request - which is why "Signed in" was logged with no actor.
    if ($forget) {
        $cached = false;
        $user   = null;
    }
    if ($cached) {
        return $user;
    }
    $cached = true;
    $id = $_SESSION['user_id'] ?? null;
    if ($id === null) {
        return null;
    }
    $user = one('SELECT id, username, display_name, avatar_filename, avatar_pending_filename, email, role, auth_method_id, is_active FROM users WHERE id = ? AND is_active = 1', [(int) $id]);
    if ($user === null) {
        unset($_SESSION['user_id']);
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/**
 * May this account change anything at all?
 *
 * The role does not answer this and has not since access moved to library
 * membership: 'admin' means "may configure the instance", nothing more. An
 * administrator with no membership anywhere can edit nothing, which is the
 * intended reading of acl.php - and testing for a long-deleted 'editor' role
 * here meant every ordinary account was refused before the ACL was consulted.
 */
function can_edit(): bool
{
    return current_user() !== null && can_edit_anything();
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

/** Guard for any route that writes. */
/**
 * Guard for any route that writes.
 *
 * Two different situations, and telling them apart matters: somebody who is not
 * signed in needs the sign-in page, and somebody who is signed in but has no
 * library to write to needs to be told that, not sent to a form they have
 * already filled in. It used to say "sign in with an editor account" to both -
 * naming a role that no longer exists, at a person who was already signed in as
 * an administrator.
 */
function require_edit(): void
{
    if (current_user() === null) {
        // /login was a screen here and is not any more. The `next` parameter
        // went with it: this application cannot hand somebody back to a page it
        // no longer draws.
        flash('error', 'Sign in to change the collection.');
        to_client();
    }
    if (!can_edit()) {
        flash('error', is_admin()
            ? 'You have no library you can write to. Make one, or add yourself to an existing one.'
            : 'You have read-only access. Ask an owner to invite you as a contributor.');
        redirect(is_admin() ? '/libraries' : '/');
    }
}

function require_admin(): void
{
    if (!is_admin()) {
        flash('error', 'That area is for administrators.');
        to_client();
    }
}

function user_count(): int
{
    return (int) scalar('SELECT COUNT(*) FROM users');
}

function log_auth_attempt(string $username, ?int $methodId, bool $ok, string $reason): void
{
    try {
        insert_row('auth_log', [
            'username'       => mb_substr($username, 0, 190),
            'auth_method_id' => $methodId,
            'succeeded'      => $ok ? 1 : 0,
            'reason'         => mb_substr($reason, 0, 190),
            'client_ip'      => mb_substr(client_ip(), 0, 45),
            'user_agent'     => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('[retrohive] could not write auth_log: ' . $e->getMessage());
    }
}

/**
 * Verify a username and password against whichever backend owns the account.
 *
 * Order of resolution:
 *   1. If a local row exists, use the method recorded on it.
 *   2. Otherwise try each enabled directory method in turn, so a user who has
 *      never signed in can still be created on first use.
 *
 * Returns the user row on success, or null.
 */
/**
 * Resolve whatever was typed in the sign-in box to a local account.
 *
 * Username first, because that is unambiguous. Email second, and only when it
 * picks out exactly one account: the column is not unique, so two people
 * sharing an address must not become a coin toss over whose account you get.
 */
function find_local_account(string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    $row = one('SELECT * FROM users WHERE username = ?', [$identifier]);
    if ($row !== null) {
        return $row;
    }

    if (str_contains($identifier, '@')) {
        $matches = all('SELECT * FROM users WHERE email = ?', [$identifier]);
        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            log_auth_attempt($identifier, 1, false, 'email matches more than one account');
            return null;
        }
    }

    return null;
}

/**
 * @param array|null $via  set to the auth_methods row that accepted the credentials, so
 *                         the sign-in can be logged as local or directory rather than
 *                         just "signed in".
 */
function verify_credentials(string $username, string $password, ?array &$via = null): ?array
{
    $via = null;
    $row = find_local_account($username);

    if ($row !== null) {
        if ((int) $row['is_active'] !== 1) {
            // Never signed in and the instance holds new accounts: this is not a
            // disabled account, it is one nobody has looked at yet, and telling
            // somebody their account is disabled when it is merely waiting sends
            // them to argue with the wrong person.
            $waiting = ($row['last_login_at'] ?? null) === null
                && (string) setting('registration_approval', 'auto') === 'admin';
            log_auth_attempt($username, (int) $row['auth_method_id'], false,
                             $waiting ? 'waiting for an administrator' : 'account disabled');
            return null;
        }
        $method = one('SELECT * FROM auth_methods WHERE id = ?', [(int) $row['auth_method_id']]);

        if ($method === null || $method['type'] === 'local') {
            // The directory first, if there is one.
            //
            // An account made here before Active Directory was switched on is
            // the same person as the one in the directory, and once the
            // directory is answering it should be what decides - otherwise a
            // password changed in AD keeps working here for as long as the old
            // local one is on file, which is the opposite of what turning AD on
            // was meant to achieve.
            //
            // Only a directory that *accepts* the password converts anything. A
            // rejection leaves the account exactly as it was and falls through
            // to the local password below, because the person may simply not be
            // in the directory at all.
            foreach (all("SELECT * FROM auth_methods WHERE is_enabled = 1 AND type <> 'local'
                           ORDER BY sort_order, id") as $dir) {
                $try = ldap_authenticate($dir, (string) $row['username'], $password);
                if (!($try['ok'] ?? false)) {
                    continue;
                }
                // Not gated on the sync.
                //
                // ldap_sync_user() is what *creates* an account from a directory
                // entry, and it declines when autocreation is off - which is a
                // sensible default and has nothing to do with this case. The
                // account already exists and the directory has just accepted its
                // owner's password; refusing to convert because a creation
                // policy said no left the person signed out of their own
                // catalogue with a correct password.
                // The account moves *first*, then the sync runs.
                //
                // ldap_sync_user() refuses outright when the account it finds
                // belongs to a different method - it will not let a directory
                // take over a local name - and that refusal happens before any
                // group mapping. So converting after it meant the account moved
                // and the role did not: somebody in the AD group that grants
                // administrator stayed an ordinary user.
                //
                // Moving it first makes the account the directory's, which is
                // exactly what has just been proved by the bind, and the sync
                // then applies the groups, the display name and the role.
                q('UPDATE users SET auth_method_id = ? WHERE id = ?', [(int) $dir['id'], (int) $row['id']]);
                // Adopting, so the role comes from the directory. This is the
                // moment the account changes hands, and somebody in the
                // administrators group who converts should be an administrator
                // here rather than still whatever they were before.
                ldap_sync_user($dir, $try['user'], true);
                ldap_cache_password((int) $row['id'], $password);
                log_auth_attempt($username, (int) $dir['id'], true, 'ok, account moved to the directory');
                log_security('auth.converted',
                    sprintf('%s now signs in through %s', (string) $row['username'], (string) $dir['name']),
                    LOG_NOTICE, ['user' => (int) $row['id'], 'method' => (int) $dir['id']]);
                $via = $dir;
                return one('SELECT * FROM users WHERE id = ?', [(int) $row['id']]);
            }

            if ($row['password_hash'] === null || $row['password_hash'] === '') {
                log_auth_attempt($username, (int) $row['auth_method_id'], false, 'no local password set');
                return null;
            }
            if (!password_verify($password, (string) $row['password_hash'])) {
                log_auth_attempt($username, (int) $row['auth_method_id'], false, 'bad password');
                return null;
            }
            log_auth_attempt($username, (int) $row['auth_method_id'], true, 'ok');
            $via = $method;
            return $row;
        }

        if ((int) $method['is_enabled'] !== 1) {
            // Turned off, not gone. The accounts it used to authenticate are
            // still here, and the cached password is the only thing they have -
            // switching the directory off should stop it being consulted, not
            // lock out everybody who ever used it.
            if ((string) setting('ldap_password_cache', '1') === '1'
                && $row['password_hash'] !== null && $row['password_hash'] !== ''
                && password_verify($password, (string) $row['password_hash'])) {
                log_auth_attempt($username, (int) $method['id'], true,
                                 'directory switched off, cached password accepted');
                $via = $method;
                return $row;
            }
            log_auth_attempt($username, (int) $method['id'], false, 'auth method disabled');
            return null;
        }
        // Matched by email, so hand the directory the account's own username
        // rather than whatever was typed.
        $result = ldap_authenticate($method, (string) $row['username'], $password);
        if (!$result['ok']) {
            // The cached password, and only when the directory did not answer.
            //
            // A directory that says no means no - somebody disabled in AD must
            // not get in here because their old password still matches. A
            // directory that is unreachable says nothing at all, and locking
            // everybody out of their own catalogue because a domain controller
            // is rebooting is its own kind of failure.
            if (($result['reachable'] ?? true) === false
                && (string) setting('ldap_password_cache', '1') === '1'
                && $row['password_hash'] !== null && $row['password_hash'] !== ''
                && password_verify($password, (string) $row['password_hash'])) {
                log_auth_attempt($username, (int) $method['id'], true,
                                 'directory unreachable, cached password accepted');
                $via = $method;
                return $row;
            }
            log_auth_attempt($username, (int) $method['id'], false, $result['reason']);
            return null;
        }
        // Kept fresh on every successful sign-in, so the fallback above is the
        // password they last used rather than one from a year ago.
        ldap_cache_password((int) $row['id'], $password);
        $synced = ldap_sync_user($method, $result['user']);
        log_auth_attempt($username, (int) $method['id'], $synced !== null, $synced === null ? 'sync refused' : 'ok');
        $via = $synced === null ? null : $method;
        return $synced;
    }

    // No local row: offer whatever was typed to each enabled directory in turn.
    // It may be a username, an email address, DOMAIN\user or a UPN; the
    // directory search understands all of them.
    foreach (all("SELECT * FROM auth_methods WHERE is_enabled = 1 AND type <> 'local' ORDER BY sort_order, id") as $method) {
        $result = ldap_authenticate($method, $username, $password);
        if ($result['ok']) {
            $synced = ldap_sync_user($method, $result['user']);
            log_auth_attempt($username, (int) $method['id'], $synced !== null, $synced === null ? 'autocreate disabled' : 'ok');
            if ($synced !== null) {
                $via = $method;
                return $synced;
            }
        }
    }

    // Constant-ish time for unknown users so the response does not reveal
    // whether the account exists.
    password_verify($password, '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30M1MYF5uu.');
    log_auth_attempt($username, null, false, 'unknown user');
    return null;
}

/**
 * Sign in, or explain why not.
 *
 * Returns true, or false with the reason in $reason - because "wrong password"
 * and "confirm your address first" need different things from the person, and
 * telling them the wrong one wastes their afternoon.
 */
function attempt_login(string $username, string $password, ?string &$reason = null,
                       ?array &$via = null): bool
{
    $reason = null;
    $user = verify_credentials($username, $password, $via);
    if ($user === null) {
        return false;
    }

    if (needs_email_verification($user)) {
        $reason = 'unverified';
        return false;
    }
    // Only when there is a session to regenerate. There is not, at a shell
    // prompt or in a test, and warning about it there is noise.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = (int) $user['id'];
    current_user(true);   // the memo said "nobody" a moment ago
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [(int) $user['id']]);

    // Self-healing, and it earns its keep: an install that fell over between
    // creating the administrator and creating their library left an account
    // that could not write anything, with nothing in the interface to fix it.
    // Accounts also arrive from LDAP, which has no idea about any of this.
    if (function_exists('ensure_first_library')) {
        ensure_first_library((int) $user['id']);
        $GLOBALS['__membership_cache'] = [];
    }

    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Create a local account.
 *
 * The address is required, not optional. An account that cannot be reached
 * cannot be told it has been invited to anything, cannot recover a password,
 * and cannot be verified if verification is ever switched on - and finding that
 * out later means going back to every account that was created without one.
 *
 * @throws InvalidArgumentException when the address is missing or already used.
 */
function create_user(
    string $username,
    string $password,
    string $displayName,
    string $role = 'admin',
    string $email = ''
): int {
    $email = trim($email);
    if ($email === '') {
        throw new InvalidArgumentException('An email address is required.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('That does not look like an email address.');
    }
    if (one('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
        throw new InvalidArgumentException('Another account already uses that address.');
    }

    $newId = insert_row('users', [
        'username'      => $username,
        'auth_method_id' => 1,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'display_name'  => $displayName !== '' ? $displayName : $username,
        'email'         => $email,
        'role'          => $role,
        'is_active'     => 1,
        // The first administrator is trusted by definition: they are the person
        // running the install, and there is nobody else to vouch for them.
        // Everyone else proves their address if the instance asks for it.
        'email_verified_at' => (int) scalar('SELECT COUNT(*) FROM users') === 0
            ? date('Y-m-d H:i:s') : null,
    ]);

    // A first administrator with nowhere to put anything is not a usable
    // install. Three routes reach this - the web installer, bin/create-user.php
    // and the first-run /setup page - so the guarantee belongs here, once,
    // rather than in each of them.
    // Every account, not only administrators: the personal library is the one
    // shelf everyone is promised, and an account without one cannot catalogue
    // anything at all.
    if (function_exists('ensure_first_library')) {
        ensure_first_library((int) $newId);
    }

    return $newId;
}

// ---------------------------------------------------------------------------
// Email verification
//
// Off unless an administrator turns it on, and they cannot turn it on until a
// mail relay is configured - requiring people to click a link nobody can send
// them locks everybody out, including the administrator who did it.
//
// Applies to local accounts only. A directory account has already been vouched
// for by the directory; that is what a directory is. Verifying them again would
// mean an LDAP estate could not sign in until every one of them had answered an
// email, which is not an improvement.
// ---------------------------------------------------------------------------

function email_verification_required(): bool
{
    // Proved, not merely configured. A host typed into a box is a guess until
    // something comes back from it, and locking everybody out on the strength
    // of a guess is the failure this whole feature is trying to avoid.
    // Either switch asks for the same thing: the old instance-wide one, and
    // "confirm the address" as the answer to what happens after a sign-up. Two
    // settings meaning one rule, so both are read here rather than one of them
    // being quietly ignored.
    $asked = (string) setting('require_email_verification', '0') === '1'
        || (string) setting('registration_approval', 'auto') === 'email';
    return $asked && function_exists('mail_verified') && mail_verified();
}

function is_local_account(array $user): bool
{
    return (int) ($user['auth_method_id'] ?? 1) === 1;
}

/** Does this account still need to prove its address before signing in? */
function needs_email_verification(array $user): bool
{
    return email_verification_required()
        && is_local_account($user)
        && ($user['email_verified_at'] ?? null) === null;
}

/**
 * Issue a fresh token and email it. Returns [ok, message].
 *
 * Sent inline rather than queued, unlike everything else: somebody is sitting
 * in front of the screen waiting to find out whether it worked, and "it will go
 * out within five minutes" is not an answer they can act on.
 */
function send_verification_email(int $userId): array
{
    $user = one('SELECT * FROM users WHERE id = ?', [$userId]);
    if ($user === null) {
        return [false, 'No such account.'];
    }
    $address = trim((string) ($user['email'] ?? ''));
    if ($address === '') {
        return [false, 'That account has no address to send to.'];
    }
    if (!function_exists('mail_enabled') || !mail_enabled()) {
        return [false, 'No mail relay is configured, so nothing can be sent.'];
    }

    // Stored as a hash. A stolen database should not contain working links.
    $token = bin2hex(random_bytes(32));
    q('UPDATE users SET verify_token = ?, verify_sent_at = NOW() WHERE id = ?',
      [hash('sha256', $token), $userId]);

    // Where the link points.
    //
    // `site_url` is the engine's own address, and the link has always been built
    // from it - which is right while the engine serves the screens. It is not
    // right once the web client is what people use: the address in the mail
    // would land on a page that is being retired, and a confirmation link that
    // 404s is an account nobody can finish making.
    //
    // `client_url` names where people actually go, and falls back to `site_url`
    // when it is not set - so an instance that has never heard of this setting
    // behaves exactly as before.
    $base = rtrim((string) setting('client_url', ''), '/');
    if ($base === '') {
        $base = rtrim((string) setting('site_url', ''), '/');
    }
    $link = $base === ''
        ? '(this instance has no address configured, so there is no link to give you)'
        : $base . '/verify?token=' . $token;

    [$ok, $error] = smtp_send(
        $address,
        'Confirm your address for RetroHive',
        "Somebody - probably you - created an account on RetroHive with this address.\n\n"
        . "Open this to confirm it:\n\n" . $link . "\n\n"
        . "If it was not you, ignore this. The address will not be used for anything else."
    );

    return $ok ? [true, 'Sent to ' . $address . '.'] : [false, $error];
}

/** Redeem a token. Returns [ok, message]. */
function verify_email_token(string $token): array
{
    $token = trim($token);
    if ($token === '' || !ctype_xdigit($token)) {
        return [false, 'That link is not valid.'];
    }
    $user = one('SELECT * FROM users WHERE verify_token = ?', [hash('sha256', $token)]);
    if ($user === null) {
        return [false, 'That link has already been used, or it is not valid.'];
    }

    // No expiry on purpose. A link that quietly stops working leaves somebody
    // locked out with nothing to click, and the token is single-use and
    // unguessable, which is what the expiry would have been protecting.
    q('UPDATE users SET email_verified_at = NOW(), verify_token = NULL WHERE id = ?', [(int) $user['id']]);

    return [true, 'Thank you - your address is confirmed. You can sign in now.'];
}

/** An administrator vouching for somebody by hand. */
function force_verify_email(int $userId): void
{
    q('UPDATE users SET email_verified_at = NOW(), verify_token = NULL WHERE id = ?', [$userId]);
}


/**
 * Remember a directory password locally, so a sign-in still works when the
 * directory does not answer.
 *
 * The same hash a local account would have - this is not a second, weaker store,
 * it is the existing column being kept current. It is only ever *read* when the
 * directory is unreachable, never when it answers "no": an account disabled in
 * the directory has to stop working here immediately, and it does.
 *
 * Off with `ldap_password_cache` set to anything but 1, for an instance that
 * would rather be locked out than hold a copy.
 */
function ldap_cache_password(int $userId, string $password): void
{
    if ((string) setting('ldap_password_cache', '1') !== '1' || $password === '') {
        return;
    }
    q('UPDATE users SET password_hash = ? WHERE id = ?',
      [password_hash($password, PASSWORD_DEFAULT), $userId]);
}
