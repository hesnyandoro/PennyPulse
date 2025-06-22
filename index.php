<?php
require_once 'config.php';

// Check user session
$user = null;
$settings = ['theme' => 'light'];
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];

    // Fetch user data
    $query = "SELECT username FROM users WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    // Fetch user settings
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
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Tracker - Manage Your Finances</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" 
          onerror="this.onerror=null; this.href='/expense_tracker/fontawesome/css/all.min.css'">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body class="<?php echo htmlspecialchars($settings['theme']); ?>">
    <div class="app">
        <!-- Navbar -->
        <nav class="navbar">
            <div class="logo">Expense Tracker</div>
            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-home"></i> Home</a></li>
                <?php if (isset($_SESSION['user_id'])) { ?>
                    <li><a href="add_expense.php"><i class="fas fa-plus"></i> Add Expense</a></li>
                    <li><a href="view_expenses.php"><i class="fas fa-list"></i> View Expenses</a></li>
                    <li><a href="set_budget.php"><i class="fas fa-wallet"></i> Budgets</a></li>
                    <li><a href="reports.php"><i class="fas fa-chart-pie"></i> Reports</a></li>
                    <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                <?php } ?>
            </ul>
            <div class="user-profile">
                <?php if (isset($_SESSION['user_id'])) { ?>
                    <span class="avatar"><?php echo htmlspecialchars(strtoupper($user['username'][0])); ?></span>
                    <span class="username"><?php echo htmlspecialchars($user['username']); ?></span>
                    <button id="theme-toggle" class="theme-toggle">
                        <i class="fas <?php echo $settings['theme'] === 'light' ? 'fa-moon' : 'fa-sun'; ?>"></i>
                    </button>
                    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
                <?php } else { ?>
                    <a href="login.php" class="btn">Login</a>
                    <a href="register.php" class="btn">Register</a>
                <?php } ?>
            </div>
        </nav>

        <!-- Hero Section -->
        <div class="hero-section">
            <h1>Welcome to Expense Tracker!</h1>
            <p class="tagline">Take Control of Your Finances Today</p>
            <?php if (isset($_SESSION['user_id'])) { ?>
                <a href="dashboard.php" class="cta-btn">Go to Dashboard</a>
            <?php } else { ?>
                <a href="login.php" class="cta-btn">Get Started</a>
            <?php } ?>
        </div>

        <!-- Features Section -->
        <div class="features-section">
            <h2>Why Choose Us?</h2>
            <div class="features">
                <div class="feature-card">
                    <i class="fas fa-list-alt"></i>
                    <h3>Track Expenses</h3>
                    <p>Easily log and categorize your expenses to stay on top of your spending.</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-wallet"></i>
                    <h3>Set Budgets</h3>
                    <p>Create monthly budgets to manage your finances and avoid overspending.</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-chart-pie"></i>
                    <h3>Generate Reports</h3>
                    <p>Visualize your spending patterns with insightful reports and charts.</p>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <?php if (!in_array(basename($_SERVER['PHP_SELF']), ['login.php', 'register.php', 'logout.php'])) {
            include 'footer.html';
        } ?>
    </div>

    <!-- JavaScript for Theme Toggle -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const themeToggle = document.getElementById('theme-toggle');
            const body = document.body;

            // Apply saved theme from localStorage if available
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme) {
                body.className = savedTheme;
                if (themeToggle) {
                    const icon = themeToggle.querySelector('i');
                    icon.className = savedTheme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
                }
            }

            // Theme toggle functionality
            if (themeToggle) {
                themeToggle.addEventListener('click', () => {
                    const newTheme = body.classList.contains('light') ? 'dark' : 'light';
                    body.className = newTheme;
                    localStorage.setItem('theme', newTheme);

                    // Update the icon
                    const icon = themeToggle.querySelector('i');
                    icon.className = newTheme === 'light' ? 'fas fa-moon' : 'fas fa-sun';

                    // Save theme preference to server (if logged in)
                    fetch('api/update_theme.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ theme: newTheme })
                    }).catch(error => console.error('Error updating theme:', error));
                });
            }
        });
    </script>
</body>
</html>