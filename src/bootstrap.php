<?php
// Loaded by every page and script: settings, database connection, small helpers.
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    exit("Missing config.php. Copy config.sample.php to config.php and fill it in.\n");
}
$CONFIG = require $configFile;
date_default_timezone_set($CONFIG['timezone']);

function config(string $key)
{
    global $CONFIG;
    return $CONFIG[$key] ?? null;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . config('db_path'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
    }
    return $pdo;
}

/** Run a query with values and return the statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function setting(string $key, ?string $default = null): ?string
{
    $value = q('SELECT value FROM settings WHERE key = ?', [$key])->fetchColumn();
    return $value === false ? $default : (string) $value;
}

/** Escape text for HTML output. */
function e(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

/** 1234 -> "$12.34" */
function money(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

/** Current time as a UTC string, the format used throughout the database. */
function now_utc(int $offsetSeconds = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}
