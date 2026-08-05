<?php
/**
 * Why can this account not see a library it joined?
 *
 * Reads only. Run it on the instance where the problem is, because the answer is almost
 * always about that database rather than about the code:
 *
 *   php bin/diagnose-join.php <username> <library-slug>
 *
 * It checks, in order, the things that have to be true for a joined library to appear:
 * the membership row exists and is accepted, the library is active, and the ACL agrees.
 * Whichever line says "no" is the reason.
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
define('BASE_PATH', '');
foreach (['helpers','proxy','db','auth','throttle','acl','log','structure','notify',
          'ldap','metadata','version','migrate','images','models','api'] as $f) {
    require APP_ROOT . '/src/' . $f . '.php';
}

$who  = $argv[1] ?? '';
$slug = $argv[2] ?? '';
if ($who === '' || $slug === '') {
    fwrite(STDERR, "usage: php bin/diagnose-join.php <username> <library-slug>\n");
    exit(2);
}

$user = one('SELECT * FROM users WHERE username = ?', [$who]);
$lib  = one('SELECT * FROM libraries WHERE slug = ?', [$slug]);
printf("account  : %s\n", $user === null ? "NOT FOUND" : $who . ' (id ' . $user['id'] . ')');
printf("library  : %s\n", $lib === null ? "NOT FOUND" : $slug . ' (id ' . $lib['id'] . ')');
if ($user === null || $lib === null) { exit(1); }

$id = (int) $lib['id']; $uid = (int) $user['id'];

// The columns added late. An instance whose schema was never reloaded will not have
// them, and every query that filters on one fails.
foreach (['is_active', 'pending_owner_id'] as $col) {
    $has = (int) scalar(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'libraries' AND column_name = ?", [$col]);
    printf("  libraries.%-16s %s\n", $col, $has ? 'present' : 'MISSING - reload db/schema.sql');
}

$m = one('SELECT * FROM library_members WHERE library_id = ? AND user_id = ?', [$id, $uid]);
printf("  membership row          %s\n", $m === null ? 'MISSING - the join did not write' : 'present');
if ($m !== null) {
    printf("    access                %s\n", $m['access']);
    printf("    status                %s%s\n", $m['status'],
        $m['status'] === 'accepted' ? '' : '  <- only "accepted" grants anything');
}
printf("  library is_active       %s\n", (int) ($lib['is_active'] ?? 1) === 1 ? 'yes' : 'NO - disabled, so it is hidden');
printf("  published to read       %s\n", (int) $lib['public_read'] === 1 ? 'yes' : 'no');

set_acting_user($user);
$GLOBALS['__membership_cache'] = [];
unset($GLOBALS['__public_libraries']);
$ids = array_map('intval', accessible_library_ids($user, ACCESS_VIEWER));
printf("  ACL grants access       %s\n", in_array($id, $ids, true) ? 'yes' : 'NO');
printf("  appears in the switcher %s\n",
    in_array($id, array_map(fn($l) => (int) $l['id'], joined_libraries()), true) ? 'yes' : 'NO');
