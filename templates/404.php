<?php
/**
 * Both buttons here pointed at screens this application no longer serves - the
 * overview and the entry browser - so a 404 offered two more of itself.
 *
 * One link now, to the interface, and only when the instance knows where that
 * is. A button that might go nowhere is worse than no button.
 */
$client = client_base();
?>
<div class="empty">
  <h2>Not found</h2>
  <p><?= e($message ?? 'That page is not part of the catalogue.') ?></p>
  <?php if ($client !== ''): ?>
    <a class="btn btn--accent" href="<?= e($client) ?>">Go to <?= e(config('app_name')) ?></a>
  <?php else: ?>
    <p class="hint">This address serves the API.</p>
  <?php endif; ?>
</div>
