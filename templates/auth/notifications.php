<?php /** @var array $rows @var int $unread */ ?>

<div class="pagehead">
  <div>
    <h1>Notifications</h1>
    <p class="lede">
      <?= $unread === 0 ? 'Nothing unread.' : $unread . ' waiting.' ?>
      <a href="<?= e(url('/profile/notifications')) ?>">Choose what you are told about</a>.
    </p>
  </div>
  <?php if ($unread > 0): ?>
    <form method="post" action="<?= e(url('/notifications')) ?>">
      <?= csrf_field() ?>
      <button class="btn" type="submit" name="action" value="read_all">Mark all read</button>
    </form>
  <?php endif; ?>
</div>

<?php if ($rows === []): ?>
  <div class="empty">
    <h2>Nothing here</h2>
    <p>Invitations, answers to invitations, and anything else worth knowing will appear here.</p>
  </div>
<?php else: ?>
  <section class="panel">
    <?php foreach ($rows as $n): ?>
      <?php
      // Its own layout, not the dashboard's bar-chart row.
      //
      // These reused .shelfbar__row, which is three fixed columns for a label, a bar and
      // a number - so a notification body was squeezed into a 150px column with nowrap
      // and an ellipsis on it. A message you cannot read is not a message.
      ?>
      <div class="noterow<?= $n['read_at'] === null ? ' is-unread' : '' ?>">
        <div class="noterow__text">
          <strong style="<?= $n['read_at'] === null ? '' : 'color:var(--dim);font-weight:normal' ?>">
            <?php if ($n['link_path']): ?>
              <a href="<?= e(url($n['link_path'])) ?>"><?= e($n['subject']) ?></a>
            <?php else: ?>
              <?= e($n['subject']) ?>
            <?php endif; ?>
          </strong>
          <?php if ($n['body']): ?>
            <div class="hint" style="margin-top:.25rem;white-space:pre-wrap"><?= e($n['body']) ?></div>
          <?php endif; ?>
          <div class="hint" style="margin-top:.25rem">
            <?= e(date('j M Y, H:i', strtotime((string) $n['created_at']))) ?>
            <?php if ($n['mail_state'] === 'sent'): ?> · emailed
            <?php elseif ($n['mail_state'] === 'queued'): ?> · email queued
            <?php elseif ($n['mail_state'] === 'failed'): ?> · <span style="color:var(--bad)">email failed</span>
            <?php endif; ?>
          </div>
        </div>
        <form method="post" action="<?= e(url('/notifications')) ?>" class="noterow__actions">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
          <?php if ($n['read_at'] === null): ?>
            <button class="btn btn--sm" type="submit" name="action" value="read">Mark read</button>
          <?php endif; ?>
          <button class="btn btn--sm" type="submit" name="action" value="delete" title="Remove">&times;</button>
        </form>
      </div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>
