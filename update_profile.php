<?php
session_start(); 
require 'config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication required. Please log in.']);
    exit;
}

// CSRF Token Validation
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'error' => 'Invalid request token. Please refresh the page and try again.']);
    exit;
}

// Ensure it's a POST request (though AJAX usually implies this)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Corrected: Use null coalescing operator for robustness and correct array keys
$firstName = trim($_POST['first_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');

// Server-side validation
$errors = [];
if (empty($firstName)) {
    $errors[] = 'First name is required.';
}
if (empty($lastName)) {
    $errors[] = 'Last name is required.';
}
if (empty($email)) {
    $errors[] = 'Email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email address.';
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// Database update
$stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE id = ?");

if (!$stmt) {
    error_log("Profile update prepare failed: " . $conn->error);
    echo json_encode(['success' => false, 'error' => 'An internal server error occurred during update preparation.']);
    exit;
}

$stmt->bind_param("sssi", $firstName, $lastName, $email, $user_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
} else {
    error_log("Profile update execute failed: " . $stmt->error);
    echo json_encode(['success' => false, 'error' => 'Failed to update profile. Please try again.']);
}

$stmt->close();
$conn->close();
?>