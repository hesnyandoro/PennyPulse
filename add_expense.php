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
$merchant = '';
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
    $merchant = $expense['merchant'] ?? '';
    $date = $expense['date'];
    $receipt_path = $expense['receipt_path'] ?? null;
}


//  HANDLE FORM SUBMISSION (FOR BOTH ADD AND EDIT)

$error_message = '';
$budget_warning = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and retrieve POST data
    $post_id = filter_input(INPUT_POST, 'expense_id', FILTER_VALIDATE_INT);
    $amount = $_POST['amount'] ?? '';
    $description = $_POST['description'] ?? '';
    $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
    $payment_method = $_POST['payment_method'] ?? '';
    $merchant = $_POST['merchant'] ?? '';
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

    // Validation
    if (empty($category_id) || empty($amount) || empty($payment_method) || empty($date)) {
        $error_message = 'Looks like you missed a required field—let\'s try again!';
    } elseif (!is_numeric($amount) || $amount <= 0) {
        $error_message = 'Amount must be a positive number. Please check and try again.';
    } elseif ($is_recurring_checked && empty($frequency_post)) {
        $error_message = 'Please select a frequency for the recurring expense.';
    }

    if (empty($error_message)) {
        // If an ID was posted, we are in 'edit' mode (one-time only in this scope)
        if ($post_id && !$is_recurring_checked) {
            $sql = "UPDATE expenses SET amount=?, description=?, category_id=?, payment_method=?, merchant=?, date=?, receipt_path=? WHERE id=? AND user_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("dsissssii", $amount, $description, $category_id, $payment_method, $merchant, $date, $receipt_path, $post_id, $user_id);
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
                $sql = "INSERT INTO recurring_expenses (user_id, category_id, description, amount, payment_method, merchant, frequency, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $end = !empty($recurring_end_date) ? $recurring_end_date : null;
                $stmt->bind_param("iisdsssss", $user_id, $category_id, $description, $amount, $payment_method, $merchant, $freq_title, $date, $end);

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
            $sql = "INSERT INTO expenses (user_id, amount, description, category_id, payment_method, merchant, date, receipt_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("idssssss", $user_id, $amount, $description, $category_id, $payment_method, $merchant, $date, $receipt_path);

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
    <style>
        .container {
            width: 80%;
            max-width: 80%;
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
        
        .error-message {
            background: rgba(239, 68, 68, 0.1);
            border-left: 4px solid #ef4444;
            color: #dc2626;
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .error-message::before {
            content: '\f06a';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            font-size: 18px;
        }
        
        body.dark .error-message {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }
        
        #expense-form {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        
        .expense-card {
            background: white;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
        }
        
        .expense-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
        }
        
        body.dark .expense-card {
            background: #1f2937;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
        }
        
        body.dark .expense-card:hover {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }
        
        .main-fields-card {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .form-group:has(textarea),
        .form-group:has(.checkbox-label),
        .recurring-fields {
            grid-column: 1 / -1;
        }
        
        .form-group label {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        body.dark .form-group label {
            color: #d1d5db;
        }
        
        .required {
            color: #ef4444;
            font-size: 16px;
        }
        
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="date"],
        .form-group input[type="file"],
        .form-group select,
        .form-group textarea {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 15px;
            font-family: 'Roboto', sans-serif;
            transition: all 0.2s ease;
            background: white;
        }
        
        body.dark .form-group input,
        body.dark .form-group select,
        body.dark .form-group textarea {
            background: #374151;
            border-color: #4b5563;
            color: #f3f4f6;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: #9ca3af;
        }
        
        body.dark .form-group input::placeholder,
        body.dark .form-group textarea::placeholder {
            color: #6b7280;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
            padding: 12px 0;
        }
        
        body.dark .checkbox-label {
            color: #d1d5db;
        }
        
        .checkbox-label input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: #3b82f6;
        }
        
        .recurring-fields {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            padding: 24px;
            background: rgba(59, 130, 246, 0.03);
            border-radius: 12px;
            border: 2px solid #93c5fd;
            margin-top: 8px;
        }
        
        body.dark .recurring-fields {
            background: rgba(59, 130, 246, 0.1);
            border-color: #3b82f6;
        }
        
        .form-hint {
            font-size: 13px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        body.dark .form-hint {
            color: #9ca3af;
        }
        
        .receipt-card {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.05) 0%, rgba(139, 92, 246, 0.05) 100%);
            border: 2px solid #93c5fd;
        }
        
        body.dark .receipt-card {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
            border-color: #3b82f6;
        }
        
        .form-group input[type="file"] {
            padding: 16px;
            border: 2px solid #e5e7eb;
            background: white;
            cursor: pointer;
        }
        
        .form-group input[type="file"]:hover {
            border-color: #3b82f6;
            background: rgba(59, 130, 246, 0.03);
        }
        
        body.dark .form-group input[type="file"] {
            background: #374151;
            border-color: #4b5563;
        }
        
        body.dark .form-group input[type="file"]:hover {
            border-color: #3b82f6;
            background: rgba(59, 130, 246, 0.1);
        }
        
        .form-actions {
            display: flex;
            gap: 16px;
            padding-top: 8px;
            justify-content: flex-end;
        }
        
        .save-btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            font-family: 'Roboto', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 16px rgba(59, 130, 246, 0.3);
        }
        
        .save-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.4);
        }
        
        .save-btn:active {
            transform: translateY(0);
        }
        
        .cancel-btn {
            padding: 12px 24px;
            background: white;
            color: #6b7280;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            font-family: 'Roboto', sans-serif;
            text-decoration: none;
            text-align: center;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .cancel-btn:hover {
            background: #f9fafb;
            border-color: #d1d5db;
            color: #374151;
        }
        
        body.dark .cancel-btn {
            background: #374151;
            border-color: #4b5563;
            color: #d1d5db;
        }
        
        body.dark .cancel-btn:hover {
            background: #4b5563;
            border-color: #6b7280;
            color: #f3f4f6;
        }
        
        .spinner {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* ── Receipt scan buttons ── */
        #scan-receipt-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Roboto', sans-serif;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
            white-space: nowrap;
        }
        #scan-receipt-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(59, 130, 246, 0.4);
        }
        #scan-receipt-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        #camera-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: white;
            color: #374151;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Roboto', sans-serif;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        #camera-btn:hover {
            border-color: #3b82f6;
            color: #3b82f6;
            background: rgba(59, 130, 246, 0.04);
        }
        body.dark #camera-btn {
            background: #374151;
            border-color: #4b5563;
            color: #d1d5db;
        }
        body.dark #camera-btn:hover {
            border-color: #3b82f6;
            color: #60a5fa;
        }

        /* ── AI-filled field highlight ── */
        .ai-filled {
            border-color: #10b981 !important;
            background: rgba(16, 185, 129, 0.05) !important;
            transition: border-color 0.3s, background 0.3s;
        }
        body.dark .ai-filled {
            background: rgba(16, 185, 129, 0.1) !important;
        }

        /* ── Camera modal ── */
        #shutter-btn:hover {
            transform: scale(1.06);
            box-shadow: 0 0 0 6px rgba(59, 130, 246, 0.35);
        }
        #shutter-btn:active {
            transform: scale(0.95);
        }
        
        @media (max-width: 768px) {
            .main-fields-card {
                grid-template-columns: 1fr;
            }
            
            .recurring-fields {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .cancel-btn {
                flex: 1;
            }
        }
    </style>
</head>
<body class="<?php echo htmlspecialchars($settings['theme']); ?>">
    <div class="app">
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
                        <label for="merchant">Merchant</label>
                        <input type="text" name="merchant" id="merchant" placeholder="Enter merchant name" value="<?php echo htmlspecialchars($merchant); ?>">
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

                <!-- ============================================================
                     RECEIPT CARD — replaces the original single file-input card
                     ============================================================ -->
                <?php if ($mode === 'add'): ?>
                    <div class="expense-card receipt-card">

                        <!-- Button row: Camera + Scan file -->
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
                            <div>
                                <p style="font-size:15px; font-weight:600; color:#374151; margin:0;">Receipt</p>
                                <p style="font-size:13px; color:#6b7280; margin:4px 0 0;">Upload, scan a file, or use your camera</p>
                            </div>
                            <div style="display:flex; gap:10px;">
                                <button type="button" id="camera-btn" onclick="openCamera()">
                                    <i class="fas fa-camera"></i> <span>Camera</span>
                                </button>
                                <button type="button" id="scan-receipt-btn" onclick="triggerReceiptScan()">
                                    <i class="fas fa-magic"></i> <span id="scan-btn-text">Scan file</span>
                                </button>
                            </div>
                        </div>

                        <!-- Hidden file input used by the Scan file button -->
                        <input type="file" id="scan-file-input" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="handleReceiptScan(event)">

                        <!-- Status banners -->
                        <div id="scan-status" style="display:none; align-items:center; gap:10px; padding:10px 14px; border-radius:8px; background:rgba(59,130,246,0.08); border:1px solid #93c5fd; margin-bottom:16px;">
                            <i class="fas fa-spinner fa-spin" style="color:#3b82f6;"></i>
                            <span id="scan-status-text" style="font-size:13px; color:#1d4ed8;">Reading receipt…</span>
                        </div>
                        <div id="scan-success" style="display:none; align-items:center; gap:10px; padding:10px 14px; border-radius:8px; background:rgba(16,185,129,0.08); border:1px solid #6ee7b7; margin-bottom:16px;">
                            <i class="fas fa-check-circle" style="color:#059669;"></i>
                            <span style="font-size:13px; color:#065f46;">Fields filled from receipt — review and save.</span>
                            <button type="button" onclick="resetScanUI()" style="margin-left:auto; font-size:12px; padding:3px 10px; border-radius:6px; border:1px solid #6ee7b7; background:transparent; cursor:pointer; color:#065f46;">Scan again</button>
                        </div>
                        <div id="scan-error" style="display:none; align-items:center; gap:10px; padding:10px 14px; border-radius:8px; background:rgba(239,68,68,0.08); border:1px solid #fca5a5; margin-bottom:16px;">
                            <i class="fas fa-exclamation-circle" style="color:#dc2626;"></i>
                            <span id="scan-error-text" style="font-size:13px; color:#991b1b;"></span>
                        </div>

                        <!-- Manual upload (always available) -->
                        <div class="form-group">
                            <label for="receipt">Or upload file manually</label>
                            <input type="file" name="receipt" id="receipt" accept="image/*,application/pdf">
                            <small class="form-hint">Supported formats: JPG, PNG, PDF</small>
                        </div>
                    </div>
                <?php endif; ?>
                <!-- ============================================================ -->

                <!-- Action Buttons -->
                <div class="form-actions">
                    <button type="submit" class="save-btn" id="save-btn">
                        <i class="fas fa-save"></i>
                        <span class="btn-text"><?php echo ($mode === 'edit') ? 'Update Expense' : 'Save Expense'; ?></span>
                        <span class="spinner" style="display: none;"><i class="fas fa-spinner fa-spin"></i></span>
                    </button>
                    <a href="dashboard.php" class="cancel-btn">Cancel</a>
                </div>
            </form>
        </div>

        <!-- ============================================================
             CAMERA MODAL
             ============================================================ -->
        <div id="camera-modal" style="display:none; position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,0.85); align-items:center; justify-content:center;">
            <div id="camera-container" style="position:relative; width:100%; max-width:540px; margin:0 16px; background:#111; border-radius:20px; overflow:hidden; box-shadow:0 24px 64px rgba(0,0,0,0.6);">

                <!-- Header -->
                <div style="display:flex; align-items:center; justify-content:space-between; padding:16px 20px; background:rgba(255,255,255,0.05); border-bottom:1px solid rgba(255,255,255,0.08);">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:8px; height:8px; border-radius:50%; background:#ef4444;" id="rec-dot"></div>
                        <span style="font-size:14px; font-weight:600; color:#fff;" id="camera-header-text">Position receipt in frame</span>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button type="button" onclick="switchCamera()" id="switch-cam-btn" title="Switch camera"
                            style="background:rgba(255,255,255,0.1); border:none; color:#fff; width:36px; height:36px; border-radius:50%; cursor:pointer; font-size:14px; display:flex; align-items:center; justify-content:center;">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                        <button type="button" onclick="closeCamera()"
                            style="background:rgba(255,255,255,0.1); border:none; color:#fff; width:36px; height:36px; border-radius:50%; cursor:pointer; font-size:14px; display:flex; align-items:center; justify-content:center;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>

                <!-- Viewfinder -->
                <div style="position:relative; background:#000; aspect-ratio:4/3; overflow:hidden;">
                    <video id="camera-video" autoplay playsinline muted
                        style="width:100%; height:100%; object-fit:cover; display:block;"></video>
                    <canvas id="camera-canvas" style="display:none;"></canvas>

                    <!-- Receipt guide overlay -->
                    <div id="camera-guide" style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; pointer-events:none;">
                        <div style="width:88%; height:82%; border:2px solid rgba(255,255,255,0.5); border-radius:10px; box-shadow:0 0 0 9999px rgba(0,0,0,0.35); position:relative;">
                            <span style="position:absolute; top:-22px; left:50%; transform:translateX(-50%); font-size:11px; color:rgba(255,255,255,0.7); white-space:nowrap; letter-spacing:0.5px;">ALIGN RECEIPT WITHIN FRAME</span>
                            <!-- Corner marks -->
                            <div style="position:absolute; top:-2px; left:-2px; width:18px; height:18px; border-top:3px solid #3b82f6; border-left:3px solid #3b82f6; border-radius:3px 0 0 0;"></div>
                            <div style="position:absolute; top:-2px; right:-2px; width:18px; height:18px; border-top:3px solid #3b82f6; border-right:3px solid #3b82f6; border-radius:0 3px 0 0;"></div>
                            <div style="position:absolute; bottom:-2px; left:-2px; width:18px; height:18px; border-bottom:3px solid #3b82f6; border-left:3px solid #3b82f6; border-radius:0 0 0 3px;"></div>
                            <div style="position:absolute; bottom:-2px; right:-2px; width:18px; height:18px; border-bottom:3px solid #3b82f6; border-right:3px solid #3b82f6; border-radius:0 0 3px 0;"></div>
                        </div>
                    </div>

                    <!-- Preview image shown after capture -->
                    <img id="camera-preview-img" style="display:none; position:absolute; inset:0; width:100%; height:100%; object-fit:cover;" alt="Captured receipt">

                    <!-- Torch toggle (mobile only, shown when supported) -->
                    <button type="button" id="torch-btn" onclick="toggleTorch()" style="display:none; position:absolute; bottom:14px; left:14px; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.2); color:#fff; padding:6px 12px; border-radius:20px; font-size:12px; cursor:pointer; align-items:center; gap:6px;">
                        <i class="fas fa-bolt"></i> Flash
                    </button>
                </div>

                <!-- Controls -->
                <div style="padding:20px 24px; display:flex; align-items:center; justify-content:center; gap:20px; background:#111;">

                    <!-- Retake (hidden until after capture) -->
                    <button type="button" id="retake-btn" onclick="retakePhoto()" style="display:none; background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:10px 20px; border-radius:10px; font-size:14px; font-weight:500; cursor:pointer; font-family:'Roboto',sans-serif; align-items:center; gap:8px;">
                        <i class="fas fa-redo"></i> Retake
                    </button>

                    <!-- Shutter -->
                    <button type="button" id="shutter-btn" onclick="capturePhoto()"
                        style="width:68px; height:68px; border-radius:50%; border:4px solid #fff; background:linear-gradient(135deg,#3b82f6,#8b5cf6); cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:22px; color:#fff; transition:transform 0.1s, box-shadow 0.2s; box-shadow:0 0 0 4px rgba(59,130,246,0.3);">
                        <i class="fas fa-camera"></i>
                    </button>

                    <!-- Use photo (hidden until after capture) -->
                    <button type="button" id="use-photo-btn" onclick="usePhoto()" style="display:none; background:linear-gradient(135deg,#3b82f6,#8b5cf6); border:none; color:#fff; padding:10px 20px; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; font-family:'Roboto',sans-serif; align-items:center; gap:8px; box-shadow:0 4px 12px rgba(59,130,246,0.4);">
                        <i class="fas fa-check"></i> Use photo
                    </button>
                </div>

                <!-- Camera error state -->
                <div id="camera-error-state" style="display:none; padding:40px 24px; text-align:center;">
                    <i class="fas fa-video-slash" style="font-size:40px; color:#6b7280; margin-bottom:16px; display:block;"></i>
                    <p style="color:#d1d5db; font-size:15px; margin:0 0 8px;">Camera access denied</p>
                    <p style="color:#6b7280; font-size:13px; margin:0 0 20px;">Allow camera access in your browser settings, or use "Scan file" to upload an image instead.</p>
                    <button type="button" onclick="closeCamera()" style="background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.15); color:#fff; padding:8px 20px; border-radius:8px; cursor:pointer; font-size:14px;">
                        Close
                    </button>
                </div>

            </div>
        </div>
        <!-- ============================================================ -->

        <?php include 'footer.html'; ?>
    </div>

    <script src="js/theme-toggle.js?v=<?php echo filemtime('js/theme-toggle.js'); ?>"></script>
    <script src="js/notifications.js?v=<?php echo filemtime('js/notifications.js'); ?>"></script>

    <script>
        // Active nav link
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
                if (link.textContent.trim() === pageMapping[currentPage]) {
                    link.classList.add('active');
                }
            });
        });
    </script>

    <script>
        // Toggle recurring fields visibility
        (function() {
            const chk = document.getElementById('is_recurring');
            const box = document.getElementById('recurring-fields');
            if (!chk || !box) return;
            function sync() { box.style.display = chk.checked ? 'grid' : 'none'; }
            chk.addEventListener('change', sync);
            sync();
        })();
    </script>

    <!-- ============================================================
         RECEIPT SCAN — shared helpers + file-upload flow
         ============================================================ -->
    <script>
    (function () {

        /* ── Shared field helper (used by both file scan and camera) ── */
        window.setFieldAiFilled = function (id, value) {
            if (!value) return;
            const el = document.getElementById(id);
            if (!el) return;
            el.value = value;
            el.classList.add('ai-filled');
        };

        /* ── Banner helper ── */
        function showBanner(id) {
            ['scan-status', 'scan-success', 'scan-error'].forEach(b => {
                document.getElementById(b).style.display = (b === id) ? 'flex' : 'none';
            });
        }

        /* ── Reset scan UI ── */
        window.resetScanUI = function () {
            ['scan-status', 'scan-success', 'scan-error'].forEach(b => {
                document.getElementById(b).style.display = 'none';
            });
            document.getElementById('scan-file-input').value = '';
            ['amount', 'merchant', 'date', 'description', 'category_id', 'payment_method'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.classList.remove('ai-filled');
            });
        };

        /* ── Trigger file picker ── */
        window.triggerReceiptScan = function () {
            document.getElementById('scan-file-input').click();
        };

        /* ── Handle selected/dropped file ── */
        window.handleReceiptScan = async function (event) {
            const file = event.target.files[0];
            if (!file) return;
            await sendToScanner(file);
        };

        /* ── Core: POST image to scan_receipt.php and fill form ── */
        window.sendToScanner = async function (fileOrBlob, filename) {
            const btn = document.getElementById('scan-receipt-btn');
            btn.disabled = true;
            document.getElementById('scan-btn-text').textContent = 'Scanning…';
            showBanner('scan-status');
            document.getElementById('scan-status-text').textContent = 'Extracting details with AI…';

            try {
                const formData = new FormData();
                formData.append('receipt_scan', fileOrBlob, filename || fileOrBlob.name || 'receipt.jpg');

                const res  = await fetch('scan_receipt.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (!res.ok || data.error) throw new Error(data.error || 'Unexpected server error');

                setFieldAiFilled('merchant',       data.merchant);
                setFieldAiFilled('date',           data.date);
                setFieldAiFilled('amount',         data.amount);
                setFieldAiFilled('description',    data.description);

                if (data.payment_method) setFieldAiFilled('payment_method', data.payment_method);

                if (data.category_id) {
                    const sel = document.getElementById('category_id');
                    if (sel.querySelector(`option[value="${data.category_id}"]`)) {
                        sel.value = data.category_id;
                        sel.classList.add('ai-filled');
                    }
                }

                showBanner('scan-success');

            } catch (err) {
                document.getElementById('scan-error-text').textContent =
                    err.message || 'Could not read receipt. Please fill in the form manually.';
                showBanner('scan-error');
            } finally {
                btn.disabled = false;
                document.getElementById('scan-btn-text').textContent = 'Scan file';
            }
        };

    })();
    </script>

    <!-- ============================================================
         CAMERA — modal open/close, stream, capture, send
         ============================================================ -->
    <script>
    (function () {
        let stream       = null;
        let capturedBlob = null;
        let facingMode   = 'environment'; // back camera first (best for receipts)
        let torchOn      = false;
        let torchTrack   = null;

        /* ── Open / close ── */
        window.openCamera = async function () {
            document.getElementById('camera-modal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
            resetCameraUI();
            await startStream();
        };

        window.closeCamera = function () {
            stopStream();
            document.getElementById('camera-modal').style.display = 'none';
            document.body.style.overflow = '';
        };

        /* ── Stream management ── */
        async function startStream() {
            stopStream();
            const video      = document.getElementById('camera-video');
            const errorState = document.getElementById('camera-error-state');
            const guide      = document.getElementById('camera-guide');

            errorState.style.display = 'none';
            video.style.display      = 'block';
            guide.style.display      = 'flex';

            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode, width: { ideal: 1920 }, height: { ideal: 1080 } }
                });
                video.srcObject = stream;

                // Torch support detection
                torchTrack = stream.getVideoTracks()[0];
                const caps = torchTrack.getCapabilities?.() ?? {};
                const torchBtn = document.getElementById('torch-btn');
                torchBtn.style.display = caps.torch ? 'flex' : 'none';

            } catch (err) {
                video.style.display      = 'none';
                guide.style.display      = 'none';
                errorState.style.display = 'block';
                console.error('Camera error:', err);
            }
        }

        function stopStream() {
            if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
            torchOn    = false;
            torchTrack = null;
        }

        /* ── Switch camera (front ↔ back) ── */
        window.switchCamera = async function () {
            facingMode = facingMode === 'environment' ? 'user' : 'environment';
            await startStream();
        };

        /* ── Torch ── */
        window.toggleTorch = async function () {
            if (!torchTrack) return;
            torchOn = !torchOn;
            try {
                await torchTrack.applyConstraints({ advanced: [{ torch: torchOn }] });
                const btn = document.getElementById('torch-btn');
                btn.style.background  = torchOn ? 'rgba(251,191,36,0.3)' : 'rgba(0,0,0,0.5)';
                btn.style.borderColor = torchOn ? '#fbbf24' : 'rgba(255,255,255,0.2)';
            } catch (e) { /* torch not supported on this device */ }
        };

        /* ── Capture frame ── */
        window.capturePhoto = function () {
            const video   = document.getElementById('camera-video');
            const canvas  = document.getElementById('camera-canvas');
            const preview = document.getElementById('camera-preview-img');
            const guide   = document.getElementById('camera-guide');

            canvas.width  = video.videoWidth  || 1280;
            canvas.height = video.videoHeight || 720;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

            // White flash effect
            const flash = document.createElement('div');
            flash.style.cssText = 'position:absolute;inset:0;background:#fff;opacity:0.8;pointer-events:none;z-index:10;transition:opacity 0.3s;';
            video.parentElement.appendChild(flash);
            setTimeout(() => { flash.style.opacity = '0'; setTimeout(() => flash.remove(), 300); }, 50);

            canvas.toBlob(blob => {
                capturedBlob = blob;
                preview.src  = URL.createObjectURL(blob);
            }, 'image/jpeg', 0.92);

            // Swap to review mode
            video.style.display   = 'none';
            preview.style.display = 'block';
            guide.style.display   = 'none';

            document.getElementById('shutter-btn').style.display  = 'none';
            document.getElementById('retake-btn').style.display   = 'flex';
            document.getElementById('use-photo-btn').style.display = 'flex';
            document.getElementById('camera-header-text').textContent = 'Review captured receipt';
            document.getElementById('rec-dot').style.background   = '#10b981';

            stopStream(); // save battery while reviewing
        };

        /* ── Retake ── */
        window.retakePhoto = async function () {
            capturedBlob = null;
            resetCameraUI();
            await startStream();
        };

        /* ── Send captured photo to scan_receipt.php ── */
        window.usePhoto = async function () {
            if (!capturedBlob) return;
            closeCamera();
            // Delegate to the shared sendToScanner helper
            await window.sendToScanner(capturedBlob, 'camera_receipt.jpg');
        };

        /* ── Reset camera UI to live-view state ── */
        function resetCameraUI() {
            document.getElementById('camera-video').style.display        = 'block';
            document.getElementById('camera-preview-img').style.display  = 'none';
            document.getElementById('camera-guide').style.display        = 'flex';
            document.getElementById('shutter-btn').style.display         = 'flex';
            document.getElementById('retake-btn').style.display          = 'none';
            document.getElementById('use-photo-btn').style.display       = 'none';
            document.getElementById('camera-header-text').textContent    = 'Position receipt in frame';
            document.getElementById('rec-dot').style.background          = '#ef4444';
            capturedBlob = null;
        }

        // Close on backdrop click
        document.getElementById('camera-modal').addEventListener('click', function (e) {
            if (e.target === this) closeCamera();
        });

        // Close on Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' &&
                document.getElementById('camera-modal').style.display === 'flex') {
                closeCamera();
            }
        });

    })();
    </script>

</body>
</html>
