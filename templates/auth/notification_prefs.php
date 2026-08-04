<?php /** @var array $prefs @var bool $mailEnabled @var bool $mailForYou @var ?string $address */ ?>

<div class="pagehead">
  <div>
    <h1>Notification settings</h1>
    <p class="lede">
      What you are told about, and how. These are yours; an administrator sets
      the defaults for anyone who has not chosen.
    </p>
  </div>
</div>

<form method="post" action="<?= e(url('/profile/notifications')) ?>" class="form">
  <?= csrf_field() ?>

  <section class="panel">
    <table class="table">
      <thead>
        <tr>
          <th>What happens</th>
          <th style="width:7rem;text-align:center">Here</th>
          <th style="width:7rem;text-align:center">By email</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($prefs as $kind => $p): ?>
        <tr>
          <td>
            <strong><?= e($p['label']) ?></strong>
            <span class="hint" style="display:block"><?= e($p['description']) ?></span>
          </td>
          <td style="text-align:center">
            <input type="checkbox" name="in_app[<?= e($kind) ?>]" value="1"
                   style="width:auto;height:auto" <?= $p['in_app'] ? 'checked' : '' ?>>
          </td>
          <td style="text-align:center;<?= $mailEnabled ? '' : 'opacity:.4' ?>">
            <input type="checkbox" name="by_mail[<?= e($kind) ?>]" value="1"
                   style="width:auto;height:auto" <?= $p['by_mail'] ? 'checked' : '' ?>
                   <?= $mailEnabled ? '' : 'disabled title="This instance has no proved mail relay"' ?>>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="panel">
    <h2 class="panel__title">Email</h2>
    <?php if (!$mailEnabled): ?>
      <p class="hint" style="margin-top:0">
        This instance has no working mail relay, so the email column is locked
        and nothing can be sent whatever you tick. An administrator configures
        one and sends a test message to prove it.
      </p>
    <?php endif; ?>
    <div class="field" style="max-width:26rem">
      <label for="email">Your address</label>
      <input id="email" name="email" type="email" maxlength="190" value="<?= e((string) $address) ?>">
      <span class="hint">
        <?php if ($mailEnabled && !$mailForYou && trim((string) $address) === ''): ?>
          Needed before anything can be emailed to you.
        <?php else: ?>
          Leave it blank and nothing will be emailed to you.
        <?php endif; ?>
      </span>
    </div>
  </section>

  <div class="formactions">
    <button class="btn btn--accent" type="submit">Save</button>
  </div>
</form>
