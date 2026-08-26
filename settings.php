<?php
session_start();
require 'config.php';
require_once 'includes/theme_handler.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php?form=login");
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
$settings = getUserTheme($conn, $user_id);

// Set default values for settings that may not exist
$settings['language'] = $settings['language'] ?? 'en';
$settings['email_notifications'] = $settings['email_notifications'] ?? 0;
$settings['in_app_notifications'] = $settings['in_app_notifications'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css?v=<?php echo filemtime('css/styles.css'); ?>">
    <title>Settings - Expense Tracker</title>
    <style>
        .settings-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        
        .page-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 32px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #1f2937;
        }
        
        body.dark .page-title {
            color: #f9fafb;
        }
        
        .page-title::after {
            content: '';
            flex: 1;
            height: 4px;
            background: linear-gradient(90deg, #3b82f6, transparent);
            border-radius: 2px;
        }
        
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }
        
        @media (max-width: 768px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .settings-card {
            background: white;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        body.dark .settings-card {
            background: #1f2937;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        
        .settings-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            border-color: rgba(59, 130, 246, 0.2);
        }
        
        body.dark .settings-card:hover {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #f3f4f6;
        }
        
        body.dark .card-header {
            border-bottom-color: #374151;
        }
        
        .card-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            font-size: 24px;
        }
        
        .card-icon.blue {
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            color: #1e40af;
        }
        
        body.dark .card-icon.blue {
            background: linear-gradient(135deg, #1e3a8a, #1e40af);
            color: #93c5fd;
        }
        
        .card-icon.purple {
            background: linear-gradient(135deg, #e9d5ff, #d8b4fe);
            color: #7e22ce;
        }
        
        body.dark .card-icon.purple {
            background: linear-gradient(135deg, #581c87, #6b21a8);
            color: #d8b4fe;
        }
        
        .card-icon.green {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #047857;
        }
        
        body.dark .card-icon.green {
            background: linear-gradient(135deg, #064e3b, #065f46);
            color: #6ee7b7;
        }
        
        .card-icon.red {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #b91c1c;
        }
        
        body.dark .card-icon.red {
            background: linear-gradient(135deg, #7f1d1d, #991b1b);
            color: #fca5a5;
        }
        
        .settings-card h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            color: #1f2937;
        }
        
        body.dark .settings-card h2 {
            color: #f9fafb;
        }
        
        .settings-card form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .form-label {
            font-size: 14px;
            font-weight: 500;
            color: #4b5563;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        body.dark .form-label {
            color: #d1d5db;
        }
        
        .form-label i {
            color: #3b82f6;
            font-size: 16px;
        }
        
        .settings-card input[type="text"],
        .settings-card input[type="email"],
        .settings-card input[type="password"],
        .settings-card select {
            padding: 14px 16px;
            border-radius: 12px;
            border: 2px solid #e5e7eb;
            font-size: 15px;
            transition: all 0.3s ease;
            background: white;
            color: #1f2937;
            font-family: 'Roboto', sans-serif;
        }
        
        body.dark .settings-card input[type="text"],
        body.dark .settings-card input[type="email"],
        body.dark .settings-card input[type="password"],
        body.dark .settings-card select {
            background: #111827;
            border-color: #374151;
            color: #f9fafb;
        }
        
        .settings-card input:focus,
        .settings-card select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }
        
        .settings-card button[type="submit"] {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .settings-card button[type="submit"]:hover {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
        }
        
        .settings-card button[type="submit"]:active {
            transform: translateY(0);
        }
        
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 10px;
            background: #f9fafb;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        body.dark .checkbox-wrapper {
            background: #111827;
        }
        
        .checkbox-wrapper:hover {
            background: #f3f4f6;
        }
        
        body.dark .checkbox-wrapper:hover {
            background: #1f2937;
        }
        
        .settings-card input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: #3b82f6;
        }
        
        .checkbox-wrapper label {
            flex: 1;
            font-size: 15px;
            color: #374151;
            cursor: pointer;
            margin: 0;
        }
        
        body.dark .checkbox-wrapper label {
            color: #d1d5db;
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn-secondary {
            background: white;
            color: #3b82f6;
            border: 2px solid #3b82f6;
            padding: 14px 28px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        body.dark .btn-secondary {
            background: #111827;
            border-color: #3b82f6;
        }
        
        .btn-secondary:hover {
            background: #3b82f6;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.3);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(239, 68, 68, 0.4);
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            justify-content: center;
            align-items: center;
            z-index: 1000;
            backdrop-filter: blur(4px);
        }
        
        .modal-content {
            background: white;
            padding: 40px;
            border-radius: 20px;
            text-align: center;
            max-width: 480px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }
        
        body.dark .modal-content {
            background: #1f2937;
        }
        
        .modal-content h3 {
            margin-top: 0;
            margin-bottom: 16px;
            font-size: 24px;
            color: #1f2937;
        }
        
        body.dark .modal-content h3 {
            color: #f9fafb;
        }
        
        .modal-content p {
            color: #6b7280;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        
        body.dark .modal-content p {
            color: #9ca3af;
        }
        
        .modal-content button {
            margin: 0 8px;
        }
        
        .error, .success {
            padding: 14px 18px;
            border-radius: 12px;
            margin-top: 16px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #dc2626;
        }
        
        body.dark .error {
            background: #7f1d1d;
            color: #fecaca;
        }
        
        .success {
            background: #dcfce7;
            color: #166534;
            border-left: 4px solid #16a34a;
        }
        
        body.dark .success {
            background: #14532d;
            color: #bbf7d0;
        }
        
        .full-width {
            grid-column: 1 / -1;
        }
    </style>
</head>
<body class="<?php echo htmlspecialchars($settings['theme']); ?>">
    <div class="app">
    <!-- Navbar -->
    <nav class="navbar">
            <div class="logo">PennyPulse</div>
            <ul class="nav-links">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="add_expense.php"><i class="fas fa-plus"></i> Manage Expenses</a></li>
                <li><a href="view_expenses.php"><i class="fas fa-list"></i> View Expenses</a></li>
                <li><a href="set_budget.php"><i class="fas fa-wallet"></i> Budgets</a></li>
                <li><a href="reports.php"><i class="fas fa-chart-pie"></i> Reports</a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            </ul>
            <div class="user-profile">
                <span class="avatar" data-username="<?php echo htmlspecialchars($user['username']); ?>">
                    <?php echo isset($user['username'][0]) ? htmlspecialchars(strtoupper($user['username'][0])) : ''; ?>
                </span>
                <?php include 'includes/notifications_nav.php'; ?>
                <button id="theme-toggle" class="theme-toggle" data-theme-text="<?php echo $settings['theme'] === 'light' ? 'Dark Mode' : 'Light Mode'; ?>">
                    <i class="fas <?php echo $settings['theme'] === 'light' ? 'fa-moon' : 'fa-sun'; ?>"></i>
                </button>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </nav>
    
    <div class="settings-container">
        <h1 class="page-title">
            <i class="fas fa-cog"></i> Settings
        </h1>

        <div class="settings-grid">
            <!-- Profile Settings -->
            <div class="settings-card">
                <div class="card-header">
                    <div class="card-icon blue">
                        <i class="fas fa-user"></i>
                    </div>
                    <h2>Profile Settings</h2>
                </div>
                <form id="profileForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-user"></i> First Name</label>
                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" placeholder="Enter your first name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-user"></i> Last Name</label>
                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" placeholder="Enter your last name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-envelope"></i> Email Address</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" placeholder="Enter your email" required>
                    </div>
                    <button type="submit">
                        <i class="fas fa-save"></i> Update Profile
                    </button>
                </form>
                <div id="profileMessage"></div>
            </div>

            <!-- App Preferences -->
            <div class="settings-card">
                <div class="card-header">
                    <div class="card-icon purple">
                        <i class="fas fa-palette"></i>
                    </div>
                    <h2>App Preferences</h2>
                </div>
                <form id="settingsForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-moon"></i> Theme</label>
                        <select name="theme">
                            <option value="light" <?php if ($settings['theme'] == 'light') echo 'selected'; ?>>☀️ Light Mode</option>
                            <option value="dark" <?php if ($settings['theme'] == 'dark') echo 'selected'; ?>>🌙 Dark Mode</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-language"></i> Language</label>
                        <select name="language">
                            <option value="en" <?php if ($settings['language'] == 'en') echo 'selected'; ?>>🇺🇸 English</option>
                            <option value="es" <?php if ($settings['language'] == 'es') echo 'selected'; ?>>🇪🇸 Spanish</option>
                            <option value="fr" <?php if ($settings['language'] == 'fr') echo 'selected'; ?>>🇫🇷 French</option>
                        </select>
                    </div>
                    <div class="checkbox-group">
                        <input type="hidden" name="email_notifications" value="0">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="email_notifications" name="email_notifications" value="1" <?php if ($settings['email_notifications']) echo 'checked'; ?>>
                            <label for="email_notifications"><i class="fas fa-envelope"></i> Email Notifications</label>
                        </div>
                        <input type="hidden" name="in_app_notifications" value="0">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="in_app_notifications" name="in_app_notifications" value="1" <?php if ($settings['in_app_notifications']) echo 'checked'; ?>>
                            <label for="in_app_notifications"><i class="fas fa-bell"></i> In-App Notifications</label>
                        </div>
                    </div>
                    <button type="submit">
                        <i class="fas fa-check"></i> Save Preferences
                    </button>
                </form>
                <div id="settingsMessage"></div>
            </div>

            <!-- Security Settings -->
            <div class="settings-card">
                <div class="card-header">
                    <div class="card-icon green">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h2>Security Settings</h2>
                </div>
                <form id="passwordForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-key"></i> Current Password</label>
                        <input type="password" name="current_password" placeholder="Enter current password" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-lock"></i> New Password</label>
                        <input type="password" name="new_password" placeholder="Enter new password" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-lock"></i> Confirm Password</label>
                        <input type="password" name="confirm_password" placeholder="Confirm new password" required>
                    </div>
                    <button type="submit">
                        <i class="fas fa-shield-alt"></i> Change Password
                    </button>
                </form>
                <div id="passwordMessage"></div>
            </div>

            <!-- Data Management -->
            <div class="settings-card">
                <div class="card-header">
                    <div class="card-icon red">
                        <i class="fas fa-database"></i>
                    </div>
                    <h2>Data Management</h2>
                </div>
                <p style="color: #6b7280; margin-bottom: 20px; line-height: 1.6;">
                    Export your data or permanently delete your account. These actions are important for managing your data.
                </p>
                <div class="action-buttons">
                    <button class="btn-secondary" onclick="window.location.href='export_data.php'">
                        <i class="fas fa-download"></i> Export Data
                    </button>
                    <button class="btn-danger" onclick="showDeleteModal()">
                        <i class="fas fa-trash-alt"></i> Delete Account
                    </button>
                </div>
                <div id="dataMessage"></div>
            </div>
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
    </div>

    <script src="js/theme-toggle.js?v=<?php echo filemtime('js/theme-toggle.js'); ?>"></script>
    <script src="js/notifications.js?v=<?php echo filemtime('js/notifications.js'); ?>"></script>
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
            const selectedTheme = e.currentTarget.elements.theme.value;
            submitForm('settingsForm', 'update_settings.php', 'settingsMessage').then(() => {
                const message = document.getElementById('settingsMessage');
                if (message.className === 'success' && typeof window.applyTheme === 'function') {
                    window.applyTheme(selectedTheme);
                }
            });
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
            setTimeout(() => window.location.href = 'auth.php?form=login', 2000);
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
    </script>
</body>
</html>