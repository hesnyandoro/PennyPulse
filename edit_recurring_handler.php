<?php
require 'config.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$recurring_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$instance_date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_STRING);

if (!$recurring_id || !$instance_date) {
    header('Location: view_expenses.php?error=invalid_parameters');
    exit;
}

// Fetch the original recurring expense rule
$stmt = $conn->prepare("SELECT * FROM recurring_expenses WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $recurring_id, $user_id);
$stmt->execute();
$rule = $stmt->get_result()->fetch_assoc();

if (!$rule) {
    header('Location: view_expenses.php?error=not_found');
    exit;
}

// Create a new one-time expense to act as an override
$insert_stmt = $conn->prepare(
    "INSERT INTO expenses (user_id, recurring_expense_id, is_override, category_id, amount, description, payment_method, merchant, date)
     VALUES (?, ?, TRUE, ?, ?, ?, ?, ?, ?)"
);

$insert_stmt->bind_param(
    "iiisdsss",
    $user_id,
    $recurring_id,
    $rule['category_id'],
    $rule['amount'],
    $rule['description'],
    $rule['payment_method'],
    $rule['merchant'],
    $instance_date
);

$insert_stmt->execute();
$new_expense_id = $insert_stmt->insert_id;

// Redirect to the standard edit page for the new override expense
header("Location: edit_expense.php?id=" . $new_expense_id);
exit;