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
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css?v=<?php echo filemtime('css/styles.css'); ?>">
    <style>
        .dashboard {
            max-width: 95%;
            margin: 0 auto;
            padding: 20px;
        }
        
        .welcome-section {
            background: white;
            padding: 32px;
            border-radius: 16px;
            margin-bottom: 32px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            border-left: 4px solid transparent;
            border-image: linear-gradient(135deg, #3b82f6, #8b5cf6) 1;
        }
        
        .welcome-section h1 {
            margin: 0 0 8px 0;
            font-size: 32px;
            font-weight: 700;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .welcome-section p {
            margin: 0;
            font-size: 16px;
            color: #6b7280;
            font-weight: 500;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
            border: 1px solid #f3f4f6;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }
        
        .stat-card .stat-content {
            flex: 1;
        }
        
        .stat-card h3 {
            margin: 0 0 8px 0;
            font-size: 14px;
            font-weight: 500;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-card p {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
        }
        
        .stat-card .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        
        .stat-card:nth-child(1) .stat-icon {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }
        
        .stat-card:nth-child(2) .stat-icon {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        
        .budget-overview {
            background: white;
            padding: 32px;
            border-radius: 16px;
            margin-bottom: 32px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        }
        
        .budget-overview h2 {
            margin: 0 0 24px 0;
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .budget-overview ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .budget-overview li {
            padding: 20px;
            margin-bottom: 16px;
            background: #f9fafb;
            border-radius: 12px;
            border-left: 4px solid #3b82f6;
            transition: all 0.2s ease;
        }
        
        .budget-overview li:hover {
            background: #f3f4f6;
            transform: translateX(4px);
        }
        
        .budget-overview li.warning {
            border-left-color: #f59e0b;
            background: #fffbeb;
        }
        
        .budget-overview li.danger {
            border-left-color: #ef4444;
            background: #fef2f2;
        }
        
        .budget-overview li strong {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            display: block;
            margin-bottom: 12px;
        }
        
        .progress-bar {
            width: 100%;
            height: 10px;
            background: #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 8px;
        }
        
        .progress {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .budget-overview li.warning .progress {
            background: linear-gradient(90deg, #f59e0b, #d97706);
        }
        
        .budget-overview li.danger .progress {
            background: linear-gradient(90deg, #ef4444, #dc2626);
        }
        
        .status {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .expenses-table {
            background: white;
            padding: 32px;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }
        
        .expenses-table h2 {
            margin: 0 0 24px 0;
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .expenses-table table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        
        .expenses-table thead {
            background: linear-gradient(135deg, #f9fafb, #f3f4f6);
        }
        
        .expenses-table th {
            padding: 14px 12px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .expenses-table tbody tr {
            border-bottom: 1px solid #f3f4f6;
            transition: all 0.2s ease;
        }
        
        .expenses-table tbody tr:hover {
            background: #f9fafb;
            transform: scale(1.01);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }
        
        .expenses-table td {
            padding: 16px 12px;
            font-size: 14px;
            color: #374151;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .expenses-table td:first-child {
            width: 12%;
            font-weight: 600;
        }
        
        .expenses-table td:nth-child(2) {
            width: 18%;
        }
        
        .expenses-table td:nth-child(3) {
            width: 40%;
        }
        
        .expenses-table td:nth-child(4) {
            width: 18%;
            font-weight: 600;
            color: #1f2937;
        }
        
        .expenses-table td:last-child {
            width: 12%;
        }
        
        .expenses-table .btn {
            padding: 6px 12px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .expenses-table .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }
        
        .category-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: #eff6ff;
            color: #3b82f6;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid #dbeafe;
        }
    </style>
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
                <h1>
                    <i class="fas fa-hand-wave" style="color: #f59e0b;"></i>
                    Hello <?php echo htmlspecialchars($user['username']); ?>!
                </h1>
                <p><?php 
                    $spending_percentage = $total_budget > 0 ? ($total_spent / $total_budget) * 100 : 0;
                    if ($spending_percentage < 50) {
                        echo "Great job! Your spending is well under control this week.";
                    } elseif ($spending_percentage < 75) {
                        echo "You're doing well! Keep monitoring your expenses.";
                    } elseif ($spending_percentage < 90) {
                        echo "Watch out! You're approaching your budget limit.";
                    } else {
                        echo "Alert! You've reached or exceeded your budget.";
                    }
                ?></p>
            </div>
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-content">
                        <h3>Total Spent This Week</h3>
                        <p>KES&nbsp;<?php echo number_format($total, 2); ?></p>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-content">
                        <h3>Budget Remaining</h3>
                        <p>KES&nbsp;<?php echo number_format($remaining, 2); ?></p>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-piggy-bank"></i>
                    </div>
                </div>
            </div>
            <div class="budget-overview">
                <h2>
                    <i class="fas fa-chart-bar" style="color: #3b82f6;"></i>
                    Budget Overview (<?php echo date('F Y'); ?>)
                </h2>
                <?php if (!empty($budgets)) { ?>
                    <ul>
                        <?php foreach ($budgets as $budget) {
                            $percentage = $budget['budget_amount'] > 0 ? ($budget['spent'] / $budget['budget_amount']) * 100 : 0;
                            $status_class = $percentage >= 90 ? 'danger' : ($percentage >= 75 ? 'warning' : '');
                        ?>
                            <li class="<?php echo $status_class; ?>">
                                <div>
                                    <strong>
                                        <i class="fas fa-tag" style="color: #3b82f6; font-size: 14px; margin-right: 6px;"></i>
                                        <?php echo htmlspecialchars($budget['category_name']); ?>
                                    </strong>
                                    <div class="progress-bar">
                                        <div class="progress" style="width: <?php echo min($percentage, 100); ?>%;"></div>
                                    </div>
                                    <div class="status">
                                        <span>KES&nbsp;<?php echo number_format($budget['spent'], 2); ?> / KES&nbsp;<?php echo number_format($budget['budget_amount'], 2); ?></span>
                                        <span style="<?php 
                                            if ($percentage >= 90) echo 'color: #ef4444; font-weight: 700;';
                                            elseif ($percentage >= 75) echo 'color: #f59e0b; font-weight: 700;';
                                            else echo 'color: #10b981; font-weight: 700;';
                                        ?>"><?php echo round($percentage, 1); ?>%</span>
                                    </div>
                                </div>
                            </li>
                        <?php } ?>
                    </ul>
                <?php } else { ?>
                    <p style="color: #6b7280; text-align: center; padding: 20px;">No budgets set for this month. <a href="set_budget.php" style="color: #3b82f6; font-weight: 600; text-decoration: none;">Set a budget</a> now.</p>
                <?php } ?>
            </div>
            <div class="expenses-table">
                <h2>
                    <i class="fas fa-history" style="color: #3b82f6;"></i>
                    Recent Expenses
                </h2>
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
                                    <td><?php echo date('M d, Y', strtotime($expense['date'])); ?></td>
                                    <td>
                                        <span class="category-badge">
                                            <i class="fas fa-folder"></i>
                                            <?php echo htmlspecialchars($expense['category']); ?>
                                        </span>
                                    </td>
                                    <td title="<?php echo htmlspecialchars($expense['description']); ?>"><?php echo htmlspecialchars($expense['description']); ?></td>
                                    <td>KES&nbsp;<?php echo number_format($expense['amount'], 2); ?></td>
                                    <td>
                                        <a href="add_expense.php?id=<?php echo $expense['id']; ?>" class="btn">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <p style="color: #6b7280; text-align: center; padding: 20px;">No recent expenses found. <a href="add_expense.php" style="color: #3b82f6; font-weight: 600; text-decoration: none;">Add an expense</a> to get started.</p>
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