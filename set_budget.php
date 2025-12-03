<?php
error_log("set_budget.php script started.");
ob_start();

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
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css?v=<?php echo filemtime('css/styles.css'); ?>">
    <style>
        .add-expense-section {
            max-width: 95%;
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
        
        /* Messages */
        .success-message, .error-message {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .success-message {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        
        .error-message {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        
        /* Budget Form */
        #budget-form {
            background: white;
            padding: 32px;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 32px;
        }
        
        .bento-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .bento-box.primary-box {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: 'Roboto', sans-serif;
        }
        
        .form-group label i {
            color: #3b82f6;
            font-size: 12px;
        }
        
        .required {
            color: #ef4444;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-wrapper select,
        .input-wrapper input {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
            transition: all 0.2s ease;
            background: white;
            color: #374151;
            box-sizing: border-box;
        }
        
        .input-wrapper select:focus,
        .input-wrapper input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .input-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            transition: all 0.2s ease;
        }
        
        .input-icon i.valid {
            color: #10b981;
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        
        .save-btn, .cancel-btn {
            padding: 12px 28px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-family: 'Roboto', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .save-btn {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .save-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
        }
        
        .cancel-btn {
            background: white;
            color: #6b7280;
            border: 2px solid #e5e7eb;
        }
        
        .cancel-btn:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }
        
        /* Filter Controls */
        .filter-controls {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 32px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            font-family: 'Roboto', sans-serif;
        }
        
        .filter-group select {
            padding: 10px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
            background: white;
            color: #374151;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .filter-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        /* Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }
        
        .summary-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }
        
        .summary-card h3 {
            margin: 0 0 8px 0;
            font-size: 14px;
            font-weight: 500;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .summary-card p {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
        }
        
        .summary-card::after {
            content: '';
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .summary-card:nth-child(1)::after {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }
        
        .summary-card:nth-child(2)::after {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        
        .summary-card:nth-child(3)::after {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }
        
        /* Budget Overview */
        .budget-overview {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 24px;
        }
        
        .budget-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            border-left: 4px solid #3b82f6;
            transition: all 0.3s ease;
        }
        
        .budget-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }
        
        .budget-card h3 {
            margin: 0 0 16px 0;
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
            font-family: 'Roboto', sans-serif;
        }
        
        .budget-card p {
            margin: 8px 0;
            font-size: 14px;
            color: #6b7280;
            font-family: 'Roboto', sans-serif;
        }
        
        .budget-card.under_budget {
            border-left-color: #10b981;
            background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
        }
        
        .budget-card.near_limit {
            border-left-color: #f59e0b;
            background: linear-gradient(135deg, #ffffff 0%, #fffbeb 100%);
        }
        
        .budget-card.over_budget {
            border-left-color: #ef4444;
            background: linear-gradient(135deg, #ffffff 0%, #fef2f2 100%);
        }
        
        .warning-message {
            margin: 12px 0;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .budget-card.near_limit .warning-message {
            background: #fef3c7;
            color: #92400e;
        }
        
        .budget-card.over_budget .warning-message {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .progress-bar {
            width: 100%;
            height: 10px;
            background: #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
            margin: 16px 0;
        }
        
        .progress {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .budget-card.near_limit .progress {
            background: linear-gradient(90deg, #f59e0b, #d97706);
        }
        
        .budget-card.over_budget .progress {
            background: linear-gradient(90deg, #ef4444, #dc2626);
        }
        
        .delete-btn {
            padding: 8px 16px;
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Roboto', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .delete-btn:hover {
            background: rgba(239, 68, 68, 0.2);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(239, 68, 68, 0.2);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        }
        
        .empty-state p {
            font-size: 16px;
            color: #6b7280;
            margin-bottom: 20px;
        }
        
        .add-budget-btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Roboto', sans-serif;
        }
        
        .add-budget-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
        }
        
        /* Dark Mode */
        body.dark .page-title {
            color: #f9fafb;
        }
        
        body.dark .success-message {
            background: #064e3b;
            color: #d1fae5;
        }
        
        body.dark .error-message {
            background: #7f1d1d;
            color: #fee2e2;
        }
        
        body.dark #budget-form {
            background: #1f2937;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.3);
        }
        
        body.dark .form-group label {
            color: #d1d5db;
        }
        
        body.dark .input-wrapper select,
        body.dark .input-wrapper input {
            background: #111827;
            border-color: #374151;
            color: #f9fafb;
        }
        
        body.dark .input-wrapper select:focus,
        body.dark .input-wrapper input:focus {
            border-color: #3b82f6;
            background: #1f2937;
        }
        
        body.dark .cancel-btn {
            background: #374151;
            color: #d1d5db;
            border-color: #4b5563;
        }
        
        body.dark .cancel-btn:hover {
            background: #4b5563;
        }
        
        body.dark .filter-controls {
            background: #1f2937;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.3);
        }
        
        body.dark .filter-group label {
            color: #d1d5db;
        }
        
        body.dark .filter-group select {
            background: #111827;
            border-color: #374151;
            color: #f9fafb;
        }
        
        body.dark .summary-card {
            background: #1f2937;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.3);
        }
        
        body.dark .summary-card:hover {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
        }
        
        body.dark .summary-card h3 {
            color: #9ca3af;
        }
        
        body.dark .summary-card p {
            color: #f9fafb;
        }
        
        body.dark .budget-card {
            background: #1f2937;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.3);
        }
        
        body.dark .budget-card.under_budget {
            background: linear-gradient(135deg, #1f2937 0%, #064e3b 100%);
        }
        
        body.dark .budget-card.near_limit {
            background: linear-gradient(135deg, #1f2937 0%, #78350f 100%);
        }
        
        body.dark .budget-card.over_budget {
            background: linear-gradient(135deg, #1f2937 0%, #7f1d1d 100%);
        }
        
        body.dark .budget-card h3 {
            color: #f9fafb;
        }
        
        body.dark .budget-card p {
            color: #9ca3af;
        }
        
        body.dark .progress-bar {
            background: #374151;
        }
        
        body.dark .budget-card.near_limit .warning-message {
            background: #78350f;
            color: #fef3c7;
        }
        
        body.dark .budget-card.over_budget .warning-message {
            background: #7f1d1d;
            color: #fee2e2;
        }
        
        body.dark .delete-btn {
            background: rgba(239, 68, 68, 0.2);
            border-color: rgba(239, 68, 68, 0.3);
        }
        
        body.dark .empty-state {
            background: #1f2937;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.3);
        }
        
        body.dark .empty-state p {
            color: #9ca3af;
        }
    </style>
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
            <h1 class="page-title"><i class="fas fa-wallet"></i> Manage Budgets</h1>
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
                            <label for="category_id"><i class="fas fa-tag"></i> Category <span class="required">*</span></label>
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
                            <label for="month"><i class="fas fa-calendar-alt"></i> Month <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <input type="month" name="month" id="month" required>
                                <span class="input-icon"><i class="fas fa-check"></i></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="budget_amount"><i class="fas fa-dollar-sign"></i> Budget Amount <span class="required">*</span></label>
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
                        <label for="time_filter"><i class="fas fa-clock"></i> Filter by Time:</label>
                        <select id="time_filter" name="time_filter" onchange="this.form.submit()">
                            <option value="active" <?php echo $time_filter === 'active' ? 'selected' : ''; ?>>Active Budgets</option>
                            <option value="past" <?php echo $time_filter === 'past' ? 'selected' : ''; ?>>Past Budgets</option>
                            <option value="upcoming" <?php echo $time_filter === 'upcoming' ? 'selected' : ''; ?>>Upcoming Budgets</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="status_filter"><i class="fas fa-chart-line"></i> Filter by Status:</label>
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
            <div class="summary-cards">
                <div class="summary-card">
                    <h3>Total Budgeted</h3>
                    <p>KES&nbsp;<?php echo number_format($total_budgeted, 2); ?></p>
                </div>
                <div class="summary-card">
                    <h3>Total Spent</h3>
                    <p>KES&nbsp;<?php echo number_format($total_spent, 2); ?></p>
                </div>
                <div class="summary-card">
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
                            <p>Budget: KES&nbsp;<?php echo number_format($budget['budget_amount'], 2); ?></p>
                            <p>Spent: KES&nbsp;<?php echo number_format($budget['spent'], 2); ?></p>
                            <p>Remaining: KES&nbsp;<?php echo number_format($budget['remaining'], 2); ?></p>
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