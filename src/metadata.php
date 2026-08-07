<?php
declare(strict_types=1);

/**
 * Pluggable metadata lookup.
 *
 * A provider does two things: search for candidates by title, and fetch one
 * candidate in full. Everything else - caching, platform mapping, merging into
 * an entry - is shared, so adding a source means writing two functions and one
 * definition entry.
 *
 * Nothing is ever written to an entry automatically. A lookup produces a
 * suggestion the user reviews field by field; scraped data is frequently wrong
 * about exactly the things a collector cares about, like which regional release
 * you actually own.
 */

// --- Provider definitions ---------------------------------------------------

/**
 * Every supported source, what it needs, and what it is good for.
 * `free` means no key and no account.
 */
/**
 * What this source asks for before it will answer.
 *
 * Not every credential is "an API key". IGDB wants a client id and a client
 * secret, and calling the pair a key meant a form with one box for two values.
 *
 * Falls back to a single api_key for anything that needs one and says no more,
 * which is what every other key-using source is.
 *
 * @return array<string,array{label:string,secret:bool}>
 */
function metadata_provider_credentials(string $type): array
{
    $def = metadata_provider_definition($type);
    if ($def === null || empty($def['needs_key'])) {
        return [];
    }
    $creds = $def['credentials'] ?? null;
    return is_array($creds) && $creds !== []
        ? $creds
        : ['api_key' => ['label' => 'API key', 'secret' => true]];
}

/**
 * Does this source carry that field at all?
 *
 * Different from "did not find it this time". A column of dashes reads as a
 * scraper that is failing; where the source simply has no such field, saying so
 * is the difference between a bug to chase and a fact about the site.
 */
function metadata_provider_omits(string $type, string $field): bool
{
    $def = metadata_provider_definition($type);
    return $def !== null && in_array($field, (array) ($def['omits'] ?? []), true);
}

/**
 * Is this source *about* a fixed set of machines?
 *
 * Different from whether it can narrow a search, which is what this used to be
 * read off and is a question about the API rather than about the site.
 * Wikipedia takes no platform parameter and covers everything ever made; the
 * Amiga Hardware Database takes none either and covers three machines. Reading
 * both off one flag skipped Wikipedia on a Jaguar entry with the words "covers
 * only amiga, c64, pc..." - a list of what it had been tried on, presented as
 * the limit of what it knows.
 *
 * @return list<string> the slugs it covers, or [] for "everything"
 */
function metadata_provider_limited_to(string $type): array
{
    $def = metadata_provider_definition($type);
    return (array) ($def['limited_to'] ?? []);
}

/**
 * Can this source narrow a search to one machine?
 *
 * Not all of them can, and the ones that cannot were being nagged about it. The
 * Amiga Hardware Database covers one family and takes no platform parameter at
 * all - it accepts the argument and never reads it - so a mapping for it would be
 * rows in a table that nothing consults, "0 mapped" would be a number that can
 * never go up, and the lookup screen was telling people to go and set something
 * that would change nothing. TheRetroWeb is the same: PC hardware, one scope.
 *
 * Declared rather than inferred. Whether a function reads an argument is not
 * something the interface should be working out by looking.
 */
function metadata_provider_filters_by_platform(string $type): bool
{
    $def = metadata_provider_definition($type);
    // Absent means yes, because a source that takes a platform and says nothing
    // about it is far more likely to use it than not.
    return $def === null || ($def['filters_by_platform'] ?? true);
}

/**
 * Something this source should certainly know, for the check before adding.
 *
 * "Turrican" was asked of everything, which is meaningless to a hardware
 * database: the Amiga Hardware Database answered, found nothing - correctly, it
 * has no games in it - and was added on the strength of having replied at all.
 * A check that passes when the answer is empty is not checking anything.
 */
function metadata_provider_probe(string $type): string
{
    $def = metadata_provider_definition($type);
    return (string) ($def['probe'] ?? 'Turrican');
}

function metadata_provider_types(): array
{
    // The tested-with lists come from structure/metadata_agents.json where an
    // install has synchronised, and from the arrays below where it has not.
    //
    // They are documentation, so they belong with the rest of the documentation
    // that ships and updates: somebody who catalogues on a machine we have never
    // heard of should get a longer list next time they synchronise rather than
    // next time they upgrade the code.
    static $feed = null;
    if ($feed === null) {
        $feed = [];
        $path = APP_ROOT . '/structure/metadata_agents.json';
        if (is_file($path)) {
            $read = json_decode((string) file_get_contents($path), true);
            $feed = is_array($read) ? $read : [];
        }
    }
    $GLOBALS['__metadata_feed'] = $feed;
    // Which kinds a source is worth switching on for, from the same file. The
    // domains it answers about say what it *can* do; this says what it is good
    // for, and only the file knows that - it is a judgement, not a capability.
    // Which kinds each source is worth switching on for, with the answer in the
    // code and the file able to override it.
    //
    // This used to come only from structure/metadata_agents.json, which is
    // fetched from GitHub - so an instance pulling templates published before this
    // existed got no defaults at all and arrived with every source switched off,
    // silently. That is a judgement about what each source is good for; it belongs
    // with the code that knows what the sources are, not in data that may be a
    // release behind.
    //
    // The file still wins when it says something, so the list can be corrected
    // without waiting for a release.
    $shipped = [
        'openretro'   => ['game'],
        'thegamesdb'  => ['game'],
        'igdb'        => ['game'],
        'amigahw'     => ['machine', 'peripheral'],
        'bboah'       => ['machine', 'peripheral'],
        'theretroweb' => ['machine', 'peripheral'],
        'tmdb'        => ['movie', 'tv_show'],
        'tvdb'        => ['tv_show'],
        'theaudiodb'  => ['music'],
        'musicbrainz' => ['music'],
        // Wikipedia carries the words, the article's own pictures and the Commons
        // photographs, so it is the one source worth asking about anything.
        'wikipedia'   => ['machine', 'peripheral', 'game', 'application'],
        // Off: Wikipedia brings its photographs along, and asking both put two
        // half-answers on the review screen for one title.
        'commons'     => [],
        // Off: it answered nothing useful for a title, and a source returning a
        // dash on every lookup is noise in the summary line.
        'wikidata'    => [],
    ];

    $defaultFor = function (string $key) use ($feed, $shipped): array {
        if (isset($feed[$key]['default_for_kinds']) && is_array($feed[$key]['default_for_kinds'])) {
            return array_values($feed[$key]['default_for_kinds']);
        }
        return $shipped[$key] ?? [];
    };

    $tested = fn(string $key, array $fallback) => isset($feed[$key]['tested_with'])
        && is_array($feed[$key]['tested_with'])
            ? $feed[$key]['tested_with']
            : $fallback;

    $types = [
        'wikidata' => [
            'label'     => 'Wikidata',
            'blurb'     => 'Structured data behind Wikipedia. Broad but shallow: good for developer, publisher and year, rarely for anything else. No key needed.',
            'needs_key' => false,
            // Every source says what it answers about. The two hardware sources
            // already did; these did not, so nothing could tell that asking
            // TheGamesDB about an accelerator card was pointless.
            'domains'   => ['software', 'hardware'],
            'homepage'  => 'https://www.wikidata.org/',
            'filters_by_platform' => true,
            'probe'     => 'Turrican',
            // Deliberately empty, and it means "any". Wikidata is an
            // encyclopaedia rather than a games database: it has an item for
            // almost any machine and shallow data about all of them, so a list
            // would either be sixty-three chips or a lie about the other sixty.
            'tested_with' => [],
            'best_for'  => [],
            'params'    => [
                'endpoint' => 'https://query.wikidata.org/sparql',
                'timeout'  => 15,
                'language' => 'en',
            ],
        ],
        'theretroweb' => [
            'label'     => 'TheRetroWeb',
            'blurb'     => 'theretroweb.com. PC motherboards, cards and chipsets. No key needed. Parses HTML, so it will want attention if the site is redesigned.',
            'needs_key' => false,
            'domains'   => ['hardware'],
            'homepage'  => 'https://theretroweb.com/',
            // The machines this site is *about*, which is the whole of it - not a
            // list of what has been tried. Absent on every other source, because
            // absent means "everything".
            'limited_to' => ['pc'],
            'filters_by_platform' => false,
            // What it does not carry, so an empty column can say which it is.
            //
            // A board page on this site has chipset, socket, FSB speeds, form
            // factor, dimensions, RAM, expansion slots and notes - and no date at
            // all. Checked against a live page rather than assumed. So the year
            // is not something the scraper is failing to find; it is not there,
            // and a bare dash in that column reads like the former.
            'omits'     => ['year'],
            'probe'     => 'Sound Blaster',
            'tested_with' => ['pc'],
            'best_for'  => ['pc'],
            'params'    => [
                'endpoint'  => 'https://theretroweb.com',
                'sections'  => 'motherboards,expansioncards,cpus',
                // Optional. Their maker filter is built in the browser, so
                // these ids cannot be read from the page - use their filter
                // once and copy manufacturerId out of the address bar.
                // Written as: 3Com=1245, Creative=88
                'manufacturers' => '',
                'timeout'   => 20,
                'min_delay' => 1.0,
            ],
        ],
        'amigahw' => [
            'label'     => 'Amiga Hardware Database',
            'blurb'     => 'amiga.resource.cx. The only real source for Amiga expansion hardware: accelerators, controllers, adapters. No key needed. Parses HTML, so it will need attention if the site is redesigned.',
            'needs_key' => false,
            'domains'   => ['hardware'],
            'homepage'  => 'https://amiga.resource.cx/',
            // The machines this site is *about*, which is the whole of it - not a
            // list of what has been tried. Absent on every other source, because
            // absent means "everything".
            'limited_to' => ['amiga', 'cd32', 'cdtv'],
            'filters_by_platform' => false,
            'probe'     => 'Blizzard',
            'tested_with' => ['amiga', 'cd32', 'cdtv'],
            'best_for'  => ['amiga', 'cd32', 'cdtv'],
            // A hard limit, not a preference: this source knows about Amiga
            // expansion hardware and nothing else, so offering it elsewhere
            // would be a promise it cannot keep. Sources without this line
            // work anywhere in their domain.
            'params'    => [
                'endpoint'  => 'https://amiga.resource.cx',
                'timeout'   => 20,
                // One request a second. It is somebody's hobby site.
                'min_delay' => 1.0,
            ],
        ],
        'bboah' => [
            'label'     => 'Big Book of Amiga Hardware',
            'blurb'     => 'bigbookofamigahardware.com. The largest Amiga hardware reference there '
                         . 'is - accelerators, controllers, monitors, oddities - with a photograph '
                         . 'and a description for most of it. No key needed. Its own search box is '
                         . 'an ASP.NET postback rather than an address, so this reads the category '
                         . 'and manufacturer listings instead, which are ordinary links.',
            'needs_key' => false,
            'domains'   => ['hardware'],
            'homepage'  => 'https://bigbookofamigahardware.com/',
            'limited_to' => ['amiga', 'cd32', 'cdtv'],
            'filters_by_platform' => false,
            'probe'     => 'Blizzard',
            'tested_with' => ['amiga', 'cd32', 'cdtv'],
            'best_for'  => ['amiga', 'cd32', 'cdtv'],
            'params'    => [
                'endpoint'  => 'https://bigbookofamigahardware.com',
                'timeout'   => 20,
                // Somebody's hobby archive that runs on donations, and this reads
                // a listing page before it reads anything else. One a second.
                'min_delay' => 1.0,
            ],
        ],
        'commons' => [
            'label'     => 'Wikimedia Commons',
            'blurb'     => 'commons.wikimedia.org. Photographs of machines, cards, cartridges and '
                         . 'the occasional box, all freely licensed and none of it going away. No '
                         . 'key, no quota, no sign-up. Pictures only - it knows nothing about a '
                         . 'release year or a studio - so it belongs alongside a source that knows '
                         . 'facts. Thin on commercial box art, which is a licensing fact about '
                         . 'Commons rather than a fault: what it has is hardware.',
            'needs_key' => false,
            'domains'   => ['software', 'hardware'],
            'homepage'  => 'https://commons.wikimedia.org/',
            'filters_by_platform' => true,
            'probe'     => 'Amiga 500',
            'tested_with' => [],
            // Pictures and nothing else, declared so the review screen does not
            // offer rows that will always be empty.
            'omits'     => ['year', 'developer', 'publisher', 'summary'],
            'params'    => [
                'endpoint'  => 'https://commons.wikimedia.org/w/api.php',
                'timeout'   => 20,
                'min_delay' => 0.5,
                'max_results' => 8,
            ],
        ],
        'thegamesdb' => [
            'label'     => 'TheGamesDB',
            'blurb'     => 'Community console database with decent box art. Free API key on request.',
            'needs_key' => true,
            'domains'   => ['software'],
            'homepage'  => 'https://thegamesdb.net/',
            'credentials' => ['api_key' => ['label' => 'API key', 'secret' => true]],
            'filters_by_platform' => true,
            'probe'     => 'Turrican',
            // The machines it actually carries, matched against this catalogue's
            // slugs rather than copied from their site: a platform it lists under
            // a name we do not have is a platform nobody here can ask about.
            'tested_with' => [
                'nes', 'snes', 'n64', 'game-boy', 'gba', 'virtual-boy',
                'master-system', 'mega-drive', 'mega-cd', 'sega-32x', 'game-gear',
                'saturn', 'dreamcast', 'sg-1000',
                'playstation', 'atari-2600', 'atari-5200', 'atari-7800', 'lynx',
                'jaguar', 'neo-geo', 'pc-engine', '3do', 'cd-i', 'colecovision',
                'intellivision', 'vectrex', 'wonderswan',
                'pc', 'amiga', 'cd32', 'cdtv', 'c64', 'atari-st', 'msx', 'zx-spectrum',
                'amstrad-cpc', 'apple-ii', 'atari-8bit', 'vic-20', 'plus4',
                'archimedes', 'acorn-electron', 'bbc-micro', 'dragon-32', 'oric',
                'trs-80', 'sam-coupe', 'zx81', 'mac-68k', 'x68000', 'pc-8801',
                'pc-9801', 'fm-towns', 'sharp-mz', 'thomson', 'sinclair-ql',
            ],
            'best_for'  => ['nes', 'snes', 'mega-drive', 'master-system', 'saturn', 'dreamcast'],
            'params'    => [
                'endpoint' => 'https://api.thegamesdb.net/v1',
                'api_key'  => '',
                'timeout'  => 15,
            ],
        ],
        'tmdb' => [
            'label'     => 'The Movie Database (TMDB)',
            'blurb'     => 'Movies and TV, with real posters and a genuine overview for almost '
                          . 'anything released. Free API key on request - the "API Read Access '
                          . 'Token" from your TMDB account, not the shorter v3 key.',
            'needs_key' => true,
            'domains'   => ['video'],
            'homepage'  => 'https://www.themoviedb.org/',
            'credentials' => ['api_key' => ['label' => 'API Read Access Token', 'secret' => true]],
            // TMDB has no platform concept of its own - a film is a film,
            // not "the VHS release" or "the Blu-ray release" the way a
            // game has a real Amiga version and a real DOS one. Asking it
            // to filter by platform would be asking a question it has no
            // way to answer.
            'filters_by_platform' => false,
            'probe'     => 'Jack Reacher',
            'tested_with' => ['vhs', 'dvd', 'blu-ray', 'laserdisc'],
            'best_for'  => ['vhs', 'dvd', 'blu-ray', 'laserdisc'],
            'params'    => [
                'endpoint' => 'https://api.themoviedb.org/3',
                'image_endpoint' => 'https://image.tmdb.org/t/p',
                'api_key'  => '',
                'language' => 'en-US',
                'timeout'  => 15,
            ],
        ],
        'tvdb' => [
            'label'     => 'TheTVDB',
            'blurb'     => 'TV series, with a network, a genuine overview and a poster for '
                          . 'almost anything that has aired. Free API key on request. '
                          . 'Authenticates itself: exchanges the key for a bearer token '
                          . 'automatically, so there is nothing to renew by hand.',
            'needs_key' => true,
            'domains'   => ['video'],
            'homepage'  => 'https://www.thetvdb.com/',
            'credentials' => ['api_key' => ['label' => 'API key', 'secret' => true]],
            // No platform concept of its own, the same reasoning TMDB
            // already has - a series is a series, not "the DVD box set"
            // the way a game has a real Amiga release and a real DOS one.
            'filters_by_platform' => false,
            'probe'     => 'Breaking Bad',
            'tested_with' => ['vhs', 'dvd', 'blu-ray'],
            'best_for'  => ['vhs', 'dvd', 'blu-ray'],
            'params'    => [
                'endpoint' => 'https://api4.thetvdb.com/v4',
                'api_key'  => '',
                // Only needed on a user-supported (free tier) key.
                // Removed from the login request entirely when blank,
                // rather than sent empty - TheTVDB's own docs are
                // explicit that an unwanted pin field should be left
                // out of the call, not sent as "".
                'pin'      => '',
                'timeout'  => 15,
            ],
        ],
        'theaudiodb' => [
            'label'     => 'TheAudioDB',
            'blurb'     => 'Music. The free test key works out of the box for artist lookups - '
                          . 'biography, genre, style, record label and real artwork - but their '
                          . 'own album search is not usable on it in practice, so results come '
                          . 'back as an artist, not a specific record. Fine for filling in '
                          . 'artist and label; a candidate\'s own title will read as the band or '
                          . 'performer\'s name.',
            'needs_key' => true,
            'domains'   => ['audio'],
            'homepage'  => 'https://www.theaudiodb.com/',
            'credentials' => ['api_key' => ['label' => 'API key (123 for the free test key)', 'secret' => true]],
            // No platform concept of its own - a record is a record,
            // not "the vinyl pressing" or "the cassette" the way a
            // game has a real Amiga release and a real DOS one.
            'filters_by_platform' => false,
            'probe'     => 'Coldplay',
            'tested_with' => ['vinyl', 'cd', 'cassette'],
            'best_for'  => ['vinyl', 'cd', 'cassette'],
            'params'    => [
                'endpoint' => 'https://www.theaudiodb.com/api/v1/json',
                'api_key'  => '123',
                'timeout'  => 15,
            ],
        ],
        'musicbrainz' => [
            'label'     => 'MusicBrainz',
            'blurb'     => 'Music. Real release-level search - title, artist, label, catalogue '
                          . 'number, and where the release has one, a real barcode. No key needed, '
                          . 'but their own API terms require a real contact URL or email in every '
                          . 'request, not an anonymous one - fill in the "contact" parameter below '
                          . 'with a URL or address they could actually reach you at.',
            'needs_key' => false,
            'domains'   => ['audio'],
            'homepage'  => 'https://musicbrainz.org/',
            // No platform concept of its own, the same reasoning
            // TheAudioDB's own entry already gives.
            'filters_by_platform' => false,
            'probe'     => 'Parachutes',
            'tested_with' => ['vinyl', 'cd', 'cassette'],
            'best_for'  => ['vinyl', 'cd', 'cassette'],
            'params'    => [
                'endpoint' => 'https://musicbrainz.org/ws/2',
                'contact'  => '',
                'timeout'  => 15,
            ],
        ],
        'igdb' => [
            'label'     => 'IGDB',
            'blurb'     => 'igdb.com. Deep, current and well curated for games, with release dates, studios and platforms. Needs a free Twitch application: the client id and secret are exchanged for a token automatically, so there is no key to paste and nothing to renew by hand.',
            'needs_key' => true,
            'domains'   => ['software'],
            'homepage'  => 'https://api-docs.igdb.com/#getting-started',
            // Two, not one.
            //
            // IGDB is reached through a Twitch application, which issues a client
            // id and a client secret; the pair is exchanged for a token. The code
            // has read both since it was written - client_id and api_key, the
            // second holding the secret - but the form only ever rendered a box
            // called "API key", so the client id could not be entered at all and
            // the source could not be configured from the interface.
            'credentials' => [
                'client_id' => ['label' => 'Client ID', 'secret' => false],
                'api_key'   => ['label' => 'Client secret', 'secret' => true],
            ],
            'filters_by_platform' => true,
            'probe'     => 'Turrican',
            'tested_with' => [
                'pc', 'amiga', 'cd32', 'cdtv', 'atari-st', 'c64', 'vic-20', 'plus4',
                'zx-spectrum', 'zx81', 'amstrad-cpc', 'msx', 'apple-ii', 'atari-8bit',
                'bbc-micro', 'acorn-electron', 'archimedes', 'mac-68k', 'x68000',
                'pc-8801', 'pc-9801', 'fm-towns', 'sharp-mz', 'dragon-32', 'oric',
                'trs-80', 'sam-coupe', 'sinclair-ql',
                'nes', 'snes', 'n64', 'game-boy', 'gba', 'virtual-boy',
                'master-system', 'mega-drive', 'mega-cd', 'sega-32x', 'game-gear',
                'saturn', 'dreamcast', 'sg-1000',
                'playstation', 'atari-2600', 'atari-5200', 'atari-7800', 'lynx',
                'jaguar', 'neo-geo', 'pc-engine', '3do', 'cd-i', 'colecovision',
                'intellivision', 'vectrex', 'wonderswan',
            ],
            'best_for'  => ['pc', 'amiga', 'atari-st', 'c64', 'snes', 'mega-drive',
                            'playstation', 'saturn', 'dreamcast', 'nes'],
            'params'    => [
                'endpoint'      => 'https://api.igdb.com/v4',
                'token_url'     => 'https://id.twitch.tv/oauth2/token',
                // Twitch calls them client id and secret; the form shows the
                // secret in the API key box because that is the one to keep.
                'client_id'     => '',
                'api_key'       => '',
                'timeout'       => 15,
            ],
        ],
        'wikipedia' => [
            'label'     => 'Wikipedia',
            'blurb'     => 'en.wikipedia.org. Best of the sources for machines and expansion '
                         . 'cards: the infobox carries maker, year, processor, memory, graphics, '
                         . 'sound and ports, and those become specification rows rather than a '
                         . 'paragraph you have to read. No account and no key.',
            'needs_key' => false,
            'domains'   => ['hardware', 'software'],
            'homepage'  => 'https://en.wikipedia.org/',
            'filters_by_platform' => false,
            'probe'     => 'Amiga 2000',
            // Everything, near enough - which is why there is no scope skip for
            // it. Listed here for the tags on the add screen.
            'tested_with' => ['amiga', 'c64', 'pc', 'atari-st', 'zx-spectrum', 'msx',
                              'amstrad-cpc', 'nes', 'snes', 'mega-drive', 'apple-ii'],
            'best_for'  => ['amiga', 'c64', 'pc', 'atari-st'],
            'params'    => [
                'endpoint'    => 'https://en.wikipedia.org/w/api.php',
                'timeout'     => 20,
                'min_delay'   => 1.0,
                // How many articles to open. Each is one request, and the search
                // result alone carries nothing worth having.
                'detail_pages' => 4,
            ],
        ],

        'openretro' => [
            'label'     => 'OpenRetro',
            'blurb'     => 'openretro.org. The Amiga game configuration database behind FS-UAE, and unusually good on variants: which dump, which Kickstart, WHDLoad or floppy. Its /api/ is a sync feed for the Launcher rather than a title search, so this reads the public browse pages - it will want attention if the site is redesigned.',
            'needs_key' => false,
            'domains'   => ['software'],
            'homepage'  => 'https://openretro.org/',
            'filters_by_platform' => true,
            'probe'     => 'Turrican',
            'tested_with' => ['amiga', 'cd32', 'cdtv'],
            'best_for'  => ['amiga', 'cd32', 'cdtv'],
            // Amiga and its two consoles, and nothing else: the database is what
            // FS-UAE runs on and does not pretend to cover anything further.
            'params'    => [
                'endpoint'  => 'https://openretro.org',
                'timeout'   => 20,
                // Somebody's hobby server, same as the Amiga hardware database.
                'min_delay' => 1.0,
            ],
        ],
    ];

    foreach ($types as $key => $def) {
        $types[$key]['tested_with'] = $tested($key, $def['tested_with'] ?? []);
    }
    foreach ($types as $key => $def) {
        $types[$key]['default_for_kinds'] = $defaultFor((string) $key);
    }

    return $types;

    // CSDb and MobyGames are implemented below and deliberately not offered
    // yet: CSDb needs the simplexml extension, and MobyGames needs a key that
    // has to be requested by hand. Restoring either is a matter of putting its
    // definition back in the array above; the search and parse functions are
    // still here and still covered by the tests.
}

/** Whether a source needs an API key, for both the form and the JS toggle. */
function metadata_needs_key(string $type): bool
{
    return (bool) (metadata_provider_types()[$type]['needs_key'] ?? false);
}

function metadata_provider_definition(string $type): ?array
{
    return metadata_provider_types()[$type] ?? null;
}

/** Stored params merged over the type defaults. */
function metadata_params(array $provider): array
{
    $def = metadata_provider_definition((string) $provider['type']);
    $defaults = $def['params'] ?? [];
    $stored = [];
    if (!empty($provider['params'])) {
        $decoded = json_decode((string) $provider['params'], true);
        if (is_array($decoded)) {
            $stored = $decoded;
        }
    }
    return array_replace($defaults, $stored);
}

function enabled_metadata_providers(): array
{
    // Configured *and* enabled, and nothing else counts.
    //
    // A source bound to a node in the tree is a preference about which source to
    // prefer, not a source: the row in metadata_node_providers survives the agent
    // being deleted or switched off, and a node can name one that was never added
    // here at all. Asking anyway produced "no implementation" and "no API key" at
    // somebody who had switched the thing off on purpose.
    return all('SELECT * FROM metadata_providers WHERE is_enabled = 1 ORDER BY priority, id');
}

/** Is there any source to ask at all? */
function any_metadata_provider(): bool
{
    return enabled_metadata_providers() !== [];
}

// --- Diagnostics ------------------------------------------------------------

/**
 * What actually happened, for when the answer is "it found nothing".
 *
 * A source returning nothing has several causes that look identical from the
 * outside: the wrong URL, a page that came back empty, a page that came back
 * full and was not understood, a term genuinely not in the database. The
 * difference is visible from inside and was being thrown away.
 *
 * Off unless switched on, and it collects rather than prints - the caller
 * decides whether anybody wants to read it. There is no diagnostics mode in the
 * web interface on purpose: this is for a terminal, where the person reading it
 * asked to.
 */
function metadata_debug_on(bool $on = true): void
{
    $GLOBALS['__metadata_debug'] = $on;
    if ($on) {
        $GLOBALS['__metadata_debug_notes'] = [];
    }
}

function metadata_debugging(): bool
{
    return !empty($GLOBALS['__metadata_debug']);
}

/** Record one fact. Cheap enough to call unconditionally. */
function metadata_debug(string $what, $value): void
{
    if (empty($GLOBALS['__metadata_debug'])) {
        return;
    }
    $GLOBALS['__metadata_debug_notes'][] = [$what, $value];
}

/** @return list<array{0:string,1:mixed}> */
function metadata_debug_notes(): array
{
    return $GLOBALS['__metadata_debug_notes'] ?? [];
}

// --- HTTP -------------------------------------------------------------------

// --- Where we are willing to send a request ---------------------------------

/**
 * Is this address one the server should refuse to fetch from?
 *
 * Loopback, link-local, the RFC1918 ranges, carrier NAT, multicast and the IPv6
 * equivalents. On a self-hosted box the interesting targets are all here: the
 * MariaDB port, the Home Assistant instance, a hypervisor's management address,
 * and on a cloud host the 169.254.169.254 metadata endpoint that hands out
 * credentials to anyone who asks.
 */
function metadata_address_is_internal(string $ip): bool
{
    // filter_var settles most of it, and does so correctly for both families.
    if (filter_var($ip, FILTER_VALIDATE_IP,
                   FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
        // Public as far as the filter is concerned. Carrier-grade NAT is not in
        // its reserved list and is somebody's LAN often enough to matter.
        return ip_in_range($ip, '100.64.0.0/10');
    }
    return true;
}

/**
 * Resolve a URL's host and refuse anything that points inside the network.
 *
 * Returns null when the URL is acceptable, or a sentence saying why not.
 *
 * Provider endpoints are configured by an administrator rather than posted by a
 * visitor, so this is not an unauthenticated SSRF - but "an administrator pasted
 * a URL from a forum" is an ordinary way to reach an internal service, and the
 * check costs one DNS lookup on a request that is about to cross the internet.
 *
 * Every address behind the name is checked, not just the first: a name that
 * resolves to one public and one private address must not be fetchable on the
 * strength of the public one.
 */
function metadata_url_is_allowed(string $url): ?string
{
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        return 'That does not look like a URL.';
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return 'Only http and https URLs are fetched.';
    }
    // An IPv6 literal arrives from parse_url() wrapped in brackets, which is not
    // an address any resolver or validator recognises. Unwrapped, ::1 is caught
    // as loopback; left wrapped it fell through to a DNS lookup that failed for
    // the wrong reason.
    $host = trim($parts['host'], '[]');

    // A literal address needs no lookup, and must not get one: resolving it
    // would be a no-op that only added a way to be wrong.
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return metadata_address_is_internal($host)
            ? 'That address is inside the network, so it will not be fetched.'
            : null;
    }

    $addresses = [];
    foreach ([DNS_A, DNS_AAAA] as $type) {
        $records = @dns_get_record($host, $type);
        foreach (is_array($records) ? $records : [] as $r) {
            $ip = $r['ip'] ?? ($r['ipv6'] ?? null);
            if (is_string($ip) && $ip !== '') {
                $addresses[] = $ip;
            }
        }
    }
    // dns_get_record() asks DNS and nothing else, so a name in /etc/hosts comes
    // back empty - and 'localhost' is exactly the name somebody would try. This
    // resolves the way the request itself will, which is the answer that matters.
    if ($addresses === []) {
        $viaHosts = @gethostbynamel($host);
        if (is_array($viaHosts)) {
            $addresses = $viaHosts;
        }
    }
    // A name that will not resolve here is refused rather than attempted. The
    // request would fail anyway; failing now says why.
    if ($addresses === []) {
        return 'That hostname does not resolve.';
    }
    foreach ($addresses as $ip) {
        if (metadata_address_is_internal($ip)) {
            return 'That hostname resolves to an address inside the network ('
                 . $ip . '), so it will not be fetched.';
        }
    }
    return null;
}

/**
 * Follow redirects by hand, checking each hop.
 *
 * cURL's own FOLLOWLOCATION is the thing being replaced here: it re-resolves and
 * re-connects inside the library, so a public URL that answers 302 to
 * http://127.0.0.1:8123 defeats any check made before the call. Manual hops mean
 * every address is validated before a connection is opened to it.
 *
 * Returns [finalUrl, error].
 */
function metadata_resolve_redirects(string $url, int $max = 3, int $timeout = 15): array
{
    if (!function_exists('curl_init')) {
        return [$url, null];   // the stream fallback below does its own checking
    }
    for ($hop = 0; $hop <= $max; $hop++) {
        $why = metadata_url_is_allowed($url);
        if ($why !== null) {
            return [null, $why];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($code < 300 || $code > 399 || $next === '') {
            return [$url, null];   // not a redirect, or nowhere to go
        }
        $url = $next;
    }
    return [null, 'That URL redirects more times than is reasonable.'];
}

/**
 * What an HTTP status means for somebody looking up a title.
 *
 * "The service returned HTTP 429" is the code rather than the answer. Too many
 * requests, not found, and their server is unwell are three different things to
 * do about it, and only one of them is worth trying again in a minute.
 */
function metadata_http_status_message(int $code): string
{
    return match (true) {
        $code === 429 => 'Asked too quickly — the service is rate limiting this server. '
                       . 'Wait a minute and try again.',
        $code === 403 => 'The service refused the request. It may want a key, or may be '
                       . 'blocking this server.',
        $code === 404 => 'The service has no page at that address.',
        $code >= 500  => 'The service is having trouble (HTTP ' . $code . '). Not this end.',
        default       => 'The service returned HTTP ' . $code . '.',
    };
}

/**
 * One place where outbound requests happen, so timeouts, the user agent and
 * error handling are consistent and there is a single thing to stub in tests.
 *
 * Returns [body, error]. Never throws.
 */
function metadata_http_get(string $url, array $headers = [], int $timeout = 15, ?string $post = null): array
{
    // A test double, set by the test suite, so provider parsing can be
    // exercised without reaching the network.
    if (isset($GLOBALS['metadata_http_stub']) && is_callable($GLOBALS['metadata_http_stub'])) {
        return ($GLOBALS['metadata_http_stub'])($url, $headers, $timeout);
    }

    // With a way to reach whoever is running this.
    //
    // Wikimedia's policy asks for an address in the User-Agent and throttles
    // clients that give none - which is one half of the 429s a busy lookup was
    // collecting. The instance's own base URL is the honest answer; where none is
    // configured it says so rather than inventing one.
    $contact = trim((string) (config('base_url') ?? ''));
    $ua = 'RetroHive/' . APP_VERSION . ' ('
        . ($contact !== '' ? '+' . $contact : 'self-hosted collection catalogue') . ')';

    // Provider endpoints are admin-configured rather than user-supplied, so
    // this is not an unauthenticated SSRF - but an administrator pasting a URL
    // from a forum should not be able to make the server read file:///etc/passwd
    // through a redirect, or reach the MariaDB port, or the cloud metadata
    // service. The scheme, the size and the redirect count were already capped;
    // the address the name resolves to is now checked as well, on the first
    // request and on every hop it is redirected to.
    $maxBytes = 8 * 1024 * 1024;

    [$resolved, $why] = metadata_resolve_redirects($url, 3, $timeout);
    if ($resolved === null) {
        return [null, $why];
    }
    $url = $resolved;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            // Off, because the hops were walked and checked above. Left on, cURL
            // would re-resolve inside the library and the checks would mean
            // nothing.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_MAXFILESIZE    => $maxBytes,
            // Belt and braces: MAXFILESIZE only fires when the server sends a
            // Content-Length, so cut the transfer off by hand as well.
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => static fn($ch, $dlTotal, $dlNow) => $dlNow > $maxBytes ? 1 : 0,
        ];

        // A body makes it a POST. Added for IGDB, which takes its query in the
        // body rather than the query string, and shares every check above -
        // duplicating this function for one verb would have meant duplicating
        // the address checks with it, which is exactly how one of two copies
        // ends up without them.
        if ($post !== null) {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = $post;
        }

        // PROTOCOLS_STR landed in curl 7.85 / PHP 8.2; the integer constants
        // still work everywhere older.
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR]       = 'http,https';
            $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        } elseif (defined('CURLPROTO_HTTP')) {
            $options[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            metadata_debug('request failed', ($err !== '' ? $err : 'no reason given'));
            return [null, $err !== '' ? $err : 'Request failed.'];
        }
        metadata_debug('HTTP status', $code);
        metadata_debug('bytes received', strlen((string) $body));
        if (metadata_debugging()) {
            // The first part of what came back. A login wall, a Cloudflare
            // challenge and a maintenance page all answer 200 with HTML, and
            // which one it is takes about four lines to see.
            metadata_debug('first 400 bytes',
                preg_replace('/\\s+/', ' ', substr((string) $body, 0, 400)));
            $GLOBALS['__metadata_last_body'] = (string) $body;
        }
        if ($code >= 400) {
            return [null, metadata_http_status_message((int) $code)];
        }
        return [(string) $body, null];
    }

    // The fallback has to be constrained the same way, or turning curl off
    // quietly removes the protections above.
    if (!preg_match('#^https?://#i', $url)) {
        return [null, 'Only http and https URLs are fetched.'];
    }
    $why = metadata_url_is_allowed($url);
    if ($why !== null) {
        return [null, $why];
    }
    $context = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => implode("\r\n", array_merge(['User-Agent: ' . $ua], $headers)),
        'timeout' => $timeout,
        // Zero, not three. The stream wrapper follows redirects internally with
        // no way to inspect where it is going, so a public URL answering 302 to
        // an internal one would slip past the check just made. A redirect
        // arrives as a 3xx and is reported rather than chased.
        'max_redirects' => 0,
        // Needed to read an error body, but it also means a 404 arrives looking
        // like success, so the status line has to be checked by hand below.
        'ignore_errors' => true,
    ]]);
    // maxlen rather than an unbounded read: a hostile or broken endpoint should
    // cost a few megabytes, not the process.
    $body = @file_get_contents($url, false, $context, 0, $maxBytes);
    if ($body === false) {
        return [null, 'Request failed. Is outbound HTTPS allowed from this server?'];
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];   // last one wins, after any redirects
        }
    }
    if ($status >= 400) {
        return [null, metadata_http_status_message((int) $status)];
    }

    return [(string) $body, null];
}

// --- Cache ------------------------------------------------------------------

function metadata_cache_get(string $key): ?array
{
    $row = one('SELECT payload FROM metadata_cache WHERE cache_key = ? AND (expires_at IS NULL OR expires_at > NOW())', [$key]);
    if ($row === null) {
        return null;
    }
    $decoded = json_decode((string) $row['payload'], true);
    return is_array($decoded) ? $decoded : null;
}

function metadata_cache_put(string $provider, string $key, array $payload, int $ttlHours = 168): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    q(
        'INSERT INTO metadata_cache (cache_key, provider, payload, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW(), expires_at = VALUES(expires_at)',
        [$key, $provider, $json, $ttlHours]
    );
}

function metadata_cache_key(string $provider, string $operation, array $args): string
{
    return hash('sha256', $provider . '|' . $operation . '|' . json_encode($args));
}

// --- Platform mapping -------------------------------------------------------

/** What this provider calls our library, if a mapping has been set. */
function remote_platform_for(int $providerId, int $platformId): ?string
{
    $row = one(
        'SELECT remote_platform_id FROM metadata_provider_platforms WHERE provider_id = ? AND platform_id = ?',
        [$providerId, $platformId]
    );
    return $row === null ? null : (string) $row['remote_platform_id'];
}

/**
 * Ask a provider what platforms it knows about, so our libraries can be mapped
 * onto its ids rather than guessed at. Returns [[id => name], error].
 */
function metadata_remote_platforms(array $provider): array
{
    $fn = 'metadata_platforms_' . $provider['type'];
    if (!function_exists($fn)) {
        return [[], 'This source does not publish a platform list.'];
    }
    return $fn(metadata_params($provider));
}

function metadata_platforms_thegamesdb(array $params): array
{
    return thegamesdb_platforms($params);
}

/**
 * Match our libraries against a remote platform list by name.
 *
 * Deliberately conservative: only exact and clearly-contained matches are
 * proposed. A fuzzy guess that maps the Amiga onto the Amiga CD32 is worse than
 * leaving it blank, because the mistake is silent afterwards.
 */
function metadata_suggest_platform_map(array $remote): array
{
    $normalise = function (string $s): string {
        $s = strtolower($s);
        $s = str_replace(['-', '_', '/', '.'], ' ', $s);
        $s = preg_replace('/\b(commodore|sinclair|acorn|atari|sega|nintendo|nec|sharp|amstrad)\b/', '', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    };

    $suggestions = [];
    foreach (all_platforms() as $local) {
        $localName = $normalise((string) $local['name']);
        if ($localName === '') {
            continue;
        }
        $best = null;
        foreach ($remote as $id => $name) {
            $remoteName = $normalise((string) $name);
            if ($remoteName === '') {
                continue;
            }
            if ($remoteName === $localName) {
                $best = (string) $id;
                break;                       // exact wins outright
            }
        }
        if ($best !== null) {
            $suggestions[(int) $local['id']] = $best;
        }
    }
    return $suggestions;
}

// --- Public entry points ----------------------------------------------------

/**
 * Search one provider. Returns ['results' => [...], 'error' => string|null].
 *
 * A result is a normalised candidate:
 *   remote_id, title, year, developer, publisher, platform, url, cover_url,
 *   summary, genre, provider, provider_label
 */
function metadata_search(array $provider, string $title, ?int $platformId = null): array
{
    $title = trim($title);
    if ($title === '') {
        return ['results' => [], 'error' => 'Enter a title to search for.'];
    }

    $type   = (string) $provider['type'];
    $params = metadata_params($provider);
    $remote = $platformId === null ? null : remote_platform_for((int) $provider['id'], $platformId);

    // Every lookup is logged, here, because this is the one place all of them
    // pass through - each source's own search function is reached only from
    // below this line, so nothing can quietly skip it.
    //
    // What is logged is the shape of the request and the shape of the answer:
    // which source, what was asked, which machine it was narrowed to, how many
    // came back and how long it took. Not the results themselves - an admin
    // tracing "why did this take nine seconds" or "is this source answering at
    // all" needs counts and timings, and a log full of scraped prose is a log
    // nobody reads twice.
    $started = microtime(true);
    $logCtx  = [
        'source'   => $type,
        'provider' => (int) $provider['id'],
        'title'    => mb_substr($title, 0, 120),
        'platform' => $remote,
    ];

    $key    = metadata_cache_key($type, 'search', [$title, $remote]);
    $cached = metadata_cache_get($key);
    if ($cached !== null) {
        // Said plainly, because "0.001s" against a source that is slow is the
        // sort of thing that sends somebody looking in the wrong place.
        log_event('metadata', 'search.cached',
            sprintf('%s: "%s" answered from cache, %d result%s',
                $logCtx['source'], $logCtx['title'], count($cached),
                count($cached) === 1 ? '' : 's'),
            LOG_INFO, $logCtx + ['results' => count($cached)]);
        return ['results' => $cached, 'error' => null, 'cached' => true];
    }

    $fn = 'metadata_search_' . $type;
    if (!function_exists($fn)) {
        $why = 'No implementation for provider type "' . $type . '".';
        log_event('metadata', 'search.failed',
            sprintf('%s: %s', (string) $provider['name'], $why), LOG_WARNING, $logCtx);
        return ['results' => [], 'error' => $why];
    }

    [$results, $error] = $fn($params, $title, $remote);

    $took = round((microtime(true) - $started) * 1000);

    if ($error !== null) {
        q('UPDATE metadata_providers SET last_error = ?, last_used_at = NOW() WHERE id = ?',
          [mb_substr($error, 0, 255), (int) $provider['id']]);
        log_event('metadata', 'search.failed',
            sprintf('%s: "%s" failed after %dms — %s',
                $logCtx['source'], $logCtx['title'], $took, truncate($error, 160)),
            LOG_WARNING, $logCtx + ['ms' => $took, 'error' => mb_substr($error, 0, 255)]);
        return ['results' => [], 'error' => $error];
    }

    foreach ($results as &$r) {
        $r['provider']       = $type;
        $r['provider_label'] = metadata_provider_definition($type)['label'] ?? $type;
        $r['provider_id']    = (int) $provider['id'];
        // What this source calls the library we are adding to, when a mapping
        // exists. Comparing ids is exact, unlike comparing platform names.
        $r['expected_platform_id'] = $remote;
    }
    unset($r);

    metadata_cache_put($type, $key, $results);
    q('UPDATE metadata_providers SET last_error = NULL, last_used_at = NOW() WHERE id = ?', [(int) $provider['id']]);

    // How many pictures came with it, because that is the other thing an admin
    // is tracing: a source that answers instantly with nothing to import is a
    // different problem from one that answers slowly with plenty.
    $pictures = 0;
    foreach ($results as $one) {
        $pictures += count($one['images'] ?? []);
    }
    log_event('metadata', 'search.done',
        sprintf('%s: "%s" gave %d result%s (%d image%s) in %dms',
            $logCtx['source'], $logCtx['title'], count($results),
            count($results) === 1 ? '' : 's', $pictures, $pictures === 1 ? '' : 's', $took),
        LOG_INFO, $logCtx + ['results' => count($results), 'images' => $pictures, 'ms' => $took]);

    return ['results' => $results, 'error' => null];
}

/** Search every enabled provider, best-priority first. */
/**
 * Does this source answer questions about that kind of thing?
 *
 * Every definition carries `domains` now. A source without one is treated as
 * answering about both rather than neither: a missing tag is an omission in the
 * catalogue, and silently asking nothing would look like the source being
 * broken.
 */
function metadata_provider_covers(string $type, ?string $domain): bool
{
    if ($domain === null) {
        return true;
    }
    $def = metadata_provider_definition($type);
    $domains = $def['domains'] ?? null;
    if (!is_array($domains) || $domains === []) {
        return true;
    }
    return in_array($domain, $domains, true);
}

/**
 * Has this source been tried against that machine?
 *
 * A question, not a gate. It was a gate - `only_for` refused any platform not on
 * the list - and that is wrong for the case this catalogue is built for: a
 * person who never synchronises the templates and names their own machines. Their
 * Amiga has the slug `amiga-500-mine`, matches nothing, and every source is
 * refused on every entry they own. A tag written here cannot know what somebody
 * else calls their shelves.
 *
 * So the lists are documentation now, shown beside a source rather than deciding
 * for it. A source that has not been tried on a machine may still answer, and
 * finding that out is the operator's to do.
 */
function metadata_provider_tested_with(string $type, ?string $platformSlug): bool
{
    if ($platformSlug === null) {
        return true;
    }
    $tested = metadata_provider_definition($type)['tested_with'] ?? null;
    if (!is_array($tested) || $tested === []) {
        return true;
    }
    return in_array($platformSlug, $tested, true);
}

/**
 * @param ?string $domain 'software' or 'hardware', from the entry being looked
 *        up. Null asks everything, which is what the admin probe does.
 */
/**
 * @param int|null $categoryId  the branch the entry is filed under, so the tree's
 *                              own answer about which sources serve it is used.
 *                              Without it every enabled source is asked, and the
 *                              category editor's On/Off buttons decide nothing at
 *                              all - which is what was happening: TheRetroWeb was
 *                              switched off for a branch and answered anyway.
 */
function metadata_search_all(string $title, ?int $platformId = null, ?string $domain = null,
                             ?int $categoryId = null): array
{
    $results  = [];
    $errors   = [];
    $unmapped = [];
    $skipped  = [];
    $asked    = [];

    // The machine, by slug, so the platform tags can be read against it.
    $platformSlug = null;
    if ($platformId !== null) {
        $platformSlug = scalar('SELECT slug FROM platforms WHERE id = ?', [$platformId]);
        $platformSlug = $platformSlug === null ? null : (string) $platformSlug;
    }

    // What this branch says, or everything enabled when there is no branch to
    // ask - the API's bare search has no entry behind it.
    $ask = $categoryId === null
        ? enabled_metadata_providers()
        : providers_for($categoryId, $platformId);

    foreach ($ask as $provider) {
        $type = (string) $provider['type'];

        // Asking TheGamesDB about an accelerator card was never going to work,
        // and the page of errors it produced read as configuration trouble
        // rather than as a question nobody had asked the right source.
        if (!metadata_provider_covers($type, $domain)) {
            // Not reported. A games database was never going to answer about an
            // accelerator card, that will be true on every lookup forever, and
            // there is nothing anybody can do about it - so a line naming it is
            // noise on a screen somebody is reading for the answer.
            //
            // The scope skip below is different and is still reported: it is
            // about *this* machine, and the remedy is to ask a source that covers
            // it.
            continue;
        }
        // A one-scope source is not asked about a machine outside its scope.
        //
        // This is narrower than the gate that was removed, and deliberately so.
        // That one refused any platform outside a *tested-with* list, which locked
        // out anybody whose platforms are their own: their slugs match no list, so
        // every source was refused on every entry.
        //
        // A one-scope source is different. TheRetroWeb covers PC hardware and
        // nothing else - that is the whole of the site, not a list of what has
        // been tried - so asking it about an Amiga produces a paragraph of
        // apology on the review screen for a question it was never going to
        // answer.
        //
        // Two conditions keep the old failure from coming back: the source must
        // have declared itself one-scope, and the entry's machine must be one
        // *this catalogue knows*. Somebody's own slug is unknown here, so it
        // never matches the scope and never triggers a skip - they get asked, as
        // before.
        if ($platformId !== null) {
            $slug  = (string) (scalar('SELECT slug FROM platforms WHERE id = ?', [$platformId]) ?? '');
            // What the source is about, declared - not what it has been tried on.
            $scope = metadata_provider_limited_to((string) $provider['type']);
            // "Known" means the templates ship it, not that the database has a
            // row. A platform somebody adds themselves can be global too, and
            // asking the database counted those as known - which skipped sources
            // on exactly the machines this guard was written to protect.
            //
            // The scopes were written against the template slugs, so a template
            // slug is the only thing that can meaningfully be compared with one.
            $known = $slug !== '' && in_array($slug, metadata_template_platform_slugs(), true);
            if ($known && $scope !== [] && !in_array($slug, $scope, true)) {
                // Not reported. It is true, it will be true on every lookup of
                // that machine forever, and there is nothing anybody can do
                // about it - so a line naming it is noise on a screen being read
                // for the answer. The same reasoning that removed the domain
                // mismatch, applied to the case I kept.
                //
                // Still in the diagnostics, where somebody has asked why a
                // source said nothing: bin/lookup.php --verbose.
                metadata_debug('skipped ' . $provider['name'],
                    'covers only ' . implode(', ', $scope) . ', and this is ' . $slug);
                continue;
            }
        }

        // Without a mapping the search is not filtered at all, which is how a
        // CD32 release ends up first when you are cataloguing an Amiga disk.
        //
        // Only worth saying about a source that could use one. The Amiga Hardware
        // Database takes no platform parameter - it covers one family and that is
        // the whole of its scope - so warning that it has no mapping told people
        // to go and set something that would change nothing, and reads as the
        // source not having been picked up at all.
        if ($platformId !== null
            && metadata_provider_filters_by_platform((string) $provider['type'])
            && remote_platform_for((int) $provider['id'], $platformId) === null) {
            $unmapped[] = $provider['name'];
        }
        $out = metadata_search($provider, $title, $platformId);
        if ($out['error'] !== null) {
            $errors[$provider['name']] = $out['error'];
            continue;
        }
        // What each source came back with, counted.
        //
        // A source that answers nothing is invisible on the review screen -
        // there is no row for it - so "did it even look?" was a fair question
        // with no way to answer it. A search for a card the Big Book has never
        // heard of looked exactly like a search it never ran.
        $asked[(string) $provider['name']] = count($out['results']);

        foreach ($out['results'] as $r) {
            $results[] = $r;
        }
    }

    $localPlatform = null;
    if ($platformId !== null) {
        $row = one('SELECT name FROM platforms WHERE id = ?', [$platformId]);
        $localPlatform = $row === null ? null : (string) $row['name'];
    }

    return [
        'results'  => metadata_rank_results($results, $title, $localPlatform),
        'errors'   => $errors,
        'unmapped' => $unmapped,
        'skipped'  => $skipped,
        'asked'    => $asked,
    ];
}

/**
 * Order candidates so the right one is first.
 *
 * Filtering at the provider is the main defence, but not every source supports
 * it and none of them are reliable about it. Adding Speedball 2 to the Amiga
 * shelf should put the Amiga release at the top even when the search also
 * returned the Mega Drive port.
 */
function metadata_rank_results(array $results, string $query, ?string $platformName): array
{
    $normalise = function (string $s): string {
        $s = strtolower(trim($s));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s);
    };

    $wantedPlatform = $platformName === null ? null : $normalise($platformName);
    $wantedTitle    = $normalise($query);

    foreach ($results as &$r) {
        $score = 0;

        $expectedId  = $r['expected_platform_id'] ?? null;
        $candidateId = $r['platform_id'] ?? null;

        if ($expectedId !== null && $candidateId !== null && (string) $candidateId !== '') {
            // Ids are unambiguous: TheGamesDB gives the Amiga and the Amiga
            // CD32 different numbers even though the names look alike.
            if ((string) $candidateId === (string) $expectedId) {
                $score += 120;
                $r['platform_match'] = 'exact';
            } else {
                $score -= 80;
                $r['platform_match'] = 'other';
            }
        } else {
            $candidatePlatform = $normalise((string) ($r['platform'] ?? ''));
            if ($wantedPlatform !== null && $candidatePlatform !== '') {
                if ($candidatePlatform === $wantedPlatform) {
                    $score += 100;
                    $r['platform_match'] = 'exact';
                } elseif (str_contains($candidatePlatform, $wantedPlatform) || str_contains($wantedPlatform, $candidatePlatform)) {
                    $score += 60;
                    $r['platform_match'] = 'close';
                } else {
                    $score -= 40;
                    $r['platform_match'] = 'other';
                }
            } else {
                $r['platform_match'] = 'unknown';
            }
        }

        $candidateTitle = $normalise((string) ($r['title'] ?? ''));
        if ($candidateTitle === $wantedTitle) {
            $score += 50;
        } elseif ($candidateTitle !== '' && str_starts_with($candidateTitle, $wantedTitle)) {
            $score += 30;
        } elseif ($candidateTitle !== '' && str_contains($candidateTitle, $wantedTitle)) {
            $score += 15;
        }

        // A candidate with art is more useful than one without.
        if (!empty($r['images'])) {
            $score += 10;
        }
        if (!empty($r['year'])) {
            $score += 2;
        }

        $r['score'] = $score;
    }
    unset($r);

    usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
    return $results;
}

/**
 * Turn a candidate into the fields an entry understands.
 * Only keys the provider actually supplied are returned, so a merge never
 * blanks something the user already filled in.
 */
function metadata_to_item_fields(array $candidate): array
{
    $fields = [];

    if (!empty($candidate['title']))        $fields['title'] = mb_substr((string) $candidate['title'], 0, 220);
    if (!empty($candidate['year']))         $fields['release_year'] = (int) $candidate['year'];
    if (!empty($candidate['release_date'])) $fields['release_date'] = (string) $candidate['release_date'];
    if (!empty($candidate['developer']))    $fields['developer_name'] = mb_substr((string) $candidate['developer'], 0, 160);
    if (!empty($candidate['publisher']))    $fields['publisher_name'] = mb_substr((string) $candidate['publisher'], 0, 160);
    // The barcode field has sat on items since a few rounds back with no
    // metadata source ever populating it - the first source with a real
    // one to offer (a release's own UPC/EAN) closes that gap rather than
    // leaving it for whichever provider happened to add it next.
    if (!empty($candidate['barcode']))       $fields['barcode'] = mb_substr((string) $candidate['barcode'], 0, 40);
    // No genre. Genres stopped being their own thing when they were folded into
    // the category tree - `items` has no genre column and nothing reads one - so
    // producing the field meant offering somebody a row that could not be applied,
    // above a "currently" cell that read a column which is not there.
    
    if (!empty($candidate['url']))          $fields['external_url'] = mb_substr((string) $candidate['url'], 0, 500);
    // The description, not the notes. A summary from a source is about the
    // release; notes are what somebody wrote about their own copy, and importing
    // one over the other lost the second.
    if (!empty($candidate['summary']))      $fields['description'] = (string) $candidate['summary'];

    return $fields;
}

/**
 * Download an image a provider pointed at and attach it to an entry.
 * Returns [ok, error].
 */
function metadata_import_image(int $itemId, string $url, string $kind = 'box_front', ?string $caption = null): array
{
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        return [false, 'That is not a usable image URL.'];
    }

    [$body, $err] = metadata_http_get($url, [], 20);
    if ($body === null) {
        return [false, 'Could not fetch the artwork: ' . $err];
    }
    $max = (int) config('uploads.max_bytes');
    if (strlen($body) > $max) {
        return [false, sprintf('The artwork is %.1f MB, over the %.0f MB limit.', strlen($body) / 1048576, $max / 1048576)];
    }

    // The same picture twice.
    //
    // Uploads have been deduplicated by content hash since they existed, because
    // a batch from a phone repeats constantly. An import never set the hash at
    // all - so running a lookup again and ticking the same artwork attached a
    // second copy of every picture, which is exactly what you get for doing the
    // obvious thing twice.
    //
    // Hashed from the bytes in hand, before anything is written, so a repeat
    // costs one fetch and no file.
    $hash = hash('sha256', $body);
    if (one('SELECT id FROM item_images WHERE item_id = ? AND content_hash = ?',
            [$itemId, $hash]) !== null) {
        // A third element rather than a special string: the caller needs to tell
        // "already there" from "could not be fetched", and reading the message to
        // find out is how a wording change becomes a bug. Two-element list()
        // destructuring in existing callers still works.
        return [false, 'That picture is already on this entry.', true];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'rvart');
    if ($tmp === false || file_put_contents($tmp, $body) === false) {
        return [false, 'Could not buffer the artwork on the server.'];
    }

    // Judged by inspecting the bytes, exactly as an uploaded file would be.
    // A remote source is no more trustworthy than a browser.
    $info    = @getimagesize($tmp);
    $allowed = config('uploads.allowed');
    if ($info === false || !isset($allowed[$info['mime']])) {
        @unlink($tmp);
        return [false, 'What came back was not a supported image.'];
    }

    $ext      = $allowed[$info['mime']];
    $basename = $itemId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target   = uploads_dir() . '/' . $basename;

    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        return [false, 'Could not write to the uploads directory.'];
    }
    @chmod($target, 0644);
    make_variants($target, $basename, (string) $info['mime']);

    $count = (int) scalar('SELECT COUNT(*) FROM item_images WHERE item_id = ?', [$itemId]);
    insert_row('item_images', [
        'item_id'       => $itemId,
        'filename'      => $basename,
        'original_name' => mb_substr(basename(parse_url($url, PHP_URL_PATH) ?: 'artwork'), 0, 255),
        'kind'          => in_array($kind, image_kind_options(), true) ? $kind : 'other',
        // Everything a lookup writes is official, always. That is the one rule
        // the whole split exists for: an import can never land among somebody's
        // own photographs of their own copy, whatever kind it claims to be.
        'provenance'    => 'official',
        'content_hash'  => $hash,
        // Remembered so the review screen can mark this one as already here
        // next time, rather than fetching it to find out.
        'source_url'    => mb_substr($url, 0, 500),
        'caption'       => $caption,
        'width'         => (int) $info[0],
        'height'        => (int) $info[1],
        'filesize'      => strlen($body),
        'is_primary'    => $count === 0 ? 1 : 0,
        'sort_order'    => ($count + 1) * 10,
    ]);
    ensure_primary_image($itemId);

    return [true, null];
}

/**
 * Resolve a scraped interface or feature onto the canonical vocabulary.
 *
 * The Amiga Hardware Database says "trapdoor slot"; the vocabulary calls it
 * 'trap'. Storing the code means "everything that fits a trapdoor slot" is one
 * query rather than a guess at four spellings. Anything unrecognised is kept
 * verbatim - losing information to make it tidy would be the wrong trade.
 */
function hardware_vocab_code(string $kind, string $text, ?int $platformId = null): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $sql  = 'SELECT code, name FROM hardware_vocab WHERE kind = ?';
    $args = [$kind];
    if ($platformId !== null) {
        $sql   .= ' AND (platform_id = ? OR platform_id IS NULL)';
        $args[] = $platformId;
    }

    foreach (all($sql, $args) as $row) {
        if (strcasecmp((string) $row['name'], $text) === 0
            || strcasecmp((string) $row['code'], $text) === 0) {
            return (string) $row['code'];
        }
    }

    return $text;
}

/** Human wording for a stored vocabulary code. */
function hardware_vocab_label(string $kind, string $code, ?int $platformId = null): string
{
    if ($code === '') {
        return '';
    }
    $sql  = 'SELECT name FROM hardware_vocab WHERE kind = ? AND code = ?';
    $args = [$kind, $code];
    if ($platformId !== null) {
        $sql   .= ' AND (platform_id = ? OR platform_id IS NULL)';
        $args[] = $platformId;
    }
    $row = one($sql . ' LIMIT 1', $args);
    return $row === null ? $code : (string) $row['name'];
}

/**
 * The hardware half of a candidate, mapped onto item_hardware columns.
 *
 * Kept separate from metadata_to_item_fields() because these land in a
 * different table and only apply to a hardware entry. Only keys the source
 * actually supplied come back, so a merge never blanks something already there.
 */
function metadata_to_hardware_fields(array $candidate, ?int $platformId = null): array
{
    $hw = $candidate['hardware'] ?? null;
    if (!is_array($hw)) {
        return [];
    }

    $fields = [];
    if (!empty($hw['model']))          $fields['model'] = mb_substr((string) $hw['model'], 0, 160);
    if (!empty($hw['board_revision'])) $fields['board_revision'] = mb_substr((string) $hw['board_revision'], 0, 80);
    if (!empty($hw['fits']))           $fields['fits'] = mb_substr((string) $hw['fits'], 0, 255);   // the item's own override, still text
    if (!empty($hw['cpu']))            $fields['cpu'] = mb_substr((string) $hw['cpu'], 0, 120);
    if (!empty($hw['memory']))         $fields['memory'] = mb_substr((string) $hw['memory'], 0, 120);
    if (!empty($hw['storage']))        $fields['storage'] = mb_substr((string) $hw['storage'], 0, 120);
    if (!empty($hw['provides']))       $fields['provides'] = mb_substr((string) $hw['provides'], 0, 120);

    if (!empty($hw['interface'])) {
        $fields['interface'] = mb_substr(hardware_vocab_code('interface', (string) $hw['interface'], $platformId), 0, 80);
    }
    if (!empty($candidate['year']) && empty($fields['manufactured_year'])) {
        $fields['manufactured_year'] = (int) $candidate['year'];
    }

    // Notes and the Autoconfig ID have no column of their own; both are worth
    // keeping, so they go into modifications where a person will see them.
    $extra = [];
    if (!empty($hw['notes']))         { $extra[] = (string) $hw['notes']; }
    if (!empty($hw['autoconfig_id'])) { $extra[] = 'Autoconfig ID ' . $hw['autoconfig_id']; }
    if ($extra !== []) {
        $fields['modifications'] = implode("\n", $extra);
    }

    return $fields;
}

/** Human labels for the review screen. */
function hardware_field_labels(): array
{
    return [
        'model'             => 'Model',
        'board_revision'    => 'Board revision',
        'manufactured_year' => 'Made in',
        'interface'         => 'Interface',
        'provides'          => 'Provides',
        'fits'              => 'Fits',
        'cpu'               => 'Processor',
        'memory'            => 'Memory',
        'storage'           => 'Storage',
        'modifications'     => 'Notes',
    ];
}

/** Create or update the hardware row for an entry. */
/**
 * Fold a provider's free-form specification fields into the spec list.
 *
 * A scraper reports what its source calls things - the Amiga Hardware Database
 * has processor, memory and storage sections - and those used to land in three
 * columns of the same name. The columns are gone: a machine has whatever it has,
 * and encoding three of them in the schema meant a monitor's tube size had
 * nowhere to go. The provider's vocabulary is unchanged; only where it lands is.
 *
 * Rows already present win, so re-running a lookup does not overwrite a
 * correction somebody made by hand.
 */
function hardware_fields_to_specs(array $fields, array $existing = []): array
{
    $map = [
        'cpu'     => 'Processor',
        'memory'  => 'Memory',
        'storage' => 'Storage',
        'video'   => 'Video',
    ];

    $have = [];
    foreach ($existing as $row) {
        $have[mb_strtolower(trim((string) ($row['label'] ?? '')))] = true;
    }

    $specs = $existing;
    foreach ($map as $key => $label) {
        if (!isset($fields[$key])) {
            continue;
        }
        $value = trim((string) $fields[$key]);
        if ($value === '' || isset($have[mb_strtolower($label)])) {
            continue;
        }
        $specs[] = ['label' => $label, 'value' => mb_substr($value, 0, 400)];
        $have[mb_strtolower($label)] = true;
    }
    return $specs;
}

function save_item_hardware(int $itemId, array $fields): void
{
    if ($fields === []) {
        return;
    }

    // Providers still speak in cpu/memory/storage; the entry stores one list.
    $loose = array_intersect_key($fields, array_flip(['cpu', 'memory', 'storage', 'video']));
    if ($loose !== []) {
        $current = scalar('SELECT specs FROM item_hardware WHERE item_id = ?', [$itemId]);
        $current = is_string($current) && $current !== '' ? (json_decode($current, true) ?: []) : [];
        $merged  = hardware_fields_to_specs($loose, $current);
        $fields  = array_diff_key($fields, $loose);
        if ($merged !== []) {
            $fields['specs'] = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($fields === []) {
            return;
        }
    }

    $exists = one('SELECT item_id FROM item_hardware WHERE item_id = ?', [$itemId]) !== null;
    if ($exists) {
        $sets = [];
        $args = [];
        foreach ($fields as $column => $value) {
            $sets[] = '`' . $column . '` = ?';
            $args[] = $value;
        }
        $args[] = $itemId;
        q('UPDATE item_hardware SET ' . implode(', ', $sets) . ' WHERE item_id = ?', $args);
        return;
    }
    insert_row('item_hardware', array_merge(['item_id' => $itemId], $fields));
}


/** Record what was taken and from where. */
function record_metadata_import(int $itemId, array $candidate, array $appliedFields, ?int $userId): void
{
    insert_row('metadata_imports', [
        'item_id'     => $itemId,
        'provider_id' => isset($candidate['provider_id']) ? (int) $candidate['provider_id'] : null,
        'provider'    => mb_substr((string) ($candidate['provider'] ?? 'unknown'), 0, 40),
        'remote_id'   => isset($candidate['remote_id']) ? mb_substr((string) $candidate['remote_id'], 0, 120) : null,
        'remote_url'  => isset($candidate['url']) ? mb_substr((string) $candidate['url'], 0, 500) : null,
        'fields'      => json_encode(array_keys($appliedFields)),
        'user_id'     => $userId,
    ]);
}

// ============================================================================
// Provider: TheRetroWeb  (theretroweb.com)
//
// PC hardware - motherboards above all, which is the part of a PC collection
// hardest to identify from the board itself. A stamped model number and a
// chipset are usually all you have, and this is where that turns into a socket,
// a bus and a form factor.
//
// The markup is unusually regular: a "quick-spec-head" div carries the label,
// and the div after it carries the value - plain text, a link, or a bag of
// "text-block" spans when there are several. Reading it as label-then-sibling
// means a field they add later arrives without a code change.
// ============================================================================

function metadata_search_theretroweb(array $params, string $title, ?string $remotePlatform, bool $retry = true): array
{
    $base = rtrim((string) $params['endpoint'], '/');

    // Paste a search you built on their own site.
    //
    // Their manufacturer filter is assembled in the browser and its list is
    // fetched separately, so a program cannot read the maker ids. But the
    // address bar shows the finished query - so use their filter, copy the
    // address, and paste it here. Their interface does the narrowing; this
    // reads the result.
    if (preg_match('#theretroweb\\.com/([a-z-]+)\\?(.+)$#i', trim($title), $listing)
        && !str_contains($listing[1], '/s/')) {
        $section = $listing[1];
        parse_str(html_entity_decode($listing[2]), $q);
        $q['itemsPerPage'] = 100;   // whatever they had, we want them all

        [$body, $err] = metadata_http_get(
            $base . '/' . $section . '?' . http_build_query($q), [], (int) $params['timeout']
        );
        if ($body === null) {
            return [[], 'That search could not be fetched: ' . $err];
        }

        $found = [];
        foreach (array_slice(theretroweb_parse_listing($body, $section), 0, 12) as $slug) {
            [$page, ] = metadata_http_get($base . '/' . $section . '/s/' . $slug, [], (int) $params['timeout']);
            if ($page === null) {
                continue;
            }
            $one = theretroweb_parse_board($page, $slug, $section);
            if ($one !== null) {
                $found[] = $one;
            }
        }
        return $found === []
            ? [[], 'That search loaded but matched nothing on their side.']
            : [$found, null];
    }

    // Paste the address of a single page instead of searching.
    //
    // For hardware this is usually the better route anyway: you identify a board
    // by eye, with its page already open, and the search step adds nothing. It
    // also works whether or not their listing can be read by a program, which
    // the search cannot promise.
    if (preg_match('#theretroweb\.com/([a-z-]+)/s/([a-z0-9-]+)#i', trim($title), $direct)) {
        [$page, $err] = metadata_http_get(
            'https://theretroweb.com/' . $direct[1] . '/s/' . $direct[2], [], (int) $params['timeout']
        );
        if ($page === null) {
            return [[], 'That page could not be fetched: ' . $err];
        }
        $one = theretroweb_parse_board($page, $direct[2], $direct[1]);
        return $one === null
            ? [[], 'That page loaded but carried no specification table.']
            : [[$one], null];
    }
    // The site is divided by kind of part, and a card is not a board. Searching
    // only motherboards found nothing for a Voodoo 2, which is not a helpful
    // way for a source to be wrong.
    // The site's own paths. Graphics, sound, network and I/O cards all live
    // under expansioncards rather than in sections of their own - which is why
    // the mapping below reads labels instead of assuming what kind of page it
    // is on. Override with the sections parameter if they grow.
    $sections = array_filter(array_map('trim', explode(',', (string) ($params['sections'] ?? ''))));
    if ($sections === []) {
        $sections = ['motherboards', 'expansioncards', 'cpus'];
    }

    // Narrowing by maker, when one is configured and the search names it.
    $manufacturerId = theretroweb_manufacturer_id(
        (string) ($params['manufacturers'] ?? ''), $title, $base, (int) $params['timeout']
    );

    $out    = [];
    $errors = [];
    $notes  = [];
    foreach ($sections as $section) {
        // Their form field is "Name". Guessing ?search= meant the parameter was
        // ignored and the default listing came back - so a search for a Voodoo 2
        // returned whichever boards happened to be first, and the parser dutifully
        // scraped them. A search that silently returns the wrong thing is worse
        // than one that returns nothing.
        // Their own query, as it appears in the address bar:
        //   /expansioncards?&itemsPerPage=100&name=3c900-combo
        // Without itemsPerPage the listing pages at a size that made a search
        // look like it had matched nothing.
        [$body, $err] = metadata_http_get(
            theretroweb_search_url($base, $section, $title, $manufacturerId),
            [], (int) $params['timeout']
        );
        if ($body === null) {
            $errors[] = $section . ': ' . $err;
            continue;
        }

        // "Nothing found" is not a diagnosis. Reaching the page and finding no
        // entry links at all means something different from finding links that
        // did not match - the first says the address or the parameter is wrong,
        // or that the listing is assembled in the browser and there is nothing
        // to scrape. Saying which saves somebody guessing.
        if (!str_contains($body, '/' . $section . '/s/')) {
            $notes[] = $section;
            continue;
        }

        // The listing gives slugs; the detail pages carry the specification.
        // One request each, capped - this is somebody's site, not an API.
        foreach (array_slice(theretroweb_parse_listing($body, $section), 0, 4) as $slug) {
            [$page, ] = metadata_http_get($base . '/' . $section . '/s/' . $slug, [], (int) $params['timeout']);
            if ($page === null) {
                continue;
            }
            $one = theretroweb_parse_board($page, $slug, $section);
            // A listing that ignores the query returns everything, and importing
            // that is worse than importing nothing. Keep only what plausibly
            // answers what was asked.
            if ($one !== null && theretroweb_plausible($title, (string) $one['title'], $slug)) {
                $out[] = $one;
            }
        }
        if (count($out) >= 8) {
            break;
        }
    }

    // A second attempt on the most distinctive single word. Their search appears
    // to match the name fairly literally, so "3dfx voodoo 2" finds nothing while
    // "voodoo2" finds the card - and asking a person to guess that is unkind.
    if ($out === [] && $retry) {
        $word = theretroweb_best_word($title);
        if ($word !== null && $word !== trim($title)) {
            [$second, ] = metadata_search_theretroweb($params, $word, $remotePlatform, false);
            if ($second !== []) {
                return [$second, null];
            }
        }
    }

    // Their search matches on substring, so "3c900-combo" also finds every other
    // 3C900. Keeping them is right - the four Blizzard 1230 revisions are
    // genuinely different cards and choosing one would hide the rest - but an
    // exact match should lead and say so.
    $out = theretroweb_exact_first($out, $title);

    if ($out === []) {
        // A page that would not load is a different problem from one that
        // loaded and matched nothing, and telling somebody the wrong one sends
        // them off checking their spelling when the site is refusing them.
        if ($errors !== [] && $notes === []) {
            return [[], 'TheRetroWeb could not be reached — ' . implode('; ', array_slice($errors, 0, 2))];
        }

        $why = array_merge($errors, $notes);
        if ($why !== []) {
            return [[], 'TheRetroWeb has nothing matching that in ' . implode(', ', array_slice($why, 0, 4))
                . '. Their search matches the name closely, so try it as written on the '
                . 'board — "Voodoo2" rather than "Voodoo 2", or the part number on its own.'];
        }
        return [[], 'TheRetroWeb has no match. Try the model number as stamped on the board.'];
    }
    return [$out, null];
}

/**
 * Slugs from a listing page, for whichever section it belongs to.
 *
 * The section is a parameter because the site is divided by kind of part and a
 * card is not a board. Hardcoding /motherboards/ here meant every card search
 * came back empty while looking like it had worked.
 */
/**
 * Does this result plausibly answer the search?
 *
 * A guard against the site ignoring the query and returning its default page.
 * Without it a search for "voodoo" quietly imported a Wyse 486 board, which is
 * worse than importing nothing - a wrong answer that looks like a right one.
 *
 * Loose on purpose: any meaningful word matching is enough, so "Voodoo 2" still
 * finds "3Dfx 12MB Voodoo2".
 */
function theretroweb_plausible(string $query, string $title, string $slug): bool
{
    $words = array_filter(
        preg_split('/[^a-z0-9]+/i', strtolower($query)) ?: [],
        fn($w) => strlen($w) >= 3
    );
    if ($words === []) {
        return true;
    }
    $hay = strtolower($title . ' ' . $slug);
    foreach ($words as $w) {
        if (str_contains($hay, $w)) {
            return true;
        }
    }
    return false;
}

function theretroweb_parse_listing(string $html, string $section = 'motherboards'): array
{
    preg_match_all('#href="/' . preg_quote($section, '#') . '/s/([a-z0-9\\-]+)"#i', $html, $m);
    return array_values(array_unique($m[1] ?? []));
}

/**
 * One board. Kept separate from the request so it can be tested against a
 * fixture rather than against the internet.
 */
function theretroweb_parse_board(string $html, string $slug, string $section = 'motherboards'): ?array
{
    if (!class_exists('DOMDocument')) {
        error_log('[retrohive] TheRetroWeb needs the PHP dom extension.');
        return null;
    }

    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($doc);

    $specs = theretroweb_quick_specs($xp);
    if ($specs === []) {
        return null;
    }

    $name = theretroweb_title($xp) ?: str_replace('-', ' ', $slug);
    $hw   = theretroweb_map_specs($specs);

    return [
        'remote_id'   => $slug,
        'title'       => $name,
        'year'        => ($y = theretroweb_first($specs, ['release date', 'released']))
                         && preg_match('/(\d{4})/', $y, $mm) ? (int) $mm[1] : null,
        'developer'   => theretroweb_manufacturer($name)
                         ?? theretroweb_first($specs, ['manufacturer', 'brand', 'vendor']),
        'publisher'   => null,
        'platform'    => 'PC',
        'platform_id' => null,
        'genre'       => null,
        'url'         => 'https://theretroweb.com/' . $section . '/s/' . $slug,
        'cover_url'   => null,
        'images'      => theretroweb_images($xp),
        'summary'     => theretroweb_summary($specs),
        'hardware'    => $hw + ['model' => $name],
        'documents'   => [],
    ];
}

/**
 * Turn whatever labels the page used into item_hardware fields.
 *
 * Driven by the label rather than by what kind of page we think we are on. A
 * board has a socket and a form factor, a graphics card has a bus and video
 * memory, a network card has a bus and connectors - and the site will grow
 * sections nobody has thought about. Matching on the label means those arrive
 * without a code change, and anything unrecognised is kept in the summary
 * rather than dropped.
 *
 * The three questions kept apart on purpose: what it plugs into, what it takes,
 * and what it offers. Collapsing them is what makes "will this fit?"
 * unanswerable.
 */
function theretroweb_map_specs(array $specs): array
{
    // what it plugs into
    $interface = theretroweb_first($specs, [
        'bus', 'interface', 'slot', 'slot type', 'connection', 'form factor',
    ]);

    // what it takes
    $fits = theretroweb_join($specs, 'sockets')
        ?? theretroweb_join($specs, 'socket')
        ?? theretroweb_first($specs, ['supported cpus', 'compatible with', 'compatibility']);

    // what it offers
    $provides = array_filter([
        theretroweb_first($specs, ['chipset', 'gpu', 'gpu chip', 'chip', 'controller', 'codec']),
        theretroweb_join($specs, 'connectors'),
        theretroweb_join($specs, 'ports'),
        theretroweb_join($specs, 'outputs'),
        theretroweb_first($specs, ['psu connector']),
    ]);

    $memory = array_filter([
        theretroweb_first($specs, ['ram type', 'memory type', 'vram type']),
        ($m = theretroweb_first($specs, ['memory size', 'vram', 'video memory', 'max ram size']))
            ? (isset($specs['max ram size']) ? 'max ' . $m : $m) : null,
        ($c = theretroweb_first($specs, ['cache'])) ? $c . ' cache' : null,
    ]);

    // Anything worth keeping that has no column of its own.
    $notes = [];
    foreach ([
        'fsb speeds' => 'FSB', 'dimensions' => null, 'core clock' => 'Core',
        'memory clock' => 'Memory clock', 'ramdac' => 'RAMDAC', 'process' => 'Process',
        'tdp' => 'TDP', 'bandwidth' => 'Bandwidth', 'speed' => 'Speed',
    ] as $key => $prefix) {
        $v = theretroweb_join($specs, $key);
        if ($v !== null) {
            $notes[] = ($prefix === null ? '' : $prefix . ' ') . $v;
        }
    }

    return [
        'interface' => $interface,
        'fits'      => $fits,
        'provides'  => $provides === [] ? null : implode(', ', $provides),
        'memory'    => $memory === [] ? null : implode('; ', $memory),
        'notes'     => $notes === [] ? null : implode('; ', $notes),
    ];
}

/**
 * The label-then-value pairs.
 *
 * Each "quick-spec-head" is a label and the element after it is its value.
 * Reading them positionally rather than by a fixed list of names means a field
 * they add later comes through on its own.
 */
function theretroweb_quick_specs(DOMXPath $xp): array
{
    $out = [];
    foreach ($xp->query("//div[contains(@class,'quick-spec-head')]") as $head) {
        $label = strtolower(trim(preg_replace('/\s+/u', ' ', $head->textContent) ?? ''));
        if ($label === '') {
            continue;
        }

        // The next element sibling, skipping the whitespace between them.
        // instanceof rather than a nodeType comparison: getAttribute() is on
        // DOMElement, not on DOMNode, and only the instanceof form tells that to
        // a reader or an analyser. Same nodes either way.
        $value = $head->nextSibling;
        while ($value !== null && !$value instanceof DOMElement) {
            $value = $value->nextSibling;
        }
        if ($value === null || (string) $value->getAttribute('class') === 'quick-spec-head') {
            continue;
        }

        // Several values arrive as a bag of spans; one arrives as text.
        $blocks = $xp->query(".//span[contains(@class,'text-block')]", $value);
        if ($blocks->length > 0) {
            $vals = [];
            foreach ($blocks as $b) {
                $t = trim($b->textContent);
                if ($t !== '') { $vals[] = $t; }
            }
            $out[$label] = $vals;
        } else {
            $t = trim(preg_replace('/\s+/u', ' ', $value->textContent) ?? '');
            if ($t !== '') { $out[$label] = [$t]; }
        }
    }
    return $out;
}

function theretroweb_first(array $specs, array $keys): ?string
{
    foreach ($keys as $k) {
        if (!empty($specs[$k][0])) {
            return (string) $specs[$k][0];
        }
    }
    return null;
}

function theretroweb_join(array $specs, string $key, string $sep = ', '): ?string
{
    return empty($specs[$key]) ? null : implode($sep, $specs[$key]);
}

/** The board's name, from the page heading. */
function theretroweb_title(DOMXPath $xp): ?string
{
    foreach (["//div[contains(@class,'page-header')]//h1", '//h1', "//*[contains(@class,'title')]"] as $q) {
        $n = $xp->query($q)->item(0);
        if ($n !== null) {
            $t = trim(preg_replace('/\s+/u', ' ', $n->textContent) ?? '');
            if ($t !== '') { return mb_substr($t, 0, 160); }
        }
    }
    return null;
}

/**
 * The maker, taken from the front of the name.
 *
 * They do not publish it as its own field, and guessing from a known list beats
 * inventing a company from whatever the first word happens to be.
 */
function theretroweb_manufacturer(string $name): ?string
{
    $known = ['ASUS', 'MSI', 'Gigabyte', 'Abit', 'Soyo', 'Chaintech', 'Epox', 'DFI',
              'Tyan', 'Supermicro', 'Intel', 'AOpen', 'Biostar', 'ECS', 'Shuttle',
              'Iwill', 'FIC', 'PC Chips', 'Seavo', 'QDI', 'Acorp', 'Lucky Star'];
    foreach ($known as $maker) {
        if (stripos($name, $maker) === 0) {
            return $maker;
        }
    }
    return null;
}

/** Photographs, offered for import like any other artwork. */
function theretroweb_images(DOMXPath $xp): array
{
    $out = [];
    foreach ($xp->query("//a[contains(@class,'glightbox')]") as $a) {
        $href = $a->getAttribute('href');
        if ($href === '' || !preg_match('/\.(jpe?g|png|webp)/i', $href)) {
            continue;
        }
        $out[] = [
            'url'  => str_starts_with($href, 'http') ? $href : 'https://theretroweb.com' . $href,
            'kind' => 'box_front',
        ];
    }
    return array_slice($out, 0, 6);
}

function theretroweb_summary(array $specs): ?string
{
    $parts = [];
    foreach ($specs as $label => $values) {
        $parts[] = ucfirst($label) . ': ' . implode(', ', $values);
    }
    return $parts === [] ? null : mb_substr(implode("\n", $parts), 0, 600);
}

// ============================================================================
// Provider: Amiga Hardware Database  (amiga.resource.cx)
//
// The only real source for Amiga expansion hardware. There is no API, so this
// parses HTML - which I would normally avoid, except that for hardware the
// alternative is not a different source, it is typing everything by hand.
//
// Two things about the site shape the code:
//
//   - Pages are ISO-8859-1. Converting on the way in is not optional: this
//     database is utf8mb4 throughout precisely so that Umlaute and Swedish
//     characters survive, and a raw byte copy would defeat that.
//
//   - The markup is loose - unclosed <li>, no <html> element. DOMDocument with
//     its errors suppressed copes; anything strict will not.
//
// A search returns every matching revision with full detail inline, so one
// request covers Blizzard 1230 mk1 through mk4. Worth preserving: this is one
// person's site, not a funded API.
// ============================================================================

function metadata_search_amigahw(array $params, string $title, ?string $remotePlatform): array
{
    metadata_rate_limit('amigahw', (float) ($params['min_delay'] ?? 1.0));
    $url = rtrim((string) $params['endpoint'], '/') . '/search.pl?' . http_build_query(['product' => $title]);
    [$body, $err] = metadata_http_get($url, [], (int) $params['timeout']);
    if ($body === null) {
        return [[], 'Amiga Hardware Database: ' . $err];
    }
    return [amigahw_parse_search($body), null];
}

/** Kept separate from the request so it can be tested against a fixture. */
function amigahw_parse_search(string $html): array
{
    if (!class_exists('DOMDocument')) {
        error_log('[retrohive] the Amiga Hardware Database provider needs the PHP dom extension.');
        return [];
    }

    // Declared ISO-8859-1 in the document; convert before parsing so entities
    // and byte sequences are both interpreted once, in the right encoding.
    if (!mb_check_encoding($html, 'UTF-8') || stripos($html, 'iso-8859-1') !== false) {
        $converted = @mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        if (is_string($converted) && $converted !== '') {
            $html = $converted;
        }
        // The document still declares latin-1, and DOMDocument believes meta
        // tags: leaving it would convert the bytes a second time and turn every
        // umlaut into mojibake. Make the declaration match what the bytes now
        // actually are.
        $html = preg_replace('/charset\s*=\s*["\']?iso-8859-1["\']?/i', 'charset=utf-8', $html) ?? $html;
    }

    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $xp = new DOMXPath($doc);
    $results = [];

    foreach ($xp->query("//div[contains(@class,'expansion')]") as $node) {
        $link = $xp->query(".//div[contains(@class,'title')]//a", $node)->item(0);
        if ($link === null) {
            continue;
        }
        $name = trim($link->textContent);
        if ($name === '') {
            continue;
        }
        $href = $link->getAttribute('href');
        $slug = trim(basename($href));

        // The base data table is a row of cells, each holding a <div class="th">
        // label followed by its value. Reading them generically means a column
        // they add later arrives without a code change.
        $fields = [];
        foreach ($xp->query(".//table[contains(@class,'basedata')]//td", $node) as $cell) {
            $label = $xp->query(".//div[contains(@class,'th')]", $cell)->item(0);
            if ($label === null) {
                continue;
            }
            $key = strtolower(trim($label->textContent));
            // Everything in the cell except the label is the value.
            $value = trim(str_replace($label->textContent, '', $cell->textContent));
            $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
            if ($key !== '' && $value !== '') {
                $fields[$key] = $value;
            }
        }

        // The description is a run of <ul> blocks, each introduced by an <i>
        // naming the section: processor, memory, notes, or a feature such as
        // "optional Fast SCSI 2 DMA controller".
        $sections = [];
        foreach ($xp->query(".//div[contains(@class,'description')]//ul", $node) as $ul) {
            $heading = $xp->query('./i | ./I', $ul)->item(0);
            $key = $heading === null ? '' : strtolower(trim($heading->textContent));
            $lines = [];
            foreach ($xp->query('./li | ./LI', $ul) as $li) {
                $line = trim(preg_replace('/\s+/u', ' ', $li->textContent) ?? '');
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
            if ($lines !== []) {
                $sections[] = ['heading' => $key, 'lines' => $lines];
            }
        }

        $manufacturer = null;
        if (isset($fields['manufacturer'])) {
            // "Phase 5 Digital Products, Germany" - the country is not part of
            // the company name.
            $manufacturer = trim(explode(',', $fields['manufacturer'])[0]);
        }

        $results[] = [
            'remote_id'   => $slug,
            'title'       => $name,
            'year'        => isset($fields['date']) && preg_match('/(\d{4})/', $fields['date'], $m)
                             ? (int) $m[1] : null,
            'developer'   => $manufacturer,
            'publisher'   => null,
            'platform'    => 'Commodore Amiga',
            'platform_id' => null,
            'genre'       => null,
            'url'         => 'https://amiga.resource.cx/exp/' . $slug,
            'cover_url'   => null,
            'images'      => amigahw_images($xp, $node),
            'summary'     => amigahw_summary($sections),
            // Everything below lands on item_hardware rather than the entry core.
            'hardware'    => [
                'model'          => $name,
                'board_revision' => amigahw_revision($slug, $name),
                'interface'      => $fields['interface'] ?? null,
                'fits'           => $fields['amiga'] ?? null,
                'provides'       => amigahw_provides($sections),
                'cpu'            => amigahw_section($sections, 'processor'),
                'memory'         => amigahw_section($sections, 'memory'),
                'notes'          => amigahw_section($sections, 'notes'),
                'autoconfig_id'  => $fields['autoconfig id'] ?? null,
            ],
            'documents'   => amigahw_documents($xp, $node),
        ];
    }

    return $results;
}

/** Join one named section into a single line. */
function amigahw_section(array $sections, string $wanted): ?string
{
    foreach ($sections as $section) {
        if ($section['heading'] === $wanted) {
            return mb_substr(implode('; ', $section['lines']), 0, 250);
        }
    }
    return null;
}

/**
 * What the card adds, read from the section headings.
 *
 * "optional Fast SCSI 2 DMA controller" means it provides SCSI. Matching on the
 * heading rather than the body avoids picking up "supported by Linux" as a
 * feature.
 */
function amigahw_provides(array $sections): ?string
{
    $known = [
        'scsi' => 'SCSI', 'ide' => 'IDE', 'usb' => 'USB', 'ethernet' => 'Ethernet',
        'network' => 'Ethernet', 'serial' => 'Serial', 'parallel' => 'Parallel',
        'floppy' => 'Floppy', 'cd-rom' => 'CD-ROM', 'graphics' => 'Graphics',
        'sound' => 'Sound', 'audio' => 'Sound', 'modem' => 'Modem', 'isdn' => 'ISDN',
        'clock' => 'Real-time clock', 'fpu' => 'FPU',
    ];
    $found = [];
    foreach ($sections as $section) {
        foreach ($known as $needle => $label) {
            if ($section['heading'] !== '' && str_contains($section['heading'], $needle)) {
                $found[$label] = true;
            }
        }
    }
    return $found === [] ? null : implode(', ', array_keys($found));
}

/**
 * The revision, which for this hardware is not a detail.
 * Blizzard 1230 II and IV are different cards; the site encodes it as mk1..mk4
 * in the slug and as a roman numeral in the name.
 */
function amigahw_revision(string $slug, string $name): ?string
{
    if (preg_match('/mk(\d+)$/i', $slug, $m)) {
        return 'mk' . $m[1];
    }
    if (preg_match('/\b(I{1,3}V?|IV|V|VI{0,3})$/', trim($name), $m)) {
        return $m[1];
    }
    return null;
}

/** A readable paragraph from the whole description. */
function amigahw_summary(array $sections): ?string
{
    $parts = [];
    foreach ($sections as $section) {
        $head = $section['heading'] === '' ? '' : ucfirst($section['heading']) . ': ';
        $parts[] = $head . implode('; ', $section['lines']);
    }
    return $parts === [] ? null : mb_substr(implode("\n", $parts), 0, 600);
}

/** Photographs, offered for import like any other artwork. */
function amigahw_images(DOMXPath $xp, DOMNode $node): array
{
    $images = [];
    foreach ($xp->query(".//div[contains(@class,'photos')]//img", $node) as $img) {
        $src = $img->getAttribute('src');
        if ($src === '') {
            continue;
        }
        // Thumbnail to full size, which is two changes and this made neither.
        //
        //   photos/thumbnails/a2000arev4.png   the thumbnail
        //   photos/photos/a2000arev4.jpg       the full picture
        //
        // The directory is photos/photos, not photos - "one level up" was wrong -
        // and the extension changes as well, so every derived address was a PNG
        // that is not there. Both ends 404'd, which is what the broken-image
        // squares on the review screen were.
        $base = 'https://amiga.resource.cx/';
        $thumb = $base . ltrim(str_replace('../', '', $src), '/');
        $full  = preg_replace('#/thumbnails/#', '/photos/', $thumb);
        $full  = preg_replace('/\.(png|gif)$/i', '.jpg', (string) $full);

        // What the picture is of, from the site's own caption.
        //
        // "Rev 4.0 german motherboard, front side" is a better label than "Box
        // front", and these are not boxes: the Amiga Hardware Database photographs
        // boards. The kind stays a box_front/box_back because that is the
        // vocabulary the image table has, but the caption is the source's.
        $caption = trim(preg_replace('/\s+/', ' ', (string) $img->getAttribute('alt')));

        $images[] = [
            'url'  => $full,
            'caption' => $caption === '' ? null : $caption,
            // Kept, and used. The derivation above is a rule inferred from one
            // pair of addresses; where it is wrong the thumbnail is still a real
            // picture, and a small correct image beats a large missing one.
            'thumb_url' => $thumb,
            // 'unit', not a box: these are photographs of boards. The kind
            // vocabulary had nothing else to call them until now, so a
            // motherboard went into the catalogue as a box front.
            'kind' => 'unit',
        ];
    }
    return $images;
}

/**
 * Manuals and driver disks. Not imported anywhere yet, but recorded on the
 * candidate so the review screen can show that they exist - for a card you are
 * about to buy, knowing the manual is archived is worth something.
 */
function amigahw_documents(DOMXPath $xp, DOMNode $node): array
{
    $docs = [];
    foreach ($xp->query(".//div[contains(@class,'resourcepart')]//a[@href]", $node) as $a) {
        $href = $a->getAttribute('href');
        if (!preg_match('/\.(pdf|lha|dms|adf|zip)$/i', $href)) {
            continue;
        }
        $docs[] = [
            'name' => trim($a->textContent),
            'url'  => 'https://amiga.resource.cx/' . ltrim(str_replace('../', '', $href), '/'),
        ];
    }
    return $docs;
}

// ============================================================================
// Provider: CSDb  (free, no key, excellent for the C64 scene)
// ============================================================================

function metadata_search_csdb(array $params, string $title, ?string $remotePlatform): array
{
    $url = rtrim((string) $params['endpoint'], '/')
         . '/?type=search&search=' . rawurlencode($title) . '&subtype=release';

    [$body, $err] = metadata_http_get($url, [], (int) $params['timeout']);
    if ($body === null) {
        return [[], 'CSDb: ' . $err];
    }
    return [csdb_parse_search($body), null];
}

/** Kept separate from the request so it can be tested against fixtures. */
function csdb_parse_search(string $xml): array
{
    // CSDb is the only provider that speaks XML, and simplexml is a separate
    // package on most distributions. Missing it should disable this one source,
    // not take the page down.
    if (!function_exists('simplexml_load_string')) {
        error_log('[retrohive] CSDb lookup needs the PHP simplexml extension (php8-xml).');
        return [];
    }

    $prev = libxml_use_internal_errors(true);
    $doc  = simplexml_load_string($xml);
    libxml_use_internal_errors($prev);

    if ($doc === false) {
        return [];
    }

    $results = [];
    foreach ($doc->xpath('//Release') ?: [] as $rel) {
        $id = (string) ($rel->ID ?? '');
        if ($id === '') {
            continue;
        }
        $year = (string) ($rel->ReleaseYear ?? '');

        $group = '';
        if (isset($rel->ReleasedBy->Group->Name)) {
            $group = (string) $rel->ReleasedBy->Group->Name;
        } elseif (isset($rel->ReleasedBy->Handle->Handle)) {
            $group = (string) $rel->ReleasedBy->Handle->Handle;
        }

        $results[] = [
            'remote_id' => $id,
            'title'     => trim((string) ($rel->Name ?? '')),
            'year'      => $year !== '' ? (int) $year : null,
            'developer' => $group !== '' ? $group : null,
            'publisher' => null,
            'platform'  => 'C64',
            'genre'     => trim((string) ($rel->Type ?? '')) ?: null,
            'url'       => 'https://csdb.dk/release/?id=' . $id,
            'cover_url' => null,
            'summary'   => null,
        ];
    }
    return $results;
}

// ============================================================================
// Provider: Wikidata  (free, no key, broad but shallow)
// ============================================================================

/**
 * Wikidata, in two steps rather than one.
 *
 * This used to be a single SPARQL query that walked every video game on Wikidata
 * and ran CONTAINS(LCASE(?label), ...) over the lot. That is a full scan of a
 * class with hundreds of thousands of members, and the public endpoint kills a
 * query long before it finishes - which is why the test came back "Operation
 * timed out after 15002 milliseconds with 0 bytes received" every time, on any
 * title. Raising the timeout would not have fixed it; the query was never going
 * to return.
 *
 * So: ask the search API for candidates by name, which is what it is for and
 * what it is fast at, then ask SPARQL about those specific entities with a
 * VALUES clause. The second query has a handful of bound subjects instead of an
 * unbounded scan, and comes back in well under a second.
 */
function metadata_search_wikidata(array $params, string $title, ?string $remotePlatform): array
{
    $lang    = preg_replace('/[^a-z-]/', '', (string) ($params['language'] ?? 'en')) ?: 'en';
    $timeout = (int) ($params['timeout'] ?? 15);

    // Step one: candidates by label.
    $searchUrl = 'https://www.wikidata.org/w/api.php?action=wbsearchentities'
        . '&search=' . rawurlencode($title)
        . '&language=' . rawurlencode($lang)
        . '&uselang=' . rawurlencode($lang)
        . '&type=item&limit=20&format=json&formatversion=2';
    [$searchBody, $err] = metadata_http_get($searchUrl, ['Accept: application/json'], $timeout);
    if ($searchBody === null) {
        return [[], 'Wikidata: ' . $err];
    }
    $found = json_decode($searchBody, true);
    if (!is_array($found) || !isset($found['search']) || !is_array($found['search'])) {
        return [[], 'Wikidata: the search API returned something unreadable.'];
    }

    $ids = [];
    foreach ($found['search'] as $hit) {
        if (isset($hit['id']) && preg_match('/^Q\d+$/', (string) $hit['id'])) {
            $ids[] = (string) $hit['id'];
        }
    }
    if ($ids === []) {
        // Not an error. Nothing matched, which is a legitimate answer and reads
        // very differently from a failure in the log.
        return [[], null];
    }

    // Step two: what those entities are, restricted to the ones just found.
    $values = 'wd:' . implode(' wd:', array_slice($ids, 0, 20));
    $platformClause = '';
    if ($remotePlatform !== null && preg_match('/^Q\d+$/', $remotePlatform)) {
        $platformClause = "?game wdt:P400 wd:$remotePlatform .";
    }

    $sparql = <<<SPARQL
SELECT ?game ?gameLabel ?year ?devLabel ?pubLabel WHERE {
  VALUES ?game { $values }
  ?game wdt:P31/wdt:P279* wd:Q7889 .
  $platformClause
  OPTIONAL { ?game wdt:P577 ?date . BIND(YEAR(?date) AS ?year) }
  OPTIONAL { ?game wdt:P178 ?dev . }
  OPTIONAL { ?game wdt:P123 ?pub . }
  SERVICE wikibase:label { bd:serviceParam wikibase:language "$lang,en". }
}
LIMIT 20
SPARQL;

    $url = rtrim((string) $params['endpoint'], '/') . '?format=json&query=' . rawurlencode($sparql);
    [$body, $err] = metadata_http_get($url, ['Accept: application/sparql-results+json'], $timeout);
    if ($body === null) {
        return [[], 'Wikidata: ' . $err];
    }
    return [wikidata_parse_search($body), null];
}

function wikidata_parse_search(string $json): array
{
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['results']['bindings'])) {
        return [];
    }

    $byId = [];
    foreach ($data['results']['bindings'] as $row) {
        $uri = $row['game']['value'] ?? '';
        $id  = $uri === '' ? '' : basename($uri);
        if ($id === '') {
            continue;
        }
        // The same game repeats once per developer/publisher combination.
        if (!isset($byId[$id])) {
            $byId[$id] = [
                'remote_id' => $id,
                'title'     => $row['gameLabel']['value'] ?? '',
                'year'      => isset($row['year']['value']) ? (int) $row['year']['value'] : null,
                'developer' => $row['devLabel']['value'] ?? null,
                'publisher' => $row['pubLabel']['value'] ?? null,
                'platform'  => null,
                'genre'     => null,
                'url'       => 'https://www.wikidata.org/wiki/' . $id,
                'cover_url' => null,
                'summary'   => null,
            ];
        } else {
            if (empty($byId[$id]['developer']) && isset($row['devLabel']['value'])) {
                $byId[$id]['developer'] = $row['devLabel']['value'];
            }
            if (empty($byId[$id]['publisher']) && isset($row['pubLabel']['value'])) {
                $byId[$id]['publisher'] = $row['pubLabel']['value'];
            }
        }
    }
    return array_values($byId);
}

// ============================================================================
// Provider: MobyGames  (free key, best coverage for Amiga/ST/DOS)
// ============================================================================

function metadata_search_mobygames(array $params, string $title, ?string $remotePlatform): array
{
    $key = trim((string) ($params['api_key'] ?? ''));
    if ($key === '') {
        return [[], 'MobyGames: no API key configured.'];
    }

    $query = [
        'api_key' => $key,
        'title'   => $title,
        'format'  => 'normal',
        'limit'   => 15,
    ];
    if ($remotePlatform !== null && $remotePlatform !== '') {
        $query['platform'] = $remotePlatform;
    }

    $url = rtrim((string) $params['endpoint'], '/') . '/games?' . http_build_query($query);
    [$body, $err] = metadata_http_get($url, ['Accept: application/json'], (int) $params['timeout']);
    if ($body === null) {
        return [[], 'MobyGames: ' . $err];
    }
    return [mobygames_parse_search($body), null];
}

function mobygames_parse_search(string $json): array
{
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['games']) || !is_array($data['games'])) {
        return [];
    }

    $results = [];
    foreach ($data['games'] as $game) {
        $year = null;
        $platformName = null;
        foreach ($game['platforms'] ?? [] as $p) {
            if (!empty($p['first_release_date'])) {
                $year = (int) substr((string) $p['first_release_date'], 0, 4);
            }
            $platformName = $p['platform_name'] ?? $platformName;
            break;   // the first entry is the closest match to what was asked for
        }

        $genre = null;
        foreach ($game['genres'] ?? [] as $g) {
            if (($g['genre_category'] ?? '') === 'Basic Genres') {
                $genre = $g['genre_name'] ?? null;
                break;
            }
        }

        $results[] = [
            'remote_id' => (string) ($game['game_id'] ?? ''),
            'title'     => (string) ($game['title'] ?? ''),
            'year'      => $year,
            // The search endpoint does not carry credits; the detail fetch does.
            'developer' => null,
            'publisher' => null,
            'platform'  => $platformName,
            'genre'     => $genre,
            'url'       => (string) ($game['moby_url'] ?? ''),
            'cover_url' => $game['sample_cover']['image'] ?? null,
            'summary'   => isset($game['description'])
                ? trim(mb_substr(strip_tags((string) $game['description']), 0, 600))
                : null,
        ];
    }
    return $results;
}

// ============================================================================
// Provider: TheGamesDB  (free key, console box art)
// ============================================================================

function metadata_search_thegamesdb(array $params, string $title, ?string $remotePlatform): array
{
    $key = trim((string) ($params['api_key'] ?? ''));
    if ($key === '') {
        return [[], 'TheGamesDB: no API key configured.'];
    }

    $query = [
        'apikey'  => $key,
        'name'    => $title,
        // developers and publishers have to be asked for by name or they are
        // simply absent from the response.
        'fields'  => 'players,publishers,developers,genres,overview,platform,rating,coop,youtube',
        // Without this the response carries no artwork at all.
        'include' => 'boxart,platform',
    ];
    if ($remotePlatform !== null && $remotePlatform !== '') {
        $query['filter[platform]'] = $remotePlatform;
    }

    $url = rtrim((string) $params['endpoint'], '/') . '/Games/ByGameName?' . http_build_query($query);
    [$body, $err] = metadata_http_get($url, ['Accept: application/json'], (int) $params['timeout']);
    if ($body === null) {
        return [[], 'TheGamesDB: ' . $err];
    }

    $results = thegamesdb_parse_search($body);

    // Credits come back as bare ids, so they mean nothing until they are looked
    // up. Only pay for the lookup tables when something actually needs them.
    $needsLookup = false;
    foreach ($results as $r) {
        if (!empty($r['developer_ids']) || !empty($r['publisher_ids']) || !empty($r['genre_ids'])
            // A platform id with no name is the other reason to go and look.
            || ($r['platform'] === null && $r['platform_id'] !== null)) {
            $needsLookup = true;
            break;
        }
    }
    if ($needsLookup) {
        $tables = thegamesdb_lookup_tables($params);
        foreach ($results as &$r) {
            $r['developer'] = thegamesdb_first_name($r['developer_ids'] ?? [], $tables['developers'] ?? []);
            $r['publisher'] = thegamesdb_first_name($r['publisher_ids'] ?? [], $tables['publishers'] ?? []);
            $r['genre']     = thegamesdb_first_name($r['genre_ids'] ?? [], $tables['genres'] ?? []);
            if ($r['platform'] === null && $r['platform_id'] !== null) {
                $r['platform'] = thegamesdb_first_name([$r['platform_id']], $tables['platforms'] ?? []);
            }
            unset($r['developer_ids'], $r['publisher_ids'], $r['genre_ids']);
        }
        unset($r);
    }

    return [$results, null];
}

function thegamesdb_parse_search(string $json): array
{
    $data = json_decode($json, true);
    $games = $data['data']['games'] ?? null;
    if (!is_array($games)) {
        return [];
    }

    // Artwork and platform names arrive in a separate "include" block, keyed by
    // game id, rather than on the game records themselves.
    $boxBase   = $data['include']['boxart']['base_url']['original'] ?? '';
    $thumbBase = $data['include']['boxart']['base_url']['thumb'] ?? $boxBase;
    $boxData   = $data['include']['boxart']['data'] ?? [];
    $platforms = $data['include']['platform']['data'] ?? [];

    $results = [];
    foreach ($games as $game) {
        $id      = (string) ($game['id'] ?? '');
        $release = (string) ($game['release_date'] ?? '');

        $front = null;
        $back  = null;
        foreach ($boxData[$id] ?? [] as $art) {
            if (($art['type'] ?? '') !== 'boxart' || empty($art['filename'])) {
                continue;
            }
            $url = rtrim((string) $boxBase, '/') . '/' . ltrim((string) $art['filename'], '/');
            if (($art['side'] ?? '') === 'front' && $front === null) {
                $front = $url;
            } elseif (($art['side'] ?? '') === 'back' && $back === null) {
                $back = $url;
            }
        }

        // The platform arrives as an id that has to be looked up in a separate
        // include block, and the shape of that block varies. Try each form
        // rather than silently ending up with "platform not stated", which
        // leaves the ranking nothing to work with.
        $platformId   = $game['platform'] ?? null;
        $platformName = null;
        if ($platformId !== null) {
            $key = (string) $platformId;
            if (isset($platforms[$key]['name'])) {
                $platformName = (string) $platforms[$key]['name'];
            } elseif (isset($platforms[$key]) && is_string($platforms[$key])) {
                $platformName = $platforms[$key];
            } elseif (!is_numeric($platformId)) {
                $platformName = (string) $platformId;   // already a name
            }
        }
        if ($platformName === null && isset($game['platform_name'])) {
            $platformName = (string) $game['platform_name'];
        }

        // Artwork the review screen can offer to import, in a shape shared by
        // every provider.
        $images = [];
        if ($front !== null) { $images[] = ['url' => $front, 'kind' => 'box_front']; }
        if ($back !== null)  { $images[] = ['url' => $back,  'kind' => 'box_back']; }

        $results[] = [
            'remote_id'     => $id,
            'title'         => (string) ($game['game_title'] ?? ''),
            'year'          => $release !== '' ? (int) substr($release, 0, 4) : null,
            // A full date is worth keeping; a bare year loses information.
            'release_date'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', $release) === 1 ? $release : null,
            'developer'     => null,
            'publisher'     => null,
            'platform'      => $platformName,
            'platform_id'   => $platformId === null ? null : (string) $platformId,
            'genre'         => null,
            // Resolved against the lookup tables once, not per result.
            'developer_ids' => $game['developers'] ?? [],
            'publisher_ids' => $game['publishers'] ?? [],
            'genre_ids'     => $game['genres'] ?? [],
            'players'       => isset($game['players']) ? (int) $game['players'] : null,
            'url'           => 'https://thegamesdb.net/game.php?id=' . $id,
            'cover_url'     => $front,
            'images'        => $images,
            'summary'       => isset($game['overview'])
                ? trim(mb_substr(strip_tags((string) $game['overview']), 0, 600))
                : null,
        ];
    }
    return $results;
}

/**
 * TheGamesDB returns developers, publishers and genres as bare id arrays, so
 * without these lookup tables every result comes back with empty credits.
 *
 * The tables are large but almost static, so they are cached for a month: one
 * extra request the first time, none after that.
 */
function thegamesdb_lookup_tables(array $params): array
{
    $key    = metadata_cache_key('thegamesdb', 'lookups', []);
    $cached = metadata_cache_get($key);
    if ($cached !== null) {
        return $cached;
    }

    $apiKey = trim((string) ($params['api_key'] ?? ''));
    if ($apiKey === '') {
        return ['developers' => [], 'publishers' => [], 'genres' => [], 'platforms' => []];
    }

    // Platforms among them.
    //
    // The platform arrives on a game as a bare id and the name is supposed to come
    // back in the response's `include` block. It does not always - every result in
    // a real search came back with the platform column empty while the studios
    // resolved fine - and a result that does not say which machine it is for is
    // most of what makes a candidate identifiable. Asking for the table outright
    // is one request, cached for a month, and does not depend on the include block
    // being there.
    $tables = [];
    foreach (['Developers' => 'developers', 'Publishers' => 'publishers',
              'Genres' => 'genres', 'Platforms' => 'platforms'] as $endpoint => $slot) {
        $url = rtrim((string) $params['endpoint'], '/') . '/' . $endpoint . '?' . http_build_query(['apikey' => $apiKey]);
        [$body, $err] = metadata_http_get($url, ['Accept: application/json'], (int) $params['timeout']);
        $tables[$slot] = $body === null ? [] : thegamesdb_parse_lookup($body, strtolower($endpoint));
    }

    // Only worth caching once something actually came back.
    if (array_filter($tables) !== []) {
        metadata_cache_put('thegamesdb', $key, $tables, 24 * 30);
    }
    return $tables;
}

/** Pull [id => name] out of a Developers/Publishers/Genres response. */
function thegamesdb_parse_lookup(string $json, string $slot): array
{
    $data = json_decode($json, true);
    $rows = $data['data'][$slot] ?? $data['data'] ?? [];
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $id => $row) {
        if (is_array($row) && isset($row['name'])) {
            $out[(string) ($row['id'] ?? $id)] = (string) $row['name'];
        } elseif (is_string($row)) {
            $out[(string) $id] = $row;
        }
    }
    return $out;
}

/** Resolve an array of ids against a lookup table, returning the first name. */
function thegamesdb_first_name($ids, array $table): ?string
{
    if (!is_array($ids)) {
        $ids = [$ids];
    }
    foreach ($ids as $id) {
        $name = $table[(string) $id] ?? null;
        if ($name !== null && $name !== '') {
            return $name;
        }
    }
    return null;
}

/**
 * The remote platform list, for mapping our libraries onto theirs.
 * Returns [[id => name], error].
 */
function thegamesdb_platforms(array $params): array
{
    $key = trim((string) ($params['api_key'] ?? ''));
    if ($key === '') {
        return [[], 'TheGamesDB: no API key configured.'];
    }
    $url = rtrim((string) $params['endpoint'], '/') . '/Platforms?' . http_build_query(['apikey' => $key]);
    [$body, $err] = metadata_http_get($url, ['Accept: application/json'], (int) $params['timeout']);
    if ($body === null) {
        return [[], 'TheGamesDB: ' . $err];
    }
    $data = json_decode($body, true);
    $rows = $data['data']['platforms'] ?? [];
    $out  = [];
    foreach ($rows as $row) {
        if (isset($row['id'], $row['name'])) {
            $out[(string) $row['id']] = (string) $row['name'];
        }
    }
    return [$out, null];
}

/**
 * The word most likely to identify a part.
 *
 * The longest one that is not a bare number: in "3dfx Voodoo2 12MB" that is
 * Voodoo2, which is what somebody would type into their own search box.
 */
function theretroweb_best_word(string $title): ?string
{
    $best = null;
    foreach (preg_split('/[^A-Za-z0-9]+/', trim($title)) ?: [] as $w) {
        if ($w === '' || ctype_digit($w) || mb_strlen($w) < 4) {
            continue;
        }
        if ($best === null || mb_strlen($w) > mb_strlen($best)) {
            $best = $w;
        }
    }
    return $best;
}

/**
 * Their listing address, as it appears in the address bar.
 *
 * itemsPerPage matters: the default page size is small enough that a search
 * looked like it had matched nothing.
 */
function theretroweb_search_url(string $base, string $section, string $title, ?int $manufacturerId = null): string
{
    $q = ['itemsPerPage' => 100, 'page' => 1, 'name' => trim($title)];
    if ($manufacturerId !== null) {
        $q = ['manufacturerId' => $manufacturerId] + $q;
    }
    return rtrim($base, '/') . '/' . $section . '?' . http_build_query($q);
}

/**
 * The maker's id on their site.
 *
 * Their /manufacturers page lists makers as a link to /manufacturers/{id} with
 * the name beside it. The search form's own dropdown is built in the browser
 * and its contents are fetched separately - which is why looking there found
 * nothing - but the page behind it is plain HTML and always was.
 *
 * The landing page carries the popular makers; anything else needs asking for
 * by name. Both are read the same way.
 */
function theretroweb_manufacturer_id(string $configured, string $title, ?string $base = null, int $timeout = 15): ?int
{
    // A configured list wins: somebody has looked it up and is sure.
    $fromConfig = theretroweb_configured_maker($configured, $title);
    if ($fromConfig !== null || $base === null) {
        return $fromConfig;
    }

    static $cache = [];
    $first = theretroweb_leading_word($title);
    if ($first === null) {
        return null;
    }
    if (array_key_exists($first, $cache)) {
        return $cache[$first];
    }

    $found = null;
    foreach ([rtrim($base, '/') . '/manufacturers?name=' . rawurlencode($first),
              rtrim($base, '/') . '/manufacturers'] as $url) {
        [$body, ] = metadata_http_get($url, [], $timeout);
        if ($body === null) {
            continue;
        }
        $makers = theretroweb_parse_manufacturers($body);
        $found = theretroweb_best_maker_match($makers, $title);
        if ($found !== null) {
            break;
        }
    }

    $cache[$first] = $found;
    return $found;
}

/** A maker id from a list somebody typed into the source's settings. */
function theretroweb_configured_maker(string $configured, string $title): ?int
{
    $configured = trim($configured);
    if ($configured === '') {
        return null;
    }
    $map = [];
    foreach (preg_split('/[,\n]/', $configured) ?: [] as $pair) {
        $bits = explode('=', $pair, 2);
        if (count($bits) === 2 && trim($bits[0]) !== '' && (int) trim($bits[1]) > 0) {
            $map[trim($bits[0])] = (int) trim($bits[1]);
        }
    }
    return theretroweb_best_maker_match($map, $title);
}

/** The first word worth searching a maker by. */
function theretroweb_leading_word(string $title): ?string
{
    foreach (preg_split('/[^A-Za-z0-9]+/', trim($title)) ?: [] as $w) {
        if ($w !== '' && !ctype_digit($w)) {
            return $w;
        }
    }
    return null;
}

/**
 * The longest maker name the title starts with.
 *
 * Longest wins so "3Com EtherLink" prefers 3Com over a maker that happens to
 * share its first characters, and a maker merely mentioned later is ignored -
 * "EtherLink by 3Com" is a card called EtherLink, not a search for 3Com.
 */
function theretroweb_best_maker_match(array $makers, string $title): ?int
{
    $needle = strtolower(trim($title));
    $best = null;
    $bestLen = 0;
    foreach ($makers as $name => $id) {
        $n = strtolower(trim((string) $name));
        if ($n !== '' && str_starts_with($needle, $n) && strlen($n) > $bestLen) {
            $best = (int) $id;
            $bestLen = strlen($n);
        }
    }
    return $best;
}

/**
 * Maker names and ids from their manufacturers page.
 *
 * Written against the real markup: a link to /manufacturers/{id} wrapping a
 * div of class perk-name. Two earlier attempts guessed at a select and at
 * query-string links; both were wrong because the list was never on the page
 * I was reading.
 */
function theretroweb_parse_manufacturers(string $html): array
{
    $out = [];
    preg_match_all(
        '#href="/manufacturers/(\d+)"[\s\S]{0,900}?<div class="perk-name">\s*([^<]+?)\s*</div>#i',
        $html, $m, PREG_SET_ORDER
    );
    foreach ($m as $one) {
        $name = trim(html_entity_decode($one[2]));
        if ($name !== '' && !isset($out[$name])) {
            $out[$name] = (int) $one[1];
        }
    }
    return $out;
}

/**
 * Put an exact name match at the front, and mark it.
 *
 * Compared loosely on purpose: "3c900-combo" and "3Com 3C900-COMBO" are the
 * same card written two ways, and somebody typing a part number should not have
 * to reproduce the maker's capitalisation and spacing.
 */
function theretroweb_exact_first(array $results, string $query): array
{
    $flatten = static fn(string $s): string => strtolower(preg_replace('/[^a-z0-9]+/i', '', $s) ?? '');
    $needle = $flatten($query);
    if ($needle === '') {
        return $results;
    }

    $exact = [];
    $rest  = [];
    foreach ($results as $r) {
        $title = $flatten((string) ($r['title'] ?? ''));
        // An exact match, or the query is the whole of the name bar the maker.
        if ($title === $needle || str_ends_with($title, $needle)) {
            $exact[] = $r + ['exact' => true];
        } else {
            $rest[] = $r;
        }
    }
    return array_merge($exact, $rest);
}

/**
 * One request per source per interval.
 *
 * `min_delay` has been configured on three sources since they were added and
 * nothing ever read it, so the politeness it describes was a comment. Two of the
 * three are somebody's hobby server.
 *
 * Per process rather than per install: this runs from a web request, so the
 * worst case is a handful of concurrent lookups rather than a crawl, and a
 * shared lock across processes would want a table and a cleanup job to enforce
 * a courtesy.
 */
function metadata_rate_limit(string $key, float $seconds): void
{
    if ($seconds <= 0) {
        return;
    }

    // Remembered across requests, not only within one.
    //
    // This was a static array, which lives as long as the PHP process handling
    // one page - so a lookup spaced its own calls politely and then the next
    // press of Search, a second later in a fresh worker, started again from
    // nothing. Wikipedia sees the burst, not the spacing, and answers 429.
    //
    // The row is written before the wait as well as after: two workers arriving
    // together should not both read the old time and both decide they may go now.
    static $last = [];
    $now = microtime(true);

    $held = $last[$key] ?? null;
    if ($held === null) {
        $stored = scalar('SELECT value FROM settings WHERE name = ?', ['ratelimit.' . $key]);
        $held   = $stored === null ? 0.0 : (float) $stored;
    }

    $wait = $held + $seconds - $now;
    // Capped: a clock that jumped, or a stored time from the future, should cost
    // a moment rather than hanging the page until it catches up.
    $wait = min($wait, max(5.0, $seconds * 3));
    if ($wait > 0) {
        usleep((int) round($wait * 1_000_000));
    }

    $stamp       = microtime(true);
    $last[$key]  = $stamp;
    q('INSERT INTO settings (name, value) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE value = VALUES(value)',
      ['ratelimit.' . $key, (string) $stamp]);
}

// --- IGDB -------------------------------------------------------------------

/**
 * A token, fetched and reused.
 *
 * IGDB does not take a static key. The Twitch client id and secret are exchanged
 * for a bearer token that lasts about two months, so fetching one per search
 * would be both slow and rude. Held in the session for the life of it, which is
 * the right lifetime here: this runs from an admin screen or an item form, not
 * from a daemon.
 *
 * @return array{0:?string,1:?string} token, error
 */
function igdb_token(array $params): array
{
    $clientId = trim((string) ($params['client_id'] ?? ''));
    $secret   = trim((string) ($params['api_key'] ?? ''));
    if ($clientId === '' || $secret === '') {
        return [null, 'IGDB needs a Twitch client id and secret. Both come from '
                    . 'one free application at dev.twitch.tv.'];
    }

    $cacheKey = 'igdb_token_' . substr(hash('sha256', $clientId), 0, 16);
    if (isset($_SESSION[$cacheKey]) && ($_SESSION[$cacheKey]['expires'] ?? 0) > time() + 60) {
        return [(string) $_SESSION[$cacheKey]['token'], null];
    }

    $url = rtrim((string) ($params['token_url'] ?? 'https://id.twitch.tv/oauth2/token'), '/');
    [$body, $err] = metadata_http_get(
        $url,
        ['Content-Type: application/x-www-form-urlencoded'],
        (int) ($params['timeout'] ?? 15),
        http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $secret,
            'grant_type'    => 'client_credentials',
        ])
    );
    if ($body === null) {
        return [null, 'IGDB sign-in: ' . $err];
    }

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['access_token'])) {
        // The message rather than the raw body: a wrong secret comes back as
        // {"status":403,"message":"invalid client secret"}, which is worth
        // saying plainly instead of pasting JSON at somebody.
        $why = is_array($data) && !empty($data['message'])
            ? (string) $data['message']
            : 'no token in the reply';
        return [null, 'IGDB sign-in refused: ' . $why];
    }

    $_SESSION[$cacheKey] = [
        'token'   => (string) $data['access_token'],
        'expires' => time() + max(60, (int) ($data['expires_in'] ?? 3600)),
    ];
    return [(string) $data['access_token'], null];
}

function metadata_search_igdb(array $params, string $title, ?string $remotePlatform): array
{
    [$token, $err] = igdb_token($params);
    if ($token === null) {
        return [[], $err];
    }

    // APIcalypse, which is IGDB's own query language and goes in the body.
    $safe  = str_replace(['"', "\n", "\r"], ['\\"', ' ', ' '], $title);
    $where = ($remotePlatform !== null && ctype_digit($remotePlatform))
        ? "where platforms = ($remotePlatform);"
        : '';
    $query = 'search "' . $safe . '"; '
           . 'fields name, first_release_date, summary, url, '
           . 'involved_companies.company.name, involved_companies.developer, '
           . 'involved_companies.publisher, platforms.name; '
           . $where
           . ' limit 20;';

    [$body, $err] = metadata_http_get(
        rtrim((string) ($params['endpoint'] ?? 'https://api.igdb.com/v4'), '/') . '/games',
        [
            'Client-ID: ' . trim((string) ($params['client_id'] ?? '')),
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        (int) ($params['timeout'] ?? 15),
        $query
    );
    if ($body === null) {
        return [[], 'IGDB: ' . $err];
    }
    return [igdb_parse_search($body), null];
}

/** @return list<array<string,mixed>> */
function igdb_parse_search(string $json): array
{
    $rows = json_decode($json, true);
    if (!is_array($rows)) {
        return [];
    }

    $out = [];
    foreach ($rows as $g) {
        if (!is_array($g) || empty($g['name'])) {
            continue;
        }

        // IGDB reports both roles from one list, and a company can be either or
        // both, so they are read rather than assumed from position.
        $dev = $pub = null;
        foreach ((array) ($g['involved_companies'] ?? []) as $ic) {
            $name = $ic['company']['name'] ?? null;
            if ($name === null) {
                continue;
            }
            if (!empty($ic['developer']) && $dev === null) {
                $dev = (string) $name;
            }
            if (!empty($ic['publisher']) && $pub === null) {
                $pub = (string) $name;
            }
        }

        $platforms = [];
        foreach ((array) ($g['platforms'] ?? []) as $pl) {
            if (!empty($pl['name'])) {
                $platforms[] = (string) $pl['name'];
            }
        }

        $out[] = [
            'title'     => (string) $g['name'],
            // A unix timestamp, and absent for anything unreleased.
            'year'      => empty($g['first_release_date'])
                ? null
                : (int) gmdate('Y', (int) $g['first_release_date']),
            'developer' => $dev,
            'publisher' => $pub,
            'platform'  => $platforms === [] ? null : implode(', ', array_slice($platforms, 0, 4)),
            'summary'   => isset($g['summary']) ? (string) $g['summary'] : null,
            'url'       => isset($g['url']) ? (string) $g['url'] : null,
            'source'    => 'IGDB',
        ];
    }
    return $out;
}

// --- OpenRetro --------------------------------------------------------------

/**
 * OpenRetro, read from its alphabetical index.
 *
 * Three attempts at this, and the first two were guesses. The record, because it
 * is the useful part:
 *
 * - Its /api/ is a sync feed for FS-UAE Launcher - auth, game-sync, ratings -
 *   not a title search. Confirmed.
 * - /browse?q= and /browse?search= are both ignored. The second returns the whole
 *   letter listing, which parses fine and looks like a working search returning
 *   everything, which is worse than an error.
 *
 * What the site does have is a stable alphabetical index: /browse/<letter>, and
 * /browse/<letter>/<platform> to narrow it. So the first letter of what is being
 * looked for selects the page, and the matching happens here. Slower than a query
 * endpoint and it is what exists.
 *
 * Scoped to a platform whenever one is mapped, because /browse/t is roughly a
 * megabyte and /browse/t/amiga is a fraction of it.
 */
function metadata_search_openretro(array $params, string $title, ?string $remotePlatform): array
{
    metadata_rate_limit('openretro', (float) ($params['min_delay'] ?? 1.0));

    $needle = trim($title);
    if ($needle === '') {
        return [[], null];
    }

    // The index is keyed by first letter, with digits under 0.
    $first  = mb_strtolower(mb_substr($needle, 0, 1));
    $letter = preg_match('/[a-z]/', $first) === 1 ? $first : '0';

    // /browse/<platform>/<letter> - platform first.
    //
    // This was built the other way round, which is the fourth thing about this
    // site I got wrong by reasoning about it instead of looking. It only shows
    // when a platform is mapped: with none, the URL is /browse/all/<letter> and
    // that is the shape the lookup box on the agents page has been exercising all
    // along, which is why it appeared to work.
    //
    // With no machine in mind the segment is 'platform', not 'all'.
    //
    // "All Platforms" links to /browse/all/a from a platform's own page, and I
    // took that to mean /browse/all/<letter> works for every letter. It does not:
    // /browse/all/t comes back a few kilobytes long with no games on it. The page
    // that really does list every machine is /browse/platform, and its own letter
    // strip goes to /browse/platform/a … /browse/platform/t - so that is the
    // shape, taken from the site's navigation rather than from a guess about it.
    $base     = rtrim((string) ($params['endpoint'] ?? 'https://openretro.org'), '/');
    $scoped   = $remotePlatform !== null && preg_match('/^[a-z0-9-]+$/', $remotePlatform) === 1;
    $platform = $scoped ? $remotePlatform : 'platform';
    $url = $base . '/browse/' . $platform . '/' . $letter;

    metadata_debug('openretro: letter', $letter);
    metadata_debug('openretro: url', $url);

    [$body, $err] = metadata_http_get($url, ['Accept: text/html'], (int) ($params['timeout'] ?? 20));
    if ($body === null) {
        return [[], 'OpenRetro: ' . $err];
    }
    $GLOBALS['__openretro_entries'] = 0;
    $rows = openretro_parse_listing($body, $base, $needle);

    // Year, studio and publisher are not on the index.
    //
    // The browse page carries a title and a machine and nothing else, which is why
    // those columns came back empty. They are on each game's own page, as a run of
    // labelled values - "Publisher:Rainbow Arts · Year:1992 · Developer:Factor 5" -
    // so the detail pages are fetched for the first few results.
    //
    // Only the first few, and only when asked. Each one is a request with the
    // rate limit in front of it, so twenty results would be twenty round trips
    // and a minute of waiting for rows most people will not look at.
    $enrich = (int) ($params['detail_pages'] ?? 5);
    foreach ($rows as $i => $row) {
        if ($i >= $enrich) {
            break;
        }
        $more = openretro_fetch_detail((string) $row['url'], $params);
        foreach ($more as $k => $v) {
            if ($v !== null && ($rows[$i][$k] ?? null) === null) {
                $rows[$i][$k] = $v;
            }
        }
    }
    if ($rows === [] && (int) ($GLOBALS['__openretro_entries'] ?? 0) === 0) {
        return [[], sprintf('OpenRetro answered from %s with %d bytes, but nothing on the '
            . 'page looked like a game entry — the layout has probably changed since this '
            . 'was written.', $url, strlen($body))];
    }
    return [$rows, null];
}

/**
 * Pull the games out of a browse page and keep the ones that match.
 *
 * Two link shapes, which is what the first version of this got wrong: entries are
 * either /game/<uuid> or /<platform>/<slug>, and the platform-scoped form is the
 * majority. Matching only the first found almost nothing and reported it as the
 * source knowing nothing.
 *
 * The platform name is the text after the link, not in it.
 *
 * @return list<array<string,mixed>>
 */
function openretro_parse_listing(string $html, string $base, string $needle): array
{
    if (trim($html) === '') {
        return [];
    }

    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $xpath = new DOMXPath($doc);
    $links = $xpath->query('//a[@href]');
    if ($links === false) {
        metadata_debug('openretro: parse', 'the document produced no links at all');
        return [];
    }
    metadata_debug('openretro: links on the page', $links->length);

    // Anything that is not a game page: the alphabet strip, the platform filters,
    // the navigation. Recognised by shape rather than by listing them, so a new
    // one does not become a result.
    $skip = '#^/?(browse|wishlist|support|documentation|changes|comments|reports|user|image|images)(/|$)#i';

    // The machine names, from the page's own filter list.
    //
    // Entries read "Turrican Amiga" - title then machine, with nothing between
    // them - so where the title has to be taken from that text, the machine has
    // to come off the end. Guessing at "the last word" would turn Super Turrican
    // into Super. The sidebar lists every machine this site knows, as
    // /browse/<letter>/<slug>, so the page says which words to strip.
    $machines = [];
    foreach ($xpath->query('//a[@href]') ?: [] as $f) {
        if (!$f instanceof DOMElement) {
            continue;
        }
        $fp = parse_url((string) $f->getAttribute('href'), PHP_URL_PATH) ?: '';
        // Same order as above: /browse/<platform>/<letter>. The machine name is
        // the link's text; the slug is in the URL.
        if (preg_match('#^/?browse/[a-z0-9-]+/[a-z0-9]$#i', $fp) === 1) {
            $name = trim(preg_replace('/\s+/', ' ', (string) $f->textContent));
            if ($name !== '') {
                $machines[] = $name;
            }
        }
    }
    // Longest first, or "Game Boy" would be stripped out of "Game Boy Advance".
    //
    // Values, not keys. These are names scraped off a page, and PHP turns a
    // numeric-looking array key into an int - so a machine called "2600" came
    // back from array_keys() as the integer 2600 and mb_strlen() threw a
    // TypeError, which is the crash on every OpenRetro lookup.
    //
    // Cast in the comparator as well: this list comes from a web page, and one
    // guarantee about its contents is worth two.
    $machines = array_values(array_unique($machines));
    usort($machines, fn($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));
    metadata_debug('openretro: machines named in the sidebar',
        $machines === [] ? 'none — the filter list was not recognised either'
                         : count($machines) . ' (' . implode(', ', array_slice($machines, 0, 6)) . '…)');

    $lower   = mb_strtolower($needle);
    $out     = [];
    $seen    = [];
    $entries = 0;
    foreach ($links as $a) {
        if (!$a instanceof DOMElement) {
            continue;
        }
        $href = (string) $a->getAttribute('href');
        $path = parse_url($href, PHP_URL_PATH) ?: $href;
        if ($path === '' || $path === '/' || preg_match($skip, $path) === 1) {
            continue;
        }
        // /game/<uuid> or /<platform>/<slug>. Two segments either way.
        if (preg_match('#^/?([a-z0-9-]+)/([a-z0-9-]+)$#i', $path, $m) !== 1) {
            continue;
        }
        if (isset($seen[$path])) {
            continue;
        }

        // The title, from wherever it turns out to be.
        //
        // Three places, tried in order, because the previous version picked one
        // and was wrong about it: the cover image's alt, the link's own text, and
        // the text that follows the link. A layout where the image sits outside
        // the anchor, or carries no alt, is not a layout where the source knows
        // nothing - but insisting on the alt reported it as exactly that.
        $alt = '';
        $img = $xpath->query('.//img[@alt]', $a);
        if ($img !== false && $img->length > 0 && $img->item(0) instanceof DOMElement) {
            $alt = trim(preg_replace('/^Cover for\s*/i', '',
                (string) $img->item(0)->getAttribute('alt')));
        }
        if ($alt === '') {
            $alt = trim(preg_replace('/\s+/', ' ', (string) $a->textContent));
        }
        $fromTail = false;
        if ($alt === '') {
            for ($n = $a->nextSibling; $n !== null; $n = $n->nextSibling) {
                $t = trim(preg_replace('/\s+/', ' ', (string) $n->textContent));
                if ($t !== '') { $alt = $t; $fromTail = true; break; }
            }
        }
        // Taken from the tail, so it still has the machine on the end of it.
        if ($fromTail && $alt !== '') {
            foreach ($machines as $machine) {
                if (mb_strlen($alt) > mb_strlen($machine)
                    && str_ends_with($alt, ' ' . $machine)) {
                    $alt = trim(mb_substr($alt, 0, mb_strlen($alt) - mb_strlen($machine)));
                    break;
                }
            }
        }

        // Counted whether or not it matches, so an empty answer can tell "nothing
        // here is called that" from "nothing here looked like a game at all".
        $entries++;
        if ($entries <= 3) {
            // The first few, whatever they are. If the titles come out as
            // "Amiga" or empty, the layout is different from what is assumed
            // here and this is the line that shows it.
            metadata_debug('openretro: entry ' . $entries,
                ($alt === '' ? '(no title found)' : $alt) . '  <- ' . $path);
        }
        if ($alt === '') {
            continue;
        }
        if (!str_contains(mb_strtolower($alt), $lower)) {
            continue;
        }
        $seen[$path] = true;

        // What follows the link, up to the next one, names the machine.
        $platform = null;
        for ($n = $a->nextSibling; $n !== null; $n = $n->nextSibling) {
            $text = trim(preg_replace('/\s+/', ' ', (string) $n->textContent));
            if ($text === '') {
                continue;
            }
            // "Turrican II Amiga" - the title is repeated, so what is left is the
            // machine.
            $tail = trim(str_ireplace($alt, '', $text));
            $platform = $tail !== '' ? $tail : null;
            break;
        }
        if ($platform === null && $m[1] !== 'game') {
            $platform = $m[1];   // the slug in the path, where the text gave nothing
        }

        $out[] = [
            'title'     => $alt,
            'year'      => null,
            'developer' => null,
            'publisher' => null,
            'platform'  => $platform,
            'summary'   => null,
            'url'       => str_starts_with($href, 'http') ? $href : $base . '/' . ltrim($href, '/'),
            'source'    => 'OpenRetro',
        ];
        if (count($out) >= 25) {
            break;
        }
    }

    // Recorded for the caller, which can then say something true about an empty
    // answer. Two thousand games and no Turrican is a fact about Turrican; no
    // games at all is a fact about this parser, and telling somebody their source
    // is broken when the fault is here is the wrong way round.
    $GLOBALS['__openretro_entries'] = $entries;
    metadata_debug('openretro: game entries recognised', $entries);
    metadata_debug('openretro: matching "' . $needle . '"', count($out));
    return $out;
}


/**
 * The machines this instance actually holds things for.
 *
 * Taken from entries rather than from `platforms`: a library copies the whole
 * template list when it synchronises, so "platforms this library defines" is
 * sixty-three rows on every install and says nothing about anybody. What is
 * filed against them does.
 *
 * @return list<string> platform slugs, no duplicates
 */
function instance_platform_slugs(): array
{
    $rows = all('SELECT DISTINCT p.slug
                   FROM items i JOIN platforms p ON p.id = i.platform_id
                  WHERE i.platform_id IS NOT NULL
                  ORDER BY p.slug');
    return array_map(fn($r) => (string) $r['slug'], $rows);
}

/**
 * Is this source worth offering here?
 *
 * Only about `only_for`, which is the hard limit: a source that covers the PC
 * and nothing else has nothing to offer an instance with no PC entries. A source
 * without one is offered everywhere, because it claims to work everywhere.
 *
 * An empty instance is not a narrow one. With nothing catalogued yet there is
 * nothing to narrow by, and hiding four of six sources from somebody setting up
 * for the first time would be the worst possible moment to do it.
 */
function metadata_provider_relevant_here(string $type, array $instanceSlugs): bool
{
    if ($instanceSlugs === []) {
        return true;
    }
    $tested = metadata_provider_definition($type)['tested_with'] ?? null;
    if (!is_array($tested) || $tested === []) {
        return true;
    }
    return array_intersect($tested, $instanceSlugs) !== [];
}

/**
 * What a source calls each of our machines, from the templates.
 *
 * The other half of "linked to the template structure". `tested_with` says a
 * source knows about the Amiga; this says the Amiga is platform 16 to IGDB - and
 * without it every search is unfiltered, which is how a CD32 release comes back
 * first when you are cataloguing a floppy.
 *
 * It was per-install data with an automap button and nothing else, so a fresh
 * install searched unfiltered until somebody found the button and pressed it once
 * per source.
 *
 * Deliberately partial. A wrong remote id is worse than a missing one: it filters
 * a search to the wrong machine and returns a confident, useless answer, whereas a
 * missing one merely returns too much and says so. Only ids that could be stated
 * with confidence ship; automap asks the service for the rest.
 *
 * @return array<string,string> our platform slug => their id
 */
function metadata_template_platform_map(string $type): array
{
    // Populated by metadata_provider_types(), which is where the file is read.
    if (!isset($GLOBALS['__metadata_feed'])) {
        metadata_provider_types();
    }
    $map = $GLOBALS['__metadata_feed'][$type]['platform_map'] ?? null;
    return is_array($map) ? array_map('strval', $map) : [];
}

/**
 * Write those mappings for one provider.
 *
 * Never over an existing row. A mapping somebody made by hand, or that automap
 * got from the service itself, is better evidence than a file that shipped with
 * the release - so this fills gaps and leaves answers alone.
 *
 * Matched by slug, so it covers a library's own copy of a platform as well as the
 * shared one: they carry the same slug and are the same machine.
 *
 * @return int how many mappings were written
 */
function metadata_seed_platform_map(int $providerId, string $type): int
{
    if (!metadata_provider_filters_by_platform($type)) {
        return 0;
    }
    $map = metadata_template_platform_map($type);
    if ($map === []) {
        return 0;
    }

    $written = 0;
    foreach ($map as $slug => $remoteId) {
        foreach (all('SELECT id FROM platforms WHERE slug = ?', [$slug]) as $p) {
            $done = q('INSERT IGNORE INTO metadata_provider_platforms
                           (provider_id, platform_id, remote_platform_id)
                       VALUES (?, ?, ?)',
                      [$providerId, (int) $p['id'], (string) $remoteId]);
            $written += $done->rowCount();
        }
    }
    return $written;
}

/** How many of this instance's platforms a provider can filter on. */
function metadata_mapped_count(int $providerId): int
{
    return (int) scalar('SELECT COUNT(*) FROM metadata_provider_platforms WHERE provider_id = ?',
                        [$providerId]);
}

/**
 * One game's page, for the things the index does not carry.
 *
 * Read as text rather than as markup. The values arrive as "Publisher:Rainbow
 * Arts · Year:1992 · Developer:Factor 5", and the labels are what identifies them
 * - not their position in a table, not a class name. Every previous attempt at
 * this site failed by assuming a structure; the labels are the one thing visible
 * from outside that can be relied on.
 *
 * @return array{year:?int,developer:?string,publisher:?string,summary:?string}
 */
function openretro_fetch_detail(string $url, array $params): array
{
    $empty = ['year' => null, 'developer' => null, 'publisher' => null, 'summary' => null];

    metadata_rate_limit('openretro', (float) ($params['min_delay'] ?? 1.0));
    [$body, $err] = metadata_http_get($url, ['Accept: text/html'], (int) ($params['timeout'] ?? 20));
    if ($body === null) {
        metadata_debug('openretro: detail failed', $url . ' — ' . $err);
        return $empty;
    }

    // Scripts and styles first, or their contents end up in the text.
    $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $body);
    $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/', ' ', (string) $text));

    // The value runs to the next label or separator. Anchored on the label with a
    // colon, which is how the page writes them.
    $grab = static function (string $label) use ($text): ?string {
        if (preg_match('/\b' . preg_quote($label, '/') . '\s*:\s*([^·|\n]{1,80}?)\s*(?=·|\||$|[A-Z][a-z]+\s*:)/u',
                       $text, $m) !== 1) {
            return null;
        }
        $v = trim($m[1]);
        return $v === '' ? null : $v;
    };

    $year = $grab('Year');
    $out  = [
        'year'      => ($year !== null && preg_match('/(\d{4})/', $year, $y) === 1) ? (int) $y[1] : null,
        'developer' => $grab('Developer'),
        'publisher' => $grab('Publisher'),
        'summary'   => null,
    ];
    metadata_debug('openretro: detail ' . $url,
        sprintf('year=%s developer=%s publisher=%s',
            $out['year'] ?? '-', $out['developer'] ?? '-', $out['publisher'] ?? '-'));
    return $out;
}

/**
 * The platform slugs the templates ship.
 *
 * The vocabulary a source's scope is written in. Read from the same file the
 * structure data comes from, so the two cannot drift: a scope naming a slug that
 * is not in here would be a scope nothing can match.
 *
 * @return list<string>
 */
function metadata_template_platform_slugs(): array
{
    static $slugs = null;
    if ($slugs !== null) {
        return $slugs;
    }
    $slugs = [];
    $path  = APP_ROOT . '/structure/platforms.json';
    if (is_file($path)) {
        $rows = json_decode((string) file_get_contents($path), true);
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (isset($row['slug'])) {
                $slugs[] = (string) $row['slug'];
            }
        }
    }
    return $slugs;
}

// --- Wikipedia --------------------------------------------------------------

/**
 * Strip the wikitext out of an infobox value.
 *
 * Values arrive as markup: `[[Motorola 68000]] @ 7.16&nbsp;MHz`, `{{Start
 * date|1987|03}}`, `<ref name="x">…</ref>`, `{{nowrap|…}}`. What is wanted is the
 * words. Nested braces are removed innermost-first rather than with one greedy
 * pattern, because a template inside a template is ordinary here.
 */
function wikipedia_clean(string $value): string
{
    $value = preg_replace('#<ref[^>]*/>#i', '', $value);
    $value = preg_replace('#<ref[^>]*>.*?</ref>#is', '', (string) $value);
    $value = preg_replace('#<!--.*?-->#s', '', (string) $value);

    // Templates, innermost first. A {{Start date|1987|3}} keeps its numbers,
    // which is what the year is read out of below.
    for ($i = 0; $i < 8; $i++) {
        $before = $value;
        $value  = preg_replace_callback('/\{\{([^{}]*)\}\}/', static function (array $m): string {
            $parts = explode('|', $m[1]);
            $name  = strtolower(trim(array_shift($parts) ?? ''));
            if (in_array($name, ['start date', 'start date and age', 'birth date'], true)) {
                return implode('-', array_filter($parts, static fn($p) => preg_match('/^\d+$/', trim($p)) === 1));
            }
            // nowrap, ubl, plainlist and friends: keep what is inside.
            return implode(' ', array_filter($parts, static fn($p) => !str_contains($p, '=')));
        }, (string) $value);
        if ($value === $before) {
            break;
        }
    }

    // [[Target|shown]] keeps what is shown; [[Target]] keeps the target.
    $value = preg_replace('/\[\[([^\]|]*)\|([^\]]*)\]\]/', '$2', (string) $value);
    $value = preg_replace('/\[\[([^\]]*)\]\]/', '$1', (string) $value);
    $value = preg_replace("/'''?/", '', (string) $value);
    $value = str_replace(['&nbsp;', '<br />', '<br/>', '<br>'], [' ', ' ', ' ', ' '], (string) $value);
    $value = strip_tags((string) $value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $value));
}

/**
 * The infobox, as a map of normalised names to cleaned values.
 *
 * Names are normalised because the same field is spelled several ways across the
 * templates - "release date", "releasedate" and "released" are all in use, as are
 * "units sold" and "unitssold" - and an article is not wrong for picking one.
 *
 * @return array<string,string>
 */
function wikipedia_infobox(string $wikitext): array
{
    $at = stripos($wikitext, '{{Infobox');
    if ($at === false) {
        return [];
    }

    // Walk to the matching close rather than regex to it: infoboxes contain
    // templates, and the first }} is usually one of theirs.
    $depth = 0;
    $end   = null;
    for ($i = $at, $len = strlen($wikitext); $i < $len - 1; $i++) {
        if ($wikitext[$i] === '{' && $wikitext[$i + 1] === '{') { $depth++; $i++; }
        elseif ($wikitext[$i] === '}' && $wikitext[$i + 1] === '}') {
            $depth--; $i++;
            if ($depth === 0) { $end = $i + 1; break; }
        }
    }
    $body = substr($wikitext, $at, ($end ?? strlen($wikitext)) - $at);
    // Without this the last field keeps the infobox's own closing braces, and
    // "Amiga 3000 }}" goes on the entry.
    $body = preg_replace('/\}\}\s*$/', '', $body) ?? $body;

    // Split on pipes at depth zero, so a pipe inside a template or a link stays
    // where it is.
    $fields = [];
    $buf    = '';
    $d      = 0;
    for ($i = 0, $len = strlen($body); $i < $len; $i++) {
        $two = substr($body, $i, 2);
        if ($two === '{{' || $two === '[[') { $d++; $buf .= $two; $i++; continue; }
        if ($two === '}}' || $two === ']]') { $d--; $buf .= $two; $i++; continue; }
        if ($body[$i] === '|' && $d <= 1) { $fields[] = $buf; $buf = ''; continue; }
        $buf .= $body[$i];
    }
    $fields[] = $buf;

    $out = [];
    foreach ($fields as $field) {
        if (!str_contains($field, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $field, 2);
        $key = preg_replace('/[^a-z0-9]/', '', strtolower(trim($k)));
        $val = wikipedia_clean($v);
        if ($key !== '' && $val !== '') {
            $out[$key] = $val;
        }
    }
    return $out;
}

/**
 * Search Wikipedia, then open the articles.
 *
 * The search result carries a title and a snippet and nothing structured, so the
 * value is all in the articles - and the infobox is the part worth having: maker,
 * year, processor, memory, graphics, sound, ports. Those become specification
 * rows rather than a paragraph somebody has to read and retype.
 *
 * Images are deliberately not offered. Wikipedia hosts freely-licensed pictures
 * and non-free ones side by side, the API does not make the difference obvious,
 * and quietly copying a non-free photograph onto somebody's server is not a thing
 * to get wrong. The article link is on the candidate; the pictures are one click
 * away with their terms attached.
 */
/**
 * The Movie Database - a search, then a details call per candidate for
 * the fields the search endpoint doesn't carry (overview, production
 * companies, runtime). No platform concept of its own: a film has no
 * real Amiga release the way a game does, so results carry no
 * platform_id and metadata_rank_results() ranks them on title
 * closeness alone, which is already how it behaves when a source
 * declares nothing about platform.
 */

/**
 * A logged-in bearer token for TheTVDB, cached rather than fetched on
 * every search - their own docs say a token is valid for a month, and
 * asking for a fresh one on every lookup would be a login request for
 * every search request, twice the traffic for no reason. Returns
 * [token, error].
 */
function tvdb_ensure_token(array $params, string $endpoint, int $timeout): array
{
    $apiKey = trim((string) ($params['api_key'] ?? ''));
    if ($apiKey === '') {
        return [null, 'no API key configured.'];
    }

    // Keyed on the API key itself, not just the provider - two rows
    // pointed at TheTVDB with different keys should never hand one's
    // token to the other's request.
    $cacheKey = metadata_cache_key('tvdb', 'token', [$apiKey]);
    $cached   = metadata_cache_get($cacheKey);
    if (is_array($cached) && !empty($cached['token'])) {
        return [(string) $cached['token'], null];
    }

    $body = ['apikey' => $apiKey];
    $pin  = trim((string) ($params['pin'] ?? ''));
    if ($pin !== '') {
        $body['pin'] = $pin;
    }

    metadata_rate_limit('tvdb', 0.3);
    [$resp, $err] = metadata_http_get(
        $endpoint . '/login', ['Accept: application/json', 'Content-Type: application/json'],
        $timeout, json_encode($body)
    );
    if ($resp === null) {
        return [null, $err];
    }
    $json = json_decode($resp, true);
    $token = (string) ($json['data']['token'] ?? '');
    if ($token === '') {
        return [null, 'login did not return a token.'];
    }

    // A little under a month, so a token is refreshed before TheTVDB's
    // own expiry rather than right up against it.
    metadata_cache_put('tvdb', $cacheKey, ['token' => $token], 24 * 27);
    return [$token, null];
}

/**
 * MusicBrainz - a genuine release-level search, unlike TheAudioDB's
 * artist-only free tier: title, artist, year, label, catalogue number,
 * and where the release actually has one, a real barcode - the first
 * source this catalogue has that can fill in that field at all.
 *
 * Two things MusicBrainz's own docs are explicit about, both honoured
 * here rather than assumed harmless to skip:
 *   - a meaningful User-Agent is required, with enough in it to reach
 *     whoever runs the instance - an anonymous or generic one is
 *     throttled specifically for being anonymous, separately from the
 *     ordinary rate limit everyone gets.
 *   - roughly one request per second per IP is the real limit, the
 *     strictest of the three checks their docs describe (User-Agent,
 *     IP, and global) - so the rate limit here is deliberately closer
 *     to a full second than the ~0.3s other providers use, rather than
 *     reusing a delay tuned for a source with a more generous limit.
 */
function metadata_search_musicbrainz(array $params, string $title, ?string $remotePlatform): array
{
    $base = rtrim((string) ($params['endpoint'] ?? 'https://musicbrainz.org/ws/2'), '/');
    $timeout = (int) ($params['timeout'] ?? 15);
    $contact = trim((string) ($params['contact'] ?? ''));
    if ($contact === '') {
        return [[], 'MusicBrainz: no contact URL or email configured for the User-Agent - '
                   . 'required by their own API terms, not optional here.'];
    }
    $userAgent = 'RetroHive/1.0 (' . $contact . ')';
    $headers = ['Accept: application/json', 'User-Agent: ' . $userAgent];

    metadata_rate_limit('musicbrainz', (float) ($params['min_delay'] ?? 1.1));
    $url = $base . '/release?' . http_build_query([
        'query' => $title, 'fmt' => 'json', 'limit' => (int) ($params['detail_pages'] ?? 8),
    ]);
    metadata_debug('musicbrainz: search', $url);

    [$body, $err] = metadata_http_get($url, $headers, $timeout);
    if ($body === null) {
        return [[], 'MusicBrainz: ' . $err];
    }
    $json = json_decode($body, true);
    if (isset($json['error'])) {
        return [[], 'MusicBrainz: ' . (string) $json['error']];
    }
    $hits = $json['releases'] ?? [];
    if (!is_array($hits) || $hits === []) {
        metadata_debug('musicbrainz: hits', 0);
        return [[], null];
    }
    metadata_debug('musicbrainz: hits', count($hits));

    $out = [];
    foreach ($hits as $hit) {
        $candidate = musicbrainz_release_to_candidate($hit);
        if ($candidate !== null) {
            $out[] = $candidate;
        }
    }
    return [$out, null];
}

/** One MusicBrainz release search hit, as a candidate - the search index already carries everything needed, no second lookup per result. */
function musicbrainz_release_to_candidate(array $r): ?array
{
    $title = trim((string) ($r['title'] ?? ''));
    if ($title === '') {
        return null;
    }

    $year = null;
    $dateRaw = (string) ($r['date'] ?? '');
    if ($dateRaw !== '' && preg_match('/^(\d{4})/', $dateRaw, $m) === 1) {
        $year = (int) $m[1];
    }

    // The first credited artist, not the full joined string with join
    // phrases like "feat." folded in - the same "one answer, not a
    // list" this field means everywhere else in this catalogue.
    $artist = null;
    $credits = (array) ($r['artist-credit'] ?? []);
    if ($credits !== [] && isset($credits[0]['artist']['name'])) {
        $artist = (string) $credits[0]['artist']['name'];
    }

    // The first label, and its own catalogue number alongside it -
    // label-info is a list because a release can be co-issued, but one
    // answer is what this field means here, the same as artist above.
    $label = null;
    $catalogNumber = null;
    $labelInfo = (array) ($r['label-info'] ?? []);
    if ($labelInfo !== []) {
        $label = isset($labelInfo[0]['label']['name']) ? (string) $labelInfo[0]['label']['name'] : null;
        $catalogNumber = isset($labelInfo[0]['catalog-number']) ? (string) $labelInfo[0]['catalog-number'] : null;
    }

    $specs = [];
    if (!empty($r['country'])) {
        $specs['Country'] = (string) $r['country'];
    }
    if (!empty($r['status'])) {
        $specs['Status'] = (string) $r['status'];
    }
    if (!empty($r['packaging'])) {
        $specs['Packaging'] = (string) $r['packaging'];
    }
    $media = (array) ($r['media'] ?? []);
    if ($media !== [] && !empty($media[0]['format'])) {
        $specs['Format'] = (string) $media[0]['format'];
    }

    $id = (string) ($r['id'] ?? '');

    return [
        'remote_id'  => $id,
        'title'      => $title,
        'year'       => $year,
        'developer'  => $artist,
        'publisher'  => $label,
        'platform'   => null,
        'barcode'    => !empty($r['barcode']) ? (string) $r['barcode'] : null,
        'url'        => $id !== '' ? 'https://musicbrainz.org/release/' . $id : null,
        'summary'    => null,
        'images'     => [],
        'documents'  => [],
        'hardware'   => [],
        'specs'      => $catalogNumber !== null ? $specs + ['Catalogue number' => $catalogNumber] : $specs,
    ];
}

/**
 * TheAudioDB - for music. The free key ("123") is genuinely more
 * limited than the documentation describes: searchalbum.php and
 * discography.php both return next to nothing on it in practice
 * (searchalbum.php refuses outright; discography.php answers with a
 * single, id-less album with no artwork), confirmed by hand against
 * the live API rather than assumed from the docs. What the free key
 * does answer well is a plain artist search - biography, genre,
 * style, record label, and real artwork - so that is what this
 * provider actually offers: an artist lookup, not an album-specific
 * one. A candidate's own title is therefore the artist's name, not a
 * particular record - the honest shape of what the free tier can
 * really do, not a promise the API itself doesn't keep.
 */
function metadata_search_theaudiodb(array $params, string $title, ?string $remotePlatform): array
{
    $apiKey = trim((string) ($params['api_key'] ?? '123'));
    if ($apiKey === '') {
        return [[], 'TheAudioDB: no API key configured.'];
    }
    $base    = rtrim((string) ($params['endpoint'] ?? 'https://www.theaudiodb.com/api/v1/json'), '/');
    $timeout = (int) ($params['timeout'] ?? 15);

    metadata_rate_limit('theaudiodb', (float) ($params['min_delay'] ?? 2.0));
    $url = $base . '/' . rawurlencode($apiKey) . '/search.php?' . http_build_query(['s' => $title]);
    metadata_debug('theaudiodb: search', $url);

    [$body, $err] = metadata_http_get($url, ['Accept: application/json'], $timeout);
    if ($body === null) {
        return [[], 'TheAudioDB: ' . $err];
    }
    $json = json_decode($body, true);
    $hits = $json['artists'] ?? null;
    if (!is_array($hits) || $hits === []) {
        metadata_debug('theaudiodb: hits', 0);
        return [[], null];
    }
    metadata_debug('theaudiodb: hits', count($hits));

    $limit = max(1, (int) ($params['detail_pages'] ?? 5));
    $out   = [];
    foreach (array_slice($hits, 0, $limit) as $hit) {
        $candidate = theaudiodb_artist_to_candidate($hit);
        if ($candidate !== null) {
            $out[] = $candidate;
        }
    }
    return [$out, null];
}

/** One TheAudioDB artist record, as a candidate. */
function theaudiodb_artist_to_candidate(array $a): ?array
{
    $name = trim((string) ($a['strArtist'] ?? ''));
    if ($name === '') {
        return null;
    }

    $year = null;
    if (!empty($a['intFormedYear']) && ctype_digit((string) $a['intFormedYear'])) {
        $year = (int) $a['intFormedYear'];
    }

    // Their own field for the genre a browser sees; strStyle is a finer
    // subdivision the site also tracks and worth keeping distinct
    // rather than folding into one row and losing the difference.
    $specs = [];
    if (!empty($a['strGenre'])) {
        $specs['Genre'] = (string) $a['strGenre'];
    }
    if (!empty($a['strStyle'])) {
        $specs['Style'] = (string) $a['strStyle'];
    }
    if (!empty($a['strCountry'])) {
        $specs['Country'] = (string) $a['strCountry'];
    }

    $images = [];
    foreach (['strArtistThumb' => 'Artist photo', 'strArtistFanart' => 'Fan art',
              'strArtistBanner' => 'Banner'] as $field => $caption) {
        if (!empty($a[$field])) {
            $images[] = ['url' => (string) $a[$field], 'thumb_url' => (string) $a[$field],
                         'kind' => 'other', 'caption' => $caption];
        }
    }

    $id  = (string) ($a['idArtist'] ?? '');
    $url = $id !== ''
        ? 'https://www.theaudiodb.com/artist/' . $id . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', $name)
        : 'https://www.theaudiodb.com/searchall.php?s=' . rawurlencode($name);

    return [
        'remote_id'  => $id,
        'title'      => $name,
        'year'       => $year,
        // The artist itself, in the one field this catalogue already
        // uses for "who made this" on an audio entry.
        'developer'  => $name,
        'publisher'  => !empty($a['strLabel']) ? (string) $a['strLabel'] : null,
        'platform'   => null,
        'url'        => $url,
        'summary'    => trim((string) ($a['strBiography'] ?? '')) ?: null,
        'images'     => $images,
        'documents'  => [],
        'hardware'   => [],
        'specs'      => $specs,
    ];
}

/**
 * TheTVDB, for TV shows - a single search call carries almost
 * everything needed (overview, year, network, genres, poster) without
 * a details call per candidate the way TMDB needs, since their own
 * search index already returns the fuller record.
 */
function metadata_search_tvdb(array $params, string $title, ?string $remotePlatform): array
{
    $endpoint = rtrim((string) ($params['endpoint'] ?? 'https://api4.thetvdb.com/v4'), '/');
    $timeout  = (int) ($params['timeout'] ?? 15);

    [$token, $err] = tvdb_ensure_token($params, $endpoint, $timeout);
    if ($token === null) {
        return [[], 'TheTVDB: ' . $err];
    }
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];

    metadata_rate_limit('tvdb', 0.3);
    $url = $endpoint . '/search?' . http_build_query(['query' => $title, 'type' => 'series']);
    metadata_debug('tvdb: search', $url);

    [$body, $err] = metadata_http_get($url, $headers, $timeout);
    if ($body === null) {
        return [[], 'TheTVDB: ' . $err];
    }
    $json = json_decode($body, true);
    $hits = $json['data'] ?? [];
    if (!is_array($hits) || $hits === []) {
        metadata_debug('tvdb: hits', 0);
        return [[], null];
    }
    metadata_debug('tvdb: hits', count($hits));

    $limit = max(1, (int) ($params['detail_pages'] ?? 8));
    $out   = [];
    foreach (array_slice($hits, 0, $limit) as $hit) {
        $candidate = tvdb_search_result_to_candidate($hit);
        if ($candidate !== null) {
            $out[] = $candidate;
        }
    }
    return [$out, null];
}

/** One TheTVDB search hit, as a candidate - no second request needed. */
function tvdb_search_result_to_candidate(array $hit): ?array
{
    $name = (string) ($hit['name'] ?? $hit['title'] ?? '');
    if ($name === '') {
        return null;
    }

    $year = null;
    $yearRaw = (string) ($hit['year'] ?? '');
    if ($yearRaw !== '' && preg_match('/(\d{4})/', $yearRaw, $m) === 1) {
        $year = (int) $m[1];
    }

    // The network is the closest thing a series has to "who made this"
    // - AMC made The Walking Dead the way Skydance made a film, even
    // though a network commissions rather than produces in the studio
    // sense. It is still the one-word answer to "whose is this" that
    // this field means everywhere else.
    $network = null;
    if (!empty($hit['network'])) {
        $network = (string) $hit['network'];
    } elseif (!empty($hit['companies']) && is_array($hit['companies'])) {
        $network = (string) ($hit['companies'][0] ?? '') ?: null;
    }

    $images = [];
    $poster = (string) ($hit['image_url'] ?? $hit['poster'] ?? $hit['thumbnail'] ?? '');
    if ($poster !== '') {
        $images[] = ['url' => $poster, 'thumb_url' => $poster, 'kind' => 'box_front', 'caption' => 'Poster'];
    }

    $specs = [];
    $genres = (array) ($hit['genres'] ?? []);
    $genres = array_values(array_filter(array_map('strval', $genres)));
    if ($genres !== []) {
        $specs['Genre'] = implode(', ', $genres);
    }
    if (!empty($hit['status'])) {
        $specs['Status'] = (string) $hit['status'];
    }

    $slug = (string) ($hit['slug'] ?? '');
    $url  = $slug !== '' ? 'https://www.thetvdb.com/series/' . $slug
                         : 'https://www.thetvdb.com/search?query=' . rawurlencode($name);

    return [
        'remote_id'  => (string) ($hit['tvdb_id'] ?? $hit['id'] ?? ''),
        'title'      => $name,
        'year'       => $year,
        'developer'  => $network,
        'publisher'  => null,
        // No platform opinion, the same reasoning TMDB's own provider
        // already gives - a series has no real per-machine release.
        'platform'   => null,
        'url'        => $url,
        'summary'    => trim((string) ($hit['overview'] ?? '')) ?: null,
        'images'     => $images,
        'documents'  => [],
        'hardware'   => [],
        'specs'      => $specs,
    ];
}

function metadata_search_tmdb(array $params, string $title, ?string $remotePlatform): array
{
    $apiKey = trim((string) ($params['api_key'] ?? ''));
    if ($apiKey === '') {
        return [[], 'TMDB: no API Read Access Token configured.'];
    }

    $base    = rtrim((string) ($params['endpoint'] ?? 'https://api.themoviedb.org/3'), '/');
    $imgBase = rtrim((string) ($params['image_endpoint'] ?? 'https://image.tmdb.org/t/p'), '/');
    $lang    = (string) ($params['language'] ?? 'en-US');
    $timeout = (int) ($params['timeout'] ?? 15);
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $apiKey];

    metadata_rate_limit('tmdb', (float) ($params['min_delay'] ?? 0.3));
    $url = $base . '/search/movie?' . http_build_query(['query' => $title, 'language' => $lang]);
    metadata_debug('tmdb: search', $url);

    [$body, $err] = metadata_http_get($url, $headers, $timeout);
    if ($body === null) {
        return [[], 'TMDB: ' . $err];
    }
    $json = json_decode($body, true);
    if (isset($json['status_code'])) {
        // TMDB answers a bad key with 200 and a status_code/status_message
        // pair rather than an HTTP error, so the body has to be read to
        // find out a request never really worked.
        return [[], 'TMDB: ' . (string) ($json['status_message'] ?? 'request refused.')];
    }
    $hits = $json['results'] ?? [];
    if (!is_array($hits) || $hits === []) {
        metadata_debug('tmdb: hits', 0);
        return [[], null];
    }
    metadata_debug('tmdb: hits', count($hits));

    $out   = [];
    $limit = max(1, (int) ($params['detail_pages'] ?? 4));
    foreach (array_slice($hits, 0, $limit) as $hit) {
        $id = (int) ($hit['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $candidate = tmdb_movie_details($base, $imgBase, $id, $lang, $headers, $timeout);
        if ($candidate !== null) {
            $out[] = $candidate;
        }
    }

    return [$out, null];
}

/** One film, with credits appended so production companies come in the same request. */
function tmdb_movie_details(string $base, string $imgBase, int $id, string $lang, array $headers, int $timeout): ?array
{
    metadata_rate_limit('tmdb', 0.3);
    $url = $base . '/movie/' . $id . '?' . http_build_query([
        'language' => $lang, 'append_to_response' => 'credits',
    ]);
    [$body, $err] = metadata_http_get($url, $headers, $timeout);
    if ($body === null) {
        metadata_debug('tmdb: details failed', $id . ' — ' . $err);
        return null;
    }
    $m = json_decode($body, true);
    if (!is_array($m) || empty($m['id'])) {
        return null;
    }

    $year = null;
    if (!empty($m['release_date']) && preg_match('/^(\d{4})/', (string) $m['release_date'], $mm) === 1) {
        $year = (int) $mm[1];
    }

    // The first listed company, not all of them - the same "one answer,
    // not a list" this field means everywhere else in this catalogue.
    $companies = $m['production_companies'] ?? [];
    $studio = is_array($companies) && $companies !== []
        ? (string) ($companies[0]['name'] ?? '') : null;

    $images = [];
    if (!empty($m['poster_path'])) {
        $images[] = [
            'url'       => $imgBase . '/w780' . $m['poster_path'],
            'thumb_url' => $imgBase . '/w342' . $m['poster_path'],
            'kind'      => 'box_front',
            'caption'   => 'Poster',
        ];
    }
    if (!empty($m['backdrop_path'])) {
        $images[] = [
            'url'       => $imgBase . '/w1280' . $m['backdrop_path'],
            'thumb_url' => $imgBase . '/w300' . $m['backdrop_path'],
            'kind'      => 'other',
            'caption'   => 'Backdrop',
        ];
    }

    $specs = [];
    if (!empty($m['runtime'])) {
        $specs['Running time'] = ((int) $m['runtime']) . ' minutes';
    }
    $genres = array_filter(array_map(fn($g) => (string) ($g['name'] ?? ''), (array) ($m['genres'] ?? [])));
    if ($genres !== []) {
        $specs['Genre'] = implode(', ', $genres);
    }
    $directors = array_filter(array_map(
        fn($c) => (string) ($c['job'] ?? '') === 'Director' ? (string) ($c['name'] ?? '') : null,
        (array) ($m['credits']['crew'] ?? [])
    ));
    if ($directors !== []) {
        $specs['Director'] = implode(', ', $directors);
    }

    // Structured, alongside the text spec above rather than instead of
    // it - the spec row is what a source with no structured credit
    // concept at all (Wikipedia, an infobox) already falls back to,
    // and staying consistent with that costs nothing here. This is
    // the one metadata_apply_credits() actually reads: a real,
    // linked credit rather than a line of text nobody but a human
    // reading the review screen can act on.
    $credits = array_map(fn($name) => ['role_slug' => 'director', 'name' => $name], $directors);

    return [
        'remote_id'  => (string) $id,
        'title'      => (string) ($m['title'] ?? ''),
        'year'       => $year,
        'developer'  => $studio,
        'publisher'  => null,
        // No opinion on platform - a film has no real per-machine release
        // the way a game does, so nothing here is claimed either way and
        // metadata_rank_results() falls back to title closeness alone.
        'platform'   => null,
        'url'        => 'https://www.themoviedb.org/movie/' . $id,
        'summary'    => trim((string) ($m['overview'] ?? '')) ?: null,
        'images'     => $images,
        'documents'  => [],
        'hardware'   => [],
        'specs'      => $specs,
        'credits'    => $credits,
    ];
}

function metadata_search_wikipedia(array $params, string $title, ?string $remotePlatform): array
{
    $base = rtrim((string) ($params['endpoint'] ?? 'https://en.wikipedia.org/w/api.php'), '/');

    metadata_rate_limit('wikipedia', (float) ($params['min_delay'] ?? 1.0));
    $url = $base . '?' . http_build_query([
        'action' => 'query', 'list' => 'search', 'srsearch' => $title,
        'srlimit' => 8, 'format' => 'json', 'formatversion' => 2,
    ]);
    metadata_debug('wikipedia: search', $url);

    [$body, $err] = metadata_http_get($url, ['Accept: application/json'],
                                      (int) ($params['timeout'] ?? 20));
    if ($body === null) {
        return [[], 'Wikipedia: ' . $err];
    }
    $json = json_decode($body, true);
    $hits = $json['query']['search'] ?? [];
    if (!is_array($hits) || $hits === []) {
        metadata_debug('wikipedia: hits', 0);
        return [[], null];
    }
    metadata_debug('wikipedia: hits', count($hits));

    $out   = [];
    $limit = max(1, (int) ($params['detail_pages'] ?? 4));
    foreach (array_slice($hits, 0, $limit) as $hit) {
        $pageTitle = (string) ($hit['title'] ?? '');
        if ($pageTitle === '') {
            continue;
        }
        $out[] = wikipedia_article($base, $pageTitle, $params);
    }
    $out = array_values(array_filter($out));

    // Commons photographs, on the best match only.
    //
    // Wikipedia and Commons are the same project seen from two sides: the article
    // has the words and usually a box scan under fair use, Commons has freely
    // licensed photographs of the same thing. Asking them separately meant two
    // entries on the review screen for one title, each holding half the answer.
    //
    // The best match only, because this is a request per candidate otherwise, and
    // the fourth-best article's photographs are not worth a round trip.
    // The best match gets the fuller treatment: the article's own files, then the
    // Commons photographs. Both are a request each, so both are done once.
    if ($out !== []) {
        $lead  = (string) ($out[0]['images'][0]['url'] ?? '');
        $files = wikipedia_page_images($base, (string) ($out[0]['remote_id'] ?? $out[0]['title']),
                                       $params, $lead);
        foreach ($files as $img) {
            $out[0]['images'][] = $img;
        }
    }

    if ($out !== [] && (string) ($params['include_commons'] ?? '1') === '1') {
        // The machine the *article* names, not the one the search was narrowed by.
        //
        // Wikipedia's own search takes no platform and declares so; reading the
        // argument here would make that declaration false, and there is a check
        // that compares the two. The article states its platforms anyway - "Amiga,
        // MS-DOS, Atari ST" - and the first of those is a better term for a photo
        // search than a slug the caller happened to pass.
        $stated = (string) ($out[0]['platform'] ?? '');
        $stated = trim((string) preg_split('/\s*,\s*/', $stated)[0]);
        $extra  = wikipedia_commons_images((string) $out[0]['title'],
                                           $stated === '' ? null : $stated, $params);
        if ($extra !== []) {
            $have = array_column($out[0]['images'] ?? [], 'url');
            foreach ($extra as $img) {
                if (!in_array($img['url'], $have, true)) {
                    $out[0]['images'][] = $img;
                }
            }
        }
    }

    return [$out, null];
}

/** One article, as a candidate. */
function wikipedia_article(string $base, string $pageTitle, array $params): ?array
{
    metadata_rate_limit('wikipedia', (float) ($params['min_delay'] ?? 1.0));
    // The lead image comes along with the article.
    //
    // On a game or a program that is nearly always the box art or a title
    // screen - the thing at the top right of the article - and for something like
    // Deluxe Paint, which no games database carries, it is the only picture
    // anywhere. `pageimages` gives it without a second request, so this costs
    // nothing but a longer query string.
    $url = $base . '?' . http_build_query([
        'action' => 'query', 'prop' => 'revisions|extracts|pageimages', 'titles' => $pageTitle,
        'rvslots' => 'main', 'rvprop' => 'content',
        'exintro' => 1, 'explaintext' => 1,
        // The original as well as a thumbnail: the first is what gets stored, the
        // second is what the review screen shows while you decide.
        // pilicense=any, because the default is `free`.
        //
        // This is why the picture never arrived. `pageimages` returns only
        // freely-licensed lead images unless told otherwise, and a game or
        // software box scan on Wikipedia is almost always non-free, used there
        // under fair use - so exactly the articles worth a picture returned none,
        // while Commons (which is free by definition) had plenty.
        //
        // What comes back is therefore often not free to redistribute. It is
        // offered like any other candidate image and downloaded only if somebody
        // ticks it, which keeps that judgement with the person whose catalogue it
        // is.
        'piprop' => 'original|thumbnail', 'pithumbsize' => 480, 'pilicense' => 'any',
        'format' => 'json', 'formatversion' => 2,
    ]);
    [$body, $err] = metadata_http_get($url, ['Accept: application/json'],
                                      (int) ($params['timeout'] ?? 20));
    if ($body === null) {
        metadata_debug('wikipedia: article failed', $pageTitle . ' — ' . $err);
        return null;
    }
    $page = ($json = json_decode($body, true))['query']['pages'][0] ?? null;
    if (!is_array($page)) {
        return null;
    }

    $wikitext = (string) ($page['revisions'][0]['slots']['main']['content'] ?? '');
    $box      = wikipedia_infobox($wikitext);
    metadata_debug('wikipedia: ' . $pageTitle,
        $box === [] ? 'no infobox' : count($box) . ' infobox fields');

    // The year, from whichever of the several spellings this article used.
    $year = null;
    foreach (['releasedate', 'released', 'firstreleased', 'introduced', 'lifespan'] as $k) {
        if (isset($box[$k]) && preg_match('/(1[89]\d\d|20\d\d)/', $box[$k], $m) === 1) {
            $year = (int) $m[1];
            break;
        }
    }

    // Maker before developer: on a machine the manufacturer is the answer to
    // "whose is this", and the developer field is often a design house.
    $maker = $box['manufacturer'] ?? $box['developer'] ?? null;

    // The infobox rows worth keeping as specification, with the words this
    // catalogue uses rather than the template's.
    $specMap = [
        'cpu' => 'Processor', 'soc' => 'Processor', 'memory' => 'Memory',
        'storage' => 'Storage', 'media' => 'Media', 'display' => 'Display',
        'graphics' => 'Graphics', 'sound' => 'Sound', 'os' => 'Operating system',
        'connectivity' => 'Connectivity', 'input' => 'Input', 'power' => 'Power',
        'dimensions' => 'Dimensions', 'weight' => 'Weight', 'type' => 'Type',
        'generation' => 'Generation', 'predecessor' => 'Predecessor',
        'successor' => 'Successor', 'discontinued' => 'Discontinued',
    ];
    $specs = [];
    foreach ($specMap as $key => $label) {
        if (!empty($box[$key]) && !isset($specs[$label])) {
            $specs[$label] = mb_substr($box[$key], 0, 400);
        }
    }

    return [
        'remote_id'  => $pageTitle,
        'title'      => $box['name'] ?? $box['title'] ?? $pageTitle,
        'year'       => $year,
        'developer'  => $maker === null ? null : mb_substr($maker, 0, 160),
        'publisher'  => null,
        'platform'   => $box['platform'] ?? $box['family'] ?? null,
        'url'        => 'https://en.wikipedia.org/wiki/' . str_replace(' ', '_', $pageTitle),
        'summary'    => trim((string) ($page['extract'] ?? '')) ?: null,
        // The lead image only, here.
        //
        // The article's full file list is a second request, and doing it for every
        // candidate meant a lookup that used to be five requests became ten - which
        // is how a search started collecting 429s. The search function asks for the
        // rest on the best match alone, where it is worth the round trip.
        'images'     => wikipedia_lead_image($page, $pageTitle),
        'documents'  => [],
        'hardware'   => ['cpu' => $box['cpu'] ?? null, 'memory' => $box['memory'] ?? null,
                         'storage' => $box['storage'] ?? null],
        'specs'      => $specs,
    ];
}

/**
 * Scraped specification rows, against what the entry already has.
 *
 * Merge, not replace. A lookup offering "Processor: Motorola 68000" is useful;
 * the same lookup wiping a row somebody wrote by hand because the source phrases
 * it differently is not. A label the entry already carries is offered but left
 * unticked by default and never overwrites without being asked.
 *
 * @param array $candidate  a search result, which may carry `specs`
 * @param ?int  $itemId     the entry being filled in, if it exists yet
 * @return list<array{label:string,value:string,current:?string,index:int}>
 */
function metadata_spec_rows(array $candidate, ?int $itemId): array
{
    $specs = $candidate['specs'] ?? null;
    if (!is_array($specs) || $specs === []) {
        return [];
    }

    // What is on the entry now, by label, so each offered row can say whether it
    // would be filling a gap or changing an answer.
    //
    // Hardware's own detail row first, since that is where a hardware
    // entry's specs genuinely live; a non-hardware entry has no
    // item_hardware row at all, so this simply finds nothing and falls
    // through to the entry's own specs column instead - no domain check
    // needed, since only one of the two places can ever have a row.
    $current = [];
    if ($itemId !== null) {
        $raw = scalar('SELECT specs FROM item_hardware WHERE item_id = ?', [$itemId]);
        if ($raw === null) {
            $raw = scalar('SELECT specs FROM items WHERE id = ?', [$itemId]);
        }
        foreach ((array) (json_decode((string) $raw, true) ?: []) as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label !== '') {
                $current[mb_strtolower($label)] = (string) ($row['value'] ?? '');
            }
        }
    }

    $out = [];
    $i   = 0;
    foreach ($specs as $label => $value) {
        $label = trim((string) $label);
        $value = trim((string) $value);
        if ($label === '' || $value === '') {
            continue;
        }
        $out[] = [
            'label'   => mb_substr($label, 0, 80),
            'value'   => mb_substr($value, 0, 400),
            'current' => $current[mb_strtolower($label)] ?? null,
            'index'   => $i++,
        ];
    }
    return $out;
}

/**
 * Fold the chosen rows into what the entry has.
 *
 * A row whose label is already there replaces that row in place, keeping the
 * order somebody arranged; a new label goes on the end. Nothing else is touched,
 * which is the difference between this and writing the scraped list over the top.
 *
 * @return int how many rows changed
 */
function metadata_apply_specs(int $itemId, array $rows): int
{
    if ($rows === []) {
        return 0;
    }
    // Which table actually holds this entry's specs - hardware's own
    // detail row, or the entry's own column for everything else. Read
    // from the domain rather than guessing from whether a row already
    // exists, since a brand-new hardware entry's very first lookup has
    // no item_hardware row yet either.
    $isHardware = scalar(
        'SELECT s.slug FROM items i JOIN categories c ON c.id = i.category_id
          JOIN sections s ON s.id = c.section_id WHERE i.id = ?',
        [$itemId]
    ) === 'hardware';

    $raw = $isHardware
        ? scalar('SELECT specs FROM item_hardware WHERE item_id = ?', [$itemId])
        : scalar('SELECT specs FROM items WHERE id = ?', [$itemId]);
    $existing = (array) (json_decode((string) $raw, true) ?: []);

    $byLabel = [];
    foreach ($existing as $i => $row) {
        $label = mb_strtolower(trim((string) ($row['label'] ?? '')));
        if ($label !== '') {
            $byLabel[$label] = $i;
        }
    }

    $changed = 0;
    foreach ($rows as $row) {
        $label = trim((string) ($row['label'] ?? ''));
        $value = trim((string) ($row['value'] ?? ''));
        if ($label === '' || $value === '') {
            continue;
        }
        $key = mb_strtolower($label);
        if (isset($byLabel[$key])) {
            if ((string) ($existing[$byLabel[$key]]['value'] ?? '') === $value) {
                continue;                       // already says that
            }
            $existing[$byLabel[$key]]['value'] = mb_substr($value, 0, 400);
        } else {
            $existing[]      = ['label' => mb_substr($label, 0, 80), 'value' => mb_substr($value, 0, 400)];
            $byLabel[$key]   = count($existing) - 1;
        }
        $changed++;
    }
    if ($changed === 0) {
        return 0;
    }

    $encoded = json_encode(array_values($existing), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($isHardware) {
        // The row may not exist yet: a lookup can be the first thing that
        // puts hardware detail on an entry.
        q('INSERT INTO item_hardware (item_id, specs) VALUES (?, ?)
           ON DUPLICATE KEY UPDATE specs = VALUES(specs)', [$itemId, $encoded]);
    } else {
        q('UPDATE items SET specs = ? WHERE id = ?', [$encoded, $itemId]);
    }
    return $changed;
}

/**
 * A metadata candidate's director, composer, or other credit - written
 * as a real, linked row (a person, a role, a title), not a line of
 * text nobody but a human reading the review screen could act on.
 *
 * Credits belong to a title, not an item, so a copy with nothing
 * linked yet gets one created from its own current fields first -
 * exactly what save_title() would be given by hand, filled in from
 * what the item already carries. A title made this way stays linked
 * afterward: crediting a second copy of the same release finds it by
 * name and platform rather than making another.
 */
function metadata_apply_credits(int $itemId, array $item, array $candidate, array $wantedIndexes): int
{
    $offered = (array) ($candidate['credits'] ?? []);
    if ($offered === [] || $wantedIndexes === []) {
        return 0;
    }

    $libraryId = (int) ($item['library_id'] ?? 0);
    if ($libraryId <= 0) {
        return 0;
    }

    $titleId = isset($item['title_id']) ? (int) $item['title_id'] : 0;
    if ($titleId <= 0) {
        [$newTitleId, $errors] = save_title(null, [
            'name'         => (string) ($item['title'] ?? ''),
            'platform_id'  => (int) ($item['platform_id'] ?? 0),
            'category_id'  => $item['category_id'] ?? null,
            'developer'    => $item['developer_name'] ?? null,
            'publisher'    => $item['publisher_name'] ?? null,
            'release_year' => $item['release_year'] ?? null,
        ]);
        // Nothing to credit without somewhere to attach it - a title
        // needs at minimum a name and a real platform, both of which
        // an item already has, so this failing means something is
        // wrong with the item itself rather than a real edge case.
        if ($errors !== [] || $newTitleId === null) {
            return 0;
        }
        $titleId = (int) $newTitleId;
        update_row('items', $itemId, ['title_id' => $titleId]);
    }

    $added = 0;
    foreach ($wantedIndexes as $index) {
        $row = $offered[(int) $index] ?? null;
        if (!is_array($row)) {
            continue;
        }
        $roleSlug = trim((string) ($row['role_slug'] ?? ''));
        $name     = trim((string) ($row['name'] ?? ''));
        if ($roleSlug === '' || $name === '') {
            continue;
        }

        $role = one('SELECT id FROM credit_roles WHERE slug = ? AND library_id = ?', [$roleSlug, $libraryId]);
        if ($role === null) {
            // Not a failure worth stopping over - a library that has
            // deleted or never had this role simply cannot record this
            // one credit, the same way a hardware field with nowhere
            // to go is silently skipped elsewhere in this same import.
            continue;
        }
        $roleId = (int) $role['id'];

        $personId = person_id_for_name($name, $libraryId);
        if ($personId === null) {
            continue;
        }

        $dupe = one(
            'SELECT id FROM credits WHERE title_id = ? AND role_id = ? AND person_id = ?',
            [$titleId, $roleId, $personId]
        );
        if ($dupe !== null) {
            continue;                          // already credited, not a second row
        }

        insert_row('credits', [
            'library_id' => $libraryId,
            'title_id'   => $titleId,
            'role_id'    => $roleId,
            'person_id'  => $personId,
        ]);
        $added++;
    }

    return $added;
}

/**
 * Which of a candidate's pictures are already on the entry.
 *
 * Matched on the address it was fetched from, and on the thumbnail's address
 * too, because the import falls back to that when the full-size one is a 404 -
 * so the picture on the entry may have come from either.
 *
 * The content hash is the authority and still refuses a duplicate on the way in.
 * This is only so the screen can say so first: being told afterwards that five
 * of the six you ticked were already there is an answer to a question nobody
 * would have asked.
 *
 * @return array<int,bool> keyed by the candidate's own image index
 */
function metadata_images_already_here(array $candidate, ?int $itemId): array
{
    $out = [];
    foreach (($candidate['images'] ?? []) as $i => $image) {
        $out[(int) $i] = false;
    }
    if ($itemId === null || $out === []) {
        return $out;
    }

    $known = [];
    foreach (all('SELECT source_url FROM item_images WHERE item_id = ? AND source_url IS NOT NULL',
                 [$itemId]) as $row) {
        $known[(string) $row['source_url']] = true;
    }
    if ($known === []) {
        return $out;
    }

    foreach (($candidate['images'] ?? []) as $i => $image) {
        $url   = (string) ($image['url'] ?? '');
        $thumb = (string) ($image['thumb_url'] ?? '');
        if (($url !== '' && isset($known[$url])) || ($thumb !== '' && isset($known[$thumb]))) {
            $out[(int) $i] = true;
        }
    }
    return $out;
}

/**
 * The Big Book of Amiga Hardware's own category ids.
 *
 * Shipped rather than discovered: they are on every page of the site and have
 * been stable since it moved domains, and reading the sidebar first would cost a
 * request before the search has started.
 *
 * The order is the order they are tried in when nothing in the title suggests a
 * better one - the big categories first, because a lookup that reads six lists
 * and finds nothing has cost somebody six seconds.
 *
 * @return array<int,string> id => name
 */
function bboah_categories(): array
{
    return [
        1  => 'Amiga Models & Clones',
        5  => 'A1200 Accelerators',
        2  => 'A500 Accelerators',
        6  => 'A2000 Accelerators',
        28 => 'RAM Expansions',
        31 => 'SCSI Controllers',
        16 => 'Graphics Cards (RTG)',
        24 => 'Misc Hardware',
        4  => 'A1000 Accelerators',
        7  => 'A3000 Accelerators',
        8  => 'A4000 Accelerators',
        3  => 'A600 Accelerators',
        9  => 'CD32 Accelerators',
        10 => 'CDTV Accelerators',
        11 => 'Digtizers & Framegrabbers',
        12 => 'Bridgeboards & Emulator Cards',
        13 => 'Flickerfixers & Scandoublers',
        14 => 'Floppy Controllers',
        15 => 'Genlocks',
        17 => 'Graphics (non RTG) & Video Cards',
        18 => 'IDE Controllers',
        19 => 'I/O Cards',
        20 => 'ISDN & Modem',
        21 => 'Keyboards & Adapters',
        22 => 'Kickstart Expansions & Switchers',
        23 => 'MIDI Devices',
        25 => 'Misc Zorro Cards',
        26 => 'Monitors',
        27 => 'Network Cards',
        29 => 'Samplers',
        30 => 'Scanners',
        32 => 'Sound Cards',
        33 => 'Tape Drives & Backup',
        34 => 'Time Base Correctors',
        35 => 'Tower Kits',
        36 => 'Zorro Extenders & Busboards',
        40 => 'Custom Chips',
        41 => 'Processors',
        42 => 'Mystery Corner',
        43 => 'Non hardware',
        44 => 'Floppy Drive (CBM)',
    ];
}

/**
 * Which category lists to read, best first.
 *
 * A word the title and a category name share moves that category up - "Blizzard
 * 1230 accelerator" should not read Monitors first. Everything else keeps the
 * shipped order, so a title that suggests nothing still reads the big lists.
 *
 * @return list<int> category ids
 */
function bboah_category_order(string $title): array
{
    $words = array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower($title)) ?: [],
                          static fn($w) => mb_strlen($w) >= 4);
    $scored = [];
    $rank   = 0;
    foreach (bboah_categories() as $id => $name) {
        $hay   = mb_strtolower($name);
        $score = 0;
        foreach ($words as $w) {
            if (str_contains($hay, $w)) {
                $score++;
            }
        }
        // Rank breaks ties, so the shipped order survives where nothing matches.
        $scored[] = ['id' => $id, 'score' => $score, 'rank' => $rank++];
    }
    usort($scored, static fn($a, $b) => [$b['score'], $a['rank']] <=> [$a['score'], $b['rank']]);
    return array_column($scored, 'id');
}

/**
 * Products on one of the site's listing pages.
 *
 * Every listing - by category, by manufacturer, the "needs work" views - is the
 * same table of links to product.aspx?id=N with the product's name as the link
 * text. Read off the real pages rather than assumed, which is the lesson four
 * OpenRetro bugs taught.
 *
 * @return list<array{title:string,url:string}>
 */
function bboah_parse_listing(string $html, string $base): array
{
    $out = [];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);

    foreach ($xp->query('//a[contains(translate(@href, "PRODUCT", "product"), "product.aspx?id=")]') ?: []
             as $node) {
        $href = (string) $node->getAttribute('href');
        $name = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent));
        if ($name === '') {
            continue;   // the site has a handful of nameless rows
        }
        if (preg_match('/id=(\d+)/i', $href, $m) !== 1) {
            continue;
        }
        $out[] = [
            'title' => $name,
            'url'   => $base . '/bboah/product.aspx?id=' . (int) $m[1],
        ];
    }
    return $out;
}

/**
 * Search the Big Book of Amiga Hardware.
 *
 * The site's own search box is an ASP.NET `__doPostBack`, not an address, so
 * there is nothing to GET. What it does have is ordinary listing pages - one per
 * category - and this reads those, best-guess category first, stopping as soon
 * as it has enough. Bounded on purpose: a lookup that reads forty-five lists to
 * find nothing has cost somebody forty-five seconds.
 *
 * What comes back is deliberately thin. The listing gives a name and an address;
 * the product page gives the manufacturer in its <title> ("ACA-500+ - Individual
 * Computers") and its photographs at a fixed path. Those three are read off real
 * pages. The description is *not* parsed, because the shape of the markup around
 * it is not something I have seen - and inventing a rule for it is how four
 * separate OpenRetro bugs happened.
 */
function metadata_search_bboah(array $params, string $title, ?string $remotePlatform): array
{
    metadata_rate_limit('bboah', (float) ($params['min_delay'] ?? 1.0));
    $base   = rtrim((string) ($params['endpoint'] ?? 'https://bigbookofamigahardware.com'), '/');
    $forms = bboah_name_forms($title);
    if ($forms === []) {
        return [[], null];
    }

    $maxLists = max(1, (int) ($params['max_lists'] ?? 6));
    $wanted   = max(1, (int) ($params['max_results'] ?? 5));

    $hits    = [];
    $read    = [];
    $names   = bboah_categories();
    $lastErr = null;

    foreach (bboah_category_order($title) as $catId) {
        if (count($read) >= $maxLists || count($hits) >= $wanted) {
            break;
        }
        $url = $base . '/bboah/CategoryList.aspx?id=' . $catId;
        [$body, $err] = metadata_http_get($url, [], (int) ($params['timeout'] ?? 20));
        $read[] = $names[$catId] ?? (string) $catId;
        if ($body === null) {
            $lastErr = $err;
            continue;
        }
        foreach (bboah_parse_listing($body, $base) as $row) {
            // Not plain containment. The site files the Amiga 2000 as "A2000",
            // and "A2000" does not contain "amiga 2000" - which is why a search
            // for it found nothing at all.
            $score = bboah_match_score((string) $row['title'], $forms);
            if ($score === 0) {
                continue;
            }
            $hits[$row['url']] = $row + [
                'category' => $names[$catId] ?? null,
                '__score'  => $score,
            ];
        }
        metadata_rate_limit('bboah', (float) ($params['min_delay'] ?? 1.0));
    }

    metadata_debug('bboah: lists read', implode(', ', $read));
    metadata_debug('bboah: matches', (string) count($hits));

    if ($hits === []) {
        return [[], $lastErr];
    }

    // Best match first: an exact name beats a prefix beats a mention, so the
    // Amiga 2000 comes above the A2000HD rather than in whatever order the
    // listing happened to be in.
    $ranked = array_values($hits);
    usort($ranked, static fn($a, $b) => ($b['__score'] ?? 0) <=> ($a['__score'] ?? 0));

    $out = [];
    foreach (array_slice($ranked, 0, $wanted) as $hit) {
        unset($hit['__score']);
        $out[] = bboah_detail($base, $hit, $params);
        metadata_rate_limit('bboah', (float) ($params['min_delay'] ?? 1.0));
    }
    return [$out, null];
}

/**
 * One product, filled in from its own page where that page is legible.
 *
 * Two things are read, and only two, because both are unambiguous on the real
 * pages: the <title>, which is "Product - Manufacturer", and the photographs,
 * which live under a fixed path. Everything else on that page is prose in markup
 * I have not inspected, and a parser written against a guess is worse than a
 * field left empty - the field says "not known", the guess says something wrong.
 */
function bboah_detail(string $base, array $hit, array $params): array
{
    $candidate = [
        'title'    => (string) $hit['title'],
        'url'      => (string) $hit['url'],
        'platform' => 'Amiga',
        'images'   => [],
    ];
    if (($hit['category'] ?? null) !== null) {
        $candidate['category_hint'] = (string) $hit['category'];
    }

    [$body] = metadata_http_get((string) $hit['url'], [], (int) ($params['timeout'] ?? 20));
    if ($body === null) {
        return $candidate;
    }

    // "ACA-500+ - Individual Computers". The separator is a hyphen with spaces
    // either side, and product names contain hyphens without them, so the split
    // is on the spaced one only.
    if (preg_match('#<title>(.*?)</title>#is', $body, $m) === 1) {
        $pageTitle = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $parts     = preg_split('/\s+-\s+/u', $pageTitle);
        if (is_array($parts) && count($parts) >= 2) {
            $maker = trim((string) array_pop($parts));
            $name  = trim(implode(' - ', $parts));
            if ($name !== '') {
                $candidate['title'] = $name;
            }
            if ($maker !== '' && mb_strtolower($maker) !== 'big book of amiga hardware') {
                $candidate['developer'] = $maker;
            }
        }
    }

    // Photographs sit under one path. The caption is not taken: it is loose text
    // beside the image rather than an alt attribute, and guessing which text
    // belongs to which picture is the kind of rule that quietly attaches the
    // wrong words to the wrong board.
    // Matched on the path segment, not on a leading slash.
    //
    // The pages are at /bboah/product.aspx and their images are written
    // *relative* to that - `media/display_photos/a2000_1_sm.jpg`, with no
    // /bboah/ in front. My first pattern required the absolute path, so it found
    // nothing on a page covered in photographs. I had only ever seen those
    // addresses after a fetcher had already resolved them for me, which is
    // exactly the sort of thing that reads as confirmation and is not.
    //
    // Two folders, both real: the strip at the top of the page is under
    // display_photos, and pictures inside the write-up are under raw.
    if (preg_match_all('#(?:src|href)\s*=\s*["\']([^"\']*(?:display_photos|/raw|^raw)/[^"\']+\.(?:jpe?g|png|gif))["\']#i',
                       $body, $mm) === false) {
        return $candidate;
    }
    $seen = [];
    foreach (($mm[1] ?? []) as $src) {
        $url = bboah_absolute_url($base, $src);
        if ($url === null || isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $candidate['images'][] = ['url' => $url, 'kind' => 'unit'];
    }

    // The specification table, which is the whole point of the page: Processor,
    // Chip RAM, Expansion Slots, Drive Bays and the rest, as label/value rows.
    // Read as any two-cell row whose first cell ends in a colon, so it does not
    // depend on a class name that could be renamed tomorrow.
    $specs = bboah_spec_table($body);
    if ($specs !== []) {
        $candidate['specs'] = $specs;
    }
    return $candidate;
}


/**
 * The forms a machine's name is written in, for matching a listing.
 *
 * "Amiga 2000" and "A2000" are the same computer, and the Big Book files it
 * under the second. A search for the first found nothing at all - the match was
 * plain containment, and "A2000" does not contain "amiga 2000".
 *
 * So the term is reduced to letters and digits and then expanded: an "amiga"
 * prefix comes off, and an "A" in front of digits goes on and comes off. That
 * covers A500/Amiga 500, A1200/Amiga 1200 and the rest of the family without
 * knowing anything about any particular model.
 *
 * @return list<string> longest first, so the most specific form is tried first
 */
function bboah_name_forms(string $title): array
{
    $norm = preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($title))) ?? '';
    if ($norm === '') {
        return [];
    }

    // Values, not keys.
    //
    // "2000" as an array key is the integer 2000, and array_keys() then hands
    // back ints that mb_strlen() refuses. This is the identical mistake that
    // crashed the OpenRetro parser on a platform called "2600" - the second time
    // I have written it, in the same file.
    $forms = [$norm];
    if (str_starts_with($norm, 'amiga') && mb_strlen($norm) > 5) {
        $rest = mb_substr($norm, 5);
        $forms[] = $rest;
        if (preg_match('/^\d/', $rest) === 1) {
            $forms[] = 'a' . $rest;
        }
    }
    if (preg_match('/^a(\d.*)$/', $norm, $m) === 1) {
        $forms[] = (string) $m[1];
        $forms[] = 'amiga' . $m[1];
    }

    $out = array_values(array_unique($forms));
    usort($out, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    return $out;
}

/**
 * How well a listing row answers the search, or 0 for not at all.
 *
 * Exact beats prefix beats containment, so "A2000" outranks "A2000HD" for a
 * search for the Amiga 2000, and a bare "500" cannot drag in the A1500 ahead of
 * the A500 it was asked about.
 */
function bboah_match_score(string $rowTitle, array $forms): int
{
    $norm = preg_replace('/[^a-z0-9]+/', '', mb_strtolower($rowTitle)) ?? '';
    if ($norm === '') {
        return 0;
    }
    foreach ($forms as $form) {
        if ($norm === $form) {
            return 3;
        }
    }
    foreach ($forms as $form) {
        if (str_starts_with($norm, $form)) {
            return 2;
        }
    }
    foreach ($forms as $form) {
        // Short forms only count as a prefix, never buried in the middle: "500"
        // inside "A1500" is a different machine.
        if (mb_strlen($form) >= 4 && str_contains($norm, $form)) {
            return 1;
        }
    }
    return 0;
}

/**
 * Photographs from Wikimedia Commons.
 *
 * No key and no quota, which matters more than it sounds: the Google agent next
 * to this one is shut to new sign-ups, so an image source that anybody can turn
 * on today is the one worth having.
 *
 * `generator=search` with `gsrnamespace=6` searches the File namespace and hands
 * back pages that already carry their own imageinfo, so one request does the
 * whole job.
 *
 * Commons is thin on commercial box art - that is a licensing fact about the
 * project, not a fault in this - and rich on photographs of hardware. The
 * declaration says it carries no year, studio or summary, so the review screen
 * does not offer rows that will always be empty.
 */
function metadata_search_commons(array $params, string $title, ?string $remotePlatform): array
{
    $term = trim($title);
    if ($term === '') {
        return [[], null];
    }
    // The machine, where one is known: "Turrican" alone finds a photograph of a
    // Mega Drive cartridge as readily as an Amiga box.
    if ($remotePlatform !== null && trim($remotePlatform) !== '') {
        $term .= ' ' . trim($remotePlatform);
    }

    metadata_rate_limit('commons', (float) ($params['min_delay'] ?? 0.5));
    $wanted = max(1, min(20, (int) ($params['max_results'] ?? 8)));
    $url = rtrim((string) ($params['endpoint'] ?? 'https://commons.wikimedia.org/w/api.php'), '/')
        . '?' . http_build_query([
            'action'       => 'query',
            'generator'    => 'search',
            // filetype:bitmap, so the search never returns a PDF or a video in
            // the first place.
            //
            // Asking for a thumbnail of a 700-page scanned journal makes the API
            // answer "Could not normalize image parameters", and that comes back
            // as a top-level error - so one unrelated PDF in the results failed
            // the entire search. Filtering at the source is better than coping
            // with it afterwards: the PDF was never wanted.
            'gsrsearch'    => $term . ' filetype:bitmap',
            // 6 is the File namespace. Without it the search returns article
            // pages, which have no picture on them at all.
            'gsrnamespace' => 6,
            'gsrlimit'     => $wanted,
            'prop'         => 'imageinfo',
            'iiprop'       => 'url|mime',
            'iiurlwidth'   => 320,
            'format'       => 'json',
            'formatversion' => 2,
        ]);

    [$body, $err] = metadata_http_get($url, ['Accept: application/json'],
                                      (int) ($params['timeout'] ?? 20));
    if ($body === null) {
        return [[], $err];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return [[], 'Commons answered with something that is not JSON.'];
    }
    // A complaint about one file is not a failed search.
    //
    // Commons reports a thumbnail it cannot make as a top-level error naming the
    // file, and treating that as fatal threw away every good picture alongside
    // it. A refusal that names a file is noted and the results are read anyway;
    // anything else - a bad parameter, a service that is down - still stops.
    $topError = (string) ($data['error']['info'] ?? '');
    if ($topError !== '' && !isset($data['query'])) {
        return [[], 'Commons refused: ' . $topError];
    }
    if ($topError !== '') {
        metadata_debug('commons: partial', $topError);
    }

    $images = [];
    foreach (($data['query']['pages'] ?? []) as $page) {
        $info = $page['imageinfo'][0] ?? null;
        if (!is_array($info)) {
            // iiurlwidth is documented to drop imageinfo from some rows, so a
            // page without it is skipped rather than assumed to be broken.
            continue;
        }
        $full = (string) ($info['url'] ?? '');
        if ($full === '' || !preg_match('#^https?://#i', $full)) {
            continue;
        }
        // Only what a browser can draw. Commons holds a great deal of PDF, SVG
        // and TIFF, and the uploader checks bytes rather than believing a
        // filename - so this is a courtesy, not the safeguard.
        $mime = mb_strtolower((string) ($info['mime'] ?? ''));
        if ($mime !== '' && !in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            continue;
        }
        $images[] = [
            'url'       => $full,
            'thumb_url' => (string) ($info['thumburl'] ?? $full),
            'kind'      => 'other',
            // "File:Amiga 500 (1987).jpg" - the File: prefix and the extension
            // are noise on a caption.
            'caption'   => mb_substr(preg_replace('/^File:|\.[a-z0-9]+$/i', '',
                                                  (string) ($page['title'] ?? '')) ?: '', 0, 200) ?: null,
        ];
    }
    if ($images === []) {
        return [[], null];
    }

    return [[[
        'title'  => $title,
        'url'    => 'https://commons.wikimedia.org/w/index.php?search=' . rawurlencode($term)
                  . '&ns6=1',
        'images' => $images,
    ]], null];
}

/**
 * An address on the Big Book, whatever form the page wrote it in.
 *
 * Its pages live at /bboah/product.aspx and write their images relative to
 * that, so `media/display_photos/x.jpg` means `/bboah/media/display_photos/x.jpg`.
 * Absolute and root-relative both turn up too.
 */
function bboah_absolute_url(string $base, string $src): ?string
{
    $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($src === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $src) === 1) {
        return $src;
    }
    if (str_starts_with($src, '//')) {
        return 'https:' . $src;
    }
    if (str_starts_with($src, '/')) {
        return $base . $src;
    }
    // Relative to the page, and every page this reads is under /bboah/.
    return $base . '/bboah/' . ltrim($src, './');
}

/**
 * The "Standard Specifications" table on a product page.
 *
 * Any two-cell row whose first cell ends in a colon - "Processor:", "Drive
 * Bays:" - which is how the site writes them and does not depend on a class
 * name that could be renamed tomorrow.
 *
 * The values contain line breaks on purpose: an A2000 lists four chipsets under
 * "Native Chipset". Those become newlines rather than being run together into a
 * sentence nobody can read.
 *
 * @return array<string,string> label => value
 */
function bboah_spec_table(string $html): array
{
    $out = [];
    if (preg_match_all('#<tr[^>]*>(.*?)</tr>#is', $html, $rows) === false) {
        return $out;
    }
    foreach (($rows[1] ?? []) as $row) {
        if (preg_match_all('#<t[dh][^>]*>(.*?)</t[dh]>#is', $row, $cells) === false) {
            continue;
        }
        if (count($cells[1] ?? []) !== 2) {
            continue;
        }
        $label = bboah_cell_text($cells[1][0]);
        $value = bboah_cell_text($cells[1][1]);
        if ($label === '' || $value === '' || !str_ends_with($label, ':')) {
            continue;
        }
        $label = rtrim(mb_substr($label, 0, mb_strlen($label) - 1));
        if ($label === '' || mb_strlen($label) > 80) {
            continue;
        }
        // First one wins: the page repeats a couple of labels further down in
        // prose tables, and the specification block is at the top.
        if (!isset($out[$label])) {
            $out[$label] = mb_substr($value, 0, 400);
        }
    }
    return $out;
}

/** One table cell as text, with <br> kept as a line break. */
function bboah_cell_text(string $html): string
{
    $text = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s*\n\s*/', "\n", $text) ?? $text;
    return trim($text);
}

/**
 * Does this candidate look like the thing that was searched for?
 *
 * A search for "Deluxe Paint IV" on Wikipedia comes back with *Brilliance* -
 * a different program, related enough to be in the search results and not at all
 * the entry being catalogued. Offered with its fields ticked, one press would
 * have replaced a Deluxe Paint entry's description, reference link and year with
 * another product's.
 *
 * The test has to be tolerant of the same thing written differently, though:
 * "A2000" is the Amiga 2000 and shares no words with it at all. So names are
 * reduced to letters and digits, expanded into the forms a machine is written
 * in, and then compared - by containment first, then by how much of the shorter
 * one appears in the longer.
 *
 * This decides whether to *warn*, not whether to show. A source is allowed to
 * know a release under a name nobody else uses, and hiding the answer would be
 * worse than flagging it.
 */
function metadata_title_resembles(string $query, string $found): bool
{
    $forms = bboah_name_forms($query);
    if ($forms === [] || trim($found) === '') {
        return true;   // nothing to compare; do not cry wolf
    }
    if (bboah_match_score($found, $forms) > 0) {
        return true;
    }

    $a = preg_replace('/[^a-z0-9]+/', '', mb_strtolower($query)) ?? '';
    $b = preg_replace('/[^a-z0-9]+/', '', mb_strtolower($found)) ?? '';
    if ($a === '' || $b === '') {
        return true;
    }
    // Word overlap, for titles that are phrases rather than model numbers:
    // "Jagged Alliance: Deadly Games" against "Jagged Alliance 2".
    $wordsA = array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower($query)) ?: [],
                           static fn($w) => mb_strlen($w) >= 3);
    $wordsB = array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower($found)) ?: [],
                           static fn($w) => mb_strlen($w) >= 3);
    if ($wordsA !== [] && array_intersect($wordsA, $wordsB) !== []) {
        return true;
    }

    // Last resort: mostly the same string. 0.72 keeps "Turrican II" beside
    // "Turrican 2" and refuses "Brilliance" beside "Deluxe Paint IV".
    similar_text($a, $b, $percent);
    return $percent >= 72.0;
}

/**
 * The files an article actually uses, beyond the one at the top.
 *
 * `pageimages` returns a single lead image, and which one that is depends on how
 * the article is laid out - for Deluxe Paint the box art sits in the infobox and
 * may or may not be chosen. `generator=images` lists every file the page uses, so
 * the box scan is there whichever way the article is arranged.
 *
 * The cost is one more request per article, which is why it is capped and why the
 * lead image is kept as well: if this fails or returns nothing, the article still
 * offers its picture.
 *
 * @return array<int,array<string,mixed>>
 */
function wikipedia_page_images(string $base, string $pageTitle, array $params, string $leadUrl): array
{
    metadata_rate_limit('wikipedia', (float) ($params['min_delay'] ?? 1.0));
    $url = $base . '?' . http_build_query([
        'action' => 'query', 'generator' => 'images', 'titles' => $pageTitle,
        'gimlimit' => 20,
        'prop' => 'imageinfo', 'iiprop' => 'url|mime',
        'iiurlwidth' => 480,
        'format' => 'json', 'formatversion' => 2,
    ]);
    [$body, $err] = metadata_http_get($url, ['Accept: application/json'],
                                      (int) ($params['timeout'] ?? 20));
    if ($body === null) {
        metadata_debug('wikipedia: images failed', $pageTitle . ' — ' . $err);
        return [];
    }

    $pages = json_decode((string) $body, true)['query']['pages'] ?? [];
    if (!is_array($pages)) {
        return [];
    }

    // Interface furniture, not illustrations.
    //
    // Every article carries a handful of the same files - the Commons logo, an
    // edit-pencil, a padlock, a stub icon - and offering those beside the box art
    // makes the picture somebody wants harder to find, not easier.
    $chrome = '/(commons-logo|wikimedia|wiki_?letter|question_?book|ambox|edit-clear|'
            . 'symbol_|padlock|folder_hexagonal|nuvola|crystal_|disambig|portal|'
            . 'flag_of|red_pencil|magnify-clip|office-book)/i';

    $out = [];
    foreach ($pages as $page) {
        if (count($out) >= (int) ($params['max_images'] ?? 6)) {
            break;
        }
        $info  = $page['imageinfo'][0] ?? null;
        $title = (string) ($page['title'] ?? '');
        if (!is_array($info) || $title === '' || preg_match($chrome, $title)) {
            continue;
        }
        $full = (string) ($info['url'] ?? '');
        $mime = (string) ($info['mime'] ?? '');
        if ($full === '' || $full === $leadUrl
            || !in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            continue;
        }
        $out[] = [
            'url'       => $full,
            'thumb_url' => (string) ($info['thumburl'] ?? $full),
            'kind'      => 'other',
            // "File:DPVBoxArt.png" - the prefix and extension are noise.
            'caption'   => mb_substr((string) preg_replace('/^File:|\.[a-z0-9]+$/i', '', $title),
                                     0, 200) ?: null,
        ];
    }
    metadata_debug('wikipedia: page images', $pageTitle . ' — ' . count($out));
    return $out;
}

/**
 * The picture at the top of an article, if it has one.
 *
 * One image rather than a gallery: `pageimages` returns the article's lead
 * image, which on a game or a program is the box art or a title screen. It rides
 * on the request the article already makes.
 *
 * Wikipedia serves plenty of non-free images under fair use - a box scan usually
 * is one - so this is offered like any other candidate picture and taken only if
 * somebody ticks it. That is a decision about somebody else's catalogue and not
 * one to make for them silently.
 *
 * @param array<string,mixed> $page the page object from the API
 * @return array<int,array<string,mixed>>
 */
function wikipedia_lead_image(array $page, string $pageTitle): array
{
    // The thumbnail counts as an answer when there is no original.
    //
    // Some responses carry one and not the other, and returning nothing in that
    // case is the same silent nothing that `pilicense` caused - a picture at 480
    // pixels is worth more than no picture, and the review screen says which is
    // being stored either way.
    $full = (string) ($page['original']['source'] ?? '');
    if ($full === '') {
        $full = (string) ($page['thumbnail']['source'] ?? '');
    }
    if ($full === '') {
        return [];
    }
    // Formats the uploader can store. SVG is common on Wikipedia for logos and is
    // not something the image pipeline handles, so it is left alone rather than
    // fetched and rejected.
    if (!preg_match('/\.(jpe?g|png|gif|webp)(\?|$)/i', $full)) {
        metadata_debug('wikipedia: lead image skipped', $full);
        return [];
    }

    return [[
        'url'       => $full,
        'thumb_url' => (string) ($page['thumbnail']['source'] ?? $full),
        // "front" would claim it is a box front, which it might not be - an
        // article's lead image is as often the machine itself or a screenshot.
        'kind'      => 'other',
        'caption'   => mb_substr($pageTitle, 0, 200),
    ]];
}

/**
 * Freely licensed photographs of the same subject, from Commons.
 *
 * The Wikipedia article carries the words and, under fair use, usually a box
 * scan; Commons carries photographs anybody may reuse. They are the same project
 * seen from two sides, and a person looking up Deluxe Paint wants both without
 * having to notice that two sources answered.
 *
 * Reuses the Commons agent rather than a second implementation of the same
 * search - one place to fix when Commons changes its mind about anything.
 *
 * @return array<int,array<string,mixed>>
 */
function wikipedia_commons_images(string $title, ?string $remotePlatform, array $params): array
{
    if (!function_exists('metadata_search_commons')) {
        return [];
    }
    $commonsParams = [
        'endpoint'    => 'https://commons.wikimedia.org/w/api.php',
        'max_results' => (int) ($params['commons_images'] ?? 6),
        'min_delay'   => (float) ($params['min_delay'] ?? 1.0),
        'timeout'     => (int) ($params['timeout'] ?? 20),
    ];
    [$found, $err] = metadata_search_commons($commonsParams, $title, $remotePlatform);
    if ($err !== null || $found === []) {
        metadata_debug('wikipedia: commons', $err ?? 'nothing');
        return [];
    }
    return $found[0]['images'] ?? [];
}
