<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';
$budget_warning = '';

$upload_dir = 'uploads/receipts/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Check if receipt_path column exists in expenses table
$query = "SHOW COLUMNS FROM expenses LIKE 'receipt_path'";
$result = $conn->query($query);
$has_receipt_path = $result->num_rows > 0;

$query = "SELECT username FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$query = "SELECT theme FROM user_settings WHERE user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$settings_result = $stmt->get_result();
$settings = $settings_result->fetch_assoc();

if (!$settings) {
    $query = "INSERT INTO user_settings (user_id, theme) VALUES (?, 'light')";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $settings = ['theme' => 'light'];
}

$query = "SELECT id, name FROM categories WHERE user_id = ? OR user_id IS NULL";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (empty($categories)) {
    $default_categories = ['Food', 'Transport', 'Entertainment', 'Bills', 'Other'];
    foreach ($default_categories as $cat) {
        $query = "INSERT INTO categories (user_id, name) VALUES (NULL, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $cat);
        $stmt->execute();
    }
    $query = "SELECT id, name FROM categories WHERE user_id = ? OR user_id IS NULL";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_id = $_POST['category_id'] ?? '';
    $amount = $_POST['amount'] ?? '';
    $payment_method = $_POST['payment_method'] ?? '';
    $description = $_POST['description'] ?? '';
    $date = $_POST['date'] ?? ''; // Capture the submitted date
    $is_recurring = isset($_POST['is_recurring']);
    $frequency = $_POST['frequency'] ?? '';
    $period = $_POST['period'] ?? 1; // Changed from interval to period
    $end_date = $_POST['end_date'] ? $_POST['end_date'] :null;

    // Validate date
    $date = trim($date);
    if (empty($date) || !strtotime($date)) {
        $error_message = 'Please enter a valid date.';
    } elseif (empty($category_id) || empty($amount) || empty($payment_method)) {
        $error_message = 'Looks like you missed a required field—let’s try again!';
    } elseif (!is_numeric($amount) || $amount <= 0) {
        $error_message = 'Amount must be a positive number. Please check and try again.';
    } elseif ($is_recurring && (empty($frequency) || empty($period) || !is_numeric($period) || $period < 1)) {
        $error_message = 'Please specify a valid frequency and period for recurring expenses.';
    } else {
        $current_month = date('Y-m-01', strtotime($date));
        $month_like = $current_month . '%';
        $query = "SELECT b.budget_amount, COALESCE(SUM(e.amount), 0) as spent 
                  FROM budgets b 
                  LEFT JOIN expenses e ON e.category_id = b.category_id 
                      AND e.user_id = b.user_id 
                      AND e.date LIKE ? 
                  WHERE b.user_id = ? AND b.category_id = ? AND b.month = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('siis', $month_like, $user_id, $category_id, $current_month);
        $stmt->execute();
        $budget_result = $stmt->get_result()->fetch_assoc();

        if ($budget_result && $budget_result['budget_amount'] > 0) {
            $remaining = $budget_result['budget_amount'] - $budget_result['spent'];
            if ($remaining < $amount) {
                $budget_warning = "Warning: This expense exceeds your budget for this category by $" . number_format($amount - $remaining, 2) . ".";
            }
        }

        $receipt_path = null;
        if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['receipt']['tmp_name'];
            $file_name = uniqid() . '_' . basename($_FILES['receipt']['name']);
            $file_dest = $upload_dir . $file_name;
            if (move_uploaded_file($file_tmp, $file_dest)) {
                $receipt_path = $file_dest;
            } else {
                $error_message = 'Failed to upload receipt. Please try again.';
            }
        }

        if (!$error_message) {
            if ($is_recurring) {
                $query = "INSERT INTO recurring_expenses (user_id, category_id, amount, payment_method, description, start_date, frequency, period, end_date) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($query);
                $stmt->bind_param('iidssssis', $user_id, $category_id, $amount, $payment_method, $description, $date, $frequency, $period, $end_date);
            } else {
                // Adjust query based on whether receipt_path column exists
                if ($has_receipt_path) {
                    $query = "INSERT INTO expenses (user_id, category_id, amount, payment_method, description, date, receipt_path) 
                              VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param('iidssss', $user_id, $category_id, $amount, $payment_method, $description, $date, $receipt_path);
                } else {
                    $query = "INSERT INTO expenses (user_id, category_id, amount, payment_method, description, date) 
                              VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param('iidsss', $user_id, $category_id, $amount, $payment_method, $description, $date);
                }
            }

            if ($stmt->execute()) {
                header('Location: dashboard.php?success=Expense added successfully!');
                exit;
            } else {
                $error_message = 'Failed to add expense: ' . $stmt->error;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Expense - Expense Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" 
          onerror="this.onerror=null; this.href='/expense_tracker/fontawesome/css/all.min.css'">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body class="<?php echo htmlspecialchars($settings['theme']); ?>">
    <div class="app">
        <!-- Navbar -->
        <nav class="navbar">
            <div class="logo">Expense Tracker</div>
            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-home"></i> Home</a></li>
                <li><a href="add_expense.php"><i class="fas fa-plus"></i> Add Expense</a></li>
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
        <div class="add-expense-section">
            <h1 class="page-title">ADD NEW EXPENSE</h1>
            <?php if ($budget_warning) { ?>
                <p class="warning-message"><?php echo htmlspecialchars($budget_warning); ?></p>
            <?php } ?>
            <?php if ($error_message) { ?>
                <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
            <?php } ?>
            <form id="expense-form" method="POST" enctype="multipart/form-data">
                <div class="bento-grid">
                    <!-- Primary Fields -->
                    <div class="bento-box primary-box">
                        <div class="form-group">
                            <label for="amount">Amount <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <input type="number" name="amount" id="amount" step="0.01" required placeholder="Enter amount">
                                <span class="input-icon"><i class="fas fa-check"></i></span>
                            </div>
                        </div>
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
                            <label for="payment_method">Payment Method <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <select name="payment_method" id="payment_method" required>
                                    <option value="">Select Payment Method</option>
                                    <option value="cash">Cash</option>
                                    <option value="credit_card">Credit Card</option>
                                    <option value="debit_card">Debit Card</option>
                                    <option value="mobile_payment">Mobile Payment</option>
                                </select>
                                <span class="input-icon"><i class="fas fa-check"></i></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="date">Date <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <input type="date" name="date" id="date" value="<?php echo date('Y-m-d'); ?>" required>
                                <span class="input-icon"><i class="fas fa-check"></i></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <div class="input-wrapper">
                                <textarea name="description" id="description" placeholder="Enter description"></textarea>
                                <span class="input-icon"><i class="fas fa-check"></i></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_recurring" id="is_recurring"> Is this a recurring expense?
                            </label>
                        </div>
                        <div id="recurring-fields" style="display: none;">
                            <div class="form-group">
                                <label for="frequency">Frequency <span class="required">*</span></label>
                                <div class="input-wrapper">
                                    <select name="frequency" id="frequency">
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                        <option value="yearly">Yearly</option>
                                    </select>
                                    <span class="input-icon"><i class="fas fa-check"></i></span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="period">Period <span class="required">*</span></label> <!-- Changed from interval to period -->
                                <div class="input-wrapper">
                                    <input type="number" name="period" id="period" min="1" value="1" placeholder="e.g., every 1 month"> <!-- Changed from interval to period -->
                                    <span class="input-icon"><i class="fas fa-check"></i></span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="end_date">End Date</label>
                                <div class="input-wrapper">
                                    <input type="date" name="end_date" id="end_date">
                                    <span class="input-icon"><i class="fas fa-check"></i></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Secondary Fields (Only File Upload) -->
                    <div class="bento-box secondary-box">
                        <div class="form-group">
                            <label for="receipt">Upload Receipt</label>
                            <div class="input-wrapper">
                                <input type="file" name="receipt" id="receipt" accept="image/*" class="custom-file-input">
                                <span class="input-icon"><i class="fas fa-check"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="save-btn" id="save-btn">
                        <span class="btn-text">Save</span>
                        <span class="spinner" style="display: none;"><i class="fas fa-spinner fa-spin"></i></span>
                    </button>
                    <a href="dashboard.php" class="cancel-btn">Cancel</a>
                </div>
            </form>
        </div>
        
        <!-- Footer -->
        <?php if (!in_array(basename($_SERVER['PHP_SELF']), ['login.php', 'register.php', 'logout.php'])) {
            include 'footer.html';
        } ?>
    </div>

    <!-- JavaScript for Theme Toggle, Form Validation, Animations, and Spinner -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Theme Toggle
            const themeToggle = document.getElementById('theme-toggle');
            const body = document.body;

            const savedTheme = localStorage.getItem('theme');
            if (savedTheme) {
                body.className = savedTheme;
                if (themeToggle) {
                    const icon = themeToggle.querySelector('i');
                    icon.className = savedTheme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
                }
            }

            if (themeToggle) {
                themeToggle.addEventListener('click', () => {
                    const newTheme = body.classList.contains('light') ? 'dark' : 'light';
                    body.className = newTheme;
                    localStorage.setItem('theme', newTheme);

                    const icon = themeToggle.querySelector('i');
                    icon.className = newTheme === 'light' ? 'fas fa-moon' : 'fas fa-sun';

                    fetch('api/update_theme.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ theme: newTheme })
                    }).catch(error => console.error('Error updating theme:', error));
                });
            }

            // Show/Hide Recurring Fields with Animation
            const isRecurringCheckbox = document.getElementById('is_recurring');
            const recurringFields = document.getElementById('recurring-fields');
            isRecurringCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    recurringFields.style.display = 'block';
                    recurringFields.classList.add('fade-in');
                } else {
                    recurringFields.classList.add('fade-out');
                    setTimeout(() => {
                        recurringFields.style.display = 'none';
                    }, 300);
                }
            });

            // Real-Time Validation with Micro-Interactions
            const inputs = document.querySelectorAll('input, select, textarea');
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

            // Form Validation and Spinner
            const form = document.getElementById('expense-form');
            const saveBtn = document.getElementById('save-btn');
            const btnText = saveBtn.querySelector('.btn-text');
            const spinner = saveBtn.querySelector('.spinner');

            form.addEventListener('submit', (e) => {
                const category = document.getElementById('category_id').value;
                const amount = document.getElementById('amount').value;
                const paymentMethod = document.getElementById('payment_method').value;
                const date = document.getElementById('date').value;
                const isRecurring = document.getElementById('is_recurring').checked;
                const frequency = document.getElementById('frequency').value;
                const period = document.getElementById('period').value; // Changed from interval to period

                if (!category || !amount || !paymentMethod || !date) {
                    e.preventDefault();
                    alert('Please fill in all required fields.');
                    return;
                }

                if (amount <= 0) {
                    e.preventDefault();
                    alert('Amount must be a positive number.');
                    return;
                }

                if (isRecurring && (!frequency || !period || period < 1)) {
                    e.preventDefault();
                    alert('Please specify a valid frequency and period for recurring expenses.'); // Changed from interval to period
                    return;
                }

                btnText.style.display = 'none';
                spinner.style.display = 'inline-block';
                saveBtn.classList.add('glow');
                saveBtn.disabled = true;
            });
        });
    </script>
</body>
</html>