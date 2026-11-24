<?php
// Copies Hummingbird's public catalog into our database.
declare(strict_types=1);

// A number as Hummingbird writes sizes: 25, 2.5, .5, 1/2, 1 1/2, ½ or "half".
const SIZE_NUMBER = '(\d+\s+\d+\/\d+|\d+\/\d+|\d*\.\d+|\d+|½|half)';

/** "1/2" -> 0.5, "1 1/2" -> 1.5, "½" or "half" -> 0.5, "2.5" -> 2.5 */
function size_number(string $raw): float
{
    $raw = strtolower(trim($raw));
    if ($raw === '½' || $raw === 'half') {
        return 0.5;
    }
    if (preg_match('/^(?:(\d+)\s+)?(\d+)\/(\d+)$/', $raw, $m)) {
        return (float) ($m[1] !== '' ? $m[1] : 0) + ((int) $m[3] ? $m[2] / $m[3] : 0);
    }
    return (float) $raw;
}

/** "25 lb bag" -> [25.0, 'lb'];  "1/2 Gallon jar" -> [0.5, 'gal'];  no size found -> [null, null] */
function parse_size(string $title): array
{
    if (preg_match('/' . SIZE_NUMBER . '[\s-]*(lbs?\b|pounds?\b|gal(?:lons?)?\b|oz\b|#)/iu', $title, $m)) {
        $unit = strtolower($m[2]);
        $unit = str_starts_with($unit, 'gal') ? 'gal' : ($unit === 'oz' ? 'oz' : 'lb');
        return [size_number($m[1]), $unit];
    }
    return [null, null];
}

/** Container deposit, from the gallons mentioned in the size: half gallon, or one gallon and up. */
function deposit_for(string $title): int
{
    if (!preg_match('/' . SIZE_NUMBER . '[\s-]*gal/iu', $title, $m)) {
        return 0;
    }
    $gallons = size_number($m[1]);
    if ($gallons >= 1) {
        return (int) setting('deposit_gallon_plus_cents', '300');
    }
    if ($gallons >= 0.5) {
        return (int) setting('deposit_half_gallon_cents', '150');
    }
    return 0;
}

/** Works out every size's deposit again from its title. Run after the deposit amounts change. */
function recompute_deposits(): void
{
    db()->beginTransaction(); // one write to disk instead of one per size
    foreach (q('SELECT id, title FROM variants')->fetchAll() as $v) {
        q('UPDATE variants SET deposit_cents = ? WHERE id = ?', [deposit_for((string) $v['title']), $v['id']]);
    }
    db()->commit();
}

/** Saves one page of products. Returns how many variants it saw. */
function save_products(array $products, string $syncedAt): int
{
    $count = 0;
    foreach ($products as $p) {
        $tags = is_array($p['tags'] ?? null) ? implode(', ', $p['tags']) : (string) ($p['tags'] ?? '');
        q('INSERT INTO products (id, title, handle, product_type, tags) VALUES (?, ?, ?, ?, ?)
           ON CONFLICT(id) DO UPDATE SET title = excluded.title, handle = excluded.handle,
               product_type = excluded.product_type, tags = excluded.tags',
            [$p['id'], $p['title'], $p['handle'] ?? null, $p['product_type'] ?? null, $tags]);

        foreach ($p['variants'] ?? [] as $v) {
            [$amount, $unit] = parse_size((string) $v['title']);
            q('INSERT INTO variants (id, product_id, sku, title, list_price_cents, grams, available,
                                     size_amount, size_unit, deposit_cents, active, synced_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
               ON CONFLICT(id) DO UPDATE SET sku = excluded.sku, title = excluded.title,
                   list_price_cents = excluded.list_price_cents, grams = excluded.grams,
                   available = excluded.available, size_amount = excluded.size_amount,
                   size_unit = excluded.size_unit, deposit_cents = excluded.deposit_cents,
                   active = 1, synced_at = excluded.synced_at',
                [$v['id'], $p['id'], $v['sku'] ?? null, $v['title'],
                 (int) round(((float) $v['price']) * 100), $v['grams'] ?? null,
                 empty($v['available']) ? 0 : 1, $amount, $unit, deposit_for((string) $v['title']), $syncedAt]);
            $count++;
        }
    }
    return $count;
}

function fetch_json(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'HolyFrijolesBuyingGroup/1.0 (brent@schefley.net)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        throw new RuntimeException("Could not load $url (HTTP $code)");
    }
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['products'])) {
        throw new RuntimeException("Unexpected response from $url");
    }
    return $data['products'];
}

/**
 * Full refresh. Reads the catalog page by page, then switches off any variant
 * that is no longer listed. Nothing is switched off unless every page loaded.
 */
function sync_catalog(?string $fixtureFile = null): array
{
    $syncedAt = now_utc();
    $variants = 0;
    $pages = 0;
    db()->beginTransaction();
    try {
        if ($fixtureFile !== null) {
            $data = json_decode((string) file_get_contents($fixtureFile), true);
            $variants = save_products($data['products'] ?? [], $syncedAt);
            $pages = 1;
        } else {
            for ($page = 1; $page <= 200; $page++) {
                $products = fetch_json(config('catalog_url') . '?limit=250&page=' . $page);
                if (count($products) === 0) {
                    break;
                }
                $variants += save_products($products, $syncedAt);
                $pages++;
                usleep(300000); // be polite to their server
            }
        }
        if ($variants === 0) {
            throw new RuntimeException('The catalog came back empty, so nothing was changed.');
        }
        $retired = q('UPDATE variants SET active = 0 WHERE active = 1 AND (synced_at IS NULL OR synced_at < ?)',
            [$syncedAt])->rowCount();
        db()->commit();
    } catch (Throwable $err) {
        db()->rollBack();
        throw $err;
    }
    q("INSERT INTO settings (key, value) VALUES ('catalog_synced_at', ?)
       ON CONFLICT(key) DO UPDATE SET value = excluded.value", [$syncedAt]);
    return ['pages' => $pages, 'variants' => $variants, 'retired' => $retired];
}

/** Member price for a variant: list price less the group discount, rounded to the cent. */
function member_price_cents(int $listPriceCents): int
{
    $discount = (float) setting('discount_percent', '0');
    return (int) round($listPriceCents * (1 - $discount / 100));
}
