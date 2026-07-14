<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$notification_id = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);

if (!$notification_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid notification id']);
    exit;
}

$stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
$stmt->bind_param('ii', $notification_id, $user_id);
$stmt->execute();

header('Content-Type: application/json');
echo json_encode(['success' => true]);
