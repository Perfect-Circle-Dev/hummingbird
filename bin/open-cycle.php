<?php
// Opens a month for ordering (the organizer page will do this too).
//   php bin/open-cycle.php "December 2026" "2026-12-14 19:00" "Pickup from Wednesday, Dec 16"
// The date and time are the deadline members see, in the site's timezone.
require dirname(__DIR__) . '/src/bootstrap.php';

if ($argc < 3) {
    exit("Usage: php bin/open-cycle.php \"Label\" \"YYYY-MM-DD HH:MM\" [\"Pickup note\"]\n");
}
$due = DateTime::createFromFormat('Y-m-d H:i', $argv[2], new DateTimeZone(config('timezone')));
if (!$due) {
    exit("Could not read the date. Use the form 2026-12-14 19:00\n");
}
if (q("SELECT COUNT(*) FROM cycles WHERE status IN ('open', 'locked')")->fetchColumn() > 0) {
    exit("Another month is still open or locked. Close it before opening a new one.\n");
}
$due->setTimezone(new DateTimeZone('UTC'));
q('INSERT INTO cycles (label, due_at, pickup_note) VALUES (?, ?, ?)',
    [$argv[1], $due->format('Y-m-d H:i:s'), $argv[3] ?? null]);
echo "Opened {$argv[1]}.\n";
