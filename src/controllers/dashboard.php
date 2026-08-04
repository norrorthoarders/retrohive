<?php
declare(strict_types=1);

/**
 * The dashboard.
 *
 * Everything here reads v_items rather than items directly. That is not
 * decoration: the view carries the WHERE deleted_at IS NULL, so a soft-deleted
 * entry cannot creep back into a total, and it exposes library_id, which is
 * what access is decided on. The old queries filtered items by platform_id and
 * forgot the deleted check, so the numbers on this page were wrong twice over.
 */
function dashboard_index(): void
{
    // One library, or everything you can reach.
    //
    // The overview answered "what have I got" across every shelf at once, which is the
    // right default and the wrong only option: a shared club shelf and a private
    // collection are different collections, and their totals mean different things
    // added together. ?library=<slug> narrows the whole page - totals, breakdowns and
    // gaps - to one of them.
    $joined = joined_libraries();
    $want   = trim((string) (input('library') ?? ''));
    $only   = null;
    foreach ($joined as $l) {
        if ((string) $l['slug'] === $want) {
            $only = (int) $l['id'];
            break;
        }
    }

    [$acl, $aclP]   = library_filter_sql('library_id', ACCESS_VIEWER);
    [$aclI, $aclIp] = library_filter_sql('i.library_id', ACCESS_VIEWER);

    // Narrowing is an extra clause rather than a different query, so every panel below
    // stays in step without any of them knowing a tab exists.
    if ($only !== null) {
        $acl  = '(' . $acl . ') AND library_id = ?';
        $aclP[] = $only;
        $aclI = '(' . $aclI . ') AND i.library_id = ?';
        $aclIp[] = $only;
    }

    $totals = one('SELECT
            COUNT(*)                                   AS items,
            SUM(status = \'owned\')                     AS owned,
            SUM(status = \'wishlist\')                  AS wanted,
            SUM(acquired_price)                        AS spend,
            AVG(NULLIF(rating, 0))                     AS avg_rating,
            MIN(NULLIF(release_year, 0))               AS earliest,
            MAX(release_year)                          AS latest,
            SUM(current_value)                         AS value
        FROM v_items WHERE ' . $acl, $aclP) ?? [];

    // Libraries first, because that is the thing you own and share. Platforms
    // are a second cut of the same rows.
    $byLibrary = all('SELECT lib.name, lib.slug, lib.accent_color, COUNT(i.id) AS n
        FROM libraries lib
        LEFT JOIN items i ON i.library_id = lib.id AND i.deleted_at IS NULL AND i.status = \'owned\'
        WHERE ' . str_replace('i.library_id', 'lib.id', $aclI) . '
        GROUP BY lib.id ORDER BY n DESC, lib.name', $aclIp);

    $byPlatform = all('SELECT p.name, p.slug, p.accent_color, COUNT(i.id) AS n
        FROM platforms p
        LEFT JOIN items i ON i.platform_id = p.id AND i.deleted_at IS NULL
                         AND i.status = \'owned\' AND ' . $aclI . '
        GROUP BY p.id HAVING n > 0 ORDER BY n DESC, p.name', $aclIp);

    $byCategory = all('SELECT c.name, c.slug, s.slug AS domain, COUNT(i.id) AS n
        FROM categories c
        JOIN sections s ON s.id = c.section_id
        LEFT JOIN items i ON i.category_id = c.id AND i.deleted_at IS NULL
                         AND i.status = \'owned\' AND ' . $aclI . '
        GROUP BY c.id HAVING n > 0 ORDER BY n DESC', $aclIp);

    $byDecade = all('SELECT FLOOR(release_year / 10) * 10 AS decade, COUNT(*) AS n
        FROM v_items WHERE release_year IS NOT NULL AND status = \'owned\' AND ' . $acl . '
        GROUP BY decade ORDER BY decade', $aclP);

    $recent   = all('SELECT * FROM v_items WHERE status = \'owned\' AND ' . $acl . ' ORDER BY created_at DESC, id DESC LIMIT 8', $aclP);
    $topRated = all('SELECT * FROM v_items WHERE rating IS NOT NULL AND status = \'owned\' AND ' . $acl . ' ORDER BY rating DESC, COALESCE(sort_title, title) LIMIT 8', $aclP);


    $noPhotos = (int) scalar('SELECT COUNT(*) FROM v_items WHERE image_count = 0 AND status = \'owned\' AND ' . $acl, $aclP);
    $noYear   = (int) scalar('SELECT COUNT(*) FROM v_items WHERE release_year IS NULL AND status = \'owned\' AND ' . $acl, $aclP);
    $noDev    = (int) scalar('SELECT COUNT(*) FROM v_items WHERE developer_id IS NULL AND status = \'owned\' AND ' . $acl, $aclP);
    $imageTot = (int) scalar('SELECT COALESCE(SUM(image_count), 0) FROM v_items WHERE ' . $acl, $aclP);

    render('dashboard', [
        'pageTitle'  => config('app_name'),
        'totals'     => $totals,
        'byLibrary'  => $byLibrary,
        // The tabs, and which one is current.
        'tabLibs'    => $joined,
        'tabCurrent' => $only === null ? '' : $want,
        'byPlatform' => $byPlatform,
        'byCategory' => $byCategory,
        'byDecade'   => $byDecade,
        'recent'     => $recent,
        'topRated'   => $topRated,
        'noPhotos'   => $noPhotos,
        'noYear'     => $noYear,
        'noDev'      => $noDev,
        'imageTot'   => $imageTot,
    ]);
}
