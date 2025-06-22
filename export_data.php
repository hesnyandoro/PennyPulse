<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user data
$stmt = $conn->prepare("SELECT username, email, firstName, lastName FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();

// Fetch expenses
$stmt = $conn->prepare("SELECT amount, category, date, description FROM expenses WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Generate CSV
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="user_data_' . $user_id . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['User Data']);
fputcsv($output, ['Username', 'Email', 'First Name', 'Last Name']);
fputcsv($output, [$user_data['username'], $user_data['email'], $user_data['firstName'], $user_data['lastName']]);
fputcsv($output, []); // Empty line
fputcsv($output, ['Expenses']);
fputcsv($output, ['Amount', 'Category', 'Date', 'Description']);
foreach ($expenses as $expense) {
    fputcsv($output, [$expense['amount'], $expense['category'], $expense['date'], $expense['description']]);
}
fclose($output);
exit;
?>