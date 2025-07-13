<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Start the session only if one isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'expense_tracker';


$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    // Log the detailed error to a file (not visible to users)
    error_log("Database connection failed: " . $conn->connect_error);
    // Show a generic message to the user
    die("Sorry, we are experiencing technical difficulties. Please try again later.");
}
?>