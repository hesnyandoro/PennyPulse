<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php?form=login');
    exit;
}

$user_id = $_SESSION['user_id'];
$expense_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$expense_id) {
    header('Location: view_expenses.php');
    exit;
}

// --- Use Prepared Statement for SELECT ---
$stmt = $conn->prepare("SELECT * FROM expenses WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $expense_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$expense = $result->fetch_assoc();

if (!$expense) {
    header('Location: view_expenses.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize and validate all inputs
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $date = filter_input(INPUT_POST, 'date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
    $payment_method = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $merchant = filter_input(INPUT_POST, 'merchant', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    // --- Use Prepared Statement for UPDATE ---
    $sql = "UPDATE expenses SET amount=?, date=?, description=?, category_id=?, payment_method=?, merchant=? WHERE id=? AND user_id=?";
    $update_stmt = $conn->prepare($sql);
    $update_stmt->bind_param("dssisiii", $amount, $date, $description, $category_id, $payment_method, $merchant, $expense_id, $user_id);
    
    if ($update_stmt->execute()) {
        header('Location: view_expenses.php');
        exit;
    } else {
        $error = "Failed to update expense. Error: " . $update_stmt->error;
    }
}

// Fetch categories for the dropdown
$categories = $conn->query("SELECT id, name FROM categories WHERE user_id IS NULL OR user_id=$user_id");

// Fetch user data
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Fetch theme settings
$settings = getUserTheme($conn, $user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Expense</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/styles.css?v=<?php echo filemtime('css/styles.css'); ?>">
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
            <span class="avatar" data-username="<?php echo htmlspecialchars($user['username']); ?>"><?php echo htmlspecialchars(strtoupper($user['username'][0])); ?></span>
            <button id="theme-toggle" class="theme-toggle" data-theme-text="<?php echo $settings['theme'] === 'light' ? 'Dark Mode' : 'Light Mode'; ?>">
                <i class="fas <?php echo $settings['theme'] === 'light' ? 'fa-moon' : 'fa-sun'; ?>"></i>
            </button>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </nav>
    <div class="container">
        <h1>Edit Expense</h1>
        <?php if (isset($error)) echo "<p class='error-message'>$error</p>"; ?>
        <form class="expense-form" method="POST">
            <label for="amount">Amount:</label>
            <input type="number" id="amount" step="0.01" name="amount" value="<?php echo htmlspecialchars($expense['amount']); ?>" required>

            <label for="date">Date:</label>
            <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($expense['date']); ?>" required>

            <label for="description">Description:</label>
            <textarea id="description" name="description"><?php echo htmlspecialchars($expense['description']); ?></textarea>

            <label for="category_id">Category:</label>
            <select id="category_id" name="category_id" required>
                <?php while ($cat = $categories->fetch_assoc()) {
                    $selected = $cat['id'] == $expense['category_id'] ? 'selected' : '';
                    echo "<option value='{$cat['id']}' $selected>" . htmlspecialchars($cat['name']) . "</option>";
                } ?>
            </select>

            <label for="payment_method">Payment Method:</label>
            <select id="payment_method" name="payment_method" required>
                <?php
                $methods = ['credit_card' => 'Credit Card', 'debit_card' => 'Debit Card', 'cash' => 'Cash', 'mpesa' => 'M-Pesa', 'bank_transfer' => 'Bank Transfer'];
                foreach ($methods as $value => $label) {
                    $selected = $value == $expense['payment_method'] ? 'selected' : '';
                    echo "<option value='$value' $selected>$label</option>";
                }
                ?>
            </select>

            <label for="merchant">Merchant:</label>
            <input type="text" id="merchant" name="merchant" value="<?php echo htmlspecialchars($expense['merchant']); ?>">

            <button type="submit">Update Expense</button>
        </form>
        <a href="view_expenses.php" class="back-link">Back to Expenses</a>
    </div>
    <?php include 'footer.html'; ?>
    <script src="js/theme-toggle.js?v=<?php echo filemtime('js/theme-toggle.js'); ?>"></script>
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
    </script>
</body>
</html>