<?php
declare(strict_types=1);

function uploads_dir(): string
{
    $dir = config('uploads.dir');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * A filename nothing else in the uploads directory is using.
 *
 * Every generated name already carried the row id plus five or six random bytes, so
 * two vendors both called "Commodore" in different libraries were never in danger
 * of colliding - they are different rows with different ids. But "vanishingly
 * unlikely" is a different claim from "cannot happen", and the check costs one
 * stat() on a path we are about to write to anyway.
 *
 * Checks the variant prefixes too. A name is only free if thumb_ and disp_ are also
 * free, or a new upload could overwrite an existing photo's thumbnail and leave the
 * original pointing at somebody else's picture.
 */
function unique_upload_name(string $stem, string $ext): string
{
    $dir = uploads_dir();
    for ($try = 0; $try < 8; $try++) {
        $name = $stem . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $free = true;
        foreach (['', 'thumb_', 'disp_'] as $prefix) {
            if (file_exists($dir . '/' . $prefix . $name)) {
                $free = false;
                break;
            }
        }
        if ($free) {
            return $name;
        }
    }
    // Eight collisions on 64 random bits is not something that happens; if it
    // somehow did, the time is what makes the next attempt different.
    return $stem . '_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
}

/**
 * Normalise PHP's awkward multi-file $_FILES structure into a flat list.
 */
function normalise_files(string $field): array
{
    if (!isset($_FILES[$field])) {
        return [];
    }
    $f = $_FILES[$field];
    if (!is_array($f['name'])) {
        return [$f];
    }
    $out = [];
    foreach (array_keys($f['name']) as $i) {
        $out[] = [
            'name'     => $f['name'][$i],
            'type'     => $f['type'][$i],
            'tmp_name' => $f['tmp_name'][$i],
            'error'    => $f['error'][$i],
            'size'     => $f['size'][$i],
        ];
    }
    return $out;
}

/**
 * Shared gate for anything uploaded as an image.
 * Returns [info, extension, error] where info is the getimagesize() result.
 */
function validate_uploaded_image(array $file): array
{
    $allowed = config('uploads.allowed');
    $maxSize = (int) config('uploads.max_bytes');
    $label   = ($file['name'] ?? '') !== '' ? $file['name'] : 'file';

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        return [null, null, "$label could not be uploaded (PHP error code {$file['error']})."];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, null, "$label was rejected: not a real upload."];
    }
    if ((int) $file['size'] > $maxSize) {
        return [null, null, sprintf('%s is %.1f MB, over the %.0f MB limit.', $label, $file['size'] / 1048576, $maxSize / 1048576)];
    }

    // Decided by inspecting the file, never by trusting its name or the
    // Content-Type the browser claimed.
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return [null, null, "$label is not an image PHP can read."];
    }
    $mime = $info['mime'] ?? '';
    if (!isset($allowed[$mime])) {
        return [null, null, "$label uses $mime, which is not accepted. Use JPEG, PNG, WebP or GIF."];
    }

    // Bytes are not the constraint that matters here. GD decodes to roughly four
    // bytes a pixel regardless of how well the file compressed, so a 30000x30000
    // PNG of flat colour arrives well inside the size limit and then asks for
    // 3.6 GB the moment make_variants() opens it. Refuse on pixels as well.
    $pixels = (int) ($info[0] ?? 0) * (int) ($info[1] ?? 0);
    $maxPixels = (int) config('uploads.max_pixels', 80000000);
    if ($pixels > $maxPixels) {
        return [null, null, sprintf(
            '%s is %d x %d, which is %.0f megapixels - over the %.0f megapixel limit. '
            . 'Scale it down before uploading.',
            $label, (int) $info[0], (int) $info[1], $pixels / 1e6, $maxPixels / 1e6
        )];
    }

    return [$info, $allowed[$mime], null];
}

/**
 * Store a company logo, replacing any previous one.
 * Returns [filename, error].
 */
function store_company_logo(int $companyId, string $field): array
{
    if (!isset($_FILES[$field]) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }

    [$info, $ext, $error] = validate_uploaded_image($_FILES[$field]);
    if ($error !== null) {
        return [null, $error];
    }

    $basename = unique_upload_name('logo_companies_' . $companyId, $ext);
    $target   = uploads_dir() . '/' . $basename;

    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        return [null, 'The logo could not be written to the uploads directory. Check permissions.'];
    }
    @chmod($target, 0644);

    // Logos are shown small, so only the thumbnail variant is worth generating.
    resize_image($target, uploads_dir() . '/thumb_' . $basename, (string) $info['mime'], (int) config('uploads.thumb_px'));

    delete_company_logo($companyId);

    update_row('companies', $companyId, ['logo_filename' => $basename]);
    return [$basename, null];
}

function delete_company_logo(int $companyId): void
{
    $row = one('SELECT logo_filename FROM companies WHERE id = ?', [$companyId]);
    if ($row === null || empty($row['logo_filename'])) {
        return;
    }
    foreach (['', 'thumb_'] as $prefix) {
        $path = uploads_dir() . '/' . $prefix . $row['logo_filename'];
        if (is_file($path)) {
            @unlink($path);
        }
    }
    update_row('companies', $companyId, ['logo_filename' => null]);
}

/**
 * Store one of the instance's own logos, replacing any previous one.
 *
 * `$which` is 'small' for the header wordmark or 'large' for the sign-in page.
 * Two files rather than one scaled twice: a mark that reads at 24 pixels beside
 * a menu is rarely the same drawing as one that carries a sign-in page, and
 * making somebody use the same image for both means one of the two is wrong.
 *
 * Kept in the uploads directory with everything else, named so it cannot collide
 * with a company logo or an avatar. The filename goes in `settings`, which is
 * where the rest of the instance's own choices live.
 *
 * Returns [filename, error].
 */
function store_instance_logo(string $which, string $field): array
{
    $which = $which === 'large' ? 'large' : 'small';
    if (!isset($_FILES[$field]) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }

    [$info, $ext, $error] = validate_uploaded_image($_FILES[$field]);
    if ($error !== null) {
        return [null, $error];
    }

    $basename = unique_upload_name('logo_instance_' . $which, $ext);
    $target   = uploads_dir() . '/' . $basename;

    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        return [null, 'The logo could not be written to the uploads directory. Check permissions.'];
    }
    @chmod($target, 0644);

    // A thumbnail for the small one, which is all it is ever shown at. The large
    // one is used at its own size on the sign-in page, so scaling it down and
    // then displaying it big would throw away the reason somebody uploaded it.
    if ($which === 'small') {
        resize_image($target, uploads_dir() . '/thumb_' . $basename, (string) $info['mime'], (int) config('uploads.thumb_px'));
    }

    delete_instance_logo($which);
    set_setting('logo_' . $which, $basename);
    return [$basename, null];
}

function delete_instance_logo(string $which): void
{
    $which = $which === 'large' ? 'large' : 'small';
    $name  = (string) setting('logo_' . $which, '');
    if ($name === '') {
        return;
    }
    foreach (['', 'thumb_'] as $prefix) {
        $path = uploads_dir() . '/' . $prefix . $name;
        if (is_file($path)) {
            @unlink($path);
        }
    }
    set_setting('logo_' . $which, null);
}

/**
 * Store a profile picture, replacing any previous one.
 * Returns [filename, error].
 */
/**
 * Whether this account's next avatar waits for an administrator.
 *
 * Off unless the instance says otherwise, and never for an administrator - they
 * are the ones who would be approving it, and a queue you approve your own
 * entries in is a formality rather than a check.
 */
function avatar_approval_required(?array $user = null): bool
{
    $user = $user ?? acting_user();
    if ($user === null || is_admin_user($user)) {
        return false;
    }
    try {
        return setting('avatar_approval', '') !== '';
    } catch (Throwable $e) {
        // Before the settings table exists - an installer, a CLI tool against a
        // half-built database - the answer is the permissive one.
        return false;
    }
}

/**
 * Whether a photograph uploaded to this library waits for somebody to approve
 * it.
 *
 * Off unless the library says otherwise. Exempt: an instance administrator, and
 * anybody who curates the library itself - again, the people who would be
 * approving it. A contributor or an ordinary member is who this is for.
 */
function library_photo_approval_required(int $libraryId, ?array $user = null): bool
{
    $user = $user ?? acting_user();
    if ($user === null || is_admin_user($user)) {
        return false;
    }
    if ((int) scalar('SELECT photo_approval FROM libraries WHERE id = ?', [$libraryId]) !== 1) {
        return false;
    }
    return !can_structure_library($libraryId);
}

function store_user_avatar(int $userId, string $field): array
{
    if (!isset($_FILES[$field]) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }

    [$info, $ext, $error] = validate_uploaded_image($_FILES[$field]);
    if ($error !== null) {
        return [null, $error];
    }

    $basename = unique_upload_name('avatar_' . $userId, $ext);
    $target   = uploads_dir() . '/' . $basename;

    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        return [null, 'The picture could not be written to the uploads directory. Check permissions.'];
    }
    @chmod($target, 0644);

    // The part of the picture that is actually the avatar.
    //
    // An avatar is a circle cut out of the middle of whatever was uploaded, and
    // the middle of a photograph is very often not the face - so the form lets
    // somebody move a square over the picture and this crops to it. Absent or
    // unusable, nothing is cropped and the picture is used whole, which is what
    // happened before and is what happens without JavaScript.
    $crop = avatar_crop_from_post();
    if ($crop !== null) {
        crop_image_square($target, (string) $info['mime'], $crop[0], $crop[1], $crop[2]);
    }

    // Only the small variant is worth keeping; avatars are never shown large.
    resize_image($target, uploads_dir() . '/thumb_' . $basename, (string) $info['mime'], (int) config('uploads.thumb_px'));

    if (avatar_approval_required(one('SELECT id, role FROM users WHERE id = ?', [$userId]))) {
        // The picture that is up stays up.
        //
        // What is pending is the change, not the removal - somebody waiting a
        // day for an administrator should not spend that day as a set of
        // initials, and reverting them to one would be a second change nobody
        // asked for. Only a previous *pending* picture is cleared, since two
        // waiting at once is one too many and the newer is the one meant.
        delete_user_pending_avatar($userId);
        update_row('users', $userId, [
            'avatar_pending_filename' => $basename,
            'avatar_pending_at'       => date('Y-m-d H:i:s'),
        ]);
        return [$basename, null];
    }

    delete_user_avatar($userId);
    update_row('users', $userId, ['avatar_filename' => $basename]);

    return [$basename, null];
}

/** The waiting picture's files, and the columns naming it. */
function delete_user_pending_avatar(int $userId): void
{
    $row = one('SELECT avatar_pending_filename FROM users WHERE id = ?', [$userId]);
    if ($row === null || empty($row['avatar_pending_filename'])) {
        return;
    }
    foreach (['', 'thumb_'] as $prefix) {
        $path = uploads_dir() . '/' . $prefix . $row['avatar_pending_filename'];
        if (is_file($path)) {
            @unlink($path);
        }
    }
    update_row('users', $userId, [
        'avatar_pending_filename' => null,
        'avatar_pending_at'       => null,
    ]);
}

/** Yes: the waiting picture becomes the avatar, and the old one's files go. */
function approve_user_avatar(int $userId): bool
{
    $row = one('SELECT avatar_pending_filename FROM users WHERE id = ?', [$userId]);
    if ($row === null || empty($row['avatar_pending_filename'])) {
        return false;
    }
    $pending = (string) $row['avatar_pending_filename'];
    // The old files, not the pending ones - delete_user_avatar() reads the
    // avatar_filename column, which still names the picture being replaced.
    delete_user_avatar($userId);
    update_row('users', $userId, [
        'avatar_filename'         => $pending,
        'avatar_pending_filename' => null,
        'avatar_pending_at'       => null,
    ]);
    return true;
}

function delete_user_avatar(int $userId): void
{
    $row = one('SELECT avatar_filename FROM users WHERE id = ?', [$userId]);
    if ($row === null || empty($row['avatar_filename'])) {
        return;
    }
    foreach (['', 'thumb_'] as $prefix) {
        $path = uploads_dir() . '/' . $prefix . $row['avatar_filename'];
        if (is_file($path)) {
            @unlink($path);
        }
    }
    update_row('users', $userId, ['avatar_filename' => null]);
}

/**
 * What a category's own entries look like with no photograph of their
 * own - a client for the same upload machinery store_user_avatar()
 * already uses, storing to categories.default_image_filename instead
 * of users.avatar_filename. No crop: an avatar is cut to a circle
 * because it is shown small and round everywhere; this is shown as a
 * whole cover image the same way a real photograph would be, so
 * cropping it would be solving a problem this one does not have.
 */
function store_category_default_image(int $categoryId, string $field): array
{
    if (!isset($_FILES[$field]) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }

    [$info, $ext, $error] = validate_uploaded_image($_FILES[$field]);
    if ($error !== null) {
        return [null, $error];
    }

    $basename = unique_upload_name('category_default_' . $categoryId, $ext);
    $target   = uploads_dir() . '/' . $basename;

    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        return [null, 'The picture could not be written to the uploads directory. Check permissions.'];
    }
    @chmod($target, 0644);

    resize_image($target, uploads_dir() . '/thumb_' . $basename, (string) $info['mime'], (int) config('uploads.thumb_px'));

    delete_category_default_image($categoryId);
    update_row('categories', $categoryId, ['default_image_filename' => $basename]);

    return [$basename, null];
}

/**
 * Point a branch at one of the pictures that ship with the package, instead of
 * uploading one.
 *
 * Stored in the same column as an uploaded filename, as a `stock:` reference.
 * That keeps one column, one inheritance walk and one delete path rather than a
 * second nullable column and a rule about which wins - and image_url() already
 * resolves both, so nothing downstream has to know which kind it is holding.
 */
function set_category_stock_image(int $categoryId, string $slug): ?string
{
    if (!isset(stock_images()[$slug])) {
        return 'No stock picture is called that.';
    }
    delete_category_default_image($categoryId);
    update_row('categories', $categoryId, ['default_image_filename' => STOCK_REF_PREFIX . $slug]);
    return null;
}

function delete_category_default_image(int $categoryId): void
{
    $row = one('SELECT default_image_filename FROM categories WHERE id = ?', [$categoryId]);
    if ($row === null || empty($row['default_image_filename'])) {
        return;
    }
    // A reference is not a file. Unlinking one would either do nothing or,
    // worse, resolve somewhere unintended - so the reference is simply
    // forgotten and the shipped file left exactly where it is, for every other
    // branch still pointing at it.
    if (!is_stock_ref((string) $row['default_image_filename'])) {
        foreach (['', 'thumb_'] as $prefix) {
            $path = uploads_dir() . '/' . $prefix . $row['default_image_filename'];
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
    update_row('categories', $categoryId, ['default_image_filename' => null]);
}

/**
 * Validate and store every uploaded photo for an item.
 * Returns [storedCount, errors[]].
 */
function store_item_images(int $itemId, string $field, string $kind = 'other',
                           string $provenance = 'personal'): array
{
    // Personal unless told otherwise, and an upload form can only say
    // 'official' by naming a section that is official. Defaulting the other way
    // would mean a mistake put somebody's own photograph among the publisher's
    // artwork, which is the direction that misrepresents it.
    $provenance = $provenance === 'official' ? 'official' : 'personal';

    $files  = normalise_files($field);
    // One caption per file, in the order the browser sent them. A file the
    // browser rejected never reaches here, so the two lists stay in step; a
    // missing caption is simply absent rather than shifting the rest.
    $captions = array_values((array) ($_POST['image_captions'] ?? []));
    // And one kind per file, same rule. The batch select is the default; a photo that
    // says what it is overrides it, so a box, a disc and a manual can go up together.
    $kinds = array_values((array) ($_POST['image_kinds'] ?? []));
    $stored = 0;
    $errors = [];

    $existing = (int) scalar('SELECT COUNT(*) FROM item_images WHERE item_id = ?', [$itemId]);

    foreach ($files as $i => $file) {
        if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $caption  = trim((string) ($captions[$i] ?? ''));
        $thisKind = (string) ($kinds[$i] ?? '');
        $thisKind = in_array($thisKind, image_kind_options(), true) ? $thisKind : $kind;
        [$info, $ext, $error] = validate_uploaded_image($file);
        if ($error !== null) {
            $errors[] = $error;
            continue;
        }
        $mime     = (string) $info['mime'];
        $basename = unique_upload_name((string) $itemId, $ext);
        $target   = uploads_dir() . '/' . $basename;

        $label = ($file['name'] ?? '') !== '' ? (string) $file['name'] : 'The photo';

        // A batch upload from a phone produces the same shot twice constantly.
        // Hashing before the move means a duplicate costs nothing.
        $hash = hash_file('sha256', $file['tmp_name']);
        if ($hash !== false && one('SELECT id FROM item_images WHERE item_id = ? AND content_hash = ?', [$itemId, $hash]) !== null) {
            $errors[] = "$label is already attached to this entry.";
            continue;
        }

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            // $label used to be read here from validate_uploaded_image()'s
            // scope, where it is local - so the one error a person most needs
            // to understand arrived as " could not be written...".
            $errors[] = "$label could not be written to the uploads directory. Check permissions.";
            continue;
        }
        @chmod($target, 0644);

        make_variants($target, $basename, $mime);

        $existing++;
        $stored++;
        insert_row('item_images', [
            'item_id'       => $itemId,
            'filename'      => $basename,
            'original_name' => mb_substr((string) $file['name'], 0, 255),
            'content_hash'  => $hash === false ? null : $hash,
            'kind'          => $thisKind,
            'provenance'    => $provenance,
            'caption'       => $caption === '' ? null : mb_substr($caption, 0, 255),
            'width'         => (int) ($info[0] ?? 0),
            'height'        => (int) ($info[1] ?? 0),
            'filesize'      => (int) $file['size'],
            'is_primary'    => $existing === 1 ? 1 : 0,
            'sort_order'    => $existing * 10,
        ]);
    }

    ensure_primary_image($itemId);

    return [$stored, $errors];
}

/**
 * Write a thumb_ and disp_ variant next to the original.
 * Silently skips if GD is unavailable - the original still displays.
 */
function make_variants(string $path, string $basename, string $mime): void
{
    if (!function_exists('imagecreatetruecolor')) {
        return;
    }
    $variants = [
        'thumb_' => (int) config('uploads.thumb_px'),
        'disp_'  => (int) config('uploads.display_px'),
    ];
    foreach ($variants as $prefix => $maxEdge) {
        resize_image($path, uploads_dir() . '/' . $prefix . $basename, $mime, $maxEdge);
    }
}

/**
 * Does this GIF have more than one frame?
 *
 * Counted by scanning for Graphic Control Extension blocks (0x21 0xF9), which is
 * how a GIF marks each frame's timing. Two or more means animation. Reading the
 * bytes rather than trusting the extension, and cheap enough to do on upload: a
 * few hundred kilobytes scanned once.
 */
function gif_is_animated(string $path): bool
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return false;
    }
    $frames = 0;
    $tail   = '';
    while (!feof($fh) && $frames < 2) {
        $chunk = (string) fread($fh, 65536);
        if ($chunk === '') {
            break;
        }
        // Carry one byte over, so a marker split across two reads is still seen.
        $frames += preg_match_all('/\x21\xF9/', $tail . $chunk);
        $tail = substr($chunk, -1);
    }
    fclose($fh);
    return $frames > 1;
}

/**
 * Copy the original in place of a resized variant.
 *
 * For an animated GIF, which GD cannot resize: imagecreatefromgif reads the first
 * frame and imagegif writes that one frame back, so every variant of a spinning
 * demo logo was a still. Serving the original at full size is the wrong shape but
 * the right picture, and a GIF small enough to be animated is small enough to
 * send. Imagick would resize it properly and is used when it is installed.
 */
function copy_as_variant(string $src, string $dest): bool
{
    if ($src === $dest) {
        return true;
    }
    $ok = @copy($src, $dest);
    if ($ok) {
        @chmod($dest, 0644);
    }
    return $ok;
}

/**
 * Resize an animated GIF with Imagick, keeping every frame.
 *
 * Returns false when Imagick is not installed or the file defeats it, which sends
 * the caller to copy_as_variant() instead.
 */
function resize_animated_gif(string $src, string $dest, int $maxEdge): bool
{
    if (!class_exists('Imagick')) {
        return false;
    }
    try {
        $img = new Imagick($src);
        // coalesceImages first: frames in a GIF are often partial rectangles
        // relative to the one before, and resizing those directly smears them.
        $img = $img->coalesceImages();
        foreach ($img as $frame) {
            $frame->thumbnailImage($maxEdge, $maxEdge, true);
        }
        $img = $img->deconstructImages();
        $img->writeImages($dest, true);
        $img->clear();
        @chmod($dest, 0644);
        return true;
    } catch (Throwable $e) {
        error_log('[retrohive] Imagick could not resize an animated GIF: ' . $e->getMessage());
        return false;
    }
}

function resize_image(string $src, string $dest, string $mime, int $maxEdge): bool
{
    // An animated GIF cannot go through GD at all: it decodes one frame and
    // writes one frame, so the thumbnail and the display copy were both stills of
    // something that moves. Imagick keeps the frames where it is available;
    // otherwise the original is copied, which is the right picture at the wrong
    // size rather than the wrong picture at the right size.
    if ($mime === 'image/gif' && gif_is_animated($src)) {
        return resize_animated_gif($src, $dest, $maxEdge) || copy_as_variant($src, $dest);
    }

    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($src),
        'image/png'  => @imagecreatefrompng($src),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
        'image/gif'  => @imagecreatefromgif($src),
        default      => false,
    };
    if ($img === false) {
        return false;
    }

    if ($mime === 'image/jpeg') {
        $img = apply_exif_rotation($img, $src);
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1.0, $maxEdge / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    $out = imagecreatetruecolor($nw, $nh);
    if ($mime === 'image/png' || $mime === 'image/webp' || $mime === 'image/gif') {
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
        imagefilledrectangle($out, 0, 0, $nw, $nh, $transparent);
    }
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $ok = match ($mime) {
        'image/jpeg' => imagejpeg($out, $dest, 86),
        'image/png'  => imagepng($out, $dest, 6),
        'image/webp' => function_exists('imagewebp') ? imagewebp($out, $dest, 85) : false,
        'image/gif'  => imagegif($out, $dest),
        default      => false,
    };

    imagedestroy($img);
    imagedestroy($out);
    if ($ok) {
        @chmod($dest, 0644);
    }
    return (bool) $ok;
}

/** Phone photos of box art are usually rotated. Respect the EXIF flag. */
function apply_exif_rotation(GdImage $img, string $src): GdImage
{
    if (!function_exists('exif_read_data')) {
        return $img;
    }
    $exif = @exif_read_data($src);
    $orientation = (int) ($exif['Orientation'] ?? 0);
    $angle = match ($orientation) {
        3 => 180,
        6 => -90,
        8 => 90,
        default => 0,
    };
    if ($angle === 0) {
        return $img;
    }
    $rotated = imagerotate($img, (float) $angle, 0);
    if ($rotated === false) {
        return $img;
    }
    imagedestroy($img);
    return $rotated;
}

function delete_image(int $imageId): void
{
    $img = one('SELECT * FROM item_images WHERE id = ?', [$imageId]);
    if ($img === null) {
        return;
    }
    foreach (['', 'thumb_', 'disp_'] as $prefix) {
        $p = uploads_dir() . '/' . $prefix . $img['filename'];
        if (is_file($p)) {
            @unlink($p);
        }
    }
    delete_row('item_images', $imageId);
    ensure_primary_image((int) $img['item_id']);
}

function set_primary_image(int $itemId, int $imageId): void
{
    q('UPDATE item_images SET is_primary = 0 WHERE item_id = ?', [$itemId]);
    q('UPDATE item_images SET is_primary = 1 WHERE id = ? AND item_id = ?', [$imageId, $itemId]);
    sync_item_image_columns($itemId);
}

/** If nothing is flagged as the cover, promote the first photo. */
function ensure_primary_image(int $itemId): void
{
    // A picture waiting for approval must never become the cover.
    //
    // The cover is the one place an unapproved picture would be seen by
    // everybody without anybody opening the entry - it is on the card, in the
    // list, in the API. Both halves have to say approved: the count, or the
    // first pending upload onto an entry with no photographs would satisfy it
    // and stop anything else being chosen; and the pick, obviously.
    $has = scalar("SELECT COUNT(*) FROM item_images
                    WHERE item_id = ? AND is_primary = 1 AND approval_state = 'approved'", [$itemId]);
    if ((int) $has === 0) {
        // Any stale primary flag on a pending row is cleared on the way, or the
        // unique-ish assumption "one primary per item" quietly stops holding.
        q("UPDATE item_images SET is_primary = 0
            WHERE item_id = ? AND approval_state <> 'approved'", [$itemId]);
        $first = one("SELECT id FROM item_images
                       WHERE item_id = ? AND approval_state = 'approved'
                    ORDER BY sort_order, id LIMIT 1", [$itemId]);
        if ($first !== null) {
            q('UPDATE item_images SET is_primary = 1 WHERE id = ?', [(int) $first['id']]);
        }
    }
    sync_item_image_columns($itemId);
}

/**
 * Copy the cover and the photo count onto the entry.
 *
 * v_items used to work these out with a correlated subquery each, per row, on
 * every list query - which is what made the view impossible to index. They are
 * columns now, and this is the only thing that writes them. It runs at exactly
 * the moments they can change, so there is no window where they are stale.
 */
function sync_item_image_columns(int $itemId): void
{
    // Approved only, in both the cover and the count.
    //
    // These two columns are what v_items reads, so they are how a picture
    // reaches a card, a list and the API without anybody opening the entry. A
    // pending one counted here would show as the cover on the shelf - the exact
    // thing the feature prevents - and "3 photos" on an entry showing two is a
    // smaller version of the same lie.
    $cover = one(
        "SELECT id FROM item_images
          WHERE item_id = ? AND approval_state = 'approved'
       ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1",
        [$itemId]
    );
    q("UPDATE items SET cover_image_id = ?,
                        image_count = (SELECT COUNT(*) FROM item_images
                                        WHERE item_id = ? AND approval_state = 'approved')
        WHERE id = ?",
      [$cover === null ? null : (int) $cover['id'], $itemId, $itemId]);
}

/** Remove every file for an item (called before the row is deleted). */
function delete_all_item_images(int $itemId): void
{
    foreach (all('SELECT id FROM item_images WHERE item_id = ?', [$itemId]) as $row) {
        delete_image((int) $row['id']);
    }
}

/**
 * The square somebody chose, in the picture's own pixels.
 *
 * Posted by the cropper on the profile form. Read defensively: these are three
 * numbers from a browser, and a crop that falls outside the image is a crop that
 * would produce a black stripe rather than an error.
 *
 * @return array{0:int,1:int,2:int}|null x, y, size
 */
function avatar_crop_from_post(): ?array
{
    foreach (['avatar_crop_x', 'avatar_crop_y', 'avatar_crop_size'] as $k) {
        if (!isset($_POST[$k]) || !is_numeric($_POST[$k])) {
            return null;
        }
    }
    $x = (int) $_POST['avatar_crop_x'];
    $y = (int) $_POST['avatar_crop_y'];
    $n = (int) $_POST['avatar_crop_size'];

    // A square of nothing is not a crop.
    if ($n < 16 || $x < 0 || $y < 0) {
        return null;
    }
    return [$x, $y, $n];
}

/**
 * Cut a square out of an image, in place.
 *
 * Clamped to the image rather than trusted: a selection that runs off the edge is
 * pulled back inside, and one bigger than the picture becomes the picture. GD
 * would otherwise pad the overhang with black, which looks like a bug in the
 * uploader rather than a number being out by four.
 *
 * Animated GIFs are left alone. Cropping one through GD flattens it to a single
 * frame, and a still where a moving picture was is a worse answer than an
 * uncropped avatar.
 */
function crop_image_square(string $path, string $mime, int $x, int $y, int $size): bool
{
    if ($mime === 'image/gif' && gif_is_animated($path)) {
        return false;
    }

    $info = @getimagesize($path);
    if ($info === false) {
        return false;
    }
    [$w, $h] = $info;

    $size = min($size, $w, $h);
    if ($size < 16) {
        return false;
    }
    $x = max(0, min($x, $w - $size));
    $y = max(0, min($y, $h - $size));

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/gif'  => @imagecreatefromgif($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default      => false,
    };
    if (!$src instanceof GdImage) {
        return false;
    }

    $out = imagecreatetruecolor($size, $size);
    // Transparency survives the crop, or a PNG with a clear background comes back
    // with a black one.
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagecopy($out, $src, 0, 0, $x, $y, $size, $size);

    $ok = match ($mime) {
        'image/jpeg' => imagejpeg($out, $path, 90),
        'image/png'  => imagepng($out, $path, 6),
        'image/gif'  => imagegif($out, $path),
        'image/webp' => function_exists('imagewebp') ? imagewebp($out, $path, 90) : false,
        default      => false,
    };

    imagedestroy($src);
    imagedestroy($out);
    return (bool) $ok;
}

/**
 * Remove the files behind an entry's photographs.
 *
 * Rows cascade when the entry goes; files do not, and an uploads directory that
 * only grows is how "0 photos" ends up sitting beside gigabytes of JPEGs. Three
 * per photograph: the original, the display copy and the thumbnail.
 *
 * Called before the row is deleted, because afterwards there is nothing left to
 * ask which files were involved.
 *
 * @return int how many files went
 */
function delete_item_image_files(int $itemId): int
{
    $gone = 0;
    foreach (all('SELECT filename FROM item_images WHERE item_id = ?', [$itemId]) as $row) {
        $name = (string) $row['filename'];
        if ($name === '') {
            continue;
        }
        foreach (['', 'disp_', 'thumb_'] as $prefix) {
            $path = uploads_dir() . '/' . $prefix . $name;
            if (is_file($path) && @unlink($path)) {
                $gone++;
            }
        }
    }
    return $gone;
}
