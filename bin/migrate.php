<?php
// Creates or updates the database tables. Safe to run again at any time.
//   php bin/migrate.php
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/catalog.php';
require dirname(__DIR__) . '/src/orders.php';
db()->exec(file_get_contents(dirname(__DIR__) . '/schema.sql'));

// schema.sql only creates tables that are missing; it can't add a column to a table
// that already exists. Columns added since the site went live are listed here, and
// each is added only if the table doesn't have it yet.
function add_column_if_missing(string $table, string $column, string $definition): void
{
    $existing = array_column(db()->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    if (!in_array($column, $existing, true)) {
        db()->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        echo "Added $table.$column\n";
    }
}
add_column_if_missing('share_claims', 'cost_cents', 'INTEGER');
add_column_if_missing('cycles', 'pickup_emailed_at', 'TEXT');
add_column_if_missing('cycles', 'order_emailed_at', 'TEXT');
add_column_if_missing('cycles', 'last_call_at', 'TEXT');
add_column_if_missing('members', 'email_opt_out', 'INTEGER NOT NULL DEFAULT 0');
add_column_if_missing('members', 'unsubscribe_token', 'TEXT');
add_column_if_missing('login_tokens', 'cycle_id', 'INTEGER');
add_column_if_missing('shares', 'expiry_warned_at', 'TEXT');

// One-time changes to saved settings. Each leaves a mark so it happens only once,
// and later changes made on the Settings page are never overwritten.
$reprice = false;

// Deposits used to go by size (half gallon, or a gallon and larger). They now go by
// what the container is made of. The amounts already saved carry over to the new names.
if (setting('deposit_half_gallon_cents') !== null) {
    set_setting('deposit_glass_cents', (string) setting('deposit_half_gallon_cents'));
    set_setting('deposit_plastic_cents', (string) setting('deposit_gallon_plus_cents', '300'));
    q("DELETE FROM settings WHERE key IN ('deposit_half_gallon_cents', 'deposit_gallon_plus_cents')");
    echo "Deposits now go by container material (glass, plastic).\n";
    $reprice = true;
}

// The group discount was a stand-in of 30 until Brent worked out the real figure, 30.2163.
if (setting('discount_confirmed') === null) {
    if (setting('discount_percent') === '30') {
        set_setting('discount_percent', '30.2163');
        echo "Group discount set to 30.2163.\n";
        $reprice = true;
    }
    set_setting('discount_confirmed', '1');
}

if ($reprice) {
    // Work out every deposit again, then bring open orders and saved lists up to date.
    // Locked and closed months keep the prices they had.
    recompute_deposits();
    echo "Open orders repriced: " . refresh_open_order_prices() . " line(s).\n";
}

echo "Database is ready at " . config('db_path') . "\n";
