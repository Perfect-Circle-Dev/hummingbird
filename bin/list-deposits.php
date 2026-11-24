<?php
// Shows every size that carries a deposit, and every size that mentions a container
// but carries none, so the automatic deposit rule can be checked by eye.
//   php bin/list-deposits.php
require dirname(__DIR__) . '/src/bootstrap.php';

$rows = q("SELECT v.sku, p.title AS product, v.title AS size, v.deposit_cents, v.deposit_override_cents
           FROM variants v JOIN products p ON p.id = v.product_id
           WHERE v.active = 1 AND (v.deposit_cents > 0 OR v.deposit_override_cents IS NOT NULL
                 OR v.title LIKE '%gal%' OR v.title LIKE '%jug%' OR v.title LIKE '%bucket%' OR v.title LIKE '%pail%'
                 OR v.title LIKE '%cube%' OR v.title LIKE '%jar%' OR v.title LIKE '%glass%' OR v.title LIKE '%barrel%'
                 OR v.title LIKE '%box%' OR v.title LIKE '%tote%' OR v.title LIKE '%drum%')
           ORDER BY v.deposit_cents DESC, p.title")->fetchAll();
foreach ($rows as $r) {
    $deposit = $r['deposit_override_cents'] ?? $r['deposit_cents'];
    printf("%-8s %-12s %-45s %s\n", money((int) $deposit), $r['sku'], mb_strimwidth($r['product'], 0, 45, '…'), $r['size']);
}
echo count($rows) . " sizes listed.\n";
