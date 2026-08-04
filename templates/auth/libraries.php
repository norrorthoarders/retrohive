<?php
/** @var array $mine @var array $invites @var array $joinable @var array $ownerOffers @var string $tab */
/** @var array $members @var array $accounts @var array $platforms */
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Your account</span>
    <h1>Libraries</h1>
  </div>
</div>


<p class="lede">
  A library is what people share. Your own shelf and a club's shared shelf are
  two libraries, and both file entries under the same platforms. What you call
  your collection is everything you can reach across all of them.
</p>

<?php
// Three questions, three tabs.
//
// "Yours", "waiting on you" and "there if you want it" are different states, and they
// used to be one list plus a section further down - so an invitation could sit
// unanswered with nowhere to find it, and a published shelf looked the same as one you
// had actually taken on. Accepting or joining moves a row to the first tab, because that
// is what those words mean.
//
// Server-rendered rather than scripted: a tab that only works with JavaScript is a link
// that sometimes does nothing, and these are just three filtered views of one page.
$tab = in_array($tab ?? '', ['invites', 'public'], true) ? $tab : 'access';
?>
<nav class="tabs" aria-label="Library access">
  <a href="<?= e(url('/profile/access')) ?>"
     class="tabs__tab <?= $tab === 'access' ? 'is-current' : '' ?>">
    You have access <span class="hint">· <?= count($mine) ?></span>
  </a>
  <a href="<?= e(url('/profile/access', ['tab' => 'invites'])) ?>"
     class="tabs__tab <?= $tab === 'invites' ? 'is-current' : '' ?>">
    Invitations <span class="hint">· <?= count($invites ?? []) + count($ownerOffers ?? []) ?></span>
  </a>
  <a href="<?= e(url('/profile/access', ['tab' => 'public'])) ?>"
     class="tabs__tab <?= $tab === 'public' ? 'is-current' : '' ?>">
    Open to join <span class="hint">· <?= count($joinable ?? []) ?></span>
  </a>
</nav>

<?php if ($tab === 'invites'): ?>
<section class="panel">
  <h2 class="panel__title">Waiting on you</h2>

  <?php
  // Ownership offers first: being handed a library is a larger thing than being invited
  // to read one, and it is the item most likely to be sitting here unanswered.
  ?>
  <?php foreach (($ownerOffers ?? []) as $off): ?>
    <div class="noterow is-unread" style="margin-bottom:.6rem">
      <div class="noterow__text">
        <strong><?= e((string) $off['name']) ?></strong> — ownership offered
        <?php if (!empty($off['offered_by'])): ?>
          by <?= e((string) $off['offered_by']) ?>
        <?php endif; ?>
        <div class="hint" style="margin-top:.25rem">
          Accepting makes you responsible for this library. The current owner stays on
          as a Library Admin.
        </div>
      </div>
      <div class="noterow__actions">
        <form method="post" action="<?= e(url('/libraries/' . (int) $off['id'] . '/ownership/accept')) ?>">
          <?= csrf_field() ?>
          <button class="btn btn--sm btn--accent" type="submit">Take it on</button>
        </form>
        <form method="post" action="<?= e(url('/libraries/' . (int) $off['id'] . '/ownership/decline')) ?>">
          <?= csrf_field() ?>
          <button class="btn btn--sm" type="submit">Decline</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (empty($invites) && empty($ownerOffers)): ?>
    <p class="lede" style="margin:0">
      No invitations. When somebody invites you to a library it waits here until you
      answer — it grants nothing before that.
    </p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Library</th><th>You would be</th><th>Invited by</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($invites as $inv): ?>
        <tr>
          <td>
            <span class="spine" style="background:<?= e((string) ($inv['accent_color'] ?? '#cba6f7')) ?>"></span>
            <strong><?= e((string) $inv['name']) ?></strong>
            <?php if (!empty($inv['description'])): ?>
              <br><span class="hint"><?= e((string) $inv['description']) ?></span>
            <?php endif; ?>
          </td>
          <td><?= e(access_label((string) $inv['access'])) ?></td>
          <td><?= e((string) ($inv['invited_by'] ?? 'an administrator')) ?></td>
          <td style="text-align:right;white-space:nowrap">
            <form method="post" action="<?= e(url('/profile/access')) ?>" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
              <input type="hidden" name="action" value="accept">
              <button class="btn btn--sm btn--accent" type="submit">Accept</button>
            </form>
            <form method="post" action="<?= e(url('/profile/access')) ?>" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
              <input type="hidden" name="action" value="decline">
              <button class="btn btn--sm" type="submit">Decline</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tab === 'access'): ?>
<section class="panel">
  <h2 class="panel__title">Yours, and the ones you have been let into</h2>
  <?php if ($mine === []): ?>
    <p class="lede" style="margin:0">None yet. Create one below.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Name</th><th>Your access</th><th>Members</th><th>Entries</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($mine as $l): ?>
      <tr>
        <td>
          <span style="display:inline-block;width:8px;height:14px;border-radius:1px;vertical-align:-2px;margin-right:.5rem;background:<?= e($l['accent_color']) ?>"></span>
          <?= e($l['name']) ?>
          <?php if ((int) ($l['public_read'] ?? 0) === 1 || (int) ($l['public_write'] ?? 0) === 1): ?>
            <span class="chip" style="margin-left:.4rem">shared</span>
          <?php endif; ?>
          <?php if (!empty($l['description'])): ?>
            <br><span class="hint"><?= e(truncate((string) $l['description'], 70)) ?></span>
          <?php endif; ?>
        </td>
        <td><?= e(access_label((string) $l['access'])) ?></td>
        <td class="num"><?= (int) $l['members'] ?></td>
        <td class="num"><?= (int) $l['entries'] ?></td>
        <td style="text-align:right">
          <?php if ($l['access'] === ACCESS_OWNER || is_admin()): ?>
            <a class="btn btn--sm" href="<?= e(url('/manage/libraries', ['edit' => $l['id']])) ?>">Manage</a>
          <?php endif; ?>
          <?php
          // Leaving is for shelves somebody else runs. Your own is the one place you
          // always have, and an owner leaving would strand the library.
          $canLeave = (int) ($l['is_personal'] ?? 0) !== 1
                   && $l['access'] !== ACCESS_OWNER;
          ?>
          <?php if ($canLeave): ?>
            <form method="post" style="display:inline"
                  action="<?= e(url('/libraries/' . (int) $l['id'] . '/leave')) ?>">
              <?= csrf_field() ?>
              <button class="btn btn--sm" type="submit">Leave</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php
// Published shelves, as an offer.
//
// Being allowed to read one is not the same as having it. These used to appear in the
// switcher the moment somebody published one, which reads as being added to something
// you never agreed to; now they sit here until you say yes.
?>
<?php if ($tab === 'public'): ?>
<section class="panel">
  <h2 class="panel__title">Open to join</h2>
  <?php if (empty($joinable)): ?>
    <p class="lede" style="margin:0">Nothing published that you have not already joined.</p>
  <?php else: ?>
  <p class="lede" style="font-size:.9rem;margin-top:0">
    Shared libraries anybody signed in may read. Joining puts one in your library
    switcher; you can leave again at any time, and nothing you added goes with you.
  </p>
  <table class="table">
    <thead><tr><th>Library</th><th>You would get</th><th>Entries</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($joinable as $l): ?>
        <tr>
          <td>
            <span class="spine" style="background:<?= e((string) ($l['accent_color'] ?? '#cba6f7')) ?>"></span>
            <strong><?= e((string) $l['name']) ?></strong>
            <?php if (!empty($l['description'])): ?>
              <br><span class="hint"><?= e((string) $l['description']) ?></span>
            <?php endif; ?>
          </td>
          <?php // access_label(), so this column says what every other one says. ?>
          <td><?= e(access_label((int) $l['public_write'] === 1 ? ACCESS_CONTRIBUTOR : ACCESS_VIEWER)) ?></td>
          <td><?= (int) $l['entries'] ?></td>
          <td>
            <form method="post" action="<?= e(url('/libraries/' . (int) $l['id'] . '/join')) ?>">
              <?= csrf_field() ?>
              <button class="btn btn--sm btn--accent" type="submit">Join</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php endif; ?>



<?php if ($editing): ?>
<section class="panel" style="margin-top:1rem">
  <h2 class="panel__title">Who is in <?= e($editing['name']) ?></h2>
  <table class="table">
    <thead><tr><th>Person</th><th>Access</th><th>Granted</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($members as $m): ?>
      <tr>
        <td>
          <span style="display:flex;align-items:center;gap:.5rem">
            <?php partial('avatar', ['user' => $m, 'size' => 'sm']); ?>
            <span>
              <?= e($m['display_name'] ?: $m['username']) ?>
              <?php if ((int) $m['user_id'] === (int) ($editing['owner_id'] ?? 0)): ?>
                <span class="chip" style="margin-left:.3rem">owner</span>
              <?php endif; ?>
            </span>
          </span>
        </td>
        <td>
          <?= e(access_label((string) $m['access'])) ?>
          <br><span class="hint"><?= e(access_description((string) $m['access'])) ?></span>
        </td>
        <td class="mono" style="font-size:.76rem;color:var(--faint)">
          <?= e(substr((string) $m['granted_at'], 0, 10)) ?>
          <?php if ($m['granted_by_name']): ?><br>by <?= e($m['granted_by_name']) ?><?php endif; ?>
          <?php if ($m['note']): ?><br><span style="color:var(--warn)"><?= e($m['note']) ?></span><?php endif; ?>
        </td>
        <td style="text-align:right">
          <?php if ((int) $m['user_id'] !== (int) ($editing['owner_id'] ?? 0)): ?>
            <form method="post" action="<?= e(url('/profile/access')) ?>" style="display:inline"
                  data-confirm="Remove <?= e($m['username']) ?> from this library?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="revoke">
              <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
              <input type="hidden" name="user_id" value="<?= (int) $m['user_id'] ?>">
              <button class="btn btn--sm btn--danger" type="submit">Remove</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <form method="post" action="<?= e(url('/profile/access')) ?>" style="margin-top:1rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="grant">
    <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
    <div class="formgrid">
      <div class="field field--third">
        <label for="user_id">Add somebody</label>
        <select id="user_id" name="user_id" required>
          <?php foreach ($accounts as $a): ?>
            <option value="<?= (int) $a['id'] ?>"><?= e($a['display_name'] ?: $a['username']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field field--third">
        <label for="access">As</label>
        <select id="access" name="access">
          <?php
          // No Owner. There is one owner and it changes by being offered and accepted,
          // not by being handed out with an invitation.
          ?>
          <?php foreach ([ACCESS_VIEWER, ACCESS_CONTRIBUTOR, ACCESS_EDITOR, ACCESS_CURATOR, ACCESS_ADMIN] as $lvl): ?>
            <option value="<?= e($lvl) ?>"><?= e(access_label($lvl)) ?> — <?= e(access_description($lvl)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="formactions">
      <button class="btn btn--accent" type="submit">Add to library</button>
      <span class="hint">
        A contributor can add entries and change the ones they added, but not
        anybody else's. That is usually what you want for a shared shelf.
      </span>
    </div>
  </form>
</section>

<section class="panel" style="margin-top:1rem;border-left:4px solid var(--bad)">
  <h2 class="panel__title">Delete this library</h2>
  <p style="margin-top:0;font-size:.9rem;color:var(--dim)">
    Only possible once it is empty. Deleting a library should never be a way to
    lose a collection by accident.
  </p>
  <form method="post" action="<?= e(url('/profile/access')) ?>" data-confirm="Delete <?= e($editing['name']) ?>?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
    <button class="btn btn--danger btn--sm" type="submit">Delete library</button>
  </form>
</section>
<?php endif; ?>

<?php
// The machines box was here.
//
// It listed and edited this library's own platforms, which is what Manage > Platforms
// is for - the same rows, two screens, and the one here knew less. Removed rather than
// kept in step.
?>

