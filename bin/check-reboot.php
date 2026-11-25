<?php
// Emails the organizer when Debian has installed an update that only takes effect
// after a restart (usually a new kernel). Cron runs this each morning.
// One email per pending restart, then a reminder each week until it is done.
//   php bin/check-reboot.php
//   php bin/check-reboot.php --flag=some/file     (for testing without a real pending restart)
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/mailer.php';

// Debian creates this file when a restart is needed and removes it at the next boot.
$flag = '/var/run/reboot-required';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--flag=')) {
        $flag = substr($arg, 7);
    }
}
const REMINDER_DAYS = 7;

if (!is_file($flag)) {
    // Restarted (or nothing pending): forget the last notice so the next one is sent promptly.
    q("DELETE FROM settings WHERE key = 'reboot_notified_at'");
    echo "No restart needed.\n";
    exit;
}

$lastNotice = setting('reboot_notified_at');
if ($lastNotice !== null && strtotime($lastNotice . ' UTC') > time() - REMINDER_DAYS * 86400) {
    echo "A restart is pending. Already emailed on $lastNotice UTC.\n";
    exit;
}

// Debian lists which packages asked for the restart in a second file beside the first.
$packages = is_file($flag . '.pkgs')
    ? array_unique(array_filter(array_map('trim', file($flag . '.pkgs'))))
    : [];

$body = "Debian has installed updates on the " . config('site_name') . " server (" . gethostname() . ") "
    . "that only take effect after a restart.\n\n"
    . ($packages ? "Waiting for the restart:\n  " . implode("\n  ", $packages) . "\n\n" : '')
    . "To restart, sign in to the server and run:\n  sudo reboot\n\n"
    . "The site is offline for about a minute while it restarts. Any time works except when "
    . "members are likely to be ordering, such as the evening of a deadline.\n\n"
    . "This reminder repeats every " . REMINDER_DAYS . " days until the server has been restarted.\n";

if (send_email(admin_email(), config('site_name') . ' server needs a restart', $body)) {
    q("INSERT INTO settings (key, value) VALUES ('reboot_notified_at', ?)
       ON CONFLICT(key) DO UPDATE SET value = excluded.value", [now_utc()]);
    echo "A restart is pending. Emailed " . admin_email() . ".\n";
} else {
    // Not recorded, so tomorrow's run tries again.
    echo "A restart is pending, but the email could not be sent.\n";
    exit(1);
}
