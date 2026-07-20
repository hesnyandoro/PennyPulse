<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

function ensureNotificationTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        message VARCHAR(255) NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        type VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
}

ensureNotificationTable($conn);

$today = date('Y-m-d');
$start_of_week = date('Y-m-d', strtotime('monday this week'));
$end_of_week = date('Y-m-d', strtotime('sunday this week'));

$stmt = $conn->prepare("SELECT SUM(amount) AS total_spent FROM expenses WHERE user_id = ? AND date BETWEEN ? AND ?");
$stmt->bind_param('iss', $user_id, $start_of_week, $end_of_week);
$stmt->execute();
$weekly = $stmt->get_result()->fetch_assoc();
$weekly_spent = (float) ($weekly['total_spent'] ?? 0);

$budget_stmt = $conn->prepare("SELECT b.category_id, b.budget_amount, c.name AS category_name, COALESCE(SUM(e.amount), 0) AS spent
    FROM budgets b
    JOIN categories c ON b.category_id = c.id
    LEFT JOIN expenses e ON e.category_id = b.category_id AND e.user_id = b.user_id AND e.date LIKE ?
    WHERE b.user_id = ? AND b.month = ?
    GROUP BY b.category_id, b.budget_amount, c.name");
$month_like = date('Y-m-') . '%';
$current_month = date('Y-m-01');
$budget_stmt->bind_param('sis', $month_like, $user_id, $current_month);
$budget_stmt->execute();
$budgets = $budget_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$existing_messages = [];
$existing_stmt = $conn->prepare("SELECT message, type, created_at FROM notifications WHERE user_id = ?");
$existing_stmt->bind_param('i', $user_id);
$existing_stmt->execute();
$existing_result = $existing_stmt->get_result();
while ($row = $existing_result->fetch_assoc()) {
    $existing_messages[$row['type'] . '|' . $row['message']] = true;
}

function addNotification($conn, $user_id, $message, $type, &$existing_messages) {
    $key = $type . '|' . $message;
    if (isset($existing_messages[$key])) {
        return;
    }

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, type, created_at) VALUES (?, ?, 0, ?, NOW())");
    $stmt->bind_param('iss', $user_id, $message, $type);
    $stmt->execute();
    $existing_messages[$key] = true;
}

foreach ($budgets as $budget) {
    $budget_amount = (float) $budget['budget_amount'];
    if ($budget_amount <= 0) {
        continue;
    }

    $spent = (float) $budget['spent'];
    $percentage = ($spent / $budget_amount) * 100;
    if ($percentage >= 100) {
        addNotification($conn, $user_id, 'You have reached 100% of your ' . $budget['category_name'] . ' budget.', 'budget', $existing_messages);
    } elseif ($percentage >= 80) {
        addNotification($conn, $user_id, 'You have reached 80% of your ' . $budget['category_name'] . ' budget.', 'budget', $existing_messages);
    }
}

$recurring_stmt = $conn->prepare("SELECT id, description, next_due_date FROM recurring_expenses WHERE user_id = ? AND is_active = 1 AND next_due_date IS NOT NULL");
$recurring_stmt->bind_param('i', $user_id);
$recurring_stmt->execute();
$recurring = $recurring_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($recurring as $item) {
    if (!$item['next_due_date']) {
        continue;
    }

    $due = new DateTime($item['next_due_date']);
    $today_dt = new DateTime($today);
    $diff = $today_dt->diff($due)->days;

    if ($diff >= 0 && $diff <= 3) {
        addNotification($conn, $user_id, 'Recurring expense due soon: ' . ($item['description'] ?: 'Unnamed expense') . ' on ' . $item['next_due_date'] . '.', 'recurring', $existing_messages);
    }
}

if ($weekly_spent > 0) {
    $week_start = date('Y-m-d', strtotime('monday this week'));
    $weekly_exists_stmt = $conn->prepare("SELECT id FROM notifications WHERE user_id = ? AND type = 'weekly_summary' AND created_at >= ?");
    $weekly_exists_stmt->bind_param('is', $user_id, $week_start);
    $weekly_exists_stmt->execute();
    if ($weekly_exists_stmt->get_result()->num_rows === 0) {
        addNotification($conn, $user_id, 'You spent KES ' . number_format($weekly_spent, 2) . ' this week.', 'weekly_summary', $existing_messages);
    }
}

$notifications_stmt = $conn->prepare("SELECT id, message, type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$notifications_stmt->bind_param('i', $user_id);
$notifications_stmt->execute();
$notifications = $notifications_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$unread_count = 0;
foreach ($notifications as $notification) {
    if ((int) $notification['is_read'] === 0) {
        $unread_count++;
    }
}

header('Content-Type: application/json');
echo json_encode([
    'unread_count' => $unread_count,
    'notifications' => $notifications,
]);
