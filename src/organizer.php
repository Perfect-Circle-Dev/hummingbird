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
            // Save what each person pays. For a full share these parts already
            // add up to the price exactly (see share_split_cents).
            foreach ($share['claims'] as $c) {
                q('UPDATE share_claims SET cost_cents = ? WHERE id = ?', [$c['cost_cents'], $c['id']]);
            }
            q("UPDATE shares SET status = 'ordered', cycle_id = ?, price_cents = ? WHERE id = ?",
                [$cycleId, $share['price_cents'], $share['id']]);
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
        q("UPDATE cycles SET status = 'open', locked_at = NULL WHERE id = ?", [$cycleId]);
        refresh_open_order_prices(); // catch up with any catalog changes made while it was locked
        db()->exec('COMMIT');
    } catch (Throwable $err) {
        db()->exec('ROLLBACK');
        throw $err;
    }
    return true;
}

/** Closes a locked month once pickup and payment are done. Returns false if it was not locked. */
function cycle_close(int $cycleId): bool
{
    return q("UPDATE cycles SET status = 'closed', closed_at = ? WHERE id = ? AND status = 'locked'",
        [now_utc(), $cycleId])->rowCount() === 1;
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
            'sku' => (string) $row['sku'], 'product_title' => $row['product_title'], 'size' => $row['size'],
            'available' => (int) $row['available'], 'active' => (int) $row['active'],
            'price_cents' => $priceCents, 'qty' => 0, 'total_cents' => 0, 'deposits_cents' => 0,
            'buyers' => [], 'shares' => [],
        ];
    };

    $lines = q('SELECT l.variant_id, l.qty, l.unit_price_cents, l.deposit_cents, o.status AS order_status,
                       h.id AS household_id, h.name AS household_name,
                       v.sku, v.title AS size, v.available, v.active, p.title AS product_title
                FROM order_lines l
                JOIN orders o ON o.id = l.order_id
                JOIN households h ON h.id = o.household_id
                JOIN variants v ON v.id = l.variant_id
                JOIN products p ON p.id = v.product_id
                WHERE o.cycle_id = ? AND l.out_of_stock = 0
                ORDER BY h.name', [$cycleId])->fetchAll();
    foreach ($lines as $l) {
        $startItem($l, (int) $l['unit_price_cents']);
        $item = &$items[$l['variant_id']];
        $item['qty'] += (int) $l['qty'];
        $item['total_cents'] += $l['qty'] * $l['unit_price_cents'];
        $item['deposits_cents'] += $l['qty'] * $l['deposit_cents'];
        $item['buyers'][] = ['household' => $l['household_name'], 'qty' => (int) $l['qty'],
                             'unfinished' => $l['order_status'] !== 'submitted'];
        unset($item);
        $households[$l['household_id']] = true;
    }

    // Each share is one more of that item to order.
    foreach (shares_on_order($cycleId) as $s) {
        if ($s['out_of_stock']) {
            continue;
        }
        $startItem($s, (int) $s['price_cents']);
        $item = &$items[$s['variant_id']];
        $item['qty'] += 1;
        $item['total_cents'] += $s['price_cents'];
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
