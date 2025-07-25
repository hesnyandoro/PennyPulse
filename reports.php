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
    $settings = ['theme' => 'light'];
}
require_once 'config.php';

// Default settings
$theme = $settings['theme'] ?? 'light';
$language = 'en';

// Fetch category names and create mapping
$category_map = [];
$stmt = $conn->prepare("SELECT id, name FROM categories WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $category_map[$row['id']] = $row['name'];
}

// Fetch categories and payment methods for filters
$categories = [];
$payment_methods = [];
$stmt = $conn->prepare("SELECT DISTINCT category_id FROM expenses WHERE user_id = ? UNION SELECT DISTINCT category_id FROM recurring_expenses WHERE user_id = ?");
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if (isset($category_map[$row['category_id']])) {
        $categories[] = $category_map[$row['category_id']];
    }
}

$stmt = $conn->prepare("SELECT DISTINCT payment_method FROM expenses WHERE user_id = ? UNION SELECT DISTINCT payment_method FROM recurring_expenses WHERE user_id = ?");
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if (!empty($row['payment_method'])) {
        $payment_methods[] = $row['payment_method'];
    }
}

// Fetch budgets
$budgets = [];
$stmt = $conn->prepare("SELECT category, budget_amount FROM budgets WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $budgets[$row['category']] = $row['budget_amount'];
}

// Default filter values
$time_period = 'this_month';
$selected_category = 'all';
$selected_payment_method = 'all';
$start_date = '';
$end_date = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $time_period = $_POST['time_period'] ?? 'this_month';
    $selected_category = $_POST['category'] ?? 'all';
    $selected_payment_method = $_POST['payment_method'] ?? 'all';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
}

// Determine date range based on time period
switch ($time_period) {
    case 'this_week':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = date('Y-m-d');
        break;
    case 'last_week':
        $start_date = date('Y-m-d', strtotime('monday last week'));
        $end_date = date('Y-m-d', strtotime('sunday last week'));
        break;
    case 'last_month':
        $start_date = date('Y-m-01', strtotime('last month'));
        $end_date = date('Y-m-t', strtotime('last month'));
        break;
    case 'this_month':
    default:
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-d');
        break;
    case 'custom':
        if (!strtotime($start_date) || !strtotime($end_date)) {
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-d');
        }
        break;
}

// Build the expenses query with filters
$query = "SELECT amount, category_id AS category, payment_method, date FROM expenses WHERE user_id = ? AND date BETWEEN ? AND ?";
$params = [$user_id, $start_date, $end_date];
$types = "iss";

if ($selected_category !== 'all' && isset($category_map[array_search($selected_category, $category_map)])) {
    $selected_category_id = array_search($selected_category, $category_map);
    $query .= " AND category_id = ?";
    $params[] = $selected_category_id;
    $types .= "i";
}

if ($selected_payment_method !== 'all') {
    $query .= " AND payment_method = ?";
    $params[] = $selected_payment_method;
    $types .= "s";
}

$query .= " ORDER BY date ASC";
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch and calculate recurring expenses
$recurring_expenses = [];
$query = "SELECT amount, category_id, payment_method, start_date, end_date, frequency FROM recurring_expenses WHERE user_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)";
$stmt = $conn->prepare($query);
$stmt->bind_param("iss", $user_id, $end_date, $start_date);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $start = new DateTime(max($start_date, $row['start_date']));
    $end = new DateTime(min($end_date, $row['end_date'] ?? $end_date));
    if ($start > $end) continue; // Skip if no overlap

    $interval_map = [
        'daily' => 'P1D',
        'weekly' => 'P7D',
        'monthly' => 'P1M',
        'yearly' => 'P1Y'
    ];
    $interval = new DateInterval($interval_map[$row['frequency']]);
    $period = new DatePeriod($start, $interval, $end);

    $count = 0;
    foreach ($period as $date) {
        $count++;
    }
    if ($count > 0) {
        $recurring_expenses[] = [
            'amount' => $row['amount'] * $count,
            'category' => $row['category_id'],
            'payment_method' => $row['payment_method'],
            'date' => $start->format('Y-m-d') // Use start date for grouping
        ];
    }
}

// Merge one-time and recurring expenses
$all_expenses = array_merge($expenses, $recurring_expenses);

// Calculate summary metrics
$total_spent = 0;
$category_breakdown = [];
$daily_expenses = []; // Initialize here
$days = 0; // Initialize here

// Generate date range and initialize daily_expenses
$labels = [];
$current_date = strtotime($start_date);
$end_timestamp = strtotime($end_date);

while ($current_date <= $end_timestamp) {
    $date_str = date('Y-m-d', $current_date);
    $labels[] = $date_str;
    $daily_expenses[$date_str] = 0;
    $current_date = strtotime('+1 day', $current_date);
}
$days = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1; // Calculate days

// Map category IDs to names for breakdown
foreach ($all_expenses as $expense) {
    $category_id = $expense['category'];
    $category_name = $category_map[$category_id] ?? $category_id; // Fallback to ID if name not found
    $total_spent += $expense['amount'];
    $date = $expense['date'];
    $daily_expenses[$date] += $expense['amount'];

    if (!isset($category_breakdown[$category_name])) {
        $category_breakdown[$category_name] = 0;
    }
    $category_breakdown[$category_name] += $expense['amount'];
}

$average_daily = $days > 0 ? $total_spent / $days : 0;
$top_category = !empty($category_breakdown) ? array_keys($category_breakdown, max($category_breakdown))[0] : 'N/A';
$line_data = array_values($daily_expenses); // Safe to use now

// Calculate budget status
$overall_budget_status = 'N/A';
$total_budgeted = 0;
$total_spent_in_budgeted_categories = 0;
foreach ($budgets as $category => $budget) {
    $category_id = array_search($category, $category_map); // Reverse map to ID if needed
    $spent = $category_breakdown[$category] ?? 0;
    $total_budgeted += $budget;
    $total_spent_in_budgeted_categories += $spent;
}
if ($total_budgeted > 0) {
    $percent_used = ($total_spent_in_budgeted_categories / $total_budgeted) * 100;
    if ($percent_used >= 100) {
        $overall_budget_status = 'Over Budget';
    } elseif ($percent_used >= 90) {
        $overall_budget_status = 'Approaching Limit';
    } else {
        $overall_budget_status = 'Under Budget';
    }
}

// Generate insights
$insights = [];
if ($top_category !== 'N/A' && $total_spent > 0) {
    if ($average_daily > 100) {
        $insights[] = "Your average daily spending is high ($" . number_format($average_daily, 2) . "). Consider reviewing discretionary expenses.";
    }
    if (($category_breakdown[$top_category] / $total_spent) > 0.5) {
        $insights[] = "Over 50% of your spending is in $top_category. Diversify your spending or set a stricter budget.";
    }
    if ($total_spent_in_budgeted_categories > $total_budgeted) {
        $insights[] = "You’ve exceeded your overall budget by $" . number_format($total_spent_in_budgeted_categories - $total_budgeted, 2) . ". Reduce spending in over-budget categories.";
    }
}
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($language); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Reports - Expense Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary: #4A90E2;
            --secondary: #15803d;
            --neutral: #FFFFFF;
            --neutral-accent: #E5E7EB;
            --glow: #2DD4BF;
            --warning: #EF4444;
            --dark-bg: #1F2937;
            --dark-text: #D1D5DB;
            --background: <?php echo $theme === 'dark' ? '#1F2937' : '#FFFFFF'; ?>;
            --text: <?php echo $theme === 'dark' ? '#D1D5DB' : '#333'; ?>;
            --card-bg: <?php echo $theme === 'dark' ? '#1F2937' : '#FFFFFF'; ?>;
            --filter-bg: <?php echo $theme === 'dark' ? '#1F2937' : '#FFFFFF'; ?>;
            --border: <?php echo $theme === 'dark' ? '#D1D5DB' : '#E5E7EB'; ?>;
            --table-header-bg: <?php echo $theme === 'dark' ? '#374151' : '#E5E7EB'; ?>;
            --insight-bg: <?php echo $theme === 'dark' ? '#374151' : '#E5E7EB'; ?>;
            --input-bg: <?php echo $theme === 'dark' ? '#374151' : '#FFFFFF'; ?>;
            --input-border: <?php echo $theme === 'dark' ? '#4B5563' : '#D1D5DB'; ?>;
            --input-text: <?php echo $theme === 'dark' ? '#D1D5DB' : '#333'; ?>;
            --button-bg: #15803d;
            --button-hover-bg: #166534;
            --progress-bg: <?php echo $theme === 'dark' ? '#4B5563' : '#E5E7EB'; ?>;
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

        .theme-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 15px;
            background-color: var(--button-bg);
            color: var(--neutral);
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: background-color 0.3s;
        }

        .theme-toggle:hover {
            background-color: var(--button-hover-bg);
        }

        .bento-card {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s, background-color 0.3s;
        }

        .bento-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2), 0 0 10px var(--glow);
        }

        h1, h2 {
            margin-top: 0;
            font-weight: 600;
        }

        h1 {
            font-size: 28px;
            color: var(--primary);
        }

        h2 {
            font-size: 18px;
            color: var(--primary);
        }

        .filter-panel {
            position: sticky;
            top: 60px;
            background: var(--filter-bg);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 1000;
            transition: background-color 0.3s;
        }

        .filter-panel.collapsed .filter-content {
            display: none;
        }

        .filter-toggle {
            cursor: pointer;
            font-weight: 500;
            margin-bottom: 10px;
        }

        .filter-content {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }

        label {
            font-weight: 500;
            color: var(--neutral-accent);
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
        }

        button:hover {
            background-color: var(--button-hover-bg);
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .summary-card {
            text-align: center;
        }

        .summary-card p {
            font-size: 24px;
            font-weight: 600;
            color: var(--primary);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }

        th {
            background-color: var(--table-header-bg);
            font-weight: 600;
            transition: background-color 0.3s;
        }

        tr:nth-child(even) {
            background-color: #f9fafb;
        }

        canvas {
            max-width: 100%;
            margin-bottom: 20px;
        }

        .progress-bar {
            width: 100%;
            background-color: var(--progress-bg);
            border-radius: 5px;
            overflow: hidden;
            height: 10px;
            margin-top: 5px;
            transition: background-color 0.3s;
        }

        .progress {
            height: 100%;
            transition: width 0.3s;
        }

        .progress.green {
            background-color: var(--secondary);
        }

        .progress.yellow {
            background-color: #e67e22;
        }

        .progress.red {
            background-color: var(--warning);
        }

        .insight {
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 5px;
            background-color: var(--insight-bg);
            font-size: 14px;
            transition: background-color 0.3s;
        }
    </style>
</head>
<body class="<?php echo $theme; ?>">
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
        <h1>Expense Reports & Insights</h1>

        <!-- Filter Controls -->
        <div class="filter-panel" id="filterPanel">
            <div class="filter-toggle" onclick="toggleFilters()">Filters ▼</div>
            <div class="filter-content">
                <form method="POST" action="reports.php">
                    <label for="time_period">Time Period:</label>
                    <select id="time_period" name="time_period" onchange="toggleCustomRange()">
                        <option value="this_week" <?php echo $time_period === 'this_week' ? 'selected' : ''; ?>>This Week</option>
                        <option value="last_week" <?php echo $time_period === 'last_week' ? 'selected' : ''; ?>>Last Week</option>
                        <option value="this_month" <?php echo $time_period === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                        <option value="last_month" <?php echo $time_period === 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                        <option value="custom" <?php echo $time_period === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                    </select>

                    <label for="category">Category:</label>
                    <select id="category" name="category">
                        <option value="all">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $selected_category === $category ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="payment_method">Payment Method:</label>
                    <select id="payment_method" name="payment_method">
                        <option value="all">All Payment Methods</option>
                        <?php foreach ($payment_methods as $method): ?>
                            <option value="<?php echo htmlspecialchars($method); ?>" <?php echo $selected_payment_method === $method ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($method); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div id="customRange" style="display: <?php echo $time_period === 'custom' ? 'block' : 'none'; ?>;">
                        <label for="start_date">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                        <label for="end_date">End Date:</label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>

                    <button type="submit">Apply Filters</button>
                </form>
            </div>
        </div>

        <!-- Summary Metrics -->
        <div class="summary-cards">
            <div class="bento-card summary-card">
                <h2>Total Spent</h2>
                <p>$<?php echo number_format($total_spent, 2); ?></p>
            </div>
            <div class="bento-card summary-card">
                <h2>Average Daily Spending</h2>
                <p>$<?php echo number_format($average_daily, 2); ?></p>
            </div>
            <div class="bento-card summary-card">
                <h2>Top Category</h2>
                <p><?php echo htmlspecialchars($top_category !== 'N/A' ? $top_category : 'N/A'); ?></p>
            </div>
            <div class="bento-card summary-card">
                <h2>Budget Status</h2>
                <p><?php echo htmlspecialchars($overall_budget_status); ?></p>
            </div>
        </div>

        <!-- Visual Reports -->
        <div class="bento-card">
            <h2>Spending Patterns</h2>
            <?php if (!empty($category_breakdown)): ?>
                <canvas id="categoryPieChart"></canvas>
                <canvas id="expenseLineGraph"></canvas>
            <?php else: ?>
                <p>No data to display visualizations.</p>
            <?php endif; ?>
        </div>

        <!-- Budget against Spending -->
        <div class="bento-card">
            <h2>Budget against Spending</h2>
            <?php if (empty($category_breakdown)): ?>
                <p>No expenses found for the selected filters.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Spent ($)</th>
                            <th>Budget ($)</th>
                            <th>Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($category_breakdown as $category => $amount): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($category); ?></td>
                                <td><?php echo number_format($amount, 2); ?></td>
                                <td>
                                    <?php
                                    if (isset($budgets[$category])) {
                                        echo number_format($budgets[$category], 2);
                                    } else {
                                        echo 'No budget set';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if (isset($budgets[$category])): ?>
                                        <?php
                                        $budget = $budgets[$category];
                                        $percent_used = $budget > 0 ? ($amount / $budget) * 100 : 0;
                                        $percent_used = min($percent_used, 100);
                                        $progress_class = $percent_used >= 100 ? 'red' : ($percent_used >= 90 ? 'yellow' : 'green');
                                        ?>
                                        <div class="progress-bar">
                                            <div class="progress <?php echo $progress_class; ?>" style="width: <?php echo $percent_used; ?>%;"></div>
                                        </div>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Insights and Recommendations -->
        <div class="bento-card">
            <h2>Insights and Recommendations</h2>
            <?php if (empty($insights)): ?>
                <p>No insights available for the selected filters.</p>
            <?php else: ?>
                <?php foreach ($insights as $insight): ?>
                    <div class="insight"><?php echo htmlspecialchars($insight); ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    </div>
        
        <!-- Footer -->
        <?php if (!in_array(basename($_SERVER['PHP_SELF']), ['login.php', 'register.php', 'logout.php'])) {
            include 'footer.html';
        } ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        function toggleFilters() {
            const panel = document.getElementById('filterPanel');
            const content = panel.querySelector('.filter-content');
            panel.classList.toggle('collapsed');
            content.style.display = panel.classList.contains('collapsed') ? 'none' : 'flex';
        }

        function toggleCustomRange() {
            const timePeriod = document.getElementById('time_period').value;
            const customRange = document.getElementById('customRange');
            customRange.style.display = timePeriod === 'custom' ? 'block' : 'none';
        }

        function toggleTheme() {
            const body = document.body;
            const currentTheme = localStorage.getItem('theme') || '<?php echo $theme; ?>';
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';

            localStorage.setItem('theme', newTheme);
            body.classList.remove('light', 'dark');
            body.classList.add(newTheme);
            applyTheme(newTheme);
        }

        function applyTheme(theme) {
            const body = document.body;
            if (theme === 'dark') {
                body.style.setProperty('--background', '#1F2937');
                body.style.setProperty('--text', '#D1D5DB');
                body.style.setProperty('--card-bg', '#1F2937');
                body.style.setProperty('--filter-bg', '#1F2937');
                body.style.setProperty('--border', '#D1D5DB');
                body.style.setProperty('--table-header-bg', '#374151');
                body.style.setProperty('--insight-bg', '#374151');
                body.style.setProperty('--input-bg', '#374151');
                body.style.setProperty('--input-border', '#4B5563');
                body.style.setProperty('--input-text', '#D1D5DB');
                body.style.setProperty('--progress-bg', '#4B5563');
            } else {
                body.style.setProperty('--background', '#FFFFFF');
                body.style.setProperty('--text', '#333');
                body.style.setProperty('--card-bg', '#FFFFFF');
                body.style.setProperty('--filter-bg', '#FFFFFF');
                body.style.setProperty('--border', '#E5E7EB');
                body.style.setProperty('--table-header-bg', '#E5E7EB');
                body.style.setProperty('--insight-bg', '#E5E7EB');
                body.style.setProperty('--input-bg', '#FFFFFF');
                body.style.setProperty('--input-border', '#D1D5DB');
                body.style.setProperty('--input-text', '#333');
                body.style.setProperty('--progress-bg', '#E5E7EB');
            }

            updateCharts(theme);
        }

        document.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('theme') || '<?php echo $theme; ?>';
            document.body.classList.add(savedTheme);
            applyTheme(savedTheme);
        });

        <?php if (!empty($category_breakdown)): ?>
            let pieChart, lineChart;

            function updateCharts(theme) {
                const isDark = theme === 'dark';
                const pieColors = isDark
                    ? ['#EF4444', '#60a5fa', '#FBBF24', '#34D399', '#A78BFA', '#F472B6', '#93C5FD', '#4ADE80', '#FB923C', '#38BDF8']
                    : ['#EF4444', '#1E3A8A', '#F59E0B', '#15803d', '#8B5CF6', '#EC4899', '#93C5FD', '#22C55E', '#F97316', '#0EA5E9'];
                const lineBorderColor = isDark ? '#60a5fa' : '#1E3A8A';
                const lineBackgroundColor = isDark ? 'rgba(96, 165, 250, 0.2)' : 'rgba(30, 58, 138, 0.2)';
                const textColor = isDark ? '#D1D5DB' : '#333';
                const gridColor = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';

                if (pieChart) {
                    pieChart.data.datasets[0].backgroundColor = pieColors;
                    pieChart.options.plugins.legend.labels.color = textColor;
                    pieChart.options.plugins.title.color = textColor;
                    pieChart.update();
                }
                if (lineChart) {
                    lineChart.data.datasets[0].borderColor = lineBorderColor;
                    lineChart.data.datasets[0].backgroundColor = lineBackgroundColor;
                    lineChart.options.plugins.legend.labels.color = textColor;
                    lineChart.options.plugins.title.color = textColor;
                    lineChart.options.scales.x.title.color = textColor;
                    lineChart.options.scales.y.title.color = textColor;
                    lineChart.options.scales.x.grid.color = gridColor;
                    lineChart.options.scales.y.grid.color = gridColor;
                    lineChart.options.scales.x.ticks.color = textColor;
                    lineChart.options.scales.y.ticks.color = textColor;
                    lineChart.update();
                }
            }

            const categoryCtx = document.getElementById('categoryPieChart').getContext('2d');
            pieChart = new Chart(categoryCtx, {
                type: 'pie',
                data: {
                    labels: <?php echo json_encode(array_keys($category_breakdown)); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_values($category_breakdown)); ?>,
                        backgroundColor: []
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'top', labels: { color: '' } },
                        title: { display: true, text: 'Expense Distribution by Category', color: '' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    let value = context.raw || 0;
                                    let total = context.dataset.data.reduce((sum, val) => sum + val, 0);
                                    let percentage = ((value / total) * 100).toFixed(2);
                                    return `${label}: $${value.toFixed(2)} (${percentage}%)`; // Display exact category name
                                }
                            }
                        }
                    }
                }
            });

            const lineCtx = document.getElementById('expenseLineGraph').getContext('2d');
            lineChart = new Chart(lineCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($labels); ?>,
                    datasets: [{
                        label: 'Daily Expenses',
                        data: <?php echo json_encode($line_data); ?>,
                        borderColor: '',
                        backgroundColor: '',
                        fill: true,
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'top', labels: { color: '' } },
                        title: { display: true, text: 'Expenses Over Time', color: '' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    let value = context.raw || 0;
                                    return `${label}: $${value.toFixed(2)}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { title: { display: true, text: 'Date', color: '' }, grid: { color: '' }, ticks: { color: '' } },
                        y: { title: { display: true, text: 'Amount ($)', color: '' }, beginAtZero: true, grid: { color: '' }, ticks: { color: '' } }
                    }
                }
            });
        <?php endif; ?>
    </script>
</body>
</html>