<?php
/**
 * One maintenance finding.
 *
 * Shared by the server's maintenance page and the library editor, because they
 * show the same thing about different scopes and two copies would drift.
 *
 * @var string $key @var array $job @var array $found @var ?int $libId
 */
$clean = $found['count'] === 0;
?>
    <div class="panel" style="margin-bottom:1rem;border-left:3px solid var(--<?= $clean ? 'good' : 'warn' ?>)">
      <h3 class="panel__title" style="display:flex;gap:.6rem;align-items:baseline;flex-wrap:wrap">
        <?= e($job['label']) ?>
        <span class="chip"<?= $clean ? '' : ' style="background:var(--warn);color:var(--crust)"' ?>>
          <?= $clean ? 'nothing found' : $found['count'] . ' found' ?>
        </span>
      </h3>
      <p class="hint" style="margin:.2rem 0 .6rem"><?= e($job['blurb']) ?></p>

      <?php if ($clean): ?>
        <p style="margin:0"><?= e($found['note']) ?></p>
      <?php else: ?>
        <table class="table" style="margin:0 0 .6rem">
          <tbody>
            <?php foreach ($found['rows'] as $row): ?>
              <tr>
                <td style="width:40%"><?= e((string) $row['what']) ?></td>
                <td class="hint"><?= e((string) $row['detail']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($found['count'] > count($found['rows'])): ?>
          <p class="hint" style="margin:0 0 .6rem">
            Showing <?= count($found['rows']) ?> of <?= (int) $found['count'] ?>. A repair works on
            all of them, not only the ones listed.
          </p>
        <?php endif; ?>

        <?php if ($job['repair'] !== null): ?>
          <form method="post" action="<?= e(url('/manage/maintenance')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="job" value="<?= e($key) ?>">
            <?php if ($libId !== null && $job['scope'] === 'library'): ?>
              <input type="hidden" name="library" value="<?= (int) $libId ?>">
            <?php endif; ?>
            <button class="btn btn--sm" type="submit"
                    data-confirm="<?= e($job['repair_label'] . ' — ' . $found['count'] . ' affected. This changes data.') ?>">
              <?= e((string) $job['repair_label']) ?>
            </button>
          </form>
        <?php else: ?>
          <?php
          // Said, rather than left as an absent button. Some of these are things
          // only a person can decide - which kind an entry should be filed under
          // is not something a script can work out.
          ?>
          <p class="hint" style="margin:0">
            No automatic repair: what the right answer is depends on what you meant,
            and guessing would be worse than the fault.
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
