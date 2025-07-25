<?php
// Start output buffering
ob_start();
session_start();

require_once 'config.php';

// Check for user session
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Fetch user details
$query = "SELECT username FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    header('Location: login.php');
    exit;
}

// Fetch user settings (theme)
$query = "SELECT theme FROM user_settings WHERE user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$settings = $stmt->get_result()->fetch_assoc();
if (!$settings) {
    // Default settings if none exist
    $settings = ['theme' => 'light'];
}

// Handle one-time expense deletion
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $delete_id = (int)($_GET['delete'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $delete_id, $user_id);
    $stmt->execute();
    header('Location: view_expenses.php');
    exit;
}

// Default date range for the current month
$default_start = new DateTime('first day of this month');
$default_end = new DateTime('last day of this month');

// Parse and validate date filters
$view_start_date = !empty($_GET['date_start']) ? new DateTime($_GET['date_start']) : clone $default_start;
$view_end_date = !empty($_GET['date_end']) ? new DateTime($_GET['date_end']) : clone $default_end;
$filter_category = $_GET['category'] ?? '';
$filter_payment_method = $_GET['payment_method'] ?? '';
$filter_type = $_GET['type'] ?? 'All';
$sort = $_GET['sort'] ?? 'date';
$order = $_GET['order'] ?? 'DESC';

// Fetch one-time expenses
$all_expenses = [];
$generated_recurring = [];

// Step 1: Fetch exceptions to filter out
$exceptions_stmt = $conn->prepare("SELECT recurring_expense_id, exception_date FROM recurring_expense_exceptions WHERE user_id = ?");
$exceptions_stmt->bind_param('i', $user_id);
$exceptions_stmt->execute();
$exceptions = $exceptions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$exception_map = [];
foreach ($exceptions as $exception) {
    $exception_map[$exception['recurring_expense_id'] . '_' . $exception['exception_date']] = true;
}

// Step 2: Generate all potential recurring expense instances for the date range
if ($filter_type === 'All' || $filter_type === 'Recurring') {
    $rec_query = "SELECT r.*, c.name AS category_name 
                  FROM recurring_expenses r 
                  LEFT JOIN categories c ON r.category_id = c.id 
                  WHERE r.user_id = ?";
    $rec_params = [$user_id];
    $rec_types = 'i';

    if ($filter_category) {
        $rec_query .= " AND r.category_id = ?";
        $rec_params[] = $filter_category;
        $rec_types .= 'i';
    }
    if ($filter_payment_method) {
        $rec_query .= " AND r.payment_method = ?";
        $rec_params[] = $filter_payment_method;
        $rec_types .= 's';
    }

    $rec_stmt = $conn->prepare($rec_query);
    $rec_stmt->bind_param($rec_types, ...$rec_params);
    $rec_stmt->execute();
    $recurring_rules = $rec_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($recurring_rules as $rule) {
        $current_date = new DateTime($rule['start_date']);
        $rule_end_date = !empty($rule['end_date']) ? new DateTime($rule['end_date']) : clone $view_end_date;

        $interval = match (strtolower($rule['frequency'])) {
            'daily' => new DateInterval('P1D'),
            'weekly' => new DateInterval('P1W'),
            'monthly' => new DateInterval('P1M'),
            'yearly' => new DateInterval('P1Y'),
            default => new DateInterval('P1M'),
        };

        while ($current_date <= $rule_end_date && $current_date <= $view_end_date) {
            if ($current_date >= $view_start_date) {
                $date_key = $rule['id'] . '_' . $current_date->format('Y-m-d');
                // Skip if this date is marked as an exception
                if (!isset($exception_map[$date_key])) {
                    $instance_id = 'rec_' . $rule['id'] . '_' . $current_date->format('Ymd');
                    $generated_recurring[$instance_id] = [
                        'id' => $instance_id,
                        'rule_id' => $rule['id'],
                        'date' => $current_date->format('Y-m-d'),
                        'amount' => $rule['amount'],
                        'category_name' => $rule['category_name'],
                        'description' => $rule['description'],
                        'payment_method' => $rule['payment_method'],
                        'merchant' => $rule['merchant'] ?? 'N/A',
                        'is_recurring' => true,
                        'type' => 'Recurring',
                        'frequency' => $rule['frequency'],
                    ];
                }
            }
            $current_date->add($interval);
        }
    }
}

// Step 3: Fetch all one-time expenses and overrides
if ($filter_type === 'All' || $filter_type === 'One-Time') {
    $one_time_query = "SELECT e.*, c.name AS category_name 
                       FROM expenses e 
                       LEFT JOIN categories c ON e.category_id = c.id 
                       WHERE e.user_id = ? AND e.date BETWEEN ? AND ?";
    $params = [$user_id, $view_start_date->format('Y-m-d'), $view_end_date->format('Y-m-d')];
    $types = 'iss';

    if ($filter_category) {
        $one_time_query .= " AND e.category_id = ?";
        $params[] = $filter_category;
        $types .= 'i';
    }
    if ($filter_payment_method) {
        $one_time_query .= " AND e.payment_method = ?";
        $params[] = $filter_payment_method;
        $types .= 's';
    }

    $stmt = $conn->prepare($one_time_query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $one_time_expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($one_time_expenses as $expense) {
        if (!empty($expense['recurring_expense_id'])) {
            // This is an override for a recurring expense.
            // Remove the auto-generated version.
            $instance_id_to_remove = 'rec_' . $expense['recurring_expense_id'] . '_' . (new DateTime($expense['date']))->format('Ymd');
            unset($generated_recurring[$instance_id_to_remove]);
        }
        
        // Add the one-time expense to the final list
        $expense['is_recurring'] = false;
        $expense['type'] = 'One-Time';
        $expense['frequency'] = null;
        $all_expenses[] = $expense;
    }
}

// Step 4: Merge the remaining generated recurring expenses with the one-time/override expenses
$all_expenses = array_merge($all_expenses, array_values($generated_recurring));

// Step 5: Sort the final combined list
$sort_key = ($sort === 'category_id') ? 'category_name' : $sort;
usort($all_expenses, function ($a, $b) use ($sort_key, $order) {
    $val_a = $a[$sort_key] ?? '';
    $val_b = $b[$sort_key] ?? '';
    if ($sort_key === 'amount') {
        return $order === 'DESC' ? $val_b <=> $val_a : $val_a <=> $val_b;
    }
    return $order === 'DESC' ? strnatcmp($val_b, $val_a) : strnatcmp($val_a, $val_b);
});

// Calculate summary metrics
$total_transactions = count($all_expenses);
$category_freq = [];
$category_spend = [];
$top_category = 'N/A';
$highest_spend = 0;
$total_one_time = 0;
$total_recurring = 0;

foreach ($all_expenses as $exp) {
    $cat = $exp['category_name'] ?? 'N/A';
    $category_freq[$cat] = ($category_freq[$cat] ?? 0) + 1;
    $category_spend[$cat] = ($category_spend[$cat] ?? 0) + $exp['amount'];  
    if ($category_spend[$cat] > $highest_spend) {
        $highest_spend = $category_spend[$cat];
        $top_category = $cat;
    }
    if ($exp['is_recurring']) {
        $total_recurring += $exp['amount'];
    } else {
        $total_one_time += $exp['amount'];
    }
}
$total_expenses = $total_one_time + $total_recurring;

// Fetch categories and payment methods
$categories = $conn->query("SELECT id, name FROM categories WHERE user_id IS NULL OR user_id = $user_id");
$payment_methods = $conn->query("SELECT DISTINCT payment_method FROM expenses WHERE user_id = $user_id AND payment_method IS NOT NULL");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Expenses - Expense Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary: #4A90E2;
            --secondary: #15803d;
            --neutral: #FFFFFF;
            --neutral-accent: rgb(5, 9, 16);
            --glow: #2DD4BF;
            --warning: #EF4444;
            --dark-bg: #1F2937;
            --dark-text: #D1D5DB;
            --background: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#1F2937' : '#FFFFFF'; ?>;
            --text: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#D1D5DB' : '#333'; ?>;
            --card-bg: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#1F2937' : '#FFFFFF'; ?>;
            --filter-bg: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#1F2937' : '#FFFFFF'; ?>;
            --border: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#D1D5DB' : '#E5E7EB'; ?>;
            --table-header-bg: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#374151' : '#E5E7EB'; ?>;
            --input-bg: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#374151' : '#FFFFFF'; ?>;
            --input-border: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#4B5563' : '#D1D5DB'; ?>;
            --input-text: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#D1D5DB' : '#333'; ?>;
            --button-bg: #15803d;
            --button-hover-bg: #166534;
            --edit-bg: #10B981;
            --edit-hover-bg: #059669;
            --delete-bg: #EF4444;
            --delete-hover-bg: #DC2626;
            --progress-bg: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#4B5563' : '#E5E7EB'; ?>;
        }

        body {
            font-family: 'Roboto', sans-serif;
            background-color: var(--background);
            color: var(--text);
            margin: 0;
            padding: 20px;
            transition: background-color 0.3s, color 0.3s;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        h1 {
            font-size: 28px;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 20px;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .summary-card {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2), 0 0 10px var(--glow);
        }

        .summary-card h3 {
            font-size: 16px;
            margin-bottom: 10px;
            color: var(--neutral-accent);
        }

        .summary-card p {
            font-size: 24px;
            font-weight: 600;
            color: var(--primary);
        }

        form {
            background: var(--filter-bg);
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        select, input, button {
            padding: 8px 12px;
            border-radius: 5px;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--input-text);
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
            transition: background-color 0.3s, color 0.3s, border-color 0.3s;
        }

        select:focus, input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 5px rgba(30, 58, 138, 0.3);
        }

        button {
            background-color: var(--button-bg);
            color: var(--neutral);
            border: none;
            cursor: pointer;
            font-weight: 500;
            padding: 8px 15px;
        }

        button:hover {
            background-color: var(--button-hover-bg);
        }

        .export-btn {
            background: var(--primary);
            color: var(--neutral);
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s, transform 0.1s;
            margin-right: 10px;
        }

        .export-btn:hover {
            background: #152e6f;
            transform: translateY(-2px);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background: var(--card-bg);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        th {
            background-color: var(--table-header-bg);
            font-weight: 600;
        }

        tr:nth-child(even) {
            background-color: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#374151' : '#f9fafb'; ?>;
        }

        .action-btn {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            transition: transform 0.2s, background-color 0.3s;
            position: relative;
            overflow: hidden;
        }

        .action-btn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: var(--glow);
            opacity: 0;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.5s, height 0.5s, opacity 0.5s;
        }

        .action-btn:hover::after {
            width: 200px;
            height: 200px;
            opacity: 0.3;
        }

        .edit-btn {
            background-color: var(--edit-bg);
            color: var(--neutral);
            margin-right: 5px;
        }

        .edit-btn:hover {
            background-color: var(--edit-hover-bg);
            transform: translateY(-2px);
        }

        .delete-btn {
            background-color: var(--delete-bg);
            color: var(--neutral);
        }

        .delete-btn:hover {
            background-color: var(--delete-hover-bg);
            transform: translateY(-2px);
        }

        .action-btn.disabled {
            background-color: #9CA3AF;
            cursor: not-allowed;
            pointer-events: none;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            background: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .empty-state p {
            font-size: 1.2rem;
            color: var(--neutral-accent);
            margin-bottom: 20px;
        }

        .add-expense-btn {
            background: var(--primary);
            color: var(--neutral);
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s, transform 0.1s;
        }

        .add-expense-btn:hover {
            background: #152e6f;
            transform: translateY(-2px);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 0.9em;
        }

        .badge.one-time {
            background-color: #e6f3ff;
            color: #1E3A8A;
        }

        .badge.recurring {
            background-color: #e6ffe6;
            color: #15803d;
        }

        @media (max-width: 768px) {
            .expense-table {
                display: none;
            }
            .expense-card {
                display: block;
                background: var(--card-bg);
                border: 1px solid var(--border);
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 10px;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            }
            .expense-card div {
                margin-bottom: 8px;
            }
            .expense-card .actions {
                display: flex;
                gap: 10px;
            }
            .summary-cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
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
                <i class="fas <?php echo $settings['theme'] === 'light' ? 'fa-moon' : 'fa-sun'; ?>"></i>
            </button>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </nav>

    <div class="container">
        <h1>Your Expenses</h1>

        <div class="summary-cards">
            <div class="summary-card">
                <h3>Total Expenses</h3>
                <p>$<?php echo number_format($total_expenses, 2); ?></p>
            </div>
            <div class="summary-card">
                <h3>One-Time Expenses</h3>
                <p>$<?php echo number_format($total_one_time, 2); ?></p>
            </div>
            <div class="summary-card">
                <h3>Recurring Expenses</h3>
                <p>$<?php echo number_format($total_recurring, 2); ?></p>
            </div>
            <div class="summary-card">
                <h3>Top Category</h3>
                <p><?php echo htmlspecialchars($top_category); ?></p>
            </div>
            <div class="summary-card">
                <h3>Expense Breakdown</h3>
                <canvas id="expenseChart" width="200" height="200"></canvas>
            </div>
        </div>

        <form method="GET">
            <select name="sort">
                <option value="date" <?php echo $sort === 'date' ? 'selected' : ''; ?>>Date</option>
                <option value="amount" <?php echo $sort === 'amount' ? 'selected' : ''; ?>>Amount</option>
                <option value="category_id" <?php echo $sort === 'category_id' ? 'selected' : ''; ?>>Category</option>
                <option value="type" <?php echo $sort === 'type' ? 'selected' : ''; ?>>Type</option>
                <option value="frequency" <?php echo $sort === 'frequency' ? 'selected' : ''; ?>>Frequency</option>
            </select>
            <select name="order">
                <option value="ASC" <?php echo $order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                <option value="DESC" <?php echo $order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
            </select>
            <select name="type">
                <option value="All" <?php echo $filter_type === 'All' ? 'selected' : ''; ?>>All Types</option>
                <option value="One-Time" <?php echo $filter_type === 'One-Time' ? 'selected' : ''; ?>>One-Time</option>
                <option value="Recurring" <?php echo $filter_type === 'Recurring' ? 'selected' : ''; ?>>Recurring</option>
            </select>
            <select name="category">
                <option value="">All Categories</option>
                <?php $categories->data_seek(0); while ($cat = $categories->fetch_assoc()) echo "<option value='{$cat['id']}' " . ($filter_category == $cat['id'] ? 'selected' : '') . ">{$cat['name']}</option>"; ?>
            </select>
            <select name="payment_method">
                <option value="">All Payment Methods</option>
                <?php $payment_methods->data_seek(0); while ($method = $payment_methods->fetch_assoc()) echo "<option value='{$method['payment_method']}' " . ($filter_payment_method == $method['payment_method'] ? 'selected' : '') . ">{$method['payment_method']}</option>"; ?>
            </select>
            <input type="date" name="date_start" value="<?php echo htmlspecialchars($view_start_date->format('Y-m-d')); ?>">
            <input type="date" name="date_end" value="<?php echo htmlspecialchars($view_end_date->format('Y-m-d')); ?>">
            <button type="submit">Filter</button>
        </form>

        <div style="margin-bottom: 20px;">
            <button class="export-btn" onclick="exportData('filtered')">Export Filtered (PDF/CSV)</button>
            <button class="export-btn" onclick="exportData('all')">Export All (PDF/CSV)</button>
        </div>
        
        <?php if (count($all_expenses) > 0): ?>
            <table class="expense-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Type</th>
                        <th>Frequency</th>
                        <th>Payment Method</th>
                        <th>Merchant</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_expenses as $exp): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($exp['date'] ?? 'N/A'); ?></td>
                            <td>$<?php echo htmlspecialchars(number_format($exp['amount'] ?? 0, 2)); ?></td>
                            <td><?php echo htmlspecialchars($exp['category_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($exp['description'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="badge <?php echo $exp['type'] === 'One-Time' ? 'one-time' : 'recurring'; ?>">
                                    <?php echo $exp['type'] === 'One-Time' ? '⚫' : '🔄'; ?>
                                    <?php echo htmlspecialchars($exp['type']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($exp['frequency'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($exp['payment_method'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($exp['merchant'] ?? 'N/A'); ?></td>
                            <td>
    <?php if (isset($exp['is_recurring']) && $exp['is_recurring']): ?>
        <a href="edit_recurring_handler.php?id=<?php echo htmlspecialchars($exp['rule_id']); ?>&date=<?php echo htmlspecialchars($exp['date']); ?>" class="action-btn edit-btn">Edit</a>
        <a href="delete_recurring_handler.php?id=<?php echo htmlspecialchars($exp['rule_id']); ?>&date=<?php echo htmlspecialchars($exp['date']); ?>" class="action-btn delete-btn" onclick="return confirm('Are you sure you want to delete this single instance?');">Delete</a>
    <?php else: ?>
        <a href="add_expense.php?id=<?php echo htmlspecialchars($exp['id'] ?? '0'); ?>" class="action-btn edit-btn">Edit</a>
        <a href="view_expenses.php?delete=<?php echo htmlspecialchars($exp['id'] ?? '0'); ?>" class="action-btn delete-btn" onclick="return confirm('Are you sure?');">Delete</a>
    <?php endif; ?>
</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <p>No expenses found for the selected criteria!</p>
                <a href="add_expense.php" class="add-expense-btn">Add Expense</a>
            </div>
        <?php endif; ?>

        <!--<a href="dashboard.php" class="add-expense-btn">Back to Dashboard</a> -->
        
        <!-- Footer -->
        </div>
        <?php if (!in_array(basename($_SERVER['PHP_SELF']), ['login.php', 'register.php', 'logout.php'])) {
            include 'footer.html';
        } ?>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/PapaParse/5.3.0/papaparse.min.js"></script>
        <script>
            function exportData(type) {
                let data = [];
                let table = document.querySelector('table');
                let headers = ['Date', 'Amount', 'Category', 'Description', 'Type', 'Frequency', 'Payment Method', 'Merchant'];

                if (type === 'filtered' && table) {
                    let rows = table.querySelectorAll('tbody tr');
                    rows.forEach(row => {
                        let rowData = {};
                        row.querySelectorAll('td:not(:last-child)').forEach((cell, index) => {
                            rowData[headers[index]] = cell.textContent.trim();
                        });
                        data.push(rowData);
                    });
                } else if (type === 'all') {
                    <?php
                    $all_expenses_query = $conn->query("SELECT e.*, c.name AS category_name 
                        FROM expenses e 
                        LEFT JOIN categories c ON e.category_id = c.id 
                        WHERE e.user_id = $user_id");
                    $one_time_all = $all_expenses_query->fetch_all(MYSQLI_ASSOC);
                    foreach ($one_time_all as $exp) {
                        $exp['is_recurring'] = false;
                        $exp['type'] = 'One-Time';
                        $exp['frequency'] = null;
                        echo "data.push({
                            'Date': '{$exp['date']}',
                            'Amount': '{$exp['amount']}',
                            'Category': '" . ($exp['category_name'] ?? 'N/A') . "',
                            'Description': '{$exp['description']}',
                            'Type': 'One-Time',
                            'Frequency': '',
                            'Payment Method': '{$exp['payment_method']}',
                            'Merchant': '" . ($exp['merchant'] ?? 'N/A') . "'
                        });";
                    }
                    $rec_all_query = $conn->prepare("SELECT r.*, c.name AS category_name 
                        FROM recurring_expenses r 
                        LEFT JOIN categories c ON r.category_id = c.id 
                        WHERE r.user_id = ?");
                    $rec_all_query->bind_param('i', $user_id);
                    $rec_all_query->execute();
                    $rec_all_rules = $rec_all_query->get_result()->fetch_all(MYSQLI_ASSOC);
                    $one_time_lookup_all = [];
                    foreach ($one_time_all as $expense) {
                        $key = $expense['date'] . '_' . ($expense['category_id'] ?? 'N/A');
                        $one_time_lookup_all[$key] = $expense;
                    }
                    $unique_recurring_all = [];
                    foreach ($rec_all_rules as $rule) {
                        $current_date = new DateTime($rule['start_date']);
                        $rule_end_date = !empty($rule['end_date']) ? new DateTime($rule['end_date']) : new DateTime();
                        $interval = match (strtolower($rule['frequency'])) {
                            'daily' => new DateInterval('P1D'),
                            'weekly' => new DateInterval('P1W'),
                            'monthly' => new DateInterval('P1M'),
                            'yearly' => new DateInterval('P1Y'),
                            default => new DateInterval('P1M'),
                        };
                        while ($current_date <= $rule_end_date) {
                            $date_key = $current_date->format('Y-m-d') . '_' . ($rule['category_id'] ?? 'N/A');
                            if (!isset($unique_recurring_all[$date_key])) {
                                if (isset($one_time_lookup_all[$date_key]) && 
                                    $one_time_lookup_all[$date_key]['amount'] == $rule['amount'] && 
                                    similar_text($one_time_lookup_all[$date_key]['description'], $rule['description']) > 70) {
                                    $unique_recurring_all[$date_key] = true;
                                } else {
                                    $unique_recurring_all[$date_key] = true;
                                    echo "data.push({
                                        'Date': '{$current_date->format('Y-m-d')}',
                                        'Amount': '{$rule['amount']}',
                                        'Category': '" . ($rule['category_name'] ?? 'N/A') . "',
                                        'Description': '{$rule['description']}',
                                        'Type': 'Recurring',
                                        'Frequency': '{$rule['frequency']}',
                                        'Payment Method': '{$rule['payment_method']}',
                                        'Merchant': '" . ($rule['merchant'] ?? 'N/A') . "'
                                    });";
                                }
                            }
                            $current_date->add($interval);
                        }
                    }
                    ?>
                }

                let csv = Papa.unparse(data);
                let csvBlob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                let csvLink = document.createElement('a');
                csvLink.href = URL.createObjectURL(csvBlob);
                csvLink.download = `expenses_${type}_${new Date().toISOString().split('T')[0]}.csv`;
                csvLink.click();

                let element = document.createElement('div');
                element.innerHTML = '<h2>Expense Report</h2><table><thead><tr>' + 
                    headers.map(h => `<th>${h}</th>`).join('') + '</tr></thead><tbody>' + 
                    data.map(row => `<tr>${headers.map(h => `<td>${row[h] || 'N/A'}</td>`).join('')}</tr>`).join('') + '</tbody></table>';
                html2pdf().from(element).save(`expenses_${type}_${new Date().toISOString().split('T')[0]}.pdf`);
            }

            // Initialize pie chart
            const ctx = document.getElementById('expenseChart').getContext('2d');
            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: ['One-Time', 'Recurring'],
                    datasets: [{
                        data: [<?php echo $total_one_time; ?>, <?php echo $total_recurring; ?>],
                        backgroundColor: ['#1E3A8A', '#15803d'],
                    }],
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'top' },
                    },
                },
            });
        </script>
    </div>
</body>
</html>