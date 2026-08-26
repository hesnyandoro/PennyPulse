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
$theme = $settings['theme'] ?? 'light';
$language = 'en';

// Fetch category names and create mapping (include both user and system categories)
$category_map = [];
$stmt = $conn->prepare("SELECT id, name FROM categories WHERE user_id = ? OR user_id IS NULL");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $category_map[$row['id']] = $row['name'];
}

// Fetch budgets
$budgets = [];
$stmt = $conn->prepare("SELECT category_id, budget_amount FROM budgets WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if (isset($category_map[$row['category_id']])) {
        $category_name = $category_map[$row['category_id']];
        $budgets[$category_name] = [
            'amount' => $row['budget_amount'],
            'category_id' => $row['category_id']
        ];
    }
}

// Default filter values - check both POST and GET for auto-update
$time_period = $_REQUEST['time_period'] ?? 'this_month';
$comparison_period = $_REQUEST['comparison_period'] ?? 'last_month';
$view_mode = $_REQUEST['view_mode'] ?? 'overview';
$category_filter = filter_var($_REQUEST['category'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$category_filter = $category_filter !== false && isset($category_map[$category_filter]) ? $category_filter : null;
$payment_methods = ['cash', 'credit_card', 'debit_card', 'mobile_payment', 'bank_transfer', 'mpesa'];
$payment_method_filter = in_array($_REQUEST['payment_method'] ?? '', $payment_methods, true) ? $_REQUEST['payment_method'] : '';
$expense_types = ['all', 'one_time', 'recurring'];
$requested_expense_type = $_REQUEST['expense_type'] ?? 'all';
$expense_type_filter = in_array($requested_expense_type, $expense_types, true) ? $requested_expense_type : 'all';
$merchant_filter = trim((string)($_REQUEST['merchant'] ?? ''));
$merchant_filter = mb_substr($merchant_filter, 0, 255);
$min_amount_filter = is_numeric($_REQUEST['min_amount'] ?? '') && (float)$_REQUEST['min_amount'] >= 0 ? (float)$_REQUEST['min_amount'] : null;
$max_amount_filter = is_numeric($_REQUEST['max_amount'] ?? '') && (float)$_REQUEST['max_amount'] >= 0 ? (float)$_REQUEST['max_amount'] : null;
if ($min_amount_filter !== null && $max_amount_filter !== null && $min_amount_filter > $max_amount_filter) {
    [$min_amount_filter, $max_amount_filter] = [$max_amount_filter, $min_amount_filter];
}

// Helper function to get date range
function getDateRange($period) {
    switch ($period) {
        case 'this_week':
            return [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')];
        case 'last_week':
            return [date('Y-m-d', strtotime('monday last week')), date('Y-m-d', strtotime('sunday last week'))];
        case 'this_month':
            return [date('Y-m-01'), date('Y-m-d')];
        case 'last_month':
            $first = date('Y-m-01', strtotime('first day of last month'));
            $last = date('Y-m-t', strtotime('last day of last month'));
            return [$first, $last];
        case 'this_year':
            return [date('Y-01-01'), date('Y-m-d')];
        case 'last_year':
            return [date('Y-01-01', strtotime('last year')), date('Y-12-31', strtotime('last year'))];
        case 'last_7_days':
            return [date('Y-m-d', strtotime('-7 days')), date('Y-m-d')];
        case 'last_30_days':
            return [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')];
        default:
            return [date('Y-m-01'), date('Y-m-d')];
    }
}

list($start_date, $end_date) = getDateRange($time_period);
list($comp_start_date, $comp_end_date) = getDateRange($comparison_period);

// Fetch expenses for a period, applying the same filters to both expense sources.
function fetchExpenses($conn, $user_id, $start_date, $end_date, $category_map, $filters) {
    $expenses = [];
    $base_conditions = [];
    $base_params = [];
    $base_types = '';

    if ($filters['category'] !== null) {
        $base_conditions[] = 'category_id = ?';
        $base_params[] = $filters['category'];
        $base_types .= 'i';
    }
    if ($filters['payment_method'] !== '') {
        $base_conditions[] = 'payment_method = ?';
        $base_params[] = $filters['payment_method'];
        $base_types .= 's';
    }
    if ($filters['merchant'] !== '') {
        $base_conditions[] = 'merchant LIKE ?';
        $base_params[] = '%' . $filters['merchant'] . '%';
        $base_types .= 's';
    }
    if ($filters['min_amount'] !== null) {
        $base_conditions[] = 'amount >= ?';
        $base_params[] = $filters['min_amount'];
        $base_types .= 'd';
    }
    if ($filters['max_amount'] !== null) {
        $base_conditions[] = 'amount <= ?';
        $base_params[] = $filters['max_amount'];
        $base_types .= 'd';
    }

    $bind_params = function ($stmt, $types, $params) {
        if ($types !== '') {
            $bind_values = array_merge([$types], $params);
            $bind_references = [];
            foreach ($bind_values as $key => &$value) {
                $bind_references[$key] = &$value;
            }
            call_user_func_array([$stmt, 'bind_param'], $bind_references);
        }
    };

    if ($filters['expense_type'] !== 'recurring') {
        $conditions = array_merge(['user_id = ?', 'date BETWEEN ? AND ?'], $base_conditions);
        $params = array_merge([$user_id, $start_date, $end_date], $base_params);
        $types = 'iss' . $base_types;
        $stmt = $conn->prepare('SELECT amount, category_id as category, date, payment_method, description FROM expenses WHERE ' . implode(' AND ', $conditions));
        $bind_params($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $expenses[] = $row;
        }
    }

    if ($filters['expense_type'] === 'one_time') {
        return $expenses;
    }

    // Handle recurring expenses
    $conditions = array_merge(['user_id = ?', 'start_date <= ?', '(end_date IS NULL OR end_date >= ?)'], $base_conditions);
    $params = array_merge([$user_id, $end_date, $start_date], $base_params);
    $types = 'iss' . $base_types;
    $stmt = $conn->prepare('SELECT amount, category_id as category, start_date, end_date, frequency, payment_method, merchant, description FROM recurring_expenses WHERE ' . implode(' AND ', $conditions));
    $bind_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $interval_map = [
        'daily' => 'P1D',
        'weekly' => 'P1W',
        'monthly' => 'P1M',
        'yearly' => 'P1Y'
    ];
    
    while ($row = $result->fetch_assoc()) {
        $frequency = strtolower($row['frequency']);
        if (!isset($interval_map[$frequency])) {
            continue;
        }
        
        $interval = new DateInterval($interval_map[$frequency]);
        $period_start = new DateTime(max($row['start_date'], $start_date));
        $period_end_date = min($end_date, $row['end_date'] ?? $end_date);
        $period_end = new DateTime($period_end_date);
        $period_end->modify('+1 day');
        
        $period = new DatePeriod($period_start, $interval, $period_end);
        
        foreach ($period as $date) {
            $date_str = $date->format('Y-m-d');
            if ($date_str >= $start_date && $date_str <= $end_date) {
                $expenses[] = [
                    'amount' => $row['amount'],
                    'category' => $row['category'],
                    'date' => $date_str,
                    'payment_method' => $row['payment_method'],
                    'description' => $row['description'] . ' (Recurring)'
                ];
            }
        }
    }
    
    return $expenses;
}

$report_filters = [
    'category' => $category_filter,
    'payment_method' => $payment_method_filter,
    'expense_type' => $expense_type_filter,
    'merchant' => $merchant_filter,
    'min_amount' => $min_amount_filter,
    'max_amount' => $max_amount_filter
];

$current_expenses = fetchExpenses($conn, $user_id, $start_date, $end_date, $category_map, $report_filters);
$comparison_expenses = fetchExpenses($conn, $user_id, $comp_start_date, $comp_end_date, $category_map, $report_filters);

// Calculate metrics
function calculateMetrics($expenses, $category_map, $budgets) {
    $total = 0;
    $category_breakdown = [];
    $daily_expenses = [];
    $payment_method_breakdown = [];
    
    foreach ($expenses as $expense) {
        $amount = $expense['amount'];
        $total += $amount;
        
        $category_id = $expense['category'];
        $category_name = $category_map[$category_id] ?? 'Uncategorized';
        $category_breakdown[$category_name] = ($category_breakdown[$category_name] ?? 0) + $amount;
        
        $date = $expense['date'];
        $daily_expenses[$date] = ($daily_expenses[$date] ?? 0) + $amount;
        
        $payment_method = $expense['payment_method'] ?? 'Unknown';
        $payment_method_breakdown[$payment_method] = ($payment_method_breakdown[$payment_method] ?? 0) + $amount;
    }
    
    arsort($category_breakdown);
    
    return [
        'total' => $total,
        'category_breakdown' => $category_breakdown,
        'daily_expenses' => $daily_expenses,
        'payment_method_breakdown' => $payment_method_breakdown,
        'transaction_count' => count($expenses)
    ];
}

$current_metrics = calculateMetrics($current_expenses, $category_map, $budgets);
$comparison_metrics = calculateMetrics($comparison_expenses, $category_map, $budgets);

// Calculate budget utilization
$budget_status = [];
$total_budgeted = 0;
$total_spent_budgeted = 0;

foreach ($budgets as $category_name => $budget_info) {
    $spent = $current_metrics['category_breakdown'][$category_name] ?? 0;
    $budget_amount = $budget_info['amount'];
    $total_budgeted += $budget_amount;
    $total_spent_budgeted += $spent;
    
    $utilization = $budget_amount > 0 ? ($spent / $budget_amount) * 100 : 0;
    
    $budget_status[$category_name] = [
        'spent' => $spent,
        'budget' => $budget_amount,
        'utilization' => $utilization,
        'remaining' => $budget_amount - $spent,
        'status' => $utilization >= 100 ? 'over' : ($utilization >= 90 ? 'warning' : 'good')
    ];
}

// Calculate trends
$spending_change = $comparison_metrics['total'] > 0 
    ? (($current_metrics['total'] - $comparison_metrics['total']) / $comparison_metrics['total']) * 100 
    : 0;

$transaction_change = $comparison_metrics['transaction_count'] > 0
    ? (($current_metrics['transaction_count'] - $comparison_metrics['transaction_count']) / $comparison_metrics['transaction_count']) * 100
    : 0;

// Prepare daily trend data
ksort($current_metrics['daily_expenses']);
$trend_labels = array_keys($current_metrics['daily_expenses']);
$trend_data = array_values($current_metrics['daily_expenses']);

// Calculate average daily spending
$days_count = count($current_metrics['daily_expenses']) ?: 1;
$avg_daily = $current_metrics['total'] / $days_count;

// Generate insights
$insights = [];
if ($current_metrics['total'] > 0) {
    // Spending change insight
    if (abs($spending_change) > 10) {
        $direction = $spending_change > 0 ? 'increased' : 'decreased';
        $insights[] = [
            'icon' => $spending_change > 0 ? 'fa-arrow-up' : 'fa-arrow-down',
            'type' => $spending_change > 0 ? 'warning' : 'success',
            'message' => "Your spending has $direction by " . number_format(abs($spending_change), 1) . "% compared to the previous period."
        ];
    }
    
    // Top category insight
    if (!empty($current_metrics['category_breakdown'])) {
        $top_category = array_key_first($current_metrics['category_breakdown']);
        $top_amount = $current_metrics['category_breakdown'][$top_category];
        $percentage = ($top_amount / $current_metrics['total']) * 100;
        
        if ($percentage > 40) {
            $insights[] = [
                'icon' => 'fa-chart-pie',
                'type' => 'info',
                'message' => "$top_category accounts for " . number_format($percentage, 1) . "% of your spending (KES&nbsp;" . number_format($top_amount, 2) . ")."
            ];
        }
    }
    
    // Budget alerts
    foreach ($budget_status as $category => $status) {
        if ($status['status'] === 'over') {
            $overspent = $status['spent'] - $status['budget'];
            $insights[] = [
                'icon' => 'fa-exclamation-triangle',
                'type' => 'danger',
                'message' => "You've exceeded your $category budget by KES&nbsp;" . number_format($overspent, 2) . "."
            ];
        } elseif ($status['status'] === 'warning') {
            $insights[] = [
                'icon' => 'fa-exclamation-circle',
                'type' => 'warning',
                'message' => "You're approaching your $category budget limit (" . number_format($status['utilization'], 1) . "% used)."
            ];
        }
    }
    
    // Average spending insight
    if ($avg_daily > 1000) {
        $insights[] = [
            'icon' => 'fa-info-circle',
            'type' => 'info',
            'message' => "Your average daily spending is KES&nbsp;" . number_format($avg_daily, 2) . ". Consider setting daily spending limits."
        ];
    }
}

if (empty($insights)) {
    $insights[] = [
        'icon' => 'fa-check-circle',
        'type' => 'success',
        'message' => "Great job! Your spending is well-managed and within budget."
    ];
}
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($language); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Reports - Expense Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css?v=<?php echo filemtime('css/styles.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* Modern UI Enhancements */
        .reports-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Loading Skeleton */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: 8px;
        }
        
        body.dark .skeleton {
            background: linear-gradient(90deg, #2d2d2d 25%, #3d3d3d 50%, #2d2d2d 75%);
            background-size: 200% 100%;
        }
        
        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        /* Enhanced Filter Panel */
        .filter-panel-modern {
            background: #ffffff;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }
        
        body.dark .filter-panel-modern {
            background: #1f2937;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-group label {
            font-weight: 500;
            font-size: 14px;
            color: #4b5563;
        }
        
        body.dark .filter-group label {
            color: #9ca3af;
        }
        
        .filter-group select {
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            transition: all 0.2s;
        }
        
        body.dark .filter-group select {
            background: #374151;
            border-color: #4b5563;
            color: #f3f4f6;
        }
        
        .filter-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .filter-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        
        .btn-primary, .btn-secondary {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        body.dark .btn-secondary {
            background: #374151;
            color: #f3f4f6;
        }
        
        .btn-secondary:hover {
            background: #d1d5db;
        }
        
        body.dark .btn-secondary:hover {
            background: #4b5563;
        }
        
        /* Enhanced Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .metric-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        body.dark .metric-card {
            background: #1f2937;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }
        
        .metric-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }
        
        body.dark .metric-card:hover {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }
        
        .metric-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        
        .metric-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .metric-icon.blue { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .metric-icon.green { background: rgba(34, 197, 94, 0.1); color: #22c55e; }
        .metric-icon.purple { background: rgba(168, 85, 247, 0.1); color: #a855f7; }
        .metric-icon.orange { background: rgba(249, 115, 22, 0.1); color: #f97316; }
        
        .metric-title {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        body.dark .metric-title {
            color: #9ca3af;
        }
        
        .metric-value {
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }
        
        body.dark .metric-value {
            color: #f3f4f6;
        }
        
        .metric-change {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 13px;
            font-weight: 500;
            padding: 4px 8px;
            border-radius: 6px;
        }
        
        .metric-change.positive {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
        .metric-change.negative {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }
        
        .metric-change.neutral {
            background: rgba(107, 114, 128, 0.1);
            color: #6b7280;
        }
        
        /* Chart Container */
        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 24px;
        }
        
        body.dark .chart-container {
            background: #1f2937;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .chart-title {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
        }
        
        body.dark .chart-title {
            color: #f3f4f6;
        }
        
        .chart-actions {
            display: flex;
            gap: 8px;
        }
        
        .chart-toggle {
            padding: 6px 12px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        body.dark .chart-toggle {
            background: #374151;
            border-color: #4b5563;
            color: #f3f4f6;
        }
        
        .chart-toggle.active {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }
        
        /* Budget Performance */
        .budget-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .budget-item {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        
        .budget-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.05), transparent);
            transition: left 0.5s ease;
        }
        
        .budget-item:hover::before {
            left: 100%;
        }
        
        body.dark .budget-item {
            background: #1f2937;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }
        
        body.dark .budget-item::before {
            background: linear-gradient(90deg, transparent, rgba(96, 165, 250, 0.1), transparent);
        }
        
        .budget-item:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.15);
        }
        
        body.dark .budget-item:hover {
            box-shadow: 0 8px 24px rgba(96, 165, 250, 0.2);
        }
        
        .budget-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .budget-category {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
        }
        
        body.dark .budget-category {
            color: #f3f4f6;
        }
        
        .budget-amounts {
            display: flex;
            gap: 16px;
            font-size: 14px;
            color: #6b7280;
        }
        
        body.dark .budget-amounts {
            color: #9ca3af;
        }
        
        .budget-amount-item {
            display: flex;
            flex-direction: column;
        }
        
        .budget-amount-label {
            font-size: 12px;
            margin-bottom: 2px;
        }
        
        .budget-amount-value {
            font-weight: 600;
            color: #1f2937;
        }
        
        body.dark .budget-amount-value {
            color: #f3f4f6;
        }
        
        .progress-bar-modern {
            height: 12px;
            background: #e5e7eb;
            border-radius: 6px;
            overflow: hidden;
            position: relative;
        }
        
        body.dark .progress-bar-modern {
            background: #374151;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 0.6s ease, background-color 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        .progress-fill.good { background: linear-gradient(90deg, #22c55e, #16a34a); }
        .progress-fill.warning { background: linear-gradient(90deg, #f59e0b, #d97706); }
        .progress-fill.danger { background: linear-gradient(90deg, #ef4444, #dc2626); }
        
        .budget-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .budget-status-badge.good {
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
        }
        
        .budget-status-badge.warning {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
        }
        
        .budget-status-badge.danger {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }
        
        /* Insights Panel */
        .insights-container {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 24px;
            transition: all 0.3s ease;
        }
        
        .insights-container:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }
        
        body.dark .insights-container {
            background: #1f2937;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }
        
        body.dark .insights-container:hover {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }
        
        .insight-item {
            display: flex;
            gap: 16px;
            padding: 16px;
            border-radius: 10px;
            margin-bottom: 12px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .insight-item:last-child {
            margin-bottom: 0;
        }
        
        .insight-item:hover {
            transform: translateX(4px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .insight-item.info {
            background: rgba(59, 130, 246, 0.05);
            border-left: 4px solid #3b82f6;
        }
        
        .insight-item.success {
            background: rgba(34, 197, 94, 0.05);
            border-left: 4px solid #22c55e;
        }
        
        .insight-item.warning {
            background: rgba(245, 158, 11, 0.05);
            border-left: 4px solid #f59e0b;
        }
        
        .insight-item.danger {
            background: rgba(239, 68, 68, 0.05);
            border-left: 4px solid #ef4444;
        }
        
        .insight-icon {
            font-size: 20px;
            width: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .insight-item.info .insight-icon { color: #3b82f6; }
        .insight-item.success .insight-icon { color: #22c55e; }
        .insight-item.warning .insight-icon { color: #f59e0b; }
        .insight-item.danger .insight-icon { color: #ef4444; }
        
        .insight-content {
            flex: 1;
            color: #4b5563;
            font-size: 14px;
            line-height: 1.6;
        }
        
        body.dark .insight-content {
            color: #d1d5db;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state-icon {
            font-size: 64px;
            color: #d1d5db;
            margin-bottom: 16px;
        }
        
        body.dark .empty-state-icon {
            color: #4b5563;
        }
        
        .empty-state-title {
            font-size: 20px;
            font-weight: 600;
            color: #6b7280;
            margin-bottom: 8px;
        }
        
        body.dark .empty-state-title {
            color: #9ca3af;
        }
        
        .empty-state-message {
            font-size: 14px;
            color: #9ca3af;
        }
        
        body.dark .empty-state-message {
            color: #6b7280;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
        }
        
        /* Tooltip */
        .tooltip-hint {
            position: relative;
            display: inline-flex;
            align-items: center;
            cursor: help;
        }
        
        .tooltip-hint:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 8px 12px;
            background: #1f2937;
            color: white;
            font-size: 12px;
            border-radius: 6px;
            white-space: nowrap;
            margin-bottom: 8px;
            z-index: 1000;
        }
        
        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-export {
            padding: 8px 16px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #374151;
        }
        
        body.dark .btn-export {
            background: #374151;
            border-color: #4b5563;
            color: #f3f4f6;
        }
        
        .btn-export:hover {
            background: #f3f4f6;
            border-color: #9ca3af;
        }
        
        body.dark .btn-export:hover {
            background: #4b5563;
        }
    </style>
</head>
<body class="<?php echo htmlspecialchars($settings['theme']); ?>">
    <nav class="navbar">
        <div class="logo">PennyPulse</div>
        <ul class="nav-links">
            <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="add_expense.php"><i class="fas fa-plus"></i> Manage Expenses</a></li>
            <li><a href="view_expenses.php"><i class="fas fa-list"></i> View Expenses</a></li>
            <li><a href="set_budget.php"><i class="fas fa-wallet"></i> Budgets</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-pie"></i> Reports</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
        </ul>
        <div class="user-profile">
            <span class="avatar" data-username="<?php echo htmlspecialchars($user['username']); ?>"><?php echo htmlspecialchars(strtoupper($user['username'][0])); ?></span>
            <?php include 'includes/notifications_nav.php'; ?>
            <button id="theme-toggle" class="theme-toggle" data-theme-text="<?php echo $settings['theme'] === 'light' ? 'Dark Mode' : 'Light Mode'; ?>">
                <i class="fas <?php echo $settings['theme'] === 'light' ? 'fa-moon' : 'fa-sun'; ?>"></i>
            </button>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </nav>

    <div class="reports-container">
        <h1 style="margin-bottom: 24px; font-size: 32px; font-weight: 700;">
            <i class="fas fa-chart-line" style="color: #3b82f6;"></i> Reports & Analytics
        </h1>

        <!-- Filter Panel -->
        <div class="filter-panel-modern">
            <form method="GET" action="reports.php" id="filterForm">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label for="time_period">
                            <i class="fas fa-calendar"></i> Current Period
                        </label>
                        <select id="time_period" name="time_period" onchange="document.getElementById('filterForm').submit()">
                            <option value="last_7_days" <?php echo $time_period === 'last_7_days' ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="last_30_days" <?php echo $time_period === 'last_30_days' ? 'selected' : ''; ?>>Last 30 Days</option>
                            <option value="this_week" <?php echo $time_period === 'this_week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="last_week" <?php echo $time_period === 'last_week' ? 'selected' : ''; ?>>Last Week</option>
                            <option value="this_month" <?php echo $time_period === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                            <option value="last_month" <?php echo $time_period === 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                            <option value="this_year" <?php echo $time_period === 'this_year' ? 'selected' : ''; ?>>This Year</option>
                            <option value="last_year" <?php echo $time_period === 'last_year' ? 'selected' : ''; ?>>Last Year</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="comparison_period">
                            <i class="fas fa-exchange-alt"></i> Compare With
                        </label>
                        <select id="comparison_period" name="comparison_period" onchange="document.getElementById('filterForm').submit()">
                            <option value="last_7_days" <?php echo $comparison_period === 'last_7_days' ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="last_30_days" <?php echo $comparison_period === 'last_30_days' ? 'selected' : ''; ?>>Last 30 Days</option>
                            <option value="this_week" <?php echo $comparison_period === 'this_week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="last_week" <?php echo $comparison_period === 'last_week' ? 'selected' : ''; ?>>Last Week</option>
                            <option value="this_month" <?php echo $comparison_period === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                            <option value="last_month" <?php echo $comparison_period === 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                            <option value="this_year" <?php echo $comparison_period === 'this_year' ? 'selected' : ''; ?>>This Year</option>
                            <option value="last_year" <?php echo $comparison_period === 'last_year' ? 'selected' : ''; ?>>Last Year</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="category"><i class="fas fa-tag"></i> Category</label>
                        <select id="category" name="category">
                            <option value="">All Categories</option>
                            <?php foreach ($category_map as $category_id => $category_name): ?>
                                <option value="<?php echo (int)$category_id; ?>" <?php echo $category_filter === (int)$category_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($category_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="payment_method"><i class="fas fa-credit-card"></i> Payment Method</label>
                        <select id="payment_method" name="payment_method">
                            <option value="">All Payment Methods</option>
                            <?php foreach ($payment_methods as $payment_method): ?>
                                <option value="<?php echo htmlspecialchars($payment_method); ?>" <?php echo $payment_method_filter === $payment_method ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $payment_method))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="expense_type"><i class="fas fa-list"></i> Expense Type</label>
                        <select id="expense_type" name="expense_type">
                            <option value="all" <?php echo $expense_type_filter === 'all' ? 'selected' : ''; ?>>All Expenses</option>
                            <option value="one_time" <?php echo $expense_type_filter === 'one_time' ? 'selected' : ''; ?>>One-Time</option>
                            <option value="recurring" <?php echo $expense_type_filter === 'recurring' ? 'selected' : ''; ?>>Recurring</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="merchant"><i class="fas fa-store"></i> Merchant</label>
                        <input type="search" id="merchant" name="merchant" value="<?php echo htmlspecialchars($merchant_filter); ?>" maxlength="255" placeholder="Search merchant">
                    </div>

                    <div class="filter-group">
                        <label for="min_amount"><i class="fas fa-arrow-down"></i> Minimum Amount</label>
                        <input type="number" id="min_amount" name="min_amount" value="<?php echo $min_amount_filter !== null ? htmlspecialchars((string)$min_amount_filter) : ''; ?>" min="0" step="0.01" placeholder="0.00">
                    </div>

                    <div class="filter-group">
                        <label for="max_amount"><i class="fas fa-arrow-up"></i> Maximum Amount</label>
                        <input type="number" id="max_amount" name="max_amount" value="<?php echo $max_amount_filter !== null ? htmlspecialchars((string)$max_amount_filter) : ''; ?>" min="0" step="0.01" placeholder="No limit">
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="button" class="btn-secondary" onclick="clearFilters()">
                        <i class="fas fa-times"></i> Clear Filters
                    </button>
                    <button type="button" class="btn-export" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                    <button type="button" class="btn-export" onclick="exportToCSV()">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                </div>
            </form>
        </div>

        <!-- Summary Metrics -->
        <div class="summary-grid">
            <div class="metric-card">
                <div class="metric-card-header">
                    <div>
                        <div class="metric-title">Total Spending</div>
                        <div class="metric-value">KES&nbsp;<?php echo number_format($current_metrics['total'], 2); ?></div>
                        <?php if ($spending_change != 0): ?>
                            <div class="metric-change <?php echo $spending_change > 0 ? 'positive' : 'negative'; ?>">
                                <i class="fas fa-arrow-<?php echo $spending_change > 0 ? 'up' : 'down'; ?>"></i>
                                <?php echo number_format(abs($spending_change), 1); ?>%
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="metric-icon blue">
                        <i class="fas fa-wallet"></i>
                    </div>
                </div>
            </div>
            
            <div class="metric-card">
                <div class="metric-card-header">
                    <div>
                        <div class="metric-title">Avg. Daily Spending</div>
                        <div class="metric-value">KES&nbsp;<?php echo number_format($avg_daily, 2); ?></div>
                        <div class="metric-change neutral">
                            <i class="fas fa-calendar-day"></i>
                            <?php echo $days_count; ?> days
                        </div>
                    </div>
                    <div class="metric-icon green">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
            </div>
            
            <div class="metric-card">
                <div class="metric-card-header">
                    <div>
                        <div class="metric-title">Transactions</div>
                        <div class="metric-value"><?php echo $current_metrics['transaction_count']; ?></div>
                        <?php if ($transaction_change != 0): ?>
                            <div class="metric-change <?php echo $transaction_change > 0 ? 'positive' : 'negative'; ?>">
                                <i class="fas fa-arrow-<?php echo $transaction_change > 0 ? 'up' : 'down'; ?>"></i>
                                <?php echo number_format(abs($transaction_change), 1); ?>%
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="metric-icon purple">
                        <i class="fas fa-receipt"></i>
                    </div>
                </div>
            </div>
            
            <div class="metric-card">
                <div class="metric-card-header">
                    <div>
                        <div class="metric-title">Budget Status</div>
                        <div class="metric-value">
                            <?php 
                            $overall_utilization = $total_budgeted > 0 ? ($total_spent_budgeted / $total_budgeted) * 100 : 0;
                            echo number_format($overall_utilization, 1); ?>%
                        </div>
                        <div class="metric-change <?php 
                            echo $overall_utilization >= 100 ? 'positive' : ($overall_utilization >= 90 ? 'neutral' : 'negative'); 
                        ?>">
                            <?php 
                            if ($overall_utilization >= 100) {
                                echo '<i class="fas fa-exclamation-circle"></i> Over Budget';
                            } elseif ($overall_utilization >= 90) {
                                echo '<i class="fas fa-exclamation-triangle"></i> Near Limit';
                            } else {
                                echo '<i class="fas fa-check-circle"></i> On Track';
                            }
                            ?>
                        </div>
                    </div>
                    <div class="metric-icon orange">
                        <i class="fas fa-bullseye"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Insights Panel -->
        <div class="insights-container">
            <div class="chart-header">
                <h2 class="chart-title"><i class="fas fa-lightbulb"></i> Spending Insights</h2>
            </div>
            <?php foreach ($insights as $insight): ?>
                <div class="insight-item <?php echo $insight['type']; ?>">
                    <div class="insight-icon">
                        <i class="fas <?php echo $insight['icon']; ?>"></i>
                    </div>
                    <div class="insight-content">
                        <?php echo $insight['message']; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Spending Trend Chart -->
        <div class="chart-container">
            <div class="chart-header">
                <h2 class="chart-title"><i class="fas fa-chart-area"></i> Spending Trend Analysis</h2>
                <div class="chart-actions">
                    <button class="chart-toggle active" data-chart="line">
                        <i class="fas fa-chart-line"></i> Line
                    </button>
                    <button class="chart-toggle" data-chart="bar">
                        <i class="fas fa-chart-bar"></i> Bar
                    </button>
                </div>
            </div>
            <?php if (empty($trend_data)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="empty-state-title">No Data Available</div>
                    <div class="empty-state-message">Start tracking expenses to see your spending trends</div>
                </div>
            <?php else: ?>
                <canvas id="trendChart" height="80"></canvas>
            <?php endif; ?>
        </div>

        <!-- Category Breakdown -->
        <div class="chart-container">
            <div class="chart-header">
                <h2 class="chart-title"><i class="fas fa-chart-pie"></i> Category Breakdown</h2>
            </div>
            <?php if (empty($current_metrics['category_breakdown'])): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-chart-pie"></i></div>
                    <div class="empty-state-title">No Categories Yet</div>
                    <div class="empty-state-message">Add expenses to see category breakdown</div>
                </div>
            <?php else: ?>
                <div style="max-width: 500px; margin: 0 auto;">
                    <canvas id="categoryChart"></canvas>
                </div>
            <?php endif; ?>
        </div>

        <!-- Budget Performance Dashboard -->
        <?php if (!empty($budget_status)): ?>
        <div class="chart-container">
            <div class="chart-header">
                <h2 class="chart-title"><i class="fas fa-tachometer-alt"></i> Budget Performance Dashboard</h2>
            </div>
            <div class="budget-list">
                <?php foreach ($budget_status as $category => $status): ?>
                    <div class="budget-item">
                        <div class="budget-header">
                            <div class="budget-category">
                                <i class="fas fa-tag"></i> <?php echo htmlspecialchars($category); ?>
                            </div>
                            <div class="budget-status-badge <?php echo $status['status'] === 'over' ? 'danger' : ($status['status'] === 'warning' ? 'warning' : 'good'); ?>">
                                <?php 
                                if ($status['status'] === 'over') {
                                    echo '<i class="fas fa-times-circle"></i> Over Budget';
                                } elseif ($status['status'] === 'warning') {
                                    echo '<i class="fas fa-exclamation-circle"></i> Warning';
                                } else {
                                    echo '<i class="fas fa-check-circle"></i> Good';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <div class="budget-amounts">
                            <div class="budget-amount-item">
                                <span class="budget-amount-label">Spent</span>
                                <span class="budget-amount-value">KES&nbsp;<?php echo number_format($status['spent'], 2); ?></span>
                            </div>
                            <div class="budget-amount-item">
                                <span class="budget-amount-label">Budget</span>
                                <span class="budget-amount-value">KES&nbsp;<?php echo number_format($status['budget'], 2); ?></span>
                            </div>
                            <div class="budget-amount-item">
                                <span class="budget-amount-label">Remaining</span>
                                <span class="budget-amount-value" style="color: <?php echo $status['remaining'] < 0 ? '#ef4444' : '#22c55e'; ?>">
                                    KES&nbsp;<?php echo number_format($status['remaining'], 2); ?>
                                </span>
                            </div>
                            <div class="budget-amount-item">
                                <span class="budget-amount-label">Used</span>
                                <span class="budget-amount-value"><?php echo number_format($status['utilization'], 1); ?>%</span>
                            </div>
                        </div>
                        
                        <div class="progress-bar-modern">
                            <div class="progress-fill <?php echo $status['status'] === 'over' ? 'danger' : ($status['status'] === 'warning' ? 'warning' : 'good'); ?>" 
                                 style="width: <?php echo min($status['utilization'], 100); ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <?php include 'footer.html'; ?>

    <script src="js/theme-toggle.js?v=<?php echo filemtime('js/theme-toggle.js'); ?>"></script>
    <script src="js/notifications.js?v=<?php echo filemtime('js/notifications.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const currentPage = window.location.pathname.split('/').pop() || 'index.php';
            const pageMapping = {
                'index.php': 'Dashboard',
                'dashboard.php': 'Dashboard',
                'add_expense.php': 'Manage Expenses',
                'edit_expenses.php': 'Manage Expenses',
                'view_expenses.php': 'View Expenses',
                'set_budget.php': 'Budgets',
                'reports.php': 'Reports',
                'settings.php': 'Settings'
            };
            
            const navLinks = document.querySelectorAll('.nav-links a');
            navLinks.forEach(link => {
                const linkText = link.textContent.trim();
                const mappedPage = pageMapping[currentPage];
                
                if (linkText === mappedPage) {
                    link.classList.add('active');
                }
            });
        });

        // Initialize charts
        let trendChart, categoryChart;
        
        const isDarkMode = () => document.body.classList.contains('dark');
        
        const getChartColors = () => {
            const dark = isDarkMode();
            return {
                primary: dark ? '#60a5fa' : '#3b82f6',
                background: dark ? 'rgba(96, 165, 250, 0.1)' : 'rgba(59, 130, 246, 0.1)',
                text: dark ? '#d1d5db' : '#374151',
                grid: dark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)',
                categoryColors: dark 
                    ? ['#ef4444', '#60a5fa', '#fbbf24', '#34d399', '#a78bfa', '#f472b6', '#fb923c', '#38bdf8']
                    : ['#ef4444', '#3b82f6', '#f59e0b', '#22c55e', '#8b5cf6', '#ec4899', '#f97316', '#0ea5e9']
            };
        };
        
        <?php if (!empty($trend_data)): ?>
        // Trend Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        const colors = getChartColors();
        
        trendChart = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trend_labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    label: 'Daily Spending',
                    data: <?php echo json_encode($trend_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>,
                    borderColor: colors.primary,
                    backgroundColor: colors.background,
                    fill: true,
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: colors.primary
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: isDarkMode() ? '#1f2937' : '#ffffff',
                        titleColor: colors.text,
                        bodyColor: colors.text,
                        borderColor: colors.grid,
                        borderWidth: 1,
                        padding: 12,
                        displayColors: false,
                        callbacks: {
                            label: (context) => `KES ${context.raw.toFixed(2)}`
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: colors.grid,
                            drawBorder: false
                        },
                        ticks: {
                            color: colors.text,
                            font: { size: 11 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: colors.grid,
                            drawBorder: false
                        },
                        ticks: {
                            color: colors.text,
                            font: { size: 11 },
                            callback: (value) => 'KES ' + value
                        }
                    }
                }
            }
        });
        
        // Chart type toggle
        document.querySelectorAll('.chart-toggle').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.chart-toggle').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const type = this.dataset.chart;
                trendChart.config.type = type;
                trendChart.update();
            });
        });
        <?php endif; ?>
        
        <?php if (!empty($current_metrics['category_breakdown'])): ?>
        // Category Chart
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        
        categoryChart = new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_keys($current_metrics['category_breakdown']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($current_metrics['category_breakdown']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>,
                    backgroundColor: colors.categoryColors,
                    borderWidth: 2,
                    borderColor: isDarkMode() ? '#1f2937' : '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: colors.text,
                            padding: 15,
                            font: { size: 12 }
                        }
                    },
                    tooltip: {
                        backgroundColor: isDarkMode() ? '#1f2937' : '#ffffff',
                        titleColor: colors.text,
                        bodyColor: colors.text,
                        borderColor: colors.grid,
                        borderWidth: 1,
                        padding: 12,
                        callbacks: {
                            label: (context) => {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return `${label}: KES ${value.toFixed(2)} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Update charts on theme change
        function updateChartsTheme() {
            const colors = getChartColors();
            
            if (trendChart) {
                trendChart.data.datasets[0].borderColor = colors.primary;
                trendChart.data.datasets[0].backgroundColor = colors.background;
                trendChart.data.datasets[0].pointBackgroundColor = colors.primary;
                trendChart.options.scales.x.grid.color = colors.grid;
                trendChart.options.scales.y.grid.color = colors.grid;
                trendChart.options.scales.x.ticks.color = colors.text;
                trendChart.options.scales.y.ticks.color = colors.text;
                trendChart.update('none');
            }
            
            if (categoryChart) {
                categoryChart.data.datasets[0].backgroundColor = colors.categoryColors;
                categoryChart.data.datasets[0].borderColor = isDarkMode() ? '#1f2937' : '#ffffff';
                categoryChart.options.plugins.legend.labels.color = colors.text;
                categoryChart.update('none');
            }
        }
        
        // Listen for theme changes
        const observer = new MutationObserver(() => updateChartsTheme());
        observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
        
        // Clear filters function
        function clearFilters() {
            window.location.href = 'reports.php';
        }
        
        // Export functions
function exportToPDF() {
    window.print();
}

function exportToCSV() {
    const reportMeta = {
        period: <?php echo json_encode($time_period, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        startDate: <?php echo json_encode($start_date, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        endDate: <?php echo json_encode($end_date, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        totalSpending: <?php echo json_encode($current_metrics['total']); ?>,
        avgDaily: <?php echo json_encode($avg_daily); ?>,
        totalTransactions: <?php echo json_encode($current_metrics['transaction_count']); ?>,
        budgetUtilization: <?php echo json_encode($overall_utilization ?? 0); ?>,
        category: <?php echo json_encode($category_filter !== null ? ($category_map[$category_filter] ?? 'Unknown') : 'All'); ?>,
        paymentMethod: <?php echo json_encode($payment_method_filter !== '' ? ucwords(str_replace('_', ' ', $payment_method_filter)) : 'All'); ?>,
        expenseType: <?php echo json_encode(ucwords(str_replace('_', ' ', $expense_type_filter))); ?>,
        merchant: <?php echo json_encode($merchant_filter !== '' ? $merchant_filter : 'All'); ?>,
        minAmount: <?php echo json_encode($min_amount_filter !== null ? $min_amount_filter : 'No minimum'); ?>,
        maxAmount: <?php echo json_encode($max_amount_filter !== null ? $max_amount_filter : 'No maximum'); ?>
    };

    const expenses = <?php echo json_encode(
        array_map(function($e) use ($category_map) {
            return [
                'date'           => $e['date'],
                'category'       => $category_map[$e['category']] ?? 'Uncategorized',
                'amount'         => $e['amount'],
                'payment_method' => $e['payment_method'] ?? 'N/A',
                'description'    => $e['description'] ?? ''
            ];
        }, $current_expenses),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
    ); ?>;

    const categoryBreakdown = <?php echo json_encode(
        array_map(function($amount) use ($current_metrics) {
            return [
                'amount'     => $amount,
                'percentage' => $current_metrics['total'] > 0
                    ? round(($amount / $current_metrics['total']) * 100, 2)
                    : 0
            ];
        }, $current_metrics['category_breakdown']),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
    ); ?>;

    const budgetPerformance = <?php echo json_encode(
        array_map(function($status) {
            return [
                'spent'       => $status['spent'],
                'budget'      => $status['budget'],
                'remaining'   => $status['remaining'],
                'utilization' => round($status['utilization'], 2),
                'status'      => ucfirst($status['status'])
            ];
        }, $budget_status),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
    ); ?>;

    let csv = 'EXPENSE REPORT\n';
    csv += 'Period: ' + reportMeta.period + '\n';
    csv += 'Date Range: ' + reportMeta.startDate + ' to ' + reportMeta.endDate + '\n';
    csv += 'Filters: Category=' + reportMeta.category + '; Payment Method=' + reportMeta.paymentMethod + '; Expense Type=' + reportMeta.expenseType + '; Merchant=' + reportMeta.merchant + '; Amount=' + reportMeta.minAmount + ' to ' + reportMeta.maxAmount + '\n';
    csv += 'Generated: ' + new Date().toLocaleString() + '\n\n';

    csv += 'SUMMARY\n';
    csv += 'Total Spending,' + reportMeta.totalSpending + '\n';
    csv += 'Average Daily Spending,' + reportMeta.avgDaily + '\n';
    csv += 'Total Transactions,' + reportMeta.totalTransactions + '\n';
    csv += 'Budget Utilization,' + reportMeta.budgetUtilization + '%\n\n';

    csv += 'DETAILED EXPENSES\n';
    csv += 'Date,Category,Amount,Payment Method,Description\n';
    expenses.forEach(e => {
        csv += [e.date, e.category, e.amount, e.payment_method, '"' + e.description.replace(/"/g, '""') + '"'].join(',') + '\n';
    });

    csv += '\nCATEGORY BREAKDOWN\n';
    csv += 'Category,Amount,Percentage\n';
    Object.entries(categoryBreakdown).forEach(([category, data]) => {
        csv += category + ',' + data.amount + ',' + data.percentage + '%\n';
    });

    csv += '\nBUDGET PERFORMANCE\n';
    csv += 'Category,Spent,Budget,Remaining,Utilization,Status\n';
    Object.entries(budgetPerformance).forEach(([category, data]) => {
        csv += category + ',' + data.spent + ',' + data.budget + ',' + data.remaining + ',' + data.utilization + '%,' + data.status + '\n';
    });

    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'expense-report-' + reportMeta.period + '-' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}
    </script>
</body>
</html>
