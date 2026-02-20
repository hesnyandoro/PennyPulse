<?php
require_once 'config.php';
require_once 'includes/theme_handler.php';

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
    $settings = getUserTheme($conn, $user_id);
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
            <div class="logo">PennyPulse</div>
            <div class="user-profile">
                <?php if (isset($_SESSION['user_id']) && $user) { ?>
                    <span class="avatar" data-username="<?php echo htmlspecialchars($user['username'] ?? 'Guest'); ?>"><?php echo htmlspecialchars(strtoupper($user['username'][0] ?? 'U')); ?></span>
                    <button id="theme-toggle" class="theme-toggle" data-theme-text="<?php echo ($settings['theme'] ?? 'light') === 'light' ? 'Dark Mode' : 'Light Mode'; ?>">
                        <i class="fas <?php echo ($settings['theme'] ?? 'light') === 'light' ? 'fa-moon' : 'fa-sun'; ?>"></i>
                    </button>
                    <a href="dashboard.php" class="btn">Dashboard</a>
                    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i></a>
                <?php } else { ?>
                    <a href="auth.php?form=login" class="btn">Login</a>
                    <a href="auth.php?form=register" class="btn">Register</a>
                <?php } ?>
            </div>
        </nav>

        <div class="hero-section">
            <h1>Welcome to PennyPulse!</h1>
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

    <script src="js/theme-toggle.js?v=<?php echo filemtime('js/theme-toggle.js'); ?>"></script>
</body>
</html>