<?php
/**
 * Somebody arrived at the root of an instance that does not know where its own
 * interface is.
 *
 * This application serves an API and nothing a person reads, so the root has to
 * send them somewhere - and it can only do that if it has been told where. Said
 * plainly rather than guessed at: redirecting to a path that might be right is
 * how somebody ends up on a second error page wondering which one is the real
 * problem.
 */
?>
<div class="empty">
  <h2>No interface configured</h2>
  <p>
    This address serves the API. The address people use is set under
    <strong>Instance settings &rsaquo; General &rsaquo; Where people go</strong>,
    and has not been filled in.
  </p>
  <p class="hint">
    Until it is, open the web client directly. Confirmation and invitation links
    are built from the same setting, so they will point here rather than at the
    client too.
  </p>
</div>
