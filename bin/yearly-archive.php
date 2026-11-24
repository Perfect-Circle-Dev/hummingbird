<?php
// The end-of-year tidy-up of the member list. Cron runs this every night
// (deploy/setup-auto.sh), and it does nothing except on December 31. If the server was
// off that night, the first week of January catches up for the year just ended.
// It archives members whose household had nothing on any order that year, sends each
// a note saying how to come back, and emails the organizers the list.
// Organizers, and anyone added since July, are never archived by it.
//   php bin/yearly-archive.php                         (what cron runs)
//   php bin/yearly-archive.php --dry-run               (list who would be archived this year;
//   php bin/yearly-archive.php --dry-run --year=2027    changes nothing and sends nothing)
//   php bin/yearly-archive.php --today=2027-12-31      (behave as if it were that date; for
//                                                       trying it out on a test copy, never the live site)
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/mailer.php';
require dirname(__DIR__) . '/src/auth.php';
require dirname(__DIR__) . '/src/catalog.php';
require dirname(__DIR__) . '/src/orders.php';
require dirname(__DIR__) . '/src/shares.php';
require dirname(__DIR__) . '/src/mailings.php';
require dirname(__DIR__) . '/src/members.php';

$dryRun = in_array('--dry-run', $argv, true);
$year = null;
$today = new DateTime('now'); // by the site's own clock
foreach ($argv as $arg) {
    if (preg_match('/^--year=(\d{4})$/', $arg, $m)) {
        $year = (int) $m[1];
    }
    if (preg_match('/^--today=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $today = new DateTime($m[1]);
    }
}

if ($dryRun) {
    $year ??= (int) $today->format('Y');
    $members = yearly_archive_candidates($year);
    echo "For $year, the tidy-up would archive " . count($members) . " member(s). Nothing has been changed.\n";
    foreach ($members as $m) {
        echo "  {$m['name']} <{$m['email']}>, {$m['household_name']}\n";
    }
    exit;
}

// Which year, if any, is due tonight?
$month = (int) $today->format('n');
$day = (int) $today->format('j');
if ($month === 12 && $day === 31) {
    $due = (int) $today->format('Y');
} elseif ($month === 1 && $day <= 7) {
    $due = (int) $today->format('Y') - 1;
} else {
    exit; // any other night of the year
}
if ((int) setting('archive_done_year', '0') >= $due) {
    exit; // already done for that year
}
$archived = yearly_archive_run($due);
echo date('Y-m-d H:i:s') . " yearly tidy-up for $due: " . count($archived) . " member(s) archived.\n";
