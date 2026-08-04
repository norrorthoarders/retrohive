#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Send queued notification mail.
 *
 *   php bin/notify.php send        send what is waiting
 *   php bin/notify.php status      how much is waiting, and what failed
 *   php bin/notify.php retry       put failed messages back in the queue
 *
 * Meant for cron, every few minutes. In crontab:
 *
 *   (every five minutes) cd /opt/retrohive && php bin/notify.php send >/dev/null
 *
 * written with the usual slash-five in the first column - spelled out here
 * because the literal would close this comment block.
 *
 * Mail is queued rather than sent inside a request on purpose: a slow relay
 * should not make saving an entry slow, and a broken one should not make it
 * fail.
 */

define('APP_ROOT', dirname(__DIR__));
define('BASE_PATH', '');

require APP_ROOT . '/src/helpers.php';
require APP_ROOT . '/src/proxy.php';
require APP_ROOT . '/src/db.php';
require APP_ROOT . '/src/auth.php';
require APP_ROOT . '/src/acl.php';
require APP_ROOT . '/src/log.php';
require APP_ROOT . '/src/notify.php';

$command = $argv[1] ?? 'status';

if ($command === 'status') {
    $queued = (int) scalar("SELECT COUNT(*) FROM notifications WHERE mail_state = 'queued'");
    $failed = (int) scalar("SELECT COUNT(*) FROM notifications WHERE mail_state = 'failed'");
    printf("mail: %s\n", mail_enabled() ? 'configured' : 'not configured');
    printf("queued: %d\nfailed: %d\n", $queued, $failed);
    foreach (all("SELECT subject, mail_error FROM notifications
                   WHERE mail_state = 'failed' ORDER BY created_at DESC LIMIT 10") as $row) {
        printf("  %s — %s\n", $row['subject'], $row['mail_error']);
    }
    exit(0);
}

if ($command === 'retry') {
    q("UPDATE notifications SET mail_state = 'queued', mail_error = NULL WHERE mail_state = 'failed'");
    printf("Failed messages put back in the queue.\n");
    exit(0);
}

if ($command === 'send') {
    if (!mail_enabled()) {
        fwrite(STDERR, "No mail relay configured; nothing to do.\n");
        exit(0);
    }
    [$sent, $failed] = flush_notification_mail(100);

    // The same run trims the log. One cron entry rather than two, and the
    // pruning is cheap enough that nothing is gained by separating them.
    $pruned = log_prune();
    printf("sent %d, failed %d, pruned %d log entries\n", $sent, $failed, $pruned);
    exit($failed > 0 ? 1 : 0);
}

fwrite(STDERR, "Usage: notify.php [send|status|retry]\n");
exit(2);
