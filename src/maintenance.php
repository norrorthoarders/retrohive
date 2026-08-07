<?php
declare(strict_types=1);

/**
 * Maintenance jobs.
 *
 * Two kinds of thing go wrong in a catalogue this shape. Rows drift out of
 * agreement with each other - an entry filed under a kind that was deleted, a
 * card fitted to a machine in another library, a photograph on disk that nothing
 * points at any more. And the database drifts out of agreement with the schema,
 * usually because a migration was interrupted.
 *
 * Neither shows up as an error. They show up as a page that is quietly missing
 * something, months later, with no way to tell whether it was ever there. So
 * these are checks first: every job says what it found before anything is
 * offered that would change it.
 *
 * Every repair has a check, never the other way round. A repair that cannot be
 * previewed is a repair nobody should press.
 *
 * Scope is either 'instance' - the whole database, administrators only - or
 * 'library', which an owner or a curator can run on a library they hold. The
 * split is not about difficulty: a library job touches rows inside one library
 * and can be reasoned about by the person who owns it, while an instance job
 * reads across everybody's shelves or changes structure.
 */

/**
 * Every job, keyed by the name used in a URL and a form.
 *
 * @return array<string,array{
 *   label:string, scope:string, access:string, blurb:string,
 *   check:callable, repair:?callable, repair_label:?string
 * }>
 */
function maintenance_jobs(): array
{
    return [
        // --- The whole instance, for administrators --------------------------
        'schema' => [
            'label'  => 'Database structure',
            'scope'  => 'instance',
            'access' => 'admin',
            'blurb'  => 'Compares the live database against db/schema.sql. A table or '
                      . 'column that is declared and missing is almost always a migration '
                      . 'that stopped halfway, and the symptom is a page that fails on one '
                      . 'record in a thousand.',
            'check'  => 'maintenance_check_schema',
            'repair' => null,
            'repair_label' => null,
        ],
        'category_paths' => [
            'label'  => 'Filing tree consistency',
            'scope'  => 'instance',
            'access' => 'admin',
            'blurb'  => 'Every kind stores the path from its root, so a branch can be '
                      . 'listed without walking it. Moving a node rewrites the paths '
                      . 'beneath it; an interrupted move leaves them describing where '
                      . 'things used to be.',
            'check'  => 'maintenance_check_category_paths',
            'repair' => 'maintenance_repair_category_paths',
            'repair_label' => 'Rebuild the paths',
        ],
        'orphan_uploads' => [
            'label'  => 'Photographs nothing points at',
            'scope'  => 'instance',
            'access' => 'admin',
            'blurb'  => 'Files in the uploads directory with no row referring to them. '
                      . 'Rows cascade and files do not, so anything deleted before the '
                      . 'purge existed left its pictures behind - unreachable from any '
                      . 'screen and still served to anyone holding the URL.',
            'check'  => 'maintenance_check_orphan_uploads',
            'repair' => 'maintenance_repair_orphan_uploads',
            'repair_label' => 'Delete them',
        ],
        'missing_uploads' => [
            'label'  => 'Photographs whose file is gone',
            'scope'  => 'instance',
            'access' => 'admin',
            'blurb'  => 'The mirror of the job above: rows pointing at files that are not '
                      . 'in the uploads directory. A handful means a few pictures were lost. '
                      . 'All of them means the directory is not the one the pictures are '
                      . 'actually in, and uploads.dir in the configuration is what says '
                      . 'where. Nothing is deleted here - if the path is wrong, the rows are '
                      . 'the only record of what the files were.',
            'check'  => 'maintenance_check_missing_uploads',
            'repair' => 'maintenance_repair_missing_uploads',
            'repair_label' => 'Forget the rows whose file is gone',
        ],
        'orphan_vocab' => [
            'label'  => 'Specification names for machines that are gone',
            'scope'  => 'instance',
            'access' => 'admin',
            'blurb'  => 'The interface vocabulary is copied into a library along with its '
                      . 'platforms, so a card can say what it plugs into. Deleting a library '
                      . 'takes the platforms and leaves the words behind, pointing at rows '
                      . 'that no longer exist. Nothing reads them and nothing counts them, '
                      . 'and they accumulate for as long as libraries come and go.',
            'check'  => 'maintenance_check_orphan_vocab',
            'repair' => 'maintenance_repair_orphan_vocab',
            'repair_label' => 'Delete them',
        ],
        'slot_platforms' => [
            'label'  => 'Slots belonging to another machine',
            'scope'  => 'instance',
            'access' => 'admin',
            'blurb'  => 'A model slot names a connector from its own platform, or from '
                      . 'the shared list. One pointing at another machine\'s connector is '
                      . 'a Zorro socket on a Master System, and the fitting rules will '
                      . 'believe it.',
            'check'  => 'maintenance_check_slot_platforms',
            'repair' => null,
            'repair_label' => null,
        ],
        'expired_tokens' => [
            'label'  => 'Expired and revoked app tokens',
            'scope'  => 'instance',
            'access' => 'admin',
            'blurb'  => 'Tokens past their expiry, or revoked. They already grant nothing '
                      . '- this is tidying rather than a hole - but a list of credentials '
                      . 'that is mostly dead is a list nobody reads.',
            'check'  => 'maintenance_check_expired_tokens',
            'repair' => 'maintenance_repair_expired_tokens',
            'repair_label' => 'Delete them',
        ],
        'stale_request_stats' => [
            'label'  => 'API request statistics past retention',
            'scope'  => 'instance',
            'access' => 'admin',
            'blurb'  => 'The hourly and five-minute buckets behind the System status page\'s '
                      . 'own charts, past the window either chart could ever show again - '
                      . '30 days for the hourly table, six hours for the five-minute one. '
                      . 'Neither chart loses anything by this running; both only ever look '
                      . 'back that far to begin with.',
            'check'  => 'maintenance_check_stale_request_stats',
            'repair' => 'maintenance_repair_stale_request_stats',
            'repair_label' => 'Prune them',
        ],

        'php_limits' => [
            'label'  => 'What PHP will accept',
            'scope'  => 'instance',
            'access' => 'admin',
            'blurb'  => 'The limits the running instance actually has, which is not always the '
                      . 'php.ini somebody edited: there is one per SAPI and a php-fpm pool can '
                      . 'override all of them. Reported here because the installer checked this '
                      . 'once and was then deleted, leaving no way to ask.',
            'check'  => 'maintenance_check_php_limits',
            'repair' => null,
            'repair_label' => null,
        ],

        // --- One library, for whoever holds it -------------------------------
        'rootless_branches' => [
            'label'  => 'Top-level branches with no machine',
            'scope'  => 'library',
            'access' => ACCESS_ADMIN,
            'blurb'  => 'A top-level branch is a machine - every one of them carries the '
                      . 'platform it belongs to. Adding one by hand used to be possible, and '
                      . 'those said "platform" on the screen while no platform existed: '
                      . 'nothing filed under them can know what it runs on. Empty ones can be '
                      . 'removed here; ones with entries under them are listed and left, '
                      . 'because moving somebody\'s entries is not a tidy-up.',
            'check'  => 'maintenance_check_rootless_branches',
            'repair' => 'maintenance_repair_rootless_branches',
            'repair_label' => 'Remove the empty ones',
        ],
        'branchless_machines' => [
            'label'  => 'Machines with no branch',
            'scope'  => 'library',
            'access' => ACCESS_ADMIN,
            'blurb'  => 'The mirror: a platform with no branch in the category tree, so there '
                      . 'is nowhere to file anything for it. Machines added before the two '
                      . 'were kept in step will be here. Giving each one a branch is safe - it '
                      . 'is what happens now when a machine is added.',
            'check'  => 'maintenance_check_branchless_machines',
            'repair' => 'maintenance_repair_branchless_machines',
            'repair_label' => 'Give them a branch',
        ],

        'scraped_notes' => [
            'label'  => 'Blurbs sitting in the notes',
            'scope'  => 'library',
            'access' => ACCESS_ADMIN,
            'blurb'  => 'Before there was a description field, a lookup wrote what it found '
                      . 'about the release into the notes - the field you keep your own '
                      . 'remarks in. These entries have notes, no description, and a '
                      . 'reference link on a metadata source, which is the same apply that '
                      . 'wrote the blurb. The text is shown so you can see whose words they '
                      . 'are before moving anything.',
            'check'  => 'maintenance_check_scraped_notes',
            'repair' => 'maintenance_repair_scraped_notes',
            'repair_label' => 'Move these notes to the description',
        ],

        'gone_sources' => [
            'label'  => 'Sources that no longer exist',
            'scope'  => 'instance',
            'access' => 'admin',
            'blurb'  => 'A metadata source configured here whose kind this version no longer '
                      . 'has - Google Images was withdrawn when Google closed the API behind '
                      . 'it. The row does no harm beyond an error line on every lookup, which '
                      . 'is one line too many for something nobody can fix by configuring it.',
            'check'  => 'maintenance_check_gone_sources',
            'repair' => 'maintenance_repair_gone_sources',
            'repair_label' => 'Remove them',
        ],

        'unasked_branches' => [
            'label'  => 'Branches no source is switched on for',
            'scope'  => 'instance',
            'access' => 'admin',
            'blurb'  => 'A lookup asks the sources switched on for the branch the entry is '
                      . 'filed under, and nothing is switched on until something switches it. '
                      . 'A branch nobody has been switched on for answers "nothing found" to '
                      . 'every lookup, having asked nobody - which reads as the sources being '
                      . 'broken rather than as never having been consulted. Video and audio '
                      . 'branches were affected on every instance built before this version: '
                      . 'the seeding step only ever considered machines, peripherals, games '
                      . 'and applications, so a Movies branch was never given a row however '
                      . 'many times a source was added.',
            'check'  => 'maintenance_check_unasked_branches',
            'repair' => 'maintenance_repair_unasked_branches',
            'repair_label' => 'Switch on the usual sources',
        ],

        'stale_branch_pictures' => [
            'label'  => 'Branch pictures not carried into libraries',
            'scope'  => 'instance',
            'access' => 'admin',
            'blurb'  => 'The structure feed says which shipped picture a branch\'s things '
                      . 'should get when they have no photograph. A library gets its own copy '
                      . 'of the tree when it is made, so a library made before the feed said '
                      . 'anything - or before this version existed - has branches with nothing '
                      . 'declared while their templates now declare something. Repairing copies '
                      . 'the template\'s answer down, matched on source_slug, and never touches '
                      . 'a branch somebody has given a picture of their own.',
            'check'  => 'maintenance_check_stale_branch_pictures',
            'repair' => 'maintenance_repair_stale_branch_pictures',
            'repair_label' => 'Carry the pictures down',
        ],

        'unfiled' => [
            'label'  => 'Entries filed somewhere that is gone',
            'scope'  => 'library',
            'access' => ACCESS_ADMIN,
            'blurb'  => 'An entry whose kind or machine points at a row that is not there '
                      . 'library. Foreign keys already stop the kind or the machine from '
                      . 'being deleted underneath an entry, and both columns are NOT NULL - '
                      . 'so what is left, and what nothing enforces, is an entry filed '
                      . 'under a kind belonging to somebody else\'s library. It appears in '
                      . 'no branch of your tree.',
            'check'  => 'maintenance_check_unfiled',
            'repair' => null,
            'repair_label' => null,
        ],
        'stray_locations' => [
            'label'  => 'Entries shelved in another library',
            'scope'  => 'library',
            'access' => ACCESS_ADMIN,
            'blurb'  => 'A place belongs to one library. An entry pointing at a place in '
                      . 'a different one says it is on a shelf its owner cannot see, which '
                      . 'is worse than saying nothing.',
            'check'  => 'maintenance_check_stray_locations',
            'repair' => 'maintenance_repair_stray_locations',
            'repair_label' => 'Clear the place',
        ],
        'cross_library_compatibility' => [
            'label'  => 'Cards fitted across libraries',
            'scope'  => 'library',
            'access' => ACCESS_ADMIN,
            'blurb'  => 'A card is installed in a machine. Both are entries, and both '
                      . 'should be in the same library - a link across the boundary shows '
                      . 'one of them a slot it does not have and the other a card nobody '
                      . 'can see.',
            'check'  => 'maintenance_check_cross_library_fits',
            'repair' => 'maintenance_repair_cross_library_fits',
            'repair_label' => 'Unfit them',
        ],
    ];
}

/** The jobs somebody may run, given who they are and what they hold. */
function maintenance_jobs_for(string $scope, ?int $libraryId = null): array
{
    $out = [];
    foreach (maintenance_jobs() as $key => $job) {
        if ($job['scope'] !== $scope) {
            continue;
        }
        if ($job['access'] === 'admin') {
            if (!is_admin()) {
                continue;
            }
        } elseif ($libraryId !== null && !can_curate_library($libraryId)) {
            // Curator is the bar for every library job here: they all change or
            // report on what is filed, which is exactly what a curator is for. A
            // contributor may edit what they added, and a job that sweeps a whole
            // library is not that.
            continue;
        }
        $out[$key] = $job;
    }
    return $out;
}

/**
 * A finding.
 *
 * `count` is what the screen shows and what decides whether a repair is offered.
 * `rows` are examples - capped, because "nine thousand" and the first ten of them
 * is as much as anybody can act on at once.
 */
function maintenance_result(int $count, array $rows = [], string $note = ''): array
{
    return ['count' => $count, 'rows' => array_slice($rows, 0, 20), 'note' => $note];
}

// --- Instance checks --------------------------------------------------------

/**
 * The live database against the schema that ships.
 *
 * Declared-and-missing only. A table the schema does not mention is somebody's
 * own, or a leftover from a version that had it, and deciding it should not be
 * there is not this job's business.
 */
function maintenance_check_schema(): array
{
    $sql = @file_get_contents(APP_ROOT . '/db/schema.sql');
    if ($sql === false) {
        return maintenance_result(0, [], 'db/schema.sql could not be read, so there is nothing to compare against.');
    }

    preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?/i', $sql, $m);
    $declared = array_unique($m[1] ?? []);

    $live = [];
    foreach (all('SHOW TABLES') as $row) {
        $live[] = (string) reset($row);
    }
    foreach (all("SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()") as $row) {
        $live[] = (string) $row['TABLE_NAME'];
    }

    $missing = array_values(array_diff($declared, $live));
    return maintenance_result(count($missing),
        array_map(fn($t) => ['what' => $t, 'detail' => 'declared in schema.sql, not in the database'], $missing),
        $missing === [] ? 'Every table and view the schema declares is present.' : '');
}

/** Categories whose stored path disagrees with their ancestry. */
function maintenance_check_category_paths(): array
{
    // A path must end in its own id, and must begin with its parent's path.
    $bad = all(
        "SELECT c.id, c.slug, c.path, p.path AS parent_path
           FROM categories c
      LEFT JOIN categories p ON p.id = c.parent_id
          WHERE c.path IS NULL
             OR c.path = ''
             OR c.path NOT LIKE CONCAT('%/', c.id, '/')
             OR (c.parent_id IS NOT NULL AND (p.path IS NULL OR c.path NOT LIKE CONCAT(p.path, '%')))
          LIMIT 200"
    );
    return maintenance_result(count($bad),
        array_map(fn($r) => ['what' => (string) $r['slug'],
                             'detail' => 'path ' . ($r['path'] === null || $r['path'] === '' ? '(empty)' : $r['path'])], $bad),
        $bad === [] ? 'Every kind agrees with the branch it is in.' : '');
}

function maintenance_repair_category_paths(): array
{
    rebuild_category_paths();
    $after = maintenance_check_category_paths();
    return ['done' => true, 'message' => $after['count'] === 0
        ? 'Paths rebuilt; every kind now agrees with its branch.'
        : $after['count'] . ' still disagree, which is a tree with a loop in it rather than a stale path.'];
}

/** Files in the uploads directory that nothing refers to. */
function maintenance_check_orphan_uploads(): array
{
    $dir = uploads_dir();
    if (!is_dir($dir)) {
        return maintenance_result(0, [], 'There is no uploads directory yet.');
    }

    // Everything any row could be pointing at, including the variants that are
    // written beside the original.
    $known = [];
    foreach (all('SELECT filename FROM item_images') as $r) {
        foreach (['', 'thumb_', 'disp_'] as $prefix) {
            $known[$prefix . $r['filename']] = true;
        }
    }
    foreach (['companies' => 'logo_filename', 'users' => 'avatar_filename'] as $table => $col) {
        foreach (all("SELECT `$col` AS f FROM `$table` WHERE `$col` IS NOT NULL AND `$col` <> ''") as $r) {
            foreach (['', 'thumb_'] as $prefix) {
                $known[$prefix . $r['f']] = true;
            }
        }
    }

    $strays = [];
    foreach ((array) scandir($dir) as $name) {
        if ($name === '.' || $name === '..' || $name === '.gitkeep' || $name === '.htaccess') {
            continue;
        }
        if (!is_file($dir . '/' . $name) || isset($known[$name])) {
            continue;
        }
        $strays[] = ['what' => $name, 'detail' => number_format((int) filesize($dir . '/' . $name)) . ' bytes'];
    }

    return maintenance_result(count($strays), $strays,
        $strays === [] ? 'Every file in the uploads directory belongs to something.' : '');
}

function maintenance_repair_orphan_uploads(): array
{
    $found = maintenance_check_orphan_uploads();
    if ($found['count'] === 0) {
        return ['done' => false, 'message' => 'Nothing to delete.'];
    }

    // Re-listed rather than trusting the rows the screen was showing: the check
    // caps its examples at twenty, and somebody may have uploaded since.
    $dir  = uploads_dir();
    $gone = 0;
    foreach ((array) scandir($dir) as $name) {
        if ($name === '.' || $name === '..' || $name === '.gitkeep' || $name === '.htaccess') {
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_file($path) && maintenance_upload_is_orphan($name) && @unlink($path)) {
            $gone++;
        }
    }
    return ['done' => true, 'message' => $gone . ' file(s) deleted.'];
}

/** Is this filename referred to by any row? */
function maintenance_upload_is_orphan(string $name): bool
{
    $bare = preg_replace('/^(thumb_|disp_)/', '', $name);
    if ((int) scalar('SELECT COUNT(*) FROM item_images WHERE filename = ?', [$bare]) > 0) {
        return false;
    }
    if ((int) scalar('SELECT COUNT(*) FROM companies WHERE logo_filename = ?', [$bare]) > 0) {
        return false;
    }
    // A branch's own default picture, which this had never counted.
    //
    // Three tables were listed here and there are four. A picture uploaded as a
    // category default was referred to by nothing this function knew about, so
    // it read as an orphan - and the repair below deletes what this reports.
    // Uploading a default picture for Movies and then running the orphan sweep
    // deleted it, leaving the row pointing at a file that was no longer there.
    // Pre-existing, and unrelated to the stock pictures that now cover the same
    // ground automatically: those are shipped files outside the uploads
    // directory entirely and were never at risk from this.
    if ((int) scalar('SELECT COUNT(*) FROM categories WHERE default_image_filename = ?', [$bare]) > 0) {
        return false;
    }
    if ((int) scalar('SELECT COUNT(*) FROM users WHERE avatar_filename = ?', [$bare]) > 0) {
        return false;
    }
    // The instance's own logos, which are named in `settings` rather than by a
    // column on a row.
    //
    // Every other picture here is referred to by a table this function can ask
    // about; these two are not, so without this they read as orphans and the
    // repair below deletes what this reports - somebody uploads a logo, runs the
    // orphan sweep, and the header goes back to the built-in mark with no
    // explanation. The same shape of gap the category default pictures had, and
    // the reason that comment above is worth having.
    //
    // Also checks the pending avatar column, for the same reason: a picture
    // waiting for an administrator is referred to by a row, just not the one
    // this used to ask about.
    if ((int) scalar('SELECT COUNT(*) FROM users WHERE avatar_pending_filename = ?', [$bare]) > 0) {
        return false;
    }
    foreach (['logo_small', 'logo_large'] as $key) {
        if ((string) setting($key, '') === $bare) {
            return false;
        }
    }
    return true;
}

/**
 * Branches that would ask nobody.
 *
 * The topmost branch of each kind is the one seeding gives a row to, because
 * both the kind and the source inherit downward - so that is the level this
 * asks about too. A branch whose kind no configured source claims as a default
 * is not reported: nothing is wrong with a Blank media branch that no metadata
 * source is good for, and a line saying so every time somebody opens this page
 * is noise.
 */
function maintenance_check_unasked_branches(): array
{
    if (!function_exists('metadata_provider_definition')) {
        return maintenance_result(0, [], 'Metadata sources are not loaded here.');
    }

    // Which kinds any configured source would be switched on for. Without at
    // least one, there is nothing this repair could do and nothing to report.
    $claimed = [];
    foreach (all('SELECT type FROM metadata_providers WHERE is_enabled = 1') as $p) {
        $def = metadata_provider_definition((string) $p['type']);
        foreach ((array) ($def['default_for_kinds'] ?? []) as $kind) {
            $claimed[$kind] = true;
        }
    }
    if ($claimed === []) {
        return maintenance_result(0, [],
            'No metadata source is configured, so there is nothing to switch on anywhere.');
    }

    $kinds = array_keys($claimed);
    $rows  = all('SELECT c.id, c.role, c.path, c.name, l.name AS library_name
                    FROM categories c
                    JOIN libraries l ON l.id = c.library_id
                   WHERE c.library_id IS NOT NULL
                     AND c.role IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')',
                 $kinds);

    $roleById = [];
    foreach ($rows as $r) {
        $roleById[(int) $r['id']] = (string) $r['role'];
    }

    $out = [];
    foreach ($rows as $r) {
        // Only the topmost branch of its kind, matching what seeding writes.
        $ids = array_map('intval', array_values(array_filter(
            explode('/', (string) $r['path']), 'strlen')));
        array_pop($ids);
        foreach ($ids as $ancestor) {
            if (($roleById[$ancestor] ?? '') === (string) $r['role']) {
                continue 2;
            }
        }

        $on = (int) scalar('SELECT COUNT(*) FROM provider_scopes
                             WHERE category_id = ? AND enabled = 1', [(int) $r['id']]);
        if ($on > 0) {
            continue;
        }
        $out[] = [
            'what'   => (string) $r['library_name'] . ' › ' . (string) $r['name'],
            'detail' => 'nothing switched on for this ' . str_replace('_', ' ', (string) $r['role'])
                      . ' branch, so a lookup here asks no source at all',
            'id'     => (int) $r['id'],
        ];
    }

    return maintenance_result(count($out), $out,
        $out === [] ? 'Every branch a source is good for has one switched on.' : '');
}

/**
 * Run the same seeding a new library gets, which only ever adds and never
 * overrides a decision somebody has made - a branch deliberately switched off
 * has a row saying so, and this skips it.
 */
function maintenance_repair_unasked_branches(): array
{
    $added = 0;
    foreach (all('SELECT id FROM libraries') as $lib) {
        $added += seed_library_provider_scopes((int) $lib['id']);
    }
    return ['done' => true, 'message' => match (true) {
        $added === 0 => 'Nothing to switch on.',
        $added === 1 => '1 source switched on.',
        default      => $added . ' sources switched on.',
    }];
}

/**
 * Library branches whose template now declares a picture they do not carry.
 *
 * Matched on source_slug, which is what ties a copy back to the template it came
 * from and is indexed for exactly this kind of question. A branch added by hand
 * has a null source_slug and is left alone - it never came from a template, so
 * there is nothing to be out of step with.
 */
function maintenance_check_stale_branch_pictures(): array
{
    $rows = all(
        "SELECT c.id, c.name, t.stock_image, l.name AS library_name
           FROM categories c
           JOIN categories t ON t.library_id IS NULL AND t.slug = c.source_slug
           JOIN libraries  l ON l.id = c.library_id
          WHERE c.library_id IS NOT NULL
            AND c.source_slug IS NOT NULL
            AND t.stock_image IS NOT NULL
            AND (c.stock_image IS NULL OR c.stock_image <> t.stock_image)
          ORDER BY l.name, c.name"
    );

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'what'   => (string) $r['library_name'] . ' › ' . (string) $r['name'],
            'detail' => 'its template declares ' . (string) $r['stock_image'] . ' and this copy does not',
            'id'     => (int) $r['id'],
        ];
    }
    return maintenance_result(count($out), $out,
        $out === [] ? 'Every branch carries what its template declares.' : '');
}

/**
 * Copy the template's answer down.
 *
 * Only this column. A picture somebody uploaded for the branch lives in
 * default_image_filename and is a different question with a different answer -
 * it is still checked first when an entry is drawn, so a branch repaired here
 * looks exactly as it did to anyone who had set one.
 */
function maintenance_repair_stale_branch_pictures(): array
{
    $st = q("UPDATE categories c
               JOIN categories t ON t.library_id IS NULL AND t.slug = c.source_slug
                SET c.stock_image = t.stock_image
              WHERE c.library_id IS NOT NULL
                AND c.source_slug IS NOT NULL
                AND t.stock_image IS NOT NULL
                AND (c.stock_image IS NULL OR c.stock_image <> t.stock_image)");
    $n = $st->rowCount();
    return ['done' => true, 'message' => match (true) {
        $n === 0 => 'Nothing to carry down.',
        $n === 1 => '1 branch updated.',
        default  => $n . ' branches updated.',
    }];
}

/** Slots pointing at a connector from a different machine. */
function maintenance_check_slot_platforms(): array
{
    $bad = all(
        "SELECT hm.name AS model, hv.code, hvp.name AS vocab_platform, hmp.name AS model_platform
           FROM model_slots ms
           JOIN hardware_models hm ON hm.id = ms.model_id
           JOIN hardware_vocab hv  ON hv.id = ms.vocab_id
      LEFT JOIN platforms hmp ON hmp.id = hm.platform_id
      LEFT JOIN platforms hvp ON hvp.id = hv.platform_id
          WHERE hv.platform_id IS NOT NULL
            AND hv.platform_id <> 0
            AND hv.platform_id <> hm.platform_id
          LIMIT 200"
    );
    return maintenance_result(count($bad),
        array_map(fn($r) => ['what' => (string) $r['model'],
                             'detail' => $r['code'] . ' belongs to ' . ($r['vocab_platform'] ?? 'another machine')
                                       . ', not ' . ($r['model_platform'] ?? 'this one')], $bad),
        $bad === [] ? 'Every slot names a connector its own machine has.' : '');
}

/** Tokens that have expired or been revoked. */
function maintenance_check_expired_tokens(): array
{
    $rows = all(
        "SELECT t.id, t.name, u.username, t.expires_at, t.revoked_at
           FROM api_tokens t LEFT JOIN users u ON u.id = t.user_id
          WHERE (t.expires_at IS NOT NULL AND t.expires_at < NOW())
             OR t.revoked_at IS NOT NULL
          LIMIT 200"
    );
    return maintenance_result(count($rows),
        array_map(fn($r) => ['what' => (string) ($r['name'] ?? 'token'),
                             'detail' => ($r['username'] ?? 'unknown account') . ' — '
                                       . ($r['revoked_at'] !== null ? 'revoked' : 'expired')], $rows),
        $rows === [] ? 'Every token on file is still live.' : '');
}

function maintenance_repair_expired_tokens(): array
{
    $st = q("DELETE FROM api_tokens
              WHERE (expires_at IS NOT NULL AND expires_at < NOW()) OR revoked_at IS NOT NULL");
    return ['done' => true, 'message' => $st->rowCount() . ' token(s) deleted.'];
}

/**
 * API request statistics past their own retention window - the hourly
 * table kept 30 days, the 5-minute one kept 6 hours, both because they
 * fuel charts with a fixed window: a bucket a chart could never show
 * again is a row with nothing left to answer. api_prune_request_stats()
 * and its 5-minute sibling existed from the round each table was built,
 * unreachable from anywhere until this job gave them one.
 */
function maintenance_check_stale_request_stats(): array
{
    $stale = (int) scalar('SELECT COUNT(*) FROM api_request_stats WHERE bucket_hour < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $stale5m = (int) scalar('SELECT COUNT(*) FROM api_request_stats_5m WHERE bucket_5m < DATE_SUB(NOW(), INTERVAL 6 HOUR)');
    $total = $stale + $stale5m;
    return maintenance_result($total,
        $total > 0 ? [['what' => 'Hourly buckets', 'detail' => number_format($stale) . ' past 30 days'],
                      ['what' => '5-minute buckets', 'detail' => number_format($stale5m) . ' past 6 hours']] : [],
        $total === 0 ? 'Nothing past its own retention window.' : '');
}

function maintenance_repair_stale_request_stats(): array
{
    $gone = api_prune_request_stats(30) + api_prune_request_stats_5m(6);
    return ['done' => true, 'message' => number_format($gone) . ' stale bucket(s) removed.'];
}

/**
 * Vocabulary rows whose platform is not there any more.
 *
 * platform_id 0 is the sentinel for "applies anywhere" and belongs to nobody, so
 * it is excluded rather than swept up with the rest - it is the one value that
 * is meant not to join.
 */
function maintenance_check_orphan_vocab(): array
{
    $rows = all(
        "SELECT hv.id, hv.kind, hv.code, hv.platform_id
           FROM hardware_vocab hv
          WHERE hv.platform_id <> 0
            AND NOT EXISTS (SELECT 1 FROM platforms p WHERE p.id = hv.platform_id)
          LIMIT 200"
    );
    $total = (int) scalar(
        "SELECT COUNT(*) FROM hardware_vocab hv
          WHERE hv.platform_id <> 0
            AND NOT EXISTS (SELECT 1 FROM platforms p WHERE p.id = hv.platform_id)"
    );

    return maintenance_result($total,
        array_map(fn($r) => ['what'   => (string) $r['code'],
                             'detail' => (string) $r['kind'] . ', machine '
                                       . (string) $r['platform_id'] . ' no longer exists'], $rows),
        $total === 0 ? 'Every specification name still belongs to a machine.' : '');
}

function maintenance_repair_orphan_vocab(): array
{
    $st = q("DELETE hv FROM hardware_vocab hv
              WHERE hv.platform_id <> 0
                AND NOT EXISTS (SELECT 1 FROM platforms p WHERE p.id = hv.platform_id)");
    return ['done' => true, 'message' => $st->rowCount() . ' specification name(s) deleted.'];
}

/**
 * What this instance will actually accept, and whether it holds together.
 *
 * post_max_size caps the whole request, so an upload_max_filesize above it can
 * never be reached - a common and invisible mistake, because the larger number
 * is the one that looks like the answer. When a request exceeds post_max_size
 * PHP discards the body entirely, which surfaces as a request that arrives
 * carrying nothing rather than as an error anybody can read.
 */
function maintenance_check_php_limits(): array
{
    // Its own parser, small as it is. The installer has one, but the installer
    // is a standalone file that the application never loads - and is often
    // deleted, which is half the reason this check exists.
    $bytes = static function (string $value): int {
        $value = trim($value);
        if ($value === '' || $value === '-1') { return -1; }
        $unit = strtolower(substr($value, -1));
        $n    = (int) $value;
        return match ($unit) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    };

    $post   = $bytes((string) ini_get('post_max_size'));
    $upload = $bytes((string) ini_get('upload_max_filesize'));
    $memory = $bytes((string) ini_get('memory_limit'));

    $rows = [
        ['what' => 'post_max_size',       'detail' => (string) ini_get('post_max_size')],
        ['what' => 'upload_max_filesize', 'detail' => (string) ini_get('upload_max_filesize')],
        ['what' => 'memory_limit',        'detail' => (string) ini_get('memory_limit')],
        ['what' => 'php.ini in use',      'detail' => (string) (php_ini_loaded_file() ?: 'none')],
        ['what' => 'running as',          'detail' => PHP_SAPI],
    ];

    $wrong = [];
    if ($post > 0 && $upload > $post) {
        $wrong[] = sprintf('upload_max_filesize (%s) is above post_max_size (%s), so it can '
                         . 'never be reached', ini_get('upload_max_filesize'),
                           ini_get('post_max_size'));
    }
    if ($upload > 0 && $upload < 16 * 1024 * 1024) {
        $wrong[] = 'upload_max_filesize is under 16M, which a photograph of a boxed machine '
                 . 'can exceed';
    }
    if ($post > 0 && $post < 32 * 1024 * 1024) {
        $wrong[] = 'post_max_size is under 32M, and a batch of photographs arrives in one '
                 . 'request';
    }
    if ($memory > 0 && $memory < 128 * 1024 * 1024) {
        $wrong[] = 'memory_limit is under 128M, which resizing a large photograph needs';
    }

    return maintenance_result(count($wrong),
        array_merge($rows, array_map(fn($w) => ['what' => 'problem', 'detail' => $w], $wrong)),
        $wrong === [] ? 'The limits are sensible and consistent.' : implode('; ', $wrong));
}

// --- Library checks ---------------------------------------------------------

/**
 * Entries filed under a kind or machine from another library.
 *
 * Narrower than it started. The first version looked for entries with no kind and
 * no machine, which the schema makes impossible - both columns are NOT NULL - and
 * the second looked for dangling ids, which `fk_items_category` makes impossible
 * too. Both were checks that could only ever report zero, which is the most
 * expensive kind of check: it runs, it reassures, and it is measuring nothing.
 *
 * What is left is real and unenforced: nothing requires the kind an entry names
 * to belong to the same library as the entry.
 */
function maintenance_check_unfiled(int $libraryId): array
{
    $rows = all(
        "SELECT i.id, i.title,
                c.id AS cat_ok, c.library_id AS cat_lib,
                p.id AS plat_ok, p.library_id AS plat_lib
           FROM items i
      LEFT JOIN categories c ON c.id = i.category_id
      LEFT JOIN platforms  p ON p.id = i.platform_id
          WHERE i.library_id = ?
            AND ((c.library_id IS NOT NULL AND c.library_id <> i.library_id)
                 OR (p.library_id IS NOT NULL AND p.library_id <> i.library_id))
          ORDER BY i.title LIMIT 200", [$libraryId]);

    $out = [];
    foreach ($rows as $r) {
        $why = [];
        if ($r['cat_ok'] === null)  { $why[] = 'its kind no longer exists'; }
        elseif ($r['cat_lib'] !== null && (int) $r['cat_lib'] !== $libraryId) { $why[] = 'its kind belongs to another library'; }
        if ($r['plat_ok'] === null) { $why[] = 'its machine no longer exists'; }
        elseif ($r['plat_lib'] !== null && (int) $r['plat_lib'] !== $libraryId) { $why[] = 'its machine belongs to another library'; }
        $out[] = ['what' => (string) $r['title'], 'detail' => implode(', and ', $why), 'id' => (int) $r['id']];
    }
    return maintenance_result(count($out), $out,
        $out === [] ? 'Every entry is filed under a kind and a machine this library has.' : '');
}

/** Entries pointing at a place in another library. */
function maintenance_check_stray_locations(int $libraryId): array
{
    $rows = all(
        "SELECT i.id, i.title, l.name AS place, l.library_id AS place_library
           FROM items i JOIN locations l ON l.id = i.location_id
          WHERE i.library_id = ? AND l.library_id <> i.library_id
          ORDER BY i.title LIMIT 200", [$libraryId]);
    return maintenance_result(count($rows),
        array_map(fn($r) => ['what' => (string) $r['title'],
                             'detail' => 'shelved at ' . $r['place'] . ', which belongs to another library',
                             'id' => (int) $r['id']], $rows),
        $rows === [] ? 'Every entry is shelved somewhere this library owns.' : '');
}

function maintenance_repair_stray_locations(int $libraryId): array
{
    $st = q("UPDATE items i JOIN locations l ON l.id = i.location_id
                SET i.location_id = NULL
              WHERE i.library_id = ? AND l.library_id <> i.library_id", [$libraryId]);
    $n = $st->rowCount();
    return ['done' => true, 'message' => $n === 1
        ? '1 entry no longer claims a shelf in another library. Where it actually is, is yours to say.'
        : $n . ' entries no longer claim a shelf in another library. Where they actually are is yours to say.'];
}

/** Cards fitted into machines in a different library. */
function maintenance_check_cross_library_fits(int $libraryId): array
{
    $rows = all(
        "SELECT c.id, c.title AS child, p.title AS parent, p.library_id AS parent_library
           FROM item_links k
           JOIN items c ON c.id = k.child_item_id
           JOIN items p ON p.id = k.parent_item_id
          WHERE k.relation = 'installed_in'
            AND c.library_id = ?
            AND p.library_id <> c.library_id
          LIMIT 200", [$libraryId]);
    return maintenance_result(count($rows),
        array_map(fn($r) => ['what' => (string) $r['child'],
                             'detail' => 'fitted in ' . $r['parent'] . ', which is in another library',
                             'id' => (int) $r['id']], $rows),
        $rows === [] ? 'Every fitted card is in the same library as the machine it is in.' : '');
}

function maintenance_repair_cross_library_fits(int $libraryId): array
{
    $st = q("DELETE k FROM item_links k
               JOIN items c ON c.id = k.child_item_id
               JOIN items p ON p.id = k.parent_item_id
              WHERE k.relation = 'installed_in'
                AND c.library_id = ?
                AND p.library_id <> c.library_id", [$libraryId]);
    return ['done' => true, 'message' => $st->rowCount() . ' link(s) removed. The entries are untouched.'];
}

/**
 * Run one job's check.
 *
 * @return array{count:int,rows:array,note:string}
 */
function maintenance_run_check(string $key, ?int $libraryId = null): array
{
    $jobs = maintenance_jobs();
    if (!isset($jobs[$key])) {
        return maintenance_result(0, [], 'No such job.');
    }
    $fn = $jobs[$key]['check'];
    return $jobs[$key]['scope'] === 'library' ? $fn((int) $libraryId) : $fn();
}

/**
 * The registrable domains a metadata source's own links live on.
 *
 * Taken from each source's declared homepage rather than listed by hand, so a
 * source added later is covered without this being edited - but reduced to the
 * last two labels, because a result link is not on the homepage's host:
 * IGDB's homepage is api-docs.igdb.com and its games are on igdb.com.
 *
 * @return list<string>
 */
function maintenance_source_domains(): array
{
    $out = [];
    foreach (metadata_provider_types() as $def) {
        $host = parse_url((string) ($def['homepage'] ?? ''), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            continue;
        }
        $parts = explode('.', $host);
        if (count($parts) >= 2) {
            $out[] = implode('.', array_slice($parts, -2));
        }
    }
    return array_values(array_unique($out));
}

/**
 * Entries whose notes are probably a lookup's blurb.
 *
 * Not a guess about the prose. Three facts about the row: it has notes, it has
 * no description, and its reference link is on a metadata source - and that link
 * is written by the same apply that used to write the summary into the notes.
 * An entry somebody typed a note on rarely has a link to openretro.org in the
 * reference field as well.
 *
 * The text is returned so the page can show it. Whose words these are is a
 * judgement, and the person whose words they might be is the one to make it.
 */
function maintenance_check_scraped_notes(int $libraryId): array
{
    $domains = maintenance_source_domains();
    if ($domains === []) {
        return maintenance_result(0, [], 'No metadata sources are configured, so there is nothing to recognise.');
    }

    $where = [];
    $args  = [$libraryId];
    foreach ($domains as $d) {
        $where[] = 'external_url LIKE ?';
        $args[]  = '%' . $d . '/%';
    }

    $rows = all(
        'SELECT id, title, notes, external_url
           FROM items
          WHERE library_id = ?
            AND notes IS NOT NULL AND notes <> \'\'
            AND (description IS NULL OR description = \'\')
            AND (' . implode(' OR ', $where) . ')
          ORDER BY title LIMIT 200', $args);

    return maintenance_result(count($rows),
        array_map(static fn(array $r): array => [
            'what'   => (string) $r['title'],
            // The note itself, because the decision cannot be made without it.
            'detail' => mb_substr(trim((string) $r['notes']), 0, 120)
                      . (mb_strlen((string) $r['notes']) > 120 ? '…' : ''),
            'id'     => (int) $r['id'],
        ], $rows),
        $rows === [] ? 'No notes look like they came from a lookup.' : '');
}

/**
 * Move those notes into the description.
 *
 * Moved, not copied and not deleted: the text ends up one field across, and if
 * the guess was wrong about an entry the remedy is to move that one back. That
 * is the whole reason this is offered as a button somebody presses after reading
 * the list, rather than as a migration that ran while they were not looking.
 *
 * Only where the description is still empty, re-checked here: the list may have
 * been read some time ago.
 */
function maintenance_repair_scraped_notes(int $libraryId): array
{
    $found = maintenance_check_scraped_notes($libraryId);
    if ($found['count'] === 0) {
        return ['done' => true, 'message' => 'Nothing to move.'];
    }

    $moved = 0;
    foreach ($found['rows'] as $row) {
        $item = one('SELECT notes, description FROM items WHERE id = ?', [(int) $row['id']]);
        if ($item === null || trim((string) ($item['description'] ?? '')) !== '') {
            continue;
        }
        update_row('items', (int) $row['id'], [
            'description' => (string) $item['notes'],
            'notes'       => null,
        ]);
        $moved++;
    }

    return ['done' => true, 'message' => $moved === 1
        ? '1 note moved into the description. If one of them was yours, move it back.'
        : $moved . ' notes moved into the description. If any were yours, move them back.'];
}

/**
 * Configured sources whose kind this version does not have.
 *
 * A source can be withdrawn - Google closed the Custom Search API to new
 * customers and gave existing ones until 2027, so the agent for it went - and
 * the row somebody configured stays behind. It cannot do anything except put
 * "No implementation for provider type" on every lookup.
 */
function maintenance_check_gone_sources(): array
{
    $known = array_keys(metadata_provider_types());
    $rows  = [];
    foreach (all('SELECT id, name, type FROM metadata_providers ORDER BY name') as $row) {
        if (in_array((string) $row['type'], $known, true)) {
            continue;
        }
        $rows[] = [
            'what'   => (string) $row['name'],
            'detail' => 'kind "' . (string) $row['type'] . '" is not in this version',
            'id'     => (int) $row['id'],
        ];
    }
    return maintenance_result(count($rows), $rows,
        $rows === [] ? 'Every configured source is one this version knows.' : '');
}

/** Delete them. Nothing else points at a source row, so there is nothing to orphan. */
function maintenance_repair_gone_sources(): array
{
    $found = maintenance_check_gone_sources();
    $gone  = 0;
    foreach ($found['rows'] as $row) {
        q('DELETE FROM metadata_providers WHERE id = ?', [(int) $row['id']]);
        $gone++;
    }
    // counted() lives in the installer, not here.
    return ['done' => true, 'message' => match (true) {
        $gone === 0 => 'Nothing to remove.',
        $gone === 1 => '1 source removed.',
        default     => $gone . ' sources removed.',
    }];
}

/**
 * Photograph rows whose file is not where this instance looks for it.
 *
 * The other half of `orphan_uploads`, and the half that was missing: that one
 * finds files nothing points at, this one finds rows pointing at nothing. An
 * erase reported "0 photos" and "no uploaded files to delete" together for weeks
 * and neither number was wrong - there was simply no way to ask the question
 * while the catalogue still had photographs in it.
 *
 * Deliberately without a repair. If the directory is merely the wrong one, these
 * rows are the only record of what the files were, and deleting them to tidy up
 * a diagnosis would destroy the thing being diagnosed.
 */
function maintenance_check_missing_uploads(): array
{
    $dir  = uploads_dir();
    $rows = all('SELECT i.id, i.filename, i.item_id, t.title
                   FROM item_images i
              LEFT JOIN items t ON t.id = i.item_id
                  ORDER BY i.id');
    $total   = count($rows);
    $missing = [];
    foreach ($rows as $row) {
        if (is_file($dir . '/' . (string) $row['filename'])) {
            continue;
        }
        $missing[] = [
            'what'   => (string) ($row['title'] ?? 'entry ' . (int) $row['item_id']),
            'detail' => (string) $row['filename'],
            'id'     => (int) $row['id'],
        ];
    }

    if ($total === 0) {
        return maintenance_result(0, [], 'There are no photographs on file yet.');
    }
    if ($missing === []) {
        return maintenance_result(0, [],
            sprintf('All %d photograph%s %s where this expects %s, in %s.',
                $total, $total === 1 ? '' : 's', $total === 1 ? 'is' : 'are',
                $total === 1 ? 'it' : 'them', maintenance_pretty_path($dir)));
    }

    // The distinction that matters. A few gone is a few lost pictures; all of
    // them gone is a directory that is not the one they are in, and those want
    // different things done about them.
    $note = count($missing) === $total
        ? sprintf('Every one of the %d photographs is missing from %s. That is usually a '
                . 'path rather than a loss - check uploads.dir in src/config.local.php '
                . 'against where the files actually are.', $total, maintenance_pretty_path($dir))
        : sprintf('%d of %d photographs are missing from %s.',
                  count($missing), $total, maintenance_pretty_path($dir));

    // Two hundred is enough to see the shape of it; the count above is the answer.
    return maintenance_result(count($missing), array_slice($missing, 0, 200), $note);
}

/**
 * A path as somebody would check it, resolved through any symlink.
 *
 * The installer has one of these; the application does not, and calling it from
 * here would have been a fatal on a screen whose whole job is to tell somebody
 * what is wrong. Resolved on purpose: "/srv/www/current/public/uploads" and the
 * release directory it points at are the same place, and only one of them is
 * what `ls` will show.
 */
function maintenance_pretty_path(string $path): string
{
    $real = realpath($path);
    return $real === false ? $path : $real;
}

/**
 * Top-level branches that are not a machine.
 *
 * Every root carries the platform it belongs to - that is what a root is. Ones
 * added by hand before that was enforced have no platform behind them: they said
 * "platform" on the screen, nothing appeared in the platform list, and nothing
 * filed under them can know what it runs on.
 */
function maintenance_check_rootless_branches(int $libraryId): array
{
    $rows = all('SELECT c.id, c.name,
                        (SELECT COUNT(*) FROM items i WHERE i.category_id = c.id) AS here,
                        (SELECT COUNT(*) FROM categories k WHERE k.parent_id = c.id) AS kids
                   FROM categories c
                  WHERE c.library_id = ? AND c.parent_id IS NULL AND c.platform_id IS NULL
                  ORDER BY c.name', [$libraryId]);
    if ($rows === []) {
        return maintenance_result(0, [], 'Every top-level branch is a machine.');
    }

    $out = [];
    foreach ($rows as $row) {
        // What is under it decides whether this is a tidy-up or a decision.
        $under = (int) $row['here'] + (int) $row['kids'];
        $out[] = [
            'what'   => (string) $row['name'],
            'detail' => $under === 0
                ? 'empty, so it can go'
                : sprintf('%d entr%s and %d branch%s under it — left alone',
                          (int) $row['here'], (int) $row['here'] === 1 ? 'y' : 'ies',
                          (int) $row['kids'], (int) $row['kids'] === 1 ? '' : 'es'),
            'id'     => (int) $row['id'],
            'empty'  => $under === 0,
        ];
    }
    $canGo = count(array_filter($out, static fn($r) => $r['empty']));
    return maintenance_result(count($out), $out, sprintf(
        '%d of these %s empty and can be removed; the rest hold something.',
        $canGo, $canGo === 1 ? 'is' : 'are'));
}

/** Delete the empty ones only. */
function maintenance_repair_rootless_branches(int $libraryId): array
{
    $found = maintenance_check_rootless_branches($libraryId);
    $gone  = 0;
    foreach ($found['rows'] as $row) {
        if (($row['empty'] ?? false) !== true) {
            continue;
        }
        q('DELETE FROM categories WHERE id = ?', [(int) $row['id']]);
        $gone++;
    }
    if ($gone > 0) {
        category_rebuild_paths();
    }
    $left = count($found['rows']) - $gone;
    return ['done' => true, 'message' => match (true) {
        $gone === 0 => 'Nothing was empty enough to remove.',
        $left === 0 => $gone === 1 ? '1 branch removed.' : $gone . ' branches removed.',
        default     => sprintf('%d removed. %d left, because %s something under %s.',
                               $gone, $left, $left === 1 ? 'it has' : 'they have',
                               $left === 1 ? 'it' : 'them'),
    }];
}

/**
 * Machines with nowhere to file anything.
 *
 * The mirror of the job above. A platform and its branch are the same fact seen
 * twice, and for a while it was possible to make one without the other.
 */
function maintenance_check_branchless_machines(int $libraryId): array
{
    $rows = all('SELECT p.id, p.name
                   FROM platforms p
                  WHERE p.library_id = ?
                    AND NOT EXISTS (SELECT 1 FROM categories c
                                     WHERE c.library_id = p.library_id
                                       AND c.platform_id = p.id AND c.parent_id IS NULL)
                  ORDER BY p.name', [$libraryId]);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'what'   => (string) $row['name'],
            'detail' => 'no branch in the category tree',
            'id'     => (int) $row['id'],
        ];
    }
    return maintenance_result(count($out), $out,
        $out === [] ? 'Every machine has a branch.' : '');
}

/** One branch each, exactly as adding a machine does now. */
function maintenance_repair_branchless_machines(int $libraryId): array
{
    $found = maintenance_check_branchless_machines($libraryId);
    $made  = 0;
    foreach ($found['rows'] as $row) {
        // The same function the platform screen calls, so a branch made here and
        // a branch made there are the same thing.
        if (platform_ensure_root((int) $row['id'], $libraryId, (string) $row['what']) !== null) {
            $made++;
        }
    }
    return ['done' => true, 'message' => match (true) {
        $made === 0 => 'Nothing needed one.',
        $made === 1 => '1 machine now has a branch.',
        default     => $made . ' machines now have a branch.',
    }];
}

/**
 * Forget photograph rows whose file is genuinely gone.
 *
 * Only when some photographs *are* present.
 *
 * That is the whole safety of it. A handful missing out of hundreds means a few
 * files were lost - a directory deleted and rebuilt, a copy that missed some -
 * and the rows describe nothing. Every single one missing means the uploads
 * directory is not the one the pictures are in, and deleting the rows in that
 * case throws away the catalogue's memory of pictures that are sitting safely
 * somewhere else.
 *
 * So the repair refuses precisely when it would do the most damage, which is also
 * when it looks most needed.
 */
function maintenance_repair_missing_uploads(): array
{
    $found = maintenance_check_missing_uploads();
    if ($found['count'] === 0) {
        return ['done' => true, 'message' => 'Nothing to forget.'];
    }

    $total = (int) scalar('SELECT COUNT(*) FROM item_images');
    if ($found['count'] >= $total) {
        return ['done' => false, 'message' => sprintf(
            'All %d photographs are missing, which is a path rather than a loss. '
            . 'Check uploads.dir before removing anything - if the files are elsewhere, '
            . 'these rows are the only record of them.', $total)];
    }

    $gone = 0;
    foreach ($found['rows'] as $row) {
        if (empty($row['id'])) {
            continue;
        }
        q('DELETE FROM item_images WHERE id = ?', [(int) $row['id']]);
        $gone++;
    }

    return ['done' => true, 'message' => $gone === 1
        ? '1 row forgotten; its file was not there.'
        : $gone . ' rows forgotten; their files were not there.'];
}
