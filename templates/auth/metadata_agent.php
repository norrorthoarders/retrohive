<?php
/**
 * One metadata source, in full.
 *
 * What it does, which machines it has been tried on, and where it fetches from.
 * All of this already existed as a paragraph in the add-a-source list, which is
 * the one place you cannot read it once the source has been added.
 *
 * @var array $def @var string $type @var array|null $configured @var array $tested
 * @var bool $testedFromMap
 */
?>
<?php partial('admin_tabs', ['current' => 'metadata']); ?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Metadata agents</span>
    <h1><?= e((string) ($def['label'] ?? $type)) ?></h1>
  </div>
  <div class="pagehead__actions">
    <a class="btn" href="<?= e(url('/manage/metadata')) ?>">All agents</a>
  </div>
</div>

<section class="panel">
  <p class="lede" style="font-size:.95rem;margin-top:0"><?= e((string) ($def['blurb'] ?? '')) ?></p>

  <table class="table">
    <tbody>
      <tr>
        <th style="width:12rem">Answers about</th>
        <td>
          <?php foreach (($def['domains'] ?? []) as $dom): ?>
            <span class="chip"><?= e($dom) ?></span>
          <?php endforeach; ?>
        </td>
      </tr>
      <tr>
        <th>Needs a key</th>
        <td><?= !empty($def['needs_key']) ? 'Yes' : 'No' ?></td>
      </tr>
      <?php if (!empty($def['homepage'])): ?>
        <tr>
          <th>Fetches from</th>
          <td>
            <a href="<?= e((string) $def['homepage']) ?>" rel="noopener noreferrer external">
              <?= e((string) $def['homepage']) ?>
            </a>
          </td>
        </tr>
      <?php endif; ?>
      <?php
      // Tried on, not allowed on. Every source can be attached to any branch; this
      // says which machines somebody has actually watched it answer about, which
      // is a different and more useful claim.
      ?>
      <?php if ($tested !== []): ?>
        <tr>
          <th><?= !empty($testedFromMap) ? "Knows these machines" : "Tried on" ?></th>
          <td>
            <?php foreach ($tested as $name): ?>
              <span class="chip"><?= e($name) ?></span>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endif; ?>
      <tr>
        <th>On this instance</th>
        <td>
          <?php if ($configured === null): ?>
            Not added.
            <a href="<?= e(url('/manage/metadata')) ?>">Add it</a>
          <?php else: ?>
            Added as <strong><?= e((string) $configured['name']) ?></strong>,
            <?= (int) $configured['is_enabled'] === 1 ? 'enabled' : 'switched off' ?>.
            <?php // Where it is attached is decided per branch, in the tree. ?>
            <br><span class="hint">
              Which branches it answers for is set in the
              <a href="<?= e(url('/manage/tree')) ?>">category editor</a>.
            </span>
          <?php endif; ?>
        </td>
      </tr>
    </tbody>
  </table>
</section>
