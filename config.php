<?php
// Must be first — before ANY output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load .env file if it exists
$env_path = __DIR__ . '/.env';
if (file_exists($env_path)) {  // ← removed the ! that was here
    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue; // skip comments
        if (strpos($line, '=') === false) continue;      // skip invalid lines
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if (!getenv($key)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

// API key for receipt scanning
define('ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: '');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database connection
$db_host = getenv('DB_HOST') ?:'host.docker.internal';
$db_user = getenv('DB_USER') ?:'expense_user';
$db_pass = getenv('DB_PASSWORD') ?:'@40268863bN845#';
$db_name = getenv('DB_NAME') ?:'expense_tracker';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Sorry, we are experiencing technical difficulties. Please try again later.");
}
?>