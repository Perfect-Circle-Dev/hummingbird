<?php
// Organizer tools: open, lock and close a month, and build the combined order for Hummingbird.
declare(strict_types=1);

function find_cycle(int $cycleId): ?array
{
    $row = q('SELECT * FROM cycles WHERE id = ?', [$cycleId])->fetch();
    return $row ?: null;
}

/**
 * A date ("2026-12-14") and a time ("19:00") in the site's timezone -> the UTC string
 * the database stores ("2026-12-15 03:00:00"), or null if they can't be read.
 */
function local_to_utc(string $date, string $time): ?string
{
    // Some browsers send the time with seconds ("19:00:00"); only hours and minutes are used.
    $text = trim($date) . ' ' . substr(trim($time), 0, 5);
    $dt = DateTime::createFromFormat('!Y-m-d H:i', $text, new DateTimeZone(config('timezone')));
    $problems = DateTime::getLastErrors();
    if (!$dt || ($problems && ($problems['warning_count'] || $problems['error_count']))) {
        return null; // also catches dates that don't exist, like February 30
    }
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Checks a month's name and deadline. Returns a short code for what is wrong
 * (the page turns it into a sentence), or null if both are fine.
 */
function cycle_problem(string $label, ?string $dueUtc, int $exceptCycleId = 0): ?string
{
    if ($label === '') {
        return 'label';
    }
    if ($dueUtc === null) {
        return 'date';
    }
    $sameName = q('SELECT COUNT(*) FROM cycles WHERE label = ? COLLATE NOCASE AND id <> ?',
        [$label, $exceptCycleId])->fetchColumn();
    return $sameName > 0 ? 'duplicate' : null;
}

/** Opens a month for ordering. Returns a problem code, or null if it worked. */
function cycle_open(string $label, ?string $dueUtc, string $pickupNote): ?string
{
    $label = trim($label);
    if (current_cycle()) {
        return 'busy'; // only one month at a time can be open or locked
    }
    $next = next_cycle();
    $problem = cycle_problem($label, $dueUtc, (int) $next['id']);
    if ($problem) {
        return $problem;
    }
    // The standing "next month" list becomes this month, so anything members saved
    // ahead of time is already on their orders (not yet submitted). A fresh
    // "next month" list is made the next time one is needed (see next_cycle).
    q("UPDATE cycles SET label = ?, due_at = ?, pickup_note = ?, status = 'open' WHERE id = ?",
        [$label, $dueUtc, trim($pickupNote) ?: null, $next['id']]);
    return null;
}

/** How many households have saved items for next month (shown to the organizer before opening it). */
function saved_for_next_count(): int
{
    return (int) q("SELECT COUNT(DISTINCT o.household_id) FROM orders o
                    JOIN cycles c ON c.id = o.cycle_id JOIN order_lines l ON l.order_id = o.id
                    WHERE c.status = 'next'")->fetchColumn();
}

/** Changes a month's name, deadline or pickup note. Returns a problem code, or null if it worked. */
function cycle_update(int $cycleId, string $label, ?string $dueUtc, string $pickupNote): ?string
{
    $cycle = find_cycle($cycleId);
    if (!$cycle || !in_array($cycle['status'], ['open', 'locked'], true)) {
        return 'closed';
    }
    $label = trim($label);
    $problem = cycle_problem($label, $dueUtc, $cycleId);
    if ($problem) {
        return $problem;
    }
    q('UPDATE cycles SET label = ?, due_at = ?, pickup_note = ? WHERE id = ?',
        [$label, $dueUtc, trim($pickupNote) ?: null, $cycleId]);
    return null;
}

/**
 * Locks a month: prices are fixed, every fully claimed share goes onto this order,
 * and members can no longer change anything.
 * Returns how many shares went onto the order, or null if the month was not open.
 */
function cycle_lock(int $cycleId): ?int
{
    // BEGIN IMMEDIATE takes the database's write lock straight away, so two taps
    // on "Lock" can't both run this at the same moment.
    db()->exec('BEGIN IMMEDIATE');
    try {
        $cycle = find_cycle($cycleId);
        if (!$cycle || $cycle['status'] !== 'open') {
            db()->exec('ROLLBACK');
            return null;
        }
        // One last match to the catalog. After this the prices on the lines stay put,
        // because the nightly refresh only touches a month that is open.
        refresh_open_order_prices();

        $moved = 0;
        foreach (q("SELECT id FROM shares WHERE status = 'open'")->fetchAll() as $row) {
            $share = find_share((int) $row['id']);
            if (!$share || !$share['full']) {
                continue; // unfilled shares stay on the board for a later month
            }
            // Fix the price and save what each person pays. The parts add up to the
            // price exactly (see share_split_cents).
            share_set_price((int) $share['id'], (int) $share['price_cents']);
            q("UPDATE shares SET status = 'ordered', cycle_id = ? WHERE id = ?", [$cycleId, $share['id']]);
            $moved++;
        }
        q("UPDATE cycles SET status = 'locked', locked_at = ? WHERE id = ?", [now_utc(), $cycleId]);
        db()->exec('COMMIT');
    } catch (Throwable $err) {
        db()->exec('ROLLBACK');
        throw $err;
    }
    return $moved;
}

/**
 * Undoes a lock, for when the month was locked too early. Members can change their
 * orders again, prices follow the catalog again, and the shares that were moved onto
 * the order go back to the share board, still fully claimed, so they rejoin the
 * order when the month is locked again. Returns false if the month was not locked.
 */
function cycle_reopen(int $cycleId): bool
{
    db()->exec('BEGIN IMMEDIATE');
    try {
        $cycle = find_cycle($cycleId);
        if (!$cycle || $cycle['status'] !== 'locked') {
            db()->exec('ROLLBACK');
            return false;
        }
        q("UPDATE share_claims SET cost_cents = NULL
           WHERE share_id IN (SELECT id FROM shares WHERE status = 'ordered' AND cycle_id = ?)", [$cycleId]);
        q("UPDATE shares SET status = 'open', cycle_id = NULL, price_cents = NULL, out_of_stock = 0
           WHERE status = 'ordered' AND cycle_id = ?", [$cycleId]);
        // Out-of-stock ticks belong to the order as it was placed, so they are cleared too.
        // Corrected prices go back to following the catalog (the refresh below).
        q('UPDATE order_lines SET out_of_stock = 0 WHERE order_id IN (SELECT id FROM orders WHERE cycle_id = ?)', [$cycleId]);
        // The order is no longer placed either: it will be placed afresh after the next lock.
        q("UPDATE cycles SET status = 'open', locked_at = NULL, placed_at = NULL, placed_note = NULL WHERE id = ?", [$cycleId]);
        refresh_open_order_prices(); // catch up with any catalog changes made while it was locked
        db()->exec('COMMIT');
    } catch (Throwable $err) {
        db()->exec('ROLLBACK');
        throw $err;
    }
    return true;
}

/**
 * Closes a locked month once pickup and payment are done. Anything that was out of stock
 * is carried over: items go onto each household's "saved for next month" list, and shares
 * go back on the share board, still fully claimed. Returns false if the month was not locked.
 */
function cycle_close(int $cycleId): bool
{
    db()->exec('BEGIN IMMEDIATE');
    try {
        $cycle = find_cycle($cycleId);
        if (!$cycle || $cycle['status'] !== 'locked') {
            db()->exec('ROLLBACK');
            return false;
        }
        carry_over_out_of_stock($cycleId);
        q("UPDATE cycles SET status = 'closed', closed_at = ? WHERE id = ?", [now_utc(), $cycleId]);
        db()->exec('COMMIT');
    } catch (Throwable $err) {
        db()->exec('ROLLBACK');
        throw $err;
    }
    return true;
}

/** Moves a month's out-of-stock items and shares along to next month. Called once, at close. */
function carry_over_out_of_stock(int $cycleId): void
{
    $next = next_cycle();
    $lines = q('SELECT l.variant_id, l.qty, l.added_by, o.household_id
                FROM order_lines l JOIN orders o ON o.id = l.order_id
                WHERE o.cycle_id = ? AND l.out_of_stock = 1', [$cycleId])->fetchAll();
    foreach ($lines as $l) {
        $variant = find_variant((int) $l['variant_id']);
        if (!$variant || !$variant['active']) {
            continue; // Hummingbird no longer lists it, so there is nothing to order next month
        }
        $order = household_order((int) $next['id'], (int) $l['household_id']);
        // If they had already saved the same item for next month, the amounts are added.
        q('INSERT INTO order_lines (order_id, variant_id, qty, unit_price_cents, deposit_cents, added_by)
           VALUES (?, ?, ?, ?, ?, ?)
           ON CONFLICT(order_id, variant_id) DO UPDATE SET qty = MIN(99, qty + excluded.qty)',
            [$order['id'], $l['variant_id'], $l['qty'], member_price_cents((int) $variant['list_price_cents']),
             variant_deposit_cents($variant), $l['added_by']]);
    }

    // Shares go back to being open and fully claimed, with a fresh expiry date, so they
    // go onto the next order. Anyone who has changed their mind can cancel their claim.
    $months = (int) setting('share_expiry_months', '3');
    $expires = gmdate('Y-m-d H:i:s', strtotime("+$months months"));
    q("UPDATE share_claims SET cost_cents = NULL WHERE share_id IN
           (SELECT id FROM shares WHERE status = 'ordered' AND cycle_id = ? AND out_of_stock = 1)", [$cycleId]);
    q("UPDATE shares SET status = 'open', cycle_id = NULL, price_cents = NULL, out_of_stock = 0, expires_at = ?,
                      expiry_warned_at = NULL
       WHERE status = 'ordered' AND cycle_id = ? AND out_of_stock = 1", [$expires, $cycleId]);
}

/**
 * Saves the organizer's out-of-stock ticks and corrected prices for a locked month.
 * $form is what the combined-order page sent:
 *   price[variant id]      the price each, as typed
 *   all[variant id]        "out of stock for everyone" is ticked
 *   was_all[variant id]    it was already ticked when the page was shown
 *   out[line id]           one household's line is ticked
 *   share_out[share id]    one share is ticked
 * Returns how many things changed, how many prices could not be read, and for each
 * household affected a list of sentences describing what changed (for the email).
 */
function stock_and_prices_save(array $cycle, array $form): array
{
    $changes = 0;
    $badPrices = 0;
    $notes = []; // household id => sentences
    $ticked = fn(string $group, $id): bool => !empty($form[$group][$id]);

    db()->exec('BEGIN IMMEDIATE');
    try {
        foreach (combined_order($cycle)['items'] as $item) {
            $variantId = $item['variant_id'];
            $name = "{$item['product_title']}, {$item['size']} ({$item['sku']})";

            // The price each, if a different one was typed.
            $oldPrice = (int) $item['price_cents'];
            $newPrice = $oldPrice;
            $typed = trim((string) ($form['price'][$variantId] ?? ''));
            if ($typed !== '') {
                $cents = money_to_cents($typed);
                if ($cents === null) {
                    $badPrices++;
                } else {
                    $newPrice = $cents;
                }
            }

            // Out of stock. $everyone is true or false when the one tick box decides for the
            // whole item, and null when each household's own box decides.
            $all = $ticked('all', $variantId);
            $wasAll = $ticked('was_all', $variantId);
            $ownBoxes = array_merge(
                array_map(fn(array $b): bool => $ticked('out', $b['line_id']), $item['buyers']),
                array_map(fn(array $s): bool => $ticked('share_out', $s['id']), $item['shares']));
            if (count($ownBoxes) === 1) {
                $everyone = $all;                 // only one entry, so it has just the one box
            } elseif ($all && !$wasAll) {
                $everyone = true;                 // "everyone" was just ticked
            } elseif (!$all && $wasAll && !in_array(false, $ownBoxes, true)) {
                $everyone = false;                // "everyone" was just unticked and nothing else was touched
            } else {
                $everyone = null;
            }

            foreach ($item['buyers'] as $b) {
                $out = $everyone ?? $ticked('out', $b['line_id']);
                if ($out !== $b['out']) {
                    q('UPDATE order_lines SET out_of_stock = ? WHERE id = ?', [$out ? 1 : 0, $b['line_id']]);
                    $notes[$b['household_id']][] = $out
                        ? "Out of stock: {$b['qty']} x $name. You won't be charged for it, and it will be saved to your list for next month."
                        : "Not out of stock after all: {$b['qty']} x $name is back on your order.";
                    $changes++;
                }
                if ($newPrice !== $oldPrice) {
                    q('UPDATE order_lines SET unit_price_cents = ? WHERE id = ?', [$newPrice, $b['line_id']]);
                    if (!$out) {
                        $notes[$b['household_id']][] = "Price change: $name was " . money($oldPrice)
                            . " each and is now " . money($newPrice) . " each.";
                    }
                    $changes++;
                }
            }

            foreach ($item['shares'] as $s) {
                $out = $everyone ?? $ticked('share_out', $s['id']);
                if ($out !== (bool) $s['out_of_stock']) {
                    q('UPDATE shares SET out_of_stock = ? WHERE id = ?', [$out ? 1 : 0, $s['id']]);
                    foreach ($s['claims'] as $c) {
                        $notes[$c['household_id']][] = $out
                            ? "Out of stock: the share of $name. Nobody is charged for it, and it goes back to Share items for next month."
                            : "Not out of stock after all: the share of $name is back on the order.";
                    }
                    $changes++;
                }
                if ($newPrice !== $oldPrice) {
                    share_set_price((int) $s['id'], $newPrice);
                    $after = find_share((int) $s['id']);
                    foreach ($s['claims'] as $i => $c) {
                        if (!$out) {
                            $notes[$c['household_id']][] = "Price change: the shared $name was " . money($oldPrice)
                                . " and is now " . money($newPrice) . ". {$c['name']}'s part (" . fmt_amount($c['amount'])
                                . " {$s['size_unit']}) was " . money($c['cost_cents']) . " and is now "
                                . money($after['claims'][$i]['cost_cents']) . ".";
                        }
                    }
                    $changes++;
                }
            }
        }
        db()->exec('COMMIT');
    } catch (Throwable $err) {
        db()->exec('ROLLBACK');
        throw $err;
    }
    return ['changes' => $changes, 'bad_prices' => $badPrices, 'notes' => $notes];
}

/**
 * Emails each household affected by out-of-stock or price changes: what changed, then
 * their whole order as it now stands. Returns how many households were emailed.
 */
function stock_and_prices_email(array $cycle, array $notes): int
{
    foreach ($notes as $householdId => $sentences) {
        $order = household_order((int) $cycle['id'], (int) $householdId);
        // {first_name} is filled in for each person in the household by email_household().
        $body = "Hi {first_name},\n\n"
            . "Hummingbird had a price change or something on your order was out of stock. Here is what changed:\n\n"
            . "- " . implode("\n\n- ", $sentences) . "\n\n"
            . "Your order now:\n\n"
            . order_text(order_summary($order), cycle_month_name($cycle) . ' Total', 'Share items', true) . "\n"
            . "You can see it any time at " . config('base_url') . "/?a=order\n";
        email_household((int) $householdId, "Hummingbird order update for {$cycle['label']}", $body, true);
    }
    return count($notes);
}

/**
 * Everything to order from Hummingbird for a month, one entry per item number,
 * each with who ordered it. Orders that are not submitted are included and marked
 * unfinished, and listed separately with contact details.
 */
function combined_order(array $cycle): array
{
    $cycleId = (int) $cycle['id'];
    $items = []; // keyed by variant id (one size of one product)
    $households = [];
    $startItem = function (array $row, int $priceCents) use (&$items): void {
        $items[$row['variant_id']] ??= [
            'variant_id' => (int) $row['variant_id'],
            'sku' => (string) $row['sku'], 'product_title' => $row['product_title'], 'size' => $row['size'],
            'available' => (int) $row['available'], 'active' => (int) $row['active'],
            // qty and the totals count only what is in stock; out_qty is what was marked out of stock.
            'price_cents' => $priceCents, 'qty' => 0, 'out_qty' => 0, 'total_cents' => 0, 'deposits_cents' => 0,
            'buyers' => [], 'shares' => [],
        ];
    };

    $lines = q('SELECT l.id AS line_id, l.variant_id, l.qty, l.unit_price_cents, l.deposit_cents, l.out_of_stock,
                       o.status AS order_status, h.id AS household_id, h.name AS household_name,
                       v.sku, v.title AS size, v.available, v.active, p.title AS product_title
                FROM order_lines l
                JOIN orders o ON o.id = l.order_id
                JOIN households h ON h.id = o.household_id
                JOIN variants v ON v.id = l.variant_id
                JOIN products p ON p.id = v.product_id
                WHERE o.cycle_id = ?
                ORDER BY h.name', [$cycleId])->fetchAll();
    foreach ($lines as $l) {
        $startItem($l, (int) $l['unit_price_cents']);
        $item = &$items[$l['variant_id']];
        if ($l['out_of_stock']) {
            $item['out_qty'] += (int) $l['qty'];
        } else {
            $item['qty'] += (int) $l['qty'];
            $item['total_cents'] += $l['qty'] * $l['unit_price_cents'];
            $item['deposits_cents'] += $l['qty'] * $l['deposit_cents'];
        }
        $item['buyers'][] = ['line_id' => (int) $l['line_id'], 'household_id' => (int) $l['household_id'],
                             'household' => $l['household_name'], 'qty' => (int) $l['qty'],
                             'unfinished' => $l['order_status'] !== 'submitted', 'out' => (bool) $l['out_of_stock']];
        unset($item);
        $households[$l['household_id']] = true;
    }

    // Each share is one more of that item to order.
    foreach (shares_on_order($cycleId) as $s) {
        $startItem($s, (int) $s['price_cents']);
        $item = &$items[$s['variant_id']];
        if ($s['out_of_stock']) {
            $item['out_qty'] += 1;
        } else {
            $item['qty'] += 1;
            $item['total_cents'] += $s['price_cents'];
        }
        $item['shares'][] = $s;
        unset($item);
        foreach ($s['claims'] as $c) {
            $households[$c['household_id']] = true;
        }
    }

    // Item-number order, so the list reads like Hummingbird's order form (G9 before G129).
    usort($items, fn(array $a, array $b): int => strnatcasecmp($a['sku'], $b['sku'])
        ?: strcasecmp($a['product_title'], $b['product_title']));

    return [
        'items' => $items,
        'unfinished' => unfinished_orders($cycleId),
        'household_count' => count($households),
        'unit_count' => array_sum(array_column($items, 'qty')),
        'out_count' => array_sum(array_column($items, 'out_qty')),
        'total_cents' => array_sum(array_column($items, 'total_cents')),
        'deposits_cents' => array_sum(array_column($items, 'deposits_cents')),
    ];
}

/**
 * Orders with items on them that have not been submitted (or were changed after
 * submitting), with the household's people so the organizer can get in touch.
 */
function unfinished_orders(int $cycleId): array
{
    $orders = q("SELECT o.id, o.submitted_at, h.id AS household_id, h.name AS household_name
                 FROM orders o JOIN households h ON h.id = o.household_id
                 WHERE o.cycle_id = ? AND o.status = 'draft'
                   AND EXISTS (SELECT 1 FROM order_lines l WHERE l.order_id = o.id)
                 ORDER BY h.name", [$cycleId])->fetchAll();
    foreach ($orders as &$o) {
        $o['people'] = q('SELECT name, phone, email FROM members WHERE household_id = ? AND active = 1 ORDER BY id',
            [$o['household_id']])->fetchAll();
    }
    return $orders;
}

/**
 * The report for a month: every household on the order with its items, share parts and
 * what it owes, plus the totals for the whole group. Always worked out from the current
 * figures, so out-of-stock ticks and corrected prices show up straight away.
 */
function month_report(array $cycle): array
{
    $cycleId = (int) $cycle['id'];
    // Households with items of their own, and households that are only on a share.
    $ids = q('SELECT DISTINCT o.household_id FROM orders o JOIN order_lines l ON l.order_id = o.id
              WHERE o.cycle_id = ?', [$cycleId])->fetchAll(PDO::FETCH_COLUMN);
    foreach (shares_on_order($cycleId) as $s) {
        foreach ($s['claims'] as $c) {
            $ids[] = $c['household_id'];
        }
    }

    $households = [];
    foreach (array_unique(array_map('intval', $ids)) as $householdId) {
        $order = household_order($cycleId, $householdId);
        $summary = order_summary($order);
        $households[] = [
            'id' => $householdId,
            'name' => (string) q('SELECT name FROM households WHERE id = ?', [$householdId])->fetchColumn(),
            'people' => q('SELECT name FROM members WHERE household_id = ? AND active = 1 ORDER BY id',
                [$householdId])->fetchAll(PDO::FETCH_COLUMN),
            // Has items of its own that were never submitted (or were changed after submitting).
            'unfinished' => $summary['lines'] && $order['status'] !== 'submitted',
            'summary' => $summary,
            // Null until the organizer marks the order paid; then when, and how (check, venmo or cash).
            'paid_at' => $order['paid_at'],
            'paid_method' => $order['paid_method'],
        ];
    }
    usort($households, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    $total = fn(string $key): int => array_sum(array_map(fn(array $h): int => $h['summary'][$key], $households));
    $paid = array_filter($households, fn(array $h): bool => $h['paid_at'] !== null);
    // A household that owes nothing (everything was out of stock) has nothing left to pay.
    $owing = array_filter($households, fn(array $h): bool => $h['paid_at'] === null && $h['summary']['due_cents'] > 0);
    return [
        'households' => $households,
        'items_cents' => $total('items_cents'), 'deposits_cents' => $total('deposits_cents'),
        'shares_cents' => $total('shares_cents'), 'due_cents' => $total('due_cents'),
        'collected_cents' => array_sum(array_map(fn(array $h): int => $h['summary']['due_cents'], $paid)),
        'owing_count' => count($owing),
    ];
}

/** The report as plain text, for the email. $occasion says why it is being sent. */
function month_report_text(array $cycle, string $occasion): string
{
    $report = month_report($cycle);
    $count = count($report['households']);
    // $occasion (why this copy was sent) is no longer printed; the heading is the same each time.
    $text = config('site_name') . " {$cycle['label']} order summary.\n\n";
    if ($count === 0) {
        return $text . "Nobody has ordered anything.\n";
    }

    // A short list first: one line per household with what it owes.
    $width = max(array_map(fn(array $h): int => mb_strlen($h['name']), $report['households'])) + 2;
    $pad = fn(string $name): string => $name . str_repeat(' ', max(1, $width - mb_strlen($name)));
    foreach ($report['households'] as $h) {
        $text .= $pad($h['name']) . str_pad(money($h['summary']['due_cents']), 10, ' ', STR_PAD_LEFT)
            . ($h['unfinished'] ? '  (unfinished)' : '')
            . ($h['paid_at'] ? '  paid by ' . paid_how($h['paid_method']) : '') . "\n";
    }
    $text .= $pad('Total') . str_pad(money($report['due_cents']), 10, ' ', STR_PAD_LEFT) . "\n\n"
        . "$count " . ($count === 1 ? 'household' : 'households') . ". Regular items " . money($report['items_cents'])
        . ", container deposits " . money($report['deposits_cents'])
        . ", shares " . money($report['shares_cents']) . ".\n";

    // Then each household in full.
    foreach ($report['households'] as $h) {
        $text .= "\n" . str_repeat('-', 40) . "\n\n" . mb_strtoupper($h['name'])
            . ($h['people'] ? ' (' . implode(', ', $h['people']) . ')' : '')
            . ($h['unfinished'] ? "\nUNFINISHED: this order was not submitted." : '') . "\n\n"
            . order_text($h['summary'], 'Total due', 'Share items');
    }
    return $text . "\n" . str_repeat('-', 40) . "\nOn the site: " . config('base_url')
        . '/?a=organizer&month=' . (int) $cycle['id'] . "&view=report\n";
}

/**
 * Emails the report to every active organizer. Pass $text to send a report that was
 * written out earlier (closing a month writes it before out-of-stock shares move on).
 * Returns how many organizers it went to.
 */
function month_report_email(array $cycle, string $occasion, ?string $text = null): int
{
    $text ??= month_report_text($cycle, $occasion);
    $organizers = q('SELECT email FROM members WHERE is_admin = 1 AND active = 1')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($organizers as $email) {
        send_email($email, config('site_name') . " report for {$cycle['label']}", $text);
    }
    return count($organizers);
}

/**
 * A link that fills a cart on Hummingbird's site with everything on the combined order,
 * so the organizer doesn't have to type each item in. It uses Shopify's "cart permalink"
 * (/cart/VARIANT:QTY,VARIANT:QTY); "storefront=true" asks for the cart page, not checkout.
 * Items marked out of stock are left out. Items Hummingbird no longer lists can't be
 * added by a link, so they come back in 'skipped' to be shown beside it.
 */
function hummingbird_cart_link(array $combined): array
{
    $parts = [];
    $skipped = [];
    foreach ($combined['items'] as $item) {
        if ($item['qty'] < 1) {
            continue;
        }
        if (!$item['active']) {
            $skipped[] = "{$item['sku']}: {$item['product_title']}, {$item['size']} x {$item['qty']}";
            continue;
        }
        $parts[] = $item['variant_id'] . ':' . $item['qty'];
    }
    // The shop's address is the catalog feed's address without the file name.
    $feed = (string) config('catalog_url');
    $shop = parse_url($feed, PHP_URL_SCHEME) . '://' . parse_url($feed, PHP_URL_HOST);
    return [
        'url' => $parts ? $shop . '/cart/' . implode(',', $parts) . '?storefront=true' : null,
        'items' => count($parts),
        'skipped' => $skipped,
    ];
}
