<?php
require 'config.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$recurring_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$exception_date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_STRING);

if (!$recurring_id || !$exception_date) {
    header('Location: view_expenses.php?error=invalid_parameters');
    exit;
}

// Verify the recurring expense belongs to the user
$stmt = $conn->prepare("SELECT id FROM recurring_expenses WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $recurring_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // Add an exception for this recurring expense on the given date
    $insert_stmt = $conn->prepare(
        "INSERT INTO recurring_expense_exceptions (user_id, recurring_expense_id, exception_date) VALUES (?, ?, ?)"
    );
    $insert_stmt->bind_param("iis", $user_id, $recurring_id, $exception_date);
    $insert_stmt->execute();
}

header('Location: view_expenses.php');
exit;