<?php
declare(strict_types=1);

/**
 * What is left of the account and library screens after the engine's own web
 * interface was removed.
 *
 * Five functions, kept for three different reasons:
 *
 *   auth_setup / auth_setup_form  first run, before there is anybody to
 *                                 authenticate - the one screen that cannot sit
 *                                 behind a sign-in
 *   library_populate              seeding a library, called by the API
 *   library_visibility_flags      the kind-to-flags rule, shared with the API
 *   user_grants                   what one account may reach, shared with the
 *                                 admin API
 *
 * `library_grantable_levels()` lived here too and now lives in acl.php, beside
 * the other rules about who may be granted what.
 */

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

function auth_setup_form(): void
{
    if (user_count() > 0) {
        redirect('/login');
    }
    render('auth/setup', ['pageTitle' => 'First run', 'bare' => true]);
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

/** [libraryId => access] for one account, read from the membership table. */
function user_grants(int $userId): array
{
    $out = [];
    foreach (all('SELECT library_id, access FROM library_members WHERE user_id = ?', [$userId]) as $row) {
        $out[(int) $row['library_id']] = (string) $row['access'];
    }
    return $out;
}
