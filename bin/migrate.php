<?php
// Creates or updates the database tables. Safe to run again at any time.
//   php bin/migrate.php
require dirname(__DIR__) . '/src/bootstrap.php';
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

echo "Database is ready at " . config('db_path') . "\n";
