<?php
require_once 'config.php';
require_once 'includes/theme_handler.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php?form=login');
    exit;
}
//require_once 'process_recurring.php';
$user_id = $_SESSION['user_id'];

// Fetch user data
$query = "SELECT username FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Fetch user settings
$settings = getUserTheme($conn, $user_id);

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

// Fetch total expenses for the week
// Calculate the start and end of the current week
// ISO-8601 numeric representation of the day of the week (1 for Monday through 7 for Sunday)
$day_of_week = date('N'); 
$start_of_week = date('Y-m-d', strtotime('-' . ($day_of_week - 1) . ' days'));
$end_of_week = date('Y-m-d', strtotime('+' . (7 - $day_of_week) . ' days'));

// Fetch total expenses for the week using the calculated date range
$total_query = "SELECT SUM(amount) as total FROM expenses WHERE user_id = ? AND date BETWEEN ? AND ?";
$total_stmt = $conn->prepare($total_query);
$total_stmt->bind_param('iss', $user_id, $start_of_week, $end_of_week);
$total_stmt->execute();
$total = $total_stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Fetch budgets and spending for the current month
$current_month = date('Y-m-01');
$month_like = date('Y-m-') . '%'; 
$budget_query = "SELECT b.category_id, b.budget_amount, c.name as category_name, 
                        COALESCE(SUM(e.amount), 0) as spent 
                 FROM budgets b 
                 JOIN categories c ON b.category_id = c.id 
                 LEFT JOIN expenses e ON e.category_id = b.category_id 
                     AND e.user_id = b.user_id 
                     AND e.date LIKE ? 
                 WHERE b.user_id = ? AND b.month = ? 
                 GROUP BY b.category_id, b.budget_amount, c.name";
$budget_stmt = $conn->prepare($budget_query);
$budget_stmt->bind_param('sis', $month_like, $user_id, $current_month); 
$budget_stmt->execute();
$budgets = $budget_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate total budget and remaining
$total_budget = array_sum(array_column($budgets, 'budget_amount'));
$total_spent = array_sum(array_column($budgets, 'spent'));
$remaining = $total_budget - $total_spent;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Expense Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/styles.css?v=<?php echo filemtime('css/styles.css'); ?>">
</head>
<body class="<?php echo htmlspecialchars($settings['theme']); ?>">
    <div class="app">
        <!-- Navbar -->
        <nav class="navbar">
            <div class="logo">Expense Tracker</div>
            <ul class="nav-links">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="add_expense.php"><i class="fas fa-plus"></i> Manage Expenses</a></li>
                <li><a href="view_expenses.php"><i class="fas fa-list"></i> View Expenses</a></li>
                <li><a href="set_budget.php"><i class="fas fa-wallet"></i> Budgets</a></li>
                <li><a href="reports.php"><i class="fas fa-chart-pie"></i> Reports</a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            </ul>
            <div class="user-profile">
                <span class="avatar"><?php echo htmlspecialchars(strtoupper($user['username'][0])); ?></span>
                <span class="username"><?php echo htmlspecialchars($user['username']); ?></span>
                <button id="theme-toggle" class="theme-toggle">
                    <i class="fas <?php echo $settings['theme'] === 'light' ? 'fa-sun' : 'fa-moon'; ?>"></i>
                </button>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </nav>

        <!-- Dashboard -->
        <div class="dashboard">
            <div class="welcome-section">
                <h1>Hello <?php echo htmlspecialchars($user['username']); ?>!</h1>
                <p>Your spending is on track this week.</p>
            </div>
            <div class="stats">
                <div class="stat-card">
                    <h3>Total Spent This Week</h3>
                    <p>KES&nbsp;<?php echo number_format($total, 2); ?></p>
                </div>
                <div class="stat-card">
                    <h3>Budget Remaining</h3>
                    <p>KES&nbsp;<?php echo number_format($remaining, 2); ?></p>
                </div>
            </div>
            <div class="budget-overview">
                <h2>Budget Overview (<?php echo date('F Y'); ?>)</h2>
                <?php if (!empty($budgets)) { ?>
                    <ul>
                        <?php foreach ($budgets as $budget) {
                            $percentage = $budget['budget_amount'] > 0 ? ($budget['spent'] / $budget['budget_amount']) * 100 : 0;
                            $status_class = $percentage >= 90 ? 'danger' : ($percentage >= 75 ? 'warning' : '');
                        ?>
                            <li class="<?php echo $status_class; ?>">
                                <div>
                                    <strong><?php echo htmlspecialchars($budget['category_name']); ?></strong>
                                    <div class="progress-bar">
                                        <div class="progress" style="width: <?php echo min($percentage, 100); ?>%;"></div>
                                    </div>
                                    <div class="status">
                                        KES&nbsp;<?php echo number_format($budget['spent'], 2); ?> / KES&nbsp;<?php echo number_format($budget['budget_amount'], 2); ?>
                                        (<?php echo round($percentage, 1); ?>%)
                                    </div>
                                </div>
                            </li>
                        <?php } ?>
                    </ul>
                <?php } else { ?>
                    <p>No budgets set for this month. <a href="set_budget.php">Set a budget</a> now.</p>
                <?php } ?>
            </div>
            <div class="expenses-table">
                <h2>Recent Expenses</h2>
                <?php if (!empty($expenses)) { ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenses as $expense) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($expense['date']); ?></td>
                                    <td><?php echo htmlspecialchars($expense['category']); ?></td>
                                    <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                    <td>KES&nbsp;<?php echo number_format($expense['amount'], 2); ?></td>
                                    <td><a href="add_expense.php?id=<?php echo $expense['id']; ?>" class="btn">Edit</a></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <p>No recent expenses found. <a href="add_expense.php">Add an expense</a> to get started.</p>
                <?php } ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php if (!in_array(basename($_SERVER['PHP_SELF']), ['login.php', 'register.php', 'logout.php'])) {
        include 'footer.html';
    } ?>

    <script src="js/theme-toggle.js?v=<?php echo filemtime('js/theme-toggle.js'); ?>"></script>
</body>
</html>