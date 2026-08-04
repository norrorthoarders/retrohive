<div class="empty">
  <h2>Not found</h2>
  <p><?= e($message ?? 'That page is not part of the catalogue.') ?></p>
  <a class="btn btn--accent" href="<?= e(url('/')) ?>">Back to the overview</a>
  <a class="btn" href="<?= e(url('/items')) ?>">Browse the collection</a>
</div>
