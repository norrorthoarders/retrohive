<?php
/**
 * What is fitted to what.
 *
 * @var array $item @var array $parents @var array $children
 * @var array $chain @var array $goesWith @var array $linkable
 */
// View mode is read-only. Fitting and unfitting happen on the edit form, which has
// the dedicated controls and the rules behind them; an Unlink button sitting on a
// page somebody is only reading is a destructive action one stray click away, and
// it duplicated the Remove button that already exists where it belongs.
$canEdit = false;

// Machines get their peripherals from the dedicated control on the edit form,
// which knows the rules - same platform, one machine or none, nothing already
// fitted elsewhere. The generic "link them" form beside it offered the same job
// with none of those checks and two extra questions, so it is not shown there.
$showLinkForm = $showLinkForm ?? true;
?>

<?php
// Machines only. The relationship runs peripheral into machine, so it is the
// machine that has a list and the card that appears on someone else's.
$isMachineItem = is_machine_category((int) ($item['category_id'] ?? 0));
?>
<section class="panel">
  <h2 class="panel__title">
    <?= $isMachineItem ? 'Installed peripherals' : 'What it is fitted to' ?>
  </h2>

  <?php if ($chain !== []): ?>
    <p class="lede" style="font-size:.95rem;margin-top:0">
      <?php $names = array_map(fn($c) => e($c['title']), $chain); ?>
      Installed in <strong><?= implode('</strong>, which is in <strong>', $names) ?></strong>.
    </p>
  <?php endif; ?>

  <?php if ($parents === [] && $children === []): ?>
    <p class="lede" style="margin:0;font-size:.9rem">
      <?php if ($isMachineItem): ?>
        Nothing fitted yet. Edit the machine to fit a peripheral you have
        catalogued for it.
      <?php else: ?>
        Not installed in anything. Edit the entry to say which machine it is in.
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <?php if ($parents !== []): ?>
    <table class="table">
      <tbody>
        <?php foreach ($parents as $p): ?>
          <tr>
            <td>
              <a href="<?= e(url('/items/' . $p['id'])) ?>"><?= e($p['title']) ?></a>
              <?php
              $pbits = array_values(array_filter([
                  $p['model_vendor'] ?? null,
                  $p['category_name'] ?? null,
              ], fn($v) => $v !== null && $v !== ''));
              ?>
              <?php if ($pbits !== []): ?>
                <span class="hint"> · <?= e(implode(' · ', $pbits)) ?></span>
              <?php endif; ?>
              <?php if (!empty($p['note'])): ?><br><span class="hint"><?= e($p['note']) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($children !== []): ?>
    <table class="table">
      <tbody>
        <?php foreach ($children as $c): ?>
          <tr>
            <td>
              <a href="<?= e(url('/items/' . $c['id'])) ?>"><?= e($c['title']) ?></a>
              <?php
              // Who made it and what kind it is, on one line. A bare title is not
              // enough to tell two accelerators apart, and both facts are already
              // on the row.
              $bits = array_values(array_filter([
                  $c['model_vendor'] ?? null,
                  $c['category_name'] ?? null,
              ], fn($v) => $v !== null && $v !== ''));
              ?>
              <?php if ($bits !== []): ?>
                <span class="hint"> · <?= e(implode(' · ', $bits)) ?></span>
              <?php endif; ?>
              <?php if (!empty($c['note'])): ?><br><span class="hint"><?= e($c['note']) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if (count($goesWith) > count($children)): ?>
    <p class="label" style="margin-top:1rem">If you sold this, it would go with</p>
    <ul style="margin:0;padding-left:1.1rem;color:var(--dim);font-size:.9rem;line-height:1.7">
      <?php foreach ($goesWith as $g): ?>
        <li style="margin-left:<?= (int) $g['depth'] ?>rem">
          <a href="<?= e(url('/items/' . $g['id'])) ?>"><?= e($g['title']) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="hint">
      Everything beneath it, at any depth — which is the question this data
      exists to answer.
    </p>
  <?php endif; ?>

  <?php if ($canEdit && $showLinkForm && $linkable !== []): ?>
    <form method="post" action="<?= e(url('/items/' . $item['id'] . '/links')) ?>" style="margin-top:1rem">
      <?= csrf_field() ?>
      <div class="formgrid">
        <div class="field field--quarter">
          <label for="direction">This one</label>
          <select id="direction" name="direction">
            <option value="inside">is fitted to…</option>
            <option value="contains">has fitted to it…</option>
          </select>
        </div>
        <div class="field field--quarter">
          <label for="relation">Which way</label>
          <select id="relation" name="relation">
            <?php foreach (link_relations() as $key => $words): ?>
              <option value="<?= e($key) ?>"><?= e($words[0]) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field field--half">
          <label for="other_id">Which entry</label>
          <select id="other_id" name="other_id" required>
            <?php foreach ($linkable as $o): ?>
              <option value="<?= (int) $o['id'] ?>"><?= e($o['title']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Only entries in the same library.</span>
        </div>
        <div class="field field--half">
          <label for="note">Note</label>
          <input id="note" name="note" type="text" maxlength="255" placeholder="trapdoor slot, 32 MB SIMM fitted">
        </div>
      </div>
      <div class="formactions"><button class="btn btn--accent" type="submit">Link them</button></div>
    </form>
  <?php endif; ?>
</section>
