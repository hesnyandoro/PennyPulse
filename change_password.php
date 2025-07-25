<?php
session_start(); 
require 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}


if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'error' => 'Invalid request token.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}
// Ensure the user is logged in
$user_id = $_SESSION['user_id'];
$currentPassword = $_POST['current_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

$errors = [];
if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
    $errors[] = 'All password fields are required.';
}
if ($newPassword !== $confirmPassword) {
    $errors[] = 'New password and confirm password do not match.';
}
// Validate new password strength
if (strlen($newPassword) < 8) {
    $errors[] = 'New password must be at least 8 characters long.';
}
if (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) ||
    !preg_match('/[0-9]/', $newPassword) || !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
    $errors[] = 'Password must include uppercase, lowercase, number, and special character.';
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'error' => implode(" ", $errors)]);
    exit;
}

// Fetch current hashed password from DB
$stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
if (!$stmt) {
    error_log("Password fetch prepare failed: " . $conn->error);
    echo json_encode(['success' => false, 'error' => 'An internal server error occurred.']);
    exit;
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user || !password_verify($currentPassword, $user['password'])) {
    echo json_encode(['success' => false, 'error' => 'Incorrect current password.']);
    exit;
}

// Hash the new password
$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

// Update password in DB
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
if (!$stmt) {
    error_log("Password update prepare failed: " . $conn->error);
    echo json_encode(['success' => false, 'error' => 'An internal server error occurred.']);
    exit;
}
$stmt->bind_param("si", $hashedPassword, $user_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Password changed successfully!']);
} else {
    error_log("Password update execute failed: " . $stmt->error);
    echo json_encode(['success' => false, 'error' => 'Failed to change password.']);
}

$stmt->close();
$conn->close();
?>