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

$user_id = $_SESSION['user_id'];

// These inputs come from the "App Preferences" form in settings.php
$theme = $_POST['theme'] ?? 'light';
$language = $_POST['language'] ?? 'en';
$email_notifications = isset($_POST['email_notifications']) && $_POST['email_notifications'] == '1' ? 1 : 0;
$in_app_notifications = isset($_POST['in_app_notifications']) && $_POST['in_app_notifications'] == '1' ? 1 : 0;

// Validate inputs against allowed values
$allowedThemes = ['light', 'dark'];
$allowedLanguages = ['en', 'es', 'fr'];

if (!in_array($theme, $allowedThemes)) {
    echo json_encode(['success' => false, 'error' => 'Invalid theme selected.']);
    exit;
}
if (!in_array($language, $allowedLanguages)) {
    echo json_encode(['success' => false, 'error' => 'Invalid language selected.']);
    exit;
}
// Prepare the SQL statement to insert or update user settings
$stmt = $conn->prepare("INSERT INTO user_settings (user_id, theme, language, email_notifications, in_app_notifications) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE theme = ?, language = ?, email_notifications = ?, in_app_notifications = ?");

if (!$stmt) {
    error_log("Settings update/insert prepare failed: " . $conn->error);
    echo json_encode(['success' => false, 'error' => 'An internal server error occurred during settings update preparation.']);
    exit;
}

$stmt->bind_param("issiiisii", $user_id, $theme, $language, $email_notifications, $in_app_notifications, $theme, $language, $email_notifications, $in_app_notifications);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Preferences saved successfully!']);
} else {
    error_log("Settings execute failed: " . $stmt->error);
    echo json_encode(['success' => false, 'error' => 'Failed to save preferences.']);
}

$stmt->close();
$conn->close();
?>