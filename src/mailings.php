<?php
// Emails that go to many people: the queue they wait in, the group emails
// ("time to order", last call, share-expiry warning), and unsubscribing.
declare(strict_types=1);

// A month's sign-in link really stops working when the month is locked. This many days
// is only a backstop, in case a month is left open for a very long time.
const MONTH_LINK_DAYS = 60;
const LAST_CALL_HOURS = 24;        // how long before the deadline the last call goes out
const SHARE_WARNING_DAYS = 7;      // how long before a share expires its people are warned

// ---- The queue ----

/**
 * Puts an email in the queue instead of sending it now. bin/mail-jobs.php, run every
 * minute, sends a few at a time. That keeps a mailing to 40 people from holding up a
 * page, and from going over the daily limit: what doesn't fit today waits for tomorrow.
 */
function queue_email(string $to, string $subject, string $body, ?string $unsubscribeUrl = null): void
{
    q('INSERT INTO email_queue (to_email, subject, body, unsubscribe_url) VALUES (?, ?, ?, ?)',
        [$to, $subject, $body, $unsubscribeUrl]);
}

/** How many emails are waiting, and when the oldest was queued (null if none). */
function queue_waiting(): array
{
    $row = q('SELECT COUNT(*) AS waiting, MIN(created_at) AS oldest FROM email_queue
              WHERE sent_at IS NULL AND attempts < 3')->fetch();
    return ['count' => (int) $row['waiting'], 'oldest' => $row['oldest']];
}

/**
 * Sends waiting emails, oldest first, and stops at the daily limit. A few are always
 * kept back for sign-in links, which can't wait. Returns how many were sent.
 */
function queue_send(int $atMost = 20): int
{
    $room = (int) setting('email_daily_cap', '90') - emails_last_24h();
    if ($room <= 0) {
        return 0;
    }
    $sent = 0;
    $rows = q('SELECT * FROM email_queue WHERE sent_at IS NULL AND attempts < 3 ORDER BY id LIMIT ?',
        [min($atMost, $room)])->fetchAll();
    foreach ($rows as $row) {
        // These two headers let mail programs show their own "unsubscribe" button.
        $headers = $row['unsubscribe_url']
            ? ['List-Unsubscribe' => '<' . $row['unsubscribe_url'] . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']
            : [];
        if (send_email($row['to_email'], $row['subject'], $row['body'], $headers)) {
            q('UPDATE email_queue SET sent_at = ? WHERE id = ?', [now_utc(), $row['id']]);
            $sent++;
        } else {
            q('UPDATE email_queue SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
        }
    }
    return $sent;
}

// ---- Who gets group emails, and how they stop ----

/** Active members who have not unsubscribed from group emails. */
function group_recipients(): array
{
    return q('SELECT m.*, h.name AS household_name FROM members m JOIN households h ON h.id = m.household_id
              WHERE m.active = 1 AND m.email_opt_out = 0 ORDER BY m.id')->fetchAll();
}

/** A member's own unsubscribe link. The secret in it is made the first time it is needed. */
function unsubscribe_url(array $member): string
{
    $token = $member['unsubscribe_token'] ?? null;
    if (!$token) {
        $token = bin2hex(random_bytes(16));
        q('UPDATE members SET unsubscribe_token = ? WHERE id = ?', [$token, $member['id']]);
    }
    return config('base_url') . '/?a=unsubscribe&t=' . $token;
}

/** The lines at the bottom of every group email. */
function group_footer(string $unsubscribeUrl): string
{
    return "\n-- \n" . config('site_name') . ", " . config('site_tagline') . ".\n"
        . "You get this because you are a member.\n"
        . "To stop these group emails: $unsubscribeUrl\n"
        . "You would still get emails about your own orders.\n";
}

/** Queues one group email to one member, with the footer and unsubscribe link added. */
function queue_group_email(array $member, string $subject, string $body): void
{
    $unsubscribe = unsubscribe_url($member);
    queue_email($member['email'], $subject, $body . group_footer($unsubscribe), $unsubscribe);
}

/**
 * Makes a sign-in link for a member that works as often as needed while this month is
 * open (see check_login_token). It goes in the "time to order" and last-call emails.
 */
function month_login_link(int $memberId, int $cycleId): string
{
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO login_tokens (token_hash, member_id, expires_at, cycle_id) VALUES (?, ?, ?, ?)',
        [token_hash($token), $memberId, now_utc(MONTH_LINK_DAYS * 86400), $cycleId]);
    return config('base_url') . '/?a=verify&t=' . $token;
}

/** How many items a household has on its order for a month, and whether it has submitted. */
function household_order_state(int $cycleId, int $householdId): array
{
    $row = q('SELECT o.status, (SELECT COUNT(*) FROM order_lines l WHERE l.order_id = o.id) AS line_count
              FROM orders o WHERE o.cycle_id = ? AND o.household_id = ?', [$cycleId, $householdId])->fetch();
    return ['items' => (int) ($row['line_count'] ?? 0), 'submitted' => ($row['status'] ?? '') === 'submitted'];
}

// ---- "Time to order" ----

/** Shares on the board that still have room, one per line, for the "time to order" email. */
function open_shares_text(): string
{
    $lines = [];
    foreach (board_shares(null) as $s) {
        if ($s['status'] === 'open' && !$s['full']) {
            $lines[] = "- {$s['product_title']}, {$s['size']}: " . fmt_amount($s['left']) . " {$s['size_unit']} left of "
                . fmt_amount((float) $s['size_amount']) . ", " . money($s['per_unit_cents']) . " per {$s['size_unit']}"
                . " (listed until " . fmt_local($s['expires_at'], 'M j') . ")";
        }
    }
    return $lines
        ? "Shares looking for takers:\n" . implode("\n", $lines) . "\n"
        : "No shares are waiting for takers right now. To start one, find the item and choose \"Share it\".\n";
}

/**
 * The "time to order" email for one member. $note is the organizer's own words for the
 * top; $link is that member's sign-in link.
 */
function time_to_order_text(array $cycle, array $member, string $note, string $link): string
{
    $state = household_order_state((int) $cycle['id'], (int) $member['household_id']);
    $text = "Hi " . first_name($member) . ",\n\n"
        . (trim($note) !== '' ? trim($note) . "\n\n" : '')
        . "The {$cycle['label']} order is open. Orders are due " . fmt_local($cycle['due_at']) . ".\n"
        . (pickup_text($cycle) !== '' ? "\n" . pickup_text($cycle) . "\n" : '')
        . "\nYour own sign-in link:\n$link\n"
        . "It works until the order is locked, so you can come back to this email whenever you like.\n";
    if ($state['items'] > 0 && !$state['submitted']) {
        $text .= "\nYour household already has {$state['items']} "
            . ($state['items'] === 1 ? 'item on this order. It still needs' : 'items on this order. They still need')
            . " to be submitted.\n";
    }
    return $text . "\n" . open_shares_text();
}

/** Queues the "time to order" email to every member who gets group emails. Returns how many. */
function time_to_order_queue(array $cycle, string $note): int
{
    $members = group_recipients();
    foreach ($members as $m) {
        $link = month_login_link((int) $m['id'], (int) $cycle['id']);
        queue_group_email($m, config('site_name') . ": time to order for {$cycle['label']}",
            time_to_order_text($cycle, $m, $note, $link));
    }
    q('UPDATE cycles SET order_emailed_at = ? WHERE id = ?', [now_utc(), $cycle['id']]);
    return count($members);
}

// ---- Last call ----

/** When the last call for a month goes out (a UTC string, like the deadline itself). */
function last_call_time(array $cycle): string
{
    return gmdate('Y-m-d H:i:s', strtotime($cycle['due_at'] . ' UTC') - LAST_CALL_HOURS * 3600);
}

/**
 * Is it time for the last call? Yes, once, in the day before the deadline of an open
 * month. Not within a day of the "time to order" email, so a month opened at the last
 * minute doesn't send both at once.
 */
function last_call_due(array $cycle): bool
{
    $now = now_utc();
    return $cycle['status'] === 'open' && $cycle['last_call_at'] === null
        && $now >= last_call_time($cycle) && $now < $cycle['due_at']
        && ($cycle['order_emailed_at'] === null || $cycle['order_emailed_at'] < now_utc(-86400));
}

/**
 * Queues the last-call reminder to members whose household has not submitted an order.
 * Households that have submitted hear nothing. Returns how many emails were queued.
 */
function last_call_queue(array $cycle): int
{
    $queued = 0;
    foreach (group_recipients() as $m) {
        $state = household_order_state((int) $cycle['id'], (int) $m['household_id']);
        if ($state['submitted']) {
            continue;
        }
        $text = "Hi " . first_name($m) . ",\n\n"
            . "The {$cycle['label']} order closes " . fmt_local($cycle['due_at']) . ".\n\n"
            . ($state['items'] > 0
                ? "Your household has {$state['items']} " . ($state['items'] === 1 ? 'item' : 'items')
                    . " on its order, but the order has not been submitted. Sign in, look it over and press \"Submit my order\".\n"
                : "Your household has not ordered yet. If you are skipping this month, there is nothing to do.\n")
            . "\nYour own sign-in link:\n" . month_login_link((int) $m['id'], (int) $cycle['id']) . "\n";
        queue_group_email($m, config('site_name') . ": last call for {$cycle['label']}", $text);
        $queued++;
    }
    q('UPDATE cycles SET last_call_at = ? WHERE id = ?', [now_utc(), $cycle['id']]);
    return $queued;
}

// ---- Share-expiry warning ----

/**
 * Warns the people on a share that has not filled and is about to come off the board.
 * Each share is warned about once. Returns how many emails were queued.
 */
function share_expiry_warnings(): int
{
    $queued = 0;
    $soon = now_utc(SHARE_WARNING_DAYS * 86400);
    $rows = q("SELECT id FROM shares WHERE status = 'open' AND expiry_warned_at IS NULL AND expires_at > ? AND expires_at < ?",
        [now_utc(), $soon])->fetchAll();
    foreach ($rows as $row) {
        $share = find_share((int) $row['id']);
        if (!$share || $share['full']) {
            continue; // a full share goes on the next order, so it has nothing to be warned about
        }
        foreach ($share['claims'] as $c) {
            $member = q('SELECT * FROM members WHERE id = ? AND active = 1 AND email_opt_out = 0', [$c['member_id']])->fetch();
            if (!$member) {
                continue;
            }
            $text = "Hi " . first_name($member) . ",\n\n"
                . "The share of {$share['product_title']}, {$share['size']} that you are part of still has "
                . fmt_amount($share['left']) . " {$share['size_unit']} unclaimed, and it comes off Share items on "
                . fmt_local($share['expires_at'], 'l, F j') . " unless it fills.\n\n"
                . "If you know someone who might want some, now is a good time to ask. You can also raise your own amount.\n\n"
                . "Share items: " . config('base_url') . "/?a=shares\n";
            queue_group_email($member, config('site_name') . ": a share you are on is about to expire", $text);
            $queued++;
        }
        q('UPDATE shares SET expiry_warned_at = ? WHERE id = ?', [now_utc(), $share['id']]);
    }
    return $queued;
}

/** The emails the site sends by itself. Called every minute by bin/mail-jobs.php. */
function automatic_mailings(): array
{
    $cycle = current_cycle();
    return [
        'last_call' => $cycle && last_call_due($cycle) ? last_call_queue($cycle) : 0,
        'share_warnings' => share_expiry_warnings(),
    ];
}
