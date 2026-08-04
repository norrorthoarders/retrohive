<?php /** @var array $users */ $me = current_user(); ?>

<div class="pagehead">
  <div>
    <span class="eyebrow">This server</span>
    <h1>Accounts</h1>
  </div>
</div>

<?php
// The form above the table, not beside it.
//
// Squeezed into a column the accounts table had two thirds of a screen for eight
// columns, and the form had a third for fields that are each one line. Above and
// full width, both get the shape they want - and while a row is being edited the
// edit form stands where Add would be, because they are the same job and having
// both open invites filling in the wrong one.
?>

    <?php
    // Invitations, only while the instance is in that mode: a form for making
    // things that cannot be used is furniture.
    ?>
    <?php if (!empty($inviteMode)): ?>
    <section class="panel">
      <h2 class="panel__title">Invite somebody</h2>
      <form method="post" action="<?= e(url('/manage/users')) ?>" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="invite">
        <div class="field">
          <label for="invite_email">Email</label>
          <input id="invite_email" name="invite_email" type="email" required
                 placeholder="them@example.com">
          <span class="hint">
            One use, expires in a fortnight, and the account it makes is theirs —
            the address cannot be changed on the way through.
          </span>
        </div>
        <div class="formactions">
          <button class="btn btn--accent btn--sm" type="submit">Send it</button>
        </div>
      </form>

      <?php if (!empty($invites)): ?>
        <p class="label" style="margin:.8rem 0 .3rem">Outstanding</p>
        <table class="table">
          <tbody>
            <?php foreach ($invites as $inv): ?>
              <tr>
                <td>
                  <?= e((string) $inv['email']) ?>
                  <br><span class="hint mono">
                    <?php
                    // The prefix, never the token. Enough to tell two apart in a
                    // list; not enough to use one.
                    ?>
                    <?= e((string) $inv['prefix']) ?>… · expires
                    <?= e(substr((string) $inv['expires_at'], 0, 10)) ?>
                  </span>
                </td>
                <td style="text-align:right">
                  <form method="post" action="<?= e(url('/manage/users')) ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="invite_revoke">
                    <input type="hidden" name="invite_id" value="<?= (int) $inv['id'] ?>">
                    <button class="btn btn--sm" type="submit">Revoke</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($editing !== null): ?>
    <section class="panel">
      <h2 class="panel__title">Edit <?= e($editing['username']) ?></h2>
      <form method="post" action="<?= e(url('/manage/users')) ?>" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">

        <div class="<?= e(field_class('username')) ?>">
          <label for="e-username">Username</label>
          <input id="e-username" name="username" type="text" maxlength="64"
                 pattern="[A-Za-z0-9._-]{3,64}"
                 value="<?= e(old('username', $editing['username'])) ?>"
                 <?= form_error('username') ? 'aria-invalid="true"' : '' ?>>
          <?= field_hint('username', 'What they sign in with. Changing it does not change anything they own.') ?>
        </div>
        <div class="<?= e(field_class('display_name')) ?>">
          <label for="e-display">Display name</label>
          <input id="e-display" name="display_name" type="text" maxlength="120"
                 value="<?= e(old('display_name', $editing['display_name'] ?? '')) ?>">
          <?= field_hint('display_name', 'Shown instead of the username where there is room.') ?>
        </div>
        <div class="<?= e(field_class('email')) ?>">
          <label for="e-email">Email address</label>
          <input id="e-email" name="email" type="email" maxlength="190"
                 value="<?= e(old('email', $editing['email'] ?? '')) ?>"
                 <?= form_error('email') ? 'aria-invalid="true"' : '' ?>>
          <?= field_hint('email') ?>
        </div>
        <div class="field">
          <label for="e-role">Role</label>
          <select id="e-role" name="role" <?= (int) $editing['id'] === (int) $me['id'] ? 'disabled' : '' ?>>
            <?php // Three spellings of two roles lived in this one file.
                  // One list now, and it is the same one the rest of the
                  // application reads. ?>
            <?php foreach (['user' => instance_role_label('user'),
                            'admin' => instance_role_label('admin')] as $v => $l): ?>
              <option value="<?= $v ?>" <?= $editing['role'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">
            <?= (int) $editing['id'] === (int) $me['id']
                ? 'You cannot demote yourself.'
                : 'Administrators configure the instance. What they may read in the catalogue is still decided per library.' ?>
          </span>
        </div>
        <div class="field">
          <label class="checkline">
            <input type="checkbox" name="is_active" value="1"
                   <?= (int) $editing['is_active'] === 1 ? 'checked' : '' ?>
                   <?= (int) $editing['id'] === (int) $me['id'] ? 'disabled' : '' ?>>
            Can sign in
          </label>
          <label class="checkline">
            <input type="checkbox" name="mail_enabled" value="1"
                   <?= (int) ($editing['mail_enabled'] ?? 1) === 1 ? 'checked' : '' ?>>
            May be emailed
          </label>
        </div>
        <div class="<?= e(field_class('password')) ?>">
          <label for="e-password">New password</label>
          <input id="e-password" name="password" type="password" autocomplete="new-password" minlength="10"
                 <?= form_error('password') ? 'aria-invalid="true"' : '' ?>>
          <?= field_hint('password', 'Leave blank to keep the current one. At least 10 characters.') ?>
        </div>
        <div class="<?= e(field_class('password_confirm')) ?>">
          <label for="e-password2">Confirm password</label>
          <input id="e-password2" name="password_confirm" type="password" autocomplete="new-password"
                 <?= form_error('password_confirm') ? 'aria-invalid="true"' : '' ?>>
          <?= field_hint('password_confirm', 'Only needed if you are setting a new one.') ?>
        </div>

        <div class="formactions">
          <button class="btn btn--accent" type="submit" name="action" value="save">Save</button>
          <a class="btn" href="<?= e(url('/manage/users')) ?>">Cancel</a>
        </div>
      </form>

      <h3 class="subhead">Address</h3>
      <?php if ((int) ($editing['auth_method_id'] ?? 1) !== 1): ?>
        <p class="hint" style="margin:0">
          A directory account. The directory has vouched for it, so confirmation
          does not apply.
        </p>
      <?php elseif (($editing['email_verified_at'] ?? null) !== null): ?>
        <p class="hint" style="margin:0">
          Confirmed <?= e(date('j M Y', strtotime((string) $editing['email_verified_at']))) ?>.
        </p>
        <?php if ((int) $editing['id'] !== (int) $me['id']): ?>
          <form method="post" action="<?= e(url('/manage/users')) ?>" style="margin-top:.5rem">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
            <button class="btn btn--sm" type="submit" name="action" value="unverify">Mark unconfirmed</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <p class="hint" style="margin:0">Not confirmed.</p>
        <form method="post" action="<?= e(url('/manage/users')) ?>" style="display:flex;gap:.4rem;margin-top:.5rem">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
          <button class="btn btn--sm" type="submit" name="action" value="verify">Vouch for them</button>
          <?php if ($editing['email']): ?>
            <button class="btn btn--sm" type="submit" name="action" value="resend">Send a link</button>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>

<?php
// Only when nothing is being edited. The edit form stands in the same place, so
// two forms for the same thing are never open at once - which is how somebody
// fills in the wrong one and makes an account they meant to change.
?>
<?php if ($editing === null): ?>
<section class="panel">
      <h2 class="panel__title">Add an account</h2>
      <form method="post" action="<?= e(url('/manage/users')) ?>">
        <?= csrf_field() ?>
        <div class="field" style="margin-bottom:.7rem">
          <label for="n-username">Username</label>
          <input id="n-username" name="username" type="text" required pattern="[A-Za-z0-9._-]{3,64}">
        </div>
        <div class="field" style="margin-bottom:.7rem">
          <label for="n-display">Display name</label>
          <input id="n-display" name="display_name" type="text">
        </div>
        <div class="field">
          <label for="n-email">Email address</label>
          <input id="n-email" name="email" type="email" required maxlength="190">
        </div>
        <div class="field" style="margin-bottom:.7rem">
          <label for="n-password">Password</label>
          <input id="n-password" name="password" type="password" required minlength="10" autocomplete="new-password">
        </div>
        <div class="field" style="margin-bottom:.7rem">
          <label for="n-role">Role</label>
          <select id="n-role" name="role">
            <option value="user">User</option>
            <option value="admin">Administrator</option>
          </select>
        </div>
        <button class="btn btn--accent" type="submit" name="action" value="save">Create account</button>
      </form>
    </section>
<?php endif; ?>

    <?php
    // No "change a password" panel.
    //
    // It said the row form above can do it, and pointed at a command-line reset -
    // a panel whose whole content is directions to two things somebody can
    // already see.
    ?>


  <section class="panel">
    <h2 class="panel__title">Accounts</h2>
    <table class="table">
      <thead>
        <tr>
          <th>Account</th>
          <th style="width:7rem">Role</th>
          <th style="width:10rem">Signs in via</th>
          <th style="width:7rem">Status</th>
          <th style="width:9rem">Can sign in</th>
          <th style="width:1%"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <?php
        // Editable only when this row is the one that was asked for. Everything
        // else on the page stays text, so there is nothing on screen that a
        // mis-aimed click or a stray scroll can change.
        $live = ($rowId ?? null) !== null && (int) $rowId === (int) $u['id'];
        $meRow = (int) $u['id'] === (int) $me['id'];
        ?>
        <tr<?= $live ? ' style="background:var(--surface-2, rgba(255,255,255,.03))"' : '' ?>>
          <td>
            <span style="display:flex;align-items:center;gap:.5rem">
              <?php partial('avatar', ['user' => $u, 'size' => 'sm']); ?>
              <span>
                <span class="mono"><?= e($u['username']) ?></span>
                <?= (int) $u['id'] === (int) $me['id'] ? '<span class="chip">you</span>' : '' ?>
                <?php if ($u['display_name']): ?>
                  <span class="hint" style="display:block"><?= e($u['display_name']) ?></span>
                <?php endif; ?>
              </span>
            </span>
          </td>
          <td>
            <?php if (!$live): ?>
              <?php // instance_role_label(), so this says the same as everywhere else. ?>
              <?= $u['role'] === 'admin'
                    ? '<strong>' . e(instance_role_label('admin')) . '</strong>'
                    : e(instance_role_label('user')) ?>
            <?php else: ?>
              <?php // Named so the form can live outside the cells it edits: a form
                    // element cannot straddle table cells, so the controls point at
                    // one declared after the row by id. ?>
              <select name="role" form="rowform-<?= (int) $u['id'] ?>" <?= $meRow ? 'disabled' : '' ?>>
                <option value="user"  <?= $u['role'] === 'admin' ? '' : 'selected' ?>><?= e(instance_role_label('user')) ?></option>
                <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>><?= e(instance_role_label('admin')) ?></option>
              </select>
              <?php if ($meRow): ?>
                <span class="hint" style="display:block">Not your own</span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <?php
          // Three separate facts, in three columns.
          //
          // They used to share one: an account that was blocked *and* from a directory
          // showed only "blocked", and one that was unconfirmed *and* from a directory
          // showed only "directory". Which you saw depended on which was true first.
          $viaDirectory = (string) ($u['auth_type'] ?? 'local') !== 'local';
          $blocked      = (int) $u['is_active'] !== 1;
          // A directory vouches for its own people, so confirmation is not asked of them.
          $unconfirmed  = !$viaDirectory && ($u['email_verified_at'] ?? null) === null;
          ?>
          <td>
            <?php if ($viaDirectory): ?>
              <span class="chip"><?= e((string) ($u['auth_name'] ?? 'directory')) ?></span>
            <?php else: ?>
              <span class="hint">Local</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!$live): ?>
              <?php if ($blocked): ?>
                <span class="chip" style="background:var(--bad);color:var(--crust)">disabled</span>
              <?php else: ?>
                <span class="hint">enabled</span>
              <?php endif; ?>
            <?php else: ?>
              <label class="checkline">
                <input type="checkbox" name="is_active" value="1"
                       form="rowform-<?= (int) $u['id'] ?>"
                       <?= $blocked ? '' : 'checked' ?> <?= $meRow ? 'disabled' : '' ?>>
                Enabled
              </label>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!$live): ?>
              <?php if ($blocked): ?>
                <span class="hint">No — disabled</span>
              <?php elseif ($unconfirmed): ?>
                <span class="hint">No — unconfirmed</span>
              <?php else: ?>
                Yes
              <?php endif; ?>
            <?php elseif ($viaDirectory): ?>
              <?php // A directory vouches for its own people, so there is no
                    // confirmation here to grant or take away. ?>
              <span class="hint">The directory decides</span>
            <?php else: ?>
              <label class="checkline">
                <input type="checkbox" name="verified" value="1"
                       form="rowform-<?= (int) $u['id'] ?>"
                       <?= $unconfirmed ? '' : 'checked' ?>>
                Address confirmed
              </label>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap">
            <?php if ($live): ?>
              <?php
              // The confirmation names the account. The risk this design is
              // answering is changing the wrong person, and the only thing that
              // actually catches that is being told whose row you are saving.
              ?>
              <button class="btn btn--sm btn--accent" type="submit"
                      form="rowform-<?= (int) $u['id'] ?>"
                      data-confirm="Save these changes to <?= e($u['username']) ?>?">Save</button>
              <a class="btn btn--sm" href="<?= e(url('/manage/users')) ?>">Cancel</a>
            <?php else: ?>
              <a class="btn btn--sm" href="<?= e(url('/manage/users', ['row' => (int) $u['id']])) ?>">Change</a>
            <?php endif; ?>
            <a class="btn btn--sm" href="<?= e(url('/manage/users', ['edit' => (int) $u['id']])) ?>">Edit</a>
            <?php if ((int) $u['id'] !== (int) $me['id']): ?>
              <form method="post" action="<?= e(url('/manage/users')) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="btn btn--sm btn--danger" type="submit" name="action" value="delete"
                        data-confirm="Delete <?= e($u['username']) ?>? Their entries stay; the account goes.">&times;</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($live): ?>
          <?php
          // The form itself, outside the row, because a form element cannot
          // straddle table cells. The controls above reference it by id, which is
          // what the HTML form attribute is for.
          //
          // (Written without the angle brackets on purpose: tests/schema.php
          // counts opening and closing form tags to catch a template truncated
          // halfway, and a mention in a comment counts as one that never closes.)
          ?>
          <tr hidden><td colspan="6">
            <form id="rowform-<?= (int) $u['id'] ?>" method="post" action="<?= e(url('/manage/users')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <input type="hidden" name="action" value="rowsave">
            </form>
          </td></tr>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
