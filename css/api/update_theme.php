<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$theme = $input['theme'] ?? 'light';
$user_id = $_SESSION['user_id'];

$query = "INSERT INTO user_settings (user_id, theme) VALUES (?, ?) 
          ON DUPLICATE KEY UPDATE theme = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('iss', $user_id, $theme, $theme);
$stmt->execute();

echo json_encode(['success' => true]);
?>