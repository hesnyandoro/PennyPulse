<?php
require 'config.php';

// Session must be started to access session variables
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
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
    $date = filter_input(INPUT_POST, 'date', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
    $payment_method = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_STRING);
    $merchant = filter_input(INPUT_POST, 'merchant', FILTER_SANITIZE_STRING);

    // --- Use Prepared Statement for UPDATE ---
    $sql = "UPDATE expenses SET amount=?, date=?, description=?, category_id=?, payment_method=?, merchant=? WHERE id=? AND user_id=?";
    $update_stmt = $conn->prepare($sql);
    // Note the types: d=double, s=string, i=integer
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Expense</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
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
</body>
</html>