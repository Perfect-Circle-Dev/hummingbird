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

/**
 * Container deposit for one size of one product, read from how Hummingbird words it.
 * Deposits go by what the container is made of:
 *   glass, $1.50 each:   half-gallon and one-gallon glass jugs, 64 oz jars, 5.5 lb honey jars
 *   plastic, $3.00 each: jugs, cubes, buckets and pails
 * A case is charged for every container in it: "Case of 6 x 1/2 gal Glass Jugs" is 6 deposits.
 * No deposit on smaller jars and bottles, barrels, totes or bag-in-a-box, nor on empty
 * jugs, jars, buckets and lids sold as products (there you are buying the container).
 * $size is the size text ("1/2 gal Glass Jug"); $product is the product's name.
 */
function deposit_for(string $size, string $product = ''): int
{
    // Empty containers and lids sold as products in their own right.
    if (preg_match('/\b(lids?|empty)\b/i', $size) || preg_match('/\bglass (jugs|jars)\b|\blids\b|^bucket\b/i', $product)) {
        return 0;
    }
    // "Case of 6 x ..." or "Case 6 x ..." holds that many containers.
    $count = preg_match('/\bcase\s+(?:of\s+)?(\d+)\s*x\b/i', $size, $m) ? (int) $m[1] : 1;

    // Glass: the size has to be one of the returnable ones (half gallon, one gallon,
    // 64 oz or the 5.5 lb honey jar) and the container has to be glass or a jar.
    // The patterns take care not to read "2.5 gal" as half a gallon or "11 gal" as one.
    $returnableGlassSize = '/(?:1\/2|½|half|(?<!\d)0?\.5)[\s-]*gal|(?<![\d.\/])1[\s-]*gal|\b64\s*(?:fl\.?\s*)?oz|\b5\.5\s*lb/iu';
    if (preg_match($returnableGlassSize, $size) && preg_match('/\bglass\b|\bjars?\b/i', $size)) {
        return $count * (int) setting('deposit_glass_cents', '150');
    }
    // Plastic: any jug, cube, bucket or pail that was not glass above.
    if (preg_match('/\b(jug|cube|bucket|pail)s?\b/i', $size)) {
        return $count * (int) setting('deposit_plastic_cents', '300');
    }
    return 0;
}

/** Works out every size's deposit again. Run after the deposit amounts or rules change. */
function recompute_deposits(): void
{
    db()->beginTransaction(); // one write to disk instead of one per size
    $variants = q('SELECT v.id, v.title, p.title AS product_title FROM variants v JOIN products p ON p.id = v.product_id')->fetchAll();
    foreach ($variants as $v) {
        q('UPDATE variants SET deposit_cents = ? WHERE id = ?',
            [deposit_for((string) $v['title'], (string) $v['product_title']), $v['id']]);
    }
    db()->commit();
}

/** Saves one page of products. Returns how many variants it saw. */
// What separates the paragraphs of a stored description: one blank line.
const PARAGRAPH_BREAK = "\n\n";

/**
 * Hummingbird's description of a product arrives as HTML. This turns it into plain text, one
 * paragraph to a line with a blank line between, and nothing else: no tags, no styling, no
 * scripts. Plain text is all the site ever shows, so nothing in a description can alter a page.
 */
function plain_description(?string $html): string
{
    $text = preg_replace('#<(script|style)\b.*?</\1>#is', '', (string) $html);
    // The ends of paragraphs, list items and the like become line breaks before the tags go.
    $text = preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|ul|ol|tr|blockquote)>#i', "\n", (string) $text);
    $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\u{00A0}", ' ', $text); // non-breaking spaces
    $lines = [];
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim((string) preg_replace('/\s+/u', ' ', $line));
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    return implode(PARAGRAPH_BREAK, $lines);
}

function save_products(array $products, string $syncedAt): int
{
    $count = 0;
    foreach ($products as $p) {
        $tags = is_array($p['tags'] ?? null) ? implode(', ', $p['tags']) : (string) ($p['tags'] ?? '');
        q('INSERT INTO products (id, title, handle, product_type, tags, description) VALUES (?, ?, ?, ?, ?, ?)
           ON CONFLICT(id) DO UPDATE SET title = excluded.title, handle = excluded.handle,
               product_type = excluded.product_type, tags = excluded.tags, description = excluded.description',
            [$p['id'], $p['title'], $p['handle'] ?? null, $p['product_type'] ?? null, $tags,
             plain_description($p['body_html'] ?? '')]);

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
                 empty($v['available']) ? 0 : 1, $amount, $unit,
                 deposit_for((string) $v['title'], (string) $p['title']), $syncedAt]);
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
// ---- Hummingbird's price sheet: where each item comes from, and whether it is organic or raw ----
// The product feed does not say these things. Hummingbird's wholesale price list does: a Google
// spreadsheet with a row for each item number. It is read after each catalog refresh.
// config.php may give another address as 'price_sheet_url'.
const PRICE_SHEET_URL = 'https://docs.google.com/spreadsheets/d/1O3edfScStDKfCxTcuMod_NnKKrsvd9XS6CAfaBy-tH0/export?format=csv';
// The sheet abbreviates. These are written out in full for members; anything not listed
// here ("Italy", "S. America") is shown as the sheet has it.
const ORIGIN_NAMES = [
    'OR' => 'Oregon', 'WA' => 'Washington', 'CA' => 'California', 'ID' => 'Idaho', 'HI' => 'Hawaii',
    'VT' => 'Vermont', 'VA' => 'Virginia', 'CO' => 'Colorado', 'AZ' => 'Arizona', 'MT' => 'Montana',
    'CAN' => 'Canada',
];

/** "OR" -> "Oregon", "USA/CAN" -> "USA/Canada"; a name already written out is left alone. */
function origin_name(string $origin): string
{
    $parts = preg_split('#\s*([/,])\s*#', trim($origin), -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as &$part) {
        $part = ORIGIN_NAMES[strtoupper($part)] ?? $part;
        if ($part === ',') {
            $part = ', ';
        }
    }
    return implode('', $parts);
}

/**
 * Reads the price sheet and saves, for each item number in the catalog, its origin and whether
 * it is organic and raw (variants.origin, organic, raw). Returns how many sizes were matched.
 * It is careful, because the sheet is kept by hand and may change shape or be out of reach:
 * unless the heading row is found and a good number of rows match the catalog, nothing is
 * changed and the site keeps what it had. An item missing from the sheet also keeps what it had.
 * $file reads a saved copy of the sheet, for testing.
 */
function sync_price_sheet(?string $file = null): int
{
    if ($file !== null) {
        $csv = (string) file_get_contents($file);
    } else {
        $ch = curl_init((string) (config('price_sheet_url') ?: PRICE_SHEET_URL));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'HolyFrijolesBuyingGroup/1.0 (brent@schefley.net)',
        ]);
        $csv = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($csv === false || $code !== 200) {
            throw new RuntimeException("Could not load the price sheet (HTTP $code)");
        }
    }

    // Find the heading row, then read each row under it by its headings.
    $columns = null;
    $facts = [];
    foreach (preg_split('/\R/', (string) $csv) as $line) {
        $cells = array_map(fn($cell): string => trim((string) $cell), str_getcsv($line, ',', '"', ''));
        if ($columns === null) {
            if (in_array('Item No.', $cells, true) && in_array('Origin', $cells, true)) {
                $columns = array_flip(array_filter($cells, fn(string $c): bool => $c !== ''));
            }
            continue;
        }
        $sku = strtoupper($cells[$columns['Item No.']] ?? '');
        if ($sku === '') {
            continue;
        }
        $facts[$sku] = [
            'origin'  => origin_name($cells[$columns['Origin']] ?? ''),
            // "OG" is organic. The sheet also has "OGI" on a couple of rows, which is left unmarked.
            'organic' => isset($columns['OG']) && strtoupper($cells[$columns['OG']] ?? '') === 'OG' ? 1 : 0,
            // "Raw" and "Raw*" both count as raw.
            'raw'     => isset($columns['Raw']) && stripos($cells[$columns['Raw']] ?? '', 'raw') === 0 ? 1 : 0,
        ];
    }
    if ($columns === null) {
        throw new RuntimeException('The price sheet has no "Item No." and "Origin" headings any more, so nothing was changed.');
    }

    $matches = [];
    foreach (q('SELECT id, sku FROM variants WHERE active = 1')->fetchAll() as $v) {
        $sku = strtoupper(trim((string) $v['sku']));
        if (isset($facts[$sku])) {
            $matches[(int) $v['id']] = $facts[$sku];
        }
    }
    if (count($matches) < 50) {
        throw new RuntimeException('Only ' . count($matches) . ' rows of the price sheet match the catalog, so nothing was changed.');
    }
    db()->beginTransaction();
    foreach ($matches as $id => $f) {
        q('UPDATE variants SET origin = ?, organic = ?, raw = ? WHERE id = ?', [$f['origin'] ?: null, $f['organic'], $f['raw'], $id]);
    }
    db()->commit();
    set_setting('price_sheet_synced_at', now_utc());
    return count($matches);
}

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
