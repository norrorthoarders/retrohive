<?php
declare(strict_types=1);

/**
 * Structure data, kept outside the code.
 *
 * The companies, categories, platforms, hardware and software models a new
 * library is classified against are not really software: they are a catalogue
 * of facts about the world that grows as people notice omissions, and shipping
 * them inside db/seed.sql meant a new platform needed a release.
 *
 * It lives in JSON files instead - in structure/ for an install with no
 * network, and in the same directory on GitHub so an existing instance can pick
 * up additions without being upgraded. Same name in both places on purpose.
 *
 * "Structure," not "template": every row here is vocabulary an entry is filed
 * against - what it is, not an example of one. A template row - an example
 * machine or a couple of catalogued peripherals, meant to be edited or deleted
 * once somebody has their own - is a different thing this file does not handle
 * yet. That distinction is why this file lost the word "template" from its own
 * name; conflating the two was the source of the confusion this rename exists
 * to fix.
 *
 * Everything written here is a *structure* row: library_id NULL for the tables
 * that have it, never used by an entry directly, copied into a library when one
 * is created. Synchronising therefore cannot damage anybody's collection - the
 * worst it can do is add a machine nobody wanted, and remove nothing.
 */

/** Where the files are fetched from when synchronising. */
function structure_source_url(): string
{
    return rtrim((string) setting(
        'structure_source',
        'https://raw.githubusercontent.com/norrorthoarders/retrohive/main/structure'
    ), '/');
}

/**
 * The local copies.
 *
 * `structure/` at the project root, and deliberately the same name the
 * directory has in the repository, so the correspondence between what is on
 * disk and what is being fetched is obvious rather than something to work out.
 *
 * Not `templates/`, which this project has used for view templates since the
 * beginning: two different meanings of the word one directory apart is how
 * somebody ends up editing the wrong thing. Outside `public/`, so nothing
 * serves them.
 */
function structure_local_dir(): string
{
    return APP_ROOT . '/structure';
}

/**
 * What a company makes, from the feed it arrived in and anything the row says.
 *
 * The feed is the reliable half: a row in hardware_manufacturers.json made hardware,
 * whatever else it also did. A row may name its own tags, so a studio that also built
 * a cartridge can say so without needing to appear in two files.
 */
function company_makes_from(array $row, string $default): string
{
    $claimed = $row['makes'] ?? null;
    $out     = [$default];
    if (is_string($claimed)) {
        $claimed = array_map('trim', explode(',', $claimed));
    }
    if (is_array($claimed)) {
        foreach ($claimed as $m) {
            if (in_array($m, ['hardware', 'software', 'video', 'audio'], true)) {
                $out[] = $m;
            }
        }
    }
    return implode(',', array_values(array_unique($out)));
}

/** Two tag sets joined, because a firm named in both feeds is in both. */
function company_makes_merge(string $have, string $add): string
{
    $all = array_filter(array_merge(explode(',', $have), explode(',', $add)));
    $all = array_values(array_unique(array_intersect(['hardware', 'software', 'video', 'audio'], $all)));
    return implode(',', $all);
}

/**
 * A platform's own domains, from the template row's own "domains" list -
 * companies.makes's own pattern, not re-derived: read what the row claims,
 * keep only the real values, fall back to the caller's default if it
 * claimed nothing usable. Unlike company_makes_from(), all four sections
 * are real answers here rather than two - a platform genuinely can be a
 * video-format and nothing else, the same way a company can make only
 * software.
 */
function platform_domains_from(array $row, string $default): string
{
    $claimed = $row['domains'] ?? null;
    if (is_string($claimed)) {
        $claimed = array_map('trim', explode(',', $claimed));
    }
    $out = [];
    if (is_array($claimed)) {
        foreach ($claimed as $d) {
            if (in_array($d, ['hardware', 'software', 'video', 'audio'], true)) {
                $out[] = $d;
            }
        }
    }
    return $out === [] ? $default : implode(',', array_values(array_unique($out)));
}

/**
 * The files, in the order they must be applied.
 *
 * Order matters: a platform names a manufacturer, a genre names a category, a
 * model names both a platform and a category. Applying them in any other order
 * leaves the references unresolved.
 */
function structure_files(): array
{
    // Named for what somebody opening the file is looking for, and split
    // wherever one file was answering two questions. A machine and the card
    // that goes in it are not the same kind of thing; nor are a game studio and
    // a spreadsheet publisher.
    return [
        'hardware_manufacturers' => 'Hardware manufacturers',
        'software_categories'  => 'Software types',
        'hardware_categories'  => 'Hardware types',
        // No game_genres file. It was the same shape as software_categories with the
        // parent key spelled "category" instead of "parent" - a second file, a second
        // import case and a second name for one idea, left over from when a genre was
        // its own kind of thing. Its rows live in software_categories.json now.
        'game_developers'      => 'Game developers and publishers',
        'software_developers'  => 'Software developers and publishers',
        'platforms'            => 'Platforms',
        // One file. Splitting it made two names for one question - what a
        // machine has and what a card brings are both "what is in this thing",
        // and the kind column already told them apart.
        'hardware_specifications' => 'Ports, sockets and specifications',
        'hardware_machines'    => 'Machine models',
        'hardware_peripherals' => 'Peripherals and expansion cards',
        // The software counterpart: what a boxed release generally is, so a title made
        // from one starts with its fields and its box contents already filled in.
        'software_models'      => 'Software models',
        // What each machine runs. After platforms, because every row names one.
        'environments'         => 'Environments',
        'video_categories'     => 'Video types',
        'audio_categories'     => 'Audio types',
        'credit_roles'         => 'Credit roles',
    ];
}

/**
 * Read one file, from the network or from disk.
 *
 * @return array{0: ?array, 1: string}  rows, or null and why not
 */
function structure_read(string $name, bool $remote): array
{
    if (!array_key_exists($name, structure_files())) {
        return [null, 'Unknown template: ' . $name];
    }

    if ($remote) {
        [$body, $error] = metadata_http_get(structure_source_url() . '/' . $name . '.json', [], 20);
        // Remembered, so the settings screen can say what went wrong without
        // anybody pressing anything. Short: which of the two failures it was.
        set_setting('structure_last_error', $error === null ? '' : 'Could not fetch ' . $name
            . '.json from that address — failed to connect.');
        if ($body === null) {
            // Fall back to the copy that shipped rather than failing. A
            // repository that has not been populated yet, a proxy in the way, a
            // network that is down - none of those should mean an instance
            // starts with nothing, and the answer is sitting on disk.
            $local = structure_local_dir() . '/' . $name . '.json';
            if (is_file($local)) {
                $rows = json_decode((string) file_get_contents($local), true);
                if (is_array($rows)) {
                    return [$rows, 'fell back to the copy that shipped: '
                                 . ($error ?: 'could not fetch it')];
                }
            }
            return [null, $error ?: 'Could not fetch ' . $name . '.json'];
        }
    } else {
        $path = structure_local_dir() . '/' . $name . '.json';
        if (!is_file($path)) {
            return [null, 'No local copy of ' . $name . '.json'];
        }
        $body = (string) file_get_contents($path);
    }

    $rows = json_decode($body, true);
    if (!is_array($rows)) {
        set_setting('structure_last_error', $name . '.json from that address is malformed.');
        return [null, $name . '.json is not valid JSON'];
    }
    set_setting('structure_last_error', '');

    // A file that parses but holds something else - an error page from a proxy,
    // say - would otherwise be applied as zero rows and called a success.
    if ($rows !== [] && !is_array(reset($rows))) {
        return [null, $name . '.json is JSON but not a list of records'];
    }

    return [$rows, ''];
}

/**
 * Apply one file. Returns [added, skipped, error].
 *
 * Nothing is deleted and nothing already present is overwritten: somebody who
 * corrected a year in their own template should not have it corrected back.
 * A synchronise adds what is new and leaves the rest alone.
 */
function structure_apply(string $name, array $rows, bool $force = false): array
{
    $added   = 0;
    $updated = 0;
    $seen    = 0;
    $skipped = 0;
    $failed  = [];

    foreach ($rows as $row) {
        if (!is_array($row) || trim((string) ($row['slug'] ?? '')) === '') {
            continue;
        }
        $slug = trim((string) $row['slug']);
        $seen++;
        // Reset per row: the force branches below test it, and a stale value
        // from the previous iteration would update the wrong record.
        $have = null;

        try {
            switch ($name) {
                case 'hardware_manufacturers':
                    $have = one('SELECT id, makes FROM companies WHERE library_id IS NULL AND slug = ?', [$slug]);
                    if ($have !== null && !$force) {
                        // Skipping the row still leaves the tag to add.
                        //
                        // A company named by both feeds - Commodore built machines and
                        // published games - was created by whichever ran first and then
                        // skipped by the other, so it carried one tag and appeared in
                        // one picker. Tagging is additive information rather than an edit
                        // somebody made, so it is merged even when the rest of the row is
                        // left alone.
                        $merged = company_makes_merge((string) ($have['makes'] ?? ''),
                                                      company_makes_from($row, 'hardware'));
                        if ($merged !== (string) ($have['makes'] ?? '')) {
                            update_row('companies', (int) $have['id'], ['makes' => $merged]);
                            $updated++;
                        }
                        break;
                    }
                    $fields = [
                        'library_id' => null,
                        'name'       => mb_substr((string) ($row['name'] ?? $slug), 0, 120),
                        'slug'       => $slug,
                        'country'    => $row['country'] ?? null,
                        'founded_year' => (function ($y) {
                            $y = (int) $y;
                            return $y >= 1800 && $y <= (int) date('Y') ? $y : null;
                        })($row['founded'] ?? 0),
                        // The rest of the company fields, matching what the studio
                        // list already carries. Absent keys stay NULL, so an older
                        // manufacturers file still imports cleanly.
                        'defunct_year' => (function ($y) {
                            $y = (int) $y;
                            return $y >= 1800 && $y <= (int) date('Y') + 1 ? $y : null;
                        })($row['defunct'] ?? ($row['closed'] ?? 0)),
                        'website'    => filter_var((string) ($row['website'] ?? ''), FILTER_VALIDATE_URL)
                            ? mb_substr((string) $row['website'], 0, 500) : null,
                        'wikipedia_url' => filter_var((string) ($row['wikipedia'] ?? ''), FILTER_VALIDATE_URL)
                            ? mb_substr((string) $row['wikipedia'], 0, 500) : null,
                        'notes'      => isset($row['notes']) && trim((string) $row['notes']) !== ''
                            ? (string) $row['notes'] : null,

                        // What they made.
                        //
                        // This feed never set it, so a company imported from the
                        // repository arrived with an empty `makes` and appeared in no
                        // picker at all - the tag existed only because the old SQL seed
                        // file wrote it directly, a path a sync never runs.
                        // The file it came from is the answer: this is the manufacturers
                        // list, so hardware, plus whatever the row claims for itself.
                        'makes'      => company_makes_from($row, 'hardware'),
                    ];
                    if (isset($have) && $have !== null) {
                        // Forced: correct what is here rather than adding a second.
                        // The tag is merged rather than replaced, so a firm already
                        // marked as making software keeps that when the hardware feed
                        // names it too - Commodore is in both lists and is both.
                        $fields['makes'] = company_makes_merge(
                            (string) (scalar('SELECT makes FROM companies WHERE id = ?', [(int) $have['id']]) ?? ''),
                            $fields['makes']
                        );
                        update_row('companies', (int) $have['id'], $fields);
                        $updated++;
                    } else {
                        insert_row('companies', $fields);
                        $added++;
                    }
                    break;

                case 'software_categories':
                case 'hardware_categories':
                case 'video_categories':
                case 'audio_categories':
                    $have = one('SELECT id FROM categories WHERE slug = ? AND library_id IS NULL', [$slug]);
                    if ($have !== null && !$force) {
                        break;
                    }
                    // A row that names a parent must find it.
                    //
                    // Unresolved, this used to fall through to null and insert the row
                    // as a top-level category - so a typo in the parent slug quietly
                    // promoted a genre to a heading beside Games rather than under it.
                    // Skipping is what the old game_genres feed did, and it is right:
                    // no row is better than a row in the wrong place.
                    $parent = null;
                    if (!empty($row['parent'])) {
                        $parent = scalar('SELECT id FROM categories WHERE slug = ? AND library_id IS NULL',
                                         [$row['parent']]);
                        if ($parent === null) {
                            $skipped++;
                            break;
                        }
                    }
                    $fields = [
                        // The file says which, by its own name - hardware_categories.json
                        // declares hardware, video_categories.json declares video, and so
                        // on. A section added later is a new file matching this same
                        // pattern, not a line to come back and edit here.
                        'section_id' => (int) scalar('SELECT id FROM sections WHERE slug = ?',
                                                     [str_replace('_categories', '', $name)]),
                        // Resolved once rather than tested and re-read: the
                        // coalesce used to be in the condition only, so a row
                        // with no role passed the check and then wrote null.
                        // Games and applications as well as machines and
                        // peripherals, movies and TV shows, music. The list here is
                        // what the column accepts, and leaving a section's roles out
                        // of it would have quietly rewritten every declared branch
                        // for that section back to "other" on the next template
                        // import - undoing the thing the file had just been updated
                        // to say.
                        'role'       => in_array($role = (string) ($row['role'] ?? 'other'),
                                                 ['machine', 'peripheral', 'game', 'application',
                                                  'movie', 'tv_show', 'music', 'other'], true)
                            ? $role : 'other',
                        'parent_id'  => $parent,
                        'name'       => mb_substr((string) ($row['name'] ?? $slug), 0, 120),
                        'slug'       => $slug,
                        // Which machines this kind belongs on. Absent means all of them,
                        // which is the right default for anything genuinely universal -
                        // and the feed can say otherwise without a release.
                        'applies_to' => (function ($v) {
                            $ok = ['computer', 'console', 'handheld', 'video-format', 'audio-format'];
                            $in = is_array($v) ? $v : explode(',', (string) $v);
                            $in = array_values(array_filter(array_map(
                                fn($c) => strtolower(trim((string) $c)), $in
                            ), fn($c) => in_array($c, $ok, true)));
                            return implode(',', $in);
                        })($row['classes'] ?? ''),
                        'sort_order' => (int) ($row['sort_order'] ?? 0),
                    ];
                    if (isset($have) && $have !== null) {
                        // Forced: correct what is here rather than adding a second.
                        update_row('categories', (int) $have['id'], $fields);
                        $updated++;
                    } else {
                        insert_row('categories', $fields);
                        $added++;
                    }
                    break;

                case 'game_developers':
                case 'software_developers':
                    $have = one('SELECT id, makes FROM companies WHERE slug = ?', [$slug]);
                    if ($have !== null && !$force) {
                        // Skipping the row still leaves the tag to add.
                        //
                        // A company named by both feeds - Commodore built machines and
                        // published games - was created by whichever ran first and then
                        // skipped by the other, so it carried one tag and appeared in
                        // one picker. Tagging is additive information rather than an edit
                        // somebody made, so it is merged even when the rest of the row is
                        // left alone.
                        $merged = company_makes_merge((string) ($have['makes'] ?? ''),
                                                      company_makes_from($row, 'software'));
                        if ($merged !== (string) ($have['makes'] ?? '')) {
                            update_row('companies', (int) $have['id'], ['makes' => $merged]);
                            $updated++;
                        }
                        break;
                    }
                    $fields = [
                        'domain'  => in_array($dom = (string) ($row['domain'] ?? ''),
                                              ['game', 'software', 'both'], true)
                            ? $dom
                            : ($name === 'software_developers' ? 'software' : 'game'),
                        'name'    => mb_substr((string) ($row['name'] ?? $slug), 0, 160),
                        'slug'    => $slug,
                        'country' => $row['country'] ?? null,
                        // No founding year: companies has never had that column,
                        // and inventing one in the writer is how a template file
                        // starts describing a schema that does not exist.
                        'website' => $row['website'] ?? null,
                        // The studio feeds, so software - same reasoning as the
                        // manufacturers list above, and the same omission before this.
                        'makes'   => company_makes_from($row, 'software'),
                    ];
                    if (isset($have) && $have !== null) {
                        // Merged, not replaced: Commodore is in both feeds and is both.
                        $fields['makes'] = company_makes_merge(
                            (string) (scalar('SELECT makes FROM companies WHERE id = ?', [(int) $have['id']]) ?? ''),
                            $fields['makes']
                        );
                        update_row('companies', (int) $have['id'], $fields);
                        $updated++;
                    } else {
                        insert_row('companies', $fields);
                        $added++;
                    }
                    break;

                case 'software_models':
                    // Template rows only. A model is copied into a library when that
                    // library is made, exactly as a machine model is.
                    $have = one('SELECT id FROM software_models WHERE library_id IS NULL AND slug = ?', [$slug]);
                    if ($have !== null && !$force) {
                        break;
                    }
                    $fields = [
                        'library_id'  => null,
                        'platform_id' => empty($row['platform']) ? null
                            : scalar('SELECT id FROM platforms WHERE library_id IS NULL AND slug = ?', [$row['platform']]),
                        'category_id' => empty($row['category']) ? null
                            : scalar('SELECT id FROM categories WHERE library_id IS NULL AND slug = ?', [$row['category']]),
                        'name'        => mb_substr((string) ($row['name'] ?? $slug), 0, 160),
                        'slug'        => $slug,
                        'media'       => nullify($row['media'] ?? null),
                        'year_from'   => isset($row['year_from']) ? (int) $row['year_from'] : null,
                        'notes'       => nullify($row['notes'] ?? null),
                        'sort_order'  => (int) ($row['sort_order'] ?? 0),
                    ];
                    // update_row() returns void, so the ternary that used to be
                    // here tested null and then returned the same id from both
                    // arms. Written straight: update, and the id is the one we
                    // already had.
                    if ($have !== null) {
                        update_row('software_models', (int) $have['id'], $fields);
                        $modelId = (int) $have['id'];
                    } else {
                        $modelId = (int) insert_row('software_models', $fields);
                    }

                    // Replaced wholesale rather than merged: a model's fields are one
                    // statement about a format, and half of an old one mixed with half
                    // of a new one is a statement nobody made.
                    q('DELETE FROM software_model_fields WHERE model_id = ?', [$modelId]);
                    foreach ((array) ($row['fields'] ?? []) as $i => $f) {
                        $label = trim((string) ($f['label'] ?? ''));
                        if ($label === '') { continue; }
                        q('INSERT IGNORE INTO software_model_fields (model_id, label, default_value, hint, sort_order)
                           VALUES (?, ?, ?, ?, ?)',
                          [$modelId, mb_substr($label, 0, 60),
                           nullify($f['default_value'] ?? null), nullify($f['hint'] ?? null), ($i + 1) * 10]);
                    }

                    q('DELETE FROM software_model_contents WHERE model_id = ?', [$modelId]);
                    foreach ((array) ($row['contents'] ?? []) as $i => $c) {
                        $label = trim((string) ($c['label'] ?? ''));
                        if ($label === '') { continue; }
                        q('INSERT IGNORE INTO software_model_contents (model_id, label, note, sort_order)
                           VALUES (?, ?, ?, ?)',
                          [$modelId, mb_substr($label, 0, 120), nullify($c['note'] ?? null), ($i + 1) * 10]);
                    }
                    $have === null ? $added++ : $updated++;
                    break;

                case 'platforms':
                    $have = one('SELECT * FROM platforms WHERE library_id IS NULL AND slug = ?', [$slug]);
                    // An existing platform used to be skipped outright unless the
                    // sync was forced, so a maker added to the templates later
                    // never reached an install that already had the row - and
                    // forcing was no answer, because it also overwrites a name
                    // somebody has edited.
                    //
                    // A gap is not a customisation. Where the local row has
                    // nothing and the template has something, the template wins;
                    // where the local row has an answer it is left alone.
                    $fillOnly = $have !== null && !$force;
                    $fields = [
                        'library_id'      => null,
                        'vendor_id'       => empty($row['maker'])
                            ? null : scalar('SELECT id FROM companies WHERE library_id IS NULL AND slug = ?', [$row['maker']]),
                        'name'            => mb_substr((string) ($row['name'] ?? $slug), 0, 120),
                        'slug'            => $slug,
                        'year_introduced' => isset($row['year']) ? (int) $row['year'] : null,
                        // The fallback used when a platform names no domains at all.
                        'domains'         => platform_domains_from($row, 'hardware,software'),
                        'accent_color'    => preg_match('/^#[0-9a-f]{6}$/i', (string) ($row['colour'] ?? ''))
                            ? $row['colour'] : '#a6adc8',
                    ];
                    if (isset($have) && $have !== null) {
                        if ($fillOnly) {
                            // Only the empty ones, and only where the template has
                            // an answer worth writing.
                            $gaps = [];
                            foreach (['vendor_id', 'year_introduced'] as $field) {
                                if (($have[$field] ?? null) === null && $fields[$field] !== null) {
                                    $gaps[$field] = $fields[$field];
                                }
                            }
                            if ($gaps !== []) {
                                update_row('platforms', (int) $have['id'], $gaps);
                                $updated++;
                            }
                            break;
                        }
                        // Forced: correct what is here rather than adding a second.
                        update_row('platforms', (int) $have['id'], $fields);
                        $updated++;
                    } else {
                        insert_row('platforms', $fields);
                        $added++;
                    }
                    break;

                case 'credit_roles':
                    $have = one('SELECT * FROM credit_roles WHERE library_id IS NULL AND slug = ?', [$slug]);
                    $fillOnly = $have !== null && !$force;
                    $fields = [
                        'library_id' => null,
                        'name'       => mb_substr((string) ($row['name'] ?? $slug), 0, 80),
                        'slug'       => $slug,
                        // Reused rather than re-derived: the same helper platforms
                        // already uses, since this row's own "domains" field is the
                        // identical shape - a JSON array of the same four values.
                        'domains'    => platform_domains_from($row, 'software'),
                        'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 100,
                    ];
                    if ($have !== null) {
                        if ($fillOnly) {
                            // A role's name and domains are what it means, not a
                            // customisation somebody would leave half-set - unlike
                            // platforms' vendor or year, there is no gap here worth
                            // filling only part of. Left alone unless forced.
                            break;
                        }
                        update_row('credit_roles', (int) $have['id'], $fields);
                        $updated++;
                    } else {
                        insert_row('credit_roles', $fields);
                        $added++;
                    }
                    break;

                case 'environments':
                    // Matched to a template platform by slug, like everything else that
                    // hangs off one. A row naming a machine this instance does not have
                    // is skipped rather than orphaned: environments are per platform,
                    // and one with no platform is not a thing.
                    $platId = empty($row['platform'])
                        ? null
                        : scalar('SELECT id FROM platforms WHERE library_id IS NULL AND slug = ?',
                                 [(string) $row['platform']]);
                    if ($platId === null) {
                        $skipped++;
                        break;
                    }
                    $have = one('SELECT id FROM operating_systems WHERE library_id IS NULL AND slug = ?', [$slug]);
                    if ($have !== null && !$force) {
                        break;
                    }
                    $fields = [
                        'library_id'  => null,
                        'platform_id' => (int) $platId,
                        'name'        => mb_substr((string) ($row['name'] ?? $slug), 0, 120),
                        'slug'        => $slug,
                        'sort_order'  => (int) ($row['sort_order'] ?? 0),
                    ];
                    if ($have !== null) {
                        update_row('operating_systems', (int) $have['id'], $fields);
                        $updated++;
                    } else {
                        insert_row('operating_systems', $fields);
                        $added++;
                    }
                    break;

                case 'hardware_specifications':
                    $platform = empty($row['platform'])
                        ? 0
                        : (int) (scalar('SELECT id FROM platforms WHERE library_id IS NULL AND slug = ?', [$row['platform']]) ?? 0);
                    if (!empty($row['platform']) && $platform === 0) {
                        break;
                    }
                    // The file decides the default kind, so a row in
                    // features.json does not have to say so on every line - but
                    // an explicit kind still wins, because sockets and form
                    // factors are connections too.
                    // Every row says which it is now that one file holds all
                    // four; interface is the common case and the fallback.
                    $kind = in_array($row['kind'] ?? '', ['interface', 'socket', 'formfactor', 'feature'], true)
                        ? (string) $row['kind']
                        : 'interface';

                    $have = one('SELECT id FROM hardware_vocab WHERE kind = ? AND platform_id = ? AND code = ?',
                                [$kind, $platform, $slug]);
                    if ($have !== null && !$force) {
                        break;
                    }
                    $fields = [
                        'kind'        => $kind,
                        'platform_id' => $platform,
                        'code'        => $slug,
                        'name'        => mb_substr((string) ($row['name'] ?? $slug), 0, 120),
                        'sort_order'  => (int) ($row['sort_order'] ?? 0),
                    ];
                    if (isset($have) && $have !== null) {
                        // Forced: correct what is here rather than adding a second.
                        update_row('hardware_vocab', (int) $have['id'], $fields);
                        $updated++;
                    } else {
                        insert_row('hardware_vocab', $fields);
                        $added++;
                    }
                    break;

                case 'hardware_machines':
                case 'hardware_peripherals':
                    // Template rows only - library_id IS NULL. The importer curates the
                    // set that gets copied into each new library; it never touches a
                    // library's own models, which are the operator's to edit.
                    $have = one('SELECT id FROM hardware_models WHERE slug = ? AND library_id IS NULL',
                                [$slug]);
                    if ($have !== null && !$force) {
                        break;
                    }
                    $platform = empty($row['platform'])
                        ? null : scalar('SELECT id FROM platforms WHERE library_id IS NULL AND slug = ?', [$row['platform']]);

                    // A model must have a kind: hardware_models.category_id is NOT
                    // NULL since migration 0011, because a model that is neither a
                    // machine nor a part falls out of every query that joins
                    // categories. A row naming a type this instance does not have
                    // is reported and skipped - before the constraint it inserted
                    // NULL and looked like a success.
                    $categoryId = empty($row['type'])
                        ? null : scalar('SELECT id FROM categories WHERE slug = ? AND library_id IS NULL', [$row['type']]);
                    if ($categoryId === null) {
                        // Same convention as the catch below: recorded as a
                        // failure with a reason, never as a skip.
                        $failed[] = $slug . ': names the type "'
                                  . (string) ($row['type'] ?? '')
                                  . '", which is not in the filing tree.';
                        break;
                    }

                    $fields = [
                        'library_id'  => null,
                        'vendor_id'   => empty($row['maker'])
                            ? null : scalar('SELECT id FROM companies WHERE library_id IS NULL AND slug = ?', [$row['maker']]),
                        'platform_id' => $platform,
                        'category_id' => (int) $categoryId,
                        'name'        => mb_substr((string) ($row['name'] ?? $slug), 0, 160),
                        'slug'        => $slug,
                        'year_from'   => isset($row['year']) ? (int) $row['year'] : null,
                        'fits_note'   => $row['fits'] ?? null,
                        'interface'   => $row['interface'] ?? null,
                        'notes'       => $row['notes'] ?? null,
                        'sort_order'  => (int) ($row['sort_order'] ?? 0),
                    ];
                    if (isset($have) && $have !== null) {
                        // Forced: correct what is here rather than adding a second.
                        update_row('hardware_models', (int) $have['id'], $fields);
                        $modelId = (int) $have['id'];
                        $updated++;
                    } else {
                        $modelId = (int) insert_row('hardware_models', $fields);
                        $added++;
                    }

                    // Which machines it fits, by slug. Applied after the row
                    // because the set points at it, and resolved by slug
                    // because a file cannot know anybody's ids.
                    if (array_key_exists('compatible_models', $row)) {
                        $targets = [];
                        foreach ((array) ($row['compatible_models'] ?? []) as $slug) {
                            $hit = one('SELECT id FROM hardware_models WHERE slug = ? AND library_id IS NULL',
                                       [(string) $slug]);
                            if ($hit !== null) {
                                $targets[] = (int) $hit['id'];
                            }
                        }
                        set_model_compatibility($modelId, $targets);
                    }

                    // The fields the model carries. They came with it in the
                    // file and are the point of a model existing, so a template
                    // install without them produced machines that describe
                    // nothing.
                    if (!empty($row['fields']) && is_array($row['fields'])) {
                        q('DELETE FROM model_fields WHERE model_id = ?', [$modelId]);
                        $order = 0;
                        foreach ($row['fields'] as $f) {
                            $label = trim((string) ($f['label'] ?? ''));
                            if ($label === '') {
                                continue;
                            }
                            $order += 10;
                            insert_row('model_fields', [
                                'model_id'      => $modelId,
                                'label'         => mb_substr($label, 0, 80),
                                'default_value' => trim((string) ($f['value'] ?? '')) ?: null,
                                'sort_order'    => $order,
                            ]);
                        }
                    }

                    // What the machine physically has.
                    //
                    // Absent from this importer until now, and the omission was
                    // quiet in the worst way: a model with no slots declared can
                    // hold nothing, so every machine that arrived through a
                    // synchronise rather than through the old SQL seed file
                    // refused every card, and refused it by simply not offering
                    // any. Replaced wholesale rather than merged, like the
                    // fields above: a slot list is one statement about a board.
                    //
                    // Codes are resolved against this platform's vocabulary, or
                    // the platform-agnostic sentinel where it has none of its
                    // own - the same rule the old SQL seed file applied, because
                    // 'cpu' means a CPU slot on an Amiga and something more
                    // general elsewhere. A code the platform does not know is
                    // skipped rather than invented.
                    if (array_key_exists('slots', $row) && is_array($row['slots'])) {
                        q('DELETE FROM model_slots WHERE model_id = ?', [$modelId]);
                        foreach ($row['slots'] as $s) {
                            $code = trim((string) (is_array($s) ? ($s['code'] ?? '') : $s));
                            if ($code === '') {
                                continue;
                            }
                            $vocabId = scalar(
                                'SELECT id FROM hardware_vocab
                                  WHERE code = ? AND platform_id IN (?, 0)
                               ORDER BY platform_id DESC LIMIT 1',
                                [$code, (int) ($platform ?? 0)]
                            );
                            if ($vocabId === null) {
                                continue;
                            }
                            q('INSERT IGNORE INTO model_slots (model_id, vocab_id, quantity, notes)
                               VALUES (?, ?, ?, ?)',
                              [$modelId, (int) $vocabId,
                               max(1, min(255, (int) (is_array($s) ? ($s['quantity'] ?? 1) : 1))),
                               is_array($s) ? nullify($s['notes'] ?? null) : null]);
                        }
                    }
                    break;
            }
        } catch (Throwable $e) {
            // One bad row should not abandon the file - but it must be counted
            // and returned. Lumping failures in with "skipped" is how thirty-four
            // broken rows looked like a clean synchronise.
            $failed[] = $slug . ': ' . $e->getMessage();
            error_log('[retrohive] template row failed (' . $name . '/' . $slug . '): ' . $e->getMessage());
        }
    }

    // Categories carry a materialised path, and a row inserted without one
    // sorts and nests wrongly for ever. The seed rebuilt it by hand; doing it
    // here means the JSON file does not have to carry a derived value.
    if (in_array($name, ['software_categories', 'hardware_categories',
                          'video_categories', 'audio_categories'], true)) {
        rebuild_category_paths();
    }

    // Skipped is what is left after everything that happened to it: rows added,
    // rows corrected under --force, rows that failed, and rows this function
    // stepped over deliberately because the parent or platform they named is not
    // here. $updated was missing from the subtraction, so a forced sync reported
    // every corrected row as skipped as well - the two columns summed to more
    // than the file had lines in it.
    $accounted = $added + $updated + count($failed) + $skipped;

    return [$added, max(0, $seen - $accounted) + $skipped, '', $updated, $failed];
}

// rebuild_category_paths() lives in models.php: it is a fact about categories,
// not about importing them, and the importer is only one of several callers.

/**
 * Apply every file. Returns [summary rows, errors].
 *
 * @param bool $remote  fetch from GitHub, or read the copies that shipped
 */
function structure_sync(bool $remote = true, bool $force = false): array
{
    $summary  = [];
    $errors   = [];
    $fellBack = [];

    foreach (structure_files() as $name => $label) {
        [$rows, $note] = structure_read($name, $remote);
        if ($rows === null) {
            $errors[] = $label . ': ' . $note;
            $summary[$name] = ['label' => $label, 'added' => 0, 'skipped' => 0,
                               'updated' => 0, 'failed' => 0, 'error' => $note];
            continue;
        }
        // A file that fell back still worked; say so rather than reporting
        // success as though the network had answered.
        if ($note !== '') {
            $fellBack[] = $label;
        }
        [$added, $skipped, , $updated, $failed] = structure_apply($name, $rows, $force);
        $summary[$name] = ['label' => $label, 'added' => $added, 'skipped' => $skipped,
                           'updated' => $updated, 'failed' => count($failed), 'error' => '',
                           // How many rows the file held. Recorded because it is
                           // the only number that can be compared with what is
                           // here afterwards - "added 0" is what a sync says
                           // whether the file matched or the fetch quietly served
                           // something a year old.
                           'in_file' => count($rows)];

        if ($failed !== []) {
            // Say how many and name the first, which is almost always enough to
            // see what is wrong with all of them.
            $errors[] = sprintf('%s: %d row%s could not be applied (%s)',
                                $label, count($failed), count($failed) === 1 ? '' : 's', $failed[0]);
        }
    }

    // The metadata agents come with the rest. Not through the loop above, because
    // the file is a map rather than a list of records - but it ships from the same
    // repository and goes stale the same way, and it was the one thing in
    // structure/ that a synchronise never touched.
    [$mapped, $agentNote] = structure_refresh_agents($remote);
    $summary['metadata_agents'] = ['label' => 'Metadata agents', 'added' => $mapped,
                                   'skipped' => 0, 'updated' => 0, 'failed' => 0,
                                   'error' => $mapped === 0 ? $agentNote : ''];

    set_setting('structure_synced_at', date('Y-m-d H:i:s'));
    set_setting('structure_synced_from', $remote ? structure_source_url() : 'the copies that shipped');
    // What this run saw, kept so the settings screen can show it beside what is
    // in the instance now. Without it the only record of a sync is a timestamp,
    // which says that one happened and nothing about what it did.
    // Rows per file, and what is here afterwards.
    //
    // Only two numbers are kept. Added, corrected, skipped and refused describe
    // one run and answer nothing later - "added 0" is what a sync says whether
    // the file matched what was here or the fetch quietly served something a year
    // old. How many the file held, against how many are here, is the difference
    // worth seeing.
    $remoteRows = [];
    foreach ($summary as $file => $s) {
        if (array_key_exists('in_file', $s)) {
            $remoteRows[$file] = (int) $s['in_file'];
        }
    }
    $localRows = [];
    foreach (structure_row_counts() as $row) {
        $localRows[$row['file']] = (int) $row['n'];
    }

    set_setting('structure_sync_report', json_encode([
        'at'     => date('Y-m-d H:i:s'),
        'from'   => $remote ? structure_source_url() : 'the copies that shipped',
        'forced' => $force,
        'remote' => $remoteRows,
        'local'  => $localRows,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    if (function_exists('log_server')) {
        // What is here afterwards, per kind, in the entry itself.
        //
        // The setting above holds one snapshot and is overwritten by the next
        // sync. The log keeps every one of them, which is what makes "when did
        // the peripherals go from 4 to 21" a question with an answer.
        $said = [];
        foreach (structure_row_counts() as $row) {
            $said[] = $row['holds'] . ' ' . $row['n'];
        }
        log_server('templates.synced', sprintf(
            'Starter data synchronised from %s%s: %d added, %d corrected. Here now: %s',
            $remote ? 'GitHub' : 'the local copies',
            $force ? ', forced' : '',
            array_sum(array_column($summary, 'added')),
            array_sum(array_column($summary, 'updated')),
            implode('; ', $said)
        ), LOG_NOTICE);
    }

    // Refreshing the templates does not touch anybody's library.
    //
    // This used to top up every library on the instance afterwards, which made a
    // template refresh a bulk write into shelves whose owners had not asked for
    // anything. Two things now depend on that not happening: a new library starts
    // empty on purpose, and the resync form lets somebody choose which parts to copy -
    // and this ran first, with no parts, so it copied everything and the choice was
    // silently overridden.
    //
    // Copying into a library is that library's decision, taken on its own page.


    if ($fellBack !== []) {
        set_setting('structure_synced_from',
            'the copies that shipped, for ' . implode(', ', $fellBack));
    }

    return [$summary, $errors, $fellBack];
}

// ---------------------------------------------------------------------------
// Is there a newer RetroHive?
//
// Asked, never acted on. Something that upgrades itself is something that can
// break itself at three in the morning, and this is a catalogue of somebody's
// possessions.
// ---------------------------------------------------------------------------

/**
 * Where the update check asks. Deliberately not a setting.
 *
 * The question is "has the project released something newer", and an instance
 * pointed at a feed it controls answers a different one - reporting itself
 * current forever, which is worse than never checking. The structure/ source
 * is configurable because that is the operator's own data; this is not.
 */
function update_check_url(): string
{
    return 'https://api.github.com/repos/norrorthoarders/retrohive/releases/latest';
}

/**
 * Ask the feed what the latest version is. Returns [version, url, error].
 *
 * Two shapes are understood, so hosting your own is a static file rather than
 * an API:
 *
 *   GitHub releases   {"tag_name": "v0.6.0", "html_url": "..."}
 *   Anything else     {"version": "0.6.0", "url": "...", "notes": "..."}
 *
 * The second is what you would write by hand. Neither is more official; the
 * first is only the default because GitHub already publishes it.
 */
function check_for_update(): array
{
    [$body, $error] = metadata_http_get(update_check_url(), ['Accept: application/vnd.github+json'], 15);
    if ($body === null) {
        // The most common failure by a distance, and the least obvious: the
        // releases endpoint answers 404 until a repository has published one,
        // which reads like a broken URL when it is an empty shelf.
        $why = str_contains((string) $error, '404') && str_contains(update_check_url(), 'api.github.com')
            ? 'GitHub answered 404, which that endpoint does when the repository has no '
              . 'releases yet. Publish a release tagged v' . APP_VERSION . ' or later, '
              . 'or point this at your own feed.'
            : ($error ?: 'Could not reach the release feed.');
        return update_check_failed($why);
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return update_check_failed('The release feed did not return JSON.');
    }

    $tag = $data['tag_name'] ?? $data['version'] ?? null;
    if ($tag === null || trim((string) $tag) === '') {
        return update_check_failed('The feed returned JSON but named no version. '
                                 . 'Expected tag_name (GitHub) or version (anything else).');
    }

    $data['html_url'] = $data['html_url'] ?? $data['url'] ?? '';
    $version = ltrim((string) $tag, 'vV');
    set_setting('update_error', '');
    set_setting('update_latest', $version);
    set_setting('update_checked_at', date('Y-m-d H:i:s'));
    set_setting('update_url', (string) ($data['html_url'] ?? ''));

    return [$version, (string) ($data['html_url'] ?? ''), ''];
}

/**
 * Record that the check did not work, and say so on the page afterwards.
 *
 * Without this the timestamp stayed unset and the settings screen said "never
 * checked" - which is a different thing from "checked, and could not tell",
 * and the one that matters is the second.
 */
function update_check_failed(string $why): array
{
    set_setting('update_checked_at', date('Y-m-d H:i:s'));
    set_setting('update_error', $why);
    set_setting('update_latest', '');
    return [null, null, $why];
}

/** Why the last check could not answer, if it could not. */
function update_check_error(): string
{
    return (string) setting('update_error', '');
}

/** Is the version we last heard about newer than the one running? */
function update_available(): bool
{
    $latest = (string) setting('update_latest', '');
    return $latest !== '' && version_compare($latest, APP_VERSION, '>');
}

/**
 * Fetch the metadata agent file and put it where the code reads it.
 *
 * `metadata_agents.json` is not row-shaped: it is a map keyed by source, holding
 * what each has been tried on and what it calls our machines. So it cannot go
 * through structure_apply() with the rest, which inserts records into tables -
 * and because it was not in structure_files() either, it was never fetched at all.
 * The lists shipped in the tarball and stayed at whatever they were on release
 * day, which is exactly what putting them in the templates was meant to avoid.
 *
 * Written to disk rather than into a table, because that is where
 * metadata_provider_types() looks for it, and then applied to every source
 * already configured so a mapping that arrived today reaches a source added last
 * week.
 *
 * @return array{0:int,1:string} mappings written, and what happened
 */
function structure_refresh_agents(bool $remote): array
{
    $path = structure_local_dir() . '/metadata_agents.json';

    if ($remote) {
        [$body, $error] = metadata_http_get(structure_source_url() . '/metadata_agents.json', [], 20);
        if ($body === null) {
            // The copy that shipped is still an answer, same as every other file.
            return [0, 'metadata agents: kept the copy that shipped (' . ($error ?: 'could not fetch it') . ')'];
        }
        $read = json_decode($body, true);
        if (!is_array($read) || $read === []) {
            return [0, 'metadata agents: the file fetched is not readable, kept the copy that shipped'];
        }
        if (@file_put_contents($path, json_encode($read, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
            return [0, 'metadata agents: fetched, but ' . $path . ' could not be written'];
        }
        // metadata_provider_types() caches the file in a static on first read.
        unset($GLOBALS['__metadata_feed']);
    }

    // Whatever the file now says, applied to what is configured. New mappings
    // only - a correction somebody made by hand, or one automap took from the
    // service, is better evidence than a file.
    $written = 0;
    foreach (all('SELECT id, type FROM metadata_providers') as $p) {
        $written += metadata_seed_platform_map((int) $p['id'], (string) $p['type']);
    }

    return [$written, $written === 0
        ? 'metadata agents: nothing new to map'
        : 'metadata agents: ' . $written . ' platform mapping(s) added'];
}

/**
 * What each template file has put into the template scope.
 *
 * One row per file, in the words the rest of the application uses. The old
 * summary counted "manufacturers" and "genres" - names this application stopped
 * using when manufacturers and developers became companies and genres became
 * branches of the category tree - so the numbers described things nobody could
 * go and look at.
 *
 * @return array<int,array{file:string,holds:string,n:int}>
 */
function structure_row_counts(?int $libraryId = null): array
{
    // Null counts the template set; an id counts what one library holds of it.
    //
    // One function rather than two, because the two are compared side by side on
    // the library screen and a second implementation would be a second set of
    // labels to drift out of step with these.
    $tpl = $libraryId === null
        ? 'library_id IS NULL'
        : 'library_id = ' . (int) $libraryId;
    $platformScope = $libraryId === null
        ? 'p.library_id IS NULL'
        : 'p.library_id = ' . (int) $libraryId;
    $rows = [
        ['platforms', 'Machines', (int) scalar("SELECT COUNT(*) FROM platforms WHERE $tpl")],
        ['hardware_manufacturers', 'Companies that make hardware',
            (int) scalar("SELECT COUNT(*) FROM companies WHERE $tpl AND FIND_IN_SET('hardware', makes)")],
        ['game_developers, software_developers', 'Companies that make software',
            (int) scalar("SELECT COUNT(*) FROM companies WHERE $tpl AND FIND_IN_SET('software', makes)")],
        ['hardware_categories', 'Hardware branches',
            (int) scalar("SELECT COUNT(*) FROM categories c JOIN sections s ON s.id = c.section_id
                           WHERE c.$tpl AND s.slug = 'hardware'")],
        ['software_categories', 'Software branches',
            (int) scalar("SELECT COUNT(*) FROM categories c JOIN sections s ON s.id = c.section_id
                           WHERE c.$tpl AND s.slug = 'software'")],
        ['video_categories', 'Video branches',
            (int) scalar("SELECT COUNT(*) FROM categories c JOIN sections s ON s.id = c.section_id
                           WHERE c.$tpl AND s.slug = 'video'")],
        ['audio_categories', 'Audio branches',
            (int) scalar("SELECT COUNT(*) FROM categories c JOIN sections s ON s.id = c.section_id
                           WHERE c.$tpl AND s.slug = 'audio'")],
        // Machine or peripheral is the branch's business, not a flag on the
        // model - there is no is_machine column, and inventing one in a count
        // would have been a second opinion about something the tree decides.
        ['hardware_machines', 'Machine models',
            (int) scalar("SELECT COUNT(*) FROM hardware_models m
                            JOIN categories c ON c.id = m.category_id
                           WHERE m.$tpl AND c.role = 'machine'")],
        // Not `role = 'peripheral'`. A model is either a machine or a part, and
        // the part half is the counterpart of the line above rather than a role
        // of its own: the tree declares `peripheral` on the branch that means it
        // - Expansions - and everything under it says `other` and inherits. So
        // this counted only models filed directly under a declaring branch, which
        // is none of them, and reported 0 next to a file holding 21.
        ['hardware_peripherals', 'Peripheral models',
            (int) scalar("SELECT COUNT(*) FROM hardware_models m
                            JOIN categories c ON c.id = m.category_id
                           WHERE m.$tpl AND c.role <> 'machine'")],
        ['software_models', 'Software models',
            (int) scalar("SELECT COUNT(*) FROM software_models WHERE $tpl")],
        // Template rows only, which is the whole table minus every library's
        // copies of it.
        //
        // seed_library_hardware() copies the vocabulary for a library's own
        // platforms, because a library that has platforms and not the words for
        // what plugs into them cannot describe a card. So the table grows by
        // roughly the file's size with every library made - and counting all of
        // it against the file said 1158 against 589 on a freshly installed
        // instance, in red, for ever. The two numbers were counting different
        // things.
        //
        // platform_id 0 is the sentinel for "applies anywhere" and is shared
        // rather than copied, which is why those rows never doubled.
        ['hardware_specifications', 'Specification names',
            (int) scalar("SELECT COUNT(*) FROM hardware_vocab hv
                           WHERE hv.platform_id = 0
                              OR EXISTS (SELECT 1 FROM platforms p
                                          WHERE p.id = hv.platform_id
                                            AND $platformScope)")],
        ['environments', 'Environments',
            // The table is operating_systems; "environments" is what the file and
            // the screens call them.
            (int) scalar("SELECT COUNT(*) FROM operating_systems WHERE $tpl")],
    ];

    $out = [];
    foreach ($rows as [$file, $holds, $n]) {
        $out[] = ['file' => $file, 'holds' => $holds, 'n' => $n];
    }
    return $out;
}
