<?php
/** @var array $methods @var array|null $editing @var array $params @var array $groupMaps */
/** @var array $libraries @var array $groupGrants @var array|null $testResult @var bool $ldapReady */
$p = fn(string $k, $d = '') => $params[$k] ?? $d;
$draft = $draft ?? null;
// A test redisplays the unsaved form, so prefer the draft for name and type.
$formName = $draft['name'] ?? ($editing['name'] ?? '');
$formType = $draft['type'] ?? ($editing['type'] ?? 'ldap');
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Server</span>
    <h1>Instance settings</h1>
    <?php // The tab strip below says which section; the heading says which page. ?>
  </div>
</div>

<?php partial('admin_tabs', ['current' => 'auth']); ?>



<?php if (!$ldapReady): ?>
  <div class="flash flash--error" style="margin-bottom:1rem">
    The PHP <span class="mono">ldap</span> extension is not installed, so directory
    sign-in cannot run yet. Install <span class="mono">php-ldap</span> and restart
    Apache. You can still configure a method now; it just will not authenticate anyone.
  </div>
<?php endif; ?>

<?php if (!empty($inspection)): $i = $inspection; ?>
  <div class="panel" style="border-left:4px solid var(--<?= $i['allowed'] ? 'good' : 'bad' ?>);margin-bottom:1rem">
    <h2 class="panel__title">Directory lookup — <?= e($i['identifier']) ?></h2>
    <?php if (!$i['found']): ?>
      <p style="margin-top:0"><?= e((string) $i['reason']) ?></p>
      <p class="hint" style="margin-bottom:0">
        The username, the email address, <span class="mono">DOMAIN\user</span> and
        <span class="mono">user@domain</span> are all accepted, so a miss here means the
        entry really is not under the search base — or the required group is on a
        different branch.
      </p>
    <?php else: ?>
      <p style="margin-top:0;font-size:1.05rem">
        <strong style="color:var(--<?= $i['allowed'] ? 'good' : 'bad' ?>)">
          <?= $i['allowed'] ? 'Would be allowed in' : 'Would be refused' ?>
        </strong>
        — <?= e((string) $i['reason']) ?>
      </p>
      <dl class="spec">
        <dt>Name</dt><dd><?= e((string) ($i['name'] ?: '—')) ?></dd>
        <dt>Username</dt><dd class="mono"><?= e((string) ($i['username'] ?: '—')) ?></dd>
        <dt>Email</dt><dd class="mono"><?= e((string) ($i['email'] ?: '—')) ?></dd>
        <dt>Entry</dt><dd class="mono" style="font-size:.76rem;word-break:break-all"><?= e((string) $i['dn']) ?></dd>
        <dt>Would sign in as</dt>
        <dd>
          <?= e(ucfirst((string) $i['role'])) ?>,
          libraries: <?= e((string) $i['access']) ?>
          <?php if ($i['matched_group']): ?>
            <span class="hint">(from the mapping for <span class="mono"><?= e((string) $i['matched_group']) ?></span>)</span>
          <?php else: ?>
            <span class="hint">(no group mapping matched, so the defaults apply)</span>
          <?php endif; ?>
        </dd>
        <dt>Local account</dt>
        <dd>
          <?php if ($i['local'] === null): ?>
            none yet — one would be created on first sign-in
          <?php else: ?>
            exists as <span class="mono"><?= e((string) $i['local']['username']) ?></span>
            (<?= e((string) $i['local']['role']) ?><?= (int) $i['local']['is_active'] === 1 ? '' : ', disabled' ?>)
          <?php endif; ?>
        </dd>
      </dl>
      <p class="label" style="margin-top:.8rem">Groups (<?= count($i['groups']) ?>)</p>
      <?php if ($i['groups'] === []): ?>
        <p class="hint" style="margin:0">None found. If you expected some, check the group base DN and filter.</p>
      <?php else: ?>
        <div style="display:flex;gap:.35rem;flex-wrap:wrap">
          <?php foreach ($i['groups'] as $g): ?>
            <span class="chip" style="font-size:.72rem"><?= e($g) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php
// The diagnostics from the last test, kept on the page.
//
// The verdict itself arrives as a notice, because "did that work?" is what you are
// waiting for after pressing Test. What is here is the evidence behind it - which host
// answered, what it searched, what it found - and that is reference material to compare
// against the form beside it, not news. It stays until the next test replaces it.
?>
<?php if ($testResult): ?>
  <div class="panel" style="border-left:4px solid var(--<?= $testResult['ok'] ? 'good' : 'bad' ?>);margin-bottom:1rem">
    <h2 class="panel__title">Last connection test</h2>
    <p style="margin-top:0"><?= e($testResult['message']) ?></p>
    <?php if (!empty($testResult['details'])): ?>
      <dl class="spec">
        <?php foreach ($testResult['details'] as $k => $v): ?>
          <dt><?= e(str_replace('_', ' ', (string) $k)) ?></dt><dd><?= e((string) $v) ?></dd>
        <?php endforeach; ?>
      </dl>
    <?php endif; ?>
  </div>
<?php endif; ?>

<section class="panel">
  <h2 class="panel__title">Configured methods</h2>
  <table class="table">
    <thead><tr><th>Name</th><th>Type</th><th>Accounts</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($methods as $m): ?>
      <tr>
        <td>
          <?= e($m['name']) ?>
          <?php if ($m['description']): ?><br><span style="font-size:.78rem;color:var(--faint)"><?= e($m['description']) ?></span><?php endif; ?>
        </td>
        <td>
          <span class="chip"><?= e(strtoupper($m['type'])) ?></span>
          <?php
          $mp = $m['type'] === 'local' ? [] : ldap_params($m);
          if ($mp !== [] && ($mp['encryption'] ?? 'none') !== 'none' && empty($mp['verify_cert'])): ?>
            <br><span class="chip" style="border-color:var(--warn);color:var(--warn);margin-top:.25rem">
              certificate not verified
            </span>
          <?php endif; ?>
        </td>
        <td class="num"><?= (int) $m['user_count'] ?></td>
        <td><?= (int) $m['is_enabled'] === 1 ? 'Enabled' : 'Disabled' ?></td>
        <td style="text-align:right;white-space:nowrap">
          <?php if ($m['type'] !== 'local'): ?>
            <a class="btn btn--sm" href="<?= e(url('/manage/auth', ['edit' => $m['id']])) ?>">Edit</a>
          <?php endif; ?>
          <form method="post" action="<?= e(url('/manage/auth')) ?>" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <?php if ((int) $m['is_protected'] === 0 || (int) $m['is_enabled'] === 0): ?>
              <button class="btn btn--sm" type="submit" name="action" value="toggle">
                <?= (int) $m['is_enabled'] === 1 ? 'Disable' : 'Enable' ?>
              </button>
            <?php endif; ?>
          </form>
          <?php if ((int) $m['is_protected'] === 0): ?>
            <form method="post" action="<?= e(url('/manage/auth')) ?>" style="display:inline"
                  data-confirm="Remove <?= e($m['name']) ?>?">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <button class="btn btn--sm btn--danger" type="submit" name="action" value="delete">Delete</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="panel" style="margin-top:1rem">
  <h2 class="panel__title"><?= $editing ? 'Edit ' . e($editing['name']) : 'Add a directory' ?></h2>

  <form method="post" action="<?= e(url('/manage/auth')) ?>"
        data-ldap-form
        data-presets="<?= e(json_encode([
            'ldap' => ldap_default_params('ldap'),
            'ad'   => ldap_default_params('ad'),
        ], JSON_UNESCAPED_SLASHES)) ?>">
    <?= csrf_field() ?>
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

    <fieldset>
      <legend>Directory</legend>
      <div class="formgrid">
        <div class="field field--quarter">
          <label for="name">Name</label>
          <input id="name" name="name" type="text" required maxlength="120"
                 value="<?= e($formName) ?>" placeholder="example.com">
        </div>
        <div class="field field--half">
          <label for="host">Server</label>
          <input id="host" name="host" type="text" value="<?= e($p('host')) ?>"
                 placeholder="dc01.example.com dc02.example.com">
        </div>
        <div class="field field--quarter">
          <label for="type">Type</label>
          <select id="type" name="type" data-ldap-type>
            <option value="ldap" <?= $formType === 'ldap' ? 'selected' : '' ?>>LDAP</option>
            <option value="ad"   <?= $formType === 'ad' ? 'selected' : '' ?>>Active Directory</option>
          </select>
        </div>

        <div class="field field--quarter">
          <span class="label">Encryption</span>
          <label class="checkline">
            <input type="checkbox" name="encrypted" value="1" data-ldap-encrypted
                   <?= $p('encryption') === 'none' ? '' : 'checked' ?>>
            Encrypted (LDAPS)
          </label>
        </div>
        <div class="field field--tiny">
          <label for="port">Port</label>
          <input id="port" name="port" type="number" value="<?= e((string) $p('port', 636)) ?>" data-ldap-port>
        </div>
        <?php $encOn = $p('encryption') !== 'none'; ?>
        <div class="field field--quarter <?= $encOn ? '' : 'field--disabled' ?>" data-cert-field>
          <span class="label">Certificate</span>
          <label class="checkline">
            <input type="checkbox" name="verify_cert" value="1" data-verify-cert
                   <?= $encOn && $p('verify_cert') ? 'checked' : '' ?>
                   <?= $encOn ? '' : 'disabled' ?>>
            Validate the server certificate
          </label>
          <span class="hint">
            <?php if (!$encOn): ?>
              Nothing to validate on an unencrypted connection.
            <?php endif; ?>
            <?php if (!defined('LDAP_OPT_X_TLS_NEWCTX')): ?>
              <br><strong>Restart Apache after changing this.</strong>
            <?php endif; ?>
          </span>
        </div>
        <div class="field field--tiny">
          <label for="timeout">Timeout</label>
          <input id="timeout" name="timeout" type="number" min="1" max="60" value="<?= e((string) $p('timeout', 5)) ?>">
        </div>
      </div>
    </fieldset>

    <fieldset>
      <legend>Where to look, and as whom</legend>
      <div class="formgrid">
        <div class="field field--half">
          <label for="base_dn">Base DN</label>
          <input id="base_dn" name="base_dn" type="text" value="<?= e($p('base_dn')) ?>"
                 placeholder="dc=example,dc=com">
        </div>
        <div class="field field--half">
          <label for="bind_dn">Service account</label>
          <input id="bind_dn" name="bind_dn" type="text" value="<?= e($p('bind_dn')) ?>"
                 placeholder="cn=retrohive,ou=service,dc=example,dc=com">
        </div>
        <div class="field field--half">
          <label for="bind_password">Service account password</label>
          <div class="reveal">
            <input id="bind_password" name="bind_password" type="password" autocomplete="new-password"
                   value="<?= e((string) $p('bind_password')) ?>" data-reveal-input>
            <button type="button" class="reveal__toggle" data-reveal-toggle
                    aria-label="Show or hide the password">show</button>
          </div>
        </div>
      </div>
    </fieldset>

    <fieldset>
      <legend>Who gets in, and as what</legend>
      <div class="formgrid">
        <div class="field field--half">
          <label for="user_group">User group</label>
          <input id="user_group" name="user_group" type="text" value="<?= e($p('user_group')) ?>"
                 placeholder="access-retrohive">
        </div>
        <div class="field field--half">
          <label for="admin_group">Administrator group</label>
          <input id="admin_group" name="admin_group" type="text" value="<?= e($p('admin_group')) ?>"
                 placeholder="admin-retrohive">
        </div>
        <div class="field field--half">
          <span class="label">Behaviour</span>
          <label class="checkline"><input type="checkbox" name="autocreate" value="1" <?= $p('autocreate') ? 'checked' : '' ?>> Create an account on first sign-in</label>
          <label class="checkline"><input type="checkbox" name="sync_on_login" value="1" <?= $p('sync_on_login') ? 'checked' : '' ?>> Re-apply group membership every sign-in</label>
          <label class="checkline"><input type="checkbox" name="is_enabled" value="1" <?= ($editing === null || (int) $editing['is_enabled'] === 1) ? 'checked' : '' ?>> Enabled</label>
        </div>
      </div>
    </fieldset>

    <div class="formactions">
      <button class="btn btn--accent" type="submit" name="action" value="save">Save</button>
      <button class="btn" type="submit" name="action" value="test">Test connection</button>
      <button class="btn" type="submit" name="action" value="inspect">Look up a user</button>
      <input class="probe" type="text" name="inspect_username" placeholder="username or email to look up" aria-label="User to look up">
      <?php if ($editing): ?><a class="btn" href="<?= e(url('/manage/auth')) ?>">New directory</a><?php endif; ?>
    </div>
  </form>
</section>

<?php if ($editing): ?>
<section class="panel" style="margin-top:1rem">
  <h2 class="panel__title">Group mappings for <?= e($editing['name']) ?></h2>
  <p class="lede" style="font-size:.88rem">
    The first matching mapping wins, lowest priority number first. A mapping sets
    the role and the default library access, and can grant specific libraries.
  </p>

  <?php if ($groupMaps): ?>
  <table class="table">
    <thead><tr><th>Priority</th><th>Group</th><th>Role</th><th>Default access</th><th>Per-library</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($groupMaps as $map):
        // Grants come from the controller in one query rather than one per row
        // here, and they are keyed on library, which is what they actually
        // confer - this used to join them to platforms, which never had any.
        $libraryNames = array_column($libraries, 'name', 'id');
        $grants = array_values(array_filter(
            $groupGrants,
            fn($g) => (int) $g['group_map_id'] === (int) $map['id']
        )); ?>
      <tr>
        <td class="mono"><?= (int) $map['priority'] ?></td>
        <td class="mono" style="font-size:.8rem"><?= e($map['group_name']) ?></td>
        <td><span class="chip"><?= e(ucfirst($map['role'])) ?></span></td>
        <td><?= e(access_label((string) ($map['default_access'] ?? ACCESS_NONE))) ?></td>
        <td style="font-size:.8rem;color:var(--dim)">
          <?php if ($grants): foreach ($grants as $g): ?>
            <?= e($libraryNames[(int) $g['library_id']] ?? '(removed library)') ?>:
            <?= e(access_label((string) $g['access'])) ?><br>
          <?php endforeach; else: ?>—<?php endif; ?>
        </td>
        <td style="text-align:right">
          <form method="post" action="<?= e(url('/manage/auth')) ?>" style="display:inline" data-confirm="Remove this mapping?">
            <?= csrf_field() ?>
            <input type="hidden" name="map_id" value="<?= (int) $map['id'] ?>">
            <button class="btn btn--sm btn--danger" type="submit" name="action" value="map_delete">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <form method="post" action="<?= e(url('/manage/auth')) ?>" style="margin-top:1rem">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
    <div class="formgrid">
      <div class="field">
        <label for="group_name">Directory group</label>
        <input id="group_name" name="group_name" type="text" required placeholder="retro-admins or its full DN">
      </div>
      <div class="field">
        <label for="role">Role</label>
        <select id="role" name="role">
          <?php foreach (['user', 'admin'] as $r): ?>
            <option value="<?= $r ?>"><?= ucfirst($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="map_access">Default access</label>
        <select id="map_access" name="map_access">
          <option value="<?= e(ACCESS_NONE) ?>" selected>None — only the libraries granted below</option>
          <option value="<?= e(ACCESS_VIEWER) ?>"><?= e(access_label(ACCESS_VIEWER)) ?> everywhere</option>
          <?php
          // Every level a directory group may be given, up to admin. Owner is
          // not on the list anywhere: a library has one, and it is appointed by
          // the person who holds it rather than by a group membership.
          ?>
          <?php foreach ([ACCESS_CONTRIBUTOR, ACCESS_EDITOR, ACCESS_CURATOR, ACCESS_ADMIN] as $lvl): ?>
            <option value="<?= e($lvl) ?>"><?= e(access_label($lvl)) ?> everywhere</option>
          <?php endforeach; ?>
        </select>
        <span class="hint">"None" makes the grants below an allow-list, which is usually what you want.</span>
      </div>
      <div class="field">
        <label for="priority">Priority</label>
        <input id="priority" name="priority" type="number" value="100">
      </div>
    </div>

    <details style="margin-top:.8rem">
      <summary style="cursor:pointer;color:var(--dim);font-size:.88rem">Grant specific libraries to this group</summary>
      <table class="table" style="margin-top:.6rem">
        <thead><tr><th>Library</th><th>Access</th></tr></thead>
        <tbody>
          <?php foreach ($libraries as $lib): ?>
          <tr>
            <td><?= e($lib['name']) ?></td>
            <td>
              <select name="library[<?= (int) $lib['id'] ?>]" style="width:auto">
                <option value="">Use the default above</option>
                <option value="<?= e(ACCESS_VIEWER) ?>"><?= e(access_label(ACCESS_VIEWER)) ?></option>
                <option value="<?= e(ACCESS_CONTRIBUTOR) ?>"><?= e(access_label(ACCESS_CONTRIBUTOR)) ?></option>
                <option value="<?= e(ACCESS_EDITOR) ?>"><?= e(access_label(ACCESS_EDITOR)) ?></option>
                <option value="<?= e(ACCESS_CURATOR) ?>"><?= e(access_label(ACCESS_CURATOR)) ?></option>
                <option value="<?= e(ACCESS_ADMIN) ?>"><?= e(access_label(ACCESS_ADMIN)) ?></option>
              </select>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if ($libraries === []): ?>
          <tr><td colspan="2" class="hint">No libraries yet. Create one first and this list fills in.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </details>

    <div style="margin-top:1rem">
      <button class="btn btn--accent" type="submit" name="action" value="map_add">Add mapping</button>
    </div>
  </form>
</section>
<?php endif; ?>

