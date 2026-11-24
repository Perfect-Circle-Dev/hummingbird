<?php
// Pickup day: split sheets for shared items, marking orders paid, and the pickup email.
declare(strict_types=1);

const PAID_METHODS = ['check' => 'Check', 'venmo' => 'Venmo', 'cash' => 'Cash'];

/**
 * An amount as you would read it off a kitchen scale or measuring jug.
 *   3.33 lb  -> "3 lb 5.3 oz · 1,510 g"
 *   0.5 gal  -> "2 qt · 8 cups"
 *   12 oz    -> "340 g"
 */
function scale_text(float $amount, string $unit): string
{
    if ($unit === 'lb') {
        // Round to a tenth of an ounce first, so 2.999 lb reads "3 lb 0 oz" and not "2 lb 16 oz".
        $ounces = round($amount * 16, 1);
        $pounds = (int) floor($ounces / 16);
        return $pounds . ' lb ' . fmt_amount($ounces - $pounds * 16) . ' oz · ' . number_format($amount * 453.592) . ' g';
    }
    if ($unit === 'gal') {
        return fmt_amount($amount * 4) . ' qt · ' . fmt_amount($amount * 16) . ' cups';
    }
    if ($unit === 'oz') {
        return number_format($amount * 28.3495) . ' g';
    }
    return '';
}

/** The shared items on a month's order, in name order, each with who gets how much. */
function split_sheets(array $cycle): array
{
    $shares = shares_on_order((int) $cycle['id']);
    usort($shares, fn(array $a, array $b): int => strcasecmp($a['product_title'], $b['product_title']));
    return $shares;
}

/**
 * Marks a household's order for a month paid (by check, venmo or cash), or not paid when
 * $method is null. Only for a locked or closed month. Returns false if nothing was changed.
 */
function order_set_paid(array $cycle, int $householdId, ?string $method): bool
{
    if (!in_array($cycle['status'], ['locked', 'closed'], true)) {
        return false;
    }
    if ($method !== null && !isset(PAID_METHODS[$method])) {
        return false;
    }
    if (!q('SELECT 1 FROM households WHERE id = ?', [$householdId])->fetchColumn()) {
        return false;
    }
    // A household that is only on a share has no order of its own yet; this makes one to hold the payment.
    $order = household_order((int) $cycle['id'], $householdId);
    q('UPDATE orders SET paid_at = ?, paid_method = ? WHERE id = ?',
        [$method === null ? null : now_utc(), $method, $order['id']]);
    return true;
}

/** "check", "Venmo" or "cash", as it reads in the middle of a sentence. */
function paid_how(?string $method): string
{
    return $method === 'venmo' ? 'Venmo' : (isset(PAID_METHODS[(string) $method]) ? (string) $method : 'other means');
}

/** "Paid by Venmo on Dec 16", for pages and emails. */
function paid_text(string $paidAt, ?string $method): string
{
    return 'Paid by ' . paid_how($method) . ' on ' . fmt_local($paidAt, 'M j');
}

/** The Venmo handle from Settings, tidied ("@Brent-S " -> "Brent-S"), or '' if none is set. */
function venmo_handle(): string
{
    return preg_replace('/[^A-Za-z0-9_-]/', '', (string) setting('venmo_handle', ''));
}

/** A link that opens Venmo ready to pay the group this amount, or null if no handle is set. */
function venmo_link(int $cents, string $note): ?string
{
    $handle = venmo_handle();
    if ($handle === '') {
        return null;
    }
    return 'https://venmo.com/' . $handle . '?txn=pay&amount=' . number_format($cents / 100, 2, '.', '')
        . '&note=' . rawurlencode($note);
}

/** Households that would get a pickup email now: something to pay, and not marked paid. */
function pickup_email_households(array $cycle): array
{
    return array_values(array_filter(month_report($cycle)['households'],
        fn(array $h): bool => $h['paid_at'] === null && $h['summary']['due_cents'] > 0));
}

/**
 * Sends each household its pickup email: where and when, what it owes, how to pay, and
 * the order in full. Records when it went out. Returns how many households were emailed.
 */
function pickup_emails_send(array $cycle): int
{
    $households = pickup_email_households($cycle);
    foreach ($households as $h) {
        $due = $h['summary']['due_cents'];
        $link = venmo_link($due, config('site_name') . ' ' . $cycle['label']);
        $body = "Your " . config('site_name') . " order for {$cycle['label']} is ready to pick up.\n\n"
            . ($cycle['pickup_note'] ? $cycle['pickup_note'] . "\n\n" : '')
            . "Amount due: " . money($due) . "\n"
            . "You can pay at pickup by check, Venmo or cash.\n"
            . ($link ? "To pay by Venmo now: $link\n" : '')
            . "\nYour order:\n\n"
            . order_text($h['summary'], 'Total due at pickup') . "\n"
            . "You can see it any time at " . config('base_url') . "/?a=order\n";
        email_household((int) $h['id'], config('site_name') . " pickup for {$cycle['label']}: " . money($due) . " due", $body);
    }
    q('UPDATE cycles SET pickup_emailed_at = ? WHERE id = ?', [now_utc(), $cycle['id']]);
    return count($households);
}

/** How many emails the site has sent in the last 24 hours. Resend's free plan allows 100 a day. */
function emails_last_24h(): int
{
    return (int) q("SELECT COUNT(*) FROM email_log WHERE status IN ('sent', 'logged') AND created_at > ?",
        [now_utc(-86400)])->fetchColumn();
}

/** How many people a list of households (from month_report) has to email. */
function household_email_count(array $households): int
{
    $count = 0;
    foreach ($households as $h) {
        $count += count($h['people']);
    }
    return $count;
}
