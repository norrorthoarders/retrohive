<?php
declare(strict_types=1);

/**
 * The log.
 *
 * Two streams in one table: `security` for who got in and who did not, `server`
 * for what the instance did. They are separate because they answer different
 * questions - "is somebody trying to get in" and "why did that entry change"
 * are not read at the same moment or by the same person.
 *
 * The vocabulary is syslog's, deliberately. Facility, severity, tag, message:
 * so sending this to a syslog receiver is a formatter rather than a redesign,
 * and the severities are RFC 5424's numbers the right way round so nothing has
 * to be translated at the boundary.
 *
 * Logging never throws. A catalogue that stops working because it could not
 * write a log entry has its priorities backwards.
 */

// RFC 5424 severities are PHP's own LOG_* constants, and they are used rather
// than redefined: LOG_WARNING and the rest are core, they already carry exactly
// these numbers, and declaring a second set of them was both a warning at
// startup and a chance for the two to drift apart.
//
//   LOG_EMERG 0  LOG_ALERT 1  LOG_CRIT 2  LOG_ERR 3
//   LOG_WARNING 4  LOG_NOTICE 5  LOG_INFO 6  LOG_DEBUG 7

function log_severity_label(int $severity): string
{
    return [
        0 => 'emergency', 1 => 'alert',  2 => 'critical', 3 => 'error',
        4 => 'warning',   5 => 'notice', 6 => 'info',     7 => 'debug',
    ][$severity] ?? 'info';
}

/**
 * Record something.
 *
 * @param string $channel  'security' or 'server'
 * @param string $event    dotted verb: 'library.created', 'auth.signin.failed'
 */
function log_event(
    string $channel,
    string $event,
    string $message,
    int $severity = LOG_INFO,
    array $context = []
): ?int {
    // Returns the row id, for the one caller that needs it: the error handler,
    // which shows it on the page so somebody looking at a broken screen and
    // somebody looking at the log are talking about the same event. Every other
    // caller ignores it, which is why this was void.
    try {
        if ($severity > (int) setting('log_min_severity', (string) LOG_INFO)) {
            return null;   // below what this instance keeps
        }

        $actor = function_exists('acting_user') ? acting_user() : null;

        $ip = null;
        if (function_exists('client_ip')) {
            $packed = @inet_pton((string) client_ip());
            $ip = $packed === false ? null : $packed;
        }

        $id = insert_row('logs', [
            // The channels this instance keeps, in one place.
            //
            // Anything else silently became 'server', which is a quiet way to
            // lose a stream: a whole channel of lookup entries was written and
            // filed under something else, and the tab for it counted zero. A
            // name that is not on this list is still kept rather than dropped -
            // the entry matters more than the label - but the list is the thing
            // to add to when a new stream appears.
            'channel'      => in_array($channel, log_channels(), true) ? $channel : 'server',
            'severity'     => max(0, min(7, $severity)),
            'event'        => mb_substr($event, 0, 60),
            'message'      => mb_substr($message, 0, 500),
            'actor_id'     => $actor === null ? null : (int) $actor['id'],
            'actor_name'   => $actor === null ? null : mb_substr((string) ($actor['display_name'] ?: $actor['username']), 0, 120),
            'subject_type' => $context['subject_type'] ?? null,
            'subject_id'   => $context['subject_id'] ?? null,
            'ip'           => $ip,
            'context'      => $context === [] ? null
                : json_encode(array_diff_key($context, array_flip(['subject_type', 'subject_id'])),
                              JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $line = syslog_line($channel, $event, $message, $severity);
        syslog_forward($line);
        logfile_write($line);
        return $id === null ? null : (int) $id;
    } catch (Throwable $e) {
        // A catalogue that stops working because it could not write a log entry
        // has its priorities backwards.
        error_log('[retrohive] log failed: ' . $e->getMessage());
        return null;
    }
}

/** Who got in, who did not, and who was let into what. */
function log_security(string $event, string $message, int $severity = LOG_NOTICE, array $context = []): ?int
{
    return log_event('security', $event, $message, $severity, $context);
}

/** What the instance did. */
function log_server(string $event, string $message, int $severity = LOG_INFO, array $context = []): ?int
{
    return log_event('server', $event, $message, $severity, $context);
}

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

function log_entries(string $channel, array $filters = [], int $limit = 100, int $offset = 0): array
{
    // 'all' reads both streams. They are separate because they answer different
    // questions, but "what happened at 14:32" is a third question that needs
    // them interleaved.
    $where = [];
    $args  = [];
    if ($channel !== 'all') {
        $where[] = 'channel = ?';
        $args[]  = in_array($channel, ['security', 'server'], true) ? $channel : 'server';
    }

    if (!empty($filters['event'])) {
        $where[] = 'event LIKE ?';
        $args[]  = rtrim((string) $filters['event'], '*') . '%';
    }
    if (isset($filters['severity']) && $filters['severity'] !== '') {
        $where[] = 'severity <= ?';
        $args[]  = (int) $filters['severity'];
    }
    if (!empty($filters['actor'])) {
        $where[] = 'actor_id = ?';
        $args[]  = (int) $filters['actor'];
    }
    if (!empty($filters['since'])) {
        $where[] = 'created_at >= ?';
        $args[]  = $filters['since'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(message LIKE ? OR actor_name LIKE ?)';
        $args[]  = '%' . $filters['q'] . '%';
        $args[]  = '%' . $filters['q'] . '%';
    }

    $limit  = max(1, min(500, $limit));
    $offset = max(0, $offset);

    $clause = $where === [] ? '1 = 1' : implode(' AND ', $where);

    return all(
        "SELECT * FROM logs WHERE $clause ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET $offset",
        $args
    );
}

/**
 * The streams this instance writes.
 *
 * One list, read by log_event() when it files an entry and by the viewer when it
 * draws its tabs, so the two cannot disagree - which they did: 'metadata' was
 * written for a while and filed as 'server', and the tab counting it said zero.
 *
 * @return list<string>
 */
function log_channels(): array
{
    return ['security', 'server', 'metadata'];
}

/** The events that have actually happened, for a filter that is not guesswork. */
function log_known_events(string $channel): array
{
    if ($channel === 'all') {
        return array_column(all('SELECT DISTINCT event FROM logs ORDER BY event'), 'event');
    }
    return array_column(
        all('SELECT DISTINCT event FROM logs WHERE channel = ? ORDER BY event', [$channel]),
        'event'
    );
}

/** Trim the log to its configured age. Called from bin/notify.php. */
/**
 * How many entries a prune would remove, without removing them.
 *
 * The same rule log_prune() applies, asked as a question - so a screen can offer
 * the button with its effect on it rather than making somebody press it to find
 * out. Zero when retention keeps everything, whatever the log holds.
 */
function log_prunable_count(): int
{
    $days = (int) setting('log_retention_days', '90');
    if ($days <= 0) {
        return 0;
    }
    return (int) scalar('SELECT COUNT(*) FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
}

function log_prune(): int
{
    $days = (int) setting('log_retention_days', '90');
    if ($days <= 0) {
        return 0;   // keep everything
    }
    $before = (int) scalar('SELECT COUNT(*) FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
    q('DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
    return $before;
}

// ---------------------------------------------------------------------------
// Syslog
//
// Written now rather than later because the shape of the table was chosen for
// it, and a format nobody has ever run is a format that does not work.
// ---------------------------------------------------------------------------

function syslog_enabled(): bool
{
    return (string) setting('syslog_enabled', '0') === '1'
        && trim((string) setting('syslog_host', '')) !== '';
}

/**
 * The syslog facilities worth offering.
 *
 * A number in a box would be more flexible and less usable: nobody remembers
 * that 16 is local0, and the ones anybody actually routes on are these.
 */
function syslog_facilities(): array
{
    return [
        16 => 'local0', 17 => 'local1', 18 => 'local2', 19 => 'local3',
        20 => 'local4', 21 => 'local5', 22 => 'local6', 23 => 'local7',
        10 => 'authpriv', 4 => 'auth', 13 => 'audit', 1 => 'user', 3 => 'daemon',
    ];
}

/** Which facility a stream goes out on. */
function syslog_facility_for(string $channel): int
{
    $key = $channel === 'security' ? 'syslog_facility_security' : 'syslog_facility_server';
    $default = $channel === 'security' ? 10 : 16;   // authpriv, local0
    $value = (int) setting($key, (string) $default);
    return array_key_exists($value, syslog_facilities()) ? $value : $default;
}

/**
 * One RFC 5424 line.
 *
 * Built once and used by both the socket and the file, so what lands on disk is
 * the same thing a receiver would have seen - which is the point of writing the
 * file in this format rather than inventing another one.
 */
function syslog_line(string $channel, string $event, string $message, int $severity): string
{
    $priority = syslog_facility_for($channel) * 8 + max(0, min(7, $severity));

    $hostname = (string) (parse_url((string) setting('site_url', ''), PHP_URL_HOST) ?: gethostname() ?: 'retrohive');
    $actor    = function_exists('acting_user') ? acting_user() : null;
    $who      = $actor === null ? '-' : ($actor['username'] ?? '-');

    return sprintf(
        '<%d>1 %s %s retrohive - %s [rv actor="%s" channel="%s"] %s',
        $priority,
        gmdate('Y-m-d\TH:i:s\Z'),
        $hostname,
        $event,
        $who,
        $channel,
        $message
    );
}

/**
 * Send one line to a receiver.
 *
 * Failures are swallowed after one attempt: a log shipper being down must not
 * make the application slow, and retrying inside a web request would do exactly
 * that. The row is already in the database either way.
 */
function syslog_forward(string $line): void
{
    if (!syslog_enabled()) {
        return;
    }

    try {
        $host  = trim((string) setting('syslog_host', ''));
        $port  = (int) setting('syslog_port', '514');
        $proto = (string) setting('syslog_protocol', 'udp') === 'tcp' ? 'tcp' : 'udp';

        $sock = @stream_socket_client(
            $proto . '://' . $host . ':' . $port,
            $errno, $errstr, 2, STREAM_CLIENT_CONNECT
        );
        if ($sock === false) {
            return;
        }
        @fwrite($sock, $proto === 'tcp' ? $line . "\n" : $line);
        @fclose($sock);
    } catch (Throwable $e) {
        // Deliberately silent. error_log() here would fill the web server's own
        // log with one line per event every time the shipper is down.
    }
}

// ---------------------------------------------------------------------------
// A file on disk
//
// The same RFC 5424 line the receiver would have got, appended to a file. Which
// means logrotate, grep and every other thing that already understands syslog
// works on it without being told anything - the reason for not inventing a
// format of our own.
// ---------------------------------------------------------------------------

function logfile_enabled(): bool
{
    return (string) setting('logfile_enabled', '0') === '1'
        && trim((string) setting('logfile_path', '')) !== '';
}

function logfile_path(): string
{
    return trim((string) setting('logfile_path', '/var/log/retrohive/retrohive.log'));
}

function logfile_write(string $line): void
{
    if (!logfile_enabled()) {
        return;
    }
    try {
        $path = logfile_path();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            // One attempt. If it fails the write below fails too, and the
            // caller finds out from logfile_problem() rather than from a
            // warning nobody reads.
            @mkdir($dir, 0o750, true);
        }
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // Same reasoning as the socket: silent, because one line per event in
        // the web server's log is worse than the thing it is reporting.
    }
}

/**
 * Why the file is not being written, if it is not. Null when it is fine.
 *
 * Checked by the settings screen rather than at write time, so the answer is
 * "your log directory is not writable by www-data" on the page where you can do
 * something about it, instead of silence everywhere.
 */
function logfile_problem(): ?string
{
    if (!logfile_enabled()) {
        return null;
    }
    $path = logfile_path();
    $dir  = dirname($path);

    if (!is_dir($dir)) {
        // Writing creates it, so "does not exist" is not a problem on its own -
        // reporting one would be a warning about something that works. Two
        // things genuinely stop it: something in the way that is not a
        // directory, and a nearest existing ancestor nobody may write to.
        $probe = $dir;
        while ($probe !== '' && $probe !== '/' && !is_dir($probe)) {
            if (file_exists($probe)) {
                return $probe . ' exists and is not a directory, so ' . $dir . ' cannot be created.';
            }
            $parent = dirname($probe);
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }
        return is_writable($probe)
            ? null
            : $dir . ' does not exist and cannot be created: ' . $probe . ' is not writable by the web server.';
    }
    if (file_exists($path) && !is_writable($path)) {
        return $path . ' exists but cannot be written to by the web server.';
    }
    if (!file_exists($path) && !is_writable($dir)) {
        return $dir . ' cannot be written to by the web server.';
    }
    return null;
}

/**
 * Write a crash into this instance's own log, and give it a reference.
 *
 * The error page used to say the details went to the PHP error log, which is
 * true and unhelpful: the person reading that sentence is usually the person who
 * cannot read that file. An instance with a Logs page should be able to say what
 * broke on its own screen.
 *
 * Defensive throughout. This runs when something has already gone wrong, and the
 * thing that went wrong may well be the database - so every step is optional and
 * the PHP error log is written either way.
 *
 * @return ?int the log entry's id, for showing on the page
 */
function retrovault_record_crash(Throwable $e, string $event = 'error.uncaught'): ?int
{
    $where = $e->getFile() . ':' . $e->getLine();
    error_log('[retrohive] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $where);

    try {
        if (!function_exists('log_server')) {
            return null;
        }

        // The path, because "Something broke" without knowing where is a bug
        // report nobody can act on. The query string is left out: it carries
        // tokens and search terms, and the path is what identifies the screen.
        $path   = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI'));

        // File and line only, no arguments. A stack trace with arguments in it
        // will eventually contain a password, and a log is the wrong place to
        // find one.
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 6) as $f) {
            if (isset($f['file'])) {
                $frames[] = basename((string) $f['file']) . ':' . (int) ($f['line'] ?? 0);
            }
        }

        return log_server($event,
            sprintf('%s on %s %s — %s at %s',
                    get_class($e), $method, $path === '' ? '/' : $path,
                    $e->getMessage(), $where),
            LOG_ERR,
            ['path' => $path, 'method' => $method, 'trace' => $frames]);
    } catch (Throwable $ignored) {
        // Whatever broke may be the reason logging cannot work either.
        return null;
    }
}

/**
 * The page somebody actually sees, with the reference on it.
 */
function crash_page(?int $ref): void
{
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
       . '<body style="font-family:system-ui;background:#1e1e2e;color:#cdd6f4;padding:3rem">'
       . '<h1>Something broke</h1>'
       . ($ref !== null
            ? '<p>Recorded in the instance log as entry <strong>#' . (int) $ref . '</strong>. '
            . 'An administrator can read it under Logs.</p>'
            : '<p>It could not be written to the instance log either, which usually means '
            . 'the database is the thing that is wrong. The details went to the PHP error log.</p>')
       . '<p style="opacity:.7">Set APP_DEBUG=1 to see them here.</p></body>';
}
