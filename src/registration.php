<?php
/**
 * Who may make an account here.
 *
 * Four modes, because "can anyone sign up" has four honest answers:
 *
 *   closed  only an administrator creates accounts
 *   public  a link on the sign-in page, anybody may use it
 *   secret  no link anywhere; you need an address containing a token
 *   invite  an administrator sends an invitation to one address
 *
 * The mode is the only thing that decides. Every entry point asks it rather than
 * carrying its own opinion, so a mode cannot be open on one route and shut on
 * another.
 */
declare(strict_types=1);

const REGISTRATION_MODES = ['closed', 'public', 'secret', 'invite'];

/** The mode in force, always one of the four. */
function registration_mode(): string
{
    $mode = (string) setting('registration_mode', 'closed');
    return in_array($mode, REGISTRATION_MODES, true) ? $mode : 'closed';
}

/**
 * The token in the secret address, made on first use.
 *
 * Not shipped in a migration and not derived from anything: a value that comes
 * with the software is a value every install shares, and the whole point of this
 * one is that only the people who were told it know it.
 */
function registration_secret(): string
{
    $secret = trim((string) setting('registration_secret', ''));
    if ($secret === '') {
        $secret = bin2hex(random_bytes(16));
        set_setting('registration_secret', $secret);
    }
    return $secret;
}

/** A new one, for when the old one has been shared too widely. */
function registration_secret_rotate(): string
{
    $secret = bin2hex(random_bytes(16));
    set_setting('registration_secret', $secret);
    return $secret;
}

/** The address to hand out, in secret mode. */
function registration_secret_url(): string
{
    return base_url() . '/join/' . registration_secret();
}

/**
 * Is this mode actually usable?
 *
 * Invitations go out by email, so choosing that mode without a relay configured
 * would be a sign-up page nobody can ever reach. Said here once rather than
 * discovered at the point somebody tries to invite their first person.
 */
function registration_mode_blocked(string $mode): ?string
{
    if ($mode === 'invite' && !mail_enabled()) {
        return 'Invitations are sent by email, so the SMTP relay has to be '
             . 'working first — Instance settings → SMTP relay.';
    }
    return null;
}

/** Should the sign-in page offer a way in? Only when the way in is a public one. */
function registration_link_shown(): bool
{
    // Never in secret mode. A link to the secret address on the page everybody
    // sees is not a secret, it is a longer public URL.
    return registration_mode() === 'public';
}

/**
 * May this request open the registration form, and under what name?
 *
 * Returns [true, invite-or-null] or [false, reason]. One function, so a route
 * cannot accidentally be more generous than the mode.
 */
function registration_allowed(string $route, string $token = ''): array
{
    $mode = registration_mode();

    if ($mode === 'closed') {
        return [false, 'This catalogue is not open for registration.'];
    }
    if ($mode === 'public') {
        return $route === 'register'
            ? [true, null]
            : [false, 'This catalogue is not open for registration.'];
    }
    if ($mode === 'secret') {
        if ($route !== 'join') {
            return [false, 'This catalogue is not open for registration.'];
        }
        // hash_equals, because comparing a secret with == leaks its length and
        // its prefix to anybody willing to time the answers.
        return hash_equals(registration_secret(), $token)
            ? [true, null]
            : [false, 'This catalogue is not open for registration.'];
    }

    // invite
    if ($route !== 'invite') {
        return [false, 'This catalogue is by invitation.'];
    }
    $invite = invite_find($token);
    if ($invite === null) {
        return [false, 'That invitation is not one this catalogue issued.'];
    }
    if ($invite['used_at'] !== null) {
        return [false, 'That invitation has already been used.'];
    }
    if (strtotime((string) $invite['expires_at']) < time()) {
        return [false, 'That invitation has expired. Ask for another.'];
    }
    return [true, $invite];
}

/**
 * What happens once somebody has signed up.
 *
 * "Who may create an account" said nothing about whether the account works, so a
 * public sign-up produced a user the account list called unconfirmed who could
 * sign in regardless - the screen and the behaviour disagreeing about the same
 * row.
 *
 *   auto   in straight away
 *   email  must confirm the address first
 *   admin  must be let in by an administrator
 */
function registration_approval(): string
{
    $mode = (string) setting('registration_approval', 'auto');
    if (!in_array($mode, ['auto', 'email', 'admin'], true)) {
        return 'auto';
    }
    // Confirming an address needs something able to send to it. Falling back to
    // admin rather than auto: the instance asked for a check, and the safe
    // reading of "the check cannot run" is not "let everybody in".
    if ($mode === 'email' && !(function_exists('mail_verified') && mail_verified())) {
        return 'admin';
    }
    return $mode;
}

/** Applied to the account just created. Returns what to tell the person. */
function registration_apply_approval(int $userId): string
{
    switch (registration_approval()) {
        case 'email':
            q('UPDATE users SET is_active = 1, email_verified_at = NULL WHERE id = ?', [$userId]);
            [$sent, $why] = send_verification_email($userId);
            return $sent
                ? 'Check your email and follow the link to finish signing in.'
                : 'Your account is made, but the confirmation email did not go: ' . $why;

        case 'admin':
            // Inactive, which is what every other screen already understands as
            // "cannot sign in" - no second flag to keep in step with the first.
            q('UPDATE users SET is_active = 0 WHERE id = ?', [$userId]);
            return 'Your account is made and is waiting for an administrator to let you in.';

        default:
            q('UPDATE users SET is_active = 1, email_verified_at = NOW() WHERE id = ?', [$userId]);
            return '';
    }
}

// --- Invitations -----------------------------------------------------------

/** Make one, and hand back the plaintext exactly once. */
function invite_create(string $email, int $byUserId, int $days = 14): array
{
    $plain = bin2hex(random_bytes(24));
    $id = insert_row('invites', [
        'email'      => mb_substr(trim($email), 0, 190),
        'token_hash' => hash('sha256', $plain),
        'prefix'     => substr($plain, 0, 12),
        'created_by' => $byUserId,
        'expires_at' => date('Y-m-d H:i:s', time() + ($days * 86400)),
    ]);
    return [(int) $id, $plain];
}

/** The invitation a token names, or null. */
function invite_find(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    return one('SELECT * FROM invites WHERE token_hash = ?', [hash('sha256', $token)]);
}

/** Spent, and pointed at the account it made. */
function invite_redeem(int $inviteId, int $userId): void
{
    q('UPDATE invites SET used_at = NOW(), user_id = ? WHERE id = ? AND used_at IS NULL',
      [$userId, $inviteId]);
}

/** The address to send somebody. */
function invite_url(string $token): string
{
    return base_url() . '/invite/' . $token;
}

// --- Search engines --------------------------------------------------------

/** Does this instance want to be found? */
function search_indexing_allowed(): bool
{
    return (string) setting('search_indexing', 'discourage') === 'allow';
}

/**
 * robots.txt, generated rather than a file on disk.
 *
 * The secret address is never written here, and that is the whole point of the
 * function existing. A `Disallow: /join/<token>` line publishes the token to
 * everybody who reads robots.txt, which is a larger audience than the people it
 * was given to - the file is the first thing a crawler asks for and the first
 * thing a curious person looks at. The prefix is disallowed instead, which says
 * "nothing under here" without saying what is under there.
 */
function robots_txt(): string
{
    $lines = ['User-agent: *'];

    if (!search_indexing_allowed()) {
        $lines[] = 'Disallow: /';
        return implode("\n", $lines) . "\n";
    }

    // Indexing allowed: the catalogue may be crawled, but the ways in may not.
    foreach (['/join/', '/invite/', '/register', '/login', '/setup', '/admin/', '/manage/',
              '/api/', '/profile/'] as $path) {
        $lines[] = 'Disallow: ' . $path;
    }
    return implode("\n", $lines) . "\n";
}
