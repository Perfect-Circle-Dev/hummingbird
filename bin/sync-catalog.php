<?php
// Refreshes the catalog from Hummingbird. Run nightly from cron.
//   php bin/sync-catalog.php
//   php bin/sync-catalog.php --file=tests/catalog-sample.json   (for testing without the network)
//   php bin/sync-catalog.php --file=... --sheet=saved-sheet.csv  (the same, with a saved price sheet)
// After the catalog it reads Hummingbird's price sheet, for each item's origin and whether it
// is organic or raw. A problem with the sheet is reported but does not undo the catalog refresh.
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/catalog.php';
require dirname(__DIR__) . '/src/orders.php';
require dirname(__DIR__) . '/src/shares.php';

$file = null;
$sheet = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $file = substr($arg, 7);
    }
    if (str_starts_with($arg, '--sheet=')) {
        $sheet = substr($arg, 8);
    }
}
try {
    $r = sync_catalog($file);
    echo "Catalog synced: {$r['variants']} sizes from {$r['pages']} page(s); {$r['retired']} no longer listed.\n";
    $repriced = refresh_open_order_prices();
    $expired = expire_shares();
    echo "Open orders repriced: $repriced line(s). Shares expired: $expired.\n";
} catch (Throwable $err) {
    fwrite(STDERR, "Catalog sync failed: " . $err->getMessage() . "\n");
    exit(1);
}

// A test run from a saved catalog stays off the network: it reads a price sheet only if given one.
if ($file === null || $sheet !== null) {
    try {
        echo "Price sheet read: origin, organic and raw saved for " . sync_price_sheet($sheet) . " sizes.\n";
    } catch (Throwable $err) {
        fwrite(STDERR, "Price sheet not read: " . $err->getMessage() . "\n");
    }
}
