<?php
/**
 * The three ways in, all through one pair of functions.
 *
 * A route decides only which door was knocked on; registration_allowed() decides
 * whether it opens. Keeping that in one place is what stops the secret address
 * still working after somebody switches the instance back to closed.
 */
declare(strict_types=1);

/** The form, whichever door it was reached through. */
function registration_form(string $route, string $token = ''): void
{
    if (acting_user() !== null) {
        redirect('/');
    }
    [$ok, $whatOrWhy] = registration_allowed($route, $token);
    if (!$ok) {
        // The same answer for a wrong secret, a closed instance and an address
        // nobody ever issued. Telling them apart tells somebody probing which
        // one they are looking at.
        not_found((string) $whatOrWhy);
    }

    // Always, whatever the site-wide setting says.
    //
    // The meta tag was already unconditional here; the header was not - it is
    // sent from send_security_headers() and only when the whole instance has
    // asked to stay out of search results. An instance that welcomes crawlers
    // still has no business having its sign-up address indexed, and the secret
    // one least of all.
    header('X-Robots-Tag: noindex, nofollow, noarchive');

    render('auth/register', [
        'pageTitle' => 'Create an account',
        'route'     => $route,
        'token'     => $token,
        'invite'    => is_array($whatOrWhy) ? $whatOrWhy : null,
        // Every one of these pages says no to crawlers in the markup as well as
        // in robots.txt: robots.txt is a request, and a page that should not be
        // indexed should say so where it cannot be ignored quite so easily.
        'noindex'   => true,
    ]);
}

/** And the account it makes. */
function registration_submit(string $route, string $token = ''): void
{
    csrf_verify();
    if (acting_user() !== null) {
        redirect('/');
    }
    [$ok, $whatOrWhy] = registration_allowed($route, $token);
    if (!$ok) {
        not_found((string) $whatOrWhy);
    }
    $invite = is_array($whatOrWhy) ? $whatOrWhy : null;

    $back = match ($route) {
        'join'   => '/join/' . $token,
        'invite' => '/invite/' . $token,
        default  => '/register',
    };

    $username = (string) input('username', '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');
    // On an invitation the address is the one that was invited, not one the
    // person types: an invitation to a@example is not an invitation for whoever
    // holds the link to sign up as b@example.
    $email    = $invite !== null
        ? (string) $invite['email']
        : trim((string) input('email', ''));

    // Keyed by field, and handed to form_failed().
    //
    // These were a flat list flashed one at a time before a bare redirect, so
    // mistyping the second password emptied the username, the display name and
    // the email as well - everything retyped to fix one thing. form_failed()
    // already keeps what was typed and drops the passwords, which is exactly the
    // behaviour wanted here; this handler simply was not using it.
    $errors = [];
    if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
        $errors['username'] = 'Username can use letters, numbers, dot, dash and underscore, '
                            . '3 to 64 characters.';
    } elseif (one('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
        $errors['username'] = 'That username is taken.';
    }
    if (strlen($password) < 10) {
        $errors['password'] = 'Use a password of at least 10 characters.';
    } elseif ($password !== $confirm) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }
    // Not on an invitation: the address is the one that was invited, and there is
    // no field for it to be wrong in.
    if ($invite === null && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $errors['email'] = 'An email address is required, and that one does not look like one.';
    }
    if ($errors !== []) {
        form_failed($back, $errors);
    }

    try {
        // 'user', never 'admin'. The first account is an administrator because
        // somebody has to be; the twentieth is not, whatever door it came in by.
        $id = create_user($username, $password,
                          (string) input('display_name', $username), 'user', $email);
    } catch (InvalidArgumentException $e) {
        form_failed($back, ['username' => $e->getMessage()]);
    }

    if ($invite !== null) {
        invite_redeem((int) $invite['id'], (int) $id);
    }

    log_security('register.created',
        sprintf('%s created an account by %s', $username, $route), LOG_NOTICE,
        ['user' => $id, 'route' => $route]);

    // What the instance does with a new account: let it in, ask for the address
    // to be confirmed, or hold it for an administrator.
    $waitFor = registration_apply_approval((int) $id);
    if ($waitFor !== '') {
        log_security('register.pending',
            sprintf('%s signed up and is waiting (%s)', $username, registration_approval()),
            LOG_NOTICE, ['user' => $id]);

        // Told, not only logged. The log entry above has existed the whole
        // time; every admin who was not reading it that day found out when
        // somebody asked why they still could not sign in.
        notify_admins('registration.pending', [
            'subject'      => sprintf('%s is waiting for approval', $username),
            'body'         => sprintf('Signed up %s. %s', date('j M Y, H:i'), registration_approval()),
            'link_path'    => '/manage/users',
            'subject_type' => 'user',
            'subject_id'   => $id,
        ]);

        flash('ok', $waitFor);
        redirect('/login');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    flash('ok', 'Welcome. Start by adding a library, then your first entry.');
    redirect('/');
}

/** robots.txt, served rather than stored. */
function robots_serve(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    // Crawlers cache this hard, and an instance that has just switched itself to
    // private should not wait a week for that to take effect.
    header('Cache-Control: no-store');
    echo robots_txt();
}

/**
 * A health check a proxy can believe.
 *
 * Every other address on this application either needs a session or redirects
 * to one, so a check pointed at "/" got a 303 and concluded the backend was
 * down. That is a proxy answering 503 in front of a server that is working.
 *
 * 200 when this instance can actually serve a page, 503 when it cannot. The
 * difference is the database: PHP running happily in front of a database it
 * cannot reach is not a healthy instance, and a check that says otherwise is
 * worse than no check.
 *
 * Nothing about the instance is disclosed - a version or a table count would
 * make this a reconnaissance endpoint, and the only thing a checker needs is
 * the status line.
 */
function health_serve(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    // What a checker may ask with, so an OPTIONS probe is answered rather than
    // guessed at.
    header('Allow: GET, HEAD, OPTIONS');

    // An instance that has not been installed yet is not unhealthy.
    //
    // It can serve: it can serve the installer, which is the only page it has
    // any business serving. Reporting 503 here marks the backend DOWN, and a
    // proxy in front then refuses every request - including the one that would
    // have reached /install.php. So unpacking this behind a proxy left you
    // unable to install it, which is a trap of my own making.
    //
    // The distinction is whether there is an installer to reach. A configured
    // instance whose database has gone is a real outage; an unconfigured one
    // with an installer present is a job half done, and the proxy should keep
    // sending traffic so somebody can finish it.
    if (!app_is_configured()) {
        if (is_file(installer_path())) {
            http_response_code(200);
            echo "setup\n";
            return;
        }
        http_response_code(503);
        echo "unconfigured\n";
        return;
    }

    try {
        // Its own connection, with its own short timeout.
        //
        // Going through db() would be neater but it does not return on failure:
        // it sets 500 and renders an error page, so the 503 below could never be
        // reached and a checker was told "internal error" when the honest answer
        // is "not ready". Three seconds, because a check that waits five is a
        // check that has already timed out.
        $c   = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                       $c['host'], (int) $c['port'], $c['name']);
        $probe = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        // The cheapest question that proves the connection and a real table.
        $probe->query('SELECT 1 FROM settings LIMIT 1');
    } catch (Throwable $e) {
        http_response_code(503);
        // Logged, because "the proxy says 503" is where somebody starts and
        // this is the line that tells them which half is broken.
        error_log('[retrohive] health check failed: ' . $e->getMessage());
        echo "unavailable\n";
        return;
    }

    http_response_code(200);
    echo "ok\n";
}
