<?php
/**
 * A table heading you can sort by.
 *
 * Clicking cycles the column: ascending, then descending, then back to
 * ascending. The arrow shows which way it is going *now* rather than what
 * clicking would do - a header that shows the action rather than the state
 * leaves you unable to tell how the list is currently ordered.
 *
 * Ordinary links, so this works with JavaScript off and a sorted view is an
 * address somebody can keep.
 *
 * @var string $label  what the column is called
 * @var string $key    the sort name, without the _desc
 * @var string $sort   the sort in force
 */
$asc  = (string) $key;
$desc = $key . '_desc';
$isAsc  = ($sort ?? '') === $asc;
$isDesc = ($sort ?? '') === $desc;
$next   = $isAsc ? $desc : $asc;
?>
<th class="<?= $isAsc || $isDesc ? 'is-sorted' : '' ?>">
  <a href="<?= e(with_query(['sort' => $next, 'page' => null])) ?>"
     title="Sort by <?= e(strtolower($label)) ?>">
    <?= e($label) ?><?php if ($isAsc): ?> <span aria-hidden="true">↑</span><?php
      elseif ($isDesc): ?> <span aria-hidden="true">↓</span><?php endif; ?>
  </a>
</th>
