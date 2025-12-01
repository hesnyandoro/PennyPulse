<?php
require 'config.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php?form=login');
    exit;
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $parent_id = empty($_POST['parent_id']) ? 'NULL' : intval($_POST['parent_id']);
    $sql = "INSERT INTO categories (user_id, name, parent_id) VALUES ($user_id, '$name', $parent_id)";
    $conn->query($sql);
}

if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    $check = $conn->query("SELECT id FROM expenses WHERE category_id=$delete_id");
    if ($check->num_rows > 0) {
        $error = "Cannot delete category with assigned expenses.";
    } else {
        $conn->query("DELETE FROM categories WHERE id=$delete_id AND user_id=$user_id");
    }
}

$categories = $conn->query("SELECT id, name, parent_id FROM categories WHERE user_id IS NULL OR user_id=$user_id");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Categories</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/styles.css?v=<?php echo filemtime('css/styles.css'); ?>">
    <style>
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #ccc;
            border-radius: 5px;
            background-color: #f9f9f9;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Manage Categories</h1>
        <?php if (isset($error)) echo "<p style='color:red;'>$error</p>"; ?>
        <form method="POST">
            <input type="text" name="name" placeholder="Category Name" required><br>
            <select name="parent_id">
                <option value="">No Parent (Top-Level)</option>
                <?php 
                $cats = $conn->query("SELECT id, name FROM categories WHERE user_id IS NULL OR user_id=$user_id");
                while ($cat = $cats->fetch_assoc()) echo "<option value='{$cat['id']}'>{$cat['name']}</option>";
                ?>
            </select><br>
            <button type="submit" name="add">Add Category</button>
        </form>
        <h2>Your Categories</h2>
        <ul>
            <?php while ($cat = $categories->fetch_assoc()): ?>
                <li>
                    <?php echo $cat['name']; ?> 
                    <?php if ($cat['user_id']) echo "[Custom] <a href='categories.php?delete={$cat['id']}'>Delete</a>"; ?>
                    <?php if ($cat['parent_id']) echo "(Subcategory)"; ?>
                </li>
            <?php endwhile; ?>
        </ul>
        <a href="dashboard.php">Back to Dashboard</a>
        <?php include 'footer.html'; ?>
        <script src="js/theme-toggle.js?v=<?php echo filemtime('js/theme-toggle.js'); ?>"></script>
    </div>
</body>
</html>