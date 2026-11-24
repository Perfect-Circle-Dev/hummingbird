<?php
// Creates or updates the database tables. Safe to run again at any time.
//   php bin/migrate.php
require dirname(__DIR__) . '/src/bootstrap.php';
db()->exec(file_get_contents(dirname(__DIR__) . '/schema.sql'));
echo "Database is ready at " . config('db_path') . "\n";
