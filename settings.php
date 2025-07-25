<?php
session_start();
require 'config.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user data
$stmt = $conn->prepare("SELECT username, email, first_name, last_name FROM users WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error); 
    die("An internal server error occurred.");   

}
$stmt->bind_param("i", $user_id);
if (!$stmt->execute()) {
    error_log("Prepare failed: " . $conn->error); 
    die("An internal server error occurred.");

}
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    die("User not found.");
}

// Fetch user settings
$stmt = $conn->prepare("SELECT theme, language, email_notifications, in_app_notifications FROM user_settings WHERE user_id = ?");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    die("An internal server error occurred.");   

}
$stmt->bind_param("i", $user_id);
if (!$stmt->execute()) {
    error_log("Execute failed: " . $stmt->error); 
    die("An internal server error occurred.");   
}
$settings = $stmt->get_result()->fetch_assoc();
if (!$settings) {
    $settings = ['theme' => 'light', 'language' => 'en', 'email_notifications' => 1, 'in_app_notifications' => 1];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/styles.css">
    <title>Settings - Expense Tracker</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f2f5;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .bento-card {
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 0 20px rgba(0, 0, 255, 0.2);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .bento-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0 30px rgba(0, 0, 255, 0.3);
        }
        h2 {
            margin-top: 0;
        }
        form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        input, select, button {
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #ccc;
            font-size: 16px;
        }
        button {
            background-color: #007bff;
            color: white;
            border: none;
            cursor: pointer;
        }
        button:hover {
            background-color: #0056b3;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .error, .success {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        .error { background: #ffcccc; }
        .success { background: #ccffcc; }
    </style>
</head>
<body>
    <!-- Navbar -->
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
                <span class="avatar">
                    <?php echo isset($user['username'][0]) ? htmlspecialchars(strtoupper($user['username'][0])) : ''; ?>
                </span>
                <span class="username"><?php echo htmlspecialchars($user['username']); ?></span>
                <button id="theme-toggle" class="theme-toggle">
                    <i class="fas <?php echo $settings['theme'] === 'light' ? 'fa-moon' : 'fa-sun'; ?>"></i>
                </button>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </nav>
    <div class="container">

        <!-- Profile Settings -->
        <div class="bento-card">
            <h2>Profile Settings</h2>
            <form id="profileForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" placeholder="First Name" required>
                <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" placeholder="Last Name" required>
                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" placeholder="Email" required>
                <button type="submit">Update Profile</button>
            </form>
            <div id="profileMessage"></div>
        </div>

        <!-- App Preferences -->
        <div class="bento-card">
            <h2>App Preferences</h2>
            <form id="settingsForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <select name="theme">
                    <option value="light" <?php if ($settings['theme'] == 'light') echo 'selected'; ?>>Light</option>
                    <option value="dark" <?php if ($settings['theme'] == 'dark') echo 'selected'; ?>>Dark</option>
                </select>
                <select name="language">
                    <option value="en" <?php if ($settings['language'] == 'en') echo 'selected'; ?>>English</option>
                    <option value="es" <?php if ($settings['language'] == 'es') echo 'selected'; ?>>Spanish</option>
                    <option value="fr" <?php if ($settings['language'] == 'fr') echo 'selected'; ?>>French</option>
                </select>
                <input type="hidden" name="email_notifications" value="0">
                <label><input type="checkbox" name="email_notifications" value="1" <?php if ($settings['email_notifications']) echo 'checked'; ?>> Email Notifications</label>
                <input type="hidden" name="in_app_notifications" value="0">
                <label><input type="checkbox" name="in_app_notifications" value="1" <?php if ($settings['in_app_notifications']) echo 'checked'; ?>> In-App Notifications</label>
                <button type="submit">Save Preferences</button>
            </form>
            <div id="settingsMessage"></div>
        </div>

        <!-- Security Settings -->
        <div class="bento-card">
            <h2>Security Settings</h2>
            <form id="passwordForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="password" name="current_password" placeholder="Current Password" required>
                <input type="password" name="new_password" placeholder="New Password" required>
                <input type="password" name="confirm_password" placeholder="Confirm New Password" required>
                <button type="submit">Change Password</button>
            </form>
            <div id="passwordMessage"></div>
        </div>

        <!-- Data Management -->
        <div class="bento-card">
            <h2>Data Management</h2>
            <button onclick="window.location.href='export_data.php'">Export Data as CSV</button>
            <button onclick="showDeleteModal()">Delete Account</button>
            <div id="dataMessage"></div>
        </div>

        <!-- Delete Account Modal -->
        <div id="deleteModal" class="modal" role="dialog" aria-modal="true" tabindex="-1">
            <div class="modal-content">
                <h3>Confirm Account Deletion</h3>
                <p>Are you sure you want to permanently delete your account? This action cannot be undone.</p>
                <button onclick="deleteAccount()">Yes, Delete</button>
                <button onclick="closeDeleteModal()">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        // Form submission handlers
        async function submitForm(formId, url, messageDivId) {
            const form = document.getElementById(formId);
            const messageDiv = document.getElementById(messageDivId);
            const formData = new FormData(form);

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                messageDiv.className = result.success ? 'success' : 'error';
                messageDiv.textContent = result.success ? 'Changes saved successfully!' : result.error;
            } catch (error) {
                messageDiv.className = 'error';
                messageDiv.textContent = 'An error occurred. Please try again.';
            }
        }

        document.getElementById('profileForm').addEventListener('submit', (e) => {
            e.preventDefault();
            submitForm('profileForm', 'update_profile.php', 'profileMessage');
        });

        document.getElementById('settingsForm').addEventListener('submit', (e) => {
            e.preventDefault();
            submitForm('settingsForm', 'update_settings.php', 'settingsMessage');
        });

        document.getElementById('passwordForm').addEventListener('submit', (e) => {
            e.preventDefault();
            const form = document.getElementById('passwordForm');
            if (form.new_password.value !== form.confirm_password.value) {
                document.getElementById('passwordMessage').className = 'error';
                document.getElementById('passwordMessage').textContent = 'Passwords do not match.';
                return;
            }
            submitForm('passwordForm', 'change_password.php', 'passwordMessage');
        });

        // Modal functions
        function showDeleteModal() {
            const modal = document.getElementById('deleteModal');
            modal.style.display = 'flex';
            modal.focus();
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

async function deleteAccount() {
    const messageDiv = document.getElementById('dataMessage');
    closeDeleteModal(); 
    const csrfToken = document.querySelector('input[name="csrf_token"]').value; 
    const formData = new FormData();
    formData.append('csrf_token', csrfToken); 

    try {
        const response = await fetch('delete_account.php', {
            method: 'POST',
            body: formData 
        });
        const result = await response.json();
        if (result.success) {
            messageDiv.className = 'success';
            messageDiv.textContent = 'Account deleted successfully. Redirecting...';
            setTimeout(() => window.location.href = 'login.php', 2000);
        } else {
            messageDiv.className = 'error';
            messageDiv.textContent = result.error;
        }
    } catch (error) {
        messageDiv.className = 'error';
        messageDiv.textContent = 'An error occurred. Please try again.';
        console.error('Fetch error:', error); 
    }
}

        document.getElementById('theme-toggle').addEventListener('click', async function() {
            const currentTheme = '<?php echo $settings['theme']; ?>';
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            const formData = new FormData();
            formData.append('theme', newTheme);
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            try {
                const response = await fetch('update_settings.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    location.reload();
                }
            } catch (error) {
                alert('Failed to change theme.');
            }
        });
    </script>
</body>
</html>