<?php /** @var array $tokens @var string|null $freshToken */ ?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Your account</span>
    <h1>App access</h1>
  </div>
</div>

<?php
// The tab strip went with the one on the profile: every destination on it is in
// the account menu in the toolbar, and a second copy directly beneath the first
// is a second thing to keep in step.
?>

<p class="lede">
  Tokens let a phone, tablet or desktop app talk to this server without storing your password.
  Issue one per device so you can revoke a lost phone without touching anything else.
</p>

<?php if ($freshToken): ?>
  <div class="panel" style="border-color: var(--good); border-left: 4px solid var(--good)">
    <h2 class="panel__title">Your new token</h2>
    <p style="margin-top:0;color:var(--dim);font-size:.9rem">
      This is the only time it is shown. Copy it into the app now.
    </p>
    <code class="mono" style="display:block;background:var(--crust);padding:.75rem;border-radius:var(--r);word-break:break-all;font-size:.85rem"><?= e($freshToken) ?></code>
    <p class="mono" style="font-size:.78rem;color:var(--faint);margin-bottom:0">
      Server URL: <?= e(base_url()) ?>/api/v1
    </p>
  </div>
<?php endif; ?>

<div class="cols cols--main">
  <section class="panel">
    <h2 class="panel__title"><?= count($tokens) ?> tokens</h2>
    <?php if (!$tokens): ?>
      <p class="lede" style="margin:0">No devices connected yet.</p>
    <?php else: ?>
    <table class="table">
      <thead>
        <tr><th>Name</th><th>Token</th><th>Scope</th><th>Last used</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($tokens as $t):
          $revoked = $t['revoked_at'] !== null;
          $expired = $t['expires_at'] !== null && strtotime((string) $t['expires_at']) < time(); ?>
        <tr style="<?= $revoked || $expired ? 'opacity:.5' : '' ?>">
          <td>
            <?= e($t['name']) ?>
            <?php if ($t['platform']): ?><br><span class="mono" style="font-size:.72rem;color:var(--faint)"><?= e($t['platform']) ?></span><?php endif; ?>
          </td>
          <td class="mono" style="font-size:.78rem"><?= e($t['prefix']) ?>…</td>
          <td><span class="chip"><?= e($t['scope'] === 'read' ? 'read only' : 'read + write') ?></span></td>
          <td class="mono" style="font-size:.78rem">
            <?= e($t['last_used_at'] ? substr((string) $t['last_used_at'], 0, 16) : 'never') ?>
            <?php if ($t['last_used_ip']): ?><br><span style="color:var(--faint)"><?= e($t['last_used_ip']) ?></span><?php endif; ?>
          </td>
          <td>
            <?php if ($revoked): ?>Revoked
            <?php elseif ($expired): ?>Expired
            <?php else: ?>Active<?php endif; ?>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <form method="post" action="<?= e(url('/profile/tokens')) ?>" style="display:inline"
                  data-confirm="<?= $revoked ? 'Remove this token from the list?' : 'Revoke ' . e($t['name']) . '? That device stops working immediately.' ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button class="btn btn--sm btn--danger" type="submit" name="action" value="<?= $revoked ? 'delete' : 'revoke' ?>">
                <?= $revoked ? 'Remove' : 'Revoke' ?>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

  <aside>
    <section class="panel">
      <h2 class="panel__title">Connect a device</h2>
      <form method="post" action="<?= e(url('/profile/tokens')) ?>">
        <?= csrf_field() ?>
        <div class="field" style="margin-bottom:.7rem">
          <label for="t-name">Device name</label>
          <input id="t-name" name="name" type="text" required maxlength="120" placeholder="iPhone 15, Mac mini, test script">
        </div>
        <div class="field" style="margin-bottom:.7rem">
          <label for="t-platform">Platform</label>
          <select id="t-platform" name="platform">
            <option value="ios">iOS</option>
            <option value="macos">macOS</option>
            <option value="android">Android</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="field" style="margin-bottom:.7rem">
          <label for="t-scope">Access</label>
          <select id="t-scope" name="scope">
            <option value="write">Read and write</option>
            <option value="read">Read only</option>
          </select>
        </div>
        <div class="field" style="margin-bottom:.7rem">
          <label for="t-expires">Expires after</label>
          <select id="t-expires" name="expires_days">
            <option value="0">Never</option>
            <option value="30">30 days</option>
            <option value="90">90 days</option>
            <option value="365">A year</option>
          </select>
        </div>
        <button class="btn btn--accent" type="submit" name="action" value="create">Create token</button>
      </form>
    </section>

    <section class="panel">
      <h2 class="panel__title">For the app developer</h2>
      <dl class="spec">
        <dt>Base URL</dt><dd class="mono" style="font-size:.78rem;word-break:break-all"><?= e(base_url()) ?>/api/v1</dd>
        <dt>Auth</dt><dd class="mono" style="font-size:.78rem">Authorization: Bearer &lt;token&gt;</dd>
        <dt>Docs</dt><dd>See <span class="mono" style="font-size:.78rem">docs/API.md</span></dd>
      </dl>
    </section>
  </aside>
</div>
