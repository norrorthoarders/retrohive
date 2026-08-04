<?php
/** @var array $rows @var array $platforms @var array|null $editing @var int $libraryId */
/*
 * What each machine can run.
 *
 * Laid out like the platforms editor: the list on the left, one row per environment
 * with the machine it belongs to in its own column, and a small form on the right to
 * add or correct one.
 *
 * It used to group the rows under machine headings and carry a hand-kept sort order,
 * which made "move this to the right platform" a matter of editing a number - and the
 * headings themselves read as broken records with every other field blank. A column
 * says the same thing without either problem: the platform is a field on the row,
 * changed by choosing a different one.
 */
?>
<div class="pagehead">
  <div>
    <span class="eyebrow">Manage</span>
    <h1>Environments</h1>
  </div>
</div>

<?php partial('manage_nav', ['current' => 'environments']); ?>

<div class="cols cols--main">
  <section class="panel">
    <h2 class="panel__title"><?= count($rows) ?> environment<?= count($rows) === 1 ? '' : 's' ?></h2>

    <?php if ($rows === []): ?>
      <p class="lede" style="margin:0">
        None yet. They arrive with the platforms when you
        <a href="<?= e(url('/libraries/' . $libraryId . '/edit')) ?>">synchronise this library</a>
        with Environments ticked, or add one on the right.
      </p>
    <?php else: ?>
      <?php
      // A filter over what is already on the page: a couple of hundred rows, and you
      // know the name of the one you want.
      ?>
      <div class="field" style="margin-bottom:.7rem">
        <label class="visually-hidden" for="filter-environment-list">Filter — environment or machine</label>
        <input id="filter-environment-list" type="search" placeholder="Filter — environment or machine"
               data-tablefilter="#environment-list" data-tablefilter-count="#count-environment-list"
               autocomplete="off" spellcheck="false">
        <span class="hint" id="count-environment-list"></span>
      </div>

      <table class="table" id="environment-list">
        <thead>
          <tr>
            <th>Environment</th>
            <th style="width:12rem">Platform</th>
            <th style="width:5rem;text-align:right">Used</th>
            <th style="width:1%"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e((string) $r['name']) ?></td>
              <td>
                <span style="display:inline-block;width:8px;height:12px;border-radius:1px;vertical-align:-1px;margin-right:.4rem;background:<?= e((string) ($r['platform_color'] ?? '#cba6f7')) ?>"></span>
                <?= e((string) $r['platform_name']) ?>
              </td>
              <td class="num"><?= (int) $r['used'] ?></td>
              <td style="text-align:right;white-space:nowrap">
                <a class="btn btn--sm" href="<?= e(url('/manage/environments', ['edit' => (int) $r['id']])) ?>">Edit</a>
                <?php
                // No delete where something still names it. The refusal is enforced on
                // save as well; this is so nobody presses a button that cannot work.
                ?>
                <?php if ((int) $r['used'] === 0): ?>
                  <form method="post" action="<?= e(url('/manage/environments')) ?>" style="display:inline"
                        data-confirm="Remove <?= e((string) $r['name']) ?>?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="action" value="delete">
                    <button class="btn btn--sm" type="submit">&times;</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

<?php
/*
 * Drawn twice: the environment being edited first, the empty form under it.
 *
 * One panel used to switch between the two on $editing, so choosing a row took
 * the add form off the page and put the answer where it had been. Manage >
 * Categories puts the thing you picked at the top of the column with adding
 * beneath it, and these screens do the same job.
 */
$environmentPanel = function (?array $row, string $idp, string $margin)
        use ($platforms, $libraryId): void {
    $editing = $row;
    $v = fn(string $k, $default = '') => $row[$k] ?? $default;
?>
    <section class="panel" style="margin:<?= e($margin) ?>">
      <h2 class="panel__title"><?= $editing ? 'Edit ' . e((string) $editing['name']) : 'Add an environment' ?></h2>

      <form method="post" action="<?= e(url('/manage/environments')) ?>" class="form">
        <?= csrf_field() ?>
        <?php if ($editing): ?>
          <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
        <?php endif; ?>

        <div class="field">
          <label for="<?= e($idp) ?>-name">Environment</label>
          <input id="<?= e($idp) ?>-name" name="name" type="text" maxlength="120" required
                 value="<?= e((string) $v('name')) ?>"
                 placeholder="MS-DOS, Workbench 3.x, TOS…">
        </div>

        <div class="field">
          <label for="<?= e($idp) ?>-platform_id">Platform</label>
          <select id="<?= e($idp) ?>-platform_id" name="platform_id" required>
            <?php if ($platforms === []): ?>
              <option value="">No platforms in this library</option>
            <?php endif; ?>
            <?php foreach ($platforms as $p): ?>
              <option value="<?= (int) $p['id'] ?>"
                      <?= (int) $v('platform_id', 0) === (int) $p['id'] ? 'selected' : '' ?>>
                <?= e((string) $p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($platforms === []): ?>
            <span class="hint">
              <a href="<?= e(url('/libraries/' . $libraryId . '/edit')) ?>">Synchronise this library</a>
              first — an environment belongs to a machine.
            </span>
          <?php else: ?>
            <span class="hint">Which machine runs it. Change this to move it.</span>
          <?php endif; ?>
        </div>

        <div class="formactions">
          <button class="btn btn--accent" type="submit"><?= $editing ? 'Save it' : 'Add it' ?></button>
          <?php if ($editing): ?>
            <a class="btn" href="<?= e(url('/manage/environments')) ?>">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </section>
<?php
};
?>

  <aside>
    <?php if ($editing !== null) { $environmentPanel($editing, 'edit', '0 0 1rem'); } ?>
    <?php $environmentPanel(null, 'add', '0'); ?>
  </aside>
</div>
