<?php
require 'config.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$expense_id = intval($_GET['id']);
$expense = $conn->query("SELECT * FROM expenses WHERE id=$expense_id AND user_id=$user_id")->fetch_assoc();

if (!$expense) {
    header('Location: view_expenses.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $amount = floatval($_POST['amount']);
    $date = $conn->real_escape_string($_POST['date']);
    $description = $conn->real_escape_string($_POST['description']);
    $category_id = intval($_POST['category_id']);
    $payment_method = $conn->real_escape_string($_POST['payment_method']);
    $merchant = $conn->real_escape_string($_POST['merchant'] ?? '');

    $sql = "UPDATE expenses SET amount=$amount, date='$date', description='$description', 
            category_id=$category_id, payment_method='$payment_method', merchant='$merchant' 
            WHERE id=$expense_id AND user_id=$user_id";
    if ($conn->query($sql)) {
        header('Location: view_expenses.php');
        exit;
    } else {
        $error = "Failed to update expense.";
    }
}

$categories = $conn->query("SELECT id, name FROM categories WHERE user_id IS NULL OR user_id=$user_id");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Expense</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <h1>Edit Expense</h1>
    <?php if (isset($error)) echo "<p style='color:red;'>$error</p>"; ?>
    <form method="POST">
        <input type="number" step="0.01" name="amount" value="<?php echo $expense['amount']; ?>" required><br>
        <input type="date" name="date" value="<?php echo $expense['date']; ?>" required><br>
        <textarea name="description"><?php echo $expense['description']; ?></textarea><br>
        <select name="category_id" required>
            <?php while ($cat = $categories->fetch_assoc()) {
                $selected = $cat['id'] == $expense['category_id'] ? 'selected' : '';
                echo "<option value='{$cat['id']}' $selected>{$cat['name']}</option>";
            } ?>
        </select><br>
        <select name="payment_method" required>
            <?php
            $methods = ['credit_card' => 'Credit Card', 'debit_card' => 'Debit Card', 'cash' => 'Cash', 'mpesa' => 'M-Pesa'];
            foreach ($methods as $value => $label) {
                $selected = $value == $expense['payment_method'] ? 'selected' : '';
                echo "<option value='$value' $selected>$label</option>";
            }
            ?>
        </select><br>
        <input type="text" name="merchant" value="<?php echo $expense['merchant']; ?>"><br>
        <button type="submit">Update Expense</button>
    </form>
    <a href="view_expenses.php">Back to Expenses</a>
</body>
</html>