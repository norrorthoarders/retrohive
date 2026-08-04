<?php
declare(strict_types=1);

/**
 * Sign-in rate limiting.
 *
 * auth_log already records every attempt with its client IP and outcome, so the
 * evidence was there; nothing read it. On a LAN that is survivable. Exposed to
 * the internet it means unlimited password guesses against an account that, for
 * a directory user, is the same password as their AD login.
 *
 * Two independent limits, because they catch different attacks:
 *   - per username, which stops someone grinding one account from a botnet
 *   - per IP, which stops someone spraying one password across many accounts
 *
 * Successful sign-ins clear the count for that username, so a person who
 * finally remembers their password is not left locked out by their own typos.
 */

const THROTTLE_WINDOW_MINUTES   = 15;
const THROTTLE_USER_ATTEMPTS    = 6;
const THROTTLE_IP_ATTEMPTS      = 25;
const THROTTLE_LOCKOUT_SECONDS  = 900;

/**
 * Failures for this username since its last success, inside the window.
 * Counting from the last success is what makes a good sign-in forgiving.
 */
function throttle_user_failures(string $username): int
{
    // Compared by row id, not timestamp: created_at only resolves to the
    // second, so a failure logged in the same second as a success would
    // otherwise be discounted - and a burst inside one second is exactly what
    // an attacker produces.
    return (int) scalar(
        "SELECT COUNT(*) FROM auth_log
          WHERE username = ?
            AND succeeded = 0
            AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            AND id > COALESCE(
                (SELECT MAX(id) FROM auth_log a2
                  WHERE a2.username = ? AND a2.succeeded = 1),
                0)",
        [$username, THROTTLE_WINDOW_MINUTES, $username]
    );
}

function throttle_ip_failures(string $ip): int
{
    if ($ip === '') {
        return 0;
    }
    return (int) scalar(
        "SELECT COUNT(*) FROM auth_log
          WHERE client_ip = ? AND succeeded = 0
            AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
        [$ip, THROTTLE_WINDOW_MINUTES]
    );
}

/**
 * Is this attempt allowed? Returns [allowed, secondsToWait, reason].
 *
 * The reason is for the log, not for the person: telling an attacker which of
 * the two limits they hit tells them whether the username exists.
 */
function throttle_check(string $username, ?string $ip = null): array
{
    $ip = $ip ?? client_ip();

    try {
        $userFailures = throttle_user_failures($username);
        $ipFailures   = throttle_ip_failures((string) $ip);
    } catch (Throwable $e) {
        // A throttle that cannot read its own log must not lock everyone out.
        error_log('[retrohive] throttle check failed: ' . $e->getMessage());
        return [true, 0, null];
    }

    if ($userFailures >= THROTTLE_USER_ATTEMPTS) {
        return [false, throttle_wait_seconds($username, null), 'too many failures for this username'];
    }
    if ($ipFailures >= THROTTLE_IP_ATTEMPTS) {
        return [false, throttle_wait_seconds(null, (string) $ip), 'too many failures from this address'];
    }

    return [true, 0, null];
}

/** How long until the oldest counted failure falls out of the window. */
function throttle_wait_seconds(?string $username, ?string $ip): int
{
    $sql = "SELECT MIN(created_at) FROM auth_log
             WHERE succeeded = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)";
    $args = [THROTTLE_WINDOW_MINUTES];

    if ($username !== null) {
        $sql .= ' AND username = ?';
        $args[] = $username;
    }
    if ($ip !== null) {
        $sql .= ' AND client_ip = ?';
        $args[] = $ip;
    }

    $oldest = scalar($sql, $args);
    if ($oldest === null) {
        return THROTTLE_LOCKOUT_SECONDS;
    }
    $elapsed = time() - strtotime((string) $oldest);
    $left    = (THROTTLE_WINDOW_MINUTES * 60) - $elapsed;

    return max(30, min(THROTTLE_LOCKOUT_SECONDS, $left));
}

/** Phrase a wait in a way that does not need a calculator. */
function throttle_message(int $seconds): string
{
    if ($seconds >= 120) {
        return 'Too many failed sign-in attempts. Try again in about ' . (int) ceil($seconds / 60) . ' minutes.';
    }
    return 'Too many failed sign-in attempts. Try again in about ' . max(30, $seconds) . ' seconds.';
}

/**
 * Clear the record for a username after a successful sign-in.
 *
 * Not strictly needed, since throttle_user_failures only counts failures after
 * the last success, but it keeps the table from growing without bound and
 * makes the intent obvious to anyone reading the log.
 */
function throttle_prune(int $keepDays = 30): int
{
    try {
        $st = db()->prepare('DELETE FROM auth_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
        $st->execute([$keepDays]);
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}
