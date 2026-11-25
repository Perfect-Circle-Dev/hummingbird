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
add_column_if_missing('members', 'pending_email', 'TEXT');
add_column_if_missing('members', 'pending_email_token', 'TEXT');
add_column_if_missing('members', 'pending_email_expires_at', 'TEXT');
add_column_if_missing('login_tokens', 'cycle_id', 'INTEGER');
add_column_if_missing('shares', 'expiry_warned_at', 'TEXT');
add_column_if_missing('products', 'description', 'TEXT');
add_column_if_missing('cycles', 'placed_at', 'TEXT');
add_column_if_missing('cycles', 'placed_note', 'TEXT');
add_column_if_missing('members', 'first_name', "TEXT NOT NULL DEFAULT ''");
add_column_if_missing('members', 'last_name', "TEXT NOT NULL DEFAULT ''");

// Members used to have one name. Anyone still without a first name has theirs split: the
// first word becomes the first name and the rest the last name. An organizer can put right
// any that split in the wrong place ("Mary Ann Smith") on the Members page.
$split = 0;
foreach (q("SELECT id, name FROM members WHERE first_name = ''")->fetchAll() as $m) {
    [$first, $last] = split_name($m['name']);
    q('UPDATE members SET first_name = ?, last_name = ? WHERE id = ?', [$first, $last, $m['id']]);
    $split++;
}
if ($split) {
    echo "Split $split member name(s) into first and last.\n";
}

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
