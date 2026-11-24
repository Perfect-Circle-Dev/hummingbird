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
                        p.title AS product_title, m.name AS started_by_name
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
    $claims = q('SELECT c.*, m.name, m.household_id FROM share_claims c JOIN members m ON m.id = c.member_id
                 WHERE c.share_id = ? ORDER BY c.id', [$s['id']])->fetchAll();
    $claimed = 0.0;
    foreach ($claims as &$c) {
        $c['amount'] = (float) $c['amount'];
        $c['cost_cents'] = (int) round($s['price_cents'] * $c['amount'] / $total);
        $claimed += $c['amount'];
    }
    unset($c);
    $s['claims'] = $claims;
    $s['claimed'] = $claimed;
    $s['left'] = max(0.0, $total - $claimed);
    $s['full'] = $s['left'] < SHARE_EPSILON;
    $s['per_unit_cents'] = (int) round($s['price_cents'] / $total);
    return $s;
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
 * A household's parts of shares that count toward a month: shares that are fully
 * claimed and still open (they will be ordered), or already ordered in that month.
 */
function household_share_portions(int $householdId, int $cycleId): array
{
    $portions = [];
    foreach (board_shares($cycleId) as $s) {
        if (!$s['full'] || $s['out_of_stock']) {
            continue;
        }
        foreach ($s['claims'] as $c) {
            if ((int) $c['household_id'] === $householdId) {
                $portions[] = [
                    'share_id' => $s['id'], 'product_title' => $s['product_title'], 'size' => $s['size'],
                    'sku' => $s['sku'], 'size_unit' => $s['size_unit'], 'amount' => $c['amount'],
                    'cost_cents' => $c['cost_cents'], 'member_name' => $c['name'],
                ];
            }
        }
    }
    return $portions;
}
