<?php /** @var array $users @var array|null $subject @var array $libraries @var array $grants */ ?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Manage</span>
    <h1>Library access</h1>
  </div>
</div>

<?php partial('manage_nav', ['current' => 'access']); ?>

<p class="lede">
  Each account gets a default that applies to every library, then any number of
  per-library exceptions. Administrators always have full access and are not
  listed here.
</p>

<div class="cols cols--main">
  <?php if ($subject === null): ?>
    <section class="panel">
      <h2 class="panel__title">Pick an account</h2>
      <table class="table">
        <thead><tr><th>Account</th><th>Role</th><th>Access</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td>
              <?= e($u['display_name'] ?: $u['username']) ?>
              <br><span class="mono" style="font-size:.75rem;color:var(--faint)"><?= e($u['username']) ?></span>
            </td>
            <td><span class="chip"><?= e(ucfirst($u['role'])) ?></span></td>
            <td style="font-size:.85rem;color:var(--dim)"><?= e(access_summary($u)) ?></td>
            <td style="text-align:right">
              <?php if ($u['role'] === 'admin'): ?>
                <span class="mono" style="font-size:.75rem;color:var(--faint)">everything</span>
              <?php else: ?>
                <a class="btn btn--sm" href="<?= e(url('/manage/access', ['user' => $u['id']])) ?>">Edit access</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php else: ?>
    <section class="panel">
      <h2 class="panel__title">
        <?= e($subject['display_name'] ?: $subject['username']) ?> — <?= e(ucfirst($subject['role'])) ?>
      </h2>

      <?php if ($subject['role'] === 'admin'): ?>
        <p class="lede" style="margin:0">
          Administrators reach every library by design, so there is nothing to set here.
          Change the role on the Accounts screen first if you want to restrict this person.
        </p>
      <?php else: ?>
      <form method="post" action="<?= e(url('/manage/access')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="user_id" value="<?= (int) $subject['id'] ?>">

        <p class="hint" style="margin-bottom:1.25rem;max-width:60ch">
          Access is a row per library and nothing else. There is no global
          default any more: "None" means no membership row, so the list below is
          the whole of what this account can reach. A personal library always
          keeps its owner, whatever is set here.
        </p>

        <table class="table">
          <thead>
            <tr><th>Library</th><th style="width:60%">Access</th></tr>
          </thead>
          <tbody>
            <?php foreach ($libraries as $lib):
              $current = $grants[(int) $lib['id']] ?? ACCESS_NONE; ?>
            <tr>
              <td>
                <span style="display:inline-block;width:8px;height:14px;border-radius:1px;vertical-align:-2px;margin-right:.5rem;background:<?= e($lib['accent_color']) ?>"></span>
                <?= e($lib['name']) ?>
                <?php if ((int) $lib['is_personal'] === 1): ?>
                  <span class="chip" style="margin-left:.4rem">personal</span>
                <?php elseif (($lib['kind'] ?? '') === 'shared'): ?>
                  <span class="chip" style="margin-left:.4rem">shared</span>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:flex;gap:1rem;flex-wrap:wrap;font-size:.85rem">
                  <?php
                  // From access_label(), not a second copy of the list. This one
                  // had drifted already - it was still spelling the levels the
                  // old way after they were renamed - and a screen that names
                  // access differently from every other screen is worse than one
                  // that names it awkwardly.
                  ?>
                  <?php foreach (access_levels() as $value): ?>
                    <?php $label = access_label($value); ?>
                    <label class="checkline" title="<?= e(access_description($value)) ?>">
                      <input type="radio"
                             name="access[<?= (int) $lib['id'] ?>]"
                             value="<?= e($value) ?>"
                             <?= $current === $value ? 'checked' : '' ?>>
                      <?= e($label) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div style="display:flex;gap:.6rem;margin-top:1.25rem">
          <button class="btn btn--accent" type="submit">Save access</button>
          <a class="btn" href="<?= e(url('/manage/access')) ?>">Back to accounts</a>
        </div>
      </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <aside>
    <section class="panel">
      <h2 class="panel__title">How it resolves</h2>
      <p style="font-size:.87rem;color:var(--dim);margin-top:0">
        For each library, the account's explicit setting wins. Where there is none,
        the default applies. The result is then capped by the account role, so a
        viewer never gains write access however the libraries are configured.
      </p>
      <dl class="spec">
        <dt>Admin</dt><dd>Everything, always</dd>
        <dt>Editor</dt><dd>Up to read and write</dd>
        <dt>Viewer</dt><dd>Read at most</dd>
      </dl>
    </section>

    <section class="panel">
      <h2 class="panel__title">What this affects</h2>
      <p style="font-size:.87rem;color:var(--dim);margin:0">
        Libraries a person cannot read are hidden from browsing, search, the
        dashboard totals, developer pages, CSV export and the API — including
        the sync feed, so a phone never learns that an entry it cannot see was
        deleted.
      </p>
    </section>
  </aside>
</div>
