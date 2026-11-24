<?php
// Nightly database backup. Cron runs this (deploy/setup-auto.sh).
//   - Makes a clean copy of the database in data/backups/, gzipped and named by date.
//   - Leaves out what could be used to sign in as someone: device sessions, sign-in
//     links, emails still waiting to go out, and the text of past emails.
//   - Keeps the newest 30 and deletes older ones.
//   - Once a week, emails the newest copy to the organizer, so one exists off the server.
//   php bin/backup.php
//   php bin/backup.php --email      (also email the copy now, whatever day it is)
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/mailer.php';

const BACKUPS_KEPT = 30;
const BACKUP_EMAIL_EVERY_DAYS = 7;
const BACKUP_EMAIL_MAX_BYTES = 15 * 1024 * 1024; // bigger than this is not attached to an email

try {
    $folder = dirname(config('db_path')) . '/backups';
    if (!is_dir($folder) && !@mkdir($folder, 0750, true)) {
        throw new RuntimeException("Could not create $folder");
    }
    $copy = $folder . '/hummingbird-' . date('Y-m-d') . '.sqlite';
    if (is_file($copy)) {
        unlink($copy);
    }

    // VACUUM INTO writes a complete, consistent copy even while the site is in use.
    db()->exec('VACUUM INTO ' . db()->quote($copy));

    // Work on the copy only: take out the sign-in material, then check it is sound.
    $backup = new PDO('sqlite:' . $copy);
    $backup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $backup->exec("DELETE FROM sessions; DELETE FROM login_tokens; DELETE FROM email_queue; UPDATE email_log SET body = ''");
    $backup->exec('VACUUM'); // so the removed rows are really gone from the file
    $sound = $backup->query('PRAGMA integrity_check')->fetchColumn();
    $members = (int) $backup->query('SELECT COUNT(*) FROM members')->fetchColumn();
    $months = (int) $backup->query("SELECT COUNT(*) FROM cycles WHERE status <> 'next'")->fetchColumn();
    $backup = null; // close the copy before compressing it
    if ($sound !== 'ok') {
        throw new RuntimeException("The copy failed its integrity check: $sound");
    }

    // Compress it. A database is mostly text, so this makes it several times smaller.
    $zipped = $copy . '.gz';
    $in = fopen($copy, 'rb');
    $out = gzopen($zipped, 'wb9');
    while (!feof($in)) {
        gzwrite($out, fread($in, 1024 * 1024));
    }
    fclose($in);
    gzclose($out);
    unlink($copy);

    // Keep the newest ones. The names sort by date.
    $all = glob($folder . '/hummingbird-*.sqlite.gz');
    rsort($all);
    foreach (array_slice($all, BACKUPS_KEPT) as $old) {
        unlink($old);
    }
    set_setting('backup_last_at', now_utc());
    $size = number_format(filesize($zipped) / 1024) . ' KB';
    echo date('Y-m-d H:i:s') . " backup: " . basename($zipped) . " ($size, $members members, $months months); "
        . min(count($all), BACKUPS_KEPT) . " kept.\n";

    // Once a week, send the newest copy off the server by email.
    $lastEmailed = setting('backup_emailed_at');
    $due = $lastEmailed === null || $lastEmailed < now_utc(-(BACKUP_EMAIL_EVERY_DAYS * 86400 - 3600));
    if ($due || in_array('--email', $argv, true)) {
        $tooBig = filesize($zipped) > BACKUP_EMAIL_MAX_BYTES;
        $body = "This is the weekly copy of the " . config('site_name') . " database, as of " . date('l, F j') . ".\n\n"
            . ($tooBig
                ? "The backup is now $size, too large to attach. It is on the server at $zipped.\n\n"
                : "Attached: " . basename($zipped) . " ($size).\n\n")
            . "It holds $members members and $months months of orders. Keep it somewhere safe: it has\n"
            . "everyone's names, email addresses, phone numbers and orders.\n\n"
            . "Left out on purpose: device sessions, sign-in links, and the text of past emails. After a\n"
            . "restore, everyone signs in again with a fresh link.\n\n"
            . "The server keeps a backup from each of the last " . BACKUPS_KEPT . " nights. How to restore one is in README.md.\n";
        if (send_email(admin_email(), config('site_name') . ' weekly backup, ' . date('M j'), $body, [], $tooBig ? [] : [$zipped])) {
            set_setting('backup_emailed_at', now_utc());
            echo date('Y-m-d H:i:s') . " backup: emailed to " . admin_email() . ".\n";
        } else {
            echo date('Y-m-d H:i:s') . " backup: the weekly email could not be sent; it will be tried again tomorrow.\n";
        }
    }
} catch (Throwable $err) {
    // Tell the organizer, because a backup that silently stops is worse than none.
    fwrite(STDERR, date('Y-m-d H:i:s') . " backup FAILED: " . $err->getMessage() . "\n");
    send_email(admin_email(), config('site_name') . ' backup failed',
        "Last night's database backup on the " . config('site_name') . " server did not work.\n\n"
        . "The error was: " . $err->getMessage() . "\n\n"
        . "The site itself is not affected. To try again, sign in to the server and run:\n"
        . "  sudo -u www-data php " . dirname(__DIR__) . "/bin/backup.php\n");
    exit(1);
}
