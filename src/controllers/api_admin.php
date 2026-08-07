<?php
declare(strict_types=1);

/**
 * The screens a native client had to send somebody to a browser for.
 *
 * Three of them, and the ones every account uses rather than only an
 * administrator: your own details, what you want to be told about, and - for an
 * administrator - how the instance is configured.
 *
 * User management, library management, the logs and maintenance are still web
 * only. They are listed here so the next person reading this file knows the gap
 * is deliberate and not an oversight.
 */

// ---------------------------------------------------------------------------
// Your own account
// ---------------------------------------------------------------------------

function api_profile_show(): void
{
    [$user] = api_require_auth();
    $row = one('SELECT id, username, display_name, email, email_verified_at, role,
                       avatar_filename, created_at, last_login_at
                  FROM users WHERE id = ?', [(int) $user['id']]);
    if ($row === null) {
        api_error('not_found', 'That account no longer exists.', 404);
    }

    api_ok([
        'id'           => (int) $row['id'],
        // Not editable here on purpose. A username is what other people's
        // library memberships point at by sight, and renaming one quietly is a
        // different job from editing a profile.
        'username'     => $row['username'],
        'display_name' => $row['display_name'],
        'email'        => $row['email'],
        'email_verified' => ($row['email_verified_at'] ?? null) !== null,
        'role'         => $row['role'],
        'avatar'       => absolute_url(image_url($row['avatar_filename'] ?? null, 'thumb')),
        'created_at'   => api_datetime($row['created_at'] ?? null),
        'last_login_at'=> api_datetime($row['last_login_at'] ?? null),
    ]);
}

/**
 * Change your display name, your address, or your password.
 *
 * A password change wants the current one, even though the caller is already
 * holding a valid token: a phone left unlocked on a table is exactly the case
 * where "already signed in" is not the same as "is the account holder".
 */
function api_profile_update(): void
{
    [$user] = api_require_write();
    $in     = api_body();
    $id     = (int) $user['id'];
    $fields = [];

    if (array_key_exists('display_name', $in)) {
        $name = trim((string) $in['display_name']);
        $fields['display_name'] = $name === '' ? null : mb_substr($name, 0, 120);
    }

    if (array_key_exists('email', $in)) {
        $email = trim((string) $in['email']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_error('validation_failed', 'That does not look like an address.', 422,
                      ['email' => 'That does not look like an address.']);
        }
        if ($email !== '' && one('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id]) !== null) {
            api_error('validation_failed', 'Another account already uses that address.', 422,
                      ['email' => 'Another account already uses that address.']);
        }
        $fields['email'] = $email === '' ? null : $email;
    }

    if (array_key_exists('password', $in)) {
        $current = (string) ($in['current_password'] ?? '');
        $fresh   = (string) $in['password'];
        $row     = one('SELECT password_hash FROM users WHERE id = ?', [$id]);

        if ($row === null || !password_verify($current, (string) $row['password_hash'])) {
            api_error('validation_failed', 'The current password is not right.', 422,
                      ['current_password' => 'The current password is not right.']);
        }
        if (mb_strlen($fresh) < 10) {
            api_error('validation_failed', 'A password needs at least ten characters.', 422,
                      ['password' => 'A password needs at least ten characters.']);
        }
        $fields['password_hash'] = password_hash($fresh, PASSWORD_DEFAULT);
    }

    if ($fields === []) {
        api_error('validation_failed', 'Nothing to change.', 422);
    }

    update_row('users', $id, $fields);
    // Logged where it happens, because a password change is a security event and
    // nothing further down would know one had occurred.
    if (isset($fields['password_hash'])) {
        log_security('password.changed', 'Password changed from the API.', LOG_NOTICE);
    }

    api_profile_show();
}

// ---------------------------------------------------------------------------
// What you want to be told about
// ---------------------------------------------------------------------------

/**
 * Described, not just the values.
 *
 * Which kinds exist is decided in notify.php and changes; a client that had them
 * written down would quietly stop offering the new ones.
 */
function api_notification_prefs_show(): void
{
    [$user] = api_require_auth();

    $out = [];
    foreach (notification_prefs_for((int) $user['id']) as $kind => $pref) {
        $out[] = [
            'kind'        => $kind,
            'label'       => $pref['label'],
            'description' => $pref['description'],
            'in_app'      => (bool) $pref['in_app'],
            'by_mail'     => (bool) $pref['by_mail'],
            // Whether this account has ever said, as opposed to inheriting the
            // default for the kind.
            'explicit'    => (bool) $pref['explicit'],
        ];
    }

    api_ok($out, [
        // Mail that is not configured cannot be sent, so a client can grey the
        // column rather than offer a switch that does nothing.
        'mail_enabled' => (bool) setting('smtp_enabled', ''),
    ]);
}

function api_notification_prefs_update(): void
{
    [$user] = api_require_write();
    $in     = api_body();
    $prefs  = $in['prefs'] ?? null;

    if (!is_array($prefs)) {
        api_error('validation_failed',
                  'Send prefs as an object of kind => {in_app, by_mail}.', 422);
    }

    $known = notification_kinds();
    foreach (array_keys($prefs) as $kind) {
        if (!isset($known[$kind])) {
            api_error('validation_failed', 'No notification kind called "' . $kind . '".', 422);
        }
    }

    // save_notification_prefs() writes every kind, treating anything missing as
    // off - which is right for a form that posts checkboxes and wrong for a
    // client sending one changed switch. So the current set is read first and
    // what arrived is laid over it.
    $merged = ['in_app' => [], 'by_mail' => []];
    foreach (notification_prefs_for((int) $user['id']) as $kind => $pref) {
        $merged['in_app'][$kind]  = $pref['in_app']  ? 1 : null;
        $merged['by_mail'][$kind] = $pref['by_mail'] ? 1 : null;
        if (isset($prefs[$kind]) && is_array($prefs[$kind])) {
            foreach (['in_app', 'by_mail'] as $channel) {
                if (array_key_exists($channel, $prefs[$kind])) {
                    $merged[$channel][$kind] = filter_var(
                        $prefs[$kind][$channel], FILTER_VALIDATE_BOOL) ? 1 : null;
                }
            }
        }
    }

    save_notification_prefs((int) $user['id'], $merged);
    api_notification_prefs_show();
}

// ---------------------------------------------------------------------------
// The instance
// ---------------------------------------------------------------------------

/**
 * The settings, and what each one is.
 *
 * The description comes with the values so a client can draw the form without
 * knowing anything about RetroHive's settings in advance - and so a setting
 * added to settings_schema() next year appears in an app nobody rebuilt.
 */
function api_settings_show(): void
{
    api_require_admin();

    $sections = [];
    foreach (settings_schema() as $key => $section) {
        $fields = [];
        foreach ($section['fields'] as $name => $field) {
            $described = [
                'name'  => $name,
                'kind'  => $field['kind'],
                'label' => $field['label'],
                'value' => setting_to_api($name, $field),
            ];
            foreach (['help', 'min', 'max'] as $extra) {
                if (isset($field[$extra])) { $described[$extra] = $field[$extra]; }
            }
            if (isset($field['options'])) {
                $described['options'] = array_map(
                    fn($value, $label) => ['value' => (string) $value, 'label' => $label],
                    array_keys($field['options']),
                    array_values($field['options'])
                );
            }
            if ($field['kind'] === 'secret') {
                // Never the value. Whether there is one is all a form needs to
                // decide between "change it" and "set it".
                $described['is_set'] = setting($name, '') !== '';
            }
            $fields[] = $described;
        }
        $sections[] = [
            'key'    => $key,
            'label'  => $section['label'],
            'help'   => $section['help'] ?? null,
            'fields' => $fields,
        ];
    }

    api_ok($sections, [
        'app_version' => APP_VERSION,
        'php_version' => PHP_VERSION,
    ]);
}

/**
 * Change some of them.
 *
 * Everything is checked before anything is written. A request that sets a good
 * host and a bad port used to be worth half applying; it is not, because the half
 * that applied is the half nobody was told about.
 */
function api_settings_update(): void
{
    api_require_admin();
    $in     = api_body();
    $wanted = $in['settings'] ?? null;

    if (!is_array($wanted) || $wanted === []) {
        api_error('validation_failed', 'Send settings as an object of name => value.', 422);
    }

    $schema  = settings_schema_fields();
    $checked = [];
    $errors  = [];

    foreach ($wanted as $name => $value) {
        if (!isset($schema[$name])) {
            $errors[(string) $name] = 'There is no setting called that.';
            continue;
        }
        [$storable, $problem] = setting_from_api((string) $name, $schema[$name], $value);
        if ($problem !== null) {
            $errors[(string) $name] = $problem;
            continue;
        }
        // The one field in this generic schema with a rule the schema
        // itself cannot express: requiring a confirmed email address
        // to sign in is safe only against a relay that has actually
        // answered a test message - turned on regardless, this locks
        // out every account, including whoever just turned it on. The
        // real app's own section='signin' handler already enforces
        // this; the generic path here has no equivalent unless it is
        // named explicitly.
        if ($name === 'require_email_verification' && $storable === '1' && !mail_verified()) {
            $errors[(string) $name] = 'Needs a relay that has answered a test message first.';
            continue;
        }
        $checked[(string) $name] = $storable;
    }

    if ($errors !== []) {
        api_error('validation_failed', 'Some settings were refused.', 422, $errors);
    }

    foreach ($checked as $name => $value) {
        set_setting($name, $value);
    }

    api_settings_show();
}

/**
 * Registration, on its own - not folded into the generic settings
 * schema above. The real shape here (four modes including invite,
 * a three-way approval, a rotatable secret URL that is a computed
 * value rather than a stored one) doesn't fit that schema's own
 * select/bool/secret vocabulary without either lying about the type
 * or leaving invite mode unreachable, so this is a dedicated pair of
 * endpoints instead, the same way auth methods and hardware sync
 * already got their own rather than being squeezed into something
 * more generic that doesn't actually describe them.
 */
function api_registration_show(): void
{
    api_require_admin();
    $mode = registration_mode();
    api_ok([
        'mode'               => $mode,
        'approval'           => registration_approval(),
        'mail_enabled'       => mail_enabled(),
        'mail_verified'      => mail_verified(),
        'secret_url'         => $mode === 'secret' ? registration_secret_url() : null,
    ]);
}

function api_registration_update(): void
{
    api_require_admin();
    $in = api_body();

    if (!empty($in['rotate_secret'])) {
        // Its own action, not a side effect of a mode/approval save -
        // the same reasoning the real app's own settings page gives:
        // a secret that changes every time somebody saves the page is
        // a secret nobody can hand out.
        registration_secret_rotate();
        api_ok(['mode' => registration_mode(), 'approval' => registration_approval(),
                'mail_enabled' => mail_enabled(), 'mail_verified' => mail_verified(),
                'secret_url' => registration_secret_url()]);
        return;
    }

    $mode = (string) ($in['mode'] ?? registration_mode());
    if (!in_array($mode, REGISTRATION_MODES, true)) {
        api_error('validation_failed', 'That is not a real registration mode.', 422, ['mode' => 'Must be closed, public, secret, or invite.']);
    }
    // A mode that cannot work is not a mode - invitations go out by
    // email, so choosing that without a relay working would be a
    // sign-up route nobody can ever reach, refused here rather than
    // discovered by the first person who tries to invite somebody.
    $blocked = registration_mode_blocked($mode);
    if ($blocked !== null) {
        api_error('validation_failed', $blocked, 422, ['mode' => $blocked]);
    }
    set_setting('registration_mode', $mode);

    $approval = (string) ($in['approval'] ?? registration_approval());
    if (in_array($approval, ['auto', 'email', 'admin'], true)) {
        set_setting('registration_approval', $approval);
    }

    api_ok([
        'mode'          => registration_mode(),
        'approval'      => registration_approval(),
        'mail_enabled'  => mail_enabled(),
        'mail_verified' => mail_verified(),
        'secret_url'    => registration_mode() === 'secret' ? registration_secret_url() : null,
    ]);
}

// ---------------------------------------------------------------------------
// The log
// ---------------------------------------------------------------------------

/**
 * The log, filtered.
 *
 * Read-only, and administrator-only for the same reason the screen is: the
 * security stream names who signed in from where, and that is not a thing a
 * library curator is entitled to read.
 *
 * The filters are the ones the web viewer offers, because a client that can see
 * less than the page it replaces is not a replacement.
 */
function api_logs_index(): void
{
    api_require_admin();

    $channel = (string) ($_GET['channel'] ?? 'all');
    if ($channel !== 'all' && !in_array($channel, log_channels(), true)) {
        api_error('validation_failed',
                  'channel must be all, ' . implode(', ', log_channels()) . '.', 422);
    }

    $filters = [];
    foreach (['event', 'actor', 'since', 'q'] as $key) {
        if (isset($_GET[$key]) && trim((string) $_GET[$key]) !== '') {
            $filters[$key] = trim((string) $_GET[$key]);
        }
    }
    if (isset($_GET['severity']) && $_GET['severity'] !== '') {
        if (!preg_match('/^[0-7]$/', (string) $_GET['severity'])) {
            api_error('validation_failed', 'severity is a syslog level, 0 to 7.', 422);
        }
        $filters['severity'] = (int) $_GET['severity'];
    }
    if (isset($filters['since']) && strtotime($filters['since']) === false) {
        api_error('validation_failed', 'since must be a timestamp the server can read.', 422);
    }

    $limit  = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $rows = log_entries($channel, $filters, $limit, $offset);

    api_ok(array_map('log_entry_to_api', $rows), [
        'channel'  => $channel,
        'limit'    => $limit,
        'offset'   => $offset,
        // What the tabs on the web viewer count, so a client can draw the same
        // thing without four requests.
        'channels' => log_channel_counts(),
        // Only the events that have happened, so a filter is a list rather than
        // guesswork.
        'events'   => log_known_events($channel),
    ]);
}

/** One entry, as the API sends it. */
function log_entry_to_api(array $row): array
{
    return [
        'id'        => (int) $row['id'],
        'channel'   => (string) $row['channel'],
        'severity'  => (int) $row['severity'],
        // The word as well as the number, from log.php's own table rather than
        // a second copy of it here - a client should not have to carry the
        // syslog levels around to draw a coloured dot.
        'severity_label' => log_severity_label((int) $row['severity']),
        'event'     => (string) $row['event'],
        'message'   => (string) $row['message'],
        'actor'     => $row['actor_id'] === null ? null : [
            'id'   => (int) $row['actor_id'],
            'name' => (string) ($row['actor_name'] ?? ''),
        ],
        'subject'   => ($row['subject_type'] ?? null) === null ? null : [
            'type' => (string) $row['subject_type'],
            'id'   => $row['subject_id'] === null ? null : (int) $row['subject_id'],
        ],
        'ip'         => $row['ip'] ?? null,
        'created_at' => api_datetime($row['created_at'] ?? null),
    ];
}

/** Per channel, for the tabs. */
function log_channel_counts(): array
{
    $out = ['all' => (int) scalar('SELECT COUNT(*) FROM logs')];
    foreach (log_channels() as $channel) {
        $out[$channel] = (int) scalar('SELECT COUNT(*) FROM logs WHERE channel = ?', [$channel]);
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Maintenance
// ---------------------------------------------------------------------------

/**
 * The jobs, and what each one currently finds.
 *
 * Every check is run to answer this, which is the point: a list of jobs with no
 * findings beside them is a list of things somebody might press, and the reason
 * to press one is that it found something.
 *
 * Instance jobs only. The library ones belong to a library and are reached
 * through it, and mixing the two here would mean this endpoint answering
 * differently depending on which library the caller happens to be looking at.
 */
function api_maintenance_index(): void
{
    api_require_admin();

    // Not maintenance_jobs_for(), which filters admin jobs with is_admin() -
    // and that reads the session, which an API request does not have. It would
    // have returned an empty list to an administrator holding a valid token, and
    // it did. api_require_admin() above has already established the same thing
    // from the token, so the filter here is only about scope.
    $out = [];
    foreach (maintenance_jobs() as $key => $job) {
        if ($job['scope'] !== 'instance') {
            continue;
        }
        $result = maintenance_run_check($key);
        $out[] = [
            'job'          => $key,
            'label'        => (string) $job['label'],
            'blurb'        => (string) $job['blurb'],
            'count'        => (int) ($result['count'] ?? 0),
            // maintenance_result() calls it `note`. Read as `message` it was
            // always the empty string, so every job on the native screen said
            // nothing about what it had found.
            'message'      => (string) ($result['note'] ?? ''),
            // Examples, capped by the check itself: "nine thousand" and the
            // first ten of them is as much as anybody can act on at once.
            'rows'         => array_values((array) ($result['rows'] ?? [])),
            'repairable'   => $job['repair'] !== null,
            'repair_label' => $job['repair_label'],
        ];
    }

    api_ok($out);
}

/**
 * Run one repair.
 *
 * A write, and an administrator's: these delete files, rewrite paths and forget
 * rows. The check is run again afterwards so the answer says what is left rather
 * than what was found before.
 */
function api_maintenance_run(string $key): void
{
    api_require_admin();

    $jobs = maintenance_jobs();
    if (!isset($jobs[$key]) || $jobs[$key]['scope'] !== 'instance') {
        api_error('not_found', 'No instance job called "' . $key . '".', 404);
    }
    $job = $jobs[$key];
    if ($job['repair'] === null) {
        api_error('validation_failed',
                  '"' . $job['label'] . '" reports and does not repair.', 422);
    }

    $fn  = $job['repair'];
    $out = $fn();

    log_security('maintenance.run',
                 sprintf('Ran "%s" from the API: %s', $job['label'],
                         (string) ($out['message'] ?? '')),
                 LOG_NOTICE);

    $after = maintenance_run_check($key);
    api_ok([
        'job'     => $key,
        'label'   => (string) $job['label'],
        'done'    => (bool) ($out['done'] ?? false),
        'message' => (string) ($out['message'] ?? ''),
        'count'   => (int) ($after['count'] ?? 0),
    ]);
}

// ---------------------------------------------------------------------------
// Accounts
// ---------------------------------------------------------------------------

/** One account, as the API sends it. */
function api_user_row(array $u): array
{
    return [
        'id'           => (int) $u['id'],
        'username'     => (string) $u['username'],
        'display_name' => (string) ($u['display_name'] ?? ''),
        'email'        => $u['email'] ?? null,
        'role'         => (string) $u['role'],
        'is_active'    => (bool) $u['is_active'],
        // Named, not just an id: "auth method 3" means nothing on a
        // phone. The id rides along too now, additive - a picker
        // choosing which directory to reassign to needs a real value
        // to submit, not just something to display.
        'signs_in_via'    => $u['auth_name'] ?? null,
        'auth_method_id'  => isset($u['auth_method_id']) ? (int) $u['auth_method_id'] : null,
        'last_login_at' => api_datetime($u['last_login_at'] ?? null),
        'created_at'    => api_datetime($u['created_at'] ?? null),
        // The picture in use, and the one waiting for a decision. Both, because
        // this screen's whole job when a picture is pending is to show them side
        // by side - "is this an improvement on that" is the question being
        // asked, and it cannot be answered with one of them.
        'avatar'         => absolute_url(image_url($u['avatar_filename'] ?? null, 'thumb')),
        'avatar_pending' => absolute_url(image_url($u['avatar_pending_filename'] ?? null, 'thumb')),
        'avatar_pending_at' => api_datetime($u['avatar_pending_at'] ?? null),
    ];
}

/**
 * Where mail confirmation stands, and the two steps that move it along.
 *
 * A relay will happily accept a message and drop it, so "the settings saved" is
 * no evidence that mail arrives. The only evidence is somebody holding a number
 * that was sent to them - which is why this is a code typed back rather than a
 * "we sent something" button.
 *
 * The engine's own screen has had this throughout; it lived in a form handler
 * and had no endpoint, so no other client could offer it.
 */
function api_mail_status(): void
{
    api_require_admin();

    $sentAt  = (string) setting('smtp_code_sent', '');
    // A code is only good against the settings it was sent under, so a pending
    // code stops being pending the moment the host changes - saying otherwise
    // would have somebody typing a number that cannot work.
    $pending = $sentAt !== ''
        && (string) setting('smtp_code_for', '') === smtp_fingerprint()
        && (time() - (int) $sentAt) < 1800;

    api_ok([
        'enabled'     => mail_enabled(),
        'verified'    => mail_verified(),
        'verified_at' => api_datetime(setting('smtp_verified_at', '') ?: null),
        'code_to'     => (string) setting('smtp_code_to', ''),
        'code_pending' => $pending,
        // Half an hour from sending, and what is left of it - a screen saying
        // "good for half an hour" beside a code sent twenty-nine minutes ago is
        // telling somebody the wrong thing.
        'code_expires_in' => $pending ? max(0, 1800 - (time() - (int) $sentAt)) : null,
    ]);
}

function api_mail_send_code(): void
{
    api_require_admin();
    $to = trim((string) (api_body()['to'] ?? ''));
    [$ok, $message] = send_smtp_confirmation($to);
    if (!$ok) {
        // 422 rather than 500: almost every failure here is a setting somebody
        // typed - a wrong host, a refused sender - and the message says which.
        api_error('mail_failed', $message, 422);
    }
    log_security('smtp.code_sent', 'Mail confirmation code sent to ' . $to, LOG_INFO);
    api_ok(['sent_to' => $to], ['message' => $message]);
}

function api_mail_confirm_code(): void
{
    api_require_admin();
    [$ok, $message] = confirm_smtp_code((string) (api_body()['code'] ?? ''));
    if (!$ok) {
        api_error('validation_failed', $message, 422, ['code' => $message]);
    }
    log_security('smtp.confirmed', 'Mail delivery confirmed', LOG_INFO);
    api_ok(['verified' => mail_verified()], ['message' => $message]);
}

/**
 * Yes or no to somebody's new profile picture.
 *
 * Administrators only, which is the same gate the rest of this file uses. An
 * administrator's own picture never arrives here - avatar_approval_required()
 * exempts them - so there is no case of approving your own.
 */
function api_avatar_decide(int $userId, bool $approve): void
{
    api_require_admin();

    $row = one('SELECT id, username, avatar_pending_filename FROM users WHERE id = ?', [$userId]);
    if ($row === null) {
        api_error('not_found', 'No such account.', 404);
    }
    if (empty($row['avatar_pending_filename'])) {
        api_error('validation_failed', 'That account has no picture waiting.', 422);
    }

    if ($approve) {
        approve_user_avatar($userId);
        log_event('security', 'avatar.approved',
                  'Profile picture approved for "' . $row['username'] . '"');
    } else {
        // The files go with the decision. A refused picture kept on disk is a
        // refused picture somebody can still reach by guessing a URL.
        delete_user_pending_avatar($userId);
        log_event('security', 'avatar.rejected',
                  'Profile picture refused for "' . $row['username'] . '"');
    }

    $after = one("SELECT u.*, am.name AS auth_name
                    FROM users u
               LEFT JOIN auth_methods am ON am.id = u.auth_method_id
                   WHERE u.id = ?", [$userId]);
    api_ok(api_user_row($after));
}

/**
 * Every account.
 *
 * `meta.admin_count` rides along because the one rule this screen has to
 * enforce - never remove the last administrator - cannot be checked on the
 * device without it.
 */
function api_users_index(): void
{
    api_require_admin();

    $rows = all(
        "SELECT u.*, am.name AS auth_name
           FROM users u
      LEFT JOIN auth_methods am ON am.id = u.auth_method_id
       ORDER BY u.username"
    );

    api_ok(array_map('api_user_row', $rows), [
        'admin_count' => (int) scalar(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1"),
        // What the column actually holds. There is no curator and no viewer -
        // library access is a membership, not a role, and inventing extra words
        // here would have written values the ENUM refuses.
        'roles' => ['admin' => 'Administrator', 'user' => 'Member'],
    ]);
}

/**
 * Change one.
 *
 * Absent fields are left alone, so a client can change a role without resending
 * an email address it may have truncated for display.
 *
 * Two things are refused outright rather than warned about: locking the last
 * administrator out of the instance, and changing your own role. The second is
 * not paternalism - an administrator who demotes themselves by mistake cannot
 * undo it, because undoing it needs the role they just gave up.
 */
function api_users_update(int $id): void
{
    [$me] = api_require_admin();

    $user = one('SELECT * FROM users WHERE id = ?', [$id]);
    if ($user === null) {
        api_error('not_found', 'No account with that id.', 404);
    }

    $in = api_body();
    if ($in === []) {
        api_error('validation_failed', 'Nothing to change.', 422);
    }

    $errors  = [];
    $changes = [];

    if (array_key_exists('role', $in)) {
        $role = (string) $in['role'];
        if (!in_array($role, ['admin', 'user'], true)) {
            $errors['role'] = 'Must be admin or user.';
        } elseif ((int) $user['id'] === (int) $me['id'] && $role !== (string) $user['role']) {
            $errors['role'] = 'You cannot change your own role - you would need the role '
                            . 'you just gave up to change it back.';
        } else {
            $changes['role'] = $role;
        }
    }

    if (array_key_exists('is_active', $in)) {
        $active = (bool) $in['is_active'];
        if (!$active && (int) $user['id'] === (int) $me['id']) {
            $errors['is_active'] = 'You cannot close your own account from here.';
        } else {
            $changes['is_active'] = $active ? 1 : 0;
        }
    }

    if (array_key_exists('display_name', $in)) {
        $name = trim((string) $in['display_name']);
        if ($name === '') {
            $errors['display_name'] = 'A display name cannot be empty.';
        } else {
            $changes['display_name'] = mb_substr($name, 0, 190);
        }
    }

    if (array_key_exists('email', $in)) {
        $email = trim((string) $in['email']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'That does not look like an email address.';
        } else {
            $changes['email'] = $email === '' ? null : $email;
        }
    }

    // Blank means "don't change it" - the same rule a credential field
    // has followed everywhere else in this application, so setting a
    // password is never forced just because other fields were sent
    // in the same call.
    if (!empty($in['password'])) {
        $password = (string) $in['password'];
        if (strlen($password) < 10) {
            $errors['password'] = 'At least 10 characters.';
        } else {
            $changes['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
    }

    // Which directory this account signs in through - the protected
    // local method's own id for the local database, or a real
    // directory's id. auth_method_id is NOT NULL DEFAULT 1 in the
    // schema itself, so "local" is never a null - it's whichever row
    // is_protected marks as the one always there. Genuinely new: the
    // real app has never offered a way to move an account between
    // directories once created, only to read which one it already
    // uses.
    if (array_key_exists('auth_method_id', $in)) {
        $wantLocal = $in['auth_method_id'] === null;
        $newMethodId = $wantLocal
            ? (int) (scalar("SELECT id FROM auth_methods WHERE is_protected = 1") ?? 1)
            : (int) $in['auth_method_id'];
        $method = one('SELECT id FROM auth_methods WHERE id = ?', [$newMethodId]);
        if ($method === null) {
            $errors['auth_method_id'] = 'No such directory.';
        } else {
            $changes['auth_method_id'] = $newMethodId;
        }
    }

    // The last administrator, checked against what the change would leave rather
    // than what is there now - demoting and deactivating both get here.
    $losingAdmin = ((($changes['role'] ?? $user['role']) !== 'admin')
                    || (($changes['is_active'] ?? (int) $user['is_active']) === 0))
                   && (string) $user['role'] === 'admin'
                   && (int) $user['is_active'] === 1;
    if ($losingAdmin) {
        $others = (int) scalar(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?",
            [$id]);
        if ($others === 0) {
            $errors['role'] = 'That is the only administrator left. Promote somebody else first.';
        }
    }

    if ($errors !== []) {
        api_error('validation_failed', 'Some fields need attention.', 422, $errors);
    }
    if ($changes === []) {
        api_error('validation_failed', 'Nothing to change.', 422);
    }

    update_row('users', $id, $changes);

    // What actually changed, in words rather than raw column names -
    // a credential is never named or shown, only that one was set.
    $changeWords = array_map(
        static fn(string $k): string => $k === 'password_hash' ? 'password set' : $k,
        array_keys($changes)
    );
    log_security('user.changed',
                 sprintf('Changed %s: %s', $user['username'], implode(', ', $changeWords)),
                 LOG_NOTICE, ['subject_type' => 'user', 'subject_id' => $id]);

    $fresh = one("SELECT u.*, am.name AS auth_name FROM users u
                    LEFT JOIN auth_methods am ON am.id = u.auth_method_id
                   WHERE u.id = ?", [$id]);
    api_ok(api_user_row($fresh));
}

/**
 * Remove an account outright - a client for the same guard the real
 * page's own delete action already enforces: never your own account,
 * and never the last administrator who can still sign in, since either
 * one leaves an instance with no way back short of the database
 * itself.
 */
function api_users_delete(int $id): void
{
    [$me] = api_require_admin();

    $target = one('SELECT id, username, role, is_active FROM users WHERE id = ?', [$id]);
    if ($target === null) {
        api_error('not_found', 'No account with that id.', 404);
    }
    if ($id === (int) $me['id']) {
        api_error('forbidden', 'You cannot delete the account you are signed in with.', 403);
    }

    $adminsLeft = (int) scalar(
        "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?", [$id]);
    if ((string) $target['role'] === 'admin' && (int) $target['is_active'] === 1 && $adminsLeft === 0) {
        api_error('forbidden', sprintf(
            '%s is the only administrator who can sign in, so it cannot be removed. '
            . 'Make another account an administrator first, or disable this one instead.',
            (string) $target['username']
        ), 403);
    }

    delete_row('users', $id);
    log_security('account.deleted', sprintf('Account "%s" removed', (string) $target['username']),
                 LOG_WARNING, ['username' => (string) $target['username'], 'role' => (string) $target['role']]);
    api_no_content();
}

/**
 * One account's library access - a client for the same table the real
 * access page reads (user_grants()), not a reimplementation. [libraryId
 * => access] for whichever libraries this account currently has a
 * membership row in; a library absent from the map means no access.
 */
function api_user_access_show(int $id): void
{
    api_require_admin();
    $user = one('SELECT id, username FROM users WHERE id = ?', [$id]);
    if ($user === null) {
        api_error('not_found', 'No account with that id.', 404);
    }
    api_ok(user_grants($id));
}

/**
 * Rewrite one account's library access wholesale - a client for
 * access_save()'s own rule, not a new one: membership is the whole of
 * access, so a library absent from the submitted map has its membership
 * removed, exactly as the real form's own bulk save already works.
 * Owner is never assignable here - it is one column on the library
 * itself, changed by being offered and accepted, not by this map - and a
 * personal library keeps its owner's membership regardless, the same
 * guard the original carries.
 */
function api_user_access_update(int $id): void
{
    [$me] = api_require_admin();
    $user = one('SELECT id, username FROM users WHERE id = ?', [$id]);
    if ($user === null) {
        api_error('not_found', 'No account with that id.', 404);
    }

    $in = api_body();
    $submitted = $in['access'] ?? [];
    if (!is_array($submitted)) {
        api_error('validation_failed', 'Must be an object of libraryId => level.', 422,
                   ['access' => 'Must be an object.']);
    }

    $keep = [];
    foreach ($submitted as $libraryId => $level) {
        $libraryId = (int) $libraryId;
        $level     = is_string($level) ? $level : ACCESS_NONE;

        if (!in_array($level, [ACCESS_VIEWER, ACCESS_CONTRIBUTOR, ACCESS_EDITOR, ACCESS_CURATOR, ACCESS_ADMIN], true)) {
            continue;
        }
        if (one('SELECT id FROM libraries WHERE id = ?', [$libraryId]) === null) {
            continue;
        }

        q('INSERT INTO library_members (library_id, user_id, access, granted_by, note)
           VALUES (?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE access = VALUES(access), granted_by = VALUES(granted_by)',
          [$libraryId, $id, $level, $me === null ? null : (int) $me['id'], 'Set from the access page']);
        $keep[] = $libraryId;
    }

    if ($keep === []) {
        q('DELETE FROM library_members WHERE user_id = ?', [$id]);
    } else {
        $placeholders = implode(',', array_fill(0, count($keep), '?'));
        q("DELETE FROM library_members WHERE user_id = ? AND library_id NOT IN ($placeholders)",
          array_merge([$id], $keep));
    }

    q("INSERT IGNORE INTO library_members (library_id, user_id, access, note)
       SELECT id, owner_id, ?, 'Personal library'
       FROM libraries WHERE is_personal = 1 AND owner_id = ?", [ACCESS_OWNER, $id]);

    $GLOBALS['__membership_cache'] = [];
    api_ok(user_grants($id));
}

// ---------------------------------------------------------------------------
// Libraries
// ---------------------------------------------------------------------------

/** One library, for an administrator: everything, with who owns it and what is in it. */
function api_admin_library_row(array $l): array
{
    return library_to_api($l) + [
        'owner'      => $l['owner_name'] === null ? null : [
            'id'   => (int) $l['owner_id'],
            'name' => (string) $l['owner_name'],
        ],
        'is_active'  => (bool) (int) ($l['is_active'] ?? 1),
        'is_default' => (bool) (int) ($l['is_default'] ?? 0),
        'created_at' => api_datetime($l['created_at'] ?? null),
    ];
}

/**
 * Every library, including the ones this administrator cannot read.
 *
 * Deliberately not /libraries, which answers with what the caller may see - an
 * administrator managing an instance needs the list to be complete, or a library
 * they have no membership of is one they cannot fix either.
 *
 * The count is every entry in it, for the same reason: it decides whether the
 * library can be deleted, and a count filtered by what the caller may read would
 * make an emptiable library look empty.
 */
function api_admin_libraries_index(): void
{
    api_require_admin();

    // Every library on the instance except somebody else's private one.
    //
    // Administering an instance means the shared shelves, the club libraries,
    // the ones with members - not reading the list of what a colleague keeps at
    // home. `acl.php` is deliberate that membership is the whole of access and
    // that being an administrator grants nothing; this screen used to be the one
    // place that quietly disagreed, listing every private shelf on the instance
    // with its owner's name and a count of what is in it.
    //
    // The administrator's own private library stays, because it is theirs.
    $me = (int) (acting_user()['id'] ?? 0);
    $rows = all(
        "SELECT l.*, u.display_name AS owner_name,
                (SELECT COUNT(*) FROM items i
                  WHERE i.library_id = l.id AND i.deleted_at IS NULL) AS n
           FROM libraries l
      LEFT JOIN users u ON u.id = l.owner_id
          WHERE l.kind <> 'private' OR l.owner_id <=> ?
       ORDER BY l.sort_order, l.name",
        [$me]
    );

    api_ok(array_map('api_admin_library_row', $rows), [
        'kinds' => ['private' => 'Private', 'shared' => 'Shared'],
    ]);
}

/** Name, description, colour, kind and order - checked once, for create and change alike. */
function api_library_input(array $in, bool $partial): array
{
    $errors = [];
    $out    = [];

    if (!$partial || array_key_exists('name', $in)) {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'A library needs a name.';
        } elseif (mb_strlen($name) > 160) {
            $errors['name'] = 'That name is too long; 160 characters at most.';
        } else {
            $out['name'] = $name;
        }
    }

    if (array_key_exists('description', $in)) {
        $out['description'] = mb_substr(trim((string) $in['description']), 0, 500) ?: null;
    }

    if (array_key_exists('kind', $in)) {
        $kind = (string) $in['kind'];
        if (!in_array($kind, ['private', 'shared'], true)) {
            $errors['kind'] = 'Must be private or shared.';
        } else {
            $out['kind'] = $kind;
        }
    }

    if (array_key_exists('color', $in)) {
        $color = trim((string) $in['color']);
        // Six hex digits behind a hash, which is what the column holds and what
        // every screen expects to read back.
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            $errors['color'] = 'A colour looks like #cba6f7.';
        } else {
            $out['accent_color'] = strtolower($color);
        }
    }

    if (array_key_exists('sort_order', $in)) {
        $out['sort_order'] = (int) $in['sort_order'];
    }

    if ($errors !== []) {
        api_error('validation_failed', 'Some fields need attention.', 422, $errors);
    }
    return $out;
}

function api_admin_libraries_create(): void
{
    [$me] = api_require_admin();

    $in     = api_body();
    $fields = api_library_input($in, false);

    $fields['slug']         = unique_slug('libraries', slugify((string) $fields['name']));
    $fields['owner_id']     = (int) $me['id'];
    $fields['kind']       ??= 'private';
    $fields['accent_color'] ??= '#cba6f7';
    $fields['is_active']    = 1;

    $id = (int) insert_row('libraries', $fields);

    // The same membership row the web writes and the user-level route now
    // writes. owner_id says whose it is; library_members decides who may see it,
    // and without this an administrator could make a library and not find it in
    // their own picker.
    q('INSERT IGNORE INTO library_members (library_id, user_id, access, granted_by)
       VALUES (?, ?, ?, ?)',
      [$id, (int) $me['id'], ACCESS_OWNER, (int) $me['id']]);
    $GLOBALS['__membership_cache'] = [];

    log_security('library.created',
                 sprintf('Created library "%s"', $fields['name']),
                 LOG_NOTICE, ['subject_type' => 'library', 'subject_id' => $id]);

    $row = one("SELECT l.*, u.display_name AS owner_name, 0 AS n
                  FROM libraries l LEFT JOIN users u ON u.id = l.owner_id
                 WHERE l.id = ?", [$id]);
    api_ok(api_admin_library_row($row), null, 201);
}

function api_admin_libraries_update(int $id): void
{
    api_require_admin();

    $library = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($library === null) {
        api_error('not_found', 'No library with that id.', 404);
    }

    $in     = api_body();
    $fields = api_library_input($in, true);

    if (array_key_exists('is_active', $in)) {
        // A closed library keeps everything in it and stops appearing. Not a
        // delete, and not offered as one.
        $fields['is_active'] = (bool) $in['is_active'] ? 1 : 0;
    }

    if ($fields === []) {
        api_error('validation_failed', 'Nothing to change.', 422);
    }

    // The slug follows the name, because a library renamed from "Loft" to
    // "Basement" with /libraries/loft in the address bar is a small lie that
    // outlives everybody who remembers it.
    if (isset($fields['name']) && $fields['name'] !== $library['name']) {
        $fields['slug'] = unique_slug('libraries', slugify((string) $fields['name']), $id);
    }

    update_row('libraries', $id, $fields);

    log_security('library.changed',
                 sprintf('Changed library "%s": %s', $library['name'],
                         implode(', ', array_keys($fields))),
                 LOG_NOTICE, ['subject_type' => 'library', 'subject_id' => $id]);

    $row = one("SELECT l.*, u.display_name AS owner_name,
                       (SELECT COUNT(*) FROM items i
                         WHERE i.library_id = l.id AND i.deleted_at IS NULL) AS n
                  FROM libraries l LEFT JOIN users u ON u.id = l.owner_id
                 WHERE l.id = ?", [$id]);
    api_ok(api_admin_library_row($row));
}

/**
 * Delete an empty one.
 *
 * Only empty. A library holds entries, photographs, a filing tree and its own
 * copy of the vocabulary, and removing all of that on one request from a phone
 * is not a thing this endpoint will do - emptying it first is deliberate work
 * that leaves a trail. The last library goes nowhere either: an instance with
 * none has nowhere to put the next thing somebody adds.
 */
function api_admin_libraries_delete(int $id): void
{
    api_require_admin();

    $library = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($library === null) {
        api_error('not_found', 'No library with that id.', 404);
    }

    // A personal library cannot be deleted, by anybody.
    //
    // api_libraries_delete() has refused this from the owner's own side
    // throughout, and this path had no such check - so the one account with the
    // most reach was the one that could destroy somebody's default shelf and
    // leave them with nowhere for their things to live. An administrator can
    // still disable it, which is what "stop this being used" actually means.
    if ((int) ($library['is_personal'] ?? 0) === 1) {
        api_error('validation_failed',
                  'That is somebody\'s personal library. Every account has exactly one and it '
                . 'cannot be deleted - disable it instead.', 422);
    }

    $held = (int) scalar('SELECT COUNT(*) FROM items WHERE library_id = ? AND deleted_at IS NULL',
                         [$id]);
    if ($held > 0) {
        api_error('validation_failed',
                  sprintf('That library still holds %d %s. Empty it first.',
                          $held, $held === 1 ? 'entry' : 'entries'), 422);
    }

    if ((int) scalar('SELECT COUNT(*) FROM libraries') <= 1) {
        api_error('validation_failed',
                  'That is the only library. An instance needs somewhere to put things.', 422);
    }

    q('DELETE FROM libraries WHERE id = ?', [$id]);

    log_security('library.deleted',
                 sprintf('Deleted the empty library "%s"', $library['name']),
                 LOG_WARNING, ['subject_type' => 'library', 'subject_id' => $id]);

    api_no_content();
}

/**
 * A new avatar, uploaded.
 *
 * Multipart, field `avatar`, because store_user_avatar() reads $_FILES and that
 * is the same path the web form takes - one place decides what a valid picture
 * is, what it is resized to and what it is called.
 *
 * No crop. The web form lets somebody drag a square over the picture before it
 * is cut, which needs a picture on screen to drag over; a client that wants that
 * can crop before uploading, and one that does not gets the middle, which is
 * what happens on the web without JavaScript too.
 */
function api_profile_avatar_upload(): void
{
    [$user] = api_require_auth();

    if (!isset($_FILES['avatar'])) {
        api_error('validation_failed',
                  'Send the picture as multipart, in a field called "avatar".', 422);
    }

    [$name, $error] = store_user_avatar((int) $user['id'], 'avatar');
    if ($error !== null) {
        api_error('upload_failed', $error, 422);
    }
    if ($name === null) {
        api_error('validation_failed', 'No picture arrived.', 422);
    }

    // Whether it is in use or waiting, said plainly rather than left for the
    // client to work out from a picture that did not change.
    $pending = avatar_approval_required($user);
    log_security('profile.avatar',
                 $pending ? 'Avatar submitted for approval' : 'Avatar changed', LOG_INFO,
                 ['subject_type' => 'user', 'subject_id' => (int) $user['id']]);

    api_ok(
        ['avatar' => absolute_url(image_url($name, 'thumb'))],
        $pending ? [
            'pending' => true,
            'message' => 'An administrator has to approve it before it is shown. '
                       . 'The picture already in use stays up until then.',
        ] : null
    );
}

/** Back to initials on a coloured circle, which is what no avatar looks like. */
function api_profile_avatar_delete(): void
{
    [$user] = api_require_auth();
    delete_user_avatar((int) $user['id']);
    log_security('profile.avatar', 'Avatar removed', LOG_INFO,
                 ['subject_type' => 'user', 'subject_id' => (int) $user['id']]);
    api_no_content();
}

/**
 * Make an account.
 *
 * Through create_user(), which is the same route the installer and
 * bin/create-user.php take: it checks the address is not already in use, hashes
 * the password, and gives the account a personal library - the one shelf
 * everybody is promised, and without which a new account cannot catalogue
 * anything at all.
 *
 * The username is checked here because create_user() does not: it trusts its
 * callers, and until now its callers were an installer and a command line.
 */
function api_users_create(): void
{
    api_require_admin();

    $in     = api_body();
    $errors = [];

    $username = trim((string) ($in['username'] ?? ''));
    if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
        $errors['username'] = 'Three to sixty characters: letters, digits, dot, dash '
                            . 'or underscore.';
    } elseif (one('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
        $errors['username'] = 'That username is taken.';
    }

    $password = (string) ($in['password'] ?? '');
    if (mb_strlen($password) < 10) {
        $errors['password'] = 'At least ten characters.';
    }

    $email = trim((string) ($in['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'An address is required.';
    }

    $role = (string) ($in['role'] ?? 'user');
    if (!in_array($role, ['admin', 'user'], true)) {
        $errors['role'] = 'Must be admin or user.';
    }

    if ($errors !== []) {
        api_error('validation_failed', 'Some fields need attention.', 422, $errors);
    }

    try {
        $id = (int) create_user($username, $password,
                                trim((string) ($in['display_name'] ?? '')) ?: $username,
                                $role, $email);
    } catch (InvalidArgumentException $e) {
        // Its own complaints, which are about the address rather than the
        // username - worth attaching to the field they belong to.
        api_error('validation_failed', 'Some fields need attention.', 422,
                  ['email' => $e->getMessage()]);
    }

    log_security('user.created',
                 sprintf('Created account "%s" as %s', $username, $role),
                 LOG_NOTICE, ['subject_type' => 'user', 'subject_id' => $id]);

    $row = one("SELECT u.*, am.name AS auth_name FROM users u
                  LEFT JOIN auth_methods am ON am.id = u.auth_method_id
                 WHERE u.id = ?", [$id]);
    api_ok(api_user_row($row), null, 201);
}

/**
 * Everything /status and /status.json deliberately do not say.
 *
 * Those two exist to answer one public question - is this instance up - and
 * their own comments explain why disclosing more there would make them a
 * reconnaissance endpoint. An administrator asking the same question from
 * inside a session is a different situation: they already know what this
 * instance is, so a real version number and real service detail answers a
 * question rather than handing a stranger a fingerprint.
 *
 * Composed from what already existed rather than re-derived: update_status()
 * already computed the version/migration/schema answer and had no caller
 * anywhere in this codebase before this one.
 */
function api_admin_status(): void
{
    api_require_admin();

    $update = update_status();

    $providers = array_map(static function (array $p): array {
        // Never params: that column is where API keys live.
        return [
            'type'         => $p['type'],
            'name'         => $p['name'],
            'is_enabled'   => (bool) $p['is_enabled'],
            'last_used_at' => api_datetime($p['last_used_at']),
            'last_error'   => $p['last_error'],
        ];
    }, all('SELECT type, name, is_enabled, last_used_at, last_error
              FROM metadata_providers ORDER BY priority, name'));

    api_ok([
        'app' => [
            'version'     => $update['app_version'],
            'released'    => $update['released'],
            'api_version' => API_VERSION,
            'php_version' => PHP_VERSION,
        ],
        'database' => [
            'migrations_applied' => count($update['applied']),
            'migrations_pending' => $update['pending'],
            'migrations_total'   => $update['migrations_total'],
            'schema_ok'          => $update['structure']['ok'],
            'needs_update'       => $update['needs_update'],
        ],
        'metadata_providers' => $providers,
        'checked_at' => api_datetime(gmdate('Y-m-d H:i:s')),
    ]);
}
