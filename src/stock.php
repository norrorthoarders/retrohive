<?php
declare(strict_types=1);

/**
 * The pictures an entry gets when nobody has given it one.
 *
 * A catalogue with no photographs in it is a wall of grey rectangles saying "no
 * photo yet", and most catalogues start that way: a metadata agent finds cover
 * art for the famous releases and nothing at all for the rest, and photographing
 * two hundred boxes is a weekend nobody has. So the shelf reads as empty long
 * after it stopped being empty.
 *
 * These pictures are blank mock-ups of the object itself - a big box, a jewel
 * case, a VHS in its slip, a record half out of its sleeve, a beige desktop, an
 * expansion card. They say nothing about the release, which is the point: they
 * say what shape of thing is on the shelf, which is a true thing this catalogue
 * already knows and was throwing away.
 *
 * Three rules govern the whole feature:
 *
 *   1. A stock picture never stands in front of a real one. Not an uploaded
 *      photograph, not artwork a metadata agent brought in, not a picture set on
 *      the entry's own branch of the category tree. It is the last thing tried.
 *
 *   2. It is chosen from what the entry already says about itself - its kind, and
 *      what it comes on - not stored against it. Nothing is written to the
 *      database, no filename is copied onto a row, and an entry that gains a real
 *      photograph tomorrow simply stops resolving to one. There is no cleanup to
 *      forget to do.
 *
 *   3. The files are part of the package, not part of the collection. They live
 *      in public/stock, are identical on every install, and are never touched by
 *      anything that manages uploads - the orphan sweep cannot eat them, a backup
 *      of public/uploads does not need to carry them, and a redeploy restores
 *      them for free.
 *
 * Hardware has two of its own - a beige desktop and an expansion card. These are
 * a weaker claim than the packaging pictures and worth being honest about: every
 * DVD case really does look like that, whereas no two machines do, so a generic
 * computer says only "this is a computer" rather than describing the object. It
 * earns its place anyway, because the alternative on a hardware shelf was a grey
 * rectangle saying nothing at all, and because the caption on the entry's own
 * page says plainly that it is not a photograph of this copy. Somebody who
 * disagrees turns the whole feature off in settings.
 */

/** The extension every stock file uses. */
const STOCK_IMAGE_EXT = 'webp';

/** The prefix that marks a stored filename as a reference to this catalogue. */
const STOCK_REF_PREFIX = 'stock:';

/**
 * Every picture that ships with the package.
 *
 * The key is the slug, which is also the filename stem and the thing a
 * `stock:` reference names. Labels are what a person picking one from a
 * list should read.
 *
 * @return array<string, array{label: string, note: string}>
 */
function stock_images(): array
{
    return [
        'big_box_games' => [
            'label' => 'Big box, game',
            'note'  => 'The tall cardboard box software came in before jewel cases.',
        ],
        'big_box_applications' => [
            'label' => 'Big box, application',
            'note'  => 'The same box, shaped as productivity software rather than a game.',
        ],
        'vhs_movies' => [
            'label' => 'VHS, slipcase',
            'note'  => 'A tape in the card sleeve a film was sold in.',
        ],
        'vhs_tv_shows' => [
            'label' => 'VHS, box set',
            'note'  => 'The heavier boxed presentation a series came in.',
        ],
        'vhs_media' => [
            'label' => 'VHS, tape only',
            'note'  => 'A bare cassette, for a copy that has lost its case.',
        ],
        'laserdisc_movies' => [
            'label' => 'LaserDisc, sleeve',
            'note'  => 'A twelve-inch disc half out of its gatefold.',
        ],
        'laserdisc_tv_shows' => [
            'label' => 'LaserDisc, series sleeve',
            'note'  => 'The same, in the multi-disc presentation a series used.',
        ],
        'dvd_movies' => [
            'label' => 'DVD, case',
            'note'  => 'The standard keep case.',
        ],
        'dvd_tv_shows' => [
            'label' => 'DVD, box set',
            'note'  => 'A season box with a disc beside it.',
        ],
        'blu_ray_movies' => [
            'label' => 'Blu-ray, case',
            'note'  => 'The slimmer keep case Blu-ray uses.',
        ],
        'blu_ray_tv_shows' => [
            'label' => 'Blu-ray, box set',
            'note'  => 'The boxed multi-disc version.',
        ],
        'cd_music_jewel_case' => [
            'label' => 'CD, jewel case',
            'note'  => 'A disc against its printed sleeve.',
        ],
        'cd_music' => [
            'label' => 'CD, disc only',
            'note'  => 'Bare discs, for a copy with no case.',
        ],
        'vinyl_music' => [
            'label' => 'Vinyl, sleeve',
            'note'  => 'A record half out of a square sleeve.',
        ],
        'cassette_tape_music' => [
            'label' => 'Cassette',
            'note'  => 'A compact cassette, shell and all.',
        ],
        'hardware_computer' => [
            'label' => 'Computer',
            'note'  => 'A beige desktop, monitor and keyboard - the shape rather than the model.',
        ],
        'hardware_peripheral' => [
            'label' => 'Expansion card',
            'note'  => 'A card with a bracket and an edge connector.',
        ],
        'hardware_console' => [
            'label' => 'Console',
            'note'  => 'A low wedge-shaped console with a cartridge slot.',
        ],

        // Cards, by what the card does.
        //
        // The isa_/pci_ in these names describes the picture, not a rule about
        // where it may be used: an ISA sound card and a Zorro sound card are the
        // same silhouette at thumbnail size, and a catalogue that insisted on
        // the difference would need a picture per bus per function and still be
        // wrong about the next machine. The bus in the name is there so somebody
        // choosing one by hand knows what they are looking at.
        'hardware_peripheral_isa_sound_card' => [
            'label' => 'Sound card',
            'note'  => 'A long card with audio jacks on the bracket.',
        ],
        'hardware_peripheral_isa_network_card' => [
            'label' => 'Network card (ISA)',
            'note'  => 'A card with a network socket on the bracket.',
        ],
        'hardware_peripheral_isa_scsi_controller' => [
            'label' => 'SCSI controller',
            'note'  => 'A controller card with a wide internal header.',
        ],
        'hardware_peripheral_isa_ide_controller' => [
            'label' => 'IDE controller',
            'note'  => 'A short controller card with drive headers.',
        ],
        'hardware_peripheral_isa_multi_io_controller' => [
            'label' => 'Multi-I/O controller',
            'note'  => 'A card carrying serial, parallel and drive ports at once.',
        ],
        'hardware_peripheral_pci_graphics_card' => [
            'label' => 'Graphics card',
            'note'  => 'A card with a fan and a display connector.',
        ],
        'hardware_peripheral_pci_compact_graphics_card' => [
            'label' => 'Graphics card (compact)',
            'note'  => 'The same, in a short low-profile shape.',
        ],
        'hardware_peripheral_pci_network_card' => [
            'label' => 'Network card (PCI)',
            'note'  => 'A short card with a single network socket.',
        ],
        'hardware_peripheral_pci_raid_controller' => [
            'label' => 'RAID controller',
            'note'  => 'A storage controller card with multiple drive headers.',
        ],
        'hardware_peripheral_pci_serial_parallel_card' => [
            'label' => 'Serial/parallel card',
            'note'  => 'A short card with serial and parallel ports.',
        ],

        // Things you hold, plug in, or solder in.
        'hardware_peripheral_console_gamepad' => [
            'label' => 'Gamepad',
            'note'  => 'A two-handed pad with a d-pad and face buttons.',
        ],
        'hardware_peripheral_console_joystick' => [
            'label' => 'Joystick',
            'note'  => 'A single-stick base with a fire button.',
        ],
        'hardware_peripheral_console_keyboard' => [
            'label' => 'Keyboard',
            'note'  => 'A full-length keyboard.',
        ],
        'hardware_peripheral_console_mouse' => [
            'label' => 'Mouse',
            'note'  => 'A two-button mouse.',
        ],
        'hardware_peripheral_console_flashcart' => [
            'label' => 'Flash cartridge',
            'note'  => 'A cartridge shell with a card slot in the end.',
        ],
        'hardware_peripheral_monitor' => [
            'label' => 'Monitor',
            'note'  => 'A beige CRT monitor on its stand.',
        ],
        'hardware_peripheral_memory_card' => [
            'label' => 'Memory card',
            'note'  => 'A long board densely populated with RAM chips.',
        ],
        'hardware_peripheral_accelerator_card' => [
            'label' => 'Accelerator card',
            'note'  => 'A processor board with a large chip and a heatsink.',
        ],

        'hardware_peripheral_drive_floppy' => [
            'label' => 'Floppy drive',
            'note'  => 'An external drive box with a 3.5-inch slot.',
        ],
        'hardware_peripheral_drive_zip' => [
            'label' => 'Zip drive',
            'note'  => 'The dark external cartridge drive.',
        ],
        'hardware_peripheral_drive_hard_disk' => [
            'label' => 'Hard drive',
            'note'  => 'A bare 3.5-inch mechanism, lid and connectors showing.',
        ],
        'hardware_peripheral_drive_optical' => [
            'label' => 'Optical drive',
            'note'  => 'A tray-loading drive box. A CD-ROM, a DVD-ROM and a writer are the same object from the front.',
        ],
        'hardware_peripheral_drive_cassette' => [
            'label' => 'Tape drive',
            'note'  => 'A cassette recorder of the kind a home computer loaded from.',
        ],

        'hardware_peripheral_console_chipmod' => [
            'label' => 'Modchip',
            'note'  => 'A small bare board meant to be fitted inside a machine.',
        ],
    ];
}

/**
 * Which picture a given kind of thing, on a given format, should get.
 *
 * Ordered, first match wins, read top to bottom. `format` is one of the tokens
 * stock_format_of() produces; `loose` means the entry says it has no case, which
 * is the only thing that distinguishes a bare tape from a boxed one and is the
 * whole reason the two "media only" pictures exist.
 *
 * A rule with `loose` absent matches either way, so the loose variants must sit
 * above their packaged siblings.
 *
 * @return list<array{role: string, format?: string, loose?: bool, image: string}>
 */
function stock_image_rules(): array
{
    return [
        // Films.
        ['role' => 'movie',   'format' => 'vhs',       'loose' => true,  'image' => 'vhs_media'],
        ['role' => 'movie',   'format' => 'vhs',                         'image' => 'vhs_movies'],
        ['role' => 'movie',   'format' => 'laserdisc',                   'image' => 'laserdisc_movies'],
        ['role' => 'movie',   'format' => 'bluray',                      'image' => 'blu_ray_movies'],
        ['role' => 'movie',   'format' => 'dvd',                         'image' => 'dvd_movies'],

        // Series. Same formats, different packaging - a season came in a box,
        // a film came in a case, and they genuinely do not look alike.
        ['role' => 'tv_show', 'format' => 'vhs',       'loose' => true,  'image' => 'vhs_media'],
        ['role' => 'tv_show', 'format' => 'vhs',                         'image' => 'vhs_tv_shows'],
        ['role' => 'tv_show', 'format' => 'laserdisc',                   'image' => 'laserdisc_tv_shows'],
        ['role' => 'tv_show', 'format' => 'bluray',                      'image' => 'blu_ray_tv_shows'],
        ['role' => 'tv_show', 'format' => 'dvd',                         'image' => 'dvd_tv_shows'],

        // Records.
        ['role' => 'music',   'format' => 'cd',        'loose' => true,  'image' => 'cd_music'],
        ['role' => 'music',   'format' => 'cd',                          'image' => 'cd_music_jewel_case'],
        ['role' => 'music',   'format' => 'vinyl',                       'image' => 'vinyl_music'],
        ['role' => 'music',   'format' => 'cassette',                    'image' => 'cassette_tape_music'],
    ];
}

/**
 * What a kind of thing gets when its format is unknown or has no picture of
 * its own - a C64 game on cassette, a PC application on CD-ROM, an entry
 * somebody filed before saying what it came on.
 *
 * Software has one picture per kind rather than one per format on purpose. The
 * big box is what boxed software looked like across every platform and decade
 * this catalogue covers, and the alternative - a jewel case for the CD-ROM era,
 * a cassette inlay for the C64 era - would be four more pictures to draw a
 * distinction nobody browsing a shelf is asking about.
 *
 * Machines and peripherals get one apiece and no format rules above them, for
 * the reason in the note at the top.
 *
 * @return array<string, string>
 */
function stock_image_fallbacks(): array
{
    return [
        'game'        => 'big_box_games',
        'application' => 'big_box_applications',
        'movie'       => 'dvd_movies',
        'tv_show'     => 'dvd_tv_shows',
        'music'       => 'cd_music_jewel_case',
        // One each, with no format rules above them: hardware has no packaging
        // axis to vary on. What a machine "comes on" is not a question, and the
        // media tokens a film or a record is matched by mean nothing here - so
        // these are reached by kind alone and every machine gets the same
        // picture, which is the honest limit of what a generic image can say.
        'machine'     => 'hardware_computer',
        'peripheral'  => 'hardware_peripheral',
    ];
}

/**
 * The patterns that turn what somebody typed into a format token.
 *
 * Ordered most specific first, because the short ones are substrings of the
 * long ones - "CD-ROM" contains "CD", "VHS tape" contains "tape" - and the
 * first list that would have matched wrongly is the one this ordering exists
 * to prevent. Matched as whole words against a normalised string, so "ld"
 * matches "LD" and not "held".
 *
 * @return list<array{token: string, words: list<string>}>
 */
function stock_format_patterns(): array
{
    return [
        ['token' => 'bluray',    'words' => ['blu ray', 'bluray', 'blu-ray', 'bd', 'bd r', 'uhd', 'ultra hd']],
        ['token' => 'laserdisc', 'words' => ['laserdisc', 'laser disc', 'ld', 'cav', 'clv']],
        ['token' => 'vhs',       'words' => ['vhs', 's vhs', 'video cassette', 'videocassette', 'video tape']],
        ['token' => 'dvd',       'words' => ['dvd', 'dvd video', 'dvd r', 'dvd rom']],
        ['token' => 'vinyl',     'words' => ['vinyl', 'lp', 'ep', 'record', '7 inch', '10 inch', '12 inch',
                                             '33 rpm', '45 rpm', 'single']],
        // Deliberately below vinyl and above plain cd: a CD-ROM is software, and
        // the music rules must not claim it.
        ['token' => 'cdrom',     'words' => ['cd rom', 'cdrom']],
        ['token' => 'cd',        'words' => ['cd', 'compact disc', 'cd audio', 'cdda', 'cd single']],
        ['token' => 'cassette',  'words' => ['cassette', 'musicassette', 'tape', 'compact cassette']],
    ];
}

/**
 * Reduce everything an entry says about its format to one token.
 *
 * Reads, in order of how much it can be trusted: the media rows, which somebody
 * entered per medium and which are the only place a multi-format release is
 * described properly; the free-text media_type column, which is what older
 * entries have; and finally the platform, which for the video and music sections
 * *is* the format - the Blu-ray platform means Blu-ray and nothing else.
 *
 * @param list<string> $media  Medium names from item_media, if they are to hand.
 */
function stock_format_of(array $row, array $media = []): ?string
{
    $haystack = [];
    foreach ($media as $m) {
        $haystack[] = (string) $m;
    }
    if (!empty($row['media_type'])) {
        $haystack[] = (string) $row['media_type'];
    }
    if (!empty($row['platform_slug'])) {
        $haystack[] = (string) $row['platform_slug'];
    }
    if ($haystack === []) {
        return null;
    }

    // One normalised string: lower case, every separator a space. "Blu-ray disc"
    // and "blu_ray" and "BLU RAY" are the same three characters apart, and the
    // alternative is a pattern list three times as long saying so.
    $flat = ' ' . preg_replace('/[^a-z0-9]+/', ' ', strtolower(implode(' ', $haystack))) . ' ';
    $flat = (string) preg_replace('/\s+/', ' ', $flat);

    foreach (stock_format_patterns() as $pattern) {
        foreach ($pattern['words'] as $word) {
            if (str_contains($flat, ' ' . $word . ' ')) {
                return $pattern['token'];
            }
        }
    }
    return null;
}

/**
 * Does this entry say it has no case?
 *
 * Only `loose` counts. "Unknown" is not a claim that the box is missing - most
 * entries never have this field touched - and treating it as one would put a
 * bare tape against half the video shelf.
 */
function stock_is_loose(array $row): bool
{
    return ($row['completeness'] ?? '') === 'loose';
}

/**
 * The stock picture this entry should show, as a `stock:` reference, or null.
 *
 * Returns a reference rather than a path so it can be handed to image_url()
 * alongside a real filename and behave the same way - which is what keeps the
 * three-step fallback in item_to_api() down to a single expression.
 *
 * @param list<string> $media  Medium names from item_media, if they are to hand.
 */
function stock_image_for_item(array $row, array $media = []): ?string
{
    if (!stock_images_enabled()) {
        return null;
    }

    // The entry's own node may be a leaf with no opinion - "Platformer" says
    // role 'other' - so this is the same walk up the branch that decides what
    // kind of thing an entry is everywhere else.
    $role = null;
    if (!empty($row['category_id'])) {
        $role = category_effective_role((int) $row['category_id']);
    } elseif (!empty($row['category_role'])) {
        $role = (string) $row['category_role'];
    }
    if ($role === null || $role === '') {
        return null;
    }

    // What the branch itself declares, if anything, walking up to the nearest
    // ancestor that does.
    //
    // Above the format rules rather than below them, and that is the point of
    // the whole column: a branch saying "things filed here are ISA sound cards"
    // knows more than any rule reading a media string ever could. It is how the
    // taxonomy gets to answer a question the taxonomy is the right thing to
    // answer - and how a picture is added for a new kind of thing by editing a
    // JSON file rather than by adding a case to stock_image_rules().
    //
    // Still below a curator's uploaded picture for the branch, which is checked
    // before this function is ever called. See item_to_api().
    if (!empty($row['category_id'])) {
        $declared = category_effective_stock_image((int) $row['category_id']);
        if ($declared !== null) {
            return STOCK_REF_PREFIX . $declared;
        }
    }

    $format = stock_format_of($row, $media);
    $loose  = stock_is_loose($row);

    if ($format !== null) {
        foreach (stock_image_rules() as $rule) {
            if ($rule['role'] !== $role || ($rule['format'] ?? null) !== $format) {
                continue;
            }
            if (array_key_exists('loose', $rule) && $rule['loose'] !== $loose) {
                continue;
            }
            return STOCK_REF_PREFIX . $rule['image'];
        }
    }

    $fallback = stock_image_fallbacks()[$role] ?? null;
    return $fallback === null ? null : STOCK_REF_PREFIX . $fallback;
}

/**
 * Is a stored image reference one of ours?
 */
function is_stock_ref(?string $value): bool
{
    return $value !== null && str_starts_with($value, STOCK_REF_PREFIX);
}

/**
 * The slug out of a `stock:` reference, if it names a picture that exists.
 *
 * Checked against the catalogue rather than the filesystem, and never against
 * anything the caller supplied directly, so a reference that arrived from the
 * database cannot become a path.
 */
function stock_ref_slug(?string $value): ?string
{
    if (!is_stock_ref($value)) {
        return null;
    }
    $slug = substr((string) $value, strlen(STOCK_REF_PREFIX));
    return isset(stock_images()[$slug]) ? $slug : null;
}

/**
 * Where a stock picture is served from. Not under /uploads: these are shipped
 * files, and putting them there would mean every tool that reasons about the
 * uploads directory had to learn about an exception.
 */
function stock_image_url(string $slug, string $variant = 'display'): ?string
{
    if (!isset(stock_images()[$slug])) {
        return null;
    }
    $prefix = $variant === 'thumb' ? 'thumb_' : '';
    return BASE_PATH . '/stock/' . rawurlencode($prefix . $slug) . '.' . STOCK_IMAGE_EXT;
}

/** The file on disk, for the installer's own check that the package is complete. */
function stock_image_path(string $slug, string $variant = 'display'): string
{
    $prefix = $variant === 'thumb' ? 'thumb_' : '';
    return APP_ROOT . '/public/stock/' . $prefix . $slug . '.' . STOCK_IMAGE_EXT;
}

/**
 * Whether this instance wants them at all.
 *
 * On by default, because the whole point is that a fresh install looks like a
 * catalogue rather than a spreadsheet. Somebody who would rather see honest
 * blanks - and there is a real argument for it, since a stock picture is a
 * picture of a category and not of the thing - turns it off here and every
 * entry goes back to saying "no photo yet".
 */
function stock_images_enabled(): bool
{
    static $on = null;
    if ($on === null) {
        // Anything before the settings table exists - the installer, a CLI tool
        // running against a half-built database - gets the default rather than
        // an exception.
        try {
            $on = setting('stock_images', '1') !== '';
        } catch (Throwable $e) {
            $on = true;
        }
    }
    return $on;
}

/**
 * What the catalogue looks like over the wire, for a client offering the list
 * as something to pick a branch's default picture from.
 *
 * @return list<array{slug: string, label: string, note: string, thumb: string, display: string}>
 */
function stock_images_to_api(): array
{
    $out = [];
    foreach (stock_images() as $slug => $meta) {
        $out[] = [
            'slug'    => $slug,
            'label'   => $meta['label'],
            'note'    => $meta['note'],
            'thumb'   => absolute_url(stock_image_url($slug, 'thumb')),
            'display' => absolute_url(stock_image_url($slug, 'display')),
        ];
    }
    return $out;
}
