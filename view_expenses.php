<?php
require 'config.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$sort = $_GET['sort'] ?? 'date';
$order = $_GET['order'] ?? 'DESC';
$filter_category = $_GET['category'] ?? '';
$filter_payment_method = $_GET['payment_method'] ?? '';
$filter_date_start = $_GET['date_start'] ?? '';
$filter_date_end = $_GET['date_end'] ?? '';

// Prepare query with parameterized statements to prevent SQL injection
$query = "SELECT e.*, c.name AS category_name FROM expenses e 
          LEFT JOIN categories c ON e.category_id = c.id 
          WHERE e.user_id = ?";
$params = [$user_id];
$types = "i";

if ($filter_category) {
    $query .= " AND e.category_id = ?";
    $params[] = $filter_category;
    $types .= "i";
}
if ($filter_payment_method) {
    $query .= " AND e.payment_method = ?";
    $params[] = $filter_payment_method;
    $types .= "s";
}
if ($filter_date_start) {
    $query .= " AND e.date >= ?";
    $params[] = $filter_date_start;
    $types .= "s";
}
if ($filter_date_end) {
    $query .= " AND e.date <= ?";
    $params[] = $filter_date_end;
    $types .= "s";
}
$query .= " ORDER BY $sort $order";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$expenses = $stmt->get_result();

// Fetch categories and payment methods for filters
$categories = $conn->query("SELECT id, name FROM categories WHERE user_id IS NULL OR user_id=$user_id");
$payment_methods = $conn->query("SELECT DISTINCT payment_method FROM expenses WHERE user_id = $user_id AND payment_method IS NOT NULL");

// Calculate summary metrics
$total_transactions = $expenses->num_rows;
$category_freq = [];
$category_spend = [];
$top_category = 'N/A';
$highest_spend = 0;

$expenses->data_seek(0); // Reset pointer
while ($exp = $expenses->fetch_assoc()) {
    $cat = $exp['category_name'] ?? 'N/A';
    $category_freq[$cat] = ($category_freq[$cat] ?? 0) + 1;
    $category_spend[$cat] = ($category_spend[$cat] ?? 0) + $exp['amount'];
    if ($category_spend[$cat] > $highest_spend) {
        $highest_spend = $category_spend[$cat];
        $top_category = $cat;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Expenses - Expense Tracker</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1E3A8A; /* Trust Blue */
            --secondary: #15803d; /* Prosperity Green */
            --neutral: #FFFFFF; /* Clean White */
            --neutral-accent: #E5E7EB; /* Soft Gray */
            --glow: #2DD4BF; /* Teal Glow */
            --warning: #EF4444; /* Soft Red */
            --dark-bg: #1F2937; /* Dark Gray */
            --dark-text: #D1D5DB; /* Light Gray */
            --background: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#1F2937' : '#FFFFFF'; ?>;
            --text: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#D1D5DB' : '#333'; ?>;
            --card-bg: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#1F2937' : '#FFFFFF'; ?>;
            --filter-bg: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#1F2937' : '#FFFFFF'; ?>;
            --border: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#D1D5DB' : '#E5E7EB'; ?>;
            --table-header-bg: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#374151' : '#E5E7EB'; ?>;
            --input-bg: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#374151' : '#FFFFFF'; ?>;
            --input-border: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#4B5563' : '#D1D5DB'; ?>;
            --input-text: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#D1D5DB' : '#333'; ?>;
            --button-bg: #15803d;
            --button-hover-bg: #166534;

            --edit-bg: #10B981; /* Success Green */
            --edit-hover-bg: #059669;
            --delete-bg: #EF4444; /* Warning Red */
            --delete-hover-bg: #DC2626;
            --progress-bg: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#4B5563' : '#E5E7EB'; ?>;
        }

        body {
            font-family: 'Roboto', sans-serif;
            background-color: var(--background);
            color: var(--text);
            margin: 0;
            padding: 20px;
            transition: background-color 0.3s, color 0.3s;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        h1 {
            font-size: 28px;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 20px;
        }

        form {
            background: var(--filter-bg);
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        select, input, button {
            padding: 8px 12px;
            border-radius: 5px;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--input-text);
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
            transition: background-color 0.3s, color 0.3s, border-color 0.3s;
        }

        select:focus, input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 5px rgba(30, 58, 138, 0.3);
        }

        button {
            background-color: var(--button-bg);
            color: var(--neutral);
            border: none;
            cursor: pointer;
            font-weight: 500;
            padding: 8px 15px;
        }

        button:hover {
            background-color: var(--button-hover-bg);
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .summary-card {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2), 0 0 10px var(--glow);
        }

        .summary-card h3 {
            font-size: 16px;
            margin-bottom: 10px;
            color: var(--neutral-accent);
        }

        .summary-card p {
            font-size: 24px;
            font-weight: 600;
            color: var(--primary);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background: var(--card-bg);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        th {
            background-color: var(--table-header-bg);
            font-weight: 600;
        }

        tr:nth-child(even) {
            background-color: <?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? '#374151' : '#f9fafb'; ?>;
        }

        .action-btn {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            transition: transform 0.2s, background-color 0.3s;
            position: relative;
            overflow: hidden;
        }

        .action-btn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: var(--glow);
            opacity: 0;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.5s, height 0.5s, opacity 0.5s;
        }

        .action-btn:hover::after {
            width: 200px;
            height: 200px;
            opacity: 0.3;
        }

        .edit-btn {
            background-color: var(--edit-bg);
            color: var(--neutral);
            margin-right: 5px;
        }

        .edit-btn:hover {
            background-color: var(--edit-hover-bg);
            transform: translateY(-2px);
        }

        .delete-btn {
            background-color: var(--delete-bg);
            color: var(--neutral);
        }

        .delete-btn:hover {
            background-color: var(--delete-hover-bg);
            transform: translateY(-2px);
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            background: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .empty-state p {
            font-size: 1.2rem;
            color: var(--neutral-accent);
            margin-bottom: 20px;
        }

        .add-expense-btn {
            background: var(--primary);
            color: var(--neutral);
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s, transform 0.1s;
        }

        .add-expense-btn:hover {
            background: #152e6f;
            transform: translateY(-2px);
        }

        .export-btn {
            background: var(--primary);
            color: var(--neutral);
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s, transform 0.1s;
            margin-right: 10px;
        }

        .export-btn:hover {
            background: #152e6f;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Your Expenses</h1>

        <!-- Summary Metrics -->
        <div class="summary-cards">
            <div class="summary-card">
                <h3>Total Transactions</h3>
                <p><?php echo $total_transactions; ?></p>
            </div>
            <div class="summary-card">
                <h3>Top Category</h3>
                <p><?php echo $top_category; ?></p>
            </div>
        </div>

        <!-- Filter Controls -->
        <form method="GET">
            <select name="sort">
                <option value="date" <?php echo $sort === 'date' ? 'selected' : ''; ?>>Date</option>
                <option value="amount" <?php echo $sort === 'amount' ? 'selected' : ''; ?>>Amount</option>
                <option value="category_id" <?php echo $sort === 'category_id' ? 'selected' : ''; ?>>Category</option>
            </select>
            <select name="order">
                <option value="ASC" <?php echo $order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                <option value="DESC" <?php echo $order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
            </select>
            <select name="category">
                <option value="">All Categories</option>
                <?php $categories->data_seek(0); while ($cat = $categories->fetch_assoc()) echo "<option value='{$cat['id']}' " . ($filter_category == $cat['id'] ? 'selected' : '') . ">{$cat['name']}</option>"; ?>
            </select>
            <select name="payment_method">
                <option value="">All Payment Methods</option>
                <?php $payment_methods->data_seek(0); while ($method = $payment_methods->fetch_assoc()) echo "<option value='{$method['payment_method']}' " . ($filter_payment_method == $method['payment_method'] ? 'selected' : '') . ">{$method['payment_method']}</option>"; ?>
            </select>
            <input type="date" name="date_start" value="<?php echo htmlspecialchars($filter_date_start); ?>">
            <input type="date" name="date_end" value="<?php echo htmlspecialchars($filter_date_end); ?>">
            <button type="submit">Filter</button>
        </form>

        <!-- Export Buttons -->
        <div style="margin-bottom: 20px;">
            <button class="export-btn" onclick="exportData('filtered')">Export Filtered (PDF/CSV)</button>
            <button class="export-btn" onclick="exportData('all')">Export All (PDF/CSV)</button>
        </div>

        <?php
        $expenses->data_seek(0); // Reset pointer
        if ($expenses->num_rows > 0) {
        ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Payment Method</th>
                        <th>Merchant</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($exp = $expenses->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($exp['date']); ?></td>
                            <td><?php echo htmlspecialchars($exp['amount']); ?></td>
                            <td><?php echo htmlspecialchars($exp['category_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($exp['description']); ?></td>
                            <td><?php echo htmlspecialchars($exp['payment_method']); ?></td>
                            <td><?php echo htmlspecialchars($exp['merchant'] ?? 'N/A'); ?></td>
                            <td>
                                <a href="edit_expense.php?id=<?php echo $exp['id']; ?>" class="action-btn edit-btn">Edit</a>
                                <a href="view_expenses.php?delete=<?php echo $exp['id']; ?>" class="action-btn delete-btn" onclick="return confirm('Are you sure?');">Delete</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php } else { ?>
            <div class="empty-state">
                <p>No expenses found!</p>
                <a href="add_expense.php" class="add-expense-btn">Add Expense</a>
            </div>
        <?php } ?>

        <a href="dashboard.php" class="add-expense-btn">Back to Dashboard</a>

        <?php
        if (isset($_GET['delete'])) {
            $delete_id = intval($_GET['delete']);
            $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $delete_id, $user_id);
            $stmt->execute();
            header('Location: view_expenses.php');
            exit;
        }
        ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/PapaParse/5.3.0/papaparse.min.js"></script>
    <script>
        function exportData(type) {
            let data = [];
            let table = document.querySelector('table');
            let headers = ['Date', 'Amount', 'Category', 'Description', 'Payment Method', 'Merchant'];

            if (type === 'filtered' && table) {
                let rows = table.querySelectorAll('tbody tr');
                rows.forEach(row => {
                    let rowData = {};
                    row.querySelectorAll('td:not(:last-child)').forEach((cell, index) => {
                        rowData[headers[index]] = cell.textContent;
                    });
                    data.push(rowData);
                });
            } else if (type === 'all') {
                // Fetch all expenses via AJAX or re-query (simplified here with current data)
                <?php
                $all_expenses = $conn->query("SELECT e.*, c.name AS category_name FROM expenses e 
                                             LEFT JOIN categories c ON e.category_id = c.id 
                                             WHERE e.user_id = $user_id");
                while ($exp = $all_expenses->fetch_assoc()) {
                    echo "data.push({
                        'Date': '{$exp['date']}',
                        'Amount': '{$exp['amount']}',
                        'Category': '" . ($exp['category_name'] ?? 'N/A') . "',
                        'Description': '{$exp['description']}',
                        'Payment Method': '{$exp['payment_method']}',
                        'Merchant': '" . ($exp['merchant'] ?? 'N/A') . "'
                    });";
                }
                ?>
            }

            // Export as CSV
            let csv = Papa.unparse(data);
            let csvBlob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            let csvLink = document.createElement('a');
            csvLink.href = URL.createObjectURL(csvBlob);
            csvLink.download = `expenses_${type}_${new Date().toISOString().split('T')[0]}.csv`;
            csvLink.click();

            // Export as PDF
            let element = document.createElement('div');
            element.innerHTML = '<h2>Expense Report</h2><table><thead><tr>' + 
                headers.map(h => `<th>${h}</th>`).join('') + '</tr></thead><tbody>' + 
                data.map(row => `<tr>${headers.map(h => `<td>${row[h] || 'N/A'}</td>`).join('')}</tr>`).join('') + '</tbody></table>';
            html2pdf().from(element).save(`expenses_${type}_${new Date().toISOString().split('T')[0]}.pdf`);
        }

        // Theme toggle (assuming a cookie-based theme system)
        document.addEventListener('DOMContentLoaded', () => {
            const theme = localStorage.getItem('theme') || 'light';
            if (theme === 'dark') {
                document.body.classList.add('dark');
                applyTheme('dark');
            } else {
                document.body.classList.add('light');
                applyTheme('light');
            }
        });

        function toggleTheme() {
            const body = document.body;
            const currentTheme = localStorage.getItem('theme') || 'light';
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            localStorage.setItem('theme', newTheme);
            body.classList.remove('light', 'dark');
            body.classList.add(newTheme);
            applyTheme(newTheme);
            document.cookie = `theme=${newTheme}; path=/`;
        }

        function applyTheme(theme) {
            if (theme === 'dark') {
                document.body.style.setProperty('--background', '#1F2937');
                document.body.style.setProperty('--text', '#D1D5DB');
                document.body.style.setProperty('--card-bg', '#1F2937');
                document.body.style.setProperty('--filter-bg', '#1F2937');
                document.body.style.setProperty('--border', '#D1D5DB');
                document.body.style.setProperty('--table-header-bg', '#374151');
                document.body.style.setProperty('--input-bg', '#374151');
                document.body.style.setProperty('--input-border', '#4B5563');
                document.body.style.setProperty('--input-text', '#D1D5DB');
                document.body.style.setProperty('--progress-bg', '#4B5563');
            } else {
                document.body.style.setProperty('--background', '#FFFFFF');
                document.body.style.setProperty('--text', '#333');
                document.body.style.setProperty('--card-bg', '#FFFFFF');
                document.body.style.setProperty('--filter-bg', '#FFFFFF');
                document.body.style.setProperty('--border', '#E5E7EB');
                document.body.style.setProperty('--table-header-bg', '#E5E7EB');
                document.body.style.setProperty('--input-bg', '#FFFFFF');
                document.body.style.setProperty('--input-border', '#D1D5DB');
                document.body.style.setProperty('--input-text', '#333');
                document.body.style.setProperty('--progress-bg', '#E5E7EB');
            }
        }

        // Add theme toggle button
        let toggleBtn = document.createElement('button');
        toggleBtn.textContent = 'Toggle Theme';
        toggleBtn.className = 'export-btn';
        toggleBtn.onclick = toggleTheme;
        toggleBtn.style.position = 'fixed';
        toggleBtn.style.top = '20px';
        toggleBtn.style.right = '20px';
        document.body.appendChild(toggleBtn);
    </script>
</body>
</html>