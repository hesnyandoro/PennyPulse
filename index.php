<?php
require_once 'config.php';

// Check user session
$user = null;
$settings = ['theme' => 'light'];
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $query = "SELECT username FROM users WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body class="<?php echo htmlspecialchars($settings['theme'] ?? 'light'); ?>">
    <div class="app">
        <nav class="navbar">
            <div class="logo">Expense Tracker</div>
            <div class="user-profile">
                <?php if (isset($_SESSION['user_id']) && $user) { ?>
                    <span class="avatar"><?php echo htmlspecialchars(strtoupper($user['username'][0] ?? 'U')); ?></span>
                    <span class="username"><?php echo htmlspecialchars($user['username']); ?></span>
                    <button id="theme-toggle" class="theme-toggle">
                        <i class="fas <?php echo ($settings['theme'] ?? 'light') === 'light' ? 'fa-sun' : 'fa-moon'; ?>"></i>
                    </button>
                    <a href="dashboard.php" class="btn">Dashboard</a>
                    <a href="logout.php" class="btn logout-btn">Logout</a>
                <?php } else { ?>
                    <a href="auth.php?form=login" class="btn">Login</a>
                    <a href="auth.php?form=register" class="btn">Register</a>
                <?php } ?>
            </div>
        </nav>

        <div class="hero-section">
            <h1>Welcome to Expense Tracker!</h1>
            <p class="tagline">Take Control of Your Finances Today</p>
            <?php if (isset($_SESSION['user_id'])) { ?>
                <a href="dashboard.php" class="cta-btn">Go to Dashboard</a> 
            <?php } else { ?>
                <a href="auth.php?form=register" class="cta-btn">Get Started</a>
            <?php } ?>
        </div>

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

        <?php include 'footer.html'; ?>
    </div>

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
                    icon.className = savedTheme === 'light' ? 'fas fa-sun' : 'fas fa-moon';
                }
            }

            // Theme toggle functionality
            if (themeToggle) {
                themeToggle.addEventListener('click', () => {
                    const newTheme = body.classList.contains('light') ? 'dark' : 'light';
                    body.className = newTheme;
                    localStorage.setItem('theme', newTheme);
                    const icon = themeToggle.querySelector('i');
                    icon.className = newTheme === 'light' ? 'fas fa-sun' : 'fas fa-moon';
                    // Save theme preference to server
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