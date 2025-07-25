<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$upload_dir = 'uploads/receipts/';

// =================================================================
// STEP 1: DETERMINE MODE (ADD VS. EDIT) & INITIALIZE VARIABLES
// =================================================================

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


// =================================================================
// STEP 2: HANDLE FORM SUBMISSION (FOR BOTH ADD AND EDIT)
// =================================================================

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
    }

    if (empty($error_message)) {
        // If an ID was posted, we are in 'edit' mode
        if ($post_id) {
            $sql = "UPDATE expenses SET amount=?, description=?, category_id=?, payment_method=?, date=?, receipt_path=? WHERE id=? AND user_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("dsissisi", $amount, $description, $category_id, $payment_method, $date, $receipt_path, $post_id, $user_id);
        }
        // Otherwise, we are in 'add' mode
        else {
            $sql = "INSERT INTO expenses (user_id, amount, description, category_id, payment_method, date, receipt_path) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("idssiss", $user_id, $amount, $description, $category_id, $payment_method, $date, $receipt_path);
        }

        if ($stmt->execute()) {
            header('Location: dashboard.php?success=Action successful!');
            exit;
        } else {
            $error_message = 'Failed to save expense: ' . $stmt->error;
        }
    }
}


// =================================================================
// STEP 3: FETCH DATA FOR THE PAGE (USER, SETTINGS, CATEGORIES)
// =================================================================

// Fetch user data
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Fetch theme settings
$stmt = $conn->prepare("SELECT theme FROM user_settings WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$settings = $stmt->get_result()->fetch_assoc() ?: ['theme' => 'light'];

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
    <title><?php echo htmlspecialchars(ucwords(strtolower($page_title))); ?> - Expense Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/styles.css">
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

        <div class="add-expense-section">
            <h1 class="page-title"><?php echo htmlspecialchars($page_title); ?></h1>

            <?php if ($error_message): ?>
                <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
            <?php endif; ?>
            
            <form id="expense-form" method="POST" enctype="multipart/form-data">
                <?php if ($mode === 'edit'): ?>
                    <input type="hidden" name="expense_id" value="<?php echo htmlspecialchars($expense_id); ?>">
                <?php endif; ?>

                <div class="bento-grid">
                    <div class="bento-box primary-box">
                        <div class="form-group">
                            <label for="amount">Amount <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <input type="number" name="amount" id="amount" step="0.01" required placeholder="Enter amount" value="<?php echo htmlspecialchars($amount); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="category_id">Category <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <select name="category_id" id="category_id" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" <?php if ($category['id'] == $category_id) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="payment_method">Payment Method <span class="required">*</span></label>
                            <div class="input-wrapper">
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
                        </div>
                        <div class="form-group">
                            <label for="date">Date <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <input type="date" name="date" id="date" value="<?php echo htmlspecialchars($date); ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <div class="input-wrapper">
                                <textarea name="description" id="description" placeholder="Enter description"><?php echo htmlspecialchars($description); ?></textarea>
                            </div>
                        </div>

                        <?php if ($mode === 'add'): ?>
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="is_recurring" id="is_recurring"> Is this a recurring expense?
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($mode === 'add'): ?>
                    <div class="bento-grid">
                        <div class="bento-box secondary-box">
                            <div class="form-group">
                                <label for="receipt">Upload Receipt</label>
                                <div class="input-wrapper">
                                    <input type="file" name="receipt" id="receipt" accept="image/*,application/pdf">
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

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
</body>
</html>