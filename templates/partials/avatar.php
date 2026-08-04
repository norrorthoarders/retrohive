<?php
/**
 * A user's picture, or their initials when there isn't one.
 * @var array  $user
 * @var string $size  'sm' | 'md' | 'lg'
 */
$size = $size ?? 'sm';
$file = $user['avatar_filename'] ?? null;
$name = trim((string) ($user['display_name'] ?? $user['username'] ?? '?'));

if ($file) : ?>
  <img class="avatar avatar--<?= e($size) ?>" src="<?= e(image_url($file, 'thumb')) ?>" alt="">
<?php else:
    // Initials from the first and last word, which reads better than one letter.
    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
    if (count($parts) > 1) {
        $initials .= mb_strtoupper(mb_substr((string) end($parts), 0, 1));
    }
    // Stable colour per person, so the same face is the same colour every time.
    $hues = ['#f38ba8', '#fab387', '#a6e3a1', '#94e2d5', '#89b4fa', '#cba6f7', '#f5c2e7'];
    $hue  = $hues[crc32((string) ($user['username'] ?? $name)) % count($hues)];
    ?>
  <span class="avatar avatar--<?= e($size) ?> avatar--initials" style="--tint: <?= e($hue) ?>"><?= e($initials) ?></span>
<?php endif; ?>
