<?php
/**
 * Cards or table, on either browser.
 *
 * Both listings read the same view (`v_items`), so both can be drawn either way -
 * the two screens simply grew different halves. Software had a card grid and a
 * row view reachable only by typing ?view=list, and hardware had a table and
 * nothing else.
 *
 * The default differs on purpose and follows what each half is: a shelf of boxed
 * software is recognised by its cover, and a list of machines and cards is read
 * by model and type, where a column of identical grey rectangles helps nobody.
 *
 * @var string $view    the mode in force
 * @var string $domain  'hardware' or 'software', for the default
 */
$isTable = ($view ?? '') === 'table';
?>
<div class="viewswitch" style="display:flex;gap:.4rem;align-items:center">
  <a class="btn btn--sm <?= $isTable ? '' : 'btn--accent' ?>"
     href="<?= e(with_query(['view' => 'cards', 'page' => null])) ?>"
     aria-pressed="<?= $isTable ? 'false' : 'true' ?>">Cards</a>
  <a class="btn btn--sm <?= $isTable ? 'btn--accent' : '' ?>"
     href="<?= e(with_query(['view' => 'table', 'page' => null])) ?>"
     aria-pressed="<?= $isTable ? 'true' : 'false' ?>">Table</a>
</div>
