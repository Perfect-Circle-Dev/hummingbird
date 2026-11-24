<?php
// Opens a month for ordering from the command line. The Organizer page does the same thing.
//   php bin/open-cycle.php "December 2026" "2026-12-14 19:00" "Pickup from Wednesday, Dec 16"
// The date and time are the deadline members see, in the site's timezone.
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/orders.php';
require dirname(__DIR__) . '/src/organizer.php';

if ($argc < 3) {
    exit("Usage: php bin/open-cycle.php \"Label\" \"YYYY-MM-DD HH:MM\" [\"Pickup note\"]\n");
}
[$date, $time] = array_pad(explode(' ', trim($argv[2]), 2), 2, '');
$problem = cycle_open($argv[1], local_to_utc($date, $time), $argv[3] ?? '');
if ($problem) {
    exit([
        'label' => "Give the month a name, like \"December 2026\".\n",
        'date' => "Could not read the date. Use the form 2026-12-14 19:00\n",
        'duplicate' => "Another month already has that name.\n",
        'busy' => "Another month is still open or locked. Close it before opening a new one.\n",
    ][$problem]);
}
echo "Opened {$argv[1]}.\n";
