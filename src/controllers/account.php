<?php
declare(strict_types=1);

function auth_login_form(): void
{
    if (user_count() === 0) {
        redirect('/setup');
    }
    if (is_logged_in()) {
        redirect('/');
    }
    render('auth/login', ['pageTitle' => 'Sign in', 'bare' => true]);
}

function auth_login(): void
{
    csrf_verify();
    $username = (string) input('username', '');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        flash('error', 'Enter both a username and a password.');
        redirect('/login');
    }

    // Checked before any password work, so being locked out cannot be told
    // apart from a wrong password by how long the response takes.
    [$allowed, $wait, $why] = throttle_check($username);
    if (!$allowed) {
        log_auth_attempt($username, null, false, 'throttled: ' . (string) $why);
        flash('error', throttle_message($wait));
        redirect('/login');
    }

    $reason = null;
    $via    = null;
    if (!attempt_login($username, $password, $reason, $via)) {
        log_security('auth.signin.failed',
            sprintf('Sign-in refused for "%s"%s', $username,
                    $reason === 'unverified' ? ': address not confirmed' : ': wrong credentials'),
            LOG_WARNING, [
                'username'    => $username,
                'reason'      => $reason ?? 'credentials',
                // Which directory was asked, where one was: a wrong password and an
                // unreachable domain controller look identical without it.
                'auth_method' => $via === null ? 'local' : (string) ($via['name'] ?? 'directory'),
            ]);
        if ($reason === 'unverified') {
            // The credentials were right, so saying "wrong password" would send
            // somebody hunting for a problem they do not have.
            flash('error', 'Confirm your email address before signing in. '
                         . 'Check your inbox, or ask for another link below.');
            redirect('/login', ['unverified' => $username]);
        }
        flash('error', 'That username and password do not match.');
        redirect('/login');
    }
    // How, not just that.
    //
    // "Signed in username=someone" does not say whether a password was checked here or
    // a domain controller was asked - which is the first question when an account you
    // did not create appears, and the difference between a local breach and a directory
    // one. The method's own name is recorded too, since an instance can have several.
    $viaType = $via === null ? 'local' : (string) ($via['type'] ?? 'local');
    $viaName = $via === null ? 'local password' : (string) ($via['name'] ?? $viaType);
    $how     = $viaType === 'local'
        ? 'local password'
        : sprintf('%s directory "%s"', strtoupper($viaType), $viaName);

    // The memo says "nobody" until this is cleared.
    //
    // current_user() resolves once per request, and this request was resolved before the
    // session existed - so the sign-in was logged with an empty Who, which is the one
    // event where knowing who it was matters most. The parameter for this already
    // existed and was never called.
    $me = current_user(true);

    log_security('auth.signin', sprintf('Signed in via %s', $how), LOG_INFO, [
        // What was typed, and what it resolved to. Signing in as
        // "frossmant@example.com" against an account called "frossmant" is worth being
        // able to see, and a directory login is exactly where those differ.
        'username'    => $username,
        'account'     => $me === null ? null : (string) $me['username'],
        'auth_type'   => $viaType,
        'auth_method' => $viaName,
        // Which directory answered, where one did. Two domain controllers behaving
        // differently is otherwise invisible.
        // Only where a directory was actually asked. The local method is a row like any
        // other, so "directory: Local database" was a contradiction in terms.
        'directory'   => $viaType === 'local'
            ? null : (string) ($via['host'] ?? $via['name'] ?? ''),
        'role'        => $me === null ? null : (string) $me['role'],
    ]);
    flash('ok', 'Signed in.');
    $next = input('next');
    redirect($next && str_starts_with($next, '/') ? $next : '/');
}

function auth_logout(): void
{
    csrf_verify();
    logout();
    start_session();
    flash('ok', 'Signed out.');
    redirect('/');
}

function auth_setup_form(): void
{
    if (user_count() > 0) {
        redirect('/login');
    }
    render('auth/setup', ['pageTitle' => 'First run', 'bare' => true]);
}

function auth_setup(): void
{
    csrf_verify();
    if (user_count() > 0) {
        redirect('/login');
    }
    $username = (string) input('username', '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');
    $email    = trim((string) input('email', ''));

    $errors = [];
    if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
        $errors[] = 'Username can use letters, numbers, dot, dash and underscore, 3 to 64 characters.';
    }
    if (strlen($password) < 10) {
        $errors[] = 'Use a password of at least 10 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }
    // create_user() has required an address since verification was added, and it
    // enforces that by throwing. This path passed no address at all and did not
    // catch, so every first-run POST /setup died as an uncaught
    // InvalidArgumentException - a 500 on the one page a new install has to get
    // through. Check it here so the person is told, not shown a stack trace.
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'An email address is required, and that one does not look like one.';
    }
    if ($errors !== []) {
        foreach ($errors as $err) {
            flash('error', $err);
        }
        redirect('/setup');
    }

    try {
        $id = create_user($username, $password, (string) input('display_name', $username), 'admin', $email);
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
        redirect('/setup');
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    flash('ok', 'Administrator account created. Start by adding a library, then your first title.');
    redirect('/');
}

// --- Account management -----------------------------------------------------

function users_index(): void
{
    require_admin();
    $editId  = input_int('edit');
    $editing = $editId === null ? null : one('SELECT * FROM users WHERE id = ?', [$editId]);

    render('auth/users', [
        // Outstanding invitations, so somebody can see who is expected and take
        // one back. Only while the instance is in that mode - a list of things
        // that cannot be created is furniture.
        'invites' => registration_mode() === 'invite'
            ? all('SELECT * FROM invites WHERE used_at IS NULL ORDER BY created_at DESC')
            : [],
        'inviteMode' => registration_mode() === 'invite',
        // Which single row, if any, is currently editable.
        //
        // One at a time, and only when asked for. The warning on this page is that
        // "a row of selects and checkboxes per account is a lot of ways to change
        // the wrong person by one click" - and it is right, so there is never a row
        // of them: there is exactly one row of controls, belonging to the account
        // whose Change button was pressed, and every other row stays text.
        'rowId'     => input_int('row'),
        'pageTitle' => 'User management',
        // The directory each account signs in through, named rather than implied by an
        // id. "Status" used to mean three different things at once - blocked, directory,
        // unconfirmed - so which one you were looking at depended on which was true
        // first.
        'users'     => all(
            "SELECT u.*, am.name AS auth_name, am.type AS auth_type,
                    (SELECT COUNT(*) FROM users x WHERE x.role = 'admin' AND x.is_active = 1) AS admin_count
               FROM users u
          LEFT JOIN auth_methods am ON am.id = u.auth_method_id
           ORDER BY u.username"
        ),
        'editing'   => $editing,
    ]);
}

function users_save(): void
{
    require_admin();
    csrf_verify();

    $id     = input_int('id');
    $action = input('action', 'save');
    $me     = current_user();

    // Sending somebody an invitation.
    //
    // Here rather than on the settings screen because this is where accounts
    // are, and an invitation is an account that has not happened yet.
    if ($action === 'invite') {
        $email = trim((string) input('invite_email', ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'That does not look like an email address.');
            redirect('/manage/users');
        }
        if (!mail_enabled()) {
            flash('error', 'Invitations go out by email, and the SMTP relay is not working.');
            redirect('/manage/users');
        }
        if (one('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            flash('error', 'There is already an account on that address.');
            redirect('/manage/users');
        }

        [$inviteId, $plain] = invite_create($email, (int) $me['id']);
        $link = invite_url($plain);
        [$sent, $why] = smtp_send($email,
            'An invitation to ' . (string) setting('instance_name', 'RetroHive'),
            "You have been invited to keep a catalogue here.\n\n"
            . $link . "\n\n"
            . "The link works once and expires in a fortnight. If you were not\n"
            . "expecting this, ignore it and nothing happens.\n");

        if (!$sent) {
            // The invitation is destroyed rather than left lying about: an
            // invitation nobody received is a live credential with no owner.
            q('DELETE FROM invites WHERE id = ?', [$inviteId]);
            flash('error', 'The invitation was not sent, so it has been cancelled: ' . $why);
            redirect('/manage/users');
        }

        log_security('invite.sent', 'invitation sent to ' . $email, LOG_NOTICE,
                     ['invite' => $inviteId, 'by' => (int) $me['id']]);
        flash('ok', 'Invitation sent to ' . $email . '. It works once and expires in a fortnight.');
        redirect('/manage/users');
    }

    if ($action === 'invite_revoke') {
        $inviteId = input_int('invite_id');
        if ($inviteId !== null) {
            q('DELETE FROM invites WHERE id = ? AND used_at IS NULL', [$inviteId]);
            flash('ok', 'That invitation will not work now.');
        }
        redirect('/manage/users');
    }

    // An administrator vouching for somebody by hand. The way out when a relay
    // breaks, an address is unreachable, or somebody simply cannot find the
    // email - without which turning verification on can strand people with no
    // way back in.
    // Saving one row.
    //
    // Deliberately not the full editor with fewer fields. It changes the three
    // things an administrator actually reaches for - role, whether the account is
    // enabled, and whether its address is taken as confirmed - and refuses each of
    // them in the cases that would lock somebody out, including the administrator
    // doing it.
    if ($action === 'rowsave' && $id !== null) {
        $target = one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($target === null) {
            flash('error', 'No such account.');
            redirect('/manage/users');
        }

        $self       = (int) $id === (int) $me['id'];
        $wantRole   = input('role', (string) $target['role']) === 'admin' ? 'admin' : 'user';
        $wantActive = input_bool('is_active') === 1 ? 1 : 0;
        $wantVerified = input_bool('verified') === 1;
        $viaDirectory = false;
        if (!empty($target['auth_method_id'])) {
            $viaDirectory = (string) (scalar('SELECT type FROM auth_methods WHERE id = ?',
                                             [(int) $target['auth_method_id']]) ?? 'local') !== 'local';
        }

        // Your own role and your own access are not yours to change from here.
        // Not paternalism: an administrator who demotes or disables themselves has
        // no way back, and the mistake is only visible once it cannot be undone.
        if ($self && $wantRole !== (string) $target['role']) {
            flash('error', 'Changing your own role here would leave you unable to change it back. '
                . 'Ask another administrator.');
            redirect('/manage/users', ['row' => $id]);
        }
        if ($self && $wantActive !== 1) {
            flash('error', 'Disabling your own account would lock you out of the instance.');
            redirect('/manage/users', ['row' => $id]);
        }

        // The last way in stays open. Demoting or disabling the only administrator
        // who can sign in leaves an instance nobody can administer, and nothing
        // else on this page can put that right.
        $adminsLeft = (int) scalar(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?", [$id]);
        $wasAdmin   = (string) $target['role'] === 'admin' && (int) $target['is_active'] === 1;
        if ($wasAdmin && $adminsLeft === 0 && ($wantRole !== 'admin' || $wantActive !== 1)) {
            flash('error', sprintf('%s is the only administrator who can sign in. Make another '
                . 'account an administrator first.', (string) $target['username']));
            redirect('/manage/users', ['row' => $id]);
        }

        $changes = [];
        $data    = [];
        if ($wantRole !== (string) $target['role']) {
            $data['role'] = $wantRole;
            $changes[] = 'role ' . $target['role'] . ' → ' . $wantRole;
        }
        if ($wantActive !== (int) $target['is_active']) {
            $data['is_active'] = $wantActive;
            $changes[] = $wantActive === 1 ? 'enabled' : 'disabled';
        }
        // Confirmation is not asked of directory accounts, so it is not offered
        // for them either - the directory vouches for its own people.
        if (!$viaDirectory) {
            $isVerified = ($target['email_verified_at'] ?? null) !== null;
            if ($wantVerified !== $isVerified) {
                if ($self && !$wantVerified) {
                    flash('error', 'Marking your own account unverified would lock you out.');
                    redirect('/manage/users', ['row' => $id]);
                }
                $data['email_verified_at'] = $wantVerified ? date('Y-m-d H:i:s') : null;
                $changes[] = $wantVerified ? 'verified' : 'unverified';
            }
        }

        if ($data === []) {
            flash('ok', 'Nothing to change.');
            redirect('/manage/users');
        }

        update_row('users', $id, $data);

        // Logged with what actually changed, because "somebody became an
        // administrator" is the line worth being able to find afterwards.
        log_security(
            isset($data['role']) && $data['role'] === 'admin' ? 'user.promoted' : 'user.updated',
            sprintf('Account "%s": %s', (string) $target['username'], implode(', ', $changes)),
            isset($data['role']) ? LOG_WARNING : LOG_NOTICE,
            ['subject_type' => 'user', 'subject_id' => $id]);

        flash('ok', sprintf('%s — %s.', (string) $target['username'], implode(', ', $changes)));
        redirect('/manage/users');
    }

    if ($action === 'verify' && $id !== null) {
        force_verify_email($id);
        log_security('user.verified.manually',
            'Address marked confirmed by an administrator', LOG_NOTICE,
            ['subject_type' => 'user', 'subject_id' => $id]);
        flash('ok', 'Marked as verified. They can sign in now.');
        redirect('/manage/users');
    }

    if ($action === 'unverify' && $id !== null) {
        if ($id === (int) $me['id']) {
            flash('error', 'Marking your own account unverified would lock you out.');
            redirect('/manage/users');
        }
        q('UPDATE users SET email_verified_at = NULL WHERE id = ?', [$id]);
        flash('ok', 'Marked as unverified.');
        redirect('/manage/users');
    }

    if ($action === 'resend' && $id !== null) {
        [$ok, $message] = send_verification_email($id);
        flash($ok ? 'ok' : 'error', $ok ? 'Confirmation link sent. ' . $message : $message);
        redirect('/manage/users');
    }

    if ($action === 'delete' && $id !== null) {
        $target = one('SELECT id, username, role, is_active FROM users WHERE id = ?', [$id]);

        // The last way in is not deletable.
        //
        // Deleting the only enabled administrator leaves an instance nobody can
        // configure - no way to add an account, fix authentication or restore anything,
        // short of the command line and the database. Blocking one is reversible from
        // the interface; removing it is not, and the interface should not offer a door
        // that locks behind you.
        $adminsLeft = (int) scalar(
            "SELECT COUNT(*) FROM users
              WHERE role = 'admin' AND is_active = 1 AND id <> ?", [$id]
        );

        if ($id === (int) $me['id']) {
            flash('error', 'You cannot delete the account you are signed in with.');
        } elseif ($target === null) {
            flash('error', 'No such account.');
        } elseif ((string) $target['role'] === 'admin' && $adminsLeft === 0) {
            flash('error', sprintf(
                '%s is the only administrator who can sign in, so it cannot be removed. '
                . 'Make another account an administrator first, or disable this one instead.',
                (string) $target['username']
            ));
        } else {
            delete_row('users', $id);
            log_security('account.deleted',
                sprintf('Account "%s" removed', (string) $target['username']),
                LOG_WARNING, ['username' => (string) $target['username'],
                              'role'     => (string) $target['role']]);
            flash('ok', 'Account removed.');
        }
        redirect('/manage/users');
    }

    $username = (string) input('username', '');
    $password = (string) ($_POST['password'] ?? '');
    // Two values, matching the column. This used to offer the pre-refactor
    // trio, so 'viewer' was written into an ENUM('admin','user') and strict
    // mode refused the insert outright - creating any non-admin account failed
    // with "Data truncated for column 'role'".
    $role     = input('role', 'user') === 'admin' ? 'admin' : 'user';

    if ($id === null) {
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
            flash('error', 'Username can use letters, numbers, dot, dash and underscore, 3 to 64 characters.');
            redirect('/manage/users');
        }
        if (strlen($password) < 10) {
            flash('error', 'Use a password of at least 10 characters.');
            redirect('/manage/users');
        }
        if (one('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
            flash('error', 'That username is taken.');
            redirect('/manage/users');
        }
        try {
            $newId = create_user($username, $password, (string) input('display_name', $username),
                                 $role, (string) input('email', ''));
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/manage/users');
        }

        // Ask them to confirm it straight away if the instance requires it.
        // Failing to send is worth saying out loud rather than leaving somebody
        // to wonder why they cannot sign in.
        if (email_verification_required()) {
            [$sent, $why] = send_verification_email((int) $newId);
            flash($sent ? 'ok' : 'error', $sent
                ? 'Account created, and a confirmation link sent.'
                : 'Account created, but the confirmation could not be sent: ' . $why
                  . ' You can mark them verified by hand.');
            redirect('/manage/users');
        }

        log_security('user.created', 'Account "' . $username . '" created', LOG_NOTICE,
                     ['subject_type' => 'user', 'subject_id' => $newId, 'role' => $role]);
        flash('ok', 'Account created.');
        redirect('/manage/users');
    }

    $data = [
        'display_name' => nullify(input('display_name')),
        'role'         => $role,
        'is_active'    => input_bool('is_active'),
        // An administrator's switch, above that person's own preferences: for
        // an address that bounces and somebody nobody can reach to ask.
        'mail_enabled' => empty($_POST['mail_enabled']) ? 0 : 1,
    ];

    $email = trim((string) input('email', ''));
    if ($email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'That does not look like an email address.');
            redirect('/manage/users');
        }
        $clash = one('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id]);
        if ($clash !== null) {
            flash('error', 'Another account already uses that address.');
            redirect('/manage/users');
        }
        $data['email'] = $email;
    }
    // Everything wrong with the form at once, each complaint against the field
    // it belongs to. Reporting the first problem and stopping means finding out
    // about the second one on the next attempt.
    $errors   = [];
    $existing = $id === null ? null : one('SELECT * FROM users WHERE id = ?', [$id]);
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if ($password !== '') {
        if (strlen($password) < 10) {
            $errors['password'] = 'At least 10 characters.';
        } elseif ($confirm === '') {
            $errors['password_confirm'] = 'Type it again to be sure.';
        } elseif ($password !== $confirm) {
            $errors['password_confirm'] = 'The two do not match.';
        }
    }

    $username = trim((string) input('username', ''));
    if ($username !== '' && $username !== ($existing['username'] ?? '')) {
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
            $errors['username'] = 'Three to 64 characters: letters, numbers, dot, dash, underscore.';
        } elseif (one('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id]) !== null) {
            $errors['username'] = 'That username is taken.';
        } else {
            $data['username'] = $username;
        }
    }

    if ($errors !== []) {
        form_failed('/manage/users', $errors, ['edit' => $id]);
    }

    if ($password !== '') {
        $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }
    if ($id === (int) $me['id']) {
        // Never let an admin lock themselves out.
        $data['role']      = 'admin';
        $data['is_active'] = 1;
    }
    // What actually changed about who this account is.
    //
    // Role and whether it may sign in are the two that decide access, so they are
    // recorded by name rather than as "account updated" - a display name and an
    // administrator promotion should not read the same in a log. The password hash is
    // never mentioned, only that one was set.
    $was = one('SELECT username, role, is_active FROM users WHERE id = ?', [$id]);
    update_row('users', $id, $data);

    $changes = [];
    if (isset($data['role']) && (string) $data['role'] !== (string) ($was['role'] ?? '')) {
        $changes[] = sprintf('role %s to %s', (string) $was['role'], (string) $data['role']);
    }
    if (isset($data['is_active']) && (int) $data['is_active'] !== (int) ($was['is_active'] ?? 1)) {
        $changes[] = (int) $data['is_active'] === 1 ? 'enabled' : 'disabled';
    }
    if (!empty($data['password_hash'])) {
        $changes[] = 'password set';
    }
    if ($changes !== []) {
        log_security('user.changed',
            sprintf('Account "%s": %s', (string) ($was['username'] ?? $id), implode(', ', $changes)),
            LOG_WARNING, ['username' => (string) ($was['username'] ?? ''), 'changed' => $changes]);
    }

    flash('ok', 'Account updated.');
    redirect('/manage/users');
}

// --- API tokens -------------------------------------------------------------

function tokens_index(): void
{
    require_edit();
    $user = current_user();
    render('auth/tokens', [
        'pageTitle'  => 'App access',
        'tokens'     => all(
            'SELECT * FROM api_tokens WHERE user_id = ? ORDER BY revoked_at IS NOT NULL, created_at DESC',
            [(int) $user['id']]
        ),
        'freshToken' => $_SESSION['fresh_token'] ?? null,
    ]);
    unset($_SESSION['fresh_token']);
}

function tokens_save(): void
{
    require_edit();
    csrf_verify();
    $user = current_user();

    $action = input('action', 'create');
    $id     = input_int('id');

    if ($action === 'revoke' && $id !== null) {
        $token = one('SELECT id FROM api_tokens WHERE id = ? AND user_id = ?', [$id, (int) $user['id']]);
        if ($token === null) {
            flash('error', 'No such token on this account.');
        } else {
            q('UPDATE api_tokens SET revoked_at = NOW() WHERE id = ?', [$id]);
            flash('ok', 'Token revoked. That device loses access on its next request.');
        }
        redirect('/manage/tokens');
    }

    if ($action === 'delete' && $id !== null) {
        q('DELETE FROM api_tokens WHERE id = ? AND user_id = ?', [$id, (int) $user['id']]);
        flash('ok', 'Token removed from the list.');
        redirect('/manage/tokens');
    }

    $name = (string) input('name', '');
    if (trim($name) === '') {
        form_failed('/manage/tokens', ['name' => 'Give the token a name so you can tell devices apart.']);
    }

    $days    = input_int('expires_days');
    $expires = ($days !== null && $days > 0) ? date('Y-m-d H:i:s', time() + $days * 86400) : null;

    [, $plain] = create_api_token(
        (int) $user['id'],
        $name,
        input('scope') === 'read' ? 'read' : 'write',
        input('platform'),
        $expires
    );

    // Shown once, on the next page load, then dropped.
    $_SESSION['fresh_token'] = $plain;
    flash('ok', 'Token created. Copy it now, it is not shown again.');
    redirect('/manage/tokens');
}

// --- Per-library access -----------------------------------------------------

function access_index(): void
{
    require_admin();

    $userId = input_int('user');
    $users  = all('SELECT id, username, display_name, avatar_filename, role, is_active FROM users ORDER BY username');
    $subject = null;
    if ($userId !== null) {
        $subject = one('SELECT id, username, display_name, avatar_filename, role FROM users WHERE id = ?', [$userId]);
    }

    render('auth/access', [
        'pageTitle' => 'Library access',
        'users'     => $users,
        'subject'   => $subject,
        'libraries' => all('SELECT * FROM libraries ORDER BY name'),
        'grants'    => $subject === null ? [] : user_grants((int) $subject['id']),
    ]);
}

/** [libraryId => access] for one account, read from the membership table. */
function user_grants(int $userId): array
{
    $out = [];
    foreach (all('SELECT library_id, access FROM library_members WHERE user_id = ?', [$userId]) as $row) {
        $out[(int) $row['library_id']] = (string) $row['access'];
    }
    return $out;
}

function access_save(): void
{
    require_admin();
    csrf_verify();

    $userId = input_int('user_id');
    if ($userId === null) {
        flash('error', 'Pick an account first.');
        redirect('/manage/access');
    }
    $subject = one('SELECT id, username, role FROM users WHERE id = ?', [$userId]);
    if ($subject === null) {
        flash('error', 'No such account.');
        redirect('/manage/access');
    }

    $me = current_user();

    // Rewritten wholesale from the form. Membership is the whole of access:
    // there is no global default to fall back to, and 'none' simply means the
    // row is absent.
    //
    // This used to write to a table called library_access keyed on platform_id.
    // No such table exists and the platform was never the boundary, so saving
    // this page threw before it changed anything.
    $submitted = $_POST['access'] ?? [];
    $keep      = [];

    if (is_array($submitted)) {
        foreach ($submitted as $libraryId => $level) {
            $libraryId = (int) $libraryId;
            $level     = is_string($level) ? $level : ACCESS_NONE;

            // Owner is not assignable here either. Ownership is one column on the
            // library and it changes by being offered and accepted; writing an owner
            // membership row from a bulk form would leave libraries.owner_id naming one
            // person and the membership another.
            if (!in_array($level, [ACCESS_VIEWER, ACCESS_CONTRIBUTOR, ACCESS_EDITOR, ACCESS_CURATOR, ACCESS_ADMIN], true)) {
                continue;   // 'none', 'owner', or nonsense: no row at all
            }
            if (one('SELECT id FROM libraries WHERE id = ?', [$libraryId]) === null) {
                continue;
            }

            q('INSERT INTO library_members (library_id, user_id, access, granted_by, note)
               VALUES (?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE access = VALUES(access), granted_by = VALUES(granted_by)',
              [$libraryId, $userId, $level, $me === null ? null : (int) $me['id'],
               'Set from the access page']);
            $keep[] = $libraryId;
        }
    }

    // Anything not resubmitted has been revoked.
    if ($keep === []) {
        q('DELETE FROM library_members WHERE user_id = ?', [$userId]);
    } else {
        $in = implode(',', array_fill(0, count($keep), '?'));
        q("DELETE FROM library_members WHERE user_id = ? AND library_id NOT IN ($in)",
          array_merge([$userId], $keep));
    }

    // A personal library keeps its owner regardless, or the account loses the
    // one place it is guaranteed to be able to put things.
    q("INSERT IGNORE INTO library_members (library_id, user_id, access, note)
       SELECT id, owner_id, ?, 'Personal library'
       FROM libraries WHERE is_personal = 1 AND owner_id = ?", [ACCESS_OWNER, $userId]);

    $GLOBALS['__membership_cache'] = [];
    flash('ok', 'Library access updated for ' . $subject['username'] . '.');
    redirect('/manage/access', ['user' => $userId]);
}

// --- Authentication methods -------------------------------------------------

function auth_methods_index(): void
{
    require_admin();
    $editId  = input_int('edit');
    $editing = $editId === null ? null : one('SELECT * FROM auth_methods WHERE id = ?', [$editId]);

    render('auth/methods', [
        'pageTitle' => 'Authentication',
        'methods'   => all('SELECT m.*, (SELECT COUNT(*) FROM users u WHERE u.auth_method_id = m.id) AS user_count
                            FROM auth_methods m ORDER BY m.sort_order, m.id'),
        'editing'   => $editing,
        // After a test the form redisplays what was on screen, not what is
        // stored, so a failed test does not throw away the typing.
        'params'    => $_SESSION['ldap_draft']['params']
                       ?? ($editing === null ? ldap_default_params('ldap') : ldap_params($editing)),
        'draft'     => $_SESSION['ldap_draft'] ?? null,
        'groupMaps' => $editing === null ? [] : all(
            'SELECT * FROM auth_group_map WHERE auth_method_id = ? ORDER BY priority, id',
            [(int) $editing['id']]
        ),
        // Group mappings grant access to libraries, so that is what the form
        // needs to offer.
        'libraries'  => all('SELECT * FROM libraries ORDER BY name'),
        'groupGrants' => $editing === null ? [] : all(
            'SELECT a.* FROM auth_group_library_access a
             JOIN auth_group_map m ON m.id = a.group_map_id
             WHERE m.auth_method_id = ?',
            [(int) $editing['id']]
        ),
        'testResult' => $_SESSION['ldap_test'] ?? null,
        'inspection' => $_SESSION['ldap_inspect'] ?? null,
        // No sign-in log: that box moved to the security log, and the query
        // outlived the thing that displayed it - fifteen rows fetched and
        // discarded on every load of this page.
        'ldapReady'  => ldap_available(),
    ]);
    unset($_SESSION['ldap_test'], $_SESSION['ldap_draft'], $_SESSION['ldap_inspect']);
}

function auth_methods_save(): void
{
    require_admin();
    csrf_verify();

    $action = input('action', 'save');
    $id     = input_int('id');

    if ($action === 'delete' && $id !== null) {
        $m = one('SELECT * FROM auth_methods WHERE id = ?', [$id]);
        if ($m === null) {
            flash('error', 'No such method.');
        } elseif ((int) $m['is_protected'] === 1) {
            flash('error', 'The local database method cannot be removed.');
        } elseif ((int) scalar('SELECT COUNT(*) FROM users WHERE auth_method_id = ?', [$id]) > 0) {
            flash('error', 'Accounts still use that method. Move or remove them first.');
        } else {
            $gone = one('SELECT name, type FROM auth_methods WHERE id = ?', [$id]);
            delete_row('auth_methods', $id);
            log_security('auth.method.deleted',
                sprintf('Directory "%s" removed', (string) ($gone['name'] ?? $id)),
                LOG_WARNING, ['method' => (string) ($gone['name'] ?? ''),
                              'type'   => (string) ($gone['type'] ?? '')]);
            flash('ok', 'Authentication method removed.');
        }
        redirect('/manage/auth');
    }

    if ($action === 'toggle' && $id !== null) {
        $m = one('SELECT * FROM auth_methods WHERE id = ?', [$id]);
        if ($m !== null && (int) $m['is_protected'] === 1 && (int) $m['is_enabled'] === 1) {
            flash('error', 'The local database method cannot be disabled — it is the way back in.');
        } elseif ($m !== null) {
            $now = (int) $m['is_enabled'] === 1 ? 0 : 1;
            update_row('auth_methods', $id, ['is_enabled' => $now]);
            log_security($now ? 'auth.method.enabled' : 'auth.method.disabled',
                sprintf('Directory "%s" %s', (string) $m['name'], $now ? 'enabled' : 'disabled'),
                LOG_WARNING, ['method' => (string) $m['name'], 'type' => (string) $m['type']]);
            flash('ok', 'Method ' . ((int) $m['is_enabled'] === 1 ? 'disabled' : 'enabled') . '.');
        }
        redirect('/manage/auth');
    }

    // Group mapping sub-actions
    if ($action === 'map_add' && $id !== null) {
        $group = trim((string) input('group_name', ''));
        if ($group === '') {
            // (path, errors, query). Passed the other way round, the flash message
            // became the bare library id, the field never showed its error, and the
            // redirect dropped ?edit= while putting the message in the query string.
            form_failed('/manage/auth', ['group_name' => 'Give the directory group name or DN.'], ['edit' => $id]);
        }
        $mapId = insert_row('auth_group_map', [
            'auth_method_id' => $id,
            'group_name'     => mb_substr($group, 0, 512),
            'role'           => input('role', 'user') === 'admin' ? 'admin' : 'user',
            'default_access' => in_array(input('map_access', ACCESS_NONE), [ACCESS_NONE, ACCESS_VIEWER, ACCESS_CONTRIBUTOR, ACCESS_EDITOR, ACCESS_CURATOR, ACCESS_ADMIN], true)
                ? (string) input('map_access', ACCESS_NONE) : ACCESS_NONE,
            'priority'       => (int) (input_int('priority') ?? 100),
        ]);

        // Per-library grants. Keyed on library, because that is what the group
        // confers access to. Keyed on platform, as this was, the grant was
        // recorded against a machine and never consulted - and the table it was
        // written to did not exist at all, so adding a mapping simply threw.
        $perLibrary = $_POST['library'] ?? [];
        if (is_array($perLibrary)) {
            foreach ($perLibrary as $libraryId => $level) {
                if (!in_array($level, [ACCESS_VIEWER, ACCESS_CONTRIBUTOR, ACCESS_EDITOR, ACCESS_CURATOR, ACCESS_ADMIN], true)) {
                    continue;
                }
                if (one('SELECT id FROM libraries WHERE id = ?', [(int) $libraryId]) === null) {
                    continue;
                }
                q('INSERT INTO auth_group_library_access (group_map_id, library_id, access)
                   VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE access = VALUES(access)',
                  [$mapId, (int) $libraryId, (string) $level]);
            }
        }
        flash('ok', 'Group mapping added.');
        redirect('/manage/auth', ['edit' => $id]);
    }

    if ($action === 'map_delete') {
        $mapId = input_int('map_id');
        if ($mapId !== null) {
            $map = one('SELECT auth_method_id FROM auth_group_map WHERE id = ?', [$mapId]);
            delete_row('auth_group_map', $mapId);
            flash('ok', 'Group mapping removed.');
            redirect('/manage/auth', ['edit' => $map['auth_method_id'] ?? null]);
        }
        redirect('/manage/auth');
    }

    // --- Create, update, or test -------------------------------------------
    $type = in_array(input('type', 'ldap'), ['ldap', 'ad'], true) ? (string) input('type', 'ldap') : 'ldap';
    $name = trim((string) input('name', ''));

    // The form offers a single "encrypted" checkbox; the stored parameter is
    // still a mode, so STARTTLS remains possible for anyone who sets it by hand.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['encryption'])) {
        $_POST['encryption'] = empty($_POST['encrypted']) ? 'none' : 'ldaps';
    }

    $existingRow    = $id === null ? null : one('SELECT * FROM auth_methods WHERE id = ?', [$id]);
    $existingParams = $existingRow === null ? [] : ldap_params($existingRow);
    $params         = auth_params_from_post($type, $existingParams);

    // Testing works on what is on screen and saves nothing, so a configuration
    // can be proven before it is committed.
    // Answers "can this person sign in, and as what?" without making an admin
    // borrow their password to find out.
    if ($action === 'inspect') {
        $identifier = trim((string) input('inspect_username', ''));
        if ($identifier === '') {
            flash('error', 'Type a username or email address to look up.');
            redirect('/manage/auth', $id === null ? [] : ['edit' => $id]);
        }
        $method = $id === null
            ? ['id' => 0, 'type' => $type, 'params' => json_encode(auth_params_from_post($type, $existingParams))]
            : $existingRow;
        $_SESSION['ldap_inspect'] = ldap_inspect_user($method ?? [], $identifier);
        $_SESSION['ldap_draft']   = ['type' => $type, 'name' => trim((string) input('name', '')),
                                     'params' => auth_params_from_post($type, $existingParams)];
        redirect('/manage/auth', $id === null ? [] : ['edit' => $id]);
    }

    if ($action === 'test') {
        [$ok, $message, $details] = ldap_test_connection(
            ['id' => $id ?? 0, 'type' => $type, 'params' => json_encode($params)]
        );
        $_SESSION['ldap_test'] = ['ok' => $ok, 'message' => $message, 'details' => $details];

        // The verdict as a notice, the diagnostics inline.
        //
        // Two different kinds of thing were sharing one panel. "Did that work?" is the
        // answer you are waiting for after pressing Test, and it belongs where notices
        // go; the base DN it searched, the entries it found and how long it took are
        // reference material you read and compare against the form beside it, and a
        // toast that fades would take them with it.
        //
        // Both, deliberately: the notice catches the eye, the panel keeps the record, so
        // missing the toast costs nothing.
        flash($ok ? 'ok' : 'error', $message);

        // A configuration test is worth recording either way - a directory that starts
        // failing is usually noticed here first, and "it worked on Tuesday" wants a date.
        log_event('server', $ok ? 'auth.test.ok' : 'auth.test.failed',
            sprintf('Directory test %s: %s', $ok ? 'succeeded' : 'failed', $message),
            $ok ? LOG_INFO : LOG_WARNING,
            ['method' => $name, 'type' => $type]);
        // Everything on screen, so a test never costs you the form. The
        // password is included: retyping a service account password after
        // every attempt is how people end up pasting it somewhere careless.
        $_SESSION['ldap_draft'] = ['type' => $type, 'name' => $name, 'params' => $params];
        redirect('/manage/auth', $id === null ? [] : ['edit' => $id]);
    }

    if ($name === '') {
        form_failed('/manage/auth', ['name' => 'Give the directory a name.']);
    }

    $data = [
        'type'        => $type,
        'name'        => mb_substr($name, 0, 120),
        'description' => nullify(input('description')),
        'params'      => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'is_enabled'  => input_bool('is_enabled'),
        'sort_order'  => (int) (input_int('sort_order') ?? 10),
    ];

    if ($id === null) {
        $id = insert_row('auth_methods', $data);
        // The name and type, never the parameters - those carry the bind password.
        log_security('auth.method.created',
            sprintf('Directory "%s" added (%s)', (string) $data['name'], (string) $data['type']),
            LOG_WARNING, ['method' => (string) $data['name'], 'type' => (string) $data['type'],
                          'enabled' => (int) ($data['is_enabled'] ?? 0)]);
        flash('ok', $data['is_enabled'] ? 'Directory saved and enabled.' : 'Directory saved, currently disabled.');
    } else {
        update_row('auth_methods', $id, $data);
        log_security('auth.method.changed',
            sprintf('Directory "%s" reconfigured', (string) $data['name']),
            LOG_WARNING, ['method' => (string) $data['name'], 'type' => (string) $data['type']]);
        flash('ok', 'Directory saved.');
    }

    redirect('/manage/auth', ['edit' => $id]);
}

/**
 * Read directory settings out of $_POST, starting from the defaults for the
 * chosen type so an option the form does not expose keeps a sane value.
 */
function auth_params_from_post(string $type, array $existing = []): array
{
    $params = ldap_default_params($type);

    foreach (array_keys($params) as $key) {
        if (!array_key_exists($key, $_POST)) {
            // An unticked checkbox is simply absent, so booleans must go false
            // rather than fall back to whatever was stored before.
            if (is_bool($params[$key])) {
                $params[$key] = false;
            } elseif (array_key_exists($key, $existing)) {
                $params[$key] = $existing[$key];
            }
            continue;
        }
        $raw = $_POST[$key];
        if (is_array($raw)) {
            continue;
        }
        $params[$key] = match (true) {
            // Checkboxes send "on"; the certificate select sends "0" or "1".
            is_bool($params[$key]) => in_array((string) $raw, ['1', 'on', 'true'], true),
            is_int($params[$key])  => (int) $raw,
            default                => trim((string) $raw),
        };
    }

    // A blank password means "keep the stored one", so it need not be retyped
    // every time the form is saved.
    if (trim((string) ($_POST['bind_password'] ?? '')) === '' && !empty($existing['bind_password'])) {
        $params['bind_password'] = $existing['bind_password'];
    }

    return $params;
}

// --- Metadata providers -----------------------------------------------------

/**
 * One source's own page.
 *
 * Its description used to live only in the add-a-source list, so the moment a
 * source was added the explanation of what it does became unreachable.
 */
function metadata_agent_show(string $type): void
{
    require_admin();
    $def = metadata_provider_definition($type);
    if ($def === null) {
        not_found('No metadata source of that kind.');
    }

    // The machines it has been tried on, by name rather than slug: "amiga-cd32"
    // is a key, "Amiga CD32" is what somebody recognises.
    //
    // Falling back to the platforms it has ids for. A source with a mapping for
    // thirty-two machines demonstrably knows about them - that is a fact in the
    // data rather than a claim somebody typed - and an empty row on this page
    // reads as "nobody has ever tried it", which is a different and worse thing
    // to say.
    $slugs = $def['tested_with'] ?? [];
    $fromMap = false;
    if ($slugs === []) {
        $slugs = array_keys((array) ($def['platform_map'] ?? []));
        $fromMap = $slugs !== [];
    }

    $tested = [];
    foreach ($slugs as $slug) {
        $name = scalar('SELECT name FROM platforms WHERE slug = ? LIMIT 1', [$slug]);
        $tested[] = $name === null ? (string) $slug : (string) $name;
    }

    render('auth/metadata_agent', [
        'pageTitle'  => (string) ($def['label'] ?? $type),
        'def'        => $def,
        'type'       => $type,
        'tested'     => $tested,
        'testedFromMap' => $fromMap,
        'configured' => one('SELECT * FROM metadata_providers WHERE type = ? LIMIT 1', [$type]),
    ]);
}

function metadata_index(): void
{
    require_admin();
    $editId  = input_int('edit');
    $editing = $editId === null ? null : one('SELECT * FROM metadata_providers WHERE id = ?', [$editId]);

    // What the instance holds, and therefore which sources are worth offering.
    // ?all=1 is the way past it: adding a source before the library it is for is
    // a reasonable thing to do, and a filter with no way round it is a wall.
    // Ordered, not filtered.
    //
    // Hiding sources whose tested-with list misses this instance's platforms was
    // wrong the moment somebody names their own machines: their slugs match
    // nothing, so every source would be hidden from them and the escape hatch
    // would be the only way to use the feature at all. Sources tried on machines
    // this instance holds come first; the rest follow.
    $here  = instance_platform_slugs();
    $types = metadata_provider_types();

    // One agent per source, so a source already configured is not an option.
    //
    // The save refuses a duplicate, which is the right answer arriving at the
    // wrong time: you pick it, fill the form, press Add and are told no. It is
    // simply not offered now, and the list says so when nothing is left.
    $taken = array_column(all('SELECT type FROM metadata_providers'), 'type');
    uksort($types, function ($a, $b) use ($here, $types) {
        $ra = metadata_provider_relevant_here((string) $a, $here) ? 0 : 1;
        $rb = metadata_provider_relevant_here((string) $b, $here) ? 0 : 1;
        return $ra <=> $rb;
    });
    $relevant = array_filter($types, fn($k) => !in_array((string) $k, $taken, true), ARRAY_FILTER_USE_KEY);

    render('auth/metadata', [
        'pageTitle'  => 'Metadata agents',
        'providers'  => all('SELECT * FROM metadata_providers ORDER BY priority, id'),
        'types'      => $relevant,
        // $allTypes is the only one of these the screen reads: the select falls
        // back to it when the source being edited is one $types has narrowed
        // away.
        //
        // 'showAll', 'hiddenCount' and 'herePlatforms' went with it. Nothing
        // read them, and $showAll was never assigned anywhere - it was an
        // undefined variable being passed to a template that ignored it, which
        // PHP warned about on every visit to this page.
        'allTypes'   => $types,
        'editing'    => $editing,
        'params'     => $editing === null ? [] : metadata_params($editing),
        // No platform list or mapping: which machines a source is asked about
        // is a property of the machine now, not of the source.
        'testResult' => $_SESSION['metadata_test'] ?? null,
        // A lookup run against several configured sources at once, which is the
        // question an administrator actually has: not "does this one answer" but
        // "what do my sources say about this".
        'probe'      => $_SESSION['metadata_probe'] ?? null,
    ]);
    unset($_SESSION['metadata_test'], $_SESSION['metadata_probe']);
}

/**
 * One source, and every machine it covers.
 *
 * The card can only carry a handful of chips before it stops being a card, and a
 * source like IGDB covers fifty-six machines. Rather than truncate silently or
 * print a paragraph of slugs, the card shows the first few and links here.
 */
function metadata_source_show(string $type): void
{
    require_admin();

    $def = metadata_provider_definition($type);
    if ($def === null) {
        flash('error', 'No such source.');
        redirect('/manage/metadata');
    }

    // Names rather than slugs, where this instance knows the machine. A slug the
    // catalogue has never heard of is still listed - the source covers it, we
    // simply have no platform row for it - but said plainly as a slug.
    $names = [];
    foreach (all('SELECT slug, name FROM platforms WHERE library_id IS NULL') as $r) {
        $names[(string) $r['slug']] = (string) $r['name'];
    }

    render('auth/metadata_source', [
        'pageTitle' => $def['label'],
        'type'      => $type,
        'def'       => $def,
        'names'     => $names,
        'here'      => instance_platform_slugs(),
    ]);
}

function metadata_save(): void
{
    require_admin();
    csrf_verify();

    $action = input('action', 'save');
    $id     = input_int('id');

    if ($action === 'delete' && $id !== null) {
        delete_row('metadata_providers', $id);
        flash('ok', 'Source removed.');
        redirect('/manage/metadata');
    }

    if ($action === 'toggle' && $id !== null) {
        $row = one('SELECT is_enabled FROM metadata_providers WHERE id = ?', [$id]);
        if ($row !== null) {
            update_row('metadata_providers', $id, ['is_enabled' => (int) $row['is_enabled'] === 1 ? 0 : 1]);
            flash('ok', 'Source updated.');
        }
        redirect('/manage/metadata');
    }

    // Fill the gaps from the templates, for a source added before this existed or
    // a machine added since. Never over an existing mapping: automap asked the
    // service, and the service is better evidence than a file that shipped.
    if ($action === 'maptemplates' && $id !== null) {
        $provider = one('SELECT * FROM metadata_providers WHERE id = ?', [$id]);
        if ($provider === null) {
            flash('error', 'No such source.');
            redirect('/manage/metadata');
        }
        $n = metadata_seed_platform_map($id, (string) $provider['type']);
        flash($n > 0 ? 'ok' : 'error', $n > 0
            ? $n . ' platform mapping(s) added from the templates.'
            : 'Nothing to add — either the templates carry no ids for this source, or '
              . 'every machine they name is mapped already.');
        redirect('/manage/metadata', ['edit' => $id]);
    }

    // Ask several configured sources the same question at once.
    //
    // Testing used to mean one source at a time, from inside the form that edits it -
    // so comparing two answers meant editing one source, testing, editing the other,
    // testing again, and holding the first result in your head. The useful question
    // is not "does this one reply" but "what do my sources say about this", which is
    // also what a lookup from an entry will do.
    if ($action === 'probe') {
        $query = trim((string) input('probe_query', ''));
        $want  = array_map('intval', (array) ($_POST['sources'] ?? []));

        if ($query === '') {
            flash('error', 'Type something to search for.');
            redirect('/manage/metadata');
        }
        if ($want === []) {
            flash('error', 'Tick at least one source to ask.');
            redirect('/manage/metadata');
        }

        $rows = [];
        foreach ($want as $providerId) {
            $provider = one('SELECT * FROM metadata_providers WHERE id = ?', [$providerId]);
            if ($provider === null) {
                continue;
            }
            $started = microtime(true);
            $out     = metadata_search($provider, $query, null);
            $rows[]  = [
                'name'    => (string) $provider['name'],
                'type'    => (string) $provider['type'],
                'ms'      => (int) round((microtime(true) - $started) * 1000),
                'error'   => $out['error'],
                'results' => array_slice($out['results'], 0, 8),
                'total'   => count($out['results']),
            ];
        }

        $_SESSION['metadata_probe'] = ['query' => $query, 'sources' => $want, 'rows' => $rows];
        redirect('/manage/metadata');
    }

    // Ask the source what platforms it knows about and match by name, so the
    // ids come from the service rather than from anybody's memory.
    if ($action === 'automap' && $id !== null) {
        $provider = one('SELECT * FROM metadata_providers WHERE id = ?', [$id]);
        [$remote, $err] = metadata_remote_platforms($provider ?? []);
        if ($err !== null) {
            flash('error', $err);
            redirect('/manage/metadata', ['edit' => $id]);
        }
        $suggested = metadata_suggest_platform_map($remote);
        $n = 0;
        foreach ($suggested as $platformId => $remoteId) {
            q('INSERT INTO metadata_provider_platforms (provider_id, platform_id, remote_platform_id)
               VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE remote_platform_id = VALUES(remote_platform_id)',
              [$id, $platformId, $remoteId]);
            $n++;
        }
        flash('ok', $n . ' of ' . count(all_platforms(true)) . ' platforms matched against '
            . count($remote) . ' remote platforms. Check the rest by hand.');
        redirect('/manage/metadata', ['edit' => $id]);
    }

    if ($action === 'map' && $id !== null) {
        q('DELETE FROM metadata_provider_platforms WHERE provider_id = ?', [$id]);
        $submitted = $_POST['remote'] ?? [];
        if (is_array($submitted)) {
            foreach ($submitted as $platformId => $remote) {
                $remote = trim((string) $remote);
                if ($remote === '') {
                    continue;
                }
                insert_row('metadata_provider_platforms', [
                    'provider_id'        => $id,
                    'platform_id'        => (int) $platformId,
                    'remote_platform_id' => mb_substr($remote, 0, 80),
                ]);
            }
        }
        flash('ok', 'Platform mapping saved.');
        redirect('/manage/metadata', ['edit' => $id]);
    }

    $type = (string) input('type', '');
    if (metadata_provider_definition($type) === null) {
        flash('error', 'Unknown source type.');
        redirect('/manage/metadata');
    }

    $existing = $id === null ? [] : metadata_params(one('SELECT * FROM metadata_providers WHERE id = ?', [$id]) ?? []);
    $def      = metadata_provider_definition($type);
    $params   = $def['params'];

    foreach (array_keys($params) as $key) {
        if (!array_key_exists($key, $_POST) || is_array($_POST[$key])) {
            continue;
        }
        $value = trim((string) $_POST[$key]);
        $params[$key] = match (true) {
            is_int($params[$key])   => (int) $value,
            is_float($params[$key]) => (float) $value,
            default                 => $value,
        };
    }
    // The form renders the stored key, so what comes back is authoritative:
    // an emptied box genuinely means "remove the key". The only exception is a
    // source that has no key field at all, where the stored value is kept.
    //
    // Every credential, not only the one that happens to be called api_key: IGDB
    // has a client id as well, and a source whose boxes were not on the form must
    // keep what it had rather than being blanked by their absence.
    foreach (array_keys(metadata_provider_credentials($type)) as $credField) {
        if (array_key_exists($credField, $params) && !array_key_exists($credField, $_POST)) {
            $params[$credField] = (string) ($existing[$credField] ?? '');
        }
    }

    // One agent per source. A second Wikidata would be two answers to the same
    // question, with no way to tell which produced a suggestion.
    if ($id === null && one('SELECT id FROM metadata_providers WHERE type = ?', [$type]) !== null) {
        flash('error', ($def['label'] ?? $type) . ' is already configured. Edit that one rather than adding a second.');
        redirect('/admin/metadata');
    }

    $data = [
        'type'       => $type,
        // The source is the name. One agent per source, so a custom one would
        // only be a second way to say the same thing.
        'name'       => (string) $def['label'],
        'params'     => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        // A new source is always enabled: it has just answered, and there is no
        // state where something that works is added switched off. Editing an
        // existing one keeps whatever the row says, because Disable is a button
        // on the list and this form should not quietly undo it.
        'is_enabled' => $id === null ? 1 : input_bool('is_enabled'),
        'priority'   => (int) (input_int('priority') ?? 100),
        // A failure recorded by an earlier test is about the old settings.
        // Leaving it on screen after a save reads as though the save failed.
        'last_error' => null,
    ];

    if ($action === 'test') {
        $out = metadata_search(
            ['id' => $id ?? 0, 'type' => $type, 'params' => $data['params']],
            // The source's own probe term. The form used to offer a box for
            // this; it was one more thing to fill in for a check that runs
            // either way, and a term the source declares beats one typed in
            // passing.
            metadata_provider_probe($type)
        );
        $_SESSION['metadata_test'] = [
            'ok'      => $out['error'] === null,
            'message' => $out['error'] ?? (count($out['results']) . ' result(s) returned.'),
            'results' => array_slice($out['results'], 0, 5),
        ];
        // Carry back what was being tested.
        //
        // Testing a source before adding it redirected with no parameters at all, so
        // the form came back with nothing selected and the select fell to its first
        // option - which is Wikidata, whatever you had actually chosen. The verdict
        // you had just asked for was then sitting above a form describing a different
        // source. The probe title goes back too, for the same reason.
        redirect('/manage/metadata', $id === null
            ? array_filter(['type' => $type])
            : ['edit' => $id, 'probe' => (string) input('probe_title', '')]);
    }

    if ($id === null) {
        // Try it before adding it.
        //
        // A source that cannot answer is worth knowing about now rather than the
        // first time somebody looks a title up from an entry and gets a page of
        // errors. Wikidata is the case that proves it: it was configurable,
        // enabled and permanently broken, and nothing on this screen said so
        // unless you thought to press Test.
        //
        // A failure is a warning, not a veto. A key can be right and the service
        // down; a network can be behind a proxy that is asleep. So the refusal is
        // one round trip only - come back with "add it anyway" ticked and it is
        // added, with the failure recorded on the row so the list still says so.
        //
        // The check is a check, not a search. It asks a question whose answer
        // nobody wants - Turrican, or whatever was typed in the probe box - and
        // the point is entirely whether an answer came back at all. Printing the
        // five titles it found put a table of results nobody asked for above the
        // thing that had just been added, and read as though the search had been
        // the purpose. What happened goes in the notice; the results are dropped.
        // No override. It used to be a warning with a way past it, which meant a
        // source that cannot answer could be kept anyway - and a source that
        // cannot answer is not a source, it is a row that produces errors later,
        // somewhere less obvious, for somebody looking up a title. If the reason
        // is a wrong key or a service that is down, fix the key or come back; the
        // reason is in the notice.
        // Asked about something this source should certainly know, rather than
        // "Turrican" regardless. Asking a hardware database for a game is a
        // question with a correct empty answer, and an empty answer was being
        // read as a pass.
        $term  = (string) (input('probe_title', '') ?: metadata_provider_probe($type));
        // Asked for explicitly, on the second attempt, after being told why the
        // first one failed. Not a default: the check exists because a source
        // that cannot find the thing it certainly knows is misconfigured, and
        // that has been true more often than the other way round.
        $skipProbe = input('skip_probe') !== null;
        $probe = $skipProbe ? ['results' => [], 'error' => null] : metadata_search(
            ['id' => 0, 'type' => $type, 'params' => $data['params']],
            $term
        );

        // Nothing found is a failure here, and only here.
        //
        // Everywhere else in this application an empty result is a legitimate
        // answer and is reported as one. This is the exception because of what is
        // being asked: not "is there a Turrican" but "does this source work", and
        // a source that returns nothing for a term chosen because it certainly
        // knows it has not demonstrated that it does. A wrong key, a changed page
        // layout and a moved endpoint all look exactly like this.
        $why = null;
        if ($skipProbe) {
            $why = null;   // asked for, and the notice below says as much
        } elseif ($probe['error'] !== null) {
            $why = rtrim(truncate($probe['error'], 160), '.') . '.';
        } elseif ($probe['results'] === []) {
            $why = sprintf('it answered, but found nothing for "%s" — a source that '
                . 'cannot find that is not working, whatever it replied.', $term);
        }
        if ($why !== null) {
            // Refused, but not immovably.
            //
            // The check is evidence, not a verdict: a source can be configured
            // correctly and still fail this because the site is down, or because
            // the page layout moved and the parser has not caught up - and both
            // of those left somebody unable to add a source that works, with
            // nothing to do about it. The way past is offered in the message.
            flash('error', sprintf('%s was not added: %s Tick "add it without '
                . 'checking" below if you are confident it is right.',
                $data['name'], $why));
            redirect('/manage/metadata', array_filter([
                'type'    => $type,
                'probe'   => (string) input('probe_title', ''),
                'offer_skip' => '1',
            ]));
        }
        $checked = $skipProbe ? -1 : count($probe['results']);

        $id = insert_row('metadata_providers', $data);

        // And switch it on where it belongs, in every library that has a tree.
        //
        // Seeding does this for sources configured before a library existed,
        // which on a fresh install is none of them - the installer configures no
        // sources at all. Without this, adding IGDB does nothing anywhere until
        // somebody visits every games branch by hand, which is the position this
        // whole default was meant to avoid.
        $switchedOn = 0;
        foreach (all('SELECT id FROM libraries') as $lib) {
            $switchedOn += seed_library_provider_scopes((int) $lib['id']);
        }

        // What it calls our machines, from the templates, at the moment it is
        // added. Without this a fresh install searches unfiltered until somebody
        // finds the automap button and presses it once per source - and an
        // unfiltered search is how a CD32 release comes back first when you are
        // cataloguing a floppy.
        $mapped = metadata_seed_platform_map((int) $id, $type);
        $mapNote = $mapped > 0
            ? sprintf(' %d platform mapping%s came with it.', $mapped, $mapped === 1 ? '' : 's')
            : '';
        flash('ok', (match (true) {
            // Said plainly, because an unchecked source is a different thing from
            // one that answered: if it turns out not to work, this line is where
            // somebody will remember that nobody asked it anything.
            $checked === -1   => $data['name'] . ' added without checking it.',
            $checked === null => $data['name'] . ' added.',
            default           => sprintf('%s added — it answered, with %d result%s.',
                $data['name'], $checked, $checked === 1 ? '' : 's'),
        }) . $mapNote);
        // Back to the list, not into the editor.
        //
        // Adding one and landing in a form for editing it says the job is not
        // finished when it is - the source is configured, and the next thing
        // anybody is likely to want is another one. The flash already says what
        // happened, and the row is on the page.
        redirect('/manage/metadata');
    }

    update_row('metadata_providers', $id, $data);
    flash('ok', $data['name'] . ' saved.');
    // Editing stays put: you were in this form because you meant to change
    // something here, and Save is as often the middle of that as the end of it.
    redirect('/manage/metadata', ['edit' => $id]);
}

// --- Lookup and apply, from the item form -----------------------------------

function metadata_lookup(): void
{
    require_edit();
    $itemId = input_int('item');
    $item   = $itemId === null ? null : find_item($itemId);
    if ($item !== null && !can_write_item($item)) {
        flash('error', 'That library is read-only for your account.');
        redirect('/items');
    }

    // Nothing to ask.
    //
    // Reached by a bookmark, or by a form left open while somebody disabled the
    // last source. Searching would produce an empty page that looks like the
    // sources having nothing to say, which is a different fact from there being
    // no sources.
    if (!any_metadata_provider()) {
        flash('error', 'No metadata source is configured and enabled. Add one under '
            . 'Instance settings → Metadata agents.');
        redirect($item === null ? '/items' : '/items/' . (int) $item['id'] . '/edit');
    }

    $title      = (string) input('q', $item['title'] ?? '');
    $platformId = input_int('platform');
    if ($platformId === null && $item !== null && !empty($item['platform_id'])) {
        $platformId = (int) $item['platform_id'];
    }

    // Hardware entries ask hardware sources, software entries ask software ones.
    $domain = null;
    if ($item !== null && !empty($item['category_id'])) {
        $domain = (string) (scalar('SELECT s.slug FROM categories c JOIN sections s ON s.id = c.section_id
                                     WHERE c.id = ?', [(int) $item['category_id']]) ?: 'software');
    }

    $out = ['results' => [], 'errors' => [], 'unmapped' => [], 'skipped' => [], 'asked' => [],
            'consulted' => null];
    if (trim($title) !== '') {
        // The entry's own branch decides which sources are asked, which is what
        // the On/Off buttons in the category editor are for.
        $out = metadata_search_all($title, $platformId, $domain,
                                   $item === null ? null : (int) $item['category_id']);
    }

    render('items/lookup', [
        'pageTitle' => 'Look up metadata',
        'item'      => $item,
        // Which side of the shop this entry is on, so the review screen names its
        // fields the way the form does. Without it the template's `$domain ?? null`
        // falls back to the software wording, and a peripheral was offered a
        // "Release year" and a "Developer" - the two words the hardware form does
        // not use.
        'domain'    => $domain,
        'query'     => $title,
        'platformId' => $platformId,
        'results'   => $out['results'],
        'errors'    => $out['errors'],
        'unmapped'  => $out['unmapped'] ?? [],
        // Which sources were not asked, and why. An absence explains nothing.
        'skipped'   => $out['skipped'] ?? [],
        // Which sources were asked, and what each said. Not advice - the answer
        // to "did it look there?", which has no other way of being answered when
        // a source that found nothing has no row on the page.
        'asked'     => $out['asked'] ?? [],
        // How many sources were consulted. Zero and "none of them found it"
        // are different answers, and the screen used to give the second when
        // it meant the first.
        'consulted' => $out['consulted'] ?? null,
        'providers' => enabled_metadata_providers(),
        // No platform list: the screen no longer offers one. It used to be
        // readable_libraries() under the name $platforms, which is where the
        // mismatch came from.
    ]);
}

function metadata_apply(): void
{
    require_edit();
    csrf_verify();

    if (!any_metadata_provider()) {
        flash('error', 'No metadata source is configured and enabled.');
        redirect('/items');
    }

    $itemId = input_int('item_id');
    $item   = $itemId === null ? null : find_item($itemId);
    if ($item === null) {
        flash('error', 'No such entry.');
        redirect('/items');
    }
    if (!can_write_item($item)) {
        flash('error', 'That library is read-only for your account.');
        redirect('/items');
    }

    $candidate = json_decode((string) ($_POST['candidate'] ?? ''), true);
    if (!is_array($candidate)) {
        flash('error', 'That suggestion could not be read. Try the search again.');
        redirect('/items/' . $itemId . '/edit');
    }

    $available = metadata_to_item_fields($candidate);
    $wanted    = $_POST['apply'] ?? [];
    $wantedArt = $_POST['artwork'] ?? [];
    $wantedDoc  = $_POST['documents'] ?? [];
    $wantedSpec = $_POST['apply_spec'] ?? [];
    if ((!is_array($wanted) || $wanted === [])
        && (!is_array($wantedArt) || $wantedArt === [])
        && (!is_array($wantedDoc) || $wantedDoc === [])
        && (!is_array($wantedSpec) || $wantedSpec === [])
        && (!is_array($_POST['apply_hw'] ?? null) || ($_POST['apply_hw'] ?? []) === [])) {
        flash('error', 'Tick at least one field, image, document or hardware detail to import.');
        redirect('/metadata/lookup', ['item' => $itemId]);
    }
    $wanted = is_array($wanted) ? $wanted : [];

    $data = [];
    // The entry's own side of the shop, read from the row rather than borrowed
    // from a variable in another function - $domain lives in metadata_lookup(),
    // not here, and using it would have been a silent fallback to software on
    // every hardware apply.
    $applyMakes = (string) (scalar('SELECT s.slug FROM items i
                                      JOIN categories c ON c.id = i.category_id
                                      JOIN sections s ON s.id = c.section_id
                                     WHERE i.id = ?', [$itemId]) ?: 'software') === 'hardware'
        ? 'hardware' : 'software';

    foreach ($available as $field => $value) {
        if (!in_array($field, $wanted, true)) {
            continue;
        }
        if ($field === 'developer_name') {
            // The entry's own side of the shop, so a lookup on a machine does
            // not file Commodore as a software house.
            $data['developer_id'] = company_id_for_name((string) $value, $applyMakes);
        } elseif ($field === 'publisher_name') {
            $data['publisher_id'] = company_id_for_name((string) $value, $applyMakes);
        } else {
            $data[$field] = $value;
        }
    }

    // Hardware detail lands in its own table, and only for a hardware entry.
    //
    // The comment said this and nothing enforced it. The screen stopped offering
    // the rows once it knew the domain, but a form is a thing anybody can post -
    // and "the template does not draw it" is not a rule, it is a habit.
    $isHardwareEntry = (string) (scalar('SELECT s.slug FROM items i
                                           JOIN categories c ON c.id = i.category_id
                                           JOIN sections s ON s.id = c.section_id
                                          WHERE i.id = ?', [$itemId]) ?: '') === 'hardware';

    $hwFields = [];
    $wantedHw = $_POST['apply_hw'] ?? [];
    if ($isHardwareEntry && is_array($wantedHw) && $wantedHw !== []) {
        $available = metadata_to_hardware_fields($candidate, (int) $item['platform_id']);
        foreach ($available as $field => $value) {
            if (in_array($field, $wantedHw, true)) {
                $hwFields[$field] = $value;
            }
        }
        save_item_hardware($itemId, $hwFields);
    }

    if ($data !== []) {
        update_row('items', $itemId, $data);
        $user = current_user();
        record_metadata_import($itemId, $candidate, $data, $user === null ? null : (int) $user['id']);
    }

    // Artwork is fetched server-side and put through the same checks an upload
    // gets, rather than being linked to and trusted.
    $art = 0;
    // Pictures the entry already had, counted separately: nothing went wrong.
    $artSame = 0;
    if (is_array($wantedArt) && $wantedArt !== []) {
        foreach ($candidate['images'] ?? [] as $index => $image) {
            if (!in_array((string) $index, array_map('strval', $wantedArt), true)) {
                continue;
            }
            // The source's caption where it gave one: "Rev 4.5 motherboard, back
            // side" says what the photograph is of, which "From the Amiga
            // Hardware Database" does not - and six pictures all captioned the
            // latter are six pictures nobody can tell apart.
            $caption = trim((string) ($image['caption'] ?? '')) !== ''
                ? (string) $image['caption']
                : 'From ' . ($candidate['provider_label'] ?? 'a metadata source');
            [$ok, $artError, $dupe] = array_pad(metadata_import_image(
                $itemId, (string) ($image['url'] ?? ''),
                (string) ($image['kind'] ?? 'box_front'), $caption
            ), 3, false);

            // Already on the entry: not a failure, and not worth a second fetch.
            // Running the same lookup again and ticking the same artwork is an
            // ordinary thing to do, and it used to attach a second copy of every
            // picture.
            if ($dupe) {
                $artSame++;
                continue;
            }

            // The full-size address is derived by rule from the thumbnail's, and
            // a rule inferred from one page will not hold for every page. Where
            // it misses, the thumbnail is a real picture at the address the source
            // itself published - a small correct image beats a large missing one.
            if (!$ok && !empty($image['thumb_url']) && $image['thumb_url'] !== ($image['url'] ?? '')) {
                [$ok, $artError, $dupe] = array_pad(metadata_import_image(
                    $itemId, (string) $image['thumb_url'],
                    (string) ($image['kind'] ?? 'box_front'), $caption
                ), 3, false);
                if ($dupe) {
                    $artSame++;
                    continue;
                }
            }
            if ($ok) {
                $art++;
            } else {
                flash('error', $artError);
            }
        }
    }

    // Specification rows, merged into what the entry has rather than written over
    // it. Indexed against the candidate, like the artwork and the documents, so a
    // posted label cannot name something the source never said.
    $specsAdded = 0;
    if ($isHardwareEntry && is_array($wantedSpec) && $wantedSpec !== []) {
        $offered = metadata_spec_rows($candidate, $itemId);
        $chosen  = [];
        foreach ($offered as $row) {
            if (in_array((string) $row['index'], array_map('strval', $wantedSpec), true)) {
                $chosen[] = $row;
            }
        }
        $specsAdded = metadata_apply_specs($itemId, $chosen);
    }

    // Documents: the address is kept, the file is not fetched.
    //
    // Indexed against the candidate rather than trusting posted urls, for the
    // same reason the artwork is: a form field naming a URL the server will then
    // store is a form field somebody can put anything in.
    $docs = 0;
    if (is_array($wantedDoc) && $wantedDoc !== []) {
        foreach ((array) ($candidate['documents'] ?? []) as $dx => $doc) {
            if (!in_array((string) $dx, array_map('strval', $wantedDoc), true)) {
                continue;
            }
            if (add_item_document($itemId, (string) ($doc['name'] ?? 'Document'),
                                  (string) ($doc['url'] ?? ''),
                                  (string) ($candidate['provider_label'] ?? 'a metadata source'))) {
                $docs++;
            }
        }
    }

    $parts = [];
    if ($data !== []) { $parts[] = count($data) . ' field' . (count($data) === 1 ? '' : 's'); }
    if ($hwFields !== []) { $parts[] = count($hwFields) . ' hardware detail' . (count($hwFields) === 1 ? '' : 's'); }
    if ($art > 0)     { $parts[] = $art . ' image' . ($art === 1 ? '' : 's'); }
    if ($artSame > 0) {
        $parts[] = $artSame . ' image' . ($artSame === 1 ? '' : 's') . ' already there';
    }
    if ($docs > 0)    { $parts[] = $docs . ' document link' . ($docs === 1 ? '' : 's'); }
    if ($specsAdded > 0) { $parts[] = $specsAdded . ' specification row' . ($specsAdded === 1 ? '' : 's'); }
    $summary = ($parts === [] ? 'Nothing' : implode(' and ', $parts))
        . ' imported from ' . ($candidate['provider_label'] ?? 'the source') . '.';

    // Logged as well as flashed. A search that answered and an import that took
    // nothing from it are different events, and only one of them changes the
    // catalogue - which is the one somebody is looking for when they ask why an
    // entry says what it says.
    log_event('metadata', 'import.applied',
        sprintf('entry %d: %s', $itemId, $summary), LOG_INFO, [
            'item'    => $itemId,
            'source'  => (string) ($candidate['provider'] ?? ''),
            'fields'  => count($data),
            'hardware' => count($hwFields),
            'images'  => $art,
            'images_already_there' => $artSame,
            'links'   => $docs,
            'specs'   => $specsAdded,
        ]);

    flash('ok', $summary);
    redirect('/items/' . $itemId);
}

// --- Your own profile -------------------------------------------------------
//
// Deliberately separate from Manage. Everything here acts on the signed-in
// account and needs no special role: an editor should be able to change their
// own password without being handed the keys to the accounts screen.

function profile_index(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('/login');
    }

    $method = one('SELECT * FROM auth_methods WHERE id = ?', [(int) ($user['auth_method_id'] ?? 1)]);
    $isLocal = $method === null || $method['type'] === 'local';

    render('auth/profile', [
        'pageTitle'   => 'Your profile',
        'user'        => one('SELECT * FROM users WHERE id = ?', [(int) $user['id']]),
        'method'      => $method,
        'isLocal'     => $isLocal,
        'tokenCount'  => (int) scalar('SELECT COUNT(*) FROM api_tokens WHERE user_id = ? AND revoked_at IS NULL', [(int) $user['id']]),
        // The libraries this account can reach. This listed every platform and
        // computed an access level by passing a platform id to a function that
        // looks up library membership, which answered a question nobody asked.
        'libraries'   => readable_libraries(ACCESS_VIEWER),
        // Offers waiting on an answer. The profile is where you look at what
        // your account is, so it is where an offer to join something belongs.
        'invitations'   => pending_invitations(),
        'recentSignIns' => all(
            'SELECT * FROM auth_log WHERE username = ? ORDER BY created_at DESC LIMIT 5',
            [$user['username']]
        ),
    ]);
}

function profile_save(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('/login');
    }
    csrf_verify();

    $userId = (int) $user['id'];
    $action = input('action', 'details');

    // --- Picture ---
    if ($action === 'avatar') {
        if (input('remove_avatar') === '1') {
            delete_user_avatar($userId);
            flash('ok', 'Picture removed.');
            redirect('/profile');
        }
        [, $error] = store_user_avatar($userId, 'avatar');
        flash($error === null ? 'ok' : 'error', $error ?? 'Picture updated.');
        redirect('/profile');
    }

    // --- Password ---
    if ($action === 'password') {
        $method  = one('SELECT * FROM auth_methods WHERE id = ?', [(int) ($user['auth_method_id'] ?? 1)]);
        $isLocal = $method === null || $method['type'] === 'local';
        if (!$isLocal) {
            flash('error', 'This account signs in through ' . ($method['name'] ?? 'a directory') . ', so its password lives there and cannot be changed here.');
            redirect('/profile');
        }

        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        $row = one('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        // Proving you know the current one is what stops a borrowed session
        // from locking the real owner out.
        if ($row === null || $row['password_hash'] === null || !password_verify($current, (string) $row['password_hash'])) {
            flash('error', 'That is not your current password.');
            redirect('/profile');
        }
        if (strlen($new) < 10) {
            flash('error', 'Use a new password of at least 10 characters.');
            redirect('/profile');
        }
        if ($new !== $confirm) {
            flash('error', 'The two new passwords do not match.');
            redirect('/profile');
        }
        if ($new === $current) {
            flash('error', 'That is the password you already have.');
            redirect('/profile');
        }

        update_row('users', $userId, ['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
        session_regenerate_id(true);
        log_auth_attempt((string) $user['username'], (int) ($user['auth_method_id'] ?? 1), true, 'password changed');
        flash('ok', 'Password changed.');
        redirect('/profile');
    }

    // --- Name and email ---
    $email = nullify(input('email'));
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'That does not look like an email address.');
        redirect('/profile');
    }

    update_row('users', $userId, [
        'display_name' => nullify(input('display_name')),
        'email'        => $email,
    ]);
    flash('ok', 'Profile updated.');
    redirect('/profile');
}

// --- Libraries ---------------------------------------------------------------
//
// A library is the thing people share, so managing one is not an administrator's
// job: whoever owns it decides who is in it. Administrators can create libraries
// and see that they exist, but a private library's contents stay private until
// somebody grants them access - and that grant is recorded.

/**
 * Offer a library to somebody who has joined it.
 *
 * An offer, not a handover: the other person has to accept, and until they do the
 * library keeps its owner. Making somebody responsible for a shelf without asking is
 * the sort of thing that is only noticed when they try to delete something.
 */
function library_offer_ownership(int $id): void
{
    if (current_user() === null) {
        redirect('/login');
    }
    csrf_verify();
    $me  = current_user();
    $lib = one('SELECT * FROM libraries WHERE id = ?', [$id]);

    if ($lib === null || !is_library_owner($me, $id)) {
        flash('error', 'Only the owner can hand a library over.');
        redirect('/profile/access');
    }
    if ((int) $lib['is_personal'] === 1) {
        flash('error', 'A personal shelf belongs to its account and cannot be handed over.');
        redirect('/profile/access');
    }

    $toId = input_int('to');
    // Only a member who has accepted. Somebody who has not joined has not agreed to be
    // in the library at all, so offering them the whole thing skips a step.
    $ok = $toId !== null && one(
        "SELECT 1 FROM library_members
          WHERE library_id = ? AND user_id = ? AND status = 'accepted' AND access <> 'owner'",
        [$id, $toId]
    ) !== null;

    if (!$ok) {
        flash('error', 'Choose somebody who has joined this library.');
        redirect('/libraries/' . $id . '/edit');
    }

    update_row('libraries', $id, ['pending_owner_id' => $toId, 'pending_owner_at' => date('Y-m-d H:i:s')]);
    notify($toId, 'library.ownership_offered', [
        'subject'   => sprintf('You have been offered ownership of "%s"', (string) $lib['name']),
        'body'      => sprintf('%s would like to hand the library over to you. It stays theirs '
                             . 'until you accept.', (string) ($me['display_name'] ?: $me['username'])),
        'link_path' => '/profile/access?tab=invites',
    ]);
    log_security('library.ownership.offered',
        sprintf('Ownership of "%s" offered', (string) $lib['name']), LOG_WARNING,
        ['library' => (string) $lib['slug'], 'to' => $toId]);

    flash('ok', 'Offered. It stays yours until they accept.');
    redirect('/libraries/' . $id . '/edit');
}

/** Take the offer back, or turn it down - the same undoing from either end. */
function library_ownership_respond(int $id, string $action): void
{
    if (current_user() === null) {
        redirect('/login');
    }
    csrf_verify();
    $me  = current_user();
    $lib = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($lib === null || (int) ($lib['pending_owner_id'] ?? 0) === 0) {
        flash('error', 'There is no offer outstanding for that library.');
        redirect('/profile/access');
    }

    $offered = (int) $lib['pending_owner_id'];
    $amOwner = is_library_owner($me, $id);
    $amThem  = (int) $me['id'] === $offered;

    if ($action === 'withdraw' && !$amOwner) {
        flash('error', 'Only the owner can withdraw the offer.');
        redirect('/profile/access');
    }
    if (in_array($action, ['accept', 'decline'], true) && !$amThem) {
        flash('error', 'That offer was not made to you.');
        redirect('/profile/access');
    }

    if ($action !== 'accept') {
        update_row('libraries', $id, ['pending_owner_id' => null, 'pending_owner_at' => null]);
        flash('ok', $action === 'withdraw' ? 'Offer withdrawn.' : 'Offer declined.');
        redirect('/profile/access');
    }

    // Accepted. One owner, so the swap is both halves at once: the column that decides,
    // and the two membership rows that follow from it. The former owner stays on as a
    // curator rather than being shown the door - they were running the place a moment
    // ago, and dropping them to nothing is a worse surprise than leaving them in.
    $wasOwner = (int) ($lib['owner_id'] ?? 0);

    update_row('libraries', $id, [
        'owner_id'         => $offered,
        'pending_owner_id' => null,
        'pending_owner_at' => null,
    ]);
    q("INSERT INTO library_members (library_id, user_id, access, status, note)
       VALUES (?, ?, 'owner', 'accepted', 'Accepted ownership')
       ON DUPLICATE KEY UPDATE access = 'owner', status = 'accepted'", [$id, $offered]);
    if ($wasOwner > 0 && $wasOwner !== $offered) {
        // Admin, not curator.
        //
        // The level the outgoing owner keeps should be everything they had bar
        // the library itself, and that is what admin now means - curator stopped
        // including members when the levels were split, so this line quietly
        // became a bigger demotion than it reads as.
        q("UPDATE library_members SET access = 'admin'
            WHERE library_id = ? AND user_id = ?", [$id, $wasOwner]);
        notify($wasOwner, 'library.ownership_answered', [
            'subject'   => sprintf('"%s" now belongs to somebody else', (string) $lib['name']),
            'body'      => 'They accepted the handover. You are still a curator there.',
            'link_path' => '/profile/access',
        ]);
    }

    $GLOBALS['__membership_cache'] = [];
    log_security('library.ownership.transferred',
        sprintf('Ownership of "%s" transferred', (string) $lib['name']), LOG_WARNING,
        ['library' => (string) $lib['slug'], 'from' => $wasOwner, 'to' => $offered]);

    flash('ok', (string) $lib['name'] . ' is yours now.');
    redirect('/profile/access');
}

/**
 * Take on a published shelf.
 *
 * Only published ones: this is accepting an open invitation, not letting anybody into a
 * private library. The access granted is what the library offers - contributor where it
 * is open to write, viewer otherwise - and never more than an invitation would have.
 */
function library_join(int $id): void
{
    if (current_user() === null) {
        redirect('/login');
    }
    csrf_verify();

    $lib = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($lib === null || (string) $lib['kind'] !== 'public'
        || ((int) $lib['public_read'] !== 1 && (int) $lib['public_write'] !== 1)) {
        flash('error', 'That library is not open to join.');
        redirect('/libraries');
    }

    $user = current_user();
    $access = (int) $lib['public_write'] === 1 ? ACCESS_CONTRIBUTOR : ACCESS_VIEWER;

    // Never downgrade: somebody invited as a curator who then presses Join keeps what
    // they were given. ON DUPLICATE KEY would have quietly demoted them.
    $held = one('SELECT access FROM library_members WHERE library_id = ? AND user_id = ?',
                [$id, (int) $user['id']]);
    if ($held === null) {
        q('INSERT INTO library_members (library_id, user_id, access, note)
           VALUES (?, ?, ?, ?)',
          [$id, (int) $user['id'], $access, 'Joined a published library']);
    }

    $GLOBALS['__membership_cache'] = [];
    flash('ok', (string) $lib['name'] . ' is on your shelf now.');
    redirect('/libraries');
}


/**
 * Library management: what exists on this server, and what an administrator may do.
 *
 * Its own page rather than a section at the bottom of the access page. Those answer
 * different questions - "what can I reach" against "what exists here" - and one long
 * page whose second half appeared only for administrators was a poor answer to both.
 */
/**
 * What is actually on a shelf, itemised.
 *
 * The list screen showed a number in an Entries column and nothing else, so the
 * decision to delete a library was being made against a count. This is the
 * answer to "what would I be destroying" - every entry linked, and the platforms,
 * makers, models and places the library defined for itself, which are the things
 * people forget a library owns until they are gone.
 */
function library_contents_index(int $id): void
{
    // An owner can read their own.
    //
    // It was admin-only because it was written for the administrator's delete
    // screen, but "what is actually in here" is not an administrative question -
    // it is the first thing an owner wants when a library has grown past what
    // they can hold in their head, and refusing it to them while showing it to
    // somebody who does not own it is the wrong way round.
    if (!is_admin() && !can_own_library($id)) {
        require_admin();
    }

    $library = one('SELECT l.*, o.username AS owner_name
                      FROM libraries l LEFT JOIN users o ON o.id = l.owner_id
                     WHERE l.id = ?', [$id]);
    if ($library === null) {
        flash('error', 'No such library.');
        redirect('/manage/libraries');
    }

    // Entries are the long list and the only one worth paging; everything else is
    // a handful of rows that fits on the page.
    $page    = max(1, (int) (input_int('page') ?? 1));
    $perPage = 100;

    render('auth/library_contents', [
        'pageTitle' => 'What ' . $library['name'] . ' holds',
        'library'   => $library,
        'summary'   => library_contents_summary($id),
        'page'      => $page,
        'perPage'   => $perPage,
        'entries'   => all(
            // Deleted entries are in the trash, not on the shelf.
            //
            // This read `items` directly while every browsing screen reads
            // v_items, which excludes them - so a deleted entry vanished from the
            // catalogue and went on being counted here. "2 entries" beside a
            // browser saying "nothing here yet" is the catalogue contradicting
            // itself, and the trashed row is also what quietly blocks deleting
            // the company it points at.
            'SELECT i.id, i.title, i.created_at, i.deleted_at,
                    c.name AS category_name, p.name AS platform_name,
                    (SELECT COUNT(*) FROM item_images im WHERE im.item_id = i.id) AS images
               FROM items i
          LEFT JOIN categories c ON c.id = i.category_id
          LEFT JOIN platforms  p ON p.id = i.platform_id
              WHERE i.library_id = ? AND i.deleted_at IS NULL
           ORDER BY i.title
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            [$id]
        ),
        'platforms'  => all('SELECT id, name, slug FROM platforms WHERE library_id = ? ORDER BY name', [$id]),
        'companies'  => all('SELECT id, name, slug FROM companies WHERE library_id = ? ORDER BY name', [$id]),
        'locations'  => all('SELECT id, name FROM locations WHERE library_id = ? ORDER BY name', [$id]),
        'hardware'   => all('SELECT id, name, slug FROM hardware_models WHERE library_id = ? ORDER BY name', [$id]),
        'software'   => all('SELECT id, name FROM software_models WHERE library_id = ? ORDER BY name', [$id]),
        'members'    => all('SELECT m.access, m.status, u.username
                               FROM library_members m JOIN users u ON u.id = m.user_id
                              WHERE m.library_id = ? ORDER BY u.username', [$id]),
    ]);
}

/**
 * The administrator's editor, which is not the owner's editor.
 *
 * Manage used to link to /libraries/{id}/edit - the full owner screen, with
 * template resynchronisation, per-library platforms, visibility, invitations and
 * the ownership handover. An administrator opening a library they do not own
 * wants none of that. They want the name, whether it is on, who owns it, and the
 * way out. So this is its own screen and deliberately short.
 */
function library_admin_edit_form(int $id): void
{
    require_admin();

    $library = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($library === null) {
        flash('error', 'No such library.');
        redirect('/manage/libraries');
    }
    if ((int) ($library['is_personal'] ?? 0) === 1) {
        flash('error', 'A personal shelf belongs to the account that owns it and is not '
            . 'managed from here.');
        redirect('/manage/libraries');
    }

    render('auth/library_admin_edit', [
        'pageTitle' => 'Manage ' . $library['name'],
        'library'   => $library,
        'summary'   => library_contents_summary($id),
        'accounts'  => all('SELECT id, username, display_name FROM users
                             WHERE is_active = 1 ORDER BY username'),
    ]);
}

function library_manage_index(): void
{
    require_admin();

    render('auth/library_admin', [
        'pageTitle' => 'Library management',
        // Every non-personal library, with the counts an administrator acts on.
        'others'    => all(
            "SELECT l.*,
                    o.username AS owner_name,
                    (SELECT COUNT(*) FROM items i WHERE i.library_id = l.id) AS entries,
                    (SELECT COUNT(*) FROM library_members m WHERE m.library_id = l.id) AS members
               FROM libraries l
          LEFT JOIN users o ON o.id = l.owner_id
              WHERE l.is_personal = 0
           ORDER BY l.is_active DESC, l.name"
        ),
    ]);
}

/**
 * Give one back.
 *
 * Not for your own: a personal shelf is the one place you always have, and leaving it
 * would strand its entries. Owners of a shared library cannot leave either - somebody
 * has to be responsible for it.
 */
function library_leave(int $id): void
{
    if (current_user() === null) {
        redirect('/login');
    }
    csrf_verify();

    $user = current_user();
    $lib  = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($lib === null) {
        redirect('/libraries');
    }
    if ((int) $lib['is_personal'] === 1 || (int) ($lib['owner_id'] ?? 0) === (int) $user['id']) {
        flash('error', 'You cannot leave a library you own.');
        redirect('/libraries');
    }

    q('DELETE FROM library_members WHERE library_id = ? AND user_id = ?', [$id, (int) $user['id']]);
    $GLOBALS['__membership_cache'] = [];

    // If it was the shelf being worked in, stop pointing at it.
    if ((int) ($_SESSION['working_library'] ?? 0) === $id) {
        unset($_SESSION['working_library']);
    }

    flash('ok', (string) $lib['name'] . ' is off your shelf. You can join it again.');
    redirect('/libraries');
}

function library_admin_index(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('/login');
    }

    $editId  = input_int('edit');
    $editing = null;
    if ($editId !== null) {
        $editing = one('SELECT * FROM libraries WHERE id = ?', [$editId]);
        if ($editing !== null && !can_own_library($editId) && !is_admin()) {
            flash('error', 'Only the owner of that library can change it.');
            redirect('/libraries');
        }
    }

    // Everything you can reach, plus - for an administrator - the ones you
    // cannot, listed by name only so the instance is legible without handing
    // over their contents.
    // Yours and the ones you joined; published shelves you have not joined are listed
    // separately, as an offer rather than as something already on your shelf.
    $mine = joined_libraries();
    $mineIds = array_map(fn($l) => (int) $l['id'], $mine);

    // The access page. Only what this account can reach, is offered, or could join.
    render('auth/libraries', [
        'pageTitle' => 'Libraries',
        // Which of the three views. A plain query parameter, so the tabs are links and
        // work without script.
        'tab'       => in_array((string) input('tab', ''), ['invites', 'public'], true)
            ? (string) input('tab') : 'access',
        'mine'      => array_map(fn($l) => $l + [
            'access'  => library_access($user, (int) $l['id']),
            'entries' => (int) scalar('SELECT COUNT(*) FROM items WHERE library_id = ? AND deleted_at IS NULL', [(int) $l['id']]),
            'members' => (int) scalar('SELECT COUNT(*) FROM library_members WHERE library_id = ?', [(int) $l['id']]),
        ], $mine),
        // Invitations waiting on an answer.
        //
        // These grant nothing until accepted - user_memberships() only counts
        // 'accepted', so a pending row is an offer and not access. They were not shown
        // anywhere on this page, which meant an invitation could sit unanswered with no
        // way to find it.
        'invites'   => all(
            "SELECT l.*, m.access, m.granted_at, m.note,
                    g.username AS invited_by
               FROM library_members m
               JOIN libraries l ON l.id = m.library_id
          LEFT JOIN users g ON g.id = m.granted_by
              WHERE m.user_id = ? AND m.status = 'pending' AND l.is_active = 1
           ORDER BY m.granted_at DESC",
            [(int) $user['id']]
        ),
        // An offer of ownership waiting on this account. It sits with the invitations
        // because it is the same kind of thing: somebody has asked, and nothing has
        // happened yet.
        'ownerOffers' => all(
            "SELECT l.*, o.username AS offered_by
               FROM libraries l
          LEFT JOIN users o ON o.id = l.owner_id
              WHERE l.pending_owner_id = ? AND l.is_active = 1
           ORDER BY l.pending_owner_at DESC",
            [(int) $user['id']]
        ),
        'joinable'  => array_map(fn($l) => $l + [
            'entries' => (int) scalar('SELECT COUNT(*) FROM items WHERE library_id = ? AND deleted_at IS NULL', [(int) $l['id']]),
        ], joinable_libraries()),
        'editing'   => $editing,
        'members'   => $editing === null ? [] : all(
            'SELECT m.*, u.username, u.display_name, u.avatar_filename,
                    g.username AS granted_by_name
               FROM library_members m
               JOIN users u ON u.id = m.user_id
          LEFT JOIN users g ON g.id = m.granted_by
              WHERE m.library_id = ?
           ORDER BY FIELD(m.access, \'owner\',\'curator\',\'contributor\',\'viewer\'), u.username',
            [(int) $editing['id']]
        ),
        'accounts'  => all('SELECT id, username, display_name FROM users WHERE is_active = 1 ORDER BY username'),
        'platforms' => all_platforms(),
        // Machines this library defined for itself, and the shared ones for
        // comparison. A library owner adding a Sharp MZ-2500 should not have to
        // ask an administrator first.
        'ownPlatforms' => $editing === null ? [] : library_platforms((int) $editing['id']),
    ]);
}

function library_admin_save(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('/login');
    }
    csrf_verify();

    $action = input('action', 'save');
    $id     = input_int('id');

    // --- Taking a shelf out of circulation, or removing it -------------------
    //
    // Administrators only, and never a personal shelf: that is the one place somebody
    // always has, and an administrator reaching into it is not library management, it
    // is reading somebody's collection.
    if (in_array($action, ['disable', 'enable', 'admin-delete', 'admin-purge', 'admin-owner'], true)) {
        // Disabling is the owner's to do as well.
        //
        // It is what they get instead of deleting when the instance does not allow that,
        // so refusing it to them would leave an owner unable to take their own shelf out
        // of circulation at all. Enabling and removing stay with administrators: coming
        // back is somebody else's decision, which is what makes disabling safe to offer.
        $mayOwnerDisable = $action === 'disable'
            && $id !== null
            && is_library_owner(current_user(), $id);
        if (!$mayOwnerDisable) {
            require_admin();
        }
        $lib = $id === null ? null : one('SELECT * FROM libraries WHERE id = ?', [$id]);

        if ($lib === null) {
            flash('error', 'No such library.');
            redirect('/manage/libraries');
        }
        if ((int) $lib['is_personal'] === 1) {
            flash('error', sprintf(
                '%s is a personal shelf. Those belong to the account that owns them and '
                . 'are not managed from here.', (string) $lib['name']));
            redirect('/manage/libraries');
        }

        // Hand it to somebody, without asking them.
        //
        // The owner's route is an offer: library_offer_ownership() writes
        // pending_owner_id and waits for the other account to accept, and it will only
        // offer to a member who has already joined. That is right between two users -
        // being made responsible for a collection is not something that should happen
        // to you silently.
        //
        // It is wrong for an administrator sorting out a library whose owner has left.
        // Waiting on an acceptance that will never come, from an account that has to be
        // invited and accept an invitation first, is three steps to fix a broken row.
        // So this sets owner_id and clears any offer in flight, and adds the membership
        // that ownership implies rather than requiring one to exist.
        if ($action === 'admin-owner') {
            require_admin();
            $newOwner = input_int('user_id');
            $account  = $newOwner === null ? null
                : one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$newOwner]);
            if ($account === null) {
                flash('error', 'Pick an active account to own it.');
                redirect('/manage/libraries', ['edit' => (int) $lib['id']]);
            }

            update_row('libraries', (int) $lib['id'], [
                'owner_id'         => (int) $account['id'],
                // Any offer in flight is answered by this, so it does not sit there
                // pointing at a decision that has been overtaken.
                'pending_owner_id' => null,
                'pending_owner_at' => null,
            ]);
            q("INSERT INTO library_members (library_id, user_id, access, status, granted_by, granted_at)
                    VALUES (?, ?, 'owner', 'accepted', ?, NOW())
               ON DUPLICATE KEY UPDATE access = 'owner', status = 'accepted'",
              [(int) $lib['id'], (int) $account['id'], (int) $user['id']]);
            $GLOBALS['__membership_cache'] = [];

            log_security('library.owner_forced', sprintf(
                'Library "%s" owner set to %s by an administrator',
                (string) $lib['name'], (string) $account['username']),
                LOG_WARNING, ['library' => (string) $lib['slug']]);
            flash('ok', sprintf('%s now belongs to %s.',
                (string) $lib['name'], (string) $account['username']));
            redirect('/manage/libraries', ['edit' => (int) $lib['id']]);
        }

        if ($action === 'disable' || $action === 'enable') {
            $on = $action === 'enable' ? 1 : 0;
            update_row('libraries', (int) $lib['id'], ['is_active' => $on]);
            log_security($on ? 'library.enabled' : 'library.disabled',
                sprintf('Library "%s" %s', (string) $lib['name'], $on ? 'enabled' : 'disabled'),
                LOG_WARNING, ['library' => (string) $lib['slug']]);
            flash('ok', sprintf('%s is %s.', (string) $lib['name'],
                $on ? 'back in circulation' : 'disabled and hidden from everyone but administrators'));
            redirect('/manage/libraries');
        }

        // Delete, forced. The administrator's version of the same button.
        //
        // Separate from the one below rather than a flag on it, because it is a
        // different decision: that one refuses while anything is on the shelf, this
        // one is the answer to "I know, delete it anyway". It ignores the instance
        // switch - libraries.deletable exists so an instance can promise its users
        // that shelves are never quietly removed, and an administrator typing a
        // library's name to destroy it is not that. It is not quiet, and it is not
        // a user.
        //
        // The name has to be typed. Everything else on this screen is one click,
        // which is right for disabling and wrong for this.
        if ($action === 'admin-purge') {
            $typed = trim((string) input('confirm_name', ''));
            if ($typed !== (string) $lib['name']) {
                flash('error', 'Type the library\'s name exactly to delete it and everything '
                    . 'in it. Nothing was changed.');
                redirect('/manage/libraries', ['contents' => (int) $lib['id']]);
            }

            $name = (string) $lib['name'];
            $slug = (string) $lib['slug'];
            [$ok, $message, $gone] = library_purge((int) $lib['id']);
            if (!$ok) {
                flash('error', $message);
                redirect('/manage/libraries');
            }

            // Logged with the count, because this is the one action here that cannot
            // be walked back and the log is the only record of what was on the shelf.
            log_security('library.purged', sprintf(
                'Library "%s" deleted with %d entries, %d images and %d files',
                $name, $gone['entries'] ?? 0, $gone['images'] ?? 0, $gone['files'] ?? 0),
                LOG_WARNING, ['library' => $slug]);
            flash('ok', sprintf('%s deleted — %d entr%s, %d image%s and %d upload%s gone.',
                $name,
                $gone['entries'] ?? 0, ($gone['entries'] ?? 0) === 1 ? 'y' : 'ies',
                $gone['images'] ?? 0,  ($gone['images'] ?? 0) === 1 ? '' : 's',
                $gone['files'] ?? 0,   ($gone['files'] ?? 0) === 1 ? '' : 's'));
            redirect('/manage/libraries');
        }

        // Delete. Refused while it still holds anything, exactly as the owner's own
        // delete is: an administrator pressing a button should not be a way to destroy
        // a collection faster than its owner could.
        $entries = (int) scalar('SELECT COUNT(*) FROM items WHERE library_id = ? AND deleted_at IS NULL', [(int) $lib['id']]);
        if ($entries > 0) {
            flash('error', sprintf(
                '%s still holds %d entr%s. Move or delete them first, or disable the '
                . 'library instead - that hides it without losing anything.',
                (string) $lib['name'], $entries, $entries === 1 ? 'y' : 'ies'));
            redirect('/manage/libraries');
        }

        $name = (string) $lib['name'];
        delete_row('libraries', (int) $lib['id']);
        log_security('library.deleted', sprintf('Library "%s" removed', $name),
            LOG_WARNING, ['library' => (string) $lib['slug']]);
        flash('ok', $name . ' removed.');
        redirect('/manage/libraries');
    }

    // --- Membership ---
    if ($action === 'grant' || $action === 'revoke') {
        if ($id === null) {
            redirect('/libraries');
        }
        $library = one('SELECT * FROM libraries WHERE id = ?', [$id]);
        if ($library === null) {
            flash('error', 'No such library.');
            redirect('/libraries');
        }

        // An owner manages their own library. An administrator may add
        // themselves, which is recorded rather than hidden.
        $targetId = input_int('user_id');
        $selfGrant = $targetId === (int) $user['id'];
        if (!can_own_library($id) && !(is_admin() && $selfGrant)) {
            flash('error', 'Only the owner of that library can change who is in it.');
            redirect('/libraries', ['edit' => $id]);
        }

        if ($action === 'revoke') {
            if ($targetId === (int) $library['owner_id']) {
                flash('error', 'The owner cannot be removed. Transfer the library first.');
                redirect('/libraries', ['edit' => $id]);
            }
            q('DELETE FROM library_members WHERE library_id = ? AND user_id = ?', [$id, $targetId]);
            log_security('library.access.revoked',
                sprintf('Access to "%s" removed from %s', $library['name'],
                        (string) scalar('SELECT username FROM users WHERE id = ?', [$targetId])),
                LOG_NOTICE, ['subject_type' => 'library', 'subject_id' => $id]);
            flash('ok', 'Access removed.');
            redirect('/libraries', ['edit' => $id]);
        }

        // Owner is not grantable, whatever arrives in the request.
        //
        // Taking the select out of the form is where somebody notices; refusing it here
        // is what makes it true, because a form is a suggestion and a POST is not. A
        // library has one owner and it changes by being offered and accepted.
        $level = (string) input('access', ACCESS_VIEWER);
        if (!in_array($level, [ACCESS_VIEWER, ACCESS_CONTRIBUTOR, ACCESS_EDITOR, ACCESS_CURATOR, ACCESS_ADMIN], true)) {
            $level = ACCESS_VIEWER;
        }
        // A personal library can be shown to people but not written to by them.
        // Anything else and it stops being personal; that is what a shared
        // library is for.
        if ((int) ($library['is_personal'] ?? 0) === 1 && $targetId !== (int) $library['owner_id']) {
            $level = ACCESS_VIEWER;
        }
        if ($level === ACCESS_OWNER && !can_own_library($id)) {
            $level = ACCESS_ADMIN;   // only an owner may appoint another owner
        }

        // Only a shared library can have anybody else in it.
        if (($library['kind'] ?? 'private') !== 'public' && !$selfGrant) {
            flash('error', 'This library is private. Make it shared before inviting anyone.');
            redirect('/libraries', ['edit' => $id]);
        }

        // An invitation is an offer. It sits pending and confers nothing until
        // the person it names accepts, so nobody wakes up in somebody else's
        // library - or is made responsible for one they never agreed to.
        //
        // Two exceptions, both of them somebody acting on themselves: the owner
        // setting up their own library, and an administrator granting
        // themselves access, which is recorded rather than hidden.
        $immediate = $selfGrant || $targetId === (int) $library['owner_id'];
        $status    = $immediate ? 'accepted' : 'pending';

        // Changing the level of somebody already in does not re-ask them.
        $current = one('SELECT status FROM library_members WHERE library_id = ? AND user_id = ?',
                       [$id, $targetId]);
        if ($current !== null && $current['status'] === 'accepted') {
            $status = 'accepted';
        }

        q('INSERT INTO library_members (library_id, user_id, access, status, granted_by, note)
           VALUES (?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE access = VALUES(access), status = VALUES(status),
                                   granted_by = VALUES(granted_by), note = VALUES(note),
                                   responded_at = NULL',
          [$id, $targetId, $level, $status, (int) $user['id'],
           $selfGrant && is_admin() && !can_own_library($id)
               ? 'Administrator granted themselves access'
               : null]);

        $GLOBALS['__membership_cache'] = [];
        log_security('library.access.granted',
            sprintf('%s given %s on "%s"%s',
                    (string) scalar('SELECT username FROM users WHERE id = ?', [$targetId]),
                    access_label($level), $library['name'],
                    $status === 'pending' ? ' (invitation pending)' : ''),
            LOG_NOTICE, ['subject_type' => 'library', 'subject_id' => $id, 'access' => $level]);

        if ($status === 'pending') {
            notify($targetId, 'library.invited', [
                'subject'      => sprintf('%s invited you to %s',
                                          $user['display_name'] ?: $user['username'], $library['name']),
                'body'         => sprintf(
                    "You have been invited to the library \"%s\" as %s.\n\n"
                    . "Nothing has changed yet - an invitation gives no access until you accept it. "
                    . "You can accept or decline it from your profile.",
                    $library['name'], access_label($level)
                ),
                'link_path'    => '/profile',
                'subject_type' => 'library',
                'subject_id'   => $id,
                // One notice per library per person, however many times the
                // invitation is re-sent or the level changed.
                'dedupe_key'   => 'library.invited:' . $id,
            ]);
        } elseif (!$selfGrant && $current !== null) {
            notify($targetId, 'library.access_changed', [
                'subject'      => sprintf('Your access to %s is now %s', $library['name'], access_label($level)),
                'link_path'    => '/libraries',
                'subject_type' => 'library',
                'subject_id'   => $id,
            ]);
        }

        flash('ok', $status === 'pending'
            ? 'Invitation sent. They will see it next time they sign in, and nothing changes until they accept.'
            : 'Access updated.');
        redirect('/libraries', ['edit' => $id]);
    }

    // --- Answering an invitation ---------------------------------------------
    //
    // The only actions here that the person performing them is not required to
    // own anything for: it is their own answer to somebody else's offer.
    if ($action === 'accept' || $action === 'decline') {
        $invite = $id === null ? null : one(
            "SELECT * FROM library_members
              WHERE library_id = ? AND user_id = ? AND status = 'pending'",
            [$id, (int) $user['id']]
        );
        if ($invite === null) {
            flash('error', 'No invitation waiting for you there.');
            redirect('/profile');
        }

        q('UPDATE library_members SET status = ?, responded_at = NOW()
            WHERE library_id = ? AND user_id = ?',
          [$action === 'accept' ? 'accepted' : 'declined', $id, (int) $user['id']]);

        $GLOBALS['__membership_cache'] = [];
        $name = (string) scalar('SELECT name FROM libraries WHERE id = ?', [$id]);

        // Tell whoever asked. An invitation nobody answers and nobody hears
        // about is worse than not sending one.
        if ($invite['granted_by'] !== null) {
            notify((int) $invite['granted_by'], 'library.invite_answered', [
                'subject'      => sprintf('%s %s your invitation to %s',
                                          $user['display_name'] ?: $user['username'],
                                          $action === 'accept' ? 'accepted' : 'declined', $name),
                'link_path'    => '/libraries?edit=' . $id,
                'subject_type' => 'library',
                'subject_id'   => $id,
            ]);
        }
        flash('ok', $action === 'accept'
            ? 'You are now a member of ' . $name . '.'
            : 'Invitation to ' . $name . ' declined.');
        redirect($action === 'accept' ? '/libraries' : '/profile');
    }

    // --- A library's own machines ---
    //
    // Only the owner, and only their own library: a platform belongs to whoever
    // defined it, and the shared ones belong to the instance.
    if ($action === 'platform_add' || $action === 'platform_remove') {
        if ($id === null || !can_own_library($id)) {
            flash('error', 'Only the owner of that library can change its machines.');
            redirect('/libraries', ['edit' => $id]);
        }

        if ($action === 'platform_remove') {
            $platformId = input_int('platform_id');
            $owned = $platformId === null ? null
                : one('SELECT * FROM platforms WHERE id = ? AND library_id = ?', [$platformId, $id]);
            if ($owned === null) {
                flash('error', 'That machine is not this library\'s to remove.');
                redirect('/libraries', ['edit' => $id]);
            }
            $inUse = (int) scalar('SELECT COUNT(*) FROM items WHERE platform_id = ?', [$platformId]);
            if ($inUse > 0) {
                flash('error', sprintf(
                    '%d %s still filed under %s. Move them first.',
                    $inUse, $inUse === 1 ? 'entry is' : 'entries are', $owned['name']
                ));
                redirect('/libraries', ['edit' => $id]);
            }
            delete_row('platforms', $platformId);
            flash('ok', $owned['name'] . ' removed.');
            redirect('/libraries', ['edit' => $id]);
        }

        $name = trim((string) input('platform_name', ''));
        if ($name === '') {
            form_failed('/libraries', ['platform_name' => 'Give the machine a name.'], ['edit' => $id]);
        }

        insert_row('platforms', [
            'library_id'   => $id,
            'name'         => mb_substr($name, 0, 120),
            'slug'         => unique_slug('platforms', slugify($name)),
            // A row from this library's own makers, not the word typed again.
            'vendor_id'    => (function () use ($id) {
                $v = input_int('platform_vendor_id');
                if ($v === null || $v <= 0) {
                    return null;
                }
                return one('SELECT id FROM companies WHERE id = ? AND library_id = ?', [$v, $id]) === null ? null : $v;
            })(),
            'year_introduced' => input_int('platform_year'),
            'accent_color' => '#a6adc8',
        ]);

        log_server('platform.created', 'Machine "' . $name . '" added to a library', LOG_INFO,
                   ['subject_type' => 'library', 'subject_id' => $id]);
        flash('ok', $name . ' added to this library.');
        redirect('/libraries', ['edit' => $id]);
    }

    // --- Delete ---
    if ($action === 'delete' && $id !== null) {
        if (!can_own_library($id)) {
            flash('error', 'Only the owner can delete a library.');
            redirect('/libraries');
        }
        $lib = one('SELECT is_personal FROM libraries WHERE id = ?', [$id]);
        if ($lib !== null && (int) $lib['is_personal'] === 1) {
            flash('error', 'A personal library cannot be deleted. It is where your own '
                . 'things live, and every account has exactly one.');
            redirect('/libraries', ['edit' => $id]);
        }
        $count = (int) scalar('SELECT COUNT(*) FROM items WHERE library_id = ? AND deleted_at IS NULL', [$id]);
        if ($count > 0) {
            flash('error', 'That library still holds ' . $count . ' entries. Move or delete them first — '
                . 'deleting a library should never be a way to lose a collection by accident.');
            redirect('/libraries', ['edit' => $id]);
        }
        if ((int) scalar('SELECT COUNT(*) FROM libraries') <= 1) {
            flash('error', 'That is the only library. Create another before deleting this one.');
            redirect('/libraries', ['edit' => $id]);
        }
        delete_row('libraries', $id);
        flash('ok', 'Library deleted.');
        redirect('/libraries');
    }

    // --- Create or update ---
    $name = trim((string) input('name', ''));
    if ($name === '') {
        form_failed('/libraries', ['name' => 'Give the library a name.']);
    }

    // A personal library is the shelf every account is guaranteed to have, and
    // guaranteeing it means guaranteeing it stays yours. The form does not offer
    // to change it; this is here so a hand-made request cannot either.
    $existing = $id === null ? null : one('SELECT * FROM libraries WHERE id = ?', [$id]);
    $personal = (int) ($existing['is_personal'] ?? 0) === 1;

    $kind = (!$personal && input('kind') === 'public') ? 'public' : 'private';

    // Turning a library private is not a quiet act: it takes access away from
    // everyone who has it. Refusing is kinder than doing it and telling them
    // afterwards, and it is one click to undo.
    if ($kind === 'private' && $existing !== null && ($existing['kind'] ?? '') === 'public') {
        $others = (int) scalar(
            'SELECT COUNT(*) FROM library_members WHERE library_id = ? AND user_id <> ?',
            [$id, (int) ($existing['owner_id'] ?? 0)]
        );
        if ($others > 0) {
            flash('error', sprintf(
                'Remove the other %s first — making this private would take their access away.',
                $others === 1 ? 'member' : "$others members"
            ));
            redirect('/libraries', ['edit' => $id]);
        }
    }

    // Only a shared library can be open to everybody, and it is always
    // read-only to join: anyone signed in may become a Viewer, and
    // nothing more, through the general join button. A higher role for
    // a specific person is still possible - the same invitation
    // mechanism a private library already uses grants any level up to
    // Administrator, unaffected by this. public_write used to let a
    // library grant Contributor to everyone who joins; removed rather
    // than merely hidden, since a column nobody can set but that old
    // rows may still carry is a trap for the next person reading this.
    $publicWrite = 0;
    $publicRead  = $kind === 'public' && !empty($_POST['public_read']) ? 1 : 0;
    $restrict   = input_int('restrict_to_platform_id');

    $data = [
        'name'        => mb_substr($name, 0, 160),
        'description' => nullify(input('description')),
        'kind'         => $kind,
        'public_read'  => $publicRead,
        'public_write' => $publicWrite,
        'restrict_to_platform_id' => $restrict !== null && $restrict > 0 ? $restrict : null,
        'accent_color' => preg_match('/^#[0-9a-f]{6}$/i', (string) input('accent_color', ''))
                          ? (string) input('accent_color') : '#cba6f7',
        'sort_order'  => (int) (input_int('sort_order') ?? 100),
    ];

    if ($id === null) {
        $data['slug']     = unique_slug('libraries', slugify($name));
        $data['owner_id'] = (int) $user['id'];
        $newId = insert_row('libraries', $data);
        // Copied, not shared: renaming your Amiga must not rename everybody's.
        seed_library_hardware((int) $newId);
        q('INSERT IGNORE INTO library_members (library_id, user_id, access, granted_by) VALUES (?, ?, ?, ?)',
          [$newId, (int) $user['id'], ACCESS_OWNER, (int) $user['id']]);
        flash('ok', $name . ' created. You are its owner.');
        redirect('/libraries', ['edit' => $newId]);
    }

    if (!can_own_library($id)) {
        flash('error', 'Only the owner can change that library.');
        redirect('/libraries');
    }
    $data['slug'] = unique_slug('libraries', slugify($name), $id);
    update_row('libraries', $id, $data);
    flash('ok', $name . ' saved.');
    redirect('/libraries', ['edit' => $id]);
}

// --- Email verification -----------------------------------------------------

function auth_verify_email(): void
{
    [$ok, $message] = verify_email_token((string) input('token', ''));
    flash($ok ? 'ok' : 'error', $message);
    redirect('/login');
}

/**
 * Another link, for somebody who never got the first one.
 *
 * Throttled by username the same way signing in is, and it answers the same
 * whether or not the account exists: an unauthenticated form that says "no such
 * account" is a way to find out who has one.
 */
function auth_verify_resend(): void
{
    csrf_verify();
    $username = trim((string) input('username', ''));

    [$allowed, $wait] = throttle_check($username);
    if (!$allowed) {
        flash('error', throttle_message($wait));
        redirect('/login');
    }

    $user = $username === '' ? null : one('SELECT * FROM users WHERE username = ?', [$username]);
    if ($user !== null && needs_email_verification($user)) {
        send_verification_email((int) $user['id']);
    }

    flash('ok', 'If that account needs confirming, another link is on its way.');
    redirect('/login');
}

// ---------------------------------------------------------------------------
// Creating and editing a library, as two separate pages
//
// The old management screen did both at once, plus the member list, the delete
// box and a table of every library on the instance - so "make a new shelf" and
// "rename this one" were the same form wearing different labels, and the
// difference between them was a hidden id. They are different jobs: creating a
// library decides what it starts out holding, which is a decision you make once
// and can never make again; editing one does not.
//
// What a library holds is its own, all of it - makers, platforms, machine and
// peripheral models, and the places things live. Populating it is therefore a
// property of the library rather than an instance-wide setting, and belongs on
// these two pages.
// ---------------------------------------------------------------------------

/**
 * The public flag, from one choice.
 *
 * Returns [publicRead, publicWrite] - publicWrite is always 0 now.
 * "Public" always means read-only to join: anyone signed in becomes a
 * Viewer, nothing more, through the general join button. A higher role
 * for a specific person still goes through the same invitation
 * mechanism a private library already uses, which grants any level up
 * to Administrator and is entirely separate from this. The write half
 * of this pair used to let a library grant Contributor to everyone who
 * joins; removed rather than merely made unreachable, since a caller
 * that still sent 'public_write' deserves the same answer 'public'
 * gives now, not a silently different one.
 */
function library_visibility_flags(string $kind, string $visibility): array
{
    if ($kind !== 'public') {
        return [0, 0];
    }
    return match ($visibility) {
        'public', 'public_write' => [1, 0],
        default                  => [0, 0],
    };
}

/** The create page. Deliberately not the edit page with an empty row. */
function library_new_form(): void
{
    if (current_user() === null) {
        redirect('/login');
    }
    render('auth/library_new', [
        'pageTitle' => 'Create a library',
        // Whether the templates are worth offering at all. An instance whose
        // structure data never loaded has nothing to copy, and offering to copy
        // nothing is worse than saying so.
        'structureCounts' => library_structure_counts(),
    ]);
}

/** How much there is to copy, so the create page can say so honestly. */
function library_structure_counts(): array
{
    return [
        'vendors'   => (int) scalar('SELECT COUNT(*) FROM companies   WHERE library_id IS NULL'),
        'platforms' => (int) scalar('SELECT COUNT(*) FROM platforms WHERE library_id IS NULL'),
        'models'    => (int) scalar("SELECT COUNT(*) FROM hardware_models hm
                                       JOIN categories c ON c.id = hm.category_id AND c.role = 'machine'
                                      WHERE hm.library_id IS NULL"),
        'parts'     => (int) scalar("SELECT COUNT(*) FROM hardware_models hm
                                       JOIN categories c ON c.id = hm.category_id AND c.role <> 'machine'
                                      WHERE hm.library_id IS NULL"),
    ];
}

/**
 * Make one. The only place that decides what a new library starts out holding.
 */
function library_create(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('/login');
    }
    csrf_verify();

    $name = trim((string) input('name', ''));
    if ($name === '') {
        form_failed('/libraries/new', ['name' => 'Give the library a name.']);
    }
    if (mb_strlen($name) > 120) {
        $name = mb_substr($name, 0, 120);
    }

    $kind = input('kind') === 'public' ? 'public' : 'private';
    // One control decides both flags, so they cannot disagree: public-write without
    // public-read is not a state a library can meaningfully be in. A private library
    // ignores them entirely.
    [$publicRead, $publicWrite] = library_visibility_flags($kind, (string) input('visibility', 'members'));

    $newId = (int) insert_row('libraries', [
        'name'         => $name,
        'slug'         => unique_slug('libraries', slugify($name)),
        'description'  => nullify(input('description')),
        'kind'         => $kind,
        'owner_id'     => (int) $user['id'],
        'public_read'  => $publicRead,
        'public_write' => $publicWrite,
        'accent_color' => preg_match('/^#[0-9a-f]{6}$/i', (string) input('accent_color', ''))
                          ? (string) input('accent_color') : '#cba6f7',
        'is_personal'  => 0,
    ]);

    q('INSERT IGNORE INTO library_members (library_id, user_id, access, granted_by) VALUES (?, ?, ?, ?)',
      [$newId, (int) $user['id'], ACCESS_OWNER, (int) $user['id']]);
    $GLOBALS['__membership_cache'] = [];

    // What it starts with. "Empty" is a real answer and the reason this page
    // exists: a library that arrives already knowing sixty-three platforms is
    // wrong for somebody cataloguing one machine.
    // Asking for the structure means asking for the current structure, so the fetch
    // is part of it rather than a second tick that can be forgotten.
    $wantStructure = input_bool('with_structure') === 1;
    $notes = library_populate($newId, [
        'refresh'  => $wantStructure,
        'structure'=> $wantStructure,
        'examples' => input_bool('with_examples') === 1,
    ]);

    log_server('library.created', 'Library "' . $name . '" created', LOG_INFO,
               ['subject_type' => 'library', 'subject_id' => $newId]);
    // And you are working in it.
    //
    // Making a library is how somebody says which shelf they are about to fill,
    // and leaving the selector pointed at the old one meant the next entry went
    // to the wrong place - or the person noticed and changed it by hand, having
    // just told the application the same thing a moment earlier.
    // The key working_library() actually reads.
    //
    // I set 'library_id' last time, which nothing looks at - so the selector went
    // on showing the old shelf and the fix appeared to do nothing. A session key
    // spelled two ways is indistinguishable from a session key that does not
    // work.
    $_SESSION['working_library'] = (int) $newId;
    $GLOBALS['__membership_cache'] = [];

    flash('ok', $name . ' created. You are its owner, and now working in it.'
        . ($notes === '' ? '' : ' ' . $notes));
    redirect('/libraries/' . $newId . '/edit');
}

/**
 * Copy the template structure, and optionally the examples, into one library.
 *
 * Additive by construction: seed_library_hardware() skips anything already there
 * by slug, so this is also the resync. Returns a sentence for the flash, because
 * "done" is not an answer when the question was "did it fetch anything".
 */
function library_populate(int $libraryId, array $want): string
{
    $said = [];

    if (!empty($want['refresh'])) {
        // Straight from the repository, so a library made today gets today's
        // machines rather than whatever shipped in the tarball. Failures are
        // reported and not fatal: the copy below still has the local set to work
        // from, which is the whole point of structure_read()'s fallback.
        [$summary, $errors] = structure_sync(true);
        $added = array_sum(array_column($summary, 'added')) + array_sum(array_column($summary, 'updated'));
        $failed = array_sum(array_column($summary, 'failed'));
        $said[] = $failed > 0
            ? sprintf('Refreshed the templates with %d change(s) and %d failure(s).', $added, $failed)
            : sprintf('Refreshed the templates from the repository (%d change(s)).', $added);
        foreach (array_slice($errors, 0, 3) as $e) {
            flash('error', $e);
        }
    }

    if (!empty($want['structure'])) {
        seed_library_hardware($libraryId, !empty($want['overwrite']),
                              $want['parts'] ?? null);
        $said[] = sprintf(
            'Copied in %d platform(s), %d maker(s) and %d model(s).',
            (int) scalar('SELECT COUNT(*) FROM platforms WHERE library_id = ?', [$libraryId]),
            (int) scalar('SELECT COUNT(*) FROM companies   WHERE library_id = ?', [$libraryId]),
            (int) scalar('SELECT COUNT(*) FROM hardware_models WHERE library_id = ?', [$libraryId])
        );
    }

    if (!empty($want['examples'])) {
        // The examples point at this library's own models, so the structure has to
        // be there first. Doing it anyway rather than refusing: somebody who asks
        // for examples and not structure has asked for something incoherent, and
        // the kind thing is to give them the coherent version.
        if (empty($want['structure'])) {
            seed_library_hardware($libraryId);
        }
        $made = seed_library_examples($libraryId);
        $said[] = $made > 0
            ? sprintf('Added %d example entr%s.', $made, $made === 1 ? 'y' : 'ies')
            : 'The examples were already there, so nothing was added.';
    }

    if ($said === [] ) {
        return 'It starts out empty.';
    }
    return implode(' ', $said);
}

/** The edit page for one library. */
function library_edit_form(int $id): void
{
    if (current_user() === null) {
        redirect('/login');
    }
    $library = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($library === null) {
        not_found('No library with that id.');
    }
    // A curator can open this: the members panel is theirs to work in. The
    // owner-only parts of the page are the settings and the deletion, and the
    // handler behind it refuses those separately - a curator who could not even
    // reach the page could not manage members at all, which is the one thing the
    // level is described as granting.
    // Members are the admin's business now, not the curator's: arranging the
    // vocabulary and deciding who is in the library are different jobs, which is
    // the whole point of the level being split.
    $mayCurateHere = can_administer_library($id) || is_admin();
    if (!can_own_library($id) && !$mayCurateHere) {
        flash('error', 'Only an owner can change that library.');
        redirect('/collection');
    }

    // The maintenance jobs that are about one library, run here rather than on the
    // server's page: this is where somebody is already looking at the library they
    // concern, and they belong to whoever holds it rather than to an administrator.
    $maintJobs    = maintenance_jobs_for('library', (int) $library['id']);
    $maintResults = [];
    foreach (array_keys($maintJobs) as $mk) {
        $maintResults[$mk] = maintenance_run_check($mk, (int) $library['id']);
    }

    render('auth/library_edit', [
        'pageTitle' => 'Edit ' . $library['name'],
        'library'   => $library,
        // What this library holds of the structure set, beside what the set
        // holds. The same function for both, so the labels cannot disagree.
        //
        // It used to be on the instance settings page, counting the structure
        // rows against the files - one set of numbers for the whole instance,
        // when the question people have is whether a particular library is
        // behind.
        'structureRows' => structure_row_counts(),
        'libraryRows'    => structure_row_counts($id),
        'maintJobs'    => $maintJobs,
        'maintResults' => $maintResults,
        // What it currently holds, per kind. The point of the page is that all of
        // this belongs to this library and nothing else.
        'holds'     => [
            'entries'   => (int) scalar('SELECT COUNT(*) FROM items WHERE library_id = ? AND deleted_at IS NULL AND deleted_at IS NULL', [$id]),
            'platforms' => (int) scalar('SELECT COUNT(*) FROM platforms WHERE library_id = ?', [$id]),
            'vendors'   => (int) scalar('SELECT COUNT(*) FROM companies   WHERE library_id = ?', [$id]),
            'models'    => (int) scalar("SELECT COUNT(*) FROM hardware_models hm
                                           JOIN categories c ON c.id = hm.category_id AND c.role = 'machine'
                                          WHERE hm.library_id = ?", [$id]),
            'parts'     => (int) scalar("SELECT COUNT(*) FROM hardware_models hm
                                           JOIN categories c ON c.id = hm.category_id AND c.role <> 'machine'
                                          WHERE hm.library_id = ?", [$id]),
            'locations' => (int) scalar('SELECT COUNT(*) FROM locations WHERE library_id = ?', [$id]),
        ],
        'structureCounts' => library_structure_counts(),
        'members'   => all(
            "SELECT m.*, u.username, u.display_name, u.email, u.avatar_filename,
                    g.username AS granted_by_name
               FROM library_members m
               JOIN users u ON u.id = m.user_id
          LEFT JOIN users g ON g.id = m.granted_by
              WHERE m.library_id = ? ORDER BY m.access DESC, u.username", [$id]
        ),
        // Active accounts not already in it, narrowed by whatever was typed. An empty
        // search lists them all, so the box is a filter rather than a gate - on an
        // instance with four accounts, making somebody search first would be
        // ceremony. Inactive accounts are never offered: inviting somebody who cannot
        // sign in is a message nobody receives.
        'memberQuery' => trim((string) ($_GET['member_q'] ?? '')),
        'invitable' => (function () use ($id) {
            $q    = trim((string) ($_GET['member_q'] ?? ''));
            $sql  = 'SELECT u.id, u.username, u.display_name, u.email
                       FROM users u
                  LEFT JOIN library_members m ON m.library_id = ? AND m.user_id = u.id
                      WHERE u.is_active = 1 AND m.user_id IS NULL AND u.id <> ?';
            $args = [$id, (int) current_user()['id']];
            if ($q !== '') {
                $sql   .= ' AND (u.username LIKE ? OR u.display_name LIKE ? OR u.email LIKE ?)';
                $like   = '%' . $q . '%';
                $args[] = $like;
                $args[] = $like;
                $args[] = $like;
            }
            return all($sql . ' ORDER BY u.username LIMIT 50', $args);
        })(),
        'grantable' => library_grantable_levels($library),
    ]);
}

/**
 * Which access levels this library may hand out.
 *
 * A private library invites readers and nothing more. Writing to somebody's private
 * shelf is what a shared library is for, and offering "contributor" on a private one
 * would make the two kinds the same thing with different labels.
 *
 * @param array $library the row, so the caller cannot pass an id and get it wrong
 * @return string[]
 */
/**
 * The levels an invitation may carry. Never owner.
 *
 * A library has exactly one owner, and it changes by being handed over and accepted -
 * see library_offer_ownership(). Inviting somebody as owner would have made a second
 * one, or silently displaced the first, depending on which row you read afterwards:
 * libraries.owner_id says one thing and the membership row another, and acl.php trusts
 * whichever it was asked. Two owners is not a state this should be able to reach from a
 * dropdown.
 */
/**
 * The levels an invitation to this library may grant.
 *
 * One rule, in acl.php, because this is an access decision and that is where
 * access decisions live. This used to answer it here and on `kind`: only a
 * public library could grant above viewer, so a private library shared with
 * three people could never make any of them an editor - which is backwards. A
 * private library is the one whose membership is deliberate.
 *
 * What actually caps it is `is_personal`: somebody's own shelf, made with their
 * account and impossible to delete, invites at viewer and no higher. Every other
 * library, private or public, goes up to admin.
 */
function library_grantable_levels(array $library): array
{
    return library_invitable_levels((int) $library['id']);
}

/** Save one library's own settings, or resync its structure. */
function library_edit_save(int $id): void
{
    if (current_user() === null) {
        redirect('/login');
    }
    csrf_verify();

    $library = one('SELECT * FROM libraries WHERE id = ?', [$id]);
    if ($library === null) {
        not_found('No library with that id.');
    }
    // Owners, and curators for the part that is theirs.
    //
    // Managing members is curator's work - inviting somebody, changing what they
    // may do, showing them out - while the library's own settings, its deletion
    // and handing it over stay with the owner. Gated on ownership alone, a
    // curator could not do the one thing the level is described as granting.
    $memberActions = ['invite', 'uninvite', 'member_access'];
    $isMemberAction = in_array((string) input('action', ''), $memberActions, true);
    $mayCurate = can_administer_library($id) || is_admin();

    if (!can_own_library($id) && !($isMemberAction && $mayCurate)) {
        flash('error', can_own_library($id)
            ? 'Only an owner can change that library.'
            : 'Only an owner can change that library. Curators can manage its members.');
        redirect('/collection');
    }

    $back = '/libraries/' . $id . '/edit';
    $me   = (int) current_user()['id'];

    // --- Members ----------------------------------------------------------
    //
    // An invitation is an offer: it lands as 'pending' and confers nothing until the
    // person it names accepts. That is what stops somebody adding your account to
    // their library and having it start reading theirs.
    if (input('action') === 'invite') {
        $who   = input_int('user_id');
        $level = (string) input('access', ACCESS_VIEWER);
        $allowed = library_grantable_levels($library);

        if ($who === null || $who === $me) {
            flash('error', 'Choose somebody to invite.');
            redirect($back);
        }
        $account = one('SELECT id, username FROM users WHERE id = ? AND is_active = 1', [$who]);
        if ($account === null) {
            flash('error', 'No active account with that id.');
            redirect($back);
        }
        if (!in_array($level, $allowed, true)) {
            flash('error', ($library['kind'] ?? 'private') === 'public'
                ? 'That is not a level this library hands out.'
                : 'A private library invites readers only. Make it shared to let somebody add to it.');
            redirect($back);
        }
        q("INSERT INTO library_members (library_id, user_id, access, status, granted_by)
           VALUES (?, ?, ?, 'pending', ?)
           ON DUPLICATE KEY UPDATE access = VALUES(access), granted_by = VALUES(granted_by)",
          [$id, $who, $level, $me]);
        // The same notification the older grant screen sends, so an invitation made
        // here arrives the same way and answers in the same place.
        notify($who, 'library.invited', [
            'subject'      => sprintf('%s invited you to %s',
                                      current_user()['display_name'] ?: current_user()['username'],
                                      $library['name']),
            'body'         => sprintf(
                "You have been invited to the library \"%s\" as %s.\n\n"
                . "Nothing has changed yet - an invitation gives no access until you accept it. "
                . "You can accept or decline it from your profile.",
                $library['name'], access_label($level)
            ),
            'link_path'    => '/profile',
            'subject_type' => 'library',
            'subject_id'   => $id,
            'dedupe_key'   => 'library.invited:' . $id,
        ]);
        log_server('library.invited', sprintf('%s invited to "%s" as %s',
                   $account['username'], $library['name'], access_label($level)), LOG_INFO,
                   ['subject_type' => 'library', 'subject_id' => $id]);
        flash('ok', $account['username'] . ' invited as ' . strtolower(access_label($level))
            . '. Nothing changes until they accept.');
        redirect($back);
    }

    if (input('action') === 'member_access') {
        $memberId = input_int('user_id');
        $want     = (string) input('access', '');
        if ($memberId === null || !in_array($want, access_levels(), true) || $want === ACCESS_NONE) {
            flash('error', 'That is not a level this library grants.');
            redirect($back);
        }
        // Never the owner. An owner who could be demoted from that table could be
        // locked out of their own library by a curator they invited; handing it
        // over is its own act, not a dropdown.
        if ($memberId === (int) $library['owner_id']) {
            flash('error', "The owner's own access is not set from here.");
            redirect($back);
        }
        // And a curator may not make somebody an owner - that is a transfer, and
        // transfers belong to the person who owns the thing.
        $meIsOwner = (int) $library['owner_id'] === (int) current_user()['id'] || is_admin();
        if ($want === ACCESS_OWNER && !$meIsOwner) {
            flash('error', 'Only the owner can hand the library to somebody else.');
            redirect($back);
        }
        $row = one('SELECT * FROM library_members WHERE library_id = ? AND user_id = ?',
                   [$id, $memberId]);
        if ($row === null || (string) $row['status'] !== 'accepted') {
            flash('error', 'That person has not accepted yet, so there is nothing to change.');
            redirect($back);
        }

        q('UPDATE library_members SET access = ? WHERE library_id = ? AND user_id = ?',
          [$want, $id, $memberId]);
        $GLOBALS['__membership_cache'] = [];
        log_security('library.access', sprintf('access in library %d set to %s', $id, $want),
                     LOG_NOTICE, ['library' => $id, 'user' => $memberId, 'access' => $want]);
        flash('ok', 'Access updated.');
        redirect($back);
    }

    if (input('action') === 'uninvite') {
        $who = input_int('user_id');
        if ($who === null) {
            redirect($back);
        }
        if ($who === (int) $library['owner_id']) {
            flash('error', 'The owner cannot be removed from their own library.');
            redirect($back);
        }
        $name = (string) (scalar('SELECT username FROM users WHERE id = ?', [$who]) ?? 'They');
        q('DELETE FROM library_members WHERE library_id = ? AND user_id = ?', [$id, $who]);
        $GLOBALS['__membership_cache'] = [];
        log_server('library.uninvited', sprintf('%s removed from "%s"', $name, $library['name']),
                   LOG_NOTICE, ['subject_type' => 'library', 'subject_id' => $id]);
        flash('ok', $name . ' no longer has access.');
        redirect($back);
    }

    // --- Delete -----------------------------------------------------------
    //
    // Here rather than on another screen, because this is the page somebody manages
    // a library from. Behind the name typed by hand: a library takes its platforms,
    // makers, models, places and entries with it, and a button you can reach with one
    // mis-click is not enough for that. The same rules the older screen applied still
    // apply - owner only, never a personal library, never the last one, and never one
    // that still holds entries.
    if (input('action') === 'delete') {
        // The owner, and only where the instance allows deleting at all.
        //
        // Two gates rather than one: an instance can decide that libraries are never
        // removed - disabling takes one out of circulation reversibly and loses nothing
        // - and where they can be, it is the owner's call rather than any curator's.
        if (!libraries_may_be_deleted()) {
            flash('error', 'Libraries cannot be deleted on this instance. Disable it '
                . 'instead — that hides it from everyone without losing what is on it.');
            redirect($back);
        }
        if (!may_delete_library(current_user(), $id)) {
            flash('error', 'Only the owner can delete this library.');
            redirect($back);
        }

        $typed = trim((string) input('confirm_name', ''));
        if ($typed !== (string) $library['name']) {
            flash('error', 'Type the library\'s name exactly to delete it. Nothing was changed.');
            redirect($back);
        }
        if ((int) ($library['is_personal'] ?? 0) === 1) {
            flash('error', 'A personal library cannot be deleted. It is where your own things '
                . 'live, and every account has exactly one.');
            redirect($back);
        }
        $held = (int) scalar('SELECT COUNT(*) FROM items WHERE library_id = ? AND deleted_at IS NULL', [$id]);
        if ($held > 0) {
            flash('error', sprintf(
                'It still holds %d entr%s. Move or delete those first — deleting a library '
                . 'should never be a way to lose a collection by accident.',
                $held, $held === 1 ? 'y' : 'ies'));
            redirect($back);
        }
        if ((int) scalar('SELECT COUNT(*) FROM libraries') <= 1) {
            flash('error', 'That is the only library. Create another before deleting this one.');
            redirect($back);
        }
        delete_row('libraries', $id);
        $GLOBALS['__membership_cache'] = [];
        log_server('library.deleted', 'Library "' . $library['name'] . '" deleted', LOG_NOTICE,
                   ['subject_type' => 'library', 'subject_id' => $id]);
        flash('ok', $library['name'] . ' deleted, with its platforms, makers, models and places.');
        redirect('/collection');
    }

    // Resync: the same copy the create page performs, run again. Additive, so it
    // adds what is missing and leaves alone anything renamed or edited.
    if (input('action') === 'resync') {
        // What was ticked. An empty list means the person cleared every box, which is a
        // request to copy nothing rather than an omission to be helpfully corrected -
        // so it is honoured, and the summary says nothing was copied.
        $picked = $_POST['parts'] ?? [];
        $parts  = [];
        foreach (array_keys(seed_parts_all()) as $key) {
            $parts[$key] = is_array($picked) && in_array($key, $picked, true);
        }

        $notes = library_populate($id, [
            'refresh'   => true,
            'structure' => in_array(true, $parts, true),
            'parts'     => $parts,
            // Ticked on the form, and off unless it is. An existing row is assumed to
            // have been edited on purpose.
            'overwrite' => input('overwrite') === '1',
            // Examples are opt-in here too. Dropping four entries into a shelf somebody
            // curates is exactly what a resync should not do by surprise.
            'examples'  => input('with_examples') === '1',
        ]);
        log_server('library.resynced', 'Library "' . $library['name'] . '" resynced from templates', LOG_INFO,
                   ['subject_type' => 'library', 'subject_id' => $id]);
        flash('ok', $notes);
        redirect($back);
    }

    $name = trim((string) input('name', ''));
    if ($name === '') {
        form_failed($back, ['name' => 'Give the library a name.']);
    }

    // A personal library cannot become shared: it is the one shelf the account is
    // promised, and sharing it would hand somebody else the only place its owner
    // can always write to.
    $personal = (int) ($library['is_personal'] ?? 0) === 1;
    // Read the posted value both ways round. This used to keep the existing kind
    // unless 'public' was posted, which meant a shared library could never be made
    // private again: the form offered the choice and the save quietly ignored half
    // of it. A personal library is always private, and that is the only clamp.
    $kind = $personal ? 'private' : (input('kind') === 'public' ? 'public' : 'private');
    if ($personal && input('kind') === 'public') {
        flash('error', 'A personal library cannot be shared. Make a shared one instead.');
        redirect($back);
    }

    [$publicRead, $publicWrite] = library_visibility_flags($kind, (string) input('visibility', 'members'));

    // Unpublishing turns the joiners out.
    //
    // Somebody who joined is there because the library was open to anyone signed in.
    // Close it and that reason is gone - leaving them in would mean the library says
    // "members only" while a dozen people who were never invited still read it, and
    // they could not rejoin if they ever left, which is a strange half-state to be in.
    //
    // Only the joiners. An invitation somebody accepted is a person the owner chose,
    // and publishing was never what let them in - which is the rule the form already
    // states: an accepted invitation always wins over this.
    $wasPublic = (int) ($library['public_read'] ?? 0) === 1
              || (int) ($library['public_write'] ?? 0) === 1;
    if ($wasPublic && $publicRead === 0 && $publicWrite === 0) {
        $turnedOut = (int) scalar(
            "SELECT COUNT(*) FROM library_members
              WHERE library_id = ? AND note = 'Joined a published library'
                AND user_id <> ?",
            [$id, (int) ($library['owner_id'] ?? 0)]
        );
        if ($turnedOut > 0) {
            q("DELETE FROM library_members
                WHERE library_id = ? AND note = 'Joined a published library'
                  AND user_id <> ?",
              [$id, (int) ($library['owner_id'] ?? 0)]);
            $GLOBALS['__membership_cache'] = [];
            log_security('library.unpublished',
                sprintf('"%s" closed; %d joined member(s) removed',
                        (string) $library['name'], $turnedOut),
                LOG_WARNING, ['library' => (string) $library['slug'], 'removed' => $turnedOut]);
            flash('ok', sprintf(
                '%d %s who had joined can no longer reach it. People you invited are unaffected.',
                $turnedOut, $turnedOut === 1 ? 'person' : 'people'));
        }
    }

    // Going from shared to private demotes anybody who could write. A private
    // library hands out reading only, so leaving a contributor in place would mean
    // the kind said one thing and the membership another - and the membership is
    // what acl.php actually enforces. The owner keeps their level.
    if ($kind === 'private' && ($library['kind'] ?? '') === 'public') {
        $demoted = (int) scalar(
            'SELECT COUNT(*) FROM library_members
              WHERE library_id = ? AND user_id <> ? AND access <> ?',
            [$id, (int) $library['owner_id'], ACCESS_VIEWER]);
        if ($demoted > 0) {
            q('UPDATE library_members SET access = ?
                WHERE library_id = ? AND user_id <> ? AND access <> ?',
              [ACCESS_VIEWER, $id, (int) $library['owner_id'], ACCESS_VIEWER]);
            $GLOBALS['__membership_cache'] = [];
            flash('ok', sprintf('%d member%s dropped to read-only, which is all a private library grants.',
                                $demoted, $demoted === 1 ? '' : 's'));
        }
    }

    update_row('libraries', $id, [
        'name'         => mb_substr($name, 0, 120),
        'slug'         => unique_slug('libraries', slugify($name), $id),
        'description'  => nullify(input('description')),
        'kind'         => $kind,
        'public_read'  => $publicRead,
        'public_write' => $publicWrite,
        'accent_color' => preg_match('/^#[0-9a-f]{6}$/i', (string) input('accent_color', ''))
                          ? (string) input('accent_color') : (string) $library['accent_color'],
    ]);

    log_server('library.updated', 'Library "' . $name . '" changed', LOG_INFO,
               ['subject_type' => 'library', 'subject_id' => $id]);
    flash('ok', $name . ' saved.');
    redirect($back);
}
// --- Maintenance ------------------------------------------------------------

/**
 * The maintenance screen.
 *
 * Checks run on load. They only read, and a page that made you press a button to
 * find out whether anything was wrong would mostly be pressed by people who
 * already suspected something.
 */
function maintenance_index(): void
{
    // Administrators only, and instance jobs only.
    //
    // The library jobs were here too, behind a picker, which put "the whole
    // database" and "this shelf of mine" on one page under one heading. They are
    // in the library editor now, where somebody is already looking at the library
    // they are about.
    require_admin();

    $instance = maintenance_jobs_for('instance');
    $results  = [];
    foreach (array_keys($instance) as $key) {
        $results[$key] = maintenance_run_check($key);
    }

    render('auth/maintenance', [
        'pageTitle' => 'Instance Status',
        'instance'  => $instance,
        'results'   => $results,
    ]);
}

/** Run one repair, having checked the person may. */
function maintenance_run(): void
{
    if (current_user() === null) {
        flash('error', 'Sign in to run maintenance.');
        redirect('/login');
    }
    csrf_verify();

    $key   = (string) input('job', '');
    $jobs  = maintenance_jobs();
    $libId = input_int('library');

    if (!isset($jobs[$key]) || $jobs[$key]['repair'] === null) {
        flash('error', 'No such repair.');
        redirect('/manage/maintenance');
    }
    $job = $jobs[$key];

    // The same test the list uses, applied again here. A form is not a
    // permission: this route is reachable without ever having seen the page.
    if ($job['scope'] === 'instance') {
        require_admin();
    } elseif ($libId === null
              || access_rank(library_access(acting_user(), $libId)) < access_rank((string) $job['access'])) {
        flash('error', 'That is not yours to repair.');
        redirect('/manage/maintenance');
    }

    $fn  = $job['repair'];
    $out = $job['scope'] === 'library' ? $fn((int) $libId) : $fn();

    log_security('maintenance.run', sprintf('Ran "%s"%s: %s', $job['label'],
                 $libId === null ? '' : ' on library ' . $libId, $out['message']),
                 LOG_NOTICE, $libId === null ? [] : ['subject_type' => 'library', 'subject_id' => $libId]);

    flash(!empty($out['done']) ? 'ok' : 'error', $job['label'] . ' — ' . $out['message']);
    // Back where it was pressed: the server page for an instance job, the library
    // editor for a library one.
    redirect($job['scope'] === 'library' && $libId !== null
        ? '/libraries/' . $libId . '/edit'
        : '/manage/maintenance');
}

/**
 * A candidate image, fetched through this server so it can be shown.
 *
 * The review screen offered artwork as a row of tick boxes with nothing above
 * them - blank lines where the pictures should be - and the reason is the
 * content policy: `img-src 'self' data: blob:` refuses a remote address, which is
 * the whole point of it. Loosening that to allow any host would mean every page
 * on the instance could load an image from anywhere, to decide one thing on one
 * screen.
 *
 * So the bytes come through here instead. Same origin, so the policy is happy,
 * and the request goes out through metadata_http_get() with the address checks
 * already in it rather than through a second fetcher written for this.
 *
 * A preview only. Ticking the box still downloads the file properly, through the
 * upload checks, exactly as before.
 */
function metadata_preview(): void
{
    // Signed in, and only where the lookup itself is reachable. This turns the
    // server into a fetcher of arbitrary URLs for whoever can call it, so it is
    // not open to anonymous callers.
    if (current_user() === null || !any_metadata_provider()) {
        http_response_code(404);
        exit;
    }

    $url = (string) input('url', '');
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)
        || preg_match('#^https?://#i', $url) !== 1) {
        http_response_code(400);
        exit;
    }

    [$body, $err] = metadata_http_get($url, ['Accept: image/*'], 10);
    if ($body === null || $body === '') {
        http_response_code(502);
        exit;
    }

    // What it is, decided from the bytes rather than from the response header or
    // the file extension. A source that answers an image request with HTML gets
    // an error, not a page rendered inside an img tag.
    $info = @getimagesizefromstring($body);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        http_response_code(415);
        exit;
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($body));
    header('X-Content-Type-Options: nosniff');
    // Long enough that ticking boxes and re-rendering does not re-fetch, short
    // enough that it is not a cache anybody has to think about.
    header('Cache-Control: private, max-age=600');
    echo $body;
    exit;
}
