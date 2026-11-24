<?php
// Copy this file to config.php on each machine and fill it in.
// config.php is ignored by git, so passwords and keys never reach GitHub.
return [
    'site_name'   => 'Holy Frijoles',
    'site_tagline'=> 'Hummingbird Wholesale buying group',
    'base_url'    => 'https://hummingbird.schefley.net',   // no trailing slash
    'db_path'     => __DIR__ . '/data/hummingbird.sqlite',
    'timezone'    => 'America/Los_Angeles',

    // Email. 'log' writes emails to the email_log table only (for testing).
    // 'smtp' sends through Resend's SMTP service (settings below) and also logs.
    'mail_driver' => 'log',
    'mail_from'   => 'brent@schefley.net',
    'mail_from_name' => 'Holy Frijoles',
    'admin_email' => 'brent@schefley.net',   // gets notices about the server: failed updates, pending restarts
    'smtp' => ['host' => 'smtp.resend.com', 'port' => 587, 'user' => 'resend', 'pass' => ''], // pass = Resend API key

    // Where the catalog comes from.
    'catalog_url' => 'https://hummingbirdwholesale.com/products.json',

    // How long a sign-in link works, and how long a device stays signed in.
    'login_link_minutes' => 30,
    'session_days'       => 180,
];
