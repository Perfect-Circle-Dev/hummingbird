<?php
// Adds a member from the command line (the admin page will do this too).
//   php bin/add-member.php "Brent" brent@schefley.net "Schefley" --admin
// The third value is the household name. Use the same household name for a second
// person in the same home and they will share one order.
require dirname(__DIR__) . '/src/bootstrap.php';

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--admin'));
$isAdmin = in_array('--admin', $argv, true) ? 1 : 0;
if (count($args) < 3 || !filter_var($args[1], FILTER_VALIDATE_EMAIL)) {
    exit("Usage: php bin/add-member.php \"Name\" email@example.com \"Household name\" [--admin]\n");
}
[$name, $email, $household] = $args;

$householdId = q('SELECT id FROM households WHERE name = ? COLLATE NOCASE', [$household])->fetchColumn();
if ($householdId === false) {
    q('INSERT INTO households (name) VALUES (?)', [$household]);
    $householdId = db()->lastInsertId();
}
try {
    q('INSERT INTO members (household_id, name, email, is_admin) VALUES (?, ?, ?, ?)',
        [$householdId, $name, $email, $isAdmin]);
} catch (PDOException $err) {
    exit("Could not add $email. Is that address already a member?\n");
}
echo "Added $name <$email> to household \"$household\"" . ($isAdmin ? " as an organizer" : "") . ".\n";
