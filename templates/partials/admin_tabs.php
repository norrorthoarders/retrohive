<?php
/**
 * The instance settings, as tabs.
 *
 * One page in the menu rather than four, because they are all the same job -
 * configuring the server - and a menu that lists every screen makes the person
 * do the grouping in their head. User management is deliberately not here: it
 * is a list of people, not a set of switches.
 *
 * @var string $current
 */
$tabs = [
    // First, and the landing tab. Whether the software is current is the one thing
    // worth seeing without being asked for, and it was buried at the bottom of a
    // long General page.
    'updates'  => ['Software updates', '/admin/settings'],
    'general'  => ['General',          '/admin/settings?tab=general'],
    'smtp'     => ['SMTP relay',       '/admin/settings?tab=smtp'],
    'security' => ['Security',         '/admin/settings?tab=security'],
    'metadata' => ['Metadata agents',  '/admin/metadata'],
    'auth'     => ['Authentication',   '/admin/auth'],
];
$current = $current ?? 'updates';
?>
<nav class="tabs" aria-label="Instance settings">
  <?php foreach ($tabs as $key => [$label, $path]): ?>
    <a href="<?= e(url($path)) ?>" class="tabs__tab <?= $current === $key ? 'is-current' : '' ?>"
       <?= $current === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
