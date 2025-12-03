<?php
require_once 'config.php';
require_once 'includes/theme_handler.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php?form=login');
    exit;
}

$user_id = $_SESSION['user_id'];
$upload_dir = 'uploads/receipts/';


// STEP 1: DETERMINE MODE (ADD VS. EDIT) & INITIALIZE VARIABLES


$mode = 'add';
$page_title = 'ADD NEW EXPENSE';
$expense_id = null;

// Default values for form fields in 'add' mode
$amount = '';
$description = '';
$category_id = '';
$payment_method = '';
$date = date('Y-m-d'); // Default to today
$receipt_path = null;
$is_recurring = false;
$frequency = 'monthly';
$period = 1;
$end_date = '';


// Check if an ID is passed in the URL for 'edit' mode
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $mode = 'edit';
    $page_title = 'EDIT EXPENSE';
    $expense_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if (!$expense_id) {
        header('Location: dashboard.php');
        exit;
    }

    // Fetch existing expense data from the database
    $stmt = $conn->prepare("SELECT * FROM expenses WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $expense_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $expense = $result->fetch_assoc();

    if (!$expense) {
        // Redirect if expense not found or doesn't belong to the user
        header('Location: dashboard.php');
        exit;
    }

    // Populate variables with existing data for the form
    $amount = $expense['amount'];
    $description = $expense['description'];
    $category_id = $expense['category_id'];
    $payment_method = $expense['payment_method'];
    $date = $expense['date'];
    $receipt_path = $expense['receipt_path'] ?? null;
    // Note: Recurring fields are not part of the 'edit' scope in your original code,
    // so they are left as default. This can be expanded if needed.
}


//  HANDLE FORM SUBMISSION (FOR BOTH ADD AND EDIT)

$error_message = '';
$budget_warning = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and retrieve POST data
    $post_id = filter_input(INPUT_POST, 'expense_id', FILTER_VALIDATE_INT);
    $amount = $_POST['amount'] ?? '';
    $description = $_POST['description'] ?? '';
    $category_id = $_POST['category_id'] ?? '';
    $payment_method = $_POST['payment_method'] ?? '';
    $date = $_POST['date'] ?? '';
    $is_recurring_checked = isset($_POST['is_recurring']);
    $frequency_post = $_POST['frequency'] ?? '';
    $recurring_end_date = $_POST['recurring_end_date'] ?? '';
    
    // Handle receipt upload
    $receipt_path = null;
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $receipt_file = $_FILES['receipt'];
        $file_name = uniqid() . '_' . basename($receipt_file['name']);
        $target_file = $upload_dir . $file_name;

        // Check if directory exists, create if it doesn't
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        // Move uploaded file
        if (move_uploaded_file($receipt_file['tmp_name'], $target_file)) {
            $receipt_path = $target_file;
        } else {
            $error_message = 'Failed to upload receipt.';
        }
    }

    // --- Validation ---
    if (empty($category_id) || empty($amount) || empty($payment_method) || empty($date)) {
        $error_message = 'Looks like you missed a required field—let’s try again!';
    } elseif (!is_numeric($amount) || $amount <= 0) {
        $error_message = 'Amount must be a positive number. Please check and try again.';
    } elseif ($is_recurring_checked && empty($frequency_post)) {
        $error_message = 'Please select a frequency for the recurring expense.';
    }

    if (empty($error_message)) {
        // If an ID was posted, we are in 'edit' mode (one-time only in this scope)
        if ($post_id && !$is_recurring_checked) {
            $sql = "UPDATE expenses SET amount=?, description=?, category_id=?, payment_method=?, date=?, receipt_path=? WHERE id=? AND user_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("dsissisi", $amount, $description, $category_id, $payment_method, $date, $receipt_path, $post_id, $user_id);
            if ($stmt->execute()) {
                header('Location: dashboard.php?success=Expense updated!');
                exit;
            } else {
                $error_message = 'Failed to update expense: ' . $stmt->error;
            }
        }
        // Add recurring rule if checkbox checked
        elseif ($is_recurring_checked) {
            // Normalize frequency to allowed enum values (Title case)
            $allowed = ['Daily','Weekly','Monthly','Yearly'];
            $freq_title = ucfirst(strtolower($frequency_post));
            if (!in_array($freq_title, $allowed, true)) {
                $error_message = 'Invalid frequency selected.';
            } else {
                $sql = "INSERT INTO recurring_expenses (user_id, category_id, description, amount, payment_method, frequency, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $end = !empty($recurring_end_date) ? $recurring_end_date : null;
                $stmt->bind_param("iisdssss", $user_id, $category_id, $description, $amount, $payment_method, $freq_title, $date, $end);

                if ($stmt->execute()) {
                    header('Location: view_expenses.php?success=Recurring+expense+created');
                    exit;
                } else {
                    $error_message = 'Failed to save recurring expense: ' . $stmt->error;
                }
            }
        }
        // Otherwise, add a one-time expense
        else {
            $sql = "INSERT INTO expenses (user_id, amount, description, category_id, payment_method, date, receipt_path) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("idssiss", $user_id, $amount, $description, $category_id, $payment_method, $date, $receipt_path);

            if ($stmt->execute()) {
                header('Location: dashboard.php?success=Expense+saved');
                exit;
            } else {
                $error_message = 'Failed to save expense: ' . $stmt->error;
            }
        }
    }
}



// FETCH DATA FOR THE PAGE (USER, SETTINGS, CATEGORIES)

// Fetch user data
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Fetch theme settings
$settings = getUserTheme($conn, $user_id);

// Fetch categories for the dropdown
$stmt = $conn->prepare("SELECT id, name FROM categories WHERE user_id = ? OR user_id IS NULL");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Expense Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css?v=<?php echo filemtime('css/styles.css'); ?>">
</head>
<body class="<?php echo htmlspecialchars($settings['theme']); ?>">
    <div class="app">
        <nav class="navbar">
            <div class="logo">Expense Tracker</div>
            <ul class="nav-links">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="manage_expense.php"><i class="fas fa-plus"></i> Manage Expenses</a></li>
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
            <h1 class="page-title"><?php echo htmlspecialchars($page_title); ?></h1>

            <?php if ($error_message): ?>
                <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
            <?php endif; ?>
            
            <form id="expense-form" method="POST" enctype="multipart/form-data">
                <?php if ($mode === 'edit'): ?>
                    <input type="hidden" name="expense_id" value="<?php echo htmlspecialchars($expense_id); ?>">
                <?php endif; ?>

                <!-- Main Input Fields Card -->
                <div class="expense-card main-fields-card">
                    <div class="form-group">
                        <label for="amount">Amount <span class="required">*</span></label>
                        <input type="number" name="amount" id="amount" step="0.01" required placeholder="Enter amount" value="<?php echo htmlspecialchars($amount); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="category_id">Category <span class="required">*</span></label>
                        <select name="category_id" id="category_id" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php if ($category['id'] == $category_id) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="payment_method">Payment Method <span class="required">*</span></label>
                        <select name="payment_method" id="payment_method" required>
                            <option value="">Select Payment Method</option>
                            <?php $methods = ['cash' => 'Cash', 'credit_card' => 'Credit Card', 'debit_card' => 'Debit Card', 'mobile_payment' => 'Mobile Payment']; ?>
                            <?php foreach ($methods as $value => $label): ?>
                                <option value="<?php echo $value; ?>" <?php if ($value == $payment_method) echo 'selected'; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="date">Date <span class="required">*</span></label>
                        <input type="date" name="date" id="date" value="<?php echo htmlspecialchars($date); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea name="description" id="description" rows="3" placeholder="Enter description"><?php echo htmlspecialchars($description); ?></textarea>
                    </div>

                    <?php if ($mode === 'add'): ?>
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_recurring" id="is_recurring"> Is this a recurring expense?
                            </label>
                        </div>

                        <div id="recurring-fields" class="recurring-fields" style="display:none;">
                            <div class="form-group">
                                <label for="frequency">Frequency <span class="required">*</span></label>
                                <select name="frequency" id="frequency">
                                    <option value="">Select Frequency</option>
                                    <option value="Daily">Daily</option>
                                    <option value="Weekly">Weekly</option>
                                    <option value="Monthly" selected>Monthly</option>
                                    <option value="Yearly">Yearly</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="recurring_end_date">End Date (optional)</label>
                                <input type="date" name="recurring_end_date" id="recurring_end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                                <small class="form-hint">Leave empty for an open-ended recurring expense.</small>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Upload Receipt Card -->
                <?php if ($mode === 'add'): ?>
                    <div class="expense-card receipt-card">
                        <div class="form-group">
                            <label for="receipt">Upload Receipt</label>
                            <input type="file" name="receipt" id="receipt" accept="image/*,application/pdf">
                            <small class="form-hint">Supported formats: JPG, PNG, PDF</small>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="form-actions">
                    <button type="submit" class="save-btn" id="save-btn">
                        <span class="btn-text"><?php echo ($mode === 'edit') ? 'Update Expense' : 'Save Expense'; ?></span>
                        <span class="spinner" style="display: none;"><i class="fas fa-spinner fa-spin"></i></span>
                    </button>
                    <a href="dashboard.php" class="cancel-btn">Cancel</a>
                </div>
            </form>
        </div>
        <?php include 'footer.html'; ?>
    </div>
    <script src="js/theme-toggle.js?v=<?php echo filemtime('js/theme-toggle.js'); ?>"></script>
    <script>
        
        // Toggle recurring fields visibility
        (function() {
            const chk = document.getElementById('is_recurring');
            const box = document.getElementById('recurring-fields');
            if (!chk || !box) return;
            function sync() {
                box.style.display = chk.checked ? 'block' : 'none';
            }
            chk.addEventListener('change', sync);
            sync();
        })();
    </script>
</body>
</html>