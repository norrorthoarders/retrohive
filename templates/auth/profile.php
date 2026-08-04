<?php
/** @var array $user @var array|null $method @var bool $isLocal */
/** @var int $tokenCount @var array $libraries @var array $recentSignIns */
?>

<div class="pagehead">
  <div>
    <h1>Your profile</h1>
  </div>
</div>

<?php
// No tab strip. Every one of those destinations is in the account menu in the
// toolbar, which is where somebody already is when they arrive here - a second
// copy of a menu directly under the first is a second thing to keep in step.
//
// The name is in the Account panel and the heading says whose page this is, so
// the heading no longer has to be somebody's username.
?>

<div class="cols cols--main">
  <div>
    <?php if (!empty($invitations)): ?>
<section class="panel" style="border-left:3px solid var(--accent)">
  <h2 class="panel__title">Waiting on you</h2>
  <p class="lede" style="font-size:.9rem;margin-top:0">
    Somebody has offered you access to a library. Nothing has changed yet —
    an invitation confers nothing until you accept it.
  </p>
  <?php foreach ($invitations as $inv): ?>
    <div class="shelfbar__row" style="--spine: <?= e($inv['accent_color']) ?>;align-items:center;gap:.8rem">
      <span class="shelfbar__name">
        <strong><?= e($inv['library_name']) ?></strong>
        <span class="hint">
          as <?= e(access_label((string) $inv['access'])) ?>,
          from <?= e($inv['invited_by_name'] ?: $inv['invited_by'] ?: 'somebody') ?>
        </span>
      </span>
      <form method="post" action="<?= e(url('/manage/libraries')) ?>" style="display:flex;gap:.4rem">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $inv['library_id'] ?>">
        <button class="btn btn--sm btn--accent" type="submit" name="action" value="accept">Accept</button>
        <button class="btn btn--sm" type="submit" name="action" value="decline">Decline</button>
      </form>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="panel">
      <h2 class="panel__title">Picture</h2>
      <div style="display:flex;gap:1.25rem;align-items:flex-start;flex-wrap:wrap">
        <?php partial('avatar', ['user' => $user, 'size' => 'lg']); ?>
        <form method="post" action="<?= e(url('/profile')) ?>" enctype="multipart/form-data" style="flex:1 1 20rem">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="avatar">
          <?php
          // data-dropzone-plain: no caption box, no "what this shows" select, and
          // one picture at a time.
          //
          // This is the same dropzone the entry forms use, and there a photo is one
          // of several that each need saying which they are. An avatar is one
          // picture. Asking it to be named, and offering to file it as a box front,
          // were controls borrowed from a different job - and the second picture
          // dropped simply replaces the first, because an account has one.
          ?>
          <?php
          // data-dropzone-crop: the picker below the zone, and the three numbers
          // it writes. Empty without JavaScript, and an empty crop means the whole
          // picture - the same avatar as before this existed.
          ?>
          <input type="hidden" name="avatar_crop_x"    data-crop-x    value="">
          <input type="hidden" name="avatar_crop_y"    data-crop-y    value="">
          <input type="hidden" name="avatar_crop_size" data-crop-size value="">
          <div class="dropzone" data-dropzone data-dropzone-plain data-dropzone-crop data-max="1">
            <div class="dropzone__prompt">
              <strong>Drop a picture here</strong>
              <span>or click to browse</span>
            </div>
            <span class="dropzone__hint">A square image works best. It is only ever shown small.</span>
            <input id="avatar" name="avatar" type="file" accept="image/*">
            <div class="dropzone__list" data-dropzone-list></div>
          </div>
          <?php
          // Where the picker draws itself. Outside the drop zone, because clicking
          // the zone opens the file dialogue and dragging a circle around inside it
          // would do that on every release.
          ?>
          <div data-crop-stage hidden style="margin-top:.8rem"></div>
          <div style="display:flex;gap:.6rem;margin-top:.8rem;flex-wrap:wrap;align-items:center">
            <button class="btn btn--accent btn--sm" type="submit">Save picture</button>
            <?php if (!empty($user['avatar_filename'])): ?>
              <label class="checkline"><input type="checkbox" name="remove_avatar" value="1"> Remove it instead</label>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </section>

    <section class="panel">
      <h2 class="panel__title">Name and email</h2>
      <form method="post" action="<?= e(url('/profile')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="details">
        <div class="formgrid">
          <div class="field field--half">
            <label for="display_name">Display name</label>
            <input id="display_name" name="display_name" type="text" maxlength="120"
                   value="<?= e($user['display_name'] ?? '') ?>">
          </div>
          <div class="field field--half">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" maxlength="190" value="<?= e($user['email'] ?? '') ?>">
            <span class="hint">Not used for anything automatic yet — it is there for your own records.</span>
          </div>
        </div>
        <div class="formactions">
          <button class="btn btn--accent" type="submit">Save changes</button>
          <span class="hint">
            Your username is <span class="mono"><?= e($user['username']) ?></span> and cannot be changed here.
          </span>
        </div>
      </form>
    </section>

    <section class="panel">
      <h2 class="panel__title">Password</h2>
      <?php if (!$isLocal): ?>
        <p class="lede" style="margin:0">
          This account signs in through <strong><?= e($method['name'] ?? 'a directory') ?></strong>,
          so its password lives there. Change it wherever you normally would and the
          new one works here immediately.
        </p>
      <?php else: ?>
        <form method="post" action="<?= e(url('/profile')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="password">
          <div class="formgrid">
            <div class="field field--third">
              <label for="current_password">Current password</label>
              <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            </div>
            <div class="field field--third">
              <label for="new_password">New password</label>
              <input id="new_password" name="new_password" type="password" autocomplete="new-password" required minlength="10">
              <span class="hint">At least 10 characters.</span>
            </div>
            <div class="field field--third">
              <label for="new_password_confirm">New password again</label>
              <input id="new_password_confirm" name="new_password_confirm" type="password" autocomplete="new-password" required minlength="10">
            </div>
          </div>
          <div class="formactions">
            <button class="btn btn--accent" type="submit">Change password</button>
            <span class="hint">You stay signed in here; other devices using a token are unaffected.</span>
          </div>
        </form>
      <?php endif; ?>
    </section>
  </div>

  <aside>
    <section class="panel">
      <h2 class="panel__title">Account</h2>
      <dl class="spec">
        <dt>Username</dt><dd class="mono"><?= e($user['username']) ?></dd>
        <dt>Role</dt><dd><?= e(ucfirst($user['role'])) ?></dd>
        <dt>Signs in via</dt><dd><?= e($method['name'] ?? 'Local database') ?></dd>
        <dt>Devices</dt>
        <dd><a href="<?= e(url('/profile/tokens')) ?>"><?= (int) $tokenCount ?> connected</a></dd>
        <?php if (!empty($user['last_login_at'])): ?>
          <dt>Last sign-in</dt><dd class="mono" style="font-size:.8rem"><?= e(substr((string) $user['last_login_at'], 0, 16)) ?></dd>
        <?php endif; ?>
      </dl>
    </section>

    <?php
    // "What you can reach" lived here as a read-only list ending in "ask an
    // administrator". Profile → App access → the library access page says the
    // same thing with the requests and the joining attached, so this was the
    // half of it that could only be looked at.
    ?>
    <?php if ($recentSignIns): ?>
    <section class="panel">
      <h2 class="panel__title">Recent sign-ins</h2>
      <table class="table">
        <tbody>
          <?php foreach ($recentSignIns as $l): ?>
          <tr>
            <td class="mono" style="font-size:.76rem"><?= e(substr((string) $l['created_at'], 0, 16)) ?></td>
            <td style="color:var(--<?= (int) $l['succeeded'] === 1 ? 'good' : 'bad' ?>);font-size:.82rem">
              <?= (int) $l['succeeded'] === 1 ? 'ok' : 'failed' ?>
            </td>
            <td class="mono" style="font-size:.76rem"><?= e((string) $l['client_ip']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint" style="margin-bottom:0">Anything here you do not recognise is worth investigating.</p>
    </section>
    <?php endif; ?>
  </aside>
</div>
