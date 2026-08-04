<?php
/**
 * The administrator's library editor.
 *
 * Deliberately not the owner's screen. That one carries template resynchronisation,
 * per-library platforms, visibility, invitations and the ownership offer, all of which
 * are the owner's business. An administrator here is doing one of four things: reading
 * the name, turning the library off, fixing who owns it, or deleting it. So those are
 * the four things on the page.
 *
 * @var array $library @var array $summary @var array $accounts
 */
$off = (int) ($library['is_active'] ?? 1) === 0;
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Manage</span>
    <h1><?= e((string) $library['name']) ?></h1>
  </div>
  <div>
    <a class="btn btn--sm" href="<?= e(url('/manage/libraries')) ?>">All libraries</a>
    <a class="btn btn--sm" href="<?= e(url('/manage/libraries/' . (int) $library['id'] . '/contents')) ?>">
      What it holds
    </a>
  </div>
</div>

<div class="cols cols--main">
  <section class="panel">
    <h2 class="panel__title">Settings</h2>
    <form method="post" action="<?= e(url('/manage/libraries')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $library['id'] ?>">
      <input type="hidden" name="action" value="save">
      <div class="field">
        <label for="name">Name</label>
        <input id="name" name="name" type="text" required maxlength="120"
               value="<?= e((string) $library['name']) ?>">
      </div>
      <div class="field">
        <label for="description">Description (optional)</label>
        <textarea id="description" name="description" rows="3"><?= e((string) ($library['description'] ?? '')) ?></textarea>
      </div>
      <div class="formactions">
        <button class="btn btn--accent" type="submit">Save</button>
      </div>
    </form>
  </section>

  <aside>
    <section class="panel" style="margin:0 0 1rem">
      <h2 class="panel__title">Owner</h2>
      <?php
      // Set, not offered.
      //
      // The owner's own handover writes pending_owner_id and waits for the other
      // account to accept, and only offers to somebody who has already joined. That is
      // right between two users. It is wrong here: an administrator fixing a library
      // whose owner has left should not have to invite that account, wait for it to
      // accept the invitation, then offer ownership and wait again.
      ?>
      <p class="hint" style="margin-top:0">
        Currently
        <strong><?= e((string) (one('SELECT username FROM users WHERE id = ?',
                                    [(int) ($library['owner_id'] ?? 0)])['username'] ?? 'nobody')) ?></strong>.
        Setting it here takes effect at once — no invitation, and no acceptance to wait for.
      </p>
      <form method="post" action="<?= e(url('/manage/libraries')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $library['id'] ?>">
        <input type="hidden" name="action" value="admin-owner">
        <div class="field">
          <label for="user_id">Give it to</label>
          <select id="user_id" name="user_id" required>
            <?php foreach ($accounts as $a): ?>
              <option value="<?= (int) $a['id'] ?>"
                      <?= (int) $a['id'] === (int) ($library['owner_id'] ?? 0) ? 'selected' : '' ?>>
                <?= e((string) $a['username']) ?><?php if (!empty($a['display_name'])): ?>
                  — <?= e((string) $a['display_name']) ?>
                <?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="formactions">
          <button class="btn" type="submit">Set owner</button>
        </div>
      </form>
    </section>

    <section class="panel" style="margin:0 0 1rem">
      <h2 class="panel__title">Circulation</h2>
      <p class="hint" style="margin-top:0">
        <?= $off
            ? 'Disabled. Nobody but an administrator can see it, and nothing has been lost.'
            : 'Active. Disabling hides it from everyone without deleting anything, and can be undone.' ?>
      </p>
      <form method="post" action="<?= e(url('/manage/libraries')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $library['id'] ?>">
        <input type="hidden" name="action" value="<?= $off ? 'enable' : 'disable' ?>">
        <button class="btn" type="submit"><?= $off ? 'Enable' : 'Disable' ?></button>
      </form>
    </section>

    <section class="panel" style="margin:0;border-left:4px solid var(--bad)">
      <h2 class="panel__title">Delete everything</h2>
      <p style="margin-top:0;font-size:.9rem;color:var(--dim)">
        This removes the library, its
        <a href="<?= e(url('/manage/libraries/' . (int) $library['id'] . '/contents')) ?>"><?=
          (int) $summary['entries'] ?> entr<?= (int) $summary['entries'] === 1 ? 'y' : 'ies' ?></a>,
        their <?= (int) $summary['images'] ?> photograph<?= (int) $summary['images'] === 1 ? '' : 's' ?>
        including the files on disk, and the
        <?= (int) $summary['platforms'] ?> platforms,
        <?= (int) $summary['companies'] ?> companies,
        <?= (int) $summary['hardware'] + (int) $summary['software'] ?> models and
        <?= (int) $summary['locations'] ?> places it defined for itself.
        It cannot be undone, and disabling is the reversible way to take a shelf out of
        circulation.
      </p>
      <form method="post" action="<?= e(url('/manage/libraries')) ?>"
            data-confirm="Delete <?= e((string) $library['name']) ?> and everything in it? This cannot be undone.">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $library['id'] ?>">
        <input type="hidden" name="action" value="admin-purge">
        <div class="field">
          <label for="confirm_name">Type <strong><?= e((string) $library['name']) ?></strong> to confirm</label>
          <input id="confirm_name" name="confirm_name" type="text" autocomplete="off"
                 spellcheck="false" placeholder="<?= e((string) $library['name']) ?>">
        </div>
        <div class="formactions">
          <button class="btn btn--danger" type="submit">Delete it and everything in it</button>
        </div>
      </form>
    </section>
  </aside>
</div>
