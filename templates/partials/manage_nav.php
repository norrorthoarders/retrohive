<?php
/**
 * One navigation bar for every screen under Manage.
 *
 * Previously each screen carried its own ad-hoc row of links, so App access and
 * Accounts lost the bar entirely and offered only "Back to lists" - leaving no
 * sense of where you were or what else there was.
 *
 * @var string $current  key of the page being shown
 */
$groups = [
    // Three rows, because the split is real: some of these are read by both domains
    // and some belong to one.
    //
    // A platform is the clearest case - a boxed game and the machine that runs it
    // both file under the Amiga, so listing Platforms under Hardware said something
    // untrue about it. Locations hold both a disk and a monitor. Manufacturers is
    // here because a platform names one, and Platforms is global.
    // Ordered by what depends on what: a place exists on its own, a company exists on
    // its own, a platform names a company, and the category trees are rooted at
    // platforms. Setting one up in this order never sends you forward for something
    // that is not there yet.
    'Global' => [
        'locations'  => ['Locations',        '/manage/locations',  true],
        // One Companies list. Manufacturers and Developers were the same table shown
        // twice, filtered by a `makes` tag - so a firm that built machines and published
        // games appeared on whichever screen you happened to open, and one whose tag was
        // wrong appeared on neither and looked missing. The tags are on the row now.
        'companies'  => ['Companies',        '/manage/companies',  false],
        'platforms'  => ['Platforms',        '/manage/platforms',  false],
        // One editor for the whole classification, hardware and software both. It
        // sat under Hardware as "Types", which was wrong twice: it holds the
        // software side too, and what it edits is a tree rather than a flat list.
        // The separate flat Categories screen is gone with it - both edited the
        // same table, and only the tree can express Games > Platformer.
        'tree'       => ['Categories',       '/manage/tree',       false],
        // Same move, same reason as Platforms above it. A packaging model has
        // never only meant software: the shipped set carries VHS clamshells,
        // vinyl sleeves and CD jewel cases beside boxed Amiga disks, and the
        // category picker on its form offers every section. Under Software it
        // read as a software-only thing, which is what sent people looking for a
        // separate one for films.
        'swmodels'   => ['Packaging models', '/manage/software-models', true],
    ],
    'Hardware' => [
        'models'     => ['Machine models',    '/manage/models',    true],
        'parts'      => ['Peripheral models', '/manage/parts',     true],
    ],
    // What is genuinely software-only, which is one thing.
    //
    // No Titles here. A title is a property of the software you catalogue, not a
    // thing to manage alongside platforms and makers - it is reached from the
    // software browser, which is where you are when you care about one.
    // Environments: what each machine can run, which is a fact about software and
    // about nothing else in the catalogue.
    'Software' => [
        'environments' => ['Environments',    '/manage/environments', true],
    ],
];
$current = $current ?? '';

// Carry the library between tabs.
//
// These were plain paths, so clicking one dropped ?library= and the page it landed
// on fell back to default_library() - which is the personal shelf. You could switch
// to the club's library, open Peripheral models, click Machine models, and silently
// be editing your own again.
//
// Read from the query string rather than from the page, because this partial is
// rendered before layout.php runs and cannot see what the switcher decided. Falling
// back to the default makes the link explicit rather than implicit, which is what
// stops the next tab guessing.
$navSlug = trim((string) ($_GET['library'] ?? ''));
if ($navSlug === '' && function_exists('default_library')) {
    $mine    = working_library();
    $navSlug = $mine === null ? '' : (string) $mine['slug'];
}
$navCarry = $navSlug === '' ? [] : ['library' => $navSlug];
?>
<nav class="managenav" aria-label="Manage sections">
  <?php foreach ($groups as $label => $links): ?>
    <?php
    // Every screen behind this nav writes to a library-scoped table, so the
    // flag that used to mean "administrators only" now means "curators only" -
    // which is the same people plus the ones who own the shelf being edited.
    $visible = array_filter($links, fn($l) => !$l[2] || can_manage_library());
    if ($visible === []) {
        continue;
    }
    ?>
    <div class="managenav__group">
      <span class="managenav__label"><?= e($label) ?></span>
      <div class="managenav__links">
        <?php foreach ($visible as $key => [$text, $path, $adminOnly]): ?>
          <a href="<?= e(url($path, $navCarry)) ?>"
             class="<?= $current === $key ? 'is-current' : '' ?>"
             <?= $current === $key ? 'aria-current="page"' : '' ?>><?= e($text) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</nav>
