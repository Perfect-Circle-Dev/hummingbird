<?php
// Refreshes the catalog from Hummingbird. Run nightly from cron.
//   php bin/sync-catalog.php
//   php bin/sync-catalog.php --file=tests/catalog-sample.json   (for testing without the network)
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/catalog.php';

$file = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $file = substr($arg, 7);
    }
}
try {
    $r = sync_catalog($file);
    echo "Catalog synced: {$r['variants']} sizes from {$r['pages']} page(s); {$r['retired']} no longer listed.\n";
} catch (Throwable $err) {
    fwrite(STDERR, "Catalog sync failed: " . $err->getMessage() . "\n");
    exit(1);
}
