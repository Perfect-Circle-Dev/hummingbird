<?php
// The share board: members split one bulk item between them, by weight or volume.
declare(strict_types=1);

const SHARE_EPSILON = 0.001; // amounts closer than this count as equal

/** Tidy number for display: 10.0 -> "10", 2.50 -> "2.5" */
function fmt_amount(float $n): string
{
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

/**
 * Shares to show on the board: open ones, plus those ordered in the given month.
 * Each comes back with its claims, how much is left, and what each claim costs.
 */
function board_shares(?int $cycleId): array
{
    $shares = q("SELECT s.*, v.sku, v.title AS size, v.size_amount, v.size_unit, v.list_price_cents,
                        v.available, v.active, p.title AS product_title, m.name AS started_by_name
                 FROM shares s JOIN variants v ON v.id = s.variant_id JOIN products p ON p.id = v.product_id
                 JOIN members m ON m.id = s.started_by
                 WHERE s.status = 'open' OR (s.status = 'ordered' AND s.cycle_id IS ?)
                 ORDER BY s.created_at", [$cycleId])->fetchAll();
    foreach ($shares as &$s) {
        $s = share_with_claims($s);
    }
    return $shares;
}

function find_share(int $shareId): ?array
{
    $s = q('SELECT s.*, v.sku, v.title AS size, v.size_amount, v.size_unit, v.list_price_cents,
                   p.title AS product_title
            FROM shares s JOIN variants v ON v.id = s.variant_id JOIN products p ON p.id = v.product_id
            WHERE s.id = ?', [$shareId])->fetch();
    return $s ? share_with_claims($s) : null;
}

function share_with_claims(array $s): array
{
    // Once ordered, the price is fixed on the share; until then it follows the catalog.
    $s['price_cents'] = (int) ($s['price_cents'] ?? member_price_cents((int) $s['list_price_cents']));
    $total = (float) $s['size_amount'];
    $claims = q('SELECT c.*, m.name, m.household_id, h.name AS household_name
                 FROM share_claims c JOIN members m ON m.id = c.member_id JOIN households h ON h.id = m.household_id
                 WHERE c.share_id = ? ORDER BY c.id', [$s['id']])->fetchAll();
    $claimed = 0.0;
    foreach ($claims as &$c) {
        $c['amount'] = (float) $c['amount'];
        $claimed += $c['amount'];
    }
    unset($c);
    $s['claimed'] = $claimed;
    $s['left'] = max(0.0, $total - $claimed);
    $s['full'] = $s['left'] < SHARE_EPSILON;
    $s['per_unit_cents'] = (int) round($s['price_cents'] / $total);

    // What each person pays. Once the share is ordered the parts are saved on the claims
    // and no longer move. Before that: a full share is split so the parts add up to the
    // price exactly, and a share still filling just shows each part rounded to the cent.
    $split = null;
    if ($s['full']) {
        // Any odd cent goes to whoever started the share. If they have since cancelled
        // their own claim, it goes to the earliest claim instead.
        $starter = array_search((int) $s['started_by'], array_map('intval', array_column($claims, 'member_id')), true);
        $split = share_split_cents($s['price_cents'], $total, array_column($claims, 'amount'), $starter === false ? 0 : $starter);
    }
    foreach ($claims as $i => &$c) {
        $saved = $c['cost_cents'] ?? null;
        if ($saved !== null) {
            $c['cost_cents'] = (int) $saved;
        } elseif ($split) {
            $c['cost_cents'] = $split[$i];
        } else {
            $c['cost_cents'] = (int) round($s['price_cents'] * $c['amount'] / $total);
        }
    }
    unset($c);
    $s['claims'] = $claims;
    return $s;
}

/**
 * Splits a price between claims in proportion to their amounts, in whole cents.
 * Rounding each part can leave the parts a cent or two off the price (three equal
 * parts of $10.00 come to $9.99), so the difference goes on one claim: the one at
 * position $oddCentTo in the list (the caller passes the share starter's claim).
 */
function share_split_cents(int $priceCents, float $total, array $amounts, int $oddCentTo = 0): array
{
    $parts = [];
    foreach ($amounts as $i => $amount) {
        $parts[$i] = (int) round($priceCents * $amount / $total);
    }
    if (isset($parts[$oddCentTo])) {
        $parts[$oddCentTo] += $priceCents - array_sum($parts);
    }
    return $parts;
}

/** Starts a share of an item, with the starter's own amount. Returns the new share id, or null. */
function share_start(int $variantId, int $memberId, float $amount): ?int
{
    $variant = find_variant($variantId);
    if (!$variant || !$variant['active'] || !$variant['size_amount'] || $amount <= 0) {
        return null;
    }
    $amount = min($amount, (float) $variant['size_amount']);
    $months = (int) setting('share_expiry_months', '3');
    $expires = gmdate('Y-m-d H:i:s', strtotime("+$months months"));
    q('INSERT INTO shares (variant_id, started_by, expires_at) VALUES (?, ?, ?)', [$variantId, $memberId, $expires]);
    $shareId = (int) db()->lastInsertId();
    q('INSERT INTO share_claims (share_id, member_id, amount) VALUES (?, ?, ?)', [$shareId, $memberId, $amount]);
    return $shareId;
}

/**
 * Sets a member's claim to an amount (not "adds"). Zero cancels the claim.
 * The amount is capped at what is left plus what the member already holds.
 * Only open shares can change; once a share is on a locked order it is fixed.
 */
function share_set_claim(int $shareId, int $memberId, float $amount): bool
{
    $share = find_share($shareId);
    if (!$share || $share['status'] !== 'open') {
        return false;
    }
    $mine = 0.0;
    foreach ($share['claims'] as $c) {
        if ((int) $c['member_id'] === $memberId) {
            $mine = $c['amount'];
        }
    }
    $amount = min($amount, $share['left'] + $mine);
    if ($amount <= 0) {
        q('DELETE FROM share_claims WHERE share_id = ? AND member_id = ?', [$shareId, $memberId]);
        // A share nobody wants any more comes off the board.
        if ((int) q('SELECT COUNT(*) FROM share_claims WHERE share_id = ?', [$shareId])->fetchColumn() === 0) {
            q('DELETE FROM shares WHERE id = ?', [$shareId]);
        }
        return true;
    }
    q('INSERT INTO share_claims (share_id, member_id, amount) VALUES (?, ?, ?)
       ON CONFLICT(share_id, member_id) DO UPDATE SET amount = excluded.amount', [$shareId, $memberId, $amount]);
    return true;
}

/**
 * Sets the price of a share that is on an order, and saves each person's part of it.
 * Used when the month is locked and again if the organizer corrects the price.
 */
function share_set_price(int $shareId, int $priceCents): void
{
    q('UPDATE shares SET price_cents = ? WHERE id = ?', [$priceCents, $shareId]);
    // Clearing the saved parts makes find_share work them out afresh from the new price.
    q('UPDATE share_claims SET cost_cents = NULL WHERE share_id = ?', [$shareId]);
    $share = find_share($shareId);
    foreach ($share['claims'] as $c) {
        q('UPDATE share_claims SET cost_cents = ? WHERE id = ?', [$c['cost_cents'], $c['id']]);
    }
}

// ---- The organizer's Shares page: changing or deleting any share by hand ----

/**
 * Can an organizer still change this share? Yes while it is on the board, and while it is
 * on the order of the month that is locked now. Not once its month is closed, or it expired.
 */
function share_admin_editable(array $share): bool
{
    if ($share['status'] === 'open') {
        return true;
    }
    $cycle = current_cycle();
    return $share['status'] === 'ordered' && $cycle && (int) $share['cycle_id'] === (int) $cycle['id'];
}

/**
 * Sets the amounts on a share as the organizer typed them. $amounts is claim id => amount
 * for the people already on it (nought takes that person off); $addMemberId and $addAmount
 * put one more person on. Returns null when saved, 'same' when nothing was different, or
 * the short name of a problem. The people on the share, before or after, are emailed.
 *
 * A share on a locked order has to stay fully claimed, because its price is already split
 * between its people; each person's part is worked out again from the new amounts.
 */
function share_admin_save(int $shareId, array $amounts, int $addMemberId, string $addAmount, array $actor): ?string
{
    $share = find_share($shareId);
    if (!$share || !share_admin_editable($share)) {
        return 'share_fixed';
    }
    $new = [];       // member id => amount, for everyone who will be on the share
    $changed = false;
    foreach ($share['claims'] as $c) {
        $typed = trim((string) ($amounts[$c['id']] ?? fmt_amount($c['amount'])));
        if (!is_numeric($typed) || (float) $typed < 0) {
            return 'share_amount';
        }
        $amount = round((float) $typed, 2);
        if (abs($amount - $c['amount']) >= SHARE_EPSILON) {
            $changed = true;
        }
        if ($amount > 0) {
            $new[(int) $c['member_id']] = $amount;
        }
    }
    if ($addMemberId && trim($addAmount) !== '') {
        $added = find_member($addMemberId);
        if (!$added || !$added['active'] || !is_numeric(trim($addAmount)) || (float) $addAmount <= 0) {
            return 'share_amount';
        }
        // Someone already on the share is changed in their own box, not added a second time.
        if (!in_array($addMemberId, array_map('intval', array_column($share['claims'], 'member_id')), true)) {
            $new[$addMemberId] = round((float) $addAmount, 2);
            $changed = true;
        }
    }
    if (!$changed) {
        return 'same';
    }
    $size = (float) $share['size_amount'];
    $total = array_sum($new);
    if ($total > $size + SHARE_EPSILON) {
        return 'share_over';
    }
    if ($share['status'] === 'ordered' && $new && abs($total - $size) >= SHARE_EPSILON) {
        return 'share_not_full';
    }
    if (!$new) {
        // Nobody left on it: that is the same as deleting it.
        return share_admin_delete($shareId, $actor);
    }

    db()->beginTransaction();
    q('DELETE FROM share_claims WHERE share_id = ? AND member_id NOT IN (' . implode(',', array_keys($new)) . ')', [$shareId]);
    foreach ($new as $memberId => $amount) {
        q('INSERT INTO share_claims (share_id, member_id, amount) VALUES (?, ?, ?)
           ON CONFLICT(share_id, member_id) DO UPDATE SET amount = excluded.amount', [$shareId, $memberId, $amount]);
    }
    if ($share['status'] === 'ordered') {
        // Works each person's part of the fixed price out again.
        share_set_price($shareId, (int) $share['price_cents']);
    }
    db()->commit();
    share_change_email($share, find_share($shareId), $actor);
    return null;
}

/** Deletes a share and everyone's claim on it, and emails the people who were on it. */
function share_admin_delete(int $shareId, array $actor): ?string
{
    $share = find_share($shareId);
    if (!$share || !share_admin_editable($share)) {
        return 'share_fixed';
    }
    q('DELETE FROM share_claims WHERE share_id = ?', [$shareId]);
    q('DELETE FROM shares WHERE id = ?', [$shareId]);
    share_change_email($share, null, $actor);
    return null;
}

/**
 * Tells the people on a share that an organizer changed it ($after is the share as it is
 * now) or deleted it ($after is null). It goes to everyone who was on it or is on it now,
 * except the organizer who did it, and except anyone archived or with "Receives
 * notification emails" switched off. Returns how many were emailed.
 */
function share_change_email(array $before, ?array $after, array $actor): int
{
    $item = "{$before['product_title']}, {$before['size']}";
    $unit = $before['size_unit'];
    $people = [];
    foreach (array_merge($before['claims'], $after['claims'] ?? []) as $c) {
        $people[(int) $c['member_id']] = true;
    }
    $now = [];       // member id => amount, as the share stands
    $list = '';
    foreach ($after['claims'] ?? [] as $c) {
        $now[(int) $c['member_id']] = $c['amount'];
        $list .= "{$c['name']}: " . fmt_amount($c['amount']) . " $unit\n";
    }
    $sent = 0;
    foreach (array_keys($people) as $memberId) {
        $m = find_member($memberId);
        if (!$m || !$m['active'] || $m['email_opt_out'] || $memberId === (int) $actor['id']) {
            continue;
        }
        $body = "Hi " . first_name($m) . ",\n\n";
        if ($after === null) {
            $subject = "Share item removed: {$before['product_title']}";
            $body .= first_name($actor) . " has removed the share of $item from Share items. "
                . "It will not be ordered and you will not be charged for it.\n";
        } else {
            $subject = "Share item changed: {$before['product_title']}";
            $body .= first_name($actor) . " has changed the share of $item. Here is how it stands now:\n\n"
                . $list . "\n"
                . fmt_amount($after['claimed']) . " of " . fmt_amount((float) $after['size_amount']) . " $unit claimed"
                . ($after['full'] ? '' : ", " . fmt_amount($after['left']) . " $unit left") . ".\n\n"
                . (isset($now[$memberId])
                    ? "Your part is " . fmt_amount($now[$memberId]) . " $unit.\n"
                    : "You are no longer on this share.\n")
                . "\nYou can see it here:\n\n" . config('base_url') . "/?a=shares#share-{$after['id']}\n";
        }
        $sent += send_email($m['email'], $subject, $body) ? 1 : 0;
    }
    return $sent;
}

/** Takes unfilled shares off the board once they pass their expiry date. Run nightly. */
function expire_shares(): int
{
    $expired = 0;
    $rows = q("SELECT id FROM shares WHERE status = 'open' AND expires_at < ?", [now_utc()])->fetchAll();
    foreach ($rows as $row) {
        $share = find_share((int) $row['id']);
        if ($share && !$share['full']) {
            q("UPDATE shares SET status = 'expired' WHERE id = ?", [$share['id']]);
            $expired++;
        }
    }
    return $expired;
}

/**
 * The shares that are part of a month's order. While the month is open, that is every
 * fully claimed share (they will be ordered when it locks). Once it is locked, only the
 * shares that were moved onto it: one that fills later waits for the next month.
 */
function shares_on_order(int $cycleId): array
{
    $status = q('SELECT status FROM cycles WHERE id = ?', [$cycleId])->fetchColumn();
    $wanted = $status === 'open' ? 'open' : 'ordered';
    return array_values(array_filter(board_shares($cycleId),
        fn(array $s): bool => $s['full'] && $s['status'] === $wanted));
}

/**
 * A household's parts of the shares on a month's order (see shares_on_order).
 * A share Hummingbird was out of stock on stays in the list, marked, and costs nothing.
 */
function household_share_portions(int $householdId, int $cycleId): array
{
    $portions = [];
    foreach (shares_on_order($cycleId) as $s) {
        foreach ($s['claims'] as $c) {
            if ((int) $c['household_id'] === $householdId) {
                $portions[] = [
                    'share_id' => $s['id'], 'product_title' => $s['product_title'], 'size' => $s['size'],
                    'sku' => $s['sku'], 'size_unit' => $s['size_unit'], 'amount' => $c['amount'],
                    'cost_cents' => $s['out_of_stock'] ? 0 : $c['cost_cents'], 'member_name' => $c['name'],
                    'out_of_stock' => (bool) $s['out_of_stock'],
                ];
            }
        }
    }
    return $portions;
}
