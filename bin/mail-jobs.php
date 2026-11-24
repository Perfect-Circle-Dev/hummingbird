<?php
// The site's background email work. Cron runs this every minute (deploy/setup-auto.sh):
//   1. queues the emails the site sends by itself (last call, share-expiry warnings)
//   2. sends what is waiting in the queue, a few at a time, under the daily limit
// It prints a line only when it did something, so the log stays short.
//   php bin/mail-jobs.php
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/mailer.php';
require dirname(__DIR__) . '/src/auth.php';
require dirname(__DIR__) . '/src/catalog.php';
require dirname(__DIR__) . '/src/orders.php';
require dirname(__DIR__) . '/src/shares.php';
require dirname(__DIR__) . '/src/mailings.php';

// Only one copy at a time: if the last run is still sending, this one steps aside.
$lock = fopen(dirname(config('db_path')) . '/mail-jobs.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit;
}

$queued = automatic_mailings();
$sent = queue_send();
$waiting = queue_waiting()['count'];

if ($queued['last_call'] || $queued['share_warnings'] || $sent) {
    echo date('Y-m-d H:i:s') . " mail: queued {$queued['last_call']} last-call and {$queued['share_warnings']} share-warning emails; "
        . "sent $sent; $waiting still waiting.\n";
}
