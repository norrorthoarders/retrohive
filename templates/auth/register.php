<?php
/** @var string $route @var string $token @var array|null $invite */
$action = match ($route) {
    'join'   => url('/join/' . $token),
    'invite' => url('/invite/' . $token),
    default  => url('/register'),
};
?>
<div class="authcard">
  <span class="eyebrow"><?= $invite !== null ? 'Invitation' : 'New account' ?></span>
  <h1>Create an account</h1>

  <?php if ($invite !== null): ?>
    <p class="lede" style="font-size:.9rem;margin-bottom:1.2rem">
      This invitation was sent to <strong><?= e((string) $invite['email']) ?></strong>
      and can be used once.
    </p>
  <?php endif; ?>

  <form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?>
    <div class="<?= e(field_class('username')) ?>">
      <label for="username">Username</label>
      <input id="username" name="username" type="text" autocomplete="username" required autofocus
             pattern="[A-Za-z0-9._-]{3,64}" value="<?= e(old('username')) ?>">
      <?php // The hint is gone; the complaint is not - it only appears when there
            // is one, which is the half of that line worth keeping. ?>
      <?php if (form_error('username') !== null): ?>
        <span class="hint"><?= e((string) form_error('username')) ?></span>
      <?php endif; ?>
    </div>
    <div class="field">
      <label for="display_name">Display name</label>
      <input id="display_name" name="display_name" type="text" autocomplete="name"
             value="<?= e(old('display_name')) ?>">
    </div>

    <?php
    // On an invitation the address is the one that was invited and is not a
    // field: an invitation to one person is not an invitation for whoever holds
    // the link to sign up as somebody else.
    ?>
    <?php if ($invite !== null): ?>
      <div class="field">
        <label>Email</label>
        <input type="email" value="<?= e((string) $invite['email']) ?>" disabled>
        <span class="hint">The address this invitation was sent to.</span>
      </div>
    <?php else: ?>
      <div class="<?= e(field_class('email')) ?>">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="email" required
               value="<?= e(old('email')) ?>">
      </div>
    <?php endif; ?>

    <div class="<?= e(field_class('password')) ?>">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="new-password" required minlength="10">
    </div>
    <?php
    // The complaint lands on the field it is about, so "the two passwords do not
    // match" appears under the box that did not match rather than at the top of
    // the page above four fields that are fine.
    ?>
    <div class="<?= e(field_class('password_confirm')) ?>">
      <label for="password_confirm">Repeat it</label>
      <input id="password_confirm" name="password_confirm" type="password"
             autocomplete="new-password" required minlength="10">
      <?php if (form_error('password_confirm') !== null): ?>
        <span class="hint"><?= e((string) form_error('password_confirm')) ?></span>
      <?php endif; ?>
    </div>

    <div class="formactions">
      <button class="btn btn--accent" type="submit">Create the account</button>
    </div>
  </form>
</div>
