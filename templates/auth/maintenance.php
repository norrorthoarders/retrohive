<?php
/**
 * Maintenance, for the server.
 *
 * Instance jobs only. The library ones live in the library editor, where
 * somebody is already looking at the library they are about.
 *
 * @var array $instance @var array $results
 */
?>
?>

<div class="pagehead">
  <div>
    <h1>Maintenance</h1>
    <p class="lede">
      Checks that read, and repairs that act only where a check found something.
      Nothing here runs on its own.
    </p>
  </div>
</div>

<?php foreach ($instance as $key => $job): ?>
  <?php partial('maintenance_panel', ['key' => $key, 'job' => $job,
                                      'found' => $results[$key], 'libId' => null]); ?>
<?php endforeach; ?>
