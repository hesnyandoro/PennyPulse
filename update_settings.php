<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $theme = $_POST['theme'];
    $language = $_POST['language'];
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $in_app_notifications = isset($_POST['in_app_notifications']) ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO user_settings (user_id, theme, language, email_notifications, in_app_notifications) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE theme = ?, language = ?, email_notifications = ?, in_app_notifications = ?");
    $stmt->bind_param("issiiisii", $user_id, $theme, $language, $email_notifications, $in_app_notifications, $theme, $language, $email_notifications, $in_app_notifications);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update settings']);
    }
    $stmt->close();
}
?>