<?php
/**
 * Editing one library.
 *
 * Its own page, and only about this library: its settings, what it holds, the
 * resync, its members. Everything a library owns is its own, so the links to the
 * per-library managers carry the library with them rather than leaving you to pick
 * it again once you arrive.
 *
 * @var array $library @var array $holds @var array $members @var array $structureCounts
 */
$lib    = $library;
$shared = ($lib['kind'] ?? 'private') === 'shared';
$personal = (int) ($lib['is_personal'] ?? 0) === 1;
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">
      Library
      <?= $personal ? '· personal' : ($shared ? '· shared' : '· private') ?>
    </span>
    <h1>
      <span class="spine" style="background:<?= e((string) $lib['accent_color']) ?>"></span>
      <?= e((string) $lib['name']) ?>
    </h1>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <?php
    // What is actually in it. The administrator's screens have had this since the
    // force-delete was written, and an owner wanting to know what a library holds
    // is the more ordinary version of the same question.
    ?>
    <a class="btn" href="<?= e(url('/manage/libraries/' . (int) $lib['id'] . '/contents')) ?>">What it holds</a>
    <a class="btn" href="<?= e(url('/collection', ['library' => $lib['slug']])) ?>">Browse it</a>
    <a class="btn" href="<?= e(url('/libraries/new')) ?>">Create another</a>
  </div>
</div>

<section class="panel">
  <?php
  // A notice, not a dashboard. Counting what the library holds put six numbers on a
  // page whose job is to change settings, and each number was a worse version of the
  // screen it linked to.
  ?>
  <p class="lede" style="margin:0">
    You are editing
    <strong><?= e((string) $lib['name']) ?></strong> —
    <?= $personal ? 'your personal library' : ($shared ? 'a shared library' : 'a private library') ?>.
    Everything below applies to this one and no other.
  </p>
  <p class="hint" style="margin:.7rem 0 0">
    Its makers, platforms, models, types, genres and studios are managed on their own
    screens, each carrying this library with it:
  </p>
  <div class="chips" style="margin-top:.5rem">
    <?php foreach ([
      ['Companies',         '/manage/companies'],
      ['Platforms',         '/manage/platforms'],
      ['Machine models',    '/manage/models'],
      ['Peripheral models', '/manage/parts'],
      ['Categories',        '/manage/tree'],
      ['Developers',        '/manage/companies'],
      ['Locations',         '/manage/locations'],
    ] as [$label, $path]): ?>
      <a class="chip" href="<?= e(url($path, ['library' => $lib['slug']])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</section>

<form method="post" action="<?= e(url('/libraries/' . (int) $lib['id'])) ?>" class="panel">
  <?= csrf_field() ?>
  <h2 class="panel__title">Settings</h2>
  <div class="formgrid">
    <div class="field formgrid--wide <?= form_error('name') ? 'field--error' : '' ?>">
      <label for="name">Name</label>
      <input id="name" name="name" type="text" required maxlength="120"
             value="<?= e((string) old('name', $lib['name'])) ?>">
      <?= field_hint('name') ?>
    </div>

    <div class="field field--half">
      <label for="kind">Library type</label>
      <?php if ($personal): ?>
        <p class="hint" style="margin:.3rem 0 0">
          <strong>Personal.</strong> It cannot be shared — it is the one shelf this
          account is always guaranteed to be able to write to.
        </p>
      <?php else: ?>
        <select id="kind" name="kind" data-library-kind>
          <option value="private" <?= $shared ? '' : 'selected' ?>>Private — only people you invite</option>
          <option value="shared"  <?= $shared ? 'selected' : '' ?>>Shared — for cataloguing with others</option>
        </select>
      <?php endif; ?>
    </div>

    <?php if (!$personal): ?>
      <?php
      // Always read-only for anybody who joins through the general
      // button now - there is no second, write-granting public state
      // any more. A higher role for a specific person still goes
      // through inviting them directly, unaffected by this choice.
      $vis = (int) ($lib['public_read'] ?? 0) === 1 ? 'public' : 'members';
      ?>
      <div class="field field--half" data-shared-only <?= $shared ? '' : 'hidden' ?>>
        <label for="visibility">Who can see it</label>
        <select id="visibility" name="visibility">
          <option value="members" <?= $vis === 'members' ? 'selected' : '' ?>>Members only — invite people, even to read</option>
          <option value="public"  <?= $vis === 'public' ? 'selected' : '' ?>>Public — everyone signed in can read it</option>
        </select>
        <span class="hint">
          Public means anybody signed in sees it under <em>Open to join</em> and can add
          it to their own shelf as a Viewer, read-only. For anything more, invite
          somebody directly — a contributor, an editor, up to an administrator.
          Turning it back to members-only removes the people who joined that way;
          anybody you invited stays. An accepted invitation always wins over this.
        </span>
      </div>
    <?php endif; ?>

    <div class="field field--quarter">
      <label for="accent_color">Shelf colour</label>
      <input id="accent_color" name="accent_color" type="color"
             value="<?= e((string) $lib['accent_color']) ?>">
    </div>

    <div class="field formgrid--wide">
      <label for="description">Description</label>
      <textarea id="description" name="description" rows="3"><?= e((string) ($lib['description'] ?? '')) ?></textarea>
    </div>
  </div>
  <div class="formactions">
    <button class="btn btn--accent" type="submit">Save</button>
  </div>
</form>

<?php
// Handing the library over.
//
// Only to somebody who has already joined: being invited and accepting is how you agree
// to be in a library at all, and being made responsible for one without that is a step
// skipped. The offer waits until they accept - see library_ownership_respond().
$candidates = library_transfer_candidates((int) $lib['id']);
$pendingTo  = (int) ($lib['pending_owner_id'] ?? 0);
?>
<?php if (is_library_owner(current_user(), (int) $lib['id']) && (int) $lib['is_personal'] !== 1): ?>
<section class="panel" style="margin-top:1rem">
  <h2 class="panel__title">Hand this library over</h2>

  <?php if ($pendingTo > 0): ?>
    <?php $who = one('SELECT username, display_name FROM users WHERE id = ?', [$pendingTo]); ?>
    <p class="lede" style="font-size:.9rem;margin-top:0">
      Offered to <strong><?= e((string) ($who['display_name'] ?: $who['username'] ?? 'somebody')) ?></strong>.
      It stays yours until they accept.
    </p>
    <form method="post" action="<?= e(url('/libraries/' . (int) $lib['id'] . '/ownership/withdraw')) ?>">
      <?= csrf_field() ?>
      <button class="btn btn--sm" type="submit">Withdraw the offer</button>
    </form>
  <?php elseif ($candidates === []): ?>
    <p class="lede" style="font-size:.9rem;margin:0">
      Nobody else has joined this library yet. Invite somebody and wait for them to
      accept, and they can be offered it.
    </p>
  <?php else: ?>
    <p class="lede" style="font-size:.9rem;margin-top:0">
      They have to accept before anything changes. You stay on as a Library Admin afterwards — everything you have now except the library itself.
    </p>
    <form method="post" action="<?= e(url('/libraries/' . (int) $lib['id'] . '/offer')) ?>"
          style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
      <?= csrf_field() ?>
      <div class="field" style="margin:0">
        <label for="to">Hand it to</label>
        <select id="to" name="to">
          <?php foreach ($candidates as $c): ?>
            <option value="<?= (int) $c['id'] ?>">
              <?= e((string) ($c['display_name'] ?: $c['username'])) ?>
              — <?= e(access_label((string) $c['access'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn--accent" type="submit">Offer it</button>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<form method="post" action="<?= e(url('/libraries/' . (int) $lib['id'])) ?>" class="panel">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="resync">
  <h2 class="panel__title">Synchronise template data from the internet</h2>
  <p class="lede" style="font-size:.9rem;margin-top:0">
    Fetches the latest structure from the repository and copies anything this library
    does not have yet.
  </p>
  <?php
  // What this library holds, against what there is to copy.
  //
  // This table was on the instance settings page, counting the template set
  // against the files it came from - one answer for the whole instance, when the
  // question anybody actually has is whether *this* library is behind. The
  // address to fetch from is still instance-wide and still lives there.
  //
  // A row is marked only when this library has fewer. The branch counts are
  // legitimately larger: the filing tree is built once per platform, so
  // twenty-six template branches become a thousand of them here.
  ?>
  <table class="table" style="margin:.4rem 0 1rem">
    <thead>
      <tr>
        <th>Holds</th>
        <th style="text-align:right">In this library</th>
        <th style="text-align:right">Available</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach (($structureRows ?? []) as $i => $available): ?>
        <?php
        $mine   = (int) ($libraryRows[$i]['n'] ?? 0);
        $behind = $mine < (int) $available['n'];
        ?>
        <tr>
          <td><?= e((string) $available['holds']) ?></td>
          <td style="text-align:right<?= $behind ? ';color:var(--bad);font-weight:600' : '' ?>">
            <?= $mine ?>
          </td>
          <td style="text-align:right"><?= (int) $available['n'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php
  // Off by default, and deliberately so.
  //
  // The additive pass matches on slug and skips anything already here, which means a
  // maker whose country you corrected or a model whose specs you fixed is left alone.
  // Ticking this throws that away: an existing row is assumed to have been edited on
  // purpose, so overwriting is a decision rather than a side effect of pressing sync.
  ?>
  <?php
  // Pick what to copy.
  //
  // It was one button meaning "all of it", which is fine for a new shelf and wrong for
  // a curated one: somebody who wants this year's platforms does not necessarily want
  // three and a half thousand categories, and somebody building a filing tree does not
  // want four example entries dropped into it.
  //
  // Anything a part depends on comes with it - a model needs a category, a category
  // needs a platform - so the boxes cannot produce an incoherent library.
  $syncParts = [
      'makers'          => ['Makers and publishers', (int) ($structureCounts['vendors'] ?? 0) . ' companies'],
      'platforms'       => ['Platforms',             (int) ($structureCounts['platforms'] ?? 0) . ' machines'],
      'categories'      => ['Category trees',        'one filing tree per platform'],
      'hardware_models' => ['Hardware models',       (int) ($structureCounts['models'] ?? 0) + (int) ($structureCounts['parts'] ?? 0) . ' machines and parts'],
      'software_models' => ['Software models',       'what a boxed release holds'],
      'environments'    => ['Environments',          'what each machine runs'],
      'locations'       => ['Locations',             'a starting shelf layout'],
  ];
  ?>
  <div class="formgrid" style="margin:.6rem 0">
    <?php
    // Locations start unticked.
    //
    // The others are reference data - platforms, makers, models, the filing tree - and
    // copying them into a library is what synchronising is for. A shelf layout is not
    // reference data: it is a guess about somebody's house, and a resync that quietly
    // adds "Retroway 22 > Basement > Book Shelf 1" to a library whose owner has their
    // own rooms already is putting furniture in the wrong place.
    //
    // Still available, still one tick. Just not assumed.
    ?>
    <?php foreach ($syncParts as $key => [$label, $note]): ?>
      <div class="field field--third">
        <label class="checkline">
          <input type="checkbox" name="parts[]" value="<?= e($key) ?>"
                 <?= $key === 'locations' ? '' : 'checked' ?>>
          <span><?= e($label) ?> <span class="hint">— <?= e($note) ?></span></span>
        </label>
      </div>
    <?php endforeach; ?>
  </div>

  <label class="checkline" style="margin:.6rem 0">
    <input type="checkbox" name="with_examples" value="1">
    Also add a few example entries
  </label>
  <span class="hint" style="display:block;margin:-.3rem 0 .6rem">
    Two machines, two cards and two boxed programs, to show what a filled-in entry looks
    like. Leave it clear on a shelf you are curating.
  </span>

  <label class="checkline" style="margin:.6rem 0">
    <input type="checkbox" name="overwrite" value="1">
    Overwrite rows this library already has
  </label>
  <span class="hint" style="display:block;margin:-.3rem 0 .6rem">
    Leave this clear to add only what is missing. Ticked, anything with a matching slug
    is replaced from the repository — including edits you have made here.
  </span>

  <div class="formactions">
    <button class="btn" type="submit">Resync this library</button>
  </div>
</form>

<section class="panel">
  <h2 class="panel__title">Library members</h2>
  <p class="lede" style="font-size:.9rem;margin-top:0">
    <?php if ($shared): ?>
      Shared, so members can be given any level from Library Viewer up to Library Admin. Owner is handed over rather than granted.
    <?php else: ?>
      Private, so members can read it and nothing more. Make it shared above to let
      somebody add to it.
    <?php endif; ?>
    An invitation gives no access until the person accepts it.
  </p>

  <?php if ($members !== []): ?>
    <table class="table">
      <thead>
        <tr>
          <th>Who</th><th>Email</th><th>Access</th><th>State</th><th>Added</th><th>By</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($members as $m): ?>
          <?php $isOwner = (int) $m['user_id'] === (int) $lib['owner_id']; ?>
          <tr>
            <td>
              <?php partial('avatar', ['user' => $m, 'size' => 24]); ?>
              <?= e((string) ($m['display_name'] ?: $m['username'])) ?>
              <?php if ($m['display_name']): ?>
                <span class="hint">· <?= e((string) $m['username']) ?></span>
              <?php endif; ?>
              <?php if ($isOwner): ?><span class="chip">owner</span><?php endif; ?>
            </td>
            <td><span class="hint mono" style="font-size:.78rem"><?= e((string) ($m['email'] ?? '')) ?></span></td>
            <td>
              <?php
              // Changeable, once they have accepted. The level was fixed at the
              // moment of inviting and never again, so somebody who joined as a
              // reader stayed one whatever they went on to do for the library.
              //
              // Never the owner's own row: an owner who could be demoted from
              // this table could be locked out of their own library by a curator
              // they invited. Handing the library to somebody else is its own
              // deliberate act, not a dropdown.
              ?>
              <?php if ($isOwner || (string) $m['status'] !== 'accepted'): ?>
                <?= e(access_label((string) $m['access'])) ?>
                <?php if (!$isOwner): ?>
                  <br><span class="hint">settable once they accept</span>
                <?php endif; ?>
              <?php else: ?>
                <form method="post" action="<?= e(url('/libraries/' . (int) $lib['id'])) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="member_access">
                  <input type="hidden" name="user_id" value="<?= (int) $m['user_id'] ?>">
                  <select name="access" onchange="this.form.submit()" aria-label="Access level">
                    <?php foreach (access_levels() as $lvl): ?>
                      <?php if ($lvl === ACCESS_NONE) { continue; } ?>
                      <option value="<?= e($lvl) ?>" <?= (string) $m['access'] === $lvl ? 'selected' : '' ?>>
                        <?= e(access_label($lvl)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <noscript><button class="btn btn--sm" type="submit">Set</button></noscript>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <?php
              // Pending and accepted are different facts and the difference matters:
              // a pending row grants nothing at all.
              $state = (string) $m['status'];
              $colour = $state === 'accepted' ? 'var(--good)'
                      : ($state === 'declined' ? 'var(--bad)' : 'var(--warn)');
              ?>
              <span style="color:<?= $colour ?>"><?= e($state) ?></span>
            </td>
            <td class="mono" style="font-size:.78rem">
              <?= e($m['granted_at'] ? substr((string) $m['granted_at'], 0, 10) : '—') ?>
            </td>
            <td>
              <span class="hint"><?= e((string) ($m['granted_by_name'] ?? '—')) ?></span>
            </td>
            <td style="text-align:right">
              <?php if (!$isOwner): ?>
                <form method="post" action="<?= e(url('/libraries/' . (int) $lib['id'])) ?>" style="display:inline"
                      data-confirm="Remove <?= e((string) $m['username']) ?> from <?= e((string) $lib['name']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="uninvite">
                  <input type="hidden" name="user_id" value="<?= (int) $m['user_id'] ?>">
                  <button class="btn btn--sm btn--danger" type="submit">Remove</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php
  // A search box, not a gate. Empty lists everybody who could be added, because on an
  // instance with four accounts making somebody search first is ceremony. Plain GET,
  // so it works before any script has run.
  ?>
  <form method="get" action="<?= e(url('/libraries/' . (int) $lib['id'] . '/edit')) ?>"
        style="margin-top:1rem">
    <div class="formgrid">
      <div class="field field--half">
        <label for="member_q">Find someone to add</label>
        <input id="member_q" name="member_q" type="search" value="<?= e((string) $memberQuery) ?>"
               placeholder="Username, name or email">
      </div>
      <div class="field field--quarter" style="align-self:end">
        <button class="btn" type="submit">Search</button>
        <?php if ($memberQuery !== ''): ?>
          <a class="btn" href="<?= e(url('/libraries/' . (int) $lib['id'] . '/edit')) ?>">Show all</a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if ($invitable === []): ?>
    <p class="hint" style="margin:.4rem 0 0">
      <?= $memberQuery === '' ? 'Nobody left to add.' : 'Nothing matches that.' ?>
    </p>
  <?php else: ?>
    <table class="table" style="margin-top:.6rem">
      <tbody>
        <?php foreach ($invitable as $u): ?>
          <tr>
            <td>
              <?= e((string) ($u['display_name'] ?: $u['username'])) ?>
              <span class="hint">· <?= e((string) $u['username']) ?></span>
            </td>
            <td><span class="hint mono" style="font-size:.78rem"><?= e((string) ($u['email'] ?? '')) ?></span></td>
            <td style="text-align:right">
              <form method="post" action="<?= e(url('/libraries/' . (int) $lib['id'])) ?>"
                    style="display:inline-flex;gap:.4rem;align-items:center">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="invite">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <select name="access" aria-label="Access for <?= e((string) $u['username']) ?>">
                  <?php foreach ($grantable as $level): ?>
                    <option value="<?= e($level) ?>"><?= e(access_label($level)) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn--sm btn--accent" type="submit">Invite</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<?php
// Retiring a library: one box, at the end, with whichever ways out are available.
//
// Disabling and deleting answer the same question - "I do not want this here any more" -
// so they belong together rather than in two panels a page apart. Delete only appears
// where the instance allows it and you own the library; otherwise disabling is the
// answer and there is no button to wonder about.
$canDisable = is_library_owner(current_user(), (int) $lib['id']) || is_admin();
$canDelete  = may_delete_library(current_user(), (int) $lib['id']);
$isOff      = (int) ($lib['is_active'] ?? 1) !== 1;
?>
<?php
// Checks on the contents: above the retire panel and below everything that
// changes them, which is the order somebody works in - set it up, look at what is
// in it, and only then think about getting rid of it.
//
// These used to be on the server's maintenance page behind a library picker,
// which put "the whole database" and "this shelf of mine" under one heading.
?>
<?php if (($maintJobs ?? []) !== []): ?>
<section class="panel">
  <h2 class="panel__title">Checks</h2>
  <p class="hint" style="margin-top:0">
    Things that can drift out of agreement inside a library. They only read; a
    repair appears where one of them found something.
  </p>
  <?php foreach ($maintJobs as $mk => $mjob): ?>
    <?php partial('maintenance_panel', ['key' => $mk, 'job' => $mjob,
                                        'found' => $maintResults[$mk],
                                        'libId' => (int) $lib['id']]); ?>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ((int) ($lib['is_personal'] ?? 0) !== 1 && ($canDisable || $canDelete)): ?>
<section class="panel">
  <h2 class="panel__title">Retire this library</h2>

  <?php if ($isOff): ?>
    <p class="lede" style="font-size:.9rem;margin:0">
      Disabled. Nobody can reach it, including you — an administrator can put it back.
    </p>
  <?php elseif ($canDisable): ?>
    <form method="post" action="<?= e(url('/manage/libraries')) ?>" style="margin-bottom:.8rem"
          data-confirm="Disable <?= e((string) $lib['name']) ?>? Nobody will be able to reach it, including you.">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $lib['id'] ?>">
      <input type="hidden" name="action" value="disable">
      <button class="btn" type="submit">Disable it</button>
      <span class="hint">Hidden from everyone. Nothing is lost, and an administrator can undo it.</span>
    </form>
  <?php endif; ?>

  <?php if ($canDelete && !$isOff): ?>
    <form method="post" action="<?= e(url('/libraries/' . (int) $lib['id'])) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <?php
      // The name sits inline rather than above the box, because it used to be the
      // placeholder and vanished at the first keystroke - the thing you were copying
      // disappearing the moment you started.
      ?>
      <div class="confirmline">
        <span class="hint">Type</span>
        <code class="confirmline__name" data-confirm-target><?= e((string) $lib['name']) ?></code>
        <input id="confirm_name" name="confirm_name" type="text" autocomplete="off"
               spellcheck="false" class="confirmline__input" data-confirm-input
               aria-label="Type the library name to confirm">
        <button class="btn btn--danger" type="submit">Delete library</button>
        <span class="hint">Entries must be moved out first. Not recoverable.</span>
      </div>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>
