<div class="authcard">
  <span class="eyebrow"><?= e(config('app_name')) ?></span>
  <h1>Sign in</h1>
  <?php
  // Nothing about the catalogue being private.
  //
  // On an instance handing out a secret address it is the one sentence that
  // should not be there: it tells anybody who finds the sign-in page that there
  // is something behind it worth having, which is the opposite of the point.
  // Everywhere else it was only stating what a sign-in form already says.
  ?>

  <form method="post" action="<?= e(url('/login')) ?>">
    <?= csrf_field() ?>
    <?php if (!empty($_GET['next'])): ?>
      <input type="hidden" name="next" value="<?= e($_GET['next']) ?>">
    <?php endif; ?>
    <div class="field">
      <label for="username">Username or email</label>
      <input id="username" name="username" type="text" autocomplete="username" required autofocus>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <button class="btn btn--accent" type="submit">Sign in</button>
  </form>

  <?php
  // Offered only when the way in is a public one.
  //
  // Never in secret mode, which is the whole point of that mode: a link to the
  // secret address on the page everybody sees is not a secret, it is a longer
  // public URL. Never in invite mode either - there is nothing to click, the
  // invitation is the way in.
  ?>
  <?php if (registration_link_shown()): ?>
    <p class="hint" style="margin-top:1rem">
      No account yet? <a href="<?= e(url('/register')) ?>">Create one</a>.
    </p>
  <?php endif; ?>

<?php $unverified = trim((string) ($_GET['unverified'] ?? '')); ?>
<?php if ($unverified !== ''): ?>
  <form method="post" action="<?= e(url('/verify/resend')) ?>" style="margin-top:1rem">
    <?= csrf_field() ?>
    <input type="hidden" name="username" value="<?= e($unverified) ?>">
    <p class="hint" style="margin:0 0 .5rem">
      Confirmation links are single-use. If the first one is gone, ask for
      another.
    </p>
    <button class="btn" type="submit">Send another confirmation link</button>
  </form>
<?php endif; ?>
</div>
