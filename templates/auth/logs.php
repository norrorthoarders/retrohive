<?php
/** @var string $channel @var array $filters @var array $rows @var array $events @var int $page @var array $counts */
?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Server</span>
    <h1>Instance Logs</h1>
  </div>
</div>

<nav class="tabs" aria-label="Log streams">
  <a href="<?= e(url('/admin/logs', ['channel' => 'all'])) ?>"
     class="tabs__tab <?= $channel === 'all' ? 'is-current' : '' ?>">
    Everything <span class="hint">· <?= (int) $counts['all'] ?></span>
  </a>
  <a href="<?= e(url('/admin/logs', ['channel' => 'security'])) ?>"
     class="tabs__tab <?= $channel === 'security' ? 'is-current' : '' ?>">
    Security <span class="hint">· <?= (int) $counts['security'] ?></span>
  </a>
  <a href="<?= e(url('/admin/logs', ['channel' => 'server'])) ?>"
     class="tabs__tab <?= $channel === 'server' ? 'is-current' : '' ?>">
    Server <span class="hint">· <?= (int) $counts['server'] ?></span>
  </a>
  <?php
  // Its own stream. A lookup is the one thing here that reaches out to somebody
  // else's server, so "did it answer, how fast, and how much" is a question
  // asked often enough to deserve not being filtered out of everything else.
  ?>
  <a href="<?= e(url('/admin/logs', ['channel' => 'metadata'])) ?>"
     class="tabs__tab <?= $channel === 'metadata' ? 'is-current' : '' ?>">
    Lookups <span class="hint">· <?= (int) ($counts['metadata'] ?? 0) ?></span>
  </a>
</nav>

<form class="filters" method="get" action="<?= e(url('/admin/logs')) ?>">
  <input type="hidden" name="channel" value="<?= e($channel) ?>">
  <div class="filters__grid">
    <div class="field">
      <label for="f-q">Search</label>
      <input id="f-q" name="q" type="search" value="<?= e((string) $filters['q']) ?>"
             placeholder="Message or who did it">
    </div>
    <div class="field">
      <label for="f-event">Event</label>
      <select id="f-event" name="event">
        <option value="">Anything</option>
        <?php foreach ($events as $ev): ?>
          <option value="<?= e($ev) ?>" <?= $filters['event'] === $ev ? 'selected' : '' ?>><?= e($ev) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="f-sev">At least</label>
      <select id="f-sev" name="severity">
        <option value="">Anything</option>
        <?php foreach ([3 => 'Error', 4 => 'Warning', 5 => 'Notice', 6 => 'Info'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= (string) $filters['severity'] === (string) $v ? 'selected' : '' ?>>
            <?= e($l) ?> and worse
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="f-since">Since</label>
      <input id="f-since" name="since" type="date" value="<?= e((string) $filters['since']) ?>">
    </div>
  </div>
  <div class="filters__actions">
    <button class="btn" type="submit">Filter</button>
    <a class="btn" href="<?= e(url('/admin/logs', ['channel' => $channel])) ?>">Clear</a>
  </div>
</form>

<?php if ($rows === []): ?>
  <div class="empty">
    <h2>Nothing here</h2>
    <p>
      <?php if ($channel === 'security'): ?>
        No sign-ins, grants or refusals recorded yet.
      <?php elseif ($channel === 'server'): ?>
        Nothing has been created, changed or configured yet.
      <?php else: ?>
        Nothing has happened yet.
      <?php endif; ?>
    </p>
  </div>
<?php else: ?>
<section class="panel">
  <table class="table">
    <thead>
      <tr>
        <th style="width:10rem">When</th>
        <th style="width:6rem">Level</th>
        <?php if ($channel === 'all'): ?><th style="width:6rem">Stream</th><?php endif; ?>
        <th style="width:12rem">Event</th>
        <th>What happened</th>
        <th style="width:9rem">Who</th>
        <th style="width:9rem">From</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $sev   = (int) $r['severity'];
        $colour = $sev <= 3 ? 'var(--bad)' : ($sev === 4 ? 'var(--warn)' : 'var(--faint)');
      ?>
      <tr>
        <td class="mono" style="font-size:.78rem"><?= e(date('j M H:i:s', strtotime((string) $r['created_at']))) ?></td>
        <td><span class="chip" style="color:<?= $colour ?>"><?= e(log_severity_label($sev)) ?></span></td>
        <?php if ($channel === 'all'): ?>
          <td class="hint" style="font-size:.78rem"><?= e($r['channel']) ?></td>
        <?php endif; ?>
        <td class="mono" style="font-size:.78rem"><?= e($r['event']) ?></td>
        <td>
          <?= e($r['message']) ?>
          <?php if ($r['context']): ?>
            <?php $ctx = json_decode((string) $r['context'], true); ?>
            <?php if (is_array($ctx) && $ctx !== []): ?>
              <span class="hint" style="display:block">
                <?php foreach ($ctx as $k => $v): ?>
                  <?= e((string) $k) ?>=<?= e(is_scalar($v) ? (string) $v : json_encode($v)) ?>
                <?php endforeach; ?>
              </span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td class="hint"><?= e($r['actor_name'] ?: '—') ?></td>
        <td class="mono hint" style="font-size:.75rem">
          <?php $ip = $r['ip'] === null ? null : @inet_ntop($r['ip']); ?>
          <?= e($ip ?: '—') ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (count($rows) >= 100): ?>
    <p style="margin-bottom:0">
      <a class="btn btn--sm" href="<?= e(url('/admin/logs', array_filter([
          'channel' => $channel, 'page' => $page + 1,
          'q' => $filters['q'], 'event' => $filters['event'],
          'severity' => $filters['severity'], 'since' => $filters['since'],
      ]))) ?>">Older</a>
      <?php if ($page > 1): ?>
        <a class="btn btn--sm" href="<?= e(url('/admin/logs', array_filter([
            'channel' => $channel, 'page' => $page - 1,
            'q' => $filters['q'], 'event' => $filters['event'],
            'severity' => $filters['severity'], 'since' => $filters['since'],
        ]))) ?>">Newer</a>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</section>
<?php endif; ?>

<p class="hint">
  How long these are kept, what is recorded, and where they are forwarded are
  configured under
  <a href="<?= e(url('/admin/settings', ['tab' => 'security'])) ?>">Instance settings &rarr; Security</a>.
  This page shows them; it does not decide them.
</p>

<form method="post" action="<?= e(url('/admin/logs')) ?>" style="margin-top:.6rem">
  <?= csrf_field() ?>
  <button class="btn btn--sm" type="submit" name="action" value="prune"
          data-confirm="Remove everything older than the retention setting?">Prune now</button>
</form>
