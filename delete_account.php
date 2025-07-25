<?php
session_start();
require 'config.php'; // Ensure your database connection is established here

header('Content-Type: application/json');

// 1. Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication required. Please log in.']);
    exit;
}

// 2. CSRF Token Validation
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'error' => 'Invalid request token. Please refresh the page and try again.']);
    exit;
}

// Ensure it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Start a transaction for safe deletion
$conn->begin_transaction();

try {
    // IMPORTANT: Delete child records FIRST.
    // Order matters here: recurring_expenses -> expenses -> user_settings -> users

    // Delete user's recurring expenses
    $stmt = $conn->prepare("DELETE FROM recurring_expenses WHERE user_id = ?");
    if (!$stmt) {
        throw new Exception("Prepare failed for recurring expenses deletion: " . $conn->error);
    }
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed for recurring expenses deletion: " . $stmt->error);
    }
    $stmt->close();

    // Delete user's general expenses
    $stmt = $conn->prepare("DELETE FROM expenses WHERE user_id = ?");
    if (!$stmt) {
        throw new Exception("Prepare failed for expenses deletion: " . $conn->error);
    }
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed for expenses deletion: " . $stmt->error);
    }
    $stmt->close();

    // Delete user's settings
    $stmt = $conn->prepare("DELETE FROM user_settings WHERE user_id = ?");
    if (!$stmt) {
        throw new Exception("Prepare failed for settings deletion: " . $conn->error);
    }
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed for settings deletion: " . $stmt->error);
    }
    $stmt->close();

    // Finally, delete the user account from the 'users' table (the parent)
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Prepare failed for user deletion: " . $conn->error);
    }
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed for user deletion: " . $stmt->error);
    }
    $stmt->close();

    $conn->commit(); // Commit transaction if all successful
    session_destroy(); // Destroy user session after account deletion

    echo json_encode(['success' => true, 'message' => 'Account deleted successfully.']);

} catch (Exception $e) {
    $conn->rollback(); // Rollback on error
    error_log("Account deletion failed for user_id $user_id: " . $e->getMessage()); // Log the specific exception message
    echo json_encode(['success' => false, 'error' => 'Failed to delete account. An error occurred: ' . $e->getMessage()]); // Provide more specific error to user (for dev, might generalize for prod)
} finally {
    $conn->close();
}
?>