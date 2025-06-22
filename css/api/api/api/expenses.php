<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch recent expenses
$query = "SELECT e.id, e.amount, e.description, e.date, c.name as category 
          FROM expenses e 
          JOIN categories c ON e.category_id = c.id 
          WHERE e.user_id = ? 
          ORDER BY e.date DESC 
          LIMIT 10";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch total expenses
$query = "SELECT SUM(amount) as total FROM expenses WHERE user_id = ? AND YEAR(date) = YEAR(CURRENT_DATE) AND WEEK(date) = WEEK(CURRENT_DATE)";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

echo json_encode([
    'expenses' => $expenses,
    'totalSpent' => $total
]);
?>