<?php
/** @var array $libraries @var array|null $report @var array $columns */
?>

<div class="pagehead">
  <div>
    <h1>Import CSV</h1>
    <p class="lede">
      The way back in from <a href="<?= e(url('/items/export.csv')) ?>">Export CSV</a>.
      Export, edit a hundred rows in a spreadsheet, put them back — a row that
      carries its <strong>ID</strong> updates that entry, a row without one
      creates a new entry.
    </p>
  </div>
</div>

<?php if ($libraries === []): ?>
  <div class="empty">
    <h2>Nowhere to put anything</h2>
    <p>You need write access to at least one library before you can import.</p>
  </div>
<?php else: ?>

<form method="post" action="<?= e(url('/import')) ?>" enctype="multipart/form-data" class="form">
  <?= csrf_field() ?>

  <div class="fieldrow">
    <div class="field field--half">
      <label for="csv">CSV file</label>
      <input id="csv" name="csv" type="file" accept=".csv,text/csv" required>
      <span class="hint">UTF-8. A byte-order mark from Excel is fine.</span>
    </div>
    <div class="field field--half">
      <label for="library_id">Default library</label>
      <select id="library_id" name="library_id" required>
        <?php foreach ($libraries as $lib): ?>
          <option value="<?= (int) $lib['id'] ?>"><?= e($lib['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="hint">Used for rows whose “Library” column is empty.</span>
    </div>
  </div>

  <div class="field">
    <label class="checkline">
      <input type="checkbox" name="create_titles" value="1">
      Create a title for each row
    </label>
    <span class="hint">
      Records the game itself once, so a second copy of something does not mean
      retyping its metadata. Leave it off for a first messy import and add
      titles later.
    </span>
  </div>

  <div style="display:flex;gap:.6rem;margin-top:1.25rem;align-items:center">
    <button class="btn btn--accent" type="submit">Check the file</button>
    <span class="hint" style="margin:0">
      Nothing is written until you have seen what it would do.
    </span>
  </div>
</form>

<?php if ($report !== null): ?>
  <section class="panel" style="margin-top:1.5rem;border-left:3px solid var(--<?= $report['errors'] === [] ? 'good' : 'bad' ?>)">
    <h2 class="panel__title">What this file would do</h2>

    <div class="stats" style="margin-bottom:1rem">
      <div class="stat">
        <span class="stat__n"><?= (int) $report['create_count'] ?></span>
        <span class="stat__label">new</span>
      </div>
      <div class="stat">
        <span class="stat__n"><?= (int) $report['update_count'] ?></span>
        <span class="stat__label">updated</span>
      </div>
      <div class="stat">
        <span class="stat__n"><?= count($report['errors']) ?></span>
        <span class="stat__label">errors</span>
      </div>
      <div class="stat">
        <span class="stat__n"><?= count($report['warnings']) ?></span>
        <span class="stat__label">warnings</span>
      </div>
    </div>

    <?php if ($report['errors']): ?>
      <h3 style="font-size:.95rem">Errors — nothing will be written until these are fixed</h3>
      <ul style="font-size:.88rem;color:var(--bad)">
        <?php foreach ($report['errors'] as $err): ?>
          <li><?= e($err) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($report['warnings']): ?>
      <h3 style="font-size:.95rem">Worth a look, but not blocking</h3>
      <ul style="font-size:.88rem;color:var(--warn)">
        <?php foreach (array_slice($report['warnings'], 0, 50) as $warn): ?>
          <li><?= e($warn) ?></li>
        <?php endforeach; ?>
        <?php if (count($report['warnings']) > 50): ?>
          <li>… and <?= count($report['warnings']) - 50 ?> more.</li>
        <?php endif; ?>
      </ul>
    <?php endif; ?>

    <?php if ($report['rows']): ?>
      <details>
        <summary style="cursor:pointer;color:var(--dim);font-size:.88rem">
          First 25 rows as they were understood
        </summary>
        <table class="table" style="margin-top:.6rem;font-size:.85rem">
          <thead><tr><th></th><th>Title</th><th>Platform</th><th>Year</th><th>Condition</th></tr></thead>
          <tbody>
            <?php foreach (array_slice($report['rows'], 0, 25) as $row): ?>
            <tr>
              <td><span class="chip"><?= $row['mode'] === 'update' ? 'update #' . (int) $row['id'] : 'new' ?></span></td>
              <td><?= e($row['title']) ?></td>
              <td class="mono"><?= (int) $row['data']['platform_id'] ?></td>
              <td class="mono"><?= e((string) ($row['data']['release_year'] ?? '—')) ?></td>
              <td><?= e(condition_label($row['data']['condition_grade'] ?? 'unknown')) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endif; ?>

    <?php if ($report['errors'] === [] && $report['rows']): ?>
      <p class="hint" style="margin-top:1rem">
        Re-select the same file and confirm. It is read again rather than
        remembered, so nothing can drift between the check and the write.
      </p>
      <form method="post" action="<?= e(url('/import')) ?>" enctype="multipart/form-data"
            style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="commit" value="1">
        <input type="hidden" name="library_id" value="<?= (int) $report['library_id'] ?>">
        <?php if (!empty($report['create_titles'])): ?>
          <input type="hidden" name="create_titles" value="1">
        <?php endif; ?>
        <div class="field" style="margin:0">
          <label for="csv2">The same file again</label>
          <input id="csv2" name="csv" type="file" accept=".csv,text/csv" required>
        </div>
        <button class="btn btn--accent" type="submit">Import for real</button>
      </form>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section class="panel" style="margin-top:1.5rem">
  <h2 class="panel__title">Columns</h2>
  <p class="hint" style="margin-top:-.4rem">
    Order does not matter and unknown columns are ignored; only <strong>Title</strong>
    is required. Condition, completeness and status accept either the label the
    export writes or the value stored underneath. Dates must be YYYY-MM-DD —
    03/04/2019 is refused rather than guessed at.
  </p>
  <div class="chips">
    <?php foreach (array_keys($columns) as $label): ?>
      <span class="chip"><?= e($label) ?></span>
    <?php endforeach; ?>
  </div>
</section>

<?php endif; ?>
