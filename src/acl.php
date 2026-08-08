<?php
declare(strict_types=1);

/**
 * Who can see and change what.
 *
 * Two things decide it, deliberately independent of each other:
 *
 *   1. The instance role. 'admin' may configure the system - authentication,
 *      metadata sources, accounts, shared taxonomy. 'user' may not. That is the
 *      whole of what the role governs.
 *
 *   2. Membership of a library. A library is the thing somebody owns or shares;
 *      a platform is only the machine an entry runs on. What a person calls
 *      their collection is everything they can reach across every library,
 *      which is a view rather than a table.
 *
 * An administrator gets no automatic sight of a private library. They can grant
 * themselves membership in one step, and that grant records who made it, so it
 * stays visible afterwards. Pretending the system could stop someone holding
 * shell and database access would be a comfortable lie; making such access
 * deliberate and legible is worth more than pretending to prevent it.
 *
 * Every item query funnels through library_filter_sql(), so the rule lives in
 * one place rather than being repeated across twenty controllers.
 */

// Ordered weakest to strongest; access_rank() depends on this order.
const ACCESS_NONE        = 'none';
const ACCESS_VIEWER      = 'viewer';
const ACCESS_CONTRIBUTOR = 'contributor';
// Editing anyone's entries and arranging the vocabulary they are filed under are
// different jobs, and so are arranging that vocabulary and deciding who is in the
// library at all. Six levels because there are six answers, not because more is
// better: each new one is a thing somebody asked to delegate without delegating
// the next thing up.
const ACCESS_EDITOR      = 'editor';
const ACCESS_CURATOR     = 'curator';
const ACCESS_ADMIN       = 'admin';
const ACCESS_OWNER       = 'owner';

function access_levels(): array
{
    return [ACCESS_NONE, ACCESS_VIEWER, ACCESS_CONTRIBUTOR, ACCESS_EDITOR,
            ACCESS_CURATOR, ACCESS_ADMIN, ACCESS_OWNER];
}

function access_rank(string $level): int
{
    $rank = array_flip(access_levels());
    return $rank[$level] ?? 0;
}

function access_label(string $level): string
{
    return [
        // "Library" on the front of each, because this instance has an admin of
        // its own and a plain "Admin" beside a plain "Admin" is two different
        // powers wearing one word. An instance administrator configures the
        // server; a Library Admin decides who is on one shelf.
        ACCESS_NONE        => 'No access',
        ACCESS_VIEWER      => 'Library Viewer',
        ACCESS_CONTRIBUTOR => 'Library Contributor',
        ACCESS_EDITOR      => 'Library Editor',
        ACCESS_CURATOR     => 'Library Curator',
        ACCESS_ADMIN       => 'Library Admin',
        ACCESS_OWNER       => 'Library Owner',
    ][$level] ?? $level;
}

function access_description(string $level): string
{
    return [
        ACCESS_VIEWER      => 'Read entries and photos.',
        ACCESS_CONTRIBUTOR => 'Add entries, and edit or delete the ones they added.',
        // What a curator may do about people is the part worth spelling out: they
        // manage members, and they cannot touch an owner. Otherwise "manage
        // members" reads as though it included the person who owns the shelf.
        ACCESS_EDITOR      => 'Add entries, and edit or delete anyone\'s.',
        ACCESS_CURATOR     => 'Everything an editor can, plus the data structures — locations, '
                            . 'companies, platforms, categories, models and environments.',
        ACCESS_ADMIN       => 'Everything a curator can, plus members and the library\'s '
                            . 'maintenance jobs — but not owners, and not the owner\'s own '
                            . 'membership.',
        ACCESS_OWNER       => 'Everything an admin can, plus the library itself: its settings, '
                            . 'disabling or deleting it, and handing it to another member.',
    ][$level] ?? '';
}

// ---------------------------------------------------------------------------
// Who is asking
//
// A web session and an API token arrive by different routes but must be judged
// identically, so both set the acting user and everything below reads it.
// ---------------------------------------------------------------------------

function set_acting_user(?array $user): void
{
    $GLOBALS['__acting_user']     = $user;
    $GLOBALS['__acting_user_set'] = true;
    // Memberships are memoised per account; a change of identity invalidates it.
    $GLOBALS['__membership_cache'] = [];
}

function acting_user(): ?array
{
    if (!empty($GLOBALS['__acting_user_set'])) {
        return $GLOBALS['__acting_user'];
    }
    return function_exists('current_user') ? current_user() : null;
}

function is_admin_user(?array $user): bool
{
    return ($user['role'] ?? '') === 'admin';
}

// ---------------------------------------------------------------------------
// Library membership
// ---------------------------------------------------------------------------

/**
 * [libraryId => access] for one account, memoised per request.
 *
 * Accepted memberships only. An invitation is an offer: until the person it
 * names says yes it confers nothing, and counting it here would mean somebody
 * could put you in their library and have your account start reading it.
 */
function user_memberships(int $userId): array
{
    if (!isset($GLOBALS['__membership_cache'])) {
        $GLOBALS['__membership_cache'] = [];
    }
    if (!isset($GLOBALS['__membership_cache'][$userId])) {
        $out = [];
        foreach (all(
            "SELECT library_id, access FROM library_members
              WHERE user_id = ? AND status = 'accepted'", [$userId]
        ) as $row) {
            $out[(int) $row['library_id']] = (string) $row['access'];
        }
        $GLOBALS['__membership_cache'][$userId] = $out;
    }
    return $GLOBALS['__membership_cache'][$userId];
}

/** Invitations waiting on this account's answer. */
function pending_invitations(?int $userId = null): array
{
    $user = $userId === null ? acting_user() : ['id' => $userId];
    if ($user === null) {
        return [];
    }
    return all(
        "SELECT m.*, l.name AS library_name, l.description, l.accent_color,
                u.username AS invited_by, u.display_name AS invited_by_name
           FROM library_members m
           JOIN libraries l ON l.id = m.library_id
      LEFT JOIN users u ON u.id = m.granted_by
          WHERE m.user_id = ? AND m.status = 'pending'
          ORDER BY m.granted_at DESC",
        [(int) $user['id']]
    );
}

/** Libraries marked public, readable by every signed-in account. */
/**
 * Shared libraries opened to everyone signed in, by level.
 *
 * Only a shared library can be open: the flags exist on every row but a private
 * library ignores them, so turning a library private closes it whatever the
 * flags happen to say. Belt and braces, because the alternative is a library
 * that reads "private" and is not.
 */
function public_library_ids(string $level = ACCESS_VIEWER): array
{
    if (!isset($GLOBALS['__public_libraries'])) {
        $rows = all("SELECT id, public_read, public_write FROM libraries WHERE kind = 'public'");
        $read = [];
        $write = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if ((int) $row['public_write'] === 1) {
                $write[] = $id;
                $read[]  = $id;   // writing implies reading
            } elseif ((int) $row['public_read'] === 1) {
                $read[] = $id;
            }
        }
        $GLOBALS['__public_libraries'] = ['read' => $read, 'write' => $write];
    }
    return access_rank($level) >= access_rank(ACCESS_CONTRIBUTOR)
        ? $GLOBALS['__public_libraries']['write']
        : $GLOBALS['__public_libraries']['read'];
}

/**
 * What this account may do in one library.
 *
 * An explicit membership wins. Failing that, a public library is readable.
 * Nothing else grants anything, including being an administrator.
 */
function library_access(?array $user, int $libraryId): string
{
    if ($user === null) {
        return ACCESS_NONE;
    }
    // An accepted membership wins, whatever the public flags say - somebody
    // invited as a curator does not lose that when the library is opened to
    // everyone as read-only.
    $memberships = user_memberships((int) $user['id']);
    if (isset($memberships[$libraryId])) {
        return $memberships[$libraryId];
    }

    // Failing that, whatever the library offers to everyone signed in.
    if (in_array($libraryId, public_library_ids(ACCESS_CONTRIBUTOR), true)) {
        return ACCESS_CONTRIBUTOR;
    }
    if (in_array($libraryId, public_library_ids(ACCESS_VIEWER), true)) {
        return ACCESS_VIEWER;
    }

    return ACCESS_NONE;
}

function can_read_library(int $libraryId): bool
{
    return access_rank(library_access(acting_user(), $libraryId)) >= access_rank(ACCESS_VIEWER);
}

/** May add entries here. */
function can_add_to_library(int $libraryId): bool
{
    return access_rank(library_access(acting_user(), $libraryId)) >= access_rank(ACCESS_CONTRIBUTOR);
}

/** May change anything here, not only their own additions. */
/**
 * May this person edit anything in the library, not only what they added?
 *
 * Editor, not curator. The two used to be the same level; they are not the same
 * job - fixing somebody else's entry is ordinary work in a shared shelf, while
 * rearranging the categories everything is filed under is not - and this
 * function is asked the first question everywhere it is called.
 *
 * The name is kept because thirty call sites ask it, and renaming them in the
 * same change that moves the boundary would make both harder to check.
 */
function can_curate_library(int $libraryId): bool
{
    return access_rank(library_access(acting_user(), $libraryId)) >= access_rank(ACCESS_EDITOR);
}

/** May this person arrange the library's vocabulary - locations, companies, models? */
function can_structure_library(int $libraryId): bool
{
    return access_rank(library_access(acting_user(), $libraryId)) >= access_rank(ACCESS_CURATOR);
}

/** May this person decide who is in the library, and run its maintenance? */
function can_administer_library(int $libraryId): bool
{
    return access_rank(library_access(acting_user(), $libraryId)) >= access_rank(ACCESS_ADMIN);
}

function can_own_library(int $libraryId): bool
{
    return access_rank(library_access(acting_user(), $libraryId)) >= access_rank(ACCESS_OWNER);
}

// ---------------------------------------------------------------------------
// Turning that into SQL
// ---------------------------------------------------------------------------

/**
 * Library ids reachable at the given level. An empty array means "nothing",
 * which callers must render as an empty result rather than as no filter.
 */
function accessible_library_ids(?array $user, string $level = ACCESS_VIEWER): array
{
    if ($user === null) {
        return [];
    }

    $wanted = access_rank($level);
    $ids    = [];

    // Disabled shelves are reachable by nobody, whatever their memberships say.
    //
    // Enforced here rather than at each caller: there are a dozen places that ask what
    // an account may read, and a library taken out of circulation has to disappear from
    // all of them or it is not out of circulation.
    static $off = null;
    if ($off === null) {
        $off = array_map('intval', array_column(
            all('SELECT id FROM libraries WHERE is_active = 0'), 'id'
        ));
    }

    foreach (user_memberships((int) $user['id']) as $libraryId => $access) {
        if (access_rank($access) >= $wanted) {
            $ids[] = $libraryId;
        }
    }

    // Whatever shared libraries offer to everyone signed in, at the level
    // being asked about.
    if ($wanted <= access_rank(ACCESS_CONTRIBUTOR)) {
        foreach (public_library_ids($level) as $libraryId) {
            $ids[] = $libraryId;
        }
    }

    return array_values(array_diff(array_unique($ids), $off));
}

/**
 * The clause every item query carries. One enforcement point, so a new screen
 * cannot forget the rule.
 *
 * Returns [sql, params]. The sql is always a usable boolean expression, and is
 * '1 = 0' when the account can reach nothing.
 */
function library_filter_sql(string $column = 'library_id', string $level = ACCESS_VIEWER): array
{
    // The ids this returns are library ids. Applying them to any other column
    // silently authorises the wrong rows rather than failing, which is exactly
    // what happened when half the API filtered on platform_id: both are small
    // auto-increment integers, so they collide constantly and a member of
    // library 2 could read every item on platform 2 in every library.
    //
    // Refusing beats guessing. A qualified name is fine; the column it names
    // is not.
    $bare = str_contains($column, '.') ? (string) substr(strrchr($column, '.') ?: '', 1) : $column;
    if ($bare !== 'library_id') {
        throw new LogicException(
            "library_filter_sql() only filters library_id, but was given '$column'. "
            . 'Access is decided by library membership; a platform grants nothing.'
        );
    }

    $ids = accessible_library_ids(acting_user(), $level);
    if ($ids === []) {
        return ['1 = 0', []];
    }
    return [$column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}


/**
 * Libraries this account can reach, for pickers and filters.
 *
 * No domain filter any more: a library holds whatever its entries' categories
 * say it holds, so one library can carry both a shelf of disks and the machines
 * that read them.
 */
/**
 * The shelves this account has actually taken on: its own, and the ones it joined.
 *
 * Distinct from readable_libraries(), and the distinction is the point. Publishing a
 * library grants permission to read it; it does not put it on somebody's shelf. A
 * published library used to appear in everybody's switcher the moment it existed, which
 * reads as having been added to something you never agreed to - and on an instance with
 * a few of them, the switcher becomes a directory of other people's collections.
 *
 * Permission is unchanged: anyone signed in may still read a published library, by link
 * or through the API. This is only about what is *yours* to switch between.
 */
function joined_library_ids(?array $user = null): array
{
    $user ??= acting_user();
    if ($user === null) {
        return [];
    }
    return array_map('intval', array_keys(user_memberships((int) $user['id'])));
}

/** The rows for those, in the order a menu wants them. */
function joined_libraries(): array
{
    $ids = joined_library_ids();
    if ($ids === []) {
        return [];
    }
    // Disabled shelves drop out here too.
    //
    // This reads memberships directly rather than going through the ACL, so the
    // exclusion there did not cover it - and the switcher is exactly where a library
    // taken out of circulation must not appear.
    return all('SELECT * FROM libraries WHERE is_active = 1 AND id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY is_personal DESC, name', $ids);
}


// ---------------------------------------------------------------------------
// Ownership
//
// One owner per library, held in libraries.owner_id. The membership row saying
// 'owner' is a consequence of that, not a second opinion - so these functions are
// the only place that decides, and everything else asks them.
// ---------------------------------------------------------------------------

/**
 * May libraries be deleted on this instance at all?
 *
 * Off by default. Deleting takes the entries with it and cannot be undone, and on a
 * shared instance the person pressing the button is rarely the only one who cared about
 * what was on that shelf. Disabling is always available and loses nothing.
 */
function libraries_may_be_deleted(): bool
{
    // setting(), not config(): this is an instance switch an administrator flips, and
    // config() reads the file on disk - which would have made the toggle inert.
    return (string) setting('libraries.deletable', '0') === '1';
}

/** The one account responsible for a library. */
function library_owner_id(int $libraryId): int
{
    $row = one('SELECT owner_id FROM libraries WHERE id = ?', [$libraryId]);
    return $row === null ? 0 : (int) ($row['owner_id'] ?? 0);
}

function is_library_owner(?array $user, int $libraryId): bool
{
    return $user !== null && (int) $user['id'] === library_owner_id($libraryId);
}

/**
 * Who may remove a library outright.
 *
 * The owner, and only if the instance allows it. Not an administrator: they can disable
 * any library, which takes it out of circulation reversibly, and that is the right tool
 * for somebody acting on a shelf that is not theirs. A personal shelf is never
 * deletable from here - it is the one place its owner always has.
 */
function may_delete_library(?array $user, int $libraryId): bool
{
    if (!libraries_may_be_deleted()) {
        return false;
    }
    $lib = one('SELECT owner_id, is_personal FROM libraries WHERE id = ?', [$libraryId]);
    if ($lib === null || (int) $lib['is_personal'] === 1) {
        return false;
    }
    return $user !== null && (int) $user['id'] === (int) ($lib['owner_id'] ?? 0);
}

/**
 * The accounts a library could be handed to.
 *
 * Members who have accepted, and not the current owner. Somebody who has not joined has
 * not agreed to be in the library at all, so offering them responsibility for it skips
 * a step - they have to be invited and accept first, and then they appear here.
 */
function library_transfer_candidates(int $libraryId): array
{
    return all(
        "SELECT u.id, u.username, u.display_name, m.access
           FROM library_members m
           JOIN users u ON u.id = m.user_id
          WHERE m.library_id = ? AND m.status = 'accepted' AND m.access <> 'owner'
            AND u.is_active = 1
       ORDER BY u.username",
        [$libraryId]
    );
}

/**
 * The highest access an invitation to this library may grant.
 *
 * A personal library is somebody's own shelf, made for them when the account was
 * made and impossible to delete. Sharing it is a courtesy - "come and look at
 * what I have" - and not a handover: an invitation there grants viewer and
 * nothing more, so nobody but its owner can ever change what is on it.
 *
 * Every other library, private or public, may invite at any level up to admin.
 * Owner is never on that list anywhere: a library has exactly one, and it moves
 * by an offer that has to be accepted rather than by an invitation.
 *
 * Enforced here rather than in the two screens that offer the list, because a
 * ceiling that lives in a dropdown is a ceiling that a second client, or a
 * crafted request, does not have.
 */
function library_invite_ceiling(int $libraryId): string
{
    $personal = (int) scalar('SELECT is_personal FROM libraries WHERE id = ?', [$libraryId]);
    return $personal === 1 ? ACCESS_VIEWER : ACCESS_ADMIN;
}

/** The levels an invitation to this library may actually offer. */
function library_invitable_levels(int $libraryId): array
{
    $ceiling = library_invite_ceiling($libraryId);
    $out = [];
    foreach ([ACCESS_VIEWER, ACCESS_CONTRIBUTOR, ACCESS_EDITOR, ACCESS_CURATOR, ACCESS_ADMIN] as $lvl) {
        $out[] = $lvl;
        if ($lvl === $ceiling) {
            break;
        }
    }
    return $out;
}

/**
 * The levels an invitation to this library may grant, taking a row.
 *
 * A thin shape around library_invitable_levels() above, kept because the API
 * calls it with a library row in hand. It lived in the own-UI controller until
 * that was removed - it was the one function in there the API genuinely needed,
 * and a rule about who may be granted what belongs beside the other rules about
 * who may do what.
 */
function library_grantable_levels(array $library): array
{
    return library_invitable_levels((int) $library['id']);
}

/** Public shelves this account could join, and has not. */
function joinable_libraries(): array
{
    $joined = joined_library_ids();
    // `kind` alone. public_read used to be a second switch beside it, so a
    // library could be public and not joinable - which made "public" mean
    // nothing on its own. The two are one answer now.
    $sql    = "SELECT * FROM libraries
                WHERE kind = 'public' AND is_active = 1";
    $args   = [];
    if ($joined !== []) {
        $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($joined), '?')) . ')';
        $args = $joined;
    }
    return all($sql . ' ORDER BY name', $args);
}

function readable_libraries(string $level = ACCESS_VIEWER): array
{
    $ids = accessible_library_ids(acting_user(), $level);
    if ($ids === []) {
        return [];
    }
    return all('SELECT * FROM libraries WHERE id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY name', $ids);
}

// ---------------------------------------------------------------------------
// Per-entry decisions
// ---------------------------------------------------------------------------

function can_read_item(array $item): bool
{
    return can_read_library((int) $item['library_id']);
}

/**
 * Editing an entry.
 *
 * A curator may change anything. A contributor may change what they added,
 * which is the level that makes a shared club library workable: people can
 * catalogue their own donations without being able to rewrite somebody else's
 * condition grades.
 */
function can_write_item(array $item): bool
{
    $libraryId = (int) $item['library_id'];

    if (can_curate_library($libraryId)) {
        return true;
    }
    if (!can_add_to_library($libraryId)) {
        return false;
    }

    $user = acting_user();
    return $user !== null
        && ($item['created_by'] ?? null) !== null
        && (int) $item['created_by'] === (int) $user['id'];
}

function can_delete_item(array $item): bool
{
    // Same rule as editing: removing your own mistake is part of contributing.
    return can_write_item($item);
}

/** Whether to offer "Add" at all. */
function can_edit_anything(): bool
{
    return accessible_library_ids(acting_user(), ACCESS_CONTRIBUTOR) !== [];
}

// ---------------------------------------------------------------------------
// Descriptions, for the interface
// ---------------------------------------------------------------------------

function access_summary(?array $user): string
{
    if ($user === null) {
        return 'Not signed in.';
    }

    $readable = accessible_library_ids($user, ACCESS_VIEWER);
    $writable = accessible_library_ids($user, ACCESS_CONTRIBUTOR);

    if ($readable === []) {
        return 'No libraries yet. An administrator or a library owner can add you to one.';
    }

    $parts = [count($readable) . ' ' . (count($readable) === 1 ? 'library' : 'libraries') . ' readable'];
    if ($writable !== []) {
        $parts[] = count($writable) . ' of them writable';
    }
    if (is_admin_user($user)) {
        $parts[] = 'administrator';
    }

    return ucfirst(implode(', ', $parts)) . '.';
}

/**
 * May this person manage the shape of the library they are working in?
 *
 * Locations, companies, models, environments, the category tree - all of it
 * belongs to a library, and the person who curates that library is who should be
 * arranging it. Requiring an administrator meant somebody with their own private
 * library could not add a location to it, or a company, and the Manage menu was
 * simply absent.
 *
 * Curator, not contributor: a contributor adds entries, and reshaping the
 * vocabulary those entries are filed under is a different thing from filling it
 * in.
 */
function can_manage_library(): bool
{
    if (is_admin()) {
        return true;
    }
    $lib = working_library();
    if ($lib === null) {
        return false;
    }
    return can_structure_library((int) $lib['id']);
}

/** The manage screens, which are curator's work rather than an administrator's. */
function require_manage(): void
{
    if (current_user() === null) {
        flash('error', 'Sign in to change the collection.');
        to_client();
    }
    if (!can_manage_library()) {
        flash('error', 'You can arrange a library you curate. This is not one of them.');
        to_client();
    }
}

/**
 * What an instance role is called.
 *
 * "Instance" on the front for the same reason "Library" is on the other set:
 * this account level configures the server - accounts, authentication, metadata
 * sources - and a Library Admin does none of that. Two powers, one word, was the
 * confusion worth spending six characters to end.
 */
function instance_role_label(string $role): string
{
    return [
        'admin' => 'Instance Admin',
        'user'  => 'Instance User',
    ][$role] ?? $role;
}
