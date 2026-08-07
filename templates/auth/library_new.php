<?php
/**
 * Creating a library.
 *
 * Its own page, not the edit form with an empty row, because the decision it asks
 * for is one you make exactly once: what the library starts out holding. Renaming
 * a library later is a different and much smaller act.
 *
 * @var array $structureCounts
 */
$t = $structureCounts;
$has = ($t['platforms'] + $t['vendors'] + $t['models'] + $t['parts']) > 0;
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Libraries</span>
    <h1>Create a library</h1>
  </div>

</div>

<form method="post" action="<?= e(url('/libraries/new')) ?>" class="panel">
  <?= csrf_field() ?>

  <fieldset>
    <legend>What it is</legend>
    <div class="formgrid">
      <div class="field formgrid--wide <?= form_error('name') ? 'field--error' : '' ?>">
        <label for="name">Library name</label>
        <input id="name" name="name" type="text" required maxlength="120" autofocus
               value="<?= e((string) old('name')) ?>" placeholder="The Amiga shelf">
        <?= field_hint('name') ?>
      </div>

      <div class="field field--half">
        <label for="kind">Library type</label>
        <select id="kind" name="kind" data-library-kind>
          <option value="private">Private — only people you invite</option>
          <option value="shared">Shared — for cataloguing with others</option>
        </select>
        <span class="hint">A private library invites readers only. Shared can also be opened to everyone.</span>
      </div>

      <div class="field field--half" data-shared-only hidden>
        <?php
        // One control, not two checkboxes. "Members only" and "public" are the two
        // states a shared library can be in, and expressing them as a pair of
        // independent ticks let you say things that are not states - public-write
        // without public-read, for one.
        ?>
        <label for="visibility">Who can see it</label>
        <select id="visibility" name="visibility">
          <option value="members">Members only — invite people, even to read</option>
          <option value="public">Public — everyone signed in can read it</option>
        </select>
        <span class="hint">Public always means read-only for whoever joins. Invite somebody directly for anything more — a contributor, an editor, up to an administrator — the same as a members-only library.</span>
      </div>

      <div class="field field--quarter">
        <label for="accent_color">Colour</label>
        <input id="accent_color" name="accent_color" type="color" value="#cba6f7">
      </div>

      <div class="field formgrid--wide">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="3"><?= e((string) old('description')) ?></textarea>
      </div>
    </div>
  </fieldset>

  <?php
// No "what it starts with" here.
//
// A library is created empty and filled from its own page, where the choice is laid out
// properly - seven things to tick, each saying what it copies - rather than two
// checkboxes on a form whose real job is a name and a colour. It also means the same
// screen answers "what is in this library" and "put more in it", instead of one screen
// deciding it once and another one topping it up.
?>

  <div class="formactions">
    <button class="btn btn--accent" type="submit">Create it</button>
    <a class="btn" href="<?= e(url('/collection')) ?>">Cancel</a>
  </div>
</form>
