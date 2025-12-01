<?php
session_start();
require 'config.php'; 

if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php?form=login");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user data
$stmt = $conn->prepare("SELECT username, email, first_name, last_name FROM users WHERE id = ?");

if (!$stmt) {
    error_log("Export data (user data) prepare failed: " . $conn->error);
    die("An internal server error occurred while preparing user data export.");
}

$stmt->bind_param("i", $user_id);

if (!$stmt->execute()) {
    error_log("Export data (user data) execute failed: " . $stmt->error);
    die("An internal server error occurred while fetching user data for export.");
}

$user_data = $stmt->get_result()->fetch_assoc();

// Check if user data was found before trying to access it
if (!$user_data) {
    error_log("No user data found for user ID: " . $user_id);
    die("User data not found for export.");
}
$stmt->close(); 

// Fetch expenses
$stmt = $conn->prepare("SELECT amount, category, date, description FROM expenses WHERE user_id = ?");

if (!$stmt) {
    error_log("Export data (expenses) prepare failed: " . $conn->error);
    die("An internal server error occurred while preparing expenses export.");
}

$stmt->bind_param("i", $user_id);

if (!$stmt->execute()) {
    error_log("Export data (expenses) execute failed: " . $stmt->error);
    die("An internal server error occurred while fetching expenses for export.");
}

$expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close(); 
$conn->close();

// Generate CSV headers
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="user_data_' . $user_id . '.csv"');
// Prevent caching
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Expires: 0');
header('Pragma: public');

$output = fopen('php://output', 'w');

// User Data Section
fputcsv($output, ['User Data']);
fputcsv($output, ['Username', 'Email', 'First Name', 'Last Name']);
fputcsv($output, [$user_data['username'], $user_data['email'], $user_data['first_name'], $user_data['last_name']]);
fputcsv($output, []); 

// Expenses Section
fputcsv($output, ['Expenses']);
fputcsv($output, ['Amount', 'Category', 'Date', 'Description']);
foreach ($expenses as $expense) {
    fputcsv($output, [$expense['amount'], $expense['category'], $expense['date'], $expense['description']]);
}
fclose($output);
exit;
?>