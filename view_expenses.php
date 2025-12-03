<?php
// Start output buffering
ob_start();
session_start();

require_once 'config.php';
require_once 'includes/theme_handler.php';

// Check for user session
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php?form=login');
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
    header('Location: auth.php?form=login');
    exit;
}

// Fetch user settings (theme)
$settings = getUserTheme($conn, $user_id);

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

        // Fast-forward to the first occurrence on/after the view start date to avoid long loops
        while ($current_date < $view_start_date) {
            $current_date->add($interval);
            if ($current_date > $rule_end_date) {
                break;
            }
        }

        while ($current_date <= $rule_end_date && $current_date <= $view_end_date) {
            $date_key = $rule['id'] . '_' . $current_date->format('Y-m-d');
            if (!isset($exception_map[$date_key])) {
                $instance_id = 'rec_' . $rule['id'] . '_' . $current_date->format('Ymd');
                $generated_recurring[$instance_id] = [
                    'id' => $instance_id,
                    'rule_id' => $rule['id'],
                    'date' => $current_date->format('Y-m-d'),
                    'amount' => (float)$rule['amount'],
                    'category_name' => $rule['category_name'] ?? 'N/A',
                    'description' => $rule['description'],
                    'payment_method' => $rule['payment_method'],
                    'merchant' => $rule['merchant'] ?? 'N/A',
                    'is_recurring' => true,
                    'type' => 'Recurring',
                    'frequency' => $rule['frequency'],
                ];
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

// Step 4b: Deduplicate merged list to avoid duplicate rows on the frontend
// - For recurring: unique by (rule_id, date)
// - For one-time: unique by (id)
$deduped = [];
$seen = [];
foreach ($all_expenses as $exp) {
    $is_rec = isset($exp['is_recurring']) && $exp['is_recurring'];
    if ($is_rec) {
        $rid = $exp['rule_id'] ?? '0';
        $d = $exp['date'] ?? '';
        $k = 'rec-' . $rid . '-' . $d;
    } else {
        $eid = $exp['id'] ?? '0';
        $k = 'one-' . $eid;
    }
    if (!isset($seen[$k])) {
        $seen[$k] = true;
        $deduped[] = $exp;
    }
}
$all_expenses = $deduped;

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

// Debug helpers (enabled via ?debug=1)
$debug_enabled = isset($_GET['debug']) && $_GET['debug'] == '1';
$debug_recurring_rules = isset($recurring_rules) ? count($recurring_rules) : 0;
$debug_generated_recurring_count = count($generated_recurring);
$debug_sample_instances = $debug_enabled ? array_slice(array_values($generated_recurring), 0, 5) : [];

// Fetch categories and payment methods
$categories = $conn->query("SELECT id, name FROM categories WHERE user_id IS NULL OR user_id = $user_id");
// Include payment methods from both one-time and recurring expenses
$payment_methods = $conn->query("SELECT DISTINCT payment_method FROM (
    SELECT payment_method FROM expenses WHERE user_id = $user_id AND payment_method IS NOT NULL
    UNION
    SELECT payment_method FROM recurring_expenses WHERE user_id = $user_id AND payment_method IS NOT NULL
) pm");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Expenses - Expense Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css?v=<?php echo filemtime('css/styles.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .container {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            padding: 40px 20px;
        }
        
        .page-title {
            font-size: 32px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: 'Roboto', sans-serif;
        }
        
        body.dark .page-title {
            color: #f3f4f6;
        }
        
        .page-title::before {
            content: '';
            width: 4px;
            height: 36px;
            background: linear-gradient(180deg, #3b82f6, #8b5cf6);
            border-radius: 2px;
        }
        
        .page-title i {
            color: #3b82f6;
        }
        
        /* Table Styling */
        .table-wrapper {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            overflow-x: auto;
            margin-bottom: 24px;
        }
        
        body.dark .table-wrapper {
            background: #1f2937;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
        }
        
        .expense-table {
            width: 100%;
            border-collapse: collapse;
            font-family: 'Roboto', sans-serif;
            table-layout: fixed;
        }
        
        .expense-table thead tr {
            background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
            border-bottom: 2px solid #e5e7eb;
        }
        
        body.dark .expense-table thead tr {
            background: linear-gradient(135deg, #374151 0%, #4b5563 100%);
            border-bottom: 2px solid #6b7280;
        }
        
        .expense-table th {
            text-align: left;
            padding: 18px 12px;
            font-weight: 700;
            color: #1f2937;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-family: 'Roboto', sans-serif;
            white-space: nowrap;
        }
        
        .expense-table th:nth-child(1) { width: 9%; }   /* Date */
        .expense-table th:nth-child(2) { width: 10%; }  /* Amount */
        .expense-table th:nth-child(3) { width: 11%; }  /* Category */
        .expense-table th:nth-child(4) { width: 18%; }  /* Description */
        .expense-table th:nth-child(5) { width: 11%; }  /* Type */
        .expense-table th:nth-child(6) { width: 10%; }  /* Frequency */
        .expense-table th:nth-child(7) { width: 13%; }  /* Payment Method */
        .expense-table th:nth-child(8) { width: 10%; }  /* Merchant */
        .expense-table th:nth-child(9) { width: 8%; }   /* Actions */
        
        body.dark .expense-table th {
            color: #f3f4f6;
        }
        
        .expense-table tbody tr {
            border-bottom: 1px solid #f3f4f6;
            transition: all 0.2s ease;
        }
        
        body.dark .expense-table tbody tr {
            border-bottom: 1px solid #374151;
        }
        
        .expense-table tbody tr:hover {
            background: rgba(59, 130, 246, 0.05);
            transform: scale(1.01);
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.1);
        }
        
        body.dark .expense-table tbody tr:hover {
            background: rgba(59, 130, 246, 0.1);
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);
        }
        
        .expense-table td {
            padding: 16px 12px;
            color: #374151;
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .expense-table td:nth-child(4) {
            white-space: normal;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 0;
        }
        
        body.dark .expense-table td {
            color: #d1d5db;
        }
        
        .expense-table tbody tr:last-child {
            border-bottom: none;
        }
        
        /* Badge Styling */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            font-family: 'Roboto', sans-serif;
        }
        
        .badge.one-time {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .badge.recurring {
            background: rgba(168, 85, 247, 0.1);
            color: #a855f7;
            border: 1px solid rgba(168, 85, 247, 0.2);
        }
        
        body.dark .badge.one-time {
            background: rgba(59, 130, 246, 0.2);
            border-color: rgba(59, 130, 246, 0.3);
        }
        
        body.dark .badge.recurring {
            background: rgba(168, 85, 247, 0.2);
            border-color: rgba(168, 85, 247, 0.3);
        }
        
        /* Action Buttons */
        .action-btn {
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            margin-right: 6px;
        }
        
        .edit-btn {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .edit-btn:hover {
            background: rgba(59, 130, 246, 0.2);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(59, 130, 246, 0.2);
        }
        
        .delete-btn {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .delete-btn:hover {
            background: rgba(239, 68, 68, 0.2);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(239, 68, 68, 0.2);
        }
        
        body.dark .edit-btn {
            background: rgba(59, 130, 246, 0.2);
            border-color: rgba(59, 130, 246, 0.3);
        }
        
        body.dark .delete-btn {
            background: rgba(239, 68, 68, 0.2);
            border-color: rgba(239, 68, 68, 0.3);
        }
    </style>
</head>
<body class="<?php echo htmlspecialchars($settings['theme']); ?>">
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

    <div class="container">
        <h1 class="page-title"><i class="fas fa-list"></i> Your Expenses</h1>

        <div class="summary-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-bottom: 35px;">
            <div class="summary-card" style="background: #FFFFFF; color: #1f2937; padding: 28px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); border: 1px solid #e5e7eb; transition: all 0.3s ease; cursor: pointer; position: relative; overflow: hidden;" onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 12px rgba(0, 0, 0, 0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px rgba(0, 0, 0, 0.1)'">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.2px; color: #6b7280; font-family: 'Roboto', sans-serif;">Total Expenses</h3>
                    <i class="fas fa-wallet" style="font-size: 24px; opacity: 0.6; color: #3b82f6;"></i>
                </div>
                <p style="margin: 0; font-size: 36px; font-weight: 700; color: #1f2937; font-family: 'Roboto', sans-serif;">KES&nbsp;<?php echo number_format($total_expenses, 2); ?></p>
            </div>
            <div class="summary-card" style="background: #FFFFFF; color: #1f2937; padding: 28px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); border: 1px solid #e5e7eb; transition: all 0.3s ease; cursor: pointer; position: relative; overflow: hidden;" onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 12px rgba(0, 0, 0, 0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px rgba(0, 0, 0, 0.1)'">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.2px; color: #6b7280; font-family: 'Roboto', sans-serif;">One-Time</h3>
                    <i class="fas fa-receipt" style="font-size: 24px; opacity: 0.6; color: #3b82f6;"></i>
                </div>
                <p style="margin: 0; font-size: 36px; font-weight: 700; color: #1f2937; font-family: 'Roboto', sans-serif;">KES&nbsp;<?php echo number_format($total_one_time, 2); ?></p>
            </div>
            <div class="summary-card" style="background: #FFFFFF; color: #1f2937; padding: 28px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); border: 1px solid #e5e7eb; transition: all 0.3s ease; cursor: pointer; position: relative; overflow: hidden;" onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 12px rgba(0, 0, 0, 0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px rgba(0, 0, 0, 0.1)'">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.2px; color: #6b7280; font-family: 'Roboto', sans-serif;">Recurring</h3>
                    <i class="fas fa-sync-alt" style="font-size: 24px; opacity: 0.6; color: #3b82f6;"></i>
                </div>
                <p style="margin: 0; font-size: 36px; font-weight: 700; color: #1f2937; font-family: 'Roboto', sans-serif;">KES&nbsp;<?php echo number_format($total_recurring, 2); ?></p>
            </div>
            <div class="summary-card" style="background: #FFFFFF; color: #1f2937; padding: 28px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); border: 1px solid #e5e7eb; transition: all 0.3s ease; cursor: pointer; position: relative; overflow: hidden;" onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 12px rgba(0, 0, 0, 0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px rgba(0, 0, 0, 0.1)'">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.2px; color: #6b7280; font-family: 'Roboto', sans-serif;">Top Category</h3>
                    <i class="fas fa-star" style="font-size: 24px; opacity: 0.6; color: #3b82f6;"></i>
                </div>
                <p style="margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; font-family: 'Roboto', sans-serif;"><?php echo htmlspecialchars($top_category); ?></p>
            </div>
            <div class="summary-card" style="background: #FFFFFF; color: #1f2937; padding: 28px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); border: 1px solid #e5e7eb; transition: all 0.3s ease; cursor: pointer; position: relative; overflow: hidden;" onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 12px rgba(0, 0, 0, 0.15)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px rgba(0, 0, 0, 0.1)'">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.2px; color: #6b7280; font-family: 'Roboto', sans-serif;">Expense Breakdown</h3>
                    <i class="fas fa-chart-pie" style="font-size: 24px; opacity: 0.6; color: #3b82f6;"></i>
                </div>
                <canvas id="expenseChart" width="200" height="200"></canvas>
            </div>
        </div>

        <?php if ($debug_enabled): ?>
            <div style="margin:16px 0;padding:12px;border:1px dashed #888;border-radius:8px;background:#f9fafb;color:#111;">
                <strong>Debug:</strong>
                <div>View range: <?php echo htmlspecialchars($view_start_date->format('Y-m-d')); ?> → <?php echo htmlspecialchars($view_end_date->format('Y-m-d')); ?></div>
                <div>Recurring rules matched: <?php echo (int)$debug_recurring_rules; ?></div>
                <div>Generated recurring instances in range: <?php echo (int)$debug_generated_recurring_count; ?></div>
                <?php if (!empty($debug_sample_instances)): ?>
                    <div>Sample instances (up to 5):</div>
                    <ul style="margin:6px 0 0 16px;">
                        <?php foreach ($debug_sample_instances as $inst): ?>
                            <li>#<?php echo htmlspecialchars($inst['rule_id']); ?> @ <?php echo htmlspecialchars($inst['date']); ?> • <?php echo htmlspecialchars($inst['frequency']); ?> • KES&nbsp;<?php echo htmlspecialchars(number_format($inst['amount'], 2)); ?> • <?php echo htmlspecialchars($inst['payment_method'] ?? 'N/A'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="GET" style="background: #FFFFFF; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); margin-bottom: 25px;">
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 13px; color: #6b7280; font-weight: 500;">Sort By</label>
                    <select name="sort" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; background: #FFFFFF; box-sizing: border-box;">
                        <option value="date" <?php echo $sort === 'date' ? 'selected' : ''; ?>>Date</option>
                        <option value="amount" <?php echo $sort === 'amount' ? 'selected' : ''; ?>>Amount</option>
                        <option value="category_id" <?php echo $sort === 'category_id' ? 'selected' : ''; ?>>Category</option>
                        <option value="type" <?php echo $sort === 'type' ? 'selected' : ''; ?>>Type</option>
                        <option value="frequency" <?php echo $sort === 'frequency' ? 'selected' : ''; ?>>Frequency</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 13px; color: #6b7280; font-weight: 500;">Order</label>
                    <select name="order" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; background: #FFFFFF; box-sizing: border-box;">
                        <option value="ASC" <?php echo $order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                        <option value="DESC" <?php echo $order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 13px; color: #6b7280; font-weight: 500;">Type</label>
                    <select name="type" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; background: #FFFFFF; box-sizing: border-box;">
                        <option value="All" <?php echo $filter_type === 'All' ? 'selected' : ''; ?>>All Types</option>
                        <option value="One-Time" <?php echo $filter_type === 'One-Time' ? 'selected' : ''; ?>>One-Time</option>
                        <option value="Recurring" <?php echo $filter_type === 'Recurring' ? 'selected' : ''; ?>>Recurring</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 13px; color: #6b7280; font-weight: 500;">Category</label>
                    <select name="category" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; background: #FFFFFF; box-sizing: border-box;">
                        <option value="">All Categories</option>
                        <?php $categories->data_seek(0); while ($cat = $categories->fetch_assoc()) echo "<option value='{$cat['id']}' " . ($filter_category == $cat['id'] ? 'selected' : '') . ">{$cat['name']}</option>"; ?>
                    </select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 2fr 2fr 2fr 1fr; gap: 20px; align-items: end;">
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 13px; color: #6b7280; font-weight: 500;">Payment Method</label>
                    <select name="payment_method" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; background: #FFFFFF; box-sizing: border-box;">
                        <option value="">All Payment Methods</option>
                        <?php $payment_methods->data_seek(0); while ($method = $payment_methods->fetch_assoc()) echo "<option value='{$method['payment_method']}' " . ($filter_payment_method == $method['payment_method'] ? 'selected' : '') . ">{$method['payment_method']}</option>"; ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 13px; color: #6b7280; font-weight: 500;">Start Date</label>
                    <input type="date" name="date_start" value="<?php echo htmlspecialchars($view_start_date->format('Y-m-d')); ?>" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; background: #FFFFFF; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 13px; color: #6b7280; font-weight: 500;">End Date</label>
                    <input type="date" name="date_end" value="<?php echo htmlspecialchars($view_end_date->format('Y-m-d')); ?>" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; background: #FFFFFF; box-sizing: border-box;">
                </div>
                <button type="submit" style="padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background 0.2s; box-sizing: border-box; height: 42px;">Filter</button>
            </div>
        </form>

        <div style="margin-bottom: 20px;">
            <button class="export-btn" onclick="exportData('filtered')">Export Filtered (PDF/CSV)</button>
            <button class="export-btn" onclick="exportData('all')">Export All (PDF/CSV)</button>
        </div>
        
        <?php if (count($all_expenses) > 0): ?>
            <div class="table-wrapper">
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
                                <td><strong>KES&nbsp;<?php echo htmlspecialchars(number_format($exp['amount'] ?? 0, 2)); ?></strong></td>
                                <td><?php echo htmlspecialchars($exp['category_name'] ?? 'N/A'); ?></td>
                                <td title="<?php echo htmlspecialchars($exp['description'] ?? 'N/A'); ?>"><?php echo htmlspecialchars($exp['description'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="badge <?php echo $exp['type'] === 'One-Time' ? 'one-time' : 'recurring'; ?>">
                                        <i class="fas <?php echo $exp['type'] === 'One-Time' ? 'fa-circle' : 'fa-sync-alt'; ?>"></i>
                                        <?php echo htmlspecialchars($exp['type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($exp['frequency'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($exp['payment_method'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($exp['merchant'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if (isset($exp['is_recurring']) && $exp['is_recurring']): ?>
                                        <a href="edit_recurring_handler.php?id=<?php echo htmlspecialchars($exp['rule_id']); ?>&date=<?php echo htmlspecialchars($exp['date']); ?>" class="action-btn edit-btn" title="Edit"><i class="fas fa-edit"></i></a>
                                        <a href="delete_recurring_handler.php?id=<?php echo htmlspecialchars($exp['rule_id']); ?>&date=<?php echo htmlspecialchars($exp['date']); ?>" class="action-btn delete-btn" onclick="return confirm('Are you sure you want to delete this single instance?');" title="Delete"><i class="fas fa-trash"></i></a>
                                    <?php else: ?>
                                        <a href="add_expense.php?id=<?php echo htmlspecialchars($exp['id'] ?? '0'); ?>" class="action-btn edit-btn" title="Edit"><i class="fas fa-edit"></i></a>
                                        <a href="view_expenses.php?delete=<?php echo htmlspecialchars($exp['id'] ?? '0'); ?>" class="action-btn delete-btn" onclick="return confirm('Are you sure?');" title="Delete"><i class="fas fa-trash"></i></a>
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

        <!-- Footer -->
        </div>
        <?php if (!in_array(basename($_SERVER['PHP_SELF']), ['login.php', 'register.php', 'logout.php'])) {
            include 'footer.html';
        } ?>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/PapaParse/5.3.0/papaparse.min.js"></script>
        <script src="js/theme-toggle.js?v=<?php echo filemtime('js/theme-toggle.js'); ?>"></script>
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
            const ctx1 = document.getElementById('expenseChart').getContext('2d');
            new Chart(ctx1, {
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