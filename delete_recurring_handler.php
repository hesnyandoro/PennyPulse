<?php
require 'config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

$recurring_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$exception_date = filter_input(INPUT_GET, 'date', FILTER_DEFAULT);

if (!$recurring_id || !$exception_date) {
    header('Location: view_expenses.php?error=invalid_data');
    exit;
}

$d = DateTime::createFromFormat('Y-m-d', $exception_date);
if (!$d || $d->format('Y-m-d') !== $exception_date) {
    $d = DateTime::createFromFormat('Y-m-d', str_replace('-', '/', $exception_date));
    if (!$d || $d->format('Y-m-d') !== str_replace('-', '/', $exception_date)) {
        header('Location: view_expenses.php?error=invalid_date_format');
        exit;
    }
    $exception_date = $d->format('Y-m-d');
} else {
    $exception_date = $d->format('Y-m-d');
}

try {
    $conn->begin_transaction();

    $check_stmt = $conn->prepare(
        "SELECT COUNT(*) FROM recurring_expense_exceptions 
         WHERE recurring_expense_id = ? AND user_id = ? AND exception_date = ?"
    );
    $check_stmt->bind_param("iis", $recurring_id, $user_id, $exception_date);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result()->fetch_row();
    $exists = $check_result[0] > 0;
    error_log("Debug: Checking exception for recurring_id=$recurring_id, user_id=$user_id, date=$exception_date, exists=" . ($exists ? '1' : '0'));

    if (!$exists) {
        $stmt = $conn->prepare(
            "INSERT INTO recurring_expense_exceptions (recurring_expense_id, exception_date, user_id)
             SELECT ?, ?, ? FROM recurring_expenses
             WHERE id = ? AND user_id = ?"
        );
        $stmt->bind_param("isiii", $recurring_id, $exception_date, $user_id, $recurring_id, $user_id);
        $stmt->execute();
        error_log("Debug: Inserted exception for recurring_id=$recurring_id, date=$exception_date, affected_rows=" . $stmt->affected_rows);

        if ($stmt->affected_rows === 0) {
            throw new Exception("No recurring expense rule found to create an exception for.");
        }
        $status = 'success=instance_deleted';
    } else {
        error_log("Debug: Exception already exists for recurring_id=$recurring_id, date=$exception_date, skipping insert");
        $status = 'info=already_deleted';
    }

    $conn->commit();
    header('Location: view_expenses.php?' . $status);
    exit;

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log("Failed to create exception for recurring ID $recurring_id: " . $e->getMessage());
    header('Location: view_expenses.php?error=delete_failed');
    exit;
}