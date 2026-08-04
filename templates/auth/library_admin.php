<?php
/** @var array $others */
/*
 * Library management, for administrators.
 *
 * Separate from /profile/access on purpose. That page answers "what can I reach, what
 * am I being offered, what could I join" - questions about the person looking. This one
 * answers "what exists on this server and what can I do about it", which is a different
 * job for a different reason, and mixing them made one long page where the useful half
 * depended on who you were.
 */
?>
<div class="pagehead">
  <div>
    <p class="label">This server</p>
    <h1>Library management</h1>
    <p class="lede">
      Every library on the instance, including ones you are not a member of. Personal
      shelves are not listed — those belong to the account that owns them.
    </p>
  </div>
</div>

<?php if ($others !== []): ?>
<section class="panel" style="margin-top:1rem">
  <h2 class="panel__title">Every library on this instance</h2>
  <p class="lede" style="font-size:.9rem">
    You are an administrator, so this is all of them — including ones you are not a
    member of. Personal shelves are not listed: those belong to the account that owns
    them. Adding yourself to a library is recorded against it either way.
  </p>
  <table class="table">
    <thead>
      <tr>
        <th>Library</th><th>Owner</th><th>Visibility</th>
        <th class="num">Entries</th><th class="num">Members</th>
        <th>State</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($others as $l): $off = (int) ($l['is_active'] ?? 1) !== 1; ?>
      <tr<?= $off ? ' style="opacity:.55"' : '' ?>>
        <td>
          <span class="spine" style="background:<?= e((string) ($l['accent_color'] ?? '#cba6f7')) ?>"></span>
          <strong><?= e((string) $l['name']) ?></strong>
        </td>
        <td><?= e((string) ($l['owner_name'] ?? 'nobody')) ?></td>
        <td>
          <?= e((string) ($l['kind'] ?? 'private')) ?>
          <?php if ((int) ($l['public_write'] ?? 0) === 1): ?>
            <span class="chip">open to write</span>
          <?php elseif ((int) ($l['public_read'] ?? 0) === 1): ?>
            <span class="chip">open to read</span>
          <?php endif; ?>
        </td>
        <td class="num"><?= (int) ($l['entries'] ?? 0) ?></td>
        <td class="num"><?= (int) ($l['members'] ?? 0) ?></td>
        <td>
          <?php if ($off): ?>
            <span class="chip" style="background:var(--bad);color:var(--crust)">disabled</span>
          <?php else: ?>
            <span class="hint">active</span>
          <?php endif; ?>
        </td>
        <td style="text-align:right;white-space:nowrap">
          <?php
          // Manage opens the same editor an owner gets - settings, members, invitations
          // - rather than a second, thinner one that would drift out of step with it.
          ?>
          <?php
          // The library's own editor, which is where every setting already lives.
          //
          // This linked back to this page with ?edit=, and the page never did anything
          // with it - the parameter was read, passed to the template and ignored, so
          // Manage reloaded the list and looked broken. One editor, reached from both
          // places, rather than a second thinner one that would drift out of step.
          ?>
          <?php
          // The administrator's own editor, not the owner's. That one carries template
          // resynchronisation, per-library platforms, visibility, invitations and the
          // ownership offer - none of which an administrator opening somebody else's
          // library is here to do.
          ?>
          <a class="btn btn--sm" href="<?= e(url('/manage/libraries/' . (int) $l['id'])) ?>">Manage</a>
          <a class="btn btn--sm" href="<?= e(url('/manage/libraries/' . (int) $l['id'] . '/contents')) ?>">Contents</a>

          <form method="post" action="<?= e(url('/manage/libraries')) ?>" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
            <input type="hidden" name="action" value="<?= $off ? 'enable' : 'disable' ?>">
            <button class="btn btn--sm" type="submit"><?= $off ? 'Enable' : 'Disable' ?></button>
          </form>

          <?php
          // One click deletes an empty one; a full one is deleted from its own screen,
          // where the name has to be typed and the page says what will go. Both end in
          // the same place, but a collection should not be destroyable from a row in a
          // list by a mis-click.
          ?>
          <?php if ((int) ($l['entries'] ?? 0) === 0): ?>
            <form method="post" action="<?= e(url('/manage/libraries')) ?>" style="display:inline"
                  data-confirm="Delete <?= e((string) $l['name']) ?>? It holds no entries, but this cannot be undone.">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
              <input type="hidden" name="action" value="admin-delete">
              <button class="btn btn--sm btn--danger" type="submit">Delete</button>
            </form>
          <?php else: ?>
            <a class="btn btn--sm btn--danger"
               href="<?= e(url('/manage/libraries/' . (int) $l['id'])) ?>">Delete…</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>


<?php
// No create form here. The header carries a Create button, which is where somebody
// reaches for it, and a management screen listing what exists does not also need to be
// the place things are made.
?>
