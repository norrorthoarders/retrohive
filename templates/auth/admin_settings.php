<?php /** @var array $smtp @var string $siteUrl @var array $kinds @var array $defaults @var array $queue @var bool $requireVerification @var string $tab */ ?>

<div class="pagehead">
  <div>
    <span class="eyebrow">Server</span>
    <h1>Instance settings</h1>
  </div>
</div>

<?php partial('admin_tabs', ['current' => in_array($tab, ['general', 'smtp', 'security'], true) ? $tab : 'updates']); ?>

<?php if ($tab === 'security'): ?>

<section class="panel">
  <h2 class="panel__title">Who may create an account</h2>
  <form method="post" action="<?= e(url('/admin/settings')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="registration">

    <?php $mode = registration_mode(); ?>
    <div class="field">
      <label for="registration_mode">Registration</label>
      <select id="registration_mode" name="registration_mode">
        <option value="closed" <?= $mode === 'closed' ? 'selected' : '' ?>>
          Closed — only administrators create accounts
        </option>
        <option value="public" <?= $mode === 'public' ? 'selected' : '' ?>>
          Open — anybody may sign up, and the sign-in page links to it
        </option>
        <option value="secret" <?= $mode === 'secret' ? 'selected' : '' ?>>
          By address — no link anywhere; you hand out the address below
        </option>
        <?php
        // Only when it can work. Offering a mode that is refused on save is a
        // choice that exists to be taken away again.
        ?>
        <?php if (mail_enabled() || $mode === 'invite'): ?>
          <option value="invite" <?= $mode === 'invite' ? 'selected' : '' ?>>
            By invitation — you send an email to one person at a time
          </option>
        <?php endif; ?>
      </select>

    </div>

    <?php
    // Two questions, not one: who may sign up, and what their account can do
    // once they have. The screen asked only the first, so a public sign-up made
    // an account the users list called unconfirmed and the sign-in page let
    // straight through.
    ?>
    <div class="field" style="margin-top:1.5rem">
      <label for="registration_approval">Once they have signed up</label>
      <?php $appr = registration_approval(); ?>
      <select id="registration_approval" name="registration_approval">
        <option value="auto" <?= $appr === 'auto' ? 'selected' : '' ?>>
          They can sign in straight away
        </option>
        <?php // Confirming an address needs something able to send to it. ?>
        <?php if (mail_verified() || $appr === 'email'): ?>
          <option value="email" <?= $appr === 'email' ? 'selected' : '' ?>>
            They must confirm their email address first
          </option>
        <?php endif; ?>
        <option value="admin" <?= $appr === 'admin' ? 'selected' : '' ?>>
          An administrator must let them in
        </option>
      </select>
    </div>

    <?php if ($mode === 'secret'): ?>
      <div class="field">
        <label for="secret-url">The address</label>
        <input id="secret-url" type="text" readonly value="<?= e(registration_secret_url()) ?>"
               onclick="this.select()">
        <span class="hint">
          Anybody who has this can create an account. It is not linked from
          anywhere and it is not in robots.txt — writing it there would publish it
          to everyone who reads that file, which is a larger audience than the
          people you gave it to.
        </span>
      </div>
      <label class="checkline" style="margin:.2rem 0 .8rem">
        <input type="checkbox" name="rotate_secret" value="1">
        Give me a new address — the current one stops working at once
      </label>
    <?php endif; ?>

    <div class="formactions">
      <button class="btn btn--accent" type="submit">Save</button>
    </div>
  </form>
</section>

<?php
// Its own panel. This is about the whole site, not about registration - putting
// it inside "who may create an account" made a site-wide switch look like part
// of a sign-up policy.
//
// The registration pages are not governed by it either way: they always say
// noindex, whatever this is set to. A way in has no business in a search result.
?>
<section class="panel">
  <h2 class="panel__title">Search engines</h2>
  <form method="post" action="<?= e(url('/admin/settings')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="indexing">
    <div class="field">
      <label for="search_indexing" class="visually-hidden">Search engines</label>
      <select id="search_indexing" name="search_indexing">
        <option value="discourage" <?= search_indexing_allowed() ? '' : 'selected' ?>>
          Ask them to stay away
        </option>
        <option value="allow" <?= search_indexing_allowed() ? 'selected' : '' ?>>
          Let them index the site
        </option>
      </select>
    </div>
    <div class="formactions">
      <button class="btn btn--accent" type="submit">Save</button>
    </div>
  </form>
</section>

<section class="panel">
  <h2 class="panel__title">Deleting libraries</h2>

  <form method="post" action="<?= e(url('/admin/settings')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="libraries">

    <label class="checkline" style="margin:.4rem 0">
      <input type="checkbox" name="libraries_deletable" value="1"
             <?= (string) setting('libraries.deletable', '0') === '1' ? 'checked' : '' ?>>
      Owners may permanently delete their own libraries
    </label>

    <div class="formactions">
      <button class="btn btn--accent" type="submit">Save</button>
    </div>
  </form>
</section>

<section class="panel" style="margin-top:1rem">
  <h2 class="panel__title">Logging</h2>

  <form method="post" action="<?= e(url('/admin/settings')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="logging">

    <div class="formgrid">
      <div class="field field--half">
        <label for="log_retention_days">Keep entries for</label>
        <input id="log_retention_days" name="log_retention_days" type="number" min="0" max="3650"
               value="<?= e((string) $logging['retention']) ?>">
      </div>
      <div class="field field--half">
        <label for="log_min_severity">Record down to</label>
        <select id="log_min_severity" name="log_min_severity">
          <?php foreach ([3 => 'Errors only', 4 => 'Warnings', 5 => 'Notices',
                          6 => 'Everything (info)', 7 => 'Including debug'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= (int) $logging['min_severity'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <h3 class="subhead">Where else entries go</h3>

    <div class="field">
      <label class="checkline">
        <input type="checkbox" name="logfile_enabled" value="1" data-logfile-toggle
               <?= $logging['file'] ? 'checked' : '' ?>>
        Append to a file
      </label>
    </div>
    <div class="formgrid" data-logfile-fields<?= $logging['file'] ? '' : ' hidden' ?>>
      <div class="field formgrid--wide<?= $logging['file_problem'] ? ' field--error' : '' ?>">
        <label for="logfile_path">Path</label>
        <input id="logfile_path" name="logfile_path" type="text" maxlength="255"
               value="<?= e((string) $logging['file_path']) ?>"
               placeholder="/var/log/retrohive/retrohive.log">
        <?php if ($logging['file_problem']): ?>
          <span class="hint" style="color:var(--bad)"><?= e((string) $logging['file_problem']) ?></span>
        <?php else: ?>
          <span class="hint">
            The directory is created if it can be, and the web server has to be
            able to write there — usually
            <span class="mono">chown www-data /var/log/retrohive</span>.
            Avoid <span class="mono">/tmp</span>: Apache and php-fpm normally run
            with systemd's PrivateTmp, so a file written there lands in a private
            namespace and is invisible from a shell. It looks like nothing
            happened, and something did.
          </span>
        <?php endif; ?>
      </div>
    </div>

    <div class="field">
      <label class="checkline">
        <input type="checkbox" name="syslog_enabled" value="1" data-syslog-toggle
               <?= $logging['syslog'] ? 'checked' : '' ?>>
        Send to a syslog receiver
      </label>
    </div>
    <div data-syslog-fields<?= $logging['syslog'] ? '' : ' hidden' ?>>
      <div class="formgrid">
        <div class="field field--half<?= $logging['host_missing'] ? ' field--error' : '' ?>">
          <label for="syslog_host">Host</label>
          <input id="syslog_host" name="syslog_host" type="text" maxlength="190"
                 value="<?= e((string) $logging['syslog_host']) ?>" placeholder="logs.example.com"
                 <?= $logging['host_missing'] ? 'aria-invalid="true"' : '' ?>>
          <?php if ($logging['host_missing']): ?>
            <span class="hint" style="color:var(--bad)">
              Forwarding is on with nowhere to forward to, so nothing is being
              sent. Fill this in, or switch it off.
            </span>
          <?php endif; ?>
        </div>
        <div class="field field--quarter">
          <label for="syslog_port">Port</label>
          <input id="syslog_port" name="syslog_port" type="number" min="1" max="65535"
                 value="<?= e((string) $logging['syslog_port']) ?>">
        </div>
        <div class="field field--quarter">
          <label for="syslog_protocol">Protocol</label>
          <select id="syslog_protocol" name="syslog_protocol">
            <option value="udp" <?= $logging['syslog_proto'] === 'udp' ? 'selected' : '' ?>>UDP</option>
            <option value="tcp" <?= $logging['syslog_proto'] === 'tcp' ? 'selected' : '' ?>>TCP</option>
          </select>
        </div>
      </div>

      <div class="formgrid">
        <div class="field field--half">
          <label for="syslog_facility_server">Facility for the server stream</label>
          <select id="syslog_facility_server" name="syslog_facility_server">
            <?php foreach ($logging['facilities'] as $num => $label): ?>
              <option value="<?= (int) $num ?>" <?= (int) $logging['fac_server'] === (int) $num ? 'selected' : '' ?>>
                <?= e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field field--half">
          <label for="syslog_facility_security">Facility for the security stream</label>
          <select id="syslog_facility_security" name="syslog_facility_security">
            <?php foreach ($logging['facilities'] as $num => $label): ?>
              <option value="<?= (int) $num ?>" <?= (int) $logging['fac_security'] === (int) $num ? 'selected' : '' ?>>
                <?= e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Kept separate so a receiver can route them apart.</span>
        </div>
      </div>
    </div>

    <?php
    // The test is in this form rather than in a panel of its own below it.
    //
    // It used to be separate, which meant it tested what was stored and the
    // panel had to carry a sentence telling you to press Save first - a caveat
    // that exists because of where the button was. In here it saves and then
    // writes, so what is tested is what is on screen.
    ?>
    <div class="formactions">
      <button class="btn" type="submit" name="action" value="test">Write test log</button>
      <button class="btn btn--accent" type="submit">Save</button>
    </div>
  </form>
</section>

<?php elseif ($tab === 'smtp'): ?>

<section class="panel">
  <h2 class="panel__title">Mail relay</h2>
  <form method="post" action="<?= e(url('/admin/settings')) ?>" class="form" data-smtp-form>
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="smtp">

    <div class="field">
      <label class="checkline">
        <input type="checkbox" name="smtp_enabled" value="1" <?= $smtp['enabled'] ? 'checked' : '' ?>>
        Send email
      </label>
    </div>

    <div class="formgrid">
      <div class="field field--half">
        <label for="smtp_host">Host</label>
        <input id="smtp_host" name="smtp_host" type="text" maxlength="190"
               value="<?= e((string) $smtp['host']) ?>" placeholder="smtp.example.com">
      </div>
      <div class="field field--quarter">
        <label for="smtp_port">Port</label>
        <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535"
               value="<?= e((string) $smtp['port']) ?>" data-smtp-port>
      </div>
    </div>

    <div class="field">
      <label class="checkline">
        <input type="checkbox" name="smtp_encrypted" value="1" data-smtp-encrypted
               <?= $smtp['encrypted'] ? 'checked' : '' ?>>
        Use encryption
      </label>
    </div>
    <div class="formgrid" data-smtp-encryption<?= $smtp['encrypted'] ? '' : ' hidden' ?>>
      <div class="field field--half">
        <label for="smtp_security">Encryption mode</label>
        <select id="smtp_security" name="smtp_security" data-smtp-security>
          <option value="starttls" data-port="587" <?= $smtp['security'] === 'starttls' ? 'selected' : '' ?>>
            STARTTLS — plain first, upgraded
          </option>
          <option value="tls" data-port="465" <?= $smtp['security'] === 'tls' ? 'selected' : '' ?>>
            TLS — encrypted from the start
          </option>
        </select>
        <span class="hint">Choosing one sets the usual port; change it back if yours is different.</span>
      </div>
    </div>

    <div class="field">
      <label class="checkline">
        <input type="checkbox" name="smtp_auth" value="1" data-smtp-auth
               <?= $smtp['auth'] ? 'checked' : '' ?>>
        The relay requires a sign-in
      </label>
    </div>
    <div class="formgrid" data-smtp-credentials<?= $smtp['auth'] ? '' : ' hidden' ?>>
      <div class="field field--half">
        <label for="smtp_username">Username</label>
        <input id="smtp_username" name="smtp_username" type="text" maxlength="190"
               value="<?= e((string) $smtp['username']) ?>" autocomplete="off">
      </div>
      <div class="field field--half">
        <label for="smtp_password">Password</label>
        <input id="smtp_password" name="smtp_password" type="password" autocomplete="new-password"
               placeholder="<?= $smtp['has_pass'] ? 'unchanged' : '' ?>">
        <span class="hint">Blank leaves the stored one alone.</span>
      </div>
    </div>

    <div class="formgrid">
      <div class="field field--half">
        <label for="smtp_from">From address</label>
        <input id="smtp_from" name="smtp_from" type="email" maxlength="190" value="<?= e((string) $smtp['from']) ?>">
      </div>
      <div class="field field--half">
        <label for="smtp_from_name">From name</label>
        <input id="smtp_from_name" name="smtp_from_name" type="text" maxlength="120"
               value="<?= e((string) $smtp['from_name']) ?>">
      </div>
    </div>

    <div class="formactions">
      <button class="btn btn--accent" type="submit">Save</button>
      <span class="hint" style="margin:0">Saving any of this clears the test result.</span>
    </div>
  </form>

  <hr style="border:0;border-top:1px solid var(--line);margin:1.2rem 0">

  <h3 class="subhead">Confirm that mail arrives</h3>
  <?php if ($smtp['verified']): ?>
    <p class="hint" style="margin-top:0">
      <span style="color:var(--good)">&#10003;</span>
      Confirmed on <?= e(date('j M Y, H:i', strtotime((string) $smtp['verified_at']))) ?>.
      Email can be switched on. Changing the host, port, encryption, sign-in or
      from address asks for this again — nothing else does.
    </p>
  <?php else: ?>
  <?php endif; ?>

  <div class="formgrid">
    <div class="field field--half">
      <form method="post" action="<?= e(url('/admin/settings')) ?>"
            style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="section" value="send_code">
        <div class="field" style="margin:0;flex:1;min-width:14rem">
          <label for="code_to">Send a code to</label>
          <input id="code_to" name="code_to" type="email" required
                 value="<?= e((string) $smtp['code_to']) ?>" placeholder="you@example.com">
        </div>
        <button class="btn" type="submit"><?= $smtp['code_pending'] ? 'Send another' : 'Send the code' ?></button>
      </form>
    </div>

    <?php if ($smtp['code_pending']): ?>
    <div class="field field--half">
      <form method="post" action="<?= e(url('/admin/settings')) ?>"
            style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="section" value="confirm_code">
        <div class="field" style="margin:0;flex:1;min-width:10rem">
          <label for="code">The code from that email</label>
          <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                 maxlength="6" placeholder="123456" required>
          <span class="hint">Good for half an hour.</span>
        </div>
        <button class="btn btn--accent" type="submit">Confirm</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($queue['queued'] > 0 || $queue['failed'] > 0): ?>
    <p class="hint" style="margin-bottom:0">
      <?= (int) $queue['queued'] ?> queued, <?= (int) $queue['failed'] ?> failed.
      Queued mail is sent by <span class="mono">php bin/notify.php send</span> from cron.
    </p>
    <?php if ($queue['recent']): ?>
      <ul class="hint" style="margin:.4rem 0 0">
        <?php foreach ($queue['recent'] as $f): ?>
          <li><?= e($f['subject']) ?> — <?= e((string) $f['mail_error']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php elseif ($tab === 'general'): ?>

<section class="panel">
  <h2 class="panel__title">This instance</h2>
  <form method="post" action="<?= e(url('/admin/settings')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="server">
    <div class="formgrid">
      <div class="field field--half">
        <label for="instance_name">Name</label>
        <input id="instance_name" name="instance_name" type="text" maxlength="120"
               value="<?= e((string) $instanceName) ?>" placeholder="RetroHive">
      </div>
      <div class="field field--half">
        <label for="site_url">Address</label>
        <input id="site_url" name="site_url" type="url" maxlength="255" value="<?= e((string) $siteUrl) ?>"
               placeholder="https://retrohive.example.com">
      </div>
    </div>
    <div class="formactions"><button class="btn btn--accent" type="submit">Save</button></div>
  </form>
</section>

<section class="panel">
  <h2 class="panel__title">Structure data</h2>
  <?php
  // The address only. Synchronising moved to the library that would receive it -
  // /libraries/{id}/edit - because what a library holds is that library's business
  // and doing it here changed the structure for everybody at once with no way to
  // say which library wanted it. Nothing about this instance is a good place to
  // decide what one shelf begins with.
  ?>
  <form method="post" action="<?= e(url('/admin/settings')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="structure">
    <input type="hidden" name="from" value="save">
    <div class="field">
      <label for="structure_source">Where to fetch from</label>
      <input id="structure_source" name="structure_source" type="url" maxlength="255"
             value="<?= e((string) $structure['source']) ?>">
    </div>

    <?php
    // No table here any more.
    //
    // What it showed was the structure set against the files, which is one number
    // per kind for the whole instance - and the question people actually have is
    // "is my library up to date", which is per library and is answered on the
    // library screen. This page keeps the address, because where to fetch from
    // is genuinely instance-wide, and the button that fetches from it.
    ?>
    <?php if (!empty($structure['error'])): ?>
      <p class="hint" style="color:var(--bad)"><?= e((string) $structure['error']) ?></p>
    <?php elseif (($structure['last_sync'] ?? null) !== null): ?>
      <p class="hint">
        Last fetched
        <?= e(date('j M Y, H:i', strtotime((string) $structure['last_sync']['at']))) ?><?php
          ?><?= !empty($structure['last_sync']['forced']) ? ', forced' : '' ?>.
        <strong>Force update</strong> rewrites rows that are already here, which is
        how a correction to something that shipped wrong arrives; an ordinary fetch
        leaves them alone. Neither touches a library &mdash; each library says what
        it holds, and resyncs, on its own page.
      </p>
    <?php elseif ($structure['synced_at']): ?>
      <p class="hint">Last fetched
        <?= e(date('j M Y, H:i', strtotime((string) $structure['synced_at']))) ?>.</p>
    <?php else: ?>
      <p class="hint">Never fetched. Force update takes what is at the address above.</p>
    <?php endif; ?>
    <div class="formactions">
      <button class="btn" type="submit" name="action" value="force">Force update</button>
      <button class="btn btn--accent" type="submit">Save</button>
    </div>
  </form>
</section>


<section class="panel">
  <h2 class="panel__title">Signing in</h2>
  <form method="post" action="<?= e(url('/admin/settings')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="signin">
    <div class="field">
      <label class="checkline">
        <input type="checkbox" name="require_email_verification" value="1"
               <?= $requireVerification ? 'checked' : '' ?>
               <?= $smtp['verified'] ? '' : 'disabled' ?>>
        Make people confirm their address before they can sign in
      </label>
      <span class="hint">
        <?php if (!$smtp['verified']): ?>
          Locked: this needs a relay that has answered a test message. Requiring
          a link nobody can send locks out everybody, including you. Set one up
          under <a href="<?= e(url('/admin/settings', ['tab' => 'smtp'])) ?>">SMTP relay</a>.
        <?php else: ?>
          Directory accounts are exempt — the directory has vouched for them
          already. An administrator can mark somebody verified by hand from
          <a href="<?= e(url('/manage/users')) ?>">User management</a>.
        <?php endif; ?>
      </span>
    </div>
    <div class="formactions"><button class="btn btn--accent" type="submit">Save</button></div>
  </form>
</section>

<section class="panel">
  <h2 class="panel__title">Notification defaults</h2>
  <?php if (!$smtp['verified']): ?>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/admin/settings')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="defaults">
    <table class="table">
      <thead>
        <tr><th>What happens</th><th style="width:7rem;text-align:center">In app</th><th style="width:7rem;text-align:center">By email</th></tr>
      </thead>
      <tbody>
        <?php foreach ($kinds as $kind => $meta): ?>
        <tr>
          <td>
            <strong><?= e($meta['label']) ?></strong>
            <span class="hint" style="display:block"><?= e($meta['description']) ?></span>
          </td>
          <td style="text-align:center">
            <input type="checkbox" name="in_app[<?= e($kind) ?>]" value="1" style="width:auto;height:auto"
                   <?= $defaults[$kind]['in_app'] ? 'checked' : '' ?>>
          </td>
          <td style="text-align:center;<?= $smtp['verified'] ? '' : 'opacity:.4' ?>">
            <input type="checkbox" name="by_mail[<?= e($kind) ?>]" value="1" style="width:auto;height:auto"
                   <?= $defaults[$kind]['by_mail'] ? 'checked' : '' ?>
                   <?= $smtp['verified'] ? '' : 'disabled title="No proved mail relay"' ?>>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="formactions"><button class="btn btn--accent" type="submit">Save defaults</button></div>
  </form>
</section>



<?php else: ?>

<?php // Software updates. Its own tab and the landing one: whether what you are
      // running is current is the first thing worth knowing, and it used to be the
      // last panel on a long General page. ?>

<section class="panel">
  <h2 class="panel__title">Software updates</h2>
  <?php
  // One sentence, and what it is for is deciding whether to upgrade.
  //
  // It used to explain the HTTP status GitHub returned and what that endpoint
  // does when a repository has no releases - a paragraph about somebody else's
  // API on a page about this instance. Whether the check worked matters; why it
  // did not is in the log.
  ?>
  <p class="lede" style="font-size:.9rem;margin-top:0">
    Running <strong><?= e($update['running']) ?></strong>
    <?php if ($update['error']): ?>
      — <span style="color:var(--warn)">could not check for a newer version</span>.
    <?php elseif ($update['available']): ?>
      — <span style="color:var(--warn)">outdated,
        <?php if ($update['url']): ?>
          <a href="<?= e((string) $update['url']) ?>" rel="noopener noreferrer external">version
            <?= e((string) $update['latest']) ?> available</a>
        <?php else: ?>
          version <?= e((string) $update['latest']) ?> available
        <?php endif; ?></span>.
    <?php elseif ($update['checked_at']): ?>
      — up to date.
    <?php else: ?>
      — not checked yet.
    <?php endif; ?>
  </p>
  <form method="post" action="<?= e(url('/admin/settings')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="update_check">
    <?php // No field for where to ask. The update check asks the project
          // whether the project has released something; pointing it elsewhere
          // answers a different question, and an instance that reports itself
          // current against a feed it controls is worse than one that never
          // checks. The structure-data source is configurable because that is
          // your data; this is not. ?>
    <div class="formactions">
      <button class="btn" type="submit">Check now</button>
    </div>
  </form>
</section>

<?php endif; ?>
