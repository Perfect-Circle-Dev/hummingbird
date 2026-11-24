<?php
// Emails the organizer about something on the server, such as a failed update.
// The message is read from standard input:
//   echo "What happened" | php bin/notify-admin.php "Subject line"
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/mailer.php';

$subject = trim((string) ($argv[1] ?? ''));
$body = trim((string) stream_get_contents(STDIN));
if ($subject === '' || $body === '') {
    fwrite(STDERR, "Usage: echo \"message\" | php bin/notify-admin.php \"Subject line\"\n");
    exit(1);
}
// Exit code 0 means sent (or logged, in 'log' mode), so scripts can tell if it worked.
exit(send_email(admin_email(), $subject, $body . "\n") ? 0 : 1);
