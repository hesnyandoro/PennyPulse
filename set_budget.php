<?php
error_log("set_budget.php script started.");
// Start output buffering to prevent header issues
ob_start();

// Ensure session is started
session_start();

// Include configuration
require_once 'config.php';
require_once 'includes/theme_handler.php';

// Check for user session
if (!isset($_SESSION['user_id'])) {
    error_log("Session user_id not set, redirecting to auth.php");
    header('Location: auth.php?form=login');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
error_log("User ID in set_budget.php: $user_id");

$success_message = '';
$error_message = '';

// Handle success/error messages
if (isset($_GET['success'])) {
    error_log("Success parameter received: " . $_GET['success']);
    if ($_GET['success'] === '1') {
        $success_message = 'Budget set successfully!';
    } elseif ($_GET['success'] === 'deleted') {
        $success_message = 'Budget deleted successfully!';
    }
}
if (isset($_GET['error'])) {
    error_log("Error parameter received: " . $_GET['error']);
    if ($_GET['error'] === 'exists') {
        $error_message = 'A budget for this category and month already exists.';
    } elseif ($_GET['error'] === 'fail') {
        $error_message = 'Failed to set budget. Please try again.';
    } elseif ($_GET['error'] === 'deletefail') {
        $error_message = 'Failed to delete budget. Please try again.';
    } elseif ($_GET['error'] === 'invalid') {
        $error_message = 'Invalid input data. Please check your entries.';
    }
}

// Fetch user details
$query = "SELECT username FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Prepare Error (Fetch User): " . $conn->error);
    $error_message = 'Failed to fetch user data.';
} else {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) {
        error_log("User not found for user_id: $user_id");
        header('Location: auth.php?form=login');
        exit;
    }
}

// Fetch user settings (theme)
$settings = getUserTheme($conn, $user_id);

// Fetch categories for the form
$query = "SELECT id, name FROM categories WHERE user_id = ? OR user_id IS NULL";
$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Prepare Error (Fetch Categories): " . $conn->error);
    $error_message = 'Failed to fetch categories.';
    $categories = [];
} else {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    error_log("Fetched Categories: " . json_encode($categories));
}

// Handle budget creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_budget'])) {
    error_log("POST Data: " . print_r($_POST, true));
    $category_id = $_POST['category_id'] ?? '';
    $month = $_POST['month'] ?? '';
    $budget_amount = $_POST['budget_amount'] ?? '';

    // Validation
    if (empty($category_id) || empty($month) || empty($budget_amount)) {
        error_log("Validation failed: Missing required fields");
        header('Location: set_budget.php?error=invalid');
        exit;
    }

    if (!is_numeric($category_id) || !is_numeric($budget_amount) || $budget_amount <= 0) {
        error_log("Validation failed: Invalid numeric values");
        header('Location: set_budget.php?error=invalid');
        exit;
    }

    if (!strtotime($month)) {
        error_log("Invalid month format: " . $_POST['month']);
        header('Location: set_budget.php?error=invalid');
        exit;
    }

    // Validate category_id exists
    $category_exists = false;
    foreach ($categories as $category) {
        if ($category['id'] == $category_id) {
            $category_exists = true;
            break;
        }
    }
    if (!$category_exists) {
        error_log("Invalid category_id: $category_id");
        header('Location: set_budget.php?error=invalid');
        exit;
    }

    $category_id = (int)$category_id;
    $budget_amount = (float)$budget_amount;
    $month = date('Y-m-01', strtotime($month));
    error_log("Validated inputs: category_id=$category_id, month=$month, budget_amount=$budget_amount");

    // Preserve current filters for redirect
    $redirect_params = [
        'time_filter' => $_GET['time_filter'] ?? 'active',
        'status_filter' => $_GET['status_filter'] ?? ''
    ];

    // Check for existing budget
    $query = "SELECT id FROM budgets WHERE user_id = ? AND category_id = ? AND month = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Prepare Error (Check Existing): " . $conn->error);
        header('Location: set_budget.php?error=fail');
        exit;
    }
    $stmt->bind_param('iis', $user_id, $category_id, $month);
    $stmt->execute();
    $existing_budget = $stmt->get_result()->fetch_assoc();

    if ($existing_budget) {
        error_log("Budget already exists for user_id: $user_id, category_id: $category_id, month: $month");
        $redirect_params['error'] = 'exists';
        header("Location: set_budget.php?" . http_build_query($redirect_params));
        exit;
    }

    // Insert new budget
    $query = "INSERT INTO budgets (user_id, category_id, month, budget_amount) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Prepare Error (Insert): " . $conn->error);
        header('Location: set_budget.php?error=fail');
        exit;
    }
    error_log("Binding values: user_id=$user_id, category_id=$category_id, month=$month, budget_amount=$budget_amount");
    $stmt->bind_param('iisd', $user_id, $category_id, $month, $budget_amount);
if ($stmt->execute()) {
    error_log("Budget inserted successfully for user_id: $user_id, category_id: $category_id, month: $month");

    // --- START: New logic to set the correct time_filter for the redirect ---
    $current_month_start = date('Y-m-01');
    $budget_month_start = date('Y-m-01', strtotime($month)); // Ensure consistent format

    if ($budget_month_start < $current_month_start) {
        $redirect_params['time_filter'] = 'past';
    } elseif ($budget_month_start > $current_month_start) {
        $redirect_params['time_filter'] = 'upcoming';
    } else {
        $redirect_params['time_filter'] = 'active';
    }
    // --- END: New logic ---

    $redirect_params['success'] = '1';
    $redirect_url = "set_budget.php?" . http_build_query($redirect_params);
    error_log("Redirecting to: $redirect_url");
    header("Location: $redirect_url");
    exit;
} else {
    error_log("Insert Error: " . $stmt->error);
    $redirect_params['error'] = 'fail';
    header("Location: set_budget.php?" . http_build_query($redirect_params));
    exit;
}
}

// Handle budget deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_budget'])) {
    error_log("Delete budget request received");
    $budget_id = $_POST['budget_id'] ?? '';
    $time_filter = $_GET['time_filter'] ?? 'active';

    if (empty($budget_id) || !is_numeric($budget_id)) {
        error_log("Invalid budget_id for deletion: $budget_id");
        header("Location: set_budget.php?error=deletefail&time_filter=$time_filter");
        exit;
    }

    $budget_id = (int)$budget_id;
    $query = "DELETE FROM budgets WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Prepare Error (Delete): " . $conn->error);
        header("Location: set_budget.php?error=deletefail&time_filter=$time_filter");
        exit;
    }
    $stmt->bind_param('ii', $budget_id, $user_id);
    if ($stmt->execute()) {
        error_log("Budget deleted successfully: Budget ID $budget_id");
        header("Location: set_budget.php?success=deleted&time_filter=$time_filter");
        exit;
    } else {
        error_log("Delete Error: " . $stmt->error);
        header("Location: set_budget.php?error=deletefail&time_filter=$time_filter");
        exit;
    }
}

// Handle filters
$time_filter = $_GET['time_filter'] ?? 'active';
$status_filter = $_GET['status_filter'] ?? '';
$current_date = date('Y-m-d'); // Current date: 2025-07-03
error_log("Applying filters - Time Filter: $time_filter, Status Filter: $status_filter");

// Fetch budgets with filters
$query = "SELECT b.id, b.category_id, c.name as category_name, b.month, b.budget_amount, 
          COALESCE(SUM(e.amount), 0) as spent 
          FROM budgets b 
          JOIN categories c ON b.category_id = c.id 
          LEFT JOIN expenses e ON e.category_id = b.category_id 
              AND e.user_id = b.user_id 
              AND DATE_FORMAT(e.date, '%Y-%m') = DATE_FORMAT(b.month, '%Y-%m') 
          WHERE b.user_id = ? ";

$params = [$user_id];
$types = 'i';

if ($time_filter === 'past') {
    $query .= "AND b.month < ?";
    $params[] = date('Y-m-01');
    $types .= 's';
} elseif ($time_filter === 'upcoming') {
    $query .= "AND b.month > ?";
    $params[] = date('Y-m-01');
    $types .= 's';
} else { // 'active'
    $query .= "AND b.month = ?";
    $params[] = date('Y-m-01');
    $types .= 's';
}

$query .= " GROUP BY b.id";

if ($status_filter) {
    if ($status_filter === 'under_budget') {
        $query .= " HAVING spent < (budget_amount * 0.8)";
    } elseif ($status_filter === 'near_limit') {
        $query .= " HAVING spent >= (budget_amount * 0.8) AND spent <= budget_amount";
    } elseif ($status_filter === 'over_budget') {
        $query .= " HAVING spent > budget_amount";
    }
}

$query .= " ORDER BY b.month DESC, c.name";

$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Prepare Error (Fetch Budgets): " . $conn->error);
    $error_message = 'Failed to fetch budgets.';
    $budgets = [];
} else {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $budgets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    error_log("Fetched Budgets: " . json_encode($budgets));
}

// Calculate summary metrics
$total_budgeted = 0;
$total_spent = 0;
$budgets_at_risk = 0;

foreach ($budgets as &$budget) {
    $budget['remaining'] = $budget['budget_amount'] - $budget['spent'];
    $budget['progress'] = $budget['budget_amount'] > 0 ? ($budget['spent'] / $budget['budget_amount']) * 100 : 0;
    $budget['status'] = $budget['spent'] > $budget['budget_amount'] ? 'over_budget' : 
                       ($budget['spent'] >= $budget['budget_amount'] * 0.8 ? 'near_limit' : 'under_budget');

    $total_budgeted += $budget['budget_amount'];
    $total_spent += $budget['spent'];
    if ($budget['status'] === 'near_limit' || $budget['status'] === 'over_budget') {
        $budgets_at_risk++;
    }
}
unset($budget); //ensures the second loop can operate on the original, unmodified array hence displaying budgets correctly and onl once
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Budget - Expense Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" 
          onerror="this.onerror=null; this.href='/expense_tracker/fontawesome/css/all.min.css'">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css?v=<?php echo filemtime('css/styles.css'); ?>">
</head>
<body class="<?php echo htmlspecialchars($settings['theme']); ?>">
    <div class="app">
        <!-- Navbar -->
        <nav class="navbar">
            <div class="logo">Expense Tracker</div>
            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-home"></i> Dashboard</a></li>
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

        <!-- Budget Section -->
        <div class="add-expense-section">
            <h1 class="page-title">Manage Budgets</h1>
            <?php if ($success_message) { ?>
                <p class="success-message"><?php echo htmlspecialchars($success_message); ?></p>
            <?php } ?>
            <?php if ($error_message) { ?>
                <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
            <?php } ?>
            
            <!-- Budget Form -->
            <form id="budget-form" method="POST" action="set_budget.php">
                <div class="bento-grid">
                    <div class="bento-box primary-box">
                        <div class="form-group">
                            <label for="category_id">Category <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <select name="category_id" id="category_id" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $category) { ?>
                                        <option value="<?php echo $category['id']; ?>">
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                                <span class="input-icon"><i class="fas fa-check"></i></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="month">Month <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <input type="month" name="month" id="month" required>
                                <span class="input-icon"><i class="fas fa-check"></i></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="budget_amount">Budget Amount <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <input type="number" name="budget_amount" id="budget_amount" step="0.01" min="0.01" required placeholder="Enter budget amount">
                                <span class="input-icon"><i class="fas fa-check"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" name="set_budget" class="save-btn" id="save-btn">
                        <span class="btn-text">Set Budget</span>
                        <span class="spinner" style="display: none;"><i class="fas fa-spinner fa-spin"></i></span>
                    </button>
                    <a href="dashboard.php" class="cancel-btn">Cancel</a>
                </div>
            </form>

            <!-- Filter Controls -->
            <form id="filter-form" method="GET">
                <div class="filter-controls">
                    <div class="filter-group">
                        <label for="time_filter">Filter by Time:</label>
                        <select id="time_filter" name="time_filter" onchange="this.form.submit()">
                            <option value="active" <?php echo $time_filter === 'active' ? 'selected' : ''; ?>>Active Budgets</option>
                            <option value="past" <?php echo $time_filter === 'past' ? 'selected' : ''; ?>>Past Budgets</option>
                            <option value="upcoming" <?php echo $time_filter === 'upcoming' ? 'selected' : ''; ?>>Upcoming Budgets</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="status_filter">Filter by Status:</label>
                        <select id="status_filter" name="status_filter" onchange="this.form.submit()">
                            <option value="" <?php echo $status_filter === '' ? 'selected' : ''; ?>>All</option>
                            <option value="under_budget" <?php echo $status_filter === 'under_budget' ? 'selected' : ''; ?>>Under Budget</option>
                            <option value="near_limit" <?php echo $status_filter === 'near_limit' ? 'selected' : ''; ?>>Near Limit</option>
                            <option value="over_budget" <?php echo $status_filter === 'over_budget' ? 'selected' : ''; ?>>Over Budget</option>
                        </select>
                    </div>
                </div>
            </form>

            <!-- Summary Metrics -->
            <div class="summary-metrics">
                <div class="metric-card">
                    <h3>Total Budgeted</h3>
                    <p>$<?php echo number_format($total_budgeted, 2); ?></p>
                </div>
                <div class="metric-card">
                    <h3>Total Spent</h3>
                    <p>$<?php echo number_format($total_spent, 2); ?></p>
                </div>
                <div class="metric-card">
                    <h3>Budgets Nearing Limit</h3>
                    <p><?php echo $budgets_at_risk; ?></p>
                </div>
            </div>

            <!-- Budget Overview -->
            <div class="budget-overview">
                <?php if (empty($budgets)) { ?>
                    <div class="empty-state">
                        <p>No budgets set. Start by setting one.</p>
                        <button class="add-budget-btn" onclick="document.getElementById('budget-form').scrollIntoView({ behavior: 'smooth' })">
                            Add Budget
                        </button>
                    </div>
                <?php } else { ?>
                    <?php foreach ($budgets as $budget) { ?>
                        <div class="budget-card bento-box <?php echo $budget['status']; ?>">
                            <h3><?php echo htmlspecialchars($budget['category_name']); ?> (<?php echo date('F Y', strtotime($budget['month'])); ?>)</h3>
                            <p>Budget: $<?php echo number_format($budget['budget_amount'], 2); ?></p>
                            <p>Spent: $<?php echo number_format($budget['spent'], 2); ?></p>
                            <p>Remaining: $<?php echo number_format($budget['remaining'], 2); ?></p>
                            <?php if ($budget['status'] === 'near_limit' || $budget['status'] === 'over_budget') { ?>
                                <p class="warning-message">
                                    <?php echo $budget['status'] === 'near_limit' ? 'Warning: Nearing limit!' : 'Alert: Over budget!'; ?>
                                </p>
                            <?php } ?>
                            <div class="progress-bar">
                                <div class="progress" style="width: <?php echo min($budget['progress'], 100); ?>%;"></div>
                            </div>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="budget_id" value="<?php echo $budget['id']; ?>">
                                <button type="submit" name="delete_budget" class="delete-btn" onclick="return confirm('Are you sure you want to delete this budget?');">
                                    <i class="fas fa-trash-alt"></i> Delete
                                </button>
                            </form>
                        </div>
                    <?php } ?>
                <?php } ?>
            </div>
        </div>

        <!-- Footer -->
        <?php if (!in_array(basename($_SERVER['PHP_SELF']), ['login.php', 'register.php', 'logout.php'])) {
            include 'footer.html';
        } ?>
    </div>

    <!-- JavaScript -->
    <script src="js/theme-toggle.js?v=<?php echo filemtime('js/theme-toggle.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Form Validation and Spinner
            const form = document.getElementById('budget-form');
            const saveBtn = document.getElementById('save-btn');
            const btnText = saveBtn.querySelector('.btn-text');
            const spinner = saveBtn.querySelector('.spinner');

            form.addEventListener('submit', (e) => {
                const category = document.getElementById('category_id').value;
                const month = document.getElementById('month').value;
                const budgetAmount = document.getElementById('budget_amount').value;

                console.log('Form Data:', { category, month, budgetAmount });

                if (!category || !month || !budgetAmount) {
                    e.preventDefault();
                    alert('Please fill in all required fields.');
                    return;
                }

                if (parseFloat(budgetAmount) <= 0) {
                    e.preventDefault();
                    alert('Budget amount must be a positive number.');
                    return;
                }

                btnText.style.display = 'none';
                spinner.style.display = 'inline-block';
                saveBtn.classList.add('glow');
                // saveBtn.disabled = true;
            });

            // Real-Time Validation
            const inputs = document.querySelectorAll('input, select');
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    const wrapper = this.closest('.input-wrapper');
                    const icon = wrapper.querySelector('.input-icon i');
                    if (this.checkValidity() && this.value) {
                        icon.classList.remove('fa-times');
                        icon.classList.add('fa-check', 'valid');
                    } else {
                        icon.classList.remove('fa-check', 'valid');
                        icon.classList.add('fa-times');
                    }
                });
            });
        });
    </script>
</body>
</html>

<?php
// Flush output buffer
ob_end_flush();
?>