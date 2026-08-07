<?php
declare(strict_types=1);

/*
 * library_access_index() lived here.
 *
 * It rendered a flat list of everything an account could reach, at /profile/access.
 * That address now serves the tabbed access page, so this and its template were
 * unreachable - about eighty lines answering a question something else already answers.
 */


// ---------------------------------------------------------------------------
// The instance itself
//
// Administrators only, and deliberately apart from Manage: managing a library
// is something a person does to their own things, and configuring a mail relay
// is not.
// ---------------------------------------------------------------------------

function admin_settings_index(): void
{
    require_admin();

    // 'updates' is the default: it is the first tab and the one worth landing on.
    $tab = (string) input('tab', 'updates');

    // A day old is old enough to ask again; anything fresher is reused.
    $lastCheck = (string) setting('update_checked_at', '');
    if ($lastCheck === '' || strtotime($lastCheck) < time() - 86400) {
        check_for_update();
    }

    render('auth/admin_settings', [
        'pageTitle' => 'Instance settings',
        'tab'       => in_array($tab, ['updates', 'general', 'smtp', 'security'], true) ? $tab : 'updates',
        'logging'   => [
            'retention'    => setting('log_retention_days', '90'),
            'min_severity' => (int) setting('log_min_severity', (string) LOG_INFO),
            'syslog'       => (string) setting('syslog_enabled', '0') === '1',
            'syslog_host'  => setting('syslog_host', ''),
            'syslog_port'  => setting('syslog_port', '514'),
            'syslog_proto' => setting('syslog_protocol', 'udp'),
            'facilities'   => syslog_facilities(),
            'fac_server'   => syslog_facility_for('server'),
            'fac_security' => syslog_facility_for('security'),
            'file'         => logfile_enabled(),
            'file_path'    => setting('logfile_path', '/var/log/retrohive/retrohive.log'),
            'file_problem' => logfile_problem(),
            // Forwarding switched on with nowhere to forward to used to fail in
            // silence: every entry was written, none was sent, and the screen
            // looked fine. It is now said out loud and marked on the field.
            'host_missing' => (string) setting('syslog_enabled', '0') === '1'
                && trim((string) setting('syslog_host', '')) === '',
        ],
        'instanceName' => setting('instance_name', 'RetroHive'),
        'templates'    => [
            'source'     => structure_source_url(),
            'files'      => structure_files(),
            'synced_at'  => setting('structure_synced_at', ''),
            'synced_from'=> setting('structure_synced_from', ''),
            // A row per file, with what that file put here.
            //
            // The old sentence said "162 manufacturers and 5188 genres", words
            // this application stopped using when manufacturers and developers
            // became companies and genres became the category tree. A count
            // nobody can match to a screen is worse than no count: it looks like
            // a fact about something that no longer exists.
            'rows'       => structure_row_counts(),
            // Short, and about the address rather than about HTTP.
            //
            // "Failed to connect" and "the file is not valid JSON" are two
            // different things to do something about; a stack of curl detail is
            // neither, and this is a settings screen rather than a log.
            'error'      => setting('structure_last_error', ''),
            // What the last run actually did, per file, so the table can show
            // the difference between what was fetched and what is here now.
            // Decoded here rather than in the template: a view should not be
            // parsing JSON to decide what to draw.
            'last_sync'  => json_decode((string) setting('structure_sync_report', ''), true) ?: null,
        ],
        // Checked when the page is opened, at most once a day.
        //
        // "Never checked" on a settings screen is a question the screen could
        // have answered itself, and pressing a button to find out whether you are
        // current is a chore nobody remembers. Once a day because the answer
        // changes about that often and GitHub is somebody else's server: opening
        // this page twice should not ask twice.
        'update'       => [
            'latest'     => setting('update_latest', ''),
            'checked_at' => setting('update_checked_at', ''),
            'url'        => setting('update_url', ''),
            'available'  => update_available(),
            'running'    => APP_VERSION,
            // "Never checked" and "checked, and could not tell" are different
            // things, and the second is the one worth saying.
            'error'      => update_check_error(),
        ],
        'smtp'      => [
            'code_pending' => (string) setting('smtp_code_hash', '') !== ''
                && (string) setting('smtp_code_for', '') === smtp_fingerprint(),
            'code_to'      => setting('smtp_code_to', ''),
            'enabled'   => (string) setting('smtp_enabled', '0') === '1',
            'host'      => setting('smtp_host', ''),
            // Plain SMTP on 25 with no authentication: what a relay on your own
            // network almost always wants, and the fewest boxes to fill in.
            'port'      => setting('smtp_port', '25'),
            'encrypted' => (string) setting('smtp_security', 'none') !== 'none',
            'security'  => setting('smtp_security', 'none'),
            'auth'      => (string) setting('smtp_auth', '0') === '1',
            'username'  => setting('smtp_username', ''),
            'has_pass'  => (string) setting('smtp_password', '') !== '',
            'from'      => setting('smtp_from', ''),
            'from_name' => setting('smtp_from_name', 'RetroHive'),
            'verified'  => mail_verified(),
            'verified_at' => setting('smtp_verified_at', ''),
        ],
        'siteUrl'   => setting('site_url', ''),
        'requireVerification' => (string) setting('require_email_verification', '0') === '1',
        'kinds'     => notification_kinds(),
        'defaults'  => admin_notification_defaults(),
        // Per-account mail lives with the rest of account management now, where
        // locking somebody out and editing their address are also decided.
        'queue'     => [
            'queued' => (int) scalar("SELECT COUNT(*) FROM notifications WHERE mail_state = 'queued'"),
            'failed' => (int) scalar("SELECT COUNT(*) FROM notifications WHERE mail_state = 'failed'"),
            'recent' => all("SELECT subject, mail_error, created_at FROM notifications
                              WHERE mail_state = 'failed' ORDER BY created_at DESC LIMIT 5"),
        ],
    ]);
}

/**
 * Write one entry and say what happened to it at each destination.
 *
 * Separately, because "sent" on its own is not a result when there are three
 * places it might have gone: the database, a file, and a syslog host that
 * answers nothing at all.
 *
 * Lifted out of the request handler because two things ask for it now - the
 * button on the logging form, which saves first so the test is of what is on
 * screen, and the older `log_test` post, which is kept so a bookmarked form
 * still works.
 *
 * @return array{0: bool, 1: string}  whether anything went wrong, and the sentence
 */
function admin_write_test_log(): array
{
    log_server('logging.test', 'Test entry, written by hand from the settings page', LOG_NOTICE);

    // Say what happened to each destination separately. "Sent" on its own
    // is not a result when there are three places it might have gone.
    $parts = ['written to the server log'];

    if (syslog_enabled()) {
        $parts[] = sprintf('forwarded to %s:%s over %s — nothing answers a syslog line, so check there',
                           setting('syslog_host', ''), setting('syslog_port', '514'),
                           strtoupper((string) setting('syslog_protocol', 'udp')));
    } else {
        $parts[] = 'not forwarded, since that is off';
    }

    $bad = false;
    if (logfile_enabled()) {
        $path    = logfile_path();
        $problem = logfile_problem();
        $bad     = $problem !== null;

        if ($problem !== null) {
            $parts[] = 'NOT written to the file: ' . $problem;
        } else {
            // Report what is actually on disk afterwards, not that we
            // tried. clearstatcache() because the write happened in this
            // same request and PHP would otherwise answer from before it.
            clearstatcache(true, $path);
            $size    = is_file($path) ? (int) filesize($path) : null;
            $parts[] = $size === null
                ? 'the file ' . $path . ' does not exist afterwards, which should not happen'
                : sprintf('appended to %s, now %d bytes', $path, $size);
            $bad = $size === null;

            // The trap that makes a successful write look like a failure:
            // Apache and php-fpm normally run with systemd's PrivateTmp, so
            // /tmp inside the web server is not the /tmp you see in a shell.
            // The file is there; it is just somewhere else.
            if (str_starts_with($path, '/tmp/')) {
                $parts[] = 'note that Apache and php-fpm usually run with systemd PrivateTmp, '
                         . 'so a file under /tmp is written into a private namespace and will not '
                         . 'be visible from a shell — use a real path such as '
                         . '/var/log/retrohive/retrohive.log';
            }
        }
    } else {
        $parts[] = 'not written to a file, since that is off';
    }

    return [$bad, ucfirst(implode('; ', $parts)) . '.'];
}

/** The instance defaults, filled in from what each kind ships with. */
function admin_notification_defaults(): array
{
    $out = [];
    foreach (notification_kinds() as $kind => $meta) {
        foreach (['in_app', 'by_mail'] as $channel) {
            $set = setting('notify_default_' . $channel . '_' . $kind);
            $out[$kind][$channel] = $set === null ? (bool) $meta[$channel] : $set === '1';
        }
    }
    return $out;
}

function admin_settings_save(): void
{
    require_admin();
    csrf_verify();

    $section = (string) input('section', 'smtp');

    if ($section === 'server') {
        set_setting('site_url', rtrim(trim((string) input('site_url', '')), '/'));
        set_setting('instance_name', trim((string) input('instance_name', '')) ?: 'RetroHive');
        flash('ok', 'Saved.');
        redirect('/admin/settings');
    }

    if ($section === 'smtp') {
        $wantMail = !empty($_POST['smtp_enabled']);
        $host     = trim((string) input('smtp_host', ''));
        $from     = trim((string) input('smtp_from', ''));

        // Saying "send email" while leaving the relay blank is not a setting,
        // it is a half-finished thought. Refusing beats storing something that
        // cannot work and only saying so when the first message fails.
        $missing = [];
        if ($wantMail && $host === '') {
            $missing[] = 'a host';
        }
        if ($wantMail && $from === '') {
            $missing[] = 'a from address';
        }
        if ($wantMail && !empty($_POST['smtp_auth']) && trim((string) input('smtp_username', '')) === '') {
            $missing[] = 'a username, since you said the relay signs in';
        }
        if ($missing !== []) {
            flash('error', 'Not saved: sending email needs ' . implode(', ', $missing) . '.');
            redirect('/admin/settings', ['tab' => 'smtp']);
        }
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Not saved: the from address does not look like an address.');
            redirect('/admin/settings', ['tab' => 'smtp']);
        }

        set_setting('smtp_enabled', $wantMail ? '1' : '0');
        set_setting('smtp_host',    $host);
        set_setting('smtp_port',    (string) max(1, min(65535, (int) input_int('smtp_port', 25))));

        // Encryption and authentication are each one decision, not a mode you
        // have to know the name of. Off means off: a relay on your own network
        // usually wants neither, and the form should not make that the awkward
        // case.
        $security = 'none';
        if (!empty($_POST['smtp_encrypted'])) {
            $chosen   = (string) input('smtp_security', 'starttls');
            $security = in_array($chosen, ['starttls', 'tls'], true) ? $chosen : 'starttls';
        }
        set_setting('smtp_security', $security);

        $auth = !empty($_POST['smtp_auth']);
        set_setting('smtp_auth', $auth ? '1' : '0');
        set_setting('smtp_username', $auth ? trim((string) input('smtp_username', '')) : '');
        if (!$auth) {
            set_setting('smtp_password', '');
        }

        set_setting('smtp_from',      trim((string) input('smtp_from', '')));
        set_setting('smtp_from_name', trim((string) input('smtp_from_name', 'RetroHive')));

        // Blank means "leave it alone", so opening the page and saving does not
        // wipe a password nobody retyped.
        $pass = (string) input('smtp_password', '');
        if ($auth && $pass !== '') {
            set_setting('smtp_password', $pass);
        }

        // The confirmation is tied to a fingerprint of the settings that decide
        // whether mail works, so it survives a cosmetic edit and is invalidated
        // by a real one - without this file having to work out which was which.


        flash('ok', 'Mail settings saved.');
        redirect('/admin/settings', ['tab' => 'smtp']);
    }

    if ($section === 'send_code') {
        $to = trim((string) input('code_to', ''));
        [$ok, $message] = send_smtp_confirmation($to);
        flash($ok ? 'ok' : 'error', $message);
        redirect('/admin/settings', ['tab' => 'smtp']);
    }

    if ($section === 'confirm_code') {
        [$ok, $message] = confirm_smtp_code((string) input('code', ''));
        flash($ok ? 'ok' : 'error', $message);
        redirect('/admin/settings', ['tab' => 'smtp']);
    }

    if ($section === 'libraries') {
        // One switch, and it only widens what an owner may do. Administrators can
        // always disable a library and can always remove an empty one; this decides
        // whether an owner may destroy a full one of their own.
        $on = !empty($_POST['libraries_deletable']);
        set_setting('libraries.deletable', $on ? '1' : '0');
        flash('ok', $on
            ? 'Owners may now delete their own libraries.'
            : 'Owners can disable their libraries; only an administrator can remove one.');
        redirect('/admin/settings', ['tab' => 'security']);
    }

    if ($section === 'registration') {
        $mode = (string) input('registration_mode', 'closed');
        if (!in_array($mode, REGISTRATION_MODES, true)) {
            $mode = 'closed';
        }
        // A mode that cannot work is not a mode. Invitations go out by email, so
        // choosing that without a relay would be a sign-up route nobody can ever
        // reach - refused here rather than discovered by the first person who
        // tries to invite somebody.
        $blocked = registration_mode_blocked($mode);
        if ($blocked !== null) {
            flash('error', $blocked);
            redirect('/admin/settings', ['tab' => 'security']);
        }
        set_setting('registration_mode', $mode);

        // And what happens once somebody has. Refused rather than coerced if it
        // is not one of the three, because a mode nobody recognises would leave
        // accounts in a state nothing checks for.
        $appr = (string) input('registration_approval', 'auto');
        if (in_array($appr, ['auto', 'email', 'admin'], true)) {
            set_setting('registration_approval', $appr);
        }

        // Rotating is its own button, not a side effect of saving: a secret that
        // changes every time somebody opens this page is a secret nobody can
        // hand out.
        if (!empty($_POST['rotate_secret'])) {
            registration_secret_rotate();
            flash('ok', 'A new address. The old one stops working immediately.');
            redirect('/admin/settings', ['tab' => 'security']);
        }

        flash('ok', match ($mode) {
            'public' => 'Anyone can create an account, and the sign-in page says so.',
            'secret' => 'Only people who have the address can create an account.',
            'invite' => 'Only people you invite can create an account.',
            default  => 'Only administrators can create accounts.',
        });
        redirect('/admin/settings', ['tab' => 'security']);
    }

    if ($section === 'indexing') {
        // Its own section, because it is about the whole site rather than about
        // registration - and saving one should not quietly rewrite the other.
        $indexing = (string) input('search_indexing', 'discourage');
        set_setting('search_indexing', $indexing === 'allow' ? 'allow' : 'discourage');
        flash('ok', $indexing === 'allow'
            ? 'Search engines may index the catalogue. The ways in stay excluded.'
            : 'Search engines are asked to stay away from all of it.');
        redirect('/admin/settings', ['tab' => 'security']);
    }

    if ($section === 'logging') {
        $days = (int) input_int('log_retention_days', 90);
        set_setting('log_retention_days', (string) max(0, min(3650, $days)));

        $severity = (int) input_int('log_min_severity', LOG_INFO);
        set_setting('log_min_severity', (string) max(0, min(7, $severity)));

        $wantSyslog = !empty($_POST['syslog_enabled']);
        $host       = trim((string) input('syslog_host', ''));

        // Saved either way, so the host they typed is not lost - but said out
        // loud, because forwarding with nowhere to forward to writes every
        // entry, sends none, and looks perfectly healthy.
        set_setting('syslog_enabled',  $wantSyslog ? '1' : '0');
        set_setting('syslog_host',     $host);
        set_setting('syslog_port',     (string) max(1, min(65535, (int) input_int('syslog_port', 514))));
        $proto = (string) input('syslog_protocol', 'udp');
        set_setting('syslog_protocol', $proto === 'tcp' ? 'tcp' : 'udp');

        foreach (['server', 'security'] as $stream) {
            $fac = (int) input_int('syslog_facility_' . $stream, $stream === 'security' ? 10 : 16);
            if (array_key_exists($fac, syslog_facilities())) {
                set_setting('syslog_facility_' . $stream, (string) $fac);
            }
        }

        // A file on disk, in the same format a receiver would have got.
        $wantFile = !empty($_POST['logfile_enabled']);
        $path     = trim((string) input('logfile_path', ''));
        set_setting('logfile_enabled', $wantFile ? '1' : '0');
        set_setting('logfile_path', $path !== '' ? $path : '/var/log/retrohive/retrohive.log');

        log_server('logging.configured', 'Logging settings changed', LOG_NOTICE);

        $problems = [];
        if ($wantSyslog && $host === '') {
            $problems[] = 'forwarding has no host, so nothing is being sent';
        }
        // Checked here rather than at write time, so "the directory is not
        // writable by the web server" appears on the page where you can fix it.
        unset($GLOBALS['__settings']);
        $fileProblem = logfile_problem();
        if ($fileProblem !== null) {
            $problems[] = 'the log file cannot be written: ' . $fileProblem;
        }
        // The test button is in this form, so it saves and then writes - which
        // is why the panel it replaced no longer needs its "save first" caveat.
        if ((string) input('action', '') === 'test') {
            [$bad, $said] = admin_write_test_log();
            flash($bad || $problems !== [] ? 'error' : 'ok',
                  ($problems === [] ? 'Saved. ' : 'Saved, but ' . implode('; and ', $problems) . '. ')
                  . $said);
            redirect('/admin/settings', ['tab' => 'security']);
        }

        flash($problems === [] ? 'ok' : 'error',
              $problems === [] ? 'Saved.' : 'Saved, but ' . implode('; and ', $problems) . '.');
        redirect('/admin/settings', ['tab' => 'security']);
    }

    if ($section === 'log_test') {
        [$bad, $said] = admin_write_test_log();
        flash($bad ? 'error' : 'ok', $said);
        // The security tab, which is where Logging is. The button that used to
        // post this is gone, so this path exists only for a bookmarked form.
        redirect('/admin/settings', ['tab' => 'security']);
    }

    if ($section === 'templates') {
        // The address, and nothing else.
        //
        // Synchronising used to happen here, which meant one button changed the
        // templates for every library at once with no way to say which library had
        // asked for it. It belongs on the library that receives the copies -
        // /libraries/{id}/edit - where the person doing it can see what they are
        // about to change. This page only records where to fetch from.
        $url = trim((string) input('structure_source', ''));
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            flash('error', 'That does not look like a URL.');
            redirect('/admin/settings', ['tab' => 'general']);
        }
        set_setting('structure_source', $url === '' ? null : rtrim($url, '/'));

        // Forced, which is the whole point of the button.
        //
        // An ordinary sync skips a slug that is already here, so a correction to
        // a row that shipped wrong - a maker, a year - could never arrive. Forced
        // rewrites the template rows it finds. It still touches nobody's library:
        // structure_apply() only ever writes rows with library_id IS NULL.
        if ((string) input('action', '') === 'force') {
            [$summary, $errors] = structure_sync(true, true);
            $added   = array_sum(array_column($summary, 'added'));
            $updated = array_sum(array_column($summary, 'updated'));
            $failed  = array_sum(array_column($summary, 'failed'));
            foreach (array_slice($errors, 0, 3) as $problem) {
                flash('error', $problem);
            }
            flash($failed > 0 ? 'error' : 'ok', sprintf(
                'Fetched: %d added, %d corrected, %d refused. Libraries are untouched;'
                . ' resync a library to copy the changes into it.',
                $added, $updated, $failed));
            redirect('/admin/settings', ['tab' => 'general']);
        }

        flash('ok', $url === ''
            ? 'Cleared. The copies that shipped will be used.'
            : 'Saved. Libraries fetch from there when you resync them.');
        redirect('/admin/settings', ['tab' => 'general']);
    }

    if ($section === 'update_check') {
        [$version, $url, $error] = check_for_update();
        if ($version === null) {
            flash('error', 'Could not check for updates, so this cannot confirm whether '
                         . APP_VERSION . ' is current: ' . $error);
        } else {
            flash(update_available() ? 'ok' : 'ok', update_available()
                ? 'Version ' . $version . ' is available; you are running ' . APP_VERSION . '.'
                : 'You are running ' . APP_VERSION . ', which is the latest.');
        }
        redirect('/admin/settings');
    }

    if ($section === 'signin') {
        // Requiring a link nobody can send locks out everybody, including
        // whoever ticked the box - so it can only be switched on against a
        // relay that has answered, and it stops applying if that stops being
        // true.
        $want = !empty($_POST['require_email_verification']);
        if ($want && !mail_verified()) {
            set_setting('require_email_verification', '0');
            flash('error', 'Address confirmation needs a relay that has answered a test message.');
            redirect('/admin/settings');
        }
        set_setting('require_email_verification', $want ? '1' : '0');
        flash('ok', 'Saved.');
        redirect('/admin/settings');
    }

    if ($section === 'defaults') {
        $canMail = mail_verified();
        foreach (array_keys(notification_kinds()) as $kind) {
            set_setting('notify_default_in_app_'  . $kind, empty($_POST['in_app'][$kind])  ? '0' : '1');
            // A disabled checkbox posts nothing, so without this guard opening
            // the page and saving would quietly clear everybody's mail defaults
            // whenever the relay happened to be unproved.
            if ($canMail) {
                set_setting('notify_default_by_mail_' . $kind, empty($_POST['by_mail'][$kind]) ? '0' : '1');
            }
        }
        flash('ok', 'Defaults saved. They apply to everyone who has not set their own.');
        redirect('/admin/settings');
    }

    redirect('/admin/settings');
}

// ---------------------------------------------------------------------------
// The log
// ---------------------------------------------------------------------------

function logs_index(): void
{
    require_admin();

    $channel = (string) input('channel', 'all');
    // 'metadata' is a stream of its own: a lookup is the one thing here that
    // reaches somebody else's server, so "did it answer, how fast, how much" is
    // asked often enough not to be filtered out of everything else each time.
    // From log_channels(), so the viewer and the writer cannot disagree about
    // which streams exist.
    $channel = in_array($channel, array_merge(log_channels(), ['all']), true) ? $channel : 'all';

    $filters = [
        'event'    => trim((string) input('event', '')),
        'severity' => input('severity', ''),
        'q'        => trim((string) input('q', '')),
        'since'    => trim((string) input('since', '')),
    ];

    $page = max(1, (int) (input_int('page') ?? 1));
    $per  = 100;

    render('auth/logs', [
        'pageTitle' => 'Instance Logs',
        'channel'   => $channel,
        'filters'   => $filters,
        'rows'      => log_entries($channel, $filters, $per, ($page - 1) * $per),
        'events'    => log_known_events($channel),
        'page'      => $page,
        'counts'    => [
            'all'      => (int) scalar("SELECT COUNT(*) FROM logs"),
            'security' => (int) scalar("SELECT COUNT(*) FROM logs WHERE channel = 'security'"),
            'server'   => (int) scalar("SELECT COUNT(*) FROM logs WHERE channel = 'server'"),
            'metadata' => (int) scalar("SELECT COUNT(*) FROM logs WHERE channel = 'metadata'"),
        ],
    ]);
}

function logs_action(): void
{
    require_admin();
    csrf_verify();

    // The only thing left that this page does: everything else moved to
    // Instance settings, because reading a log and deciding what is logged are
    // different jobs and mixing them made the reading page mostly a form.
    if ((string) input('action', '') === 'prune') {
        $n = log_prune();
        flash('ok', $n === 0 ? 'Nothing was old enough to remove.' : "$n entries removed.");
    }

    redirect('/admin/logs');
}

/*
 * The notification pages.
 *
 * These four handlers were routed but did not exist - /notifications and
 * /profile/notifications were both 500s, with their templates sitting unused on disk.
 * The templates were fine; the controllers had been lost somewhere along the way, and
 * nothing noticed because the smoke test only ever asked for pages that were linked.
 */

/** What has been sent to this account. */
function notifications_index(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('/login');
    }

    // Opening this page answers the unread notice, so it may be raised again when the
    // next thing arrives. See the layout, which announces the count once per visit.
    unset($_SESSION['unread_announced']);

    render('auth/notifications', [
        'pageTitle' => 'Notifications',
        'unread'    => unread_notification_count((int) $user['id']),
        'rows'      => all(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 200',
            [(int) $user['id']]
        ),
    ]);
}

/** Mark one read, mark them all read, or remove one. */
function notifications_action(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('/login');
    }
    csrf_verify();

    $action = (string) input('action', '');
    $id     = input_int('id');
    $uid    = (int) $user['id'];

    if ($action === 'read_all') {
        $n = (int) scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$uid]);
        q('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$uid]);
        flash('ok', $n === 0 ? 'Nothing was unread.' : "$n marked as read.");
        redirect('/notifications');
    }

    // Always scoped to this account: an id from somebody else's list is not theirs to
    // read or remove, and the where clause is the whole of that check.
    if ($id !== null && $action === 'read') {
        q('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?', [$id, $uid]);
    } elseif ($id !== null && $action === 'delete') {
        q('DELETE FROM notifications WHERE id = ? AND user_id = ?', [$id, $uid]);
    }
    redirect('/notifications');
}

/** Which kinds this account wants, and by which channel. */
function notification_prefs_index(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('/login');
    }

    $chosen = [];
    foreach (all('SELECT kind, in_app, by_mail FROM notification_prefs WHERE user_id = ?', [(int) $user['id']]) as $r) {
        $chosen[(string) $r['kind']] = ['in_app' => (int) $r['in_app'], 'by_mail' => (int) $r['by_mail']];
    }

    // Every kind, with the account's answer where it has one and the kind's own default
    // where it does not - so a kind added later appears with its intended setting rather
    // than switched off because nobody has an opinion on it yet.
    $prefs = [];
    foreach (notification_kinds() as $kind => $def) {
        $prefs[$kind] = $def + [
            'in_app'  => $chosen[$kind]['in_app']  ?? (int) $def['in_app'],
            'by_mail' => $chosen[$kind]['by_mail'] ?? (int) $def['by_mail'],
        ];
    }

    render('auth/notification_prefs', [
        'pageTitle'   => 'Notification settings',
        'prefs'       => $prefs,
        'mailEnabled' => (string) setting('smtp_enabled', '0') === '1',
        'mailForYou'  => function_exists('mail_available_for') && mail_available_for((int) $user['id']),
        'address'     => $user['email'] ?? null,
    ]);
}

function notification_prefs_save(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('/login');
    }
    csrf_verify();
    $uid = (int) $user['id'];

    // A row per kind, written whatever the answer: an absent row means "no opinion" and
    // would fall back to the default, so unticking something has to be recorded rather
    // than left out.
    $inApp = $_POST['in_app'] ?? [];
    $byMail = $_POST['by_mail'] ?? [];
    foreach (array_keys(notification_kinds()) as $kind) {
        q('INSERT INTO notification_prefs (user_id, kind, in_app, by_mail)
           VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE in_app = VALUES(in_app), by_mail = VALUES(by_mail)',
          [$uid, $kind,
           is_array($inApp)  && isset($inApp[$kind])  ? 1 : 0,
           is_array($byMail) && isset($byMail[$kind]) ? 1 : 0]);
    }

    forget_notification_prefs($uid);
    flash('ok', 'Saved.');
    redirect('/profile/notifications');
}
