#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Ask a metadata source something, from the command line.
 *
 *   ./bin/lookup.php 3c900-combo
 *   ./bin/lookup.php --source=theretroweb "3Com EtherLink III"
 *   ./bin/lookup.php --source=amigahw "Blizzard 1230"
 *   ./bin/lookup.php --json 'https://theretroweb.com/expansioncards/s/…'
 *   ./bin/lookup.php --list
 *
 * The point is being able to see what a source actually returns without going
 * through the interface - which is how most of this project's scraper bugs were
 * found, one curl at a time. A source that cannot be questioned from a terminal
 * takes a round trip to diagnose every time.
 *
 * Configured sources are used when the database has them, so keys and endpoints
 * come from wherever they were set. Failing that the type's defaults are used,
 * so a source can be tried before it is configured at all.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
define('BASE_PATH', '');

require APP_ROOT . '/src/helpers.php';
require APP_ROOT . '/src/proxy.php';
// Connecting is deferred: listing sources and asking an unconfigured one both
// work without a database, which is often the state somebody is in when they
// reach for this.
require APP_ROOT . '/src/db.php';
require APP_ROOT . '/src/metadata.php';

// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
$opts = ['source' => null, 'json' => false, 'raw' => false, 'limit' => 10,
         'list' => false, 'first' => false, 'all' => false, 'check' => false,
         'platform' => null, 'verbose' => false, 'dump' => null];
$terms = [];

foreach ($args as $a) {
    if ($a === '--list')          { $opts['list'] = true; }
    elseif ($a === '--json')      { $opts['json'] = true; }
    elseif ($a === '--raw')       { $opts['raw'] = true; }
    elseif ($a === '--first')     { $opts['first'] = true; $opts['limit'] = 1; }
    elseif ($a === '--all')       { $opts['all'] = true; }
    elseif ($a === '--check')     { $opts['check'] = true; }
    elseif ($a === '-v' || $a === '--verbose') { $opts['verbose'] = true; }
    elseif (str_starts_with($a, '--dump='))    { $opts['dump'] = substr($a, 7); }
    elseif (str_starts_with($a, '--platform=')) { $opts['platform'] = substr($a, 11); }
    elseif ($a === '-h' || $a === '--help') { usage(); exit(0); }
    elseif (str_starts_with($a, '--source=')) { $opts['source'] = substr($a, 9); }
    elseif (str_starts_with($a, '--limit='))  { $opts['limit'] = max(1, (int) substr($a, 8)); }
    elseif (str_starts_with($a, '--'))        { fail('Unknown option: ' . $a); }
    else { $terms[] = $a; }
}

// Several words are one search: "3com" "3c900-combo" reads better on the
// command line than one quoted string, and means the same thing.
$query = trim(implode(' ', $terms));

if ($opts['list']) {
    listSources();
    exit(0);
}
if ($query === '' && !$opts['check']) {
    usage();
    exit(1);
}

// Every source, one after another. The command-line twin of "Try a lookup", and
// the thing you actually want when a result looks wrong: not "what does this one
// say" but "what do they all say, and which of them disagrees".
if ($opts['all']) {
    $status = askEverything($query, $opts);
    reportDebug($opts);
    exit($status);
}

// Does each source work? Asked with the term that source is documented to know,
// not a fixed one - "Turrican" means nothing to a hardware database, and asking
// it that produced a correct empty answer that read as a pass.
//
// Exit status is the point: 0 when every source asked came back with something,
// 1 otherwise. That makes it usable from a shell script and from the suite.
if ($opts['check']) {
    $status = checkSources($opts);
    reportDebug($opts);
    exit($status);
}

if ($opts['verbose'] || $opts['dump'] !== null) {
    metadata_debug_on();
}

$source = pickSource($opts['source']);
if ($source === null) {
    fail($opts['source'] === null
        ? 'No sources available. Add one, or name a type with --source=…'
        : 'No source called "' . $opts['source'] . '". Try --list.');
}

[$results, $error] = askSource($source, $query);

if ($error !== null && $results === []) {
    fputs(STDERR, "  " . $error . "\n");
    // Printed here too, and especially here. A failure is the case somebody
    // reached for --verbose to understand; returning before saying anything
    // about it is the one place the flag must not be silent.
    reportDebug($opts);
    exit(2);
}

if ($opts['json']) {
    echo json_encode(array_slice($results, 0, $opts['limit']),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit($results === [] ? 1 : 0);
}

report($source, $query, $results, $error, $opts);
reportDebug($opts);
exit($results === [] ? 1 : 0);

// ---------------------------------------------------------------------------

function usage(): void
{
    echo <<<TXT

  Ask a metadata source something.

    ./bin/lookup.php 3c900-combo
    ./bin/lookup.php --source=theretroweb "3Com EtherLink III"
    ./bin/lookup.php --source=amigahw Blizzard 1230
    ./bin/lookup.php --all Turrican
    ./bin/lookup.php --check
    ./bin/lookup.php --all --platform=amiga Turrican

    --source=NAME   which source to ask; the first suitable one otherwise
    --all           ask every source and report each separately
    --check         ask each source what it is documented to know, and say
                    whether it answered. Exit 1 if any did not
    --platform=SLUG filter to one of our machines, using the mapping
    -v, --verbose   say what was requested, what came back and what was made of
                    it. The answer to "it found nothing" is in here: the URL, the
                    status, the size, and how much of the page was understood
    --dump=FILE     write the last response body to a file, for when the counters
                    say the page was not understood and the next question is why
    --json          machine-readable, for piping somewhere
    --raw           show every field returned, not just the interesting ones
    --first         just the best match, when you know what you want
    --limit=N       how many results to print (default 10)
    --list          what sources are available
    --help          this


TXT;
}

/** Sources the database knows about, falling back to what the code supports. */
function listSources(): void
{
    $configured = configuredSources();

    echo "\n";
    foreach (metadata_provider_types() as $type => $def) {
        $has = isset($configured[$type]);
        printf("  %-14s %-26s %s\n",
            $type,
            (string) ($def['label'] ?? $type),
            $has ? 'configured' : ($def['needs_key'] ?? false ? 'needs a key' : 'ready, unconfigured'));

        // tested_with, not only_for: the platform tags stopped being a limit and
        // this still read the key they used to be under, so it printed nothing.
        $domains = implode(', ', $def['domains'] ?? ['software']);
        $tested  = $def['tested_with'] ?? [];
        printf("  %-14s   %s%s\n", '', $domains,
            $tested === [] ? ', any machine'
                : ', tried on ' . count($tested) . ' (' . implode('/', array_slice($tested, 0, 4))
                  . (count($tested) > 4 ? '…' : '') . ')');
    }
    echo "\n";
}

/**
 * The source to ask.
 *
 * A configured one is preferred, since its keys and endpoints are whatever
 * somebody set. Otherwise the type's defaults will do - a scraper needing no
 * key can be tried before it is configured at all.
 */
function pickSource(?string $wanted): ?array
{
    $types = metadata_provider_types();

    $configured = configuredSources(true);

    if ($wanted !== null) {
        if (!isset($types[$wanted])) {
            return null;
        }
        return asSource($wanted, $types[$wanted], $configured[$wanted] ?? null);
    }

    // Nothing named: prefer something configured, else something needing no key.
    foreach ($configured as $type => $row) {
        if (isset($types[$type])) {
            return asSource($type, $types[$type], $row);
        }
    }
    foreach ($types as $type => $def) {
        if (empty($def['needs_key'])) {
            return asSource($type, $def, null);
        }
    }
    return null;
}

/**
 * Sources the database knows about, or none if it cannot be reached.
 *
 * A missing database is not an error here: the types are compiled in, so a
 * source can still be listed and tried.
 */
function configuredSources(bool $enabledOnly = false): array
{
    $out = [];
    try {
        $sql = 'SELECT * FROM metadata_providers'
             . ($enabledOnly ? ' WHERE is_enabled = 1' : '') . ' ORDER BY name';
        foreach (all($sql) as $row) {
            $out[(string) $row['type']] = $row;
        }
    } catch (Throwable $e) {
        // No database, or no table yet. Defaults will do.
    }
    return $out;
}

function asSource(string $type, array $def, ?array $row): array
{
    $params = $def['params'] ?? [];
    if ($row !== null && !empty($row['params'])) {
        $stored = json_decode((string) $row['params'], true);
        if (is_array($stored)) {
            $params = $stored + $params;
        }
    }
    return [
        'type'       => $type,
        'label'      => (string) ($def['label'] ?? $type),
        'params'     => $params,
        'configured' => $row !== null,
    ];
}

/** Call whichever search function the source provides. */
function askSource(array $source, string $query, ?string $remote = null): array
{
    $fn = 'metadata_search_' . $source['type'];
    if (!function_exists($fn)) {
        fail('The source "' . $source['type'] . '" has no search function.');
    }
    try {
        $out = $fn($source['params'], $query, $remote);
    } catch (Throwable $e) {
        return [[], get_class($e) . ': ' . $e->getMessage()];
    }
    if (!is_array($out) || count($out) < 2) {
        return [[], 'That source returned something unexpected.'];
    }
    return [is_array($out[0]) ? $out[0] : [], $out[1] ?? null];
}

function report(array $source, string $query, array $results, ?string $error, array $opts): void
{
    $tty = function_exists('posix_isatty') && @posix_isatty(STDOUT);
    $dim = $tty ? "\033[0;90m" : '';
    $off = $tty ? "\033[0m" : '';

    printf("\n  %s%s%s  %s\n", $dim, $source['label'], $off,
        $source['configured'] ? '' : $dim . '(unconfigured, using defaults)' . $off);
    printf("  %ssearched for%s %s\n\n", $dim, $off, $query);

    if ($results === []) {
        echo "  Nothing found.\n";
        if ($error !== null) {
            echo "  " . $error . "\n";
        }
        echo "\n";
        return;
    }

    foreach (array_slice($results, 0, $opts['limit']) as $i => $r) {
        // An exact match is worth pointing at: their search is a substring
        // match, so a part number finds every card whose name contains it.
        $mark = !empty($r['exact']) ? ($tty ? "\033[0;32m ✓\033[0m" : ' (exact)') : '';
        printf("  %d. %s%s%s\n", $i + 1, (string) ($r['title'] ?? '(untitled)'),
            !empty($r['year']) ? $dim . ', ' . (int) $r['year'] . $off : '', $mark);

        foreach (['developer' => 'made by', 'platform' => 'for', 'genre' => 'genre'] as $k => $label) {
            if (!empty($r[$k])) {
                printf("     %s%-9s%s %s\n", $dim, $label, $off, (string) $r[$k]);
            }
        }

        // The hardware half, which is where the interesting detail lives.
        foreach (($r['hardware'] ?? []) as $k => $v) {
            if ($v === null || $v === '' || (!$opts['raw'] && $k === 'model')) {
                continue;
            }
            printf("     %s%-9s%s %s\n", $dim, $k, $off, is_array($v) ? implode(', ', $v) : (string) $v);
        }

        if (!empty($r['images'])) {
            printf("     %s%-9s%s %d\n", $dim, 'photos', $off, count($r['images']));
        }
        if (!empty($r['url'])) {
            printf("     %s%-9s%s %s\n", $dim, 'url', $off, (string) $r['url']);
        }

        if ($opts['raw'] && !empty($r['summary'])) {
            foreach (explode("\n", (string) $r['summary']) as $line) {
                printf("     %s%s%s\n", $dim, $line, $off);
            }
        }
        echo "\n";
    }

    $shown = min(count($results), $opts['limit']);
    if (count($results) > $shown) {
        printf("  %s%d more — their search matches on substring, so a part number\n", $dim, count($results) - $shown);
        printf("  finds every card whose name contains it. --limit to see them.%s\n\n", $off);
    }
    if ($error !== null) {
        printf("  %s%s%s\n\n", $dim, $error, $off);
    }
}

function fail(string $message): never
{
    fputs(STDERR, "\n  " . $message . "\n\n");
    exit(1);
}

/**
 * Ask every source the same thing.
 *
 * Reported per source with how long it took, because "which one is slow" and
 * "which one disagrees" are the two questions that bring somebody here.
 *
 * @return int exit status: 0 if anything answered, 1 if nothing did
 */
function askEverything(string $query, array $opts): int
{
    $types      = metadata_provider_types();
    $configured = configuredSources(true);
    $remote     = null;

    $rows  = [];
    $found = 0;
    foreach ($types as $type => $def) {
        // A source needing a key that has none would only ever report the same
        // one thing. Named explicitly it is still asked, so the message can be
        // seen; swept over, it is skipped and said so.
        if (!empty($def['needs_key']) && !isset($configured[$type])) {
            $rows[] = ['type' => $type, 'ms' => 0, 'error' => 'not configured, and needs a key',
                       'results' => []];
            continue;
        }
        $source = asSource($type, $def, $configured[$type] ?? null);
        if ($opts['platform'] !== null) {
            $remote = platformIdFor($type, (string) $opts['platform'], $configured[$type] ?? null);
        }

        $started = microtime(true);
        [$results, $error] = askSource($source, $query, $remote);
        $rows[] = [
            'type'    => $type,
            'ms'      => (int) round((microtime(true) - $started) * 1000),
            'error'   => $error,
            'results' => $results,
        ];
        if ($results !== []) {
            $found++;
        }
    }

    if ($opts['json']) {
        foreach ($rows as &$r) {
            $r['results'] = array_slice($r['results'], 0, $opts['limit']);
        }
        unset($r);
        echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
        return $found > 0 ? 0 : 1;
    }

    echo "\n";
    foreach ($rows as $r) {
        printf("  %-14s %5dms  %s\n", $r['type'], $r['ms'],
            $r['error'] !== null
                ? 'FAILED  ' . $r['error']
                : count($r['results']) . ' result(s)');
        foreach (array_slice($r['results'], 0, $opts['limit']) as $hit) {
            printf("  %-14s   %s%s%s\n", '',
                (string) ($hit['title'] ?? '?'),
                empty($hit['year']) ? '' : ' (' . (int) $hit['year'] . ')',
                empty($hit['platform']) ? '' : ' — ' . $hit['platform']);
        }
    }
    echo "\n";
    return $found > 0 ? 0 : 1;
}

/**
 * Does each source work?
 *
 * The same check the interface runs before adding a source, from a terminal and
 * over all of them at once. A source is asked what it is documented to know, and
 * an empty answer counts as a failure here for the same reason it does there:
 * the question is whether the source works, and finding nothing for a term it
 * certainly knows does not show that it does.
 *
 * @return int 0 if every source asked answered, 1 otherwise
 */
function checkSources(array $opts): int
{
    $types      = metadata_provider_types();
    $configured = configuredSources(true);

    if ($opts['source'] !== null) {
        if (!isset($types[$opts['source']])) {
            fail('No source called "' . $opts['source'] . '". Try --list.');
        }
        $types = [$opts['source'] => $types[$opts['source']]];
    }

    $bad = 0;
    $out = [];
    echo "\n";
    foreach ($types as $type => $def) {
        $probe = metadata_provider_probe($type);

        if (!empty($def['needs_key']) && !isset($configured[$type])) {
            printf("  %-14s %-8s %s\n", $type, 'skipped', 'needs a key, and none configured');
            $out[$type] = ['status' => 'skipped', 'probe' => $probe];
            continue;
        }

        $source  = asSource($type, $def, $configured[$type] ?? null);
        $started = microtime(true);
        [$results, $error] = askSource($source, $probe);
        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($error !== null) {
            printf("  %-14s %-8s %s\n", $type, 'FAILED', $error);
            $out[$type] = ['status' => 'failed', 'probe' => $probe, 'error' => $error];
            $bad++;
        } elseif ($results === []) {
            printf("  %-14s %-8s found nothing for \"%s\"\n", $type, 'FAILED', $probe);
            $out[$type] = ['status' => 'empty', 'probe' => $probe];
            $bad++;
        } else {
            printf("  %-14s %-8s %d result(s) for \"%s\", %dms\n",
                $type, 'ok', count($results), $probe, $ms);
            $out[$type] = ['status' => 'ok', 'probe' => $probe, 'results' => count($results)];
        }
    }
    echo "\n";

    if ($opts['json']) {
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    }
    return $bad === 0 ? 0 : 1;
}

/**
 * What this source calls one of our machines.
 *
 * The configured mapping first, since somebody may have corrected it; the
 * templates otherwise, so --platform works before anything is configured.
 */
function platformIdFor(string $type, string $slug, ?array $configured): ?string
{
    if ($configured !== null) {
        try {
            $id = scalar(
                'SELECT mpp.remote_platform_id
                   FROM metadata_provider_platforms mpp
                   JOIN platforms p ON p.id = mpp.platform_id
                  WHERE mpp.provider_id = ? AND p.slug = ? LIMIT 1',
                [(int) $configured['id'], $slug]
            );
            if ($id !== null) {
                return (string) $id;
            }
        } catch (Throwable $e) {
            // No database, or no such table. The templates still answer.
        }
    }
    $map = metadata_template_platform_map($type);
    return $map[$slug] ?? null;
}

/**
 * What happened, when what happened is the question.
 *
 * Printed after the results rather than before, because the usual case is
 * reading the results and only then wondering why there are none of them.
 */
function reportDebug(array $opts): void
{
    if (!$opts['verbose'] && $opts['dump'] === null) {
        return;
    }

    if ($opts['verbose']) {
        $notes = metadata_debug_notes();
        echo "\n  what happened\n";
        if ($notes === []) {
            echo "    nothing recorded — this source does not report its workings yet.\n";
        }
        foreach ($notes as [$what, $value]) {
            $text = is_scalar($value) ? (string) $value : json_encode($value);
            // Wrapped rather than truncated: the first four hundred bytes of a
            // page is the one note where the whole point is seeing all of it.
            printf("    %-38s %s\n", $what, wordwrap($text, 76, "\n" . str_repeat(' ', 43), true));
        }
        echo "\n";
    }

    if ($opts['dump'] !== null) {
        $body = $GLOBALS['__metadata_last_body'] ?? null;
        if ($body === null) {
            fputs(STDERR, "  nothing to dump: no response body was kept.\n");
            return;
        }
        if (@file_put_contents($opts['dump'], $body) === false) {
            fputs(STDERR, "  could not write " . $opts['dump'] . "\n");
            return;
        }
        printf("  wrote %s (%d bytes)\n", $opts['dump'], strlen($body));
    }
}
