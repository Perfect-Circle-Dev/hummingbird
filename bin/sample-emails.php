<?php
// Sends one sample of every email the site sends to members and organizers, so their
// wording and layout can be read in a real mail program.
//
//   php bin/sample-emails.php you@example.com              send the whole set to that address
//   php bin/sample-emails.php you@example.com --only=10    send just sample number 10
//   php bin/sample-emails.php --dry-run                    print them here and send nothing
//
// How it stays safe:
//   - The emails are made by the site's own code, so the wording is exactly what goes out,
//     but from a throwaway database of made-up people and orders. The real database is
//     never used to build them, and nothing in it is changed except the count of emails
//     sent (so the daily limit stays right).
//   - The set goes to the one address given, and only if that address belongs to an
//     organizer. Nobody else is emailed.
//   - Links in the samples lead nowhere, because they belong to the throwaway database.
//
// Not included: the notices the server sends about itself (weekly backup, failed backup,
// restart needed, failed update). Those are written inside their own scripts.
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/mailer.php';
require dirname(__DIR__) . '/src/auth.php';
require dirname(__DIR__) . '/src/catalog.php';
require dirname(__DIR__) . '/src/orders.php';
require dirname(__DIR__) . '/src/shares.php';
require dirname(__DIR__) . '/src/organizer.php';
require dirname(__DIR__) . '/src/payments.php';
require dirname(__DIR__) . '/src/members.php';
require dirname(__DIR__) . '/src/mailings.php';

$dryRun = in_array('--dry-run', $argv, true);
$to = '';
$only = 0; // with --only=10, just sample number 10 is shown or sent
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--only=(\d+)$/', $arg, $m)) {
        $only = (int) $m[1];
    } elseif ($arg !== '--dry-run') {
        $to = trim($arg);
    }
}
if (!$dryRun && !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    exit("Usage: php bin/sample-emails.php you@example.com\n"
        . "       php bin/sample-emails.php you@example.com --only=10    (just sample number 10)\n"
        . "       php bin/sample-emails.php --dry-run\n");
}

// ---- The real database: looked at twice, and never used to build the samples ----
$realPath = (string) config('db_path');
$realDriver = (string) config('mail_driver');
$real = null;
$venmo = '';
if (is_file($realPath)) {
    $real = new PDO('sqlite:' . $realPath);
    $real->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $real->exec('PRAGMA busy_timeout = 5000');
    // The real Venmo name, so the pay link in the pickup sample looks as it will.
    $venmo = (string) $real->query("SELECT value FROM settings WHERE key = 'venmo_handle'")->fetchColumn();
}
if (!$dryRun) {
    $check = $real ? $real->prepare('SELECT COUNT(*) FROM members WHERE email = ? COLLATE NOCASE AND is_admin = 1 AND active = 1') : null;
    if ($check) {
        $check->execute([$to]);
    }
    if (!$check || !$check->fetchColumn()) {
        exit("$to is not an organizer's address, so nothing was sent. The samples only go to an organizer.\n");
    }
}

// ---- From here on, everything happens in a throwaway database, and no email leaves ----
$scratch = sys_get_temp_dir() . '/hummingbird-samples-' . bin2hex(random_bytes(6)) . '.sqlite';
register_shutdown_function(function () use ($scratch): void {
    foreach (['', '-wal', '-shm'] as $end) {
        if (is_file($scratch . $end)) {
            @unlink($scratch . $end);
        }
    }
});
$CONFIG['db_path'] = $scratch;
$CONFIG['mail_driver'] = 'log';
db()->exec(file_get_contents(dirname(__DIR__) . '/schema.sql'));
set_setting('venmo_handle', $venmo !== '' ? $venmo : 'Sample-Venmo-Name');

// The samples collected so far, and which emails have already been taken.
$samples = [];
$takenLog = 0;
$takenQueue = 0;

/**
 * Picks out one email made since the last call: the first whose subject contains $subject
 * and, if $toEmail is given, that was addressed to that person. Emails sent at once are in
 * email_log; emails for many people wait in email_queue. $more marks a later call for the
 * same step, which looks again at the emails the step made.
 */
function take(string $title, string $when, string $who, string $subject, ?string $toEmail = null, bool $more = false): void
{
    global $samples, $takenLog, $takenQueue;
    static $fromLog = 0, $fromQueue = 0;
    if (!$more) {
        $fromLog = $takenLog;
        $fromQueue = $takenQueue;
        $takenLog = (int) q('SELECT COALESCE(MAX(id), 0) FROM email_log')->fetchColumn();
        $takenQueue = (int) q('SELECT COALESCE(MAX(id), 0) FROM email_queue')->fetchColumn();
    }
    $found = null;
    foreach ([['email_log', $fromLog], ['email_queue', $fromQueue]] as [$table, $after]) {
        foreach (q("SELECT * FROM $table WHERE id > ? ORDER BY id", [$after])->fetchAll() as $row) {
            if ($found === null && str_contains($row['subject'], $subject) && ($toEmail === null || $row['to_email'] === $toEmail)) {
                $found = $row + ['group' => $table === 'email_queue' && !empty($row['unsubscribe_url'])];
            }
        }
    }
    if ($found === null) {
        fwrite(STDERR, "Could not make the sample \"$title\": no such email was produced.\n");
        return;
    }
    $samples[] = ['title' => $title, 'when' => $when, 'who' => $who, 'subject' => $found['subject'],
                  'body' => $found['body'], 'group' => $found['group']];
}

// ---- Made-up people. The year they joined matters only to the end-of-year tidy-up ----
$due = new DateTime('second monday of next month');
$pickup = (clone $due)->modify('+2 days');
$label = $due->format('F Y');
$year = (int) $due->format('Y');
$person = function (string $name, string $email, string $household, int $admin = 0) use ($year): array {
    $id = q('SELECT id FROM households WHERE name = ?', [$household])->fetchColumn();
    if ($id === false) {
        q('INSERT INTO households (name) VALUES (?)', [$household]);
        $id = db()->lastInsertId();
    }
    [$first, $last] = split_name($name);
    q('INSERT INTO members (household_id, name, first_name, last_name, email, is_admin, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$id, $name, $first, $last, $email, $admin, "$year-01-05 18:00:00"]);
    return find_member((int) db()->lastInsertId());
};
$organizer = $person('Sample Organizer', 'organizer@example.com', 'Organizer', 1);
$rosa = $person('Rosa Alvarez', 'rosa@example.com', 'Alvarez');
$tom = $person('Tom Alvarez', 'tom@example.com', 'Alvarez');
$priya = $person('Priya Nair', 'priya@example.com', 'Nair');
$lena = $person('Lena Brooks', 'lena@example.com', 'Brooks');
$walt = $person('Walt Jensen', 'walt@example.com', 'Jensen');

// ---- A small made-up catalog, in the shape Hummingbird's own list arrives in ----
$item = fn(int $n, string $title, string $sku, string $size, string $price): array => [
    'id' => 9000 + $n, 'title' => $title, 'handle' => 'sample-' . $n,
    'variants' => [['id' => 9100 + $n, 'sku' => $sku, 'title' => $size, 'price' => $price, 'grams' => 0, 'available' => true]],
];
save_products([
    $item(1, 'Rolled Oats, Organic', 'G129', '25 lb bag', '38.50'),
    $item(2, 'Pinto Beans, Organic', 'B210', '25 lb bag', '54.00'),
    $item(3, 'Olive Oil, Extra Virgin', 'O131', '1 Gallon glass jug', '82.00'),
    $item(4, 'Almonds, Raw', 'N105', '25 lb case', '212.00'),
    $item(5, 'Maple Syrup, Grade A', 'S318', '1/2 Gallon glass jug', '49.00'),
], now_utc());
[$oats, $beans, $oil, $almonds, $syrup] = [9101, 9102, 9103, 9104, 9105];

// ---- 1 and 2. Sign-in links ----
send_login_link($rosa['email']);
take('Sign-in link, asked for on the sign-in page',
    'A member types their email address on the sign-in page.', 'That member.', 'sign-in link');
send_login_link($priya['email'], true);
take('Sign-in link, sent by an organizer',
    'An organizer presses "Send login" beside a member on the Members page.', 'That member.', 'sign-in link');

// ---- 3. Time to order ----
next_cycle();
cycle_open($label, local_to_utc($due->format('Y-m-d'), '19:00'), 'Pickup from ' . $pickup->format('l, M j'));
$cycle = current_cycle();
$cycleId = (int) $cycle['id'];
$almondShare = share_start($almonds, (int) $priya['id'], 10);   // 10 of 25 lb, so it shows as open
share_set_claim($almondShare, (int) $rosa['id'], 5);
time_to_order_queue($cycle, 'This is where your own note appears, if you type one when you press the button. Leave the box empty and this paragraph is left out.');
take('Time to order',
    'An organizer presses "Email everyone: time to order" on an open month.',
    'Every active member who has not unsubscribed.', 'time to order', $rosa['email']);

// ---- 4. Order received ----
$order = fn(array $member): array => household_order($cycleId, (int) $member['household_id']);
foreach ([$oats, $oats, $beans, $oil] as $variantId) {
    order_add($order($rosa), $variantId, (int) $rosa['id']);
}
order_submit($order($rosa), $cycle);
take('Order received',
    'A member presses "Submit my order".', 'Everyone in that household.', 'order received', $rosa['email']);
foreach ([$oats, $syrup] as $variantId) {
    order_add($order($priya), $variantId, (int) $priya['id']);
}
order_submit($order($priya), $cycle);
order_add($order($lena), $beans, (int) $lena['id']);            // Lena adds things but never submits
order_add($order($lena), $syrup, (int) $lena['id']);

// ---- 5. Share about to expire ----
$beanShare = share_start($beans, (int) $rosa['id'], 8);
share_set_claim($beanShare, (int) $priya['id'], 5);
// As if it had been listed for three months and had five days left.
q('UPDATE shares SET created_at = ?, expires_at = ? WHERE id = ?', [now_utc(-86 * 86400), now_utc(5 * 86400), $beanShare]);
share_expiry_warnings();
take('A share is about to expire',
    'By itself, 7 days before an unfilled share comes off Share items.',
    'Each person with a claim on that share, unless they have unsubscribed.', 'about to expire', $rosa['email']);

// ---- 6 and 7. Last call, in its two forms ----
last_call_queue($cycle);
take('Last call, for a household that has ordered nothing',
    'By itself, 24 hours before the deadline.',
    'Members whose household has not submitted an order, unless they have unsubscribed.', 'last call', $walt['email']);
take('Last call, for a household with items waiting',
    'By itself, 24 hours before the deadline.',
    'Members whose household has added items but not pressed "Submit my order".', 'last call', $lena['email'], true);

// ---- 8. Report at lock ----
share_set_claim($almondShare, (int) $priya['id'], 20);          // the almonds share fills, so it goes on the order
cycle_lock($cycleId);
$cycle = find_cycle($cycleId);
month_report_email($cycle, 'Sent when the month was locked. This is what was ordered.');
take('Report for the month',
    'An organizer locks the month. It is sent again when the month is closed, and whenever "Email this report" is pressed.',
    'Every organizer.', 'report for');

// ---- 9. Order update: out of stock and a changed price ----
$oilLine = (int) q('SELECT l.id FROM order_lines l JOIN orders o ON o.id = l.order_id
                    WHERE o.cycle_id = ? AND o.household_id = ? AND l.variant_id = ?',
    [$cycleId, $rosa['household_id'], $oil])->fetchColumn();
$result = stock_and_prices_save($cycle, ['all' => [$oil => 1], 'out' => [$oilLine => 1], 'price' => [$oats => '27.50']]);
stock_and_prices_email($cycle, $result['notes']);
take('Order update',
    'An organizer saves out-of-stock ticks or corrected prices on a locked month, with the email box ticked.',
    'Everyone in each household the change touches.', 'order update', $rosa['email']);

// ---- 10. The order as it stands, after the organizer has adjusted it ----
$oatsLine = (int) q('SELECT l.id FROM order_lines l JOIN orders o ON o.id = l.order_id
                     WHERE o.cycle_id = ? AND o.household_id = ? AND l.variant_id = ?',
    [$cycleId, $rosa['household_id'], $oats])->fetchColumn();
order_line_adjust(find_cycle($cycleId), $oatsLine, 3);
order_email_household(find_cycle($cycleId), (int) $rosa['household_id']);
take('Your order as it stands',
    'An organizer presses "Email their order" on the report page, after changing a quantity there.',
    'Everyone in that household who receives notification emails.', 'order update', $rosa['email']);

// ---- 11. Order placed ----
order_placed_send(find_cycle($cycleId), 'This is where your own note appears, if you type one: the days and hours you will be home for pickup, for example. Leave the box empty and this paragraph is left out.');
take('Order placed',
    'An organizer presses "Order placed" on a locked month, after entering what Hummingbird confirmed.',
    'Everyone in each household with anything on the order.', 'order placed', $rosa['email']);

// ---- 12. Pickup reminder ----
pickup_emails_send(find_cycle($cycleId));
take('Pickup reminder',
    'An organizer presses "Send pickup reminder" on a locked month.',
    'Everyone in each household that owes something and is not marked paid.', 'pickup for', $rosa['email']);

// ---- 13 and 14. Someone is added to a household ----
household_person_add($rosa, 'Sam', 'Alvarez', 'sam@example.com', '541-555-0142');
take('Welcome to a household',
    'A member adds a partner or friend on the My household page.', 'The person who was added.', 'sign-in link', 'sam@example.com');
take('Notice: someone was added to a household',
    'A member adds a partner or friend on the My household page.', 'Every organizer.', 'was added to', null, true);

// ---- 15 and 16. Someone is taken off a household ----
household_person_remove($rosa, (int) $tom['id']);
take('Taken off a household',
    'A member removes someone on the My household page.', 'The person who was removed.', 'taken off the', $tom['email']);
take('Notice: someone was taken off a household',
    'A member removes someone on the My household page.', 'Every organizer.', 'was taken off', $organizer['email'], true);

// ---- 17 and 18. A change of email address ----
email_change_request($priya, 'priya.new@example.com');
take('Confirm a new email address',
    'A member asks to change their own email address on the My household page.',
    'The NEW address. Nothing changes until the link in it is confirmed.', 'Confirm your new email', 'priya.new@example.com');
take('Notice to the old address',
    'A member asks to change their own email address on the My household page.',
    'The address they have used until now, in case it was not them.', 'change of email address', $priya['email'], true);

// ---- 19 and 20. The end-of-year tidy-up ----
yearly_archive_run($year);
take('Account archived',
    'By itself on December 31, for members whose household had nothing on any order that year.',
    'Each member archived.', 'has been archived', $walt['email']);
take('Notice: the end-of-year tidy-up',
    'By itself on December 31, after the tidy-up has run.', 'Every organizer.', 'tidy-up', null, true);

// ---- 21. A message written by an organizer ----
notice_queue('A note about pickup this month',
    "Hi {first_name},\n\nThis is where your own message appears, exactly as you type it on the Email everyone page. "
    . "Nothing is added above it or below it, apart from the group's footer. The first name in the greeting was filled in for each person.\n\nBrent", null, (int) $organizer['id']);
take('A message from an organizer',
    'An organizer writes it on the "Email everyone" page and presses "Send to everyone".',
    'Every active member who receives notification emails.', 'A note about pickup', $rosa['email']);

// ---- 22 and 23. An organizer changes a share by hand, then deletes one ----
$oatShare = share_start($oats, (int) $rosa['id'], 10);
share_set_claim($oatShare, (int) $priya['id'], 5);
$oatClaims = array_column(find_share($oatShare)['claims'], 'id', 'member_id');
share_admin_save($oatShare, [$oatClaims[(int) $rosa['id']] => '12', $oatClaims[(int) $priya['id']] => '8'], 0, '', $organizer);
take('A share item changed by an organizer',
    'An organizer changes the amounts on a share item on the organizer\'s Shares page.',
    'Everyone who was on that share item or is on it now.', 'Share item changed', $rosa['email']);
share_admin_delete($oatShare, $organizer);
take('A share item deleted by an organizer',
    'An organizer deletes a share item on the organizer\'s Shares page.',
    'Everyone who was on that share item.', 'Share item removed', $rosa['email']);

// ---- Show them, or send them ----
$total = count($samples);
$rule = str_repeat('-', 70);
foreach ($samples as $n => &$s) {
    $s['number'] = $n + 1;
    $s['subject'] = '[Sample ' . ($n + 1) . " of $total] " . $s['subject'];
    $s['body'] = 'SAMPLE ' . ($n + 1) . " of $total: {$s['title']}\n"
        . "When it is sent: {$s['when']}\n"
        . "Who gets it: {$s['who']}\n"
        . ($s['group'] ? "The real one also carries an unsubscribe link in its heading, which most mail programs show as a button.\n" : '')
        . "Below the line is the email exactly as it goes out. The names, items and amounts are made up, "
        . "and the links in it will not work.\n$rule\n\n" . $s['body'];
}
unset($s);

// --only=N keeps just that one, with its number unchanged ("Sample 10 of 19").
if ($only) {
    $samples = array_values(array_filter($samples, fn(array $s): bool => $s['number'] === $only));
    if (!$samples) {
        exit("There is no sample number $only. They run from 1 to $total.\n");
    }
    $total = count($samples);
}

if ($dryRun) {
    foreach ($samples as $s) {
        echo "\n" . str_repeat('=', 70) . "\nSubject: {$s['subject']}\n" . str_repeat('=', 70) . "\n{$s['body']}\n";
    }
    echo "\n$total samples shown. Nothing was sent.\n";
    exit;
}
if ($realDriver !== 'smtp') {
    exit("This machine only keeps emails in its log (mail_driver is \"$realDriver\"), so nothing was sent.\n"
        . "To read the samples here, run: php bin/sample-emails.php --dry-run\n");
}

// Resend allows 100 emails a day and the site holds itself to fewer, so check there is room.
$cap = (int) ($real->query("SELECT value FROM settings WHERE key = 'email_daily_cap'")->fetchColumn() ?: 90);
$count = $real->prepare("SELECT COUNT(*) FROM email_log WHERE status IN ('sent', 'logged') AND created_at > ?");
$count->execute([now_utc(-86400)]);
$sentToday = (int) $count->fetchColumn();
if ($sentToday + $total > $cap) {
    exit("The site has sent $sentToday emails in the last 24 hours and its limit is $cap, so there is not room for $total more.\n"
        . "Nothing was sent. Try again tomorrow.\n");
}

$CONFIG['mail_driver'] = $realDriver;
$note = $real->prepare("INSERT INTO email_log (to_email, subject, body, status) VALUES (?, ?, '(a sample email; its text was not kept)', 'sent')");
$sent = 0;
foreach ($samples as $s) {
    if (smtp_send($to, $s['subject'], $s['body'])) {
        $note->execute([$to, $s['subject']]);   // counted in the real log, so the daily limit stays right
        $sent++;
        echo "  sent  {$s['subject']}\n";
    } else {
        echo "  FAILED  {$s['subject']}\n";
    }
    sleep(1); // Resend takes only a couple of emails a second
}
echo "\n$sent of $total samples sent to $to.\n";
exit($sent === $total ? 0 : 1);
