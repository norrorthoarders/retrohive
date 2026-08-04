<?php
declare(strict_types=1);

/**
 * Notifications.
 *
 * One row per thing that happened to one person, and one place that decides
 * whether it reaches them. Three readers share it: the web interface, the API a
 * native client polls, and the mailer.
 *
 * The shape is deliberately dull — a kind, a subject, a body, a link — because
 * the interesting part is the routing, and a schema that has to change to
 * announce something new is a schema that stops being used.
 *
 * Nothing is sent from inside a request. notify() writes a row; mail is queued
 * and flushed by bin/notify.php from cron. A slow relay must not make saving an
 * entry slow, and a broken one must not make it fail.
 */

// ---------------------------------------------------------------------------
// The kinds
//
// Adding one is a line here plus a call to notify(). Everything else - the
// preference screens, the admin defaults, the API - reads this list, so nothing
// has to be told twice.
// ---------------------------------------------------------------------------

/**
 * Nothing is emailed by default.
 *
 * An instance starts with no relay, so a kind that arrives wanting to be mailed
 * is a promise the software cannot keep - and turning mail on later would then
 * start posting things nobody asked for. Anybody who wants email ticks the box,
 * which is one click and is the point at which they have thought about it.
 *
 * @return array<string, array{label:string, description:string, in_app:bool, by_mail:bool}>
 */
function notification_kinds(): array
{
    return [
        'library.invited' => [
            'label'       => 'Library invitations',
            'description' => 'Somebody invites you to a library.',
            'in_app'      => true,
            'by_mail'     => false,
        ],
        'library.invite_answered' => [
            'label'       => 'Answers to your invitations',
            'description' => 'Somebody accepts or declines an invitation you sent.',
            'in_app'      => true,
            'by_mail'     => false,
        ],
        // Being handed a library is a bigger thing than being invited to one, so it is
        // its own kind: somebody who has muted invitations still wants to know they are
        // about to become responsible for a shelf.
        'library.ownership_offered' => [
            'label'       => 'Offers of library ownership',
            'description' => 'Somebody offers to hand a library over to you.',
            'in_app'      => true,
            'by_mail'     => true,
        ],
        'library.ownership_answered' => [
            'label'       => 'Answers to an ownership offer',
            'description' => 'Somebody accepts or declines a library you offered them.',
            'in_app'      => true,
            'by_mail'     => false,
        ],
        'library.access_changed' => [
            'label'       => 'Changes to your access',
            'description' => 'Your access to a library is changed or removed.',
            'in_app'      => true,
            'by_mail'     => false,
        ],
        // A registration waiting on a decision.
        //
        // registration.php has always written this to the security log; nobody
        // was actually told. An admin who does not happen to be reading the log
        // that day would not find out until somebody asked why they still could
        // not sign in.
        'registration.pending' => [
            'label'       => 'Registrations waiting for approval',
            'description' => 'Somebody signs up and needs an administrator to let them in.',
            'in_app'      => true,
            'by_mail'     => true,
        ],
        'system.backup_failed' => [
            'label'       => 'System problems',
            'description' => 'A backup or a scheduled job fails. Administrators only.',
            'in_app'      => true,
            'by_mail'     => false,
        ],
    ];
}

function notification_kind_exists(string $kind): bool
{
    return array_key_exists($kind, notification_kinds());
}

// ---------------------------------------------------------------------------
// What somebody wants
// ---------------------------------------------------------------------------

/**
 * Whether one person wants one kind, on one channel.
 *
 * Three layers, narrowest first: the person's own row, then the instance
 * default an administrator set, then what the kind ships with. A missing row
 * means "no opinion" rather than "no", which is what lets an administrator
 * change the default and have it actually apply to everyone who has not said
 * otherwise.
 */
function wants_notification(int $userId, string $kind, string $channel = 'in_app'): bool
{
    $kinds = notification_kinds();
    if (!isset($kinds[$kind])) {
        return false;
    }

    $column = $channel === 'by_mail' ? 'by_mail' : 'in_app';

    // Memoised in a global rather than a static so it can actually be cleared.
    // It was a static, which meant saving a preference and then acting on it in
    // the same request used the value from before the save - invisible in a web
    // request that redirects, and wrong everywhere else.
    if (!isset($GLOBALS['__notify_prefs'][$userId])) {
        $GLOBALS['__notify_prefs'][$userId] = [];
        foreach (all('SELECT kind, in_app, by_mail FROM notification_prefs WHERE user_id = ?', [$userId]) as $row) {
            $GLOBALS['__notify_prefs'][$userId][(string) $row['kind']] = [
                'in_app'  => (int) $row['in_app'] === 1,
                'by_mail' => (int) $row['by_mail'] === 1,
            ];
        }
    }
    if (isset($GLOBALS['__notify_prefs'][$userId][$kind])) {
        return $GLOBALS['__notify_prefs'][$userId][$kind][$column];
    }

    $default = setting('notify_default_' . $column . '_' . $kind);
    if ($default !== null && $default !== '') {
        return (string) $default === '1';
    }

    return (bool) $kinds[$kind][$column];
}

/** Clear the memoised preferences, after somebody changes them. */
function forget_notification_prefs(?int $userId = null): void
{
    if ($userId === null) {
        unset($GLOBALS['__notify_prefs']);
        return;
    }
    unset($GLOBALS['__notify_prefs'][$userId]);
}

/** One person's settings, filled in from the defaults where they said nothing. */
function notification_prefs_for(int $userId): array
{
    $rows = [];
    foreach (all('SELECT * FROM notification_prefs WHERE user_id = ?', [$userId]) as $row) {
        $rows[(string) $row['kind']] = $row;
    }

    $out = [];
    foreach (notification_kinds() as $kind => $meta) {
        $out[$kind] = [
            'label'       => $meta['label'],
            'description' => $meta['description'],
            'in_app'      => isset($rows[$kind]) ? (int) $rows[$kind]['in_app'] === 1  : wants_notification($userId, $kind, 'in_app'),
            'by_mail'     => isset($rows[$kind]) ? (int) $rows[$kind]['by_mail'] === 1 : wants_notification($userId, $kind, 'by_mail'),
            'explicit'    => isset($rows[$kind]),
        ];
    }
    return $out;
}

function save_notification_prefs(int $userId, array $posted): void
{
    foreach (array_keys(notification_kinds()) as $kind) {
        $inApp  = !empty($posted['in_app'][$kind])  ? 1 : 0;
        $byMail = !empty($posted['by_mail'][$kind]) ? 1 : 0;
        q('INSERT INTO notification_prefs (user_id, kind, in_app, by_mail) VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE in_app = VALUES(in_app), by_mail = VALUES(by_mail)',
          [$userId, $kind, $inApp, $byMail]);
    }
    forget_notification_prefs($userId);
}

// ---------------------------------------------------------------------------
// Sending
// ---------------------------------------------------------------------------

/**
 * Tell somebody something.
 *
 * Returns the row id, or null if they did not want it. Never throws: a
 * notification failing must not take down whatever caused it - the point of
 * being told about an invitation is secondary to the invitation existing.
 */
function notify(int $userId, string $kind, array $data = []): ?int
{
    try {
        if (!notification_kind_exists($kind)) {
            error_log('[retrohive] unknown notification kind: ' . $kind);
            return null;
        }
        if ((int) $userId <= 0) {
            return null;
        }

        $inApp = wants_notification($userId, $kind, 'in_app');
        $mail  = wants_notification($userId, $kind, 'by_mail') && mail_available_for($userId);

        if (!$inApp && !$mail) {
            return null;
        }

        $dedupe = $data['dedupe_key'] ?? null;
        if ($dedupe !== null) {
            // Re-inviting somebody to the same library should not queue a
            // second notice. Bump the existing one back to unread instead.
            $existing = one('SELECT id FROM notifications WHERE user_id = ? AND dedupe_key = ?',
                            [$userId, $dedupe]);
            if ($existing !== null) {
                q('UPDATE notifications SET read_at = NULL, created_at = NOW() WHERE id = ?',
                  [(int) $existing['id']]);
                return (int) $existing['id'];
            }
        }

        return insert_row('notifications', [
            'user_id'      => $userId,
            'kind'         => $kind,
            'subject'      => mb_substr((string) ($data['subject'] ?? $kind), 0, 200),
            'body'         => $data['body'] ?? null,
            'link_path'    => $data['link_path'] ?? null,
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id'   => $data['subject_id'] ?? null,
            'dedupe_key'   => $dedupe,
            'mail_state'   => $mail ? 'queued' : 'none',
        ]);
    } catch (Throwable $e) {
        error_log('[retrohive] notify failed: ' . $e->getMessage());
        return null;
    }
}

/** Everyone who administers the instance, for system notices. */
/**
 * Tell every active admin at once.
 *
 * Used by registration.php when a signup needs approval - the log entry
 * existed already; this is what tells somebody without them having to be
 * reading it.
 */
function notify_admins(string $kind, array $data = []): void
{
    foreach (all("SELECT id FROM users WHERE role = 'admin' AND is_active = 1") as $row) {
        notify((int) $row['id'], $kind, $data);
    }
}

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

function unread_notification_count(?int $userId = null): int
{
    $userId ??= (int) (acting_user()['id'] ?? 0);
    if ($userId <= 0) {
        return 0;
    }
    return (int) scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
}

/**
 * Somebody's notifications, newest first.
 *
 * `$since` is what a native client uses: it holds the timestamp of the last one
 * it saw and asks for everything after it, rather than pulling the lot and
 * working out the difference itself.
 */
function notifications_for(int $userId, int $limit = 50, ?string $since = null, bool $unreadOnly = false): array
{
    $sql  = 'SELECT * FROM notifications WHERE user_id = ?';
    $args = [$userId];

    if ($since !== null && $since !== '') {
        $sql   .= ' AND created_at > ?';
        $args[] = $since;
    }
    if ($unreadOnly) {
        $sql .= ' AND read_at IS NULL';
    }

    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(200, $limit));
    return all($sql, $args);
}

function mark_notification_read(int $userId, int $id): void
{
    q('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL',
      [$id, $userId]);
}

function mark_all_notifications_read(int $userId): int
{
    $n = unread_notification_count($userId);
    q('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$userId]);
    return $n;
}


// ---------------------------------------------------------------------------
// Mail
//
// SMTP spoken directly, because the alternative is PHP's mail() - which needs a
// working local MTA and reports nothing useful when there is not one - or a
// library, and this project has no Composer.
// ---------------------------------------------------------------------------

/** Is mail configured at all, and switched on? */
function mail_enabled(): bool
{
    return (string) setting('smtp_enabled', '0') === '1'
        && trim((string) setting('smtp_host', '')) !== '';
}

/**
 * A fingerprint of the settings that decide whether the relay works.
 *
 * Confirmation is tied to this rather than to "nothing has been saved since".
 * Renaming the from-name or editing a notification default does not invalidate
 * a relay that answered five minutes ago, and being made to redo the round trip
 * for a cosmetic edit is how a safeguard becomes a thing people work around.
 */
function smtp_fingerprint(): string
{
    return hash('sha256', implode('|', [
        setting('smtp_host', ''),
        setting('smtp_port', '25'),
        setting('smtp_security', 'none'),
        setting('smtp_auth', '0'),
        setting('smtp_username', ''),
        setting('smtp_password', '') === '' ? '' : 'set',
        setting('smtp_from', ''),
    ]));
}

/**
 * Has the relay actually been proved to work?
 *
 * Set when a test message is accepted, cleared whenever the settings change.
 * Configured and working are different states, and the difference matters: a
 * host typed into a box is a guess until something comes back from it, and
 * offering people email that silently fails is worse than not offering it.
 *
 * This is what the "by email" boxes are enabled by. Nothing can be ticked
 * against a relay nobody has heard back from.
 */
function mail_verified(): bool
{
    return mail_enabled()
        && (string) setting('smtp_verified_at', '') !== ''
        && (string) setting('smtp_verified_for', '') === smtp_fingerprint();
}

/**
 * Send a confirmation code to an address. Returns [ok, message].
 *
 * A code that has to be typed back, rather than "we sent something, tell us if
 * it turned up". A relay will happily accept a message and drop it, so the only
 * evidence that mail actually arrives is somebody holding a number that was
 * only ever inside one.
 */
function send_smtp_confirmation(string $to): array
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [false, 'That does not look like an email address.'];
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    [$ok, $error] = smtp_send(
        $to,
        'RetroHive confirmation code: ' . $code,
        "Your confirmation code is:\n\n    " . $code . "\n\n"
        . "Type it back into the SMTP relay settings to confirm that mail from "
        . "this instance actually arrives.\n\n"
        . "If you were not expecting this, somebody is configuring a RetroHive "
        . "instance and has your address. Nothing else will be sent to you."
    );

    if (!$ok) {
        return [false, $error];
    }

    // The code is stored hashed and against the settings it was sent under, so
    // it cannot be redeemed after somebody changes the host.
    set_setting('smtp_code_hash', hash('sha256', $code));
    set_setting('smtp_code_sent', (string) time());
    set_setting('smtp_code_for',  smtp_fingerprint());
    set_setting('smtp_code_to',   $to);

    return [true, 'Code sent to ' . $to . '.'];
}

/** Redeem a confirmation code. Returns [ok, message]. */
function confirm_smtp_code(string $code): array
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if ($code === '') {
        return [false, 'Type the six-digit code from the email.'];
    }

    $expected = (string) setting('smtp_code_hash', '');
    if ($expected === '') {
        return [false, 'No code is outstanding. Send one first.'];
    }
    if ((string) setting('smtp_code_for', '') !== smtp_fingerprint()) {
        return [false, 'The relay settings changed after that code was sent. Send another.'];
    }
    // Half an hour. Long enough to walk to another machine, short enough that a
    // code left in an old inbox is not a way in later.
    if (time() - (int) setting('smtp_code_sent', '0') > 1800) {
        return [false, 'That code has expired. Send another.'];
    }
    if (!hash_equals($expected, hash('sha256', $code))) {
        return [false, 'That code does not match.'];
    }

    set_setting('smtp_verified_at',  date('Y-m-d H:i:s'));
    set_setting('smtp_verified_for', smtp_fingerprint());
    set_setting('smtp_code_hash',    '');

    return [true, 'Confirmed. Mail from this instance arrives, so email can be switched on.'];
}

/** Can we mail this particular person? */
function mail_available_for(int $userId): bool
{
    if (!mail_verified()) {
        return false;
    }
    $row = one('SELECT email, mail_enabled FROM users WHERE id = ?', [$userId]);
    return $row !== null
        && (int) $row['mail_enabled'] === 1
        && trim((string) ($row['email'] ?? '')) !== '';
}

/**
 * Send everything queued. Called by bin/notify.php, not by a web request.
 *
 * Returns [sent, failed]. A failure is recorded on the row and the row is left
 * alone rather than retried forever: a bad address does not get better by being
 * tried a hundred times, and the error is worth reading.
 */
function flush_notification_mail(int $limit = 50): array
{
    if (!mail_enabled()) {
        return [0, 0];
    }

    $rows = all(
        "SELECT n.*, u.email, u.display_name, u.username
           FROM notifications n
           JOIN users u ON u.id = n.user_id
          WHERE n.mail_state = 'queued'
          ORDER BY n.created_at LIMIT " . max(1, min(200, $limit))
    );

    $sent = 0;
    $failed = 0;
    foreach ($rows as $row) {
        $to = trim((string) ($row['email'] ?? ''));
        if ($to === '') {
            q("UPDATE notifications SET mail_state = 'failed', mail_error = ? WHERE id = ?",
              ['No address on the account', (int) $row['id']]);
            $failed++;
            continue;
        }

        $body = (string) ($row['body'] ?? '');
        if (!empty($row['link_path'])) {
            $base = rtrim((string) setting('site_url', ''), '/');
            if ($base !== '') {
                $body .= "\n\n" . $base . $row['link_path'];
            }
        }

        [$ok, $error] = smtp_send($to, (string) $row['subject'], $body);
        if ($ok) {
            q("UPDATE notifications SET mail_state = 'sent', mailed_at = NOW() WHERE id = ?", [(int) $row['id']]);
            $sent++;
        } else {
            q("UPDATE notifications SET mail_state = 'failed', mail_error = ? WHERE id = ?",
              [mb_substr($error, 0, 255), (int) $row['id']]);
            $failed++;
        }
    }
    return [$sent, $failed];
}

/**
 * One message over SMTP. Returns [ok, error].
 *
 * Plain, synchronous, and honest about failing. Supports no encryption, STARTTLS
 * and implicit TLS, with optional AUTH LOGIN - which covers every relay anybody
 * self-hosting is likely to point this at.
 */
function smtp_send(string $to, string $subject, string $body): array
{
    $host = trim((string) setting('smtp_host', ''));
    $port = (int) setting('smtp_port', '587');
    $sec  = (string) setting('smtp_security', 'starttls');   // none | starttls | tls
    $user = (string) setting('smtp_username', '');
    $pass = (string) setting('smtp_password', '');
    $from = trim((string) setting('smtp_from', '')) ?: 'retrohive@localhost';
    $name = (string) setting('smtp_from_name', 'RetroHive');
    // Fixed, not a setting: there was never anywhere to change it, so reading
    // it from a table nobody writes only made it look adjustable. Fifteen
    // seconds is long enough for a slow relay and short enough that a dead one
    // does not hold a request open.
    $timeout = 15;

    if ($host === '') {
        return [false, 'No SMTP host configured'];
    }

    $target = ($sec === 'tls' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($target, $errno, $errstr, $timeout);
    if ($fp === false) {
        return [false, sprintf('Could not connect to %s: %s', $target, $errstr ?: 'no reason given')];
    }
    stream_set_timeout($fp, $timeout);

    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 515)) !== false) {
            $out .= $line;
            // A multi-line reply has a dash after the code; the last one has a
            // space. Without this the conversation goes out of step on any
            // server that announces more than one extension.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $out;
    };
    $say = function (string $line) use ($fp, $read): string {
        fwrite($fp, $line . "\r\n");
        return $read();
    };
    $code = fn(string $reply): int => (int) substr(trim($reply), 0, 3);

    try {
        if ($code($read()) !== 220) {
            return [false, 'Server did not greet us'];
        }

        $helo = (string) (parse_url((string) setting('site_url', ''), PHP_URL_HOST) ?: 'localhost');
        $reply = $say('EHLO ' . $helo);
        if ($code($reply) !== 250) {
            return [false, 'EHLO refused: ' . trim($reply)];
        }

        if ($sec === 'starttls') {
            $reply = $say('STARTTLS');
            if ($code($reply) !== 220) {
                return [false, 'STARTTLS refused: ' . trim($reply)];
            }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return [false, 'TLS handshake failed'];
            }
            // Everything the server told us before the handshake is no longer
            // trustworthy, so ask again.
            $reply = $say('EHLO ' . $helo);
            if ($code($reply) !== 250) {
                return [false, 'EHLO after STARTTLS refused: ' . trim($reply)];
            }
        }

        if ($user !== '') {
            if ($code($say('AUTH LOGIN')) !== 334) {
                return [false, 'Server would not start AUTH LOGIN'];
            }
            if ($code($say(base64_encode($user))) !== 334) {
                return [false, 'Username refused'];
            }
            $reply = $say(base64_encode($pass));
            if ($code($reply) !== 235) {
                return [false, 'Sign-in refused: ' . trim($reply)];
            }
        }

        $reply = $say('MAIL FROM:<' . $from . '>');
        if ($code($reply) !== 250) {
            return [false, 'Sender refused: ' . trim($reply)];
        }
        $reply = $say('RCPT TO:<' . $to . '>');
        if (!in_array($code($reply), [250, 251], true)) {
            return [false, 'Recipient refused: ' . trim($reply)];
        }
        if ($code($say('DATA')) !== 354) {
            return [false, 'Server would not accept the message'];
        }

        $headers = [
            'From: ' . ($name !== '' ? sprintf('%s <%s>', $name, $from) : $from),
            'To: ' . $to,
            'Subject: ' . smtp_encode_header($subject),
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
            'Auto-Submitted: auto-generated',
        ];

        // A line consisting of a single dot ends the message, so any real line
        // that starts with one has to be doubled.
        $text = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $body));
        $text = str_replace("\n", "\r\n", (string) $text);

        fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $text . "\r\n.\r\n");
        $reply = $read();
        if ($code($reply) !== 250) {
            return [false, 'Message refused: ' . trim($reply)];
        }

        $say('QUIT');
        return [true, ''];
    } finally {
        @fclose($fp);
    }
}

/** RFC 2047 for anything that is not plain ASCII. */
function smtp_encode_header(string $value): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $value)) {
        return $value;
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

// ---------------------------------------------------------------------------
// One notification as a native client sees it.
//
// Lives here rather than with the API controllers so the tests, the web
// interface and the API all read the same shape from the same place.
// ---------------------------------------------------------------------------

function notification_to_api(array $r): array
{
    return [
        'id'         => (int) $r['id'],
        'kind'       => $r['kind'],
        'subject'    => $r['subject'],
        'body'       => $r['body'],
        // A path, not a URL: the client knows its own host, and an absolute URL
        // stored in a row goes wrong the moment the instance moves.
        'link_path'  => $r['link_path'],
        'about'      => $r['subject_type'] === null ? null : [
            'type' => $r['subject_type'],
            'id'   => $r['subject_id'] === null ? null : (int) $r['subject_id'],
        ],
        'read'       => $r['read_at'] !== null,
        'read_at'    => $r['read_at'],
        'created_at' => $r['created_at'],
    ];
}
