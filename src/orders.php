<?php
// Monthly orders: one per household per month.
declare(strict_types=1);

/** The month members are ordering for right now (open or locked), or null between months. */
function current_cycle(): ?array
{
    $row = q("SELECT * FROM cycles WHERE status IN ('open', 'locked') ORDER BY id DESC LIMIT 1")->fetch();
    return $row ?: null;
}

/**
 * The standing "next month" list. It has no name or deadline yet: members can save
 * items on it at any time, and when the organizer opens a month this is the row that
 * becomes that month, so the saved items are already on each household's order.
 * There is always exactly one; it is created here the first time it is needed.
 */
function next_cycle(): array
{
    $find = fn() => q("SELECT * FROM cycles WHERE status = 'next' ORDER BY id LIMIT 1")->fetch();
    $row = $find();
    if (!$row) {
        // The name and deadline are placeholders (the columns can't be left empty).
        // OR IGNORE: if two people load a page at the same moment, only one row is made.
        q("INSERT OR IGNORE INTO cycles (label, due_at, status) VALUES ('Next month', '', 'next')");
        $row = $find();
    }
    return $row;
}

/** "2026-12-15 03:00:00" (UTC, as stored) -> "Monday, December 14 at 7:00 pm" in the site's timezone. */
function fmt_local(string $utc, string $format = 'l, F j \a\t g:i a'): string
{
    $dt = new DateTime($utc, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone(config('timezone')));
    return $dt->format($format);
}

/** A variant joined with its product name, or null. */
function find_variant(int $variantId): ?array
{
    $row = q('SELECT v.*, p.title AS product_title FROM variants v JOIN products p ON p.id = v.product_id
              WHERE v.id = ?', [$variantId])->fetch();
    return $row ?: null;
}

function variant_deposit_cents(array $variant): int
{
    return (int) ($variant['deposit_override_cents'] ?? $variant['deposit_cents']);
}

/**
 * Search the catalog by words from the name, size or item number. Every word must match.
 * Best matches come first: an exact item number, then item numbers and names that
 * start with what was typed, then names with a word that starts with it, then the rest.
 */
function search_catalog(string $query, int $limit = 30): array
{
    $query = trim($query);
    $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) {
        return [];
    }
    $esc = fn(string $text): string => addcslashes($text, '%_\\');
    $where = [];
    $whereParams = [];
    foreach ($words as $word) {
        $where[] = "(p.title LIKE ? ESCAPE '\\' OR v.title LIKE ? ESCAPE '\\' OR v.sku LIKE ? ESCAPE '\\')";
        $like = '%' . $esc($word) . '%';
        array_push($whereParams, $like, $like, $like);
    }
    $first = $esc($words[0]);
    $rankParams = [$query, $esc($query) . '%', $first . '%', '% ' . $first . '%'];
    return q("SELECT v.*, p.title AS product_title,
                     CASE WHEN v.sku = ? COLLATE NOCASE THEN 0
                          WHEN v.sku LIKE ? ESCAPE '\\' THEN 1
                          WHEN p.title LIKE ? ESCAPE '\\' THEN 2
                          WHEN p.title LIKE ? ESCAPE '\\' THEN 3
                          ELSE 4 END AS match_rank
              FROM variants v JOIN products p ON p.id = v.product_id
              WHERE v.active = 1 AND " . implode(' AND ', $where) . "
              ORDER BY match_rank, p.title, v.list_price_cents LIMIT ?",
        array_merge($rankParams, $whereParams, [$limit]))->fetchAll();
}

function favorites_for(int $memberId): array
{
    return q('SELECT v.*, p.title AS product_title FROM favorites f
              JOIN variants v ON v.id = f.variant_id JOIN products p ON p.id = v.product_id
              WHERE f.member_id = ? ORDER BY p.title, v.list_price_cents', [$memberId])->fetchAll();
}

function toggle_favorite(int $memberId, int $variantId): void
{
    $deleted = q('DELETE FROM favorites WHERE member_id = ? AND variant_id = ?', [$memberId, $variantId])->rowCount();
    if ($deleted === 0 && find_variant($variantId)) {
        q('INSERT INTO favorites (member_id, variant_id) VALUES (?, ?)', [$memberId, $variantId]);
    }
}

/** The household's order for a month, created on first use. */
function household_order(int $cycleId, int $householdId): array
{
    q('INSERT OR IGNORE INTO orders (cycle_id, household_id, updated_at) VALUES (?, ?, ?)',
        [$cycleId, $householdId, now_utc()]);
    return q('SELECT * FROM orders WHERE cycle_id = ? AND household_id = ?', [$cycleId, $householdId])->fetch();
}

function order_lines(int $orderId): array
{
    return q('SELECT l.*, v.sku, v.title AS size, v.active AS still_listed, p.title AS product_title
              FROM order_lines l JOIN variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id
              WHERE l.order_id = ? ORDER BY p.title, v.title', [$orderId])->fetchAll();
}

/** Any change puts the order back to "not submitted", so the member confirms what they changed. */
function touch_order(int $orderId): void
{
    q("UPDATE orders SET status = 'draft', updated_at = ? WHERE id = ?", [now_utc(), $orderId]);
}

/** Adds one of an item, or one more if it is already on the order. */
function order_add(array $order, int $variantId, int $memberId): bool
{
    $variant = find_variant($variantId);
    if (!$variant || !$variant['active']) {
        return false;
    }
    q('INSERT INTO order_lines (order_id, variant_id, qty, unit_price_cents, deposit_cents, added_by)
       VALUES (?, ?, 1, ?, ?, ?)
       ON CONFLICT(order_id, variant_id) DO UPDATE SET qty = qty + 1',
        [$order['id'], $variantId, member_price_cents((int) $variant['list_price_cents']),
         variant_deposit_cents($variant), $memberId]);
    touch_order((int) $order['id']);
    return true;
}

/** Sets a line's quantity. Zero or less removes the line. */
function order_set_qty(array $order, int $lineId, int $qty): void
{
    if ($qty <= 0) {
        q('DELETE FROM order_lines WHERE id = ? AND order_id = ?', [$lineId, $order['id']]);
    } else {
        q('UPDATE order_lines SET qty = ? WHERE id = ? AND order_id = ?', [min($qty, 99), $lineId, $order['id']]);
    }
    touch_order((int) $order['id']);
}

/**
 * While a month is open, order lines follow the catalog's current prices, and so do
 * items saved for next month. Called after each catalog refresh; locking a month
 * freezes its prices, because locked and closed months are left alone here.
 */
function refresh_open_order_prices(): int
{
    $changed = 0;
    $lines = q("SELECT l.id, l.unit_price_cents, l.deposit_cents, v.list_price_cents, v.deposit_cents AS v_dep,
                       v.deposit_override_cents
                FROM order_lines l JOIN orders o ON o.id = l.order_id JOIN cycles c ON c.id = o.cycle_id
                JOIN variants v ON v.id = l.variant_id
                WHERE c.status IN ('open', 'next')")->fetchAll();
    foreach ($lines as $l) {
        $price = member_price_cents((int) $l['list_price_cents']);
        $deposit = (int) ($l['deposit_override_cents'] ?? $l['v_dep']);
        if ($price !== (int) $l['unit_price_cents'] || $deposit !== (int) $l['deposit_cents']) {
            q('UPDATE order_lines SET unit_price_cents = ?, deposit_cents = ? WHERE id = ?', [$price, $deposit, $l['id']]);
            $changed++;
        }
    }
    return $changed;
}

/** Everything a household owes for a month: regular items, deposits and its part of filled shares. */
function order_summary(array $order): array
{
    $lines = order_lines((int) $order['id']);
    $items = 0;
    $deposits = 0;
    foreach ($lines as &$l) {
        $l['line_cents'] = $l['out_of_stock'] ? 0 : $l['qty'] * $l['unit_price_cents'];
        $l['line_deposit_cents'] = $l['out_of_stock'] ? 0 : $l['qty'] * $l['deposit_cents'];
        $items += $l['line_cents'];
        $deposits += $l['line_deposit_cents'];
    }
    unset($l);
    $shares = household_share_portions((int) $order['household_id'], (int) $order['cycle_id']);
    $shareTotal = array_sum(array_column($shares, 'cost_cents'));
    return [
        'lines' => $lines, 'shares' => $shares,
        'items_cents' => $items, 'deposits_cents' => $deposits, 'shares_cents' => $shareTotal,
        'due_cents' => $items + $deposits + $shareTotal,
    ];
}

/** Marks the order submitted and emails a summary to everyone in the household. */
function order_submit(array $order, array $cycle): void
{
    q("UPDATE orders SET status = 'submitted', submitted_at = ?, updated_at = ? WHERE id = ?",
        [now_utc(), now_utc(), $order['id']]);
    $summary = order_summary($order);
    $body = "Thanks! Here is your " . config('site_name') . " order for {$cycle['label']}.\n\n";
    foreach ($summary['lines'] as $l) {
        $body .= sprintf("%d x %s, %s (%s) at %s = %s%s\n", $l['qty'], $l['product_title'], $l['size'], $l['sku'],
            money((int) $l['unit_price_cents']), money($l['line_cents']),
            $l['line_deposit_cents'] ? ' + ' . money($l['line_deposit_cents']) . ' deposit' : '');
    }
    foreach ($summary['shares'] as $s) {
        $body .= sprintf("Share: %s %s of %s, %s = %s\n", rtrim(rtrim(number_format((float) $s['amount'], 2), '0'), '.'),
            $s['size_unit'], $s['product_title'], $s['size'], money($s['cost_cents']));
    }
    if (!$summary['lines'] && !$summary['shares']) {
        $body .= "(Nothing on the order yet.)\n";
    }
    $body .= "\nRegular items: " . money($summary['items_cents'])
        . "\nContainer deposits: " . money($summary['deposits_cents'])
        . "\nYour part of filled shares: " . money($summary['shares_cents'])
        . "\nEstimated total due at pickup: " . money($summary['due_cents']) . "\n\n"
        . "Prices are estimates until the order is placed. You can change your order until it is locked: "
        . config('base_url') . "/?a=order\nIf you make changes, submit again to get an updated summary.\n";

    $members = q('SELECT email FROM members WHERE household_id = ? AND active = 1', [$order['household_id']])->fetchAll();
    foreach ($members as $m) {
        send_email($m['email'], config('site_name') . " order received for {$cycle['label']}", $body);
    }
}
