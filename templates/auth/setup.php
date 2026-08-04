<div class="authcard">
  <span class="eyebrow">First run</span>
  <h1>Create the administrator</h1>
  <p class="lede" style="font-size:.9rem;margin-bottom:1.2rem">
    No accounts exist yet, so this form is open to whoever reaches it first. Fill it in now, before the app is reachable from anywhere you do not control.
  </p>

  <form method="post" action="<?= e(url('/setup')) ?>">
    <?= csrf_field() ?>
    <div class="field">
      <label for="username">Username</label>
      <input id="username" name="username" type="text" autocomplete="username" required autofocus
             pattern="[A-Za-z0-9._-]{3,64}">
      <span class="hint">Letters, numbers, dot, dash, underscore.</span>
    </div>
    <div class="field">
      <label for="display_name">Display name</label>
      <input id="display_name" name="display_name" type="text" autocomplete="name">
    </div>
    <div class="field">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" autocomplete="email" required
             value="<?= e(old('email')) ?>">
      <span class="hint">Required. Used for password recovery and notifications.</span>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="new-password" required minlength="10">
      <span class="hint">At least 10 characters.</span>
    </div>
    <div class="field">
      <label for="password_confirm">Password again</label>
      <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required minlength="10">
    </div>
    <button class="btn btn--accent" type="submit">Create account and sign in</button>
  </form>
</div>
