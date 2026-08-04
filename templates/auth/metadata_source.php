<?php
/**
 * One source, in full.
 *
 * The card on the configuration page shows a handful of platform chips and links here
 * for the rest. IGDB covers fifty-six machines; a card that tried to say so would be a
 * paragraph of slugs, and one that silently stopped at eight would be a card that lies.
 *
 * @var string $type @var array $def @var array $names @var array $here
 */
$platforms = $def['tested_with'] ?? [];
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Server</span>
    <h1>Instance settings</h1>
  </div>
  <div>
    <a class="btn btn--sm" href="<?= e(url('/manage/metadata')) ?>">Metadata agents</a>
  </div>
</div>

<section class="panel">
  <h2 class="panel__title">
    <?= e((string) $def['label']) ?>
    <span class="chip"><?= empty($def['needs_key']) ? 'no key needed' : 'free key required' ?></span>
  </h2>
  <p style="margin-top:0"><?= e((string) $def['blurb']) ?></p>
  <p style="margin:.4rem 0 0">
    <?php foreach (($def['domains'] ?? ['software', 'hardware']) as $d): ?>
      <span class="chip"><?= e($d) ?></span>
    <?php endforeach; ?>
  </p>
  <?php if (!empty($def['homepage'])): ?>
    <p class="hint" style="margin:.6rem 0 0">
      <a href="<?= e((string) $def['homepage']) ?>" target="_blank" rel="noopener noreferrer external">
        <?= e((string) $def['homepage']) ?>
      </a>
    </p>
  <?php endif; ?>
</section>

<section class="panel" style="margin-top:1rem">
  <h2 class="panel__title">
    <?= $platforms === [] ? 'Not machine-specific' : 'Tried on ' . count($platforms) . ' machines' ?>
  </h2>

  <?php if ($platforms === []): ?>
    <p class="hint" style="margin:0">
      No list. This source has an entry for almost any machine and shallow data about
      all of them, so a list would either run to every platform here or be a claim
      about the ones it left out.
    </p>
  <?php else: ?>
    <p class="hint" style="margin:0 0 .8rem">
      What it has been tried against, and nothing more than that. It is asked about
      machines outside this list too — the list is what somebody has checked, not a
      limit, and a catalogue built on its own machines would match no list at all.
      Untested is not the same as useless.
      Machines <strong>this instance has entries for</strong> are marked.
    </p>
    <p style="margin:0;line-height:2.1">
      <?php foreach ($platforms as $slug): ?>
        <?php $mine = in_array($slug, $here, true); ?>
        <span class="chip"<?= $mine ? ' style="background:var(--good);color:var(--crust)"' : '' ?>>
          <?= e($names[$slug] ?? $slug) ?>
        </span>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
</section>
