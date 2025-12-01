<?php
require_once 'config.php';

$active_tab = $_GET['form'] ?? 'register';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $active_tab = $_POST['action'] ?? $active_tab;
    
    if (isset($_POST['action']) && $_POST['action'] === 'register') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $phone = trim($_POST['phone'] ?? '');

        $errors = [];
        if (empty($first_name) || empty($last_name) || empty($email) || empty($username) || empty($password)) {
            $errors[] = 'Please fill all required fields.';
        }
        if ($password !== $confirm_password) {
            $errors[] = 'Passwords do not match.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format.';
        }
        if (!empty($errors)) {
            $error = implode('<br>', $errors);
        } else {
            $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
            $stmt->bind_param('ss', $username, $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'Username or email already exists.';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('INSERT INTO users (first_name, last_name, email, username, password, phone) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('ssssss', $first_name, $last_name, $email, $username, $hashed_password, $phone);
                if ($stmt->execute()) {
                    header('Location: auth.php?form=login&registered=1');
                    exit;
                } else {
                    $error = 'Registration failed. Please try again.';
                }
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Please fill all fields.';
        } else {
            $stmt = $conn->prepare('SELECT id, password FROM users WHERE username = ?');
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                if (password_verify($password, $row['password'])) {
                    $_SESSION['user_id'] = $row['id'];
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = 'Invalid credentials.';
                }
            } else {
                $error = 'Invalid credentials.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ucfirst($active_tab); ?> - Expense Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/styles.css?v=<?php echo filemtime('css/styles.css'); ?>">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #20c997 0%, #007bff 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .auth-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 40px;
            max-width: 400px;
            width: 100%;
        }
        .tabs {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 20px;
        }
        .tab {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            background: transparent;
            cursor: pointer;
            color: #6c757d;
            border-radius: 20px;
            transition: all 0.3s;
        }
        .tab:hover {
            background: #e9ecef;
        }
        .tab.active {
            color: #007bff;
            background: #e3f2fd;
            font-weight: bold;
        }
        .bullet {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #6c757d;
            transition: background 0.3s;
        }
        .tab.active .bullet {
            background: #007bff;
        }
        .form-title {
            text-align: center;
            color: #333;
            margin-bottom: 10px;
            font-size: 24px;
        }
        .subtitle {
            text-align: center;
            color: #6c757d;
            margin-bottom: 20px;
        }
        .error {
            color: #dc3545;
            text-align: center;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .input-group {
            margin-bottom: 15px;
        }
        .input-row {
            display: flex;
            gap: 10px;
        }
        .input-row input {
            flex: 1;
        }
        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 16px;
        }
        input:focus {
            outline: none;
            border-color: #007bff;
        }
        .submit-btn {
            width: 100%;
            padding: 12px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 10px;
            transition: background 0.3s;
        }
        .submit-btn:hover {
            background: #0056b3;
        }
        .toggle-link {
            text-align: center;
            margin-top: 20px;
            color: #6c757d;
        }
        .toggle-link a {
            color: #007bff;
            text-decoration: none;
        }
        .toggle-link a:hover {
            text-decoration: underline;
        }
        .form-hidden {
            display: none;
        }
        .navbar {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            background: #007bff;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
        }
        .logo {
            font-size: 20px;
            font-weight: bold;
        }
        .home-link {
            color: white;
            text-decoration: none;
        }
    </style>
</head>
<body class="light">
    <nav class="navbar">
        <div class="logo">Expense Tracker</div>
        <a href="index.php" class="home-link">Home</a>
    </nav>

    <div class="auth-container">
        <div class="tabs">
            <button class="tab <?php echo $active_tab === 'register' ? 'active' : ''; ?>" onclick="switchTab('register')">
                <span class="bullet"></span>
                Register
            </button>
            <button class="tab <?php echo $active_tab === 'login' ? 'active' : ''; ?>" onclick="switchTab('login')">
                <span class="bullet"></span>
                Login
            </button>
        </div>

    <div id="register-form" class="<?php echo $active_tab !== 'register' ? 'form-hidden' : ''; ?>">
    <h2 class="form-title">Register</h2>
    <p class="subtitle">Signup now and get full access to our app.</p>
    <?php if ($error && $active_tab === 'register') { echo "<div class='error'>$error</div>"; } ?>
    <form method="POST">
        <input type="hidden" name="action" value="register">
        <div class="input-row">
            <input type="text" name="first_name" placeholder="Firstname" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
            <input type="text" name="last_name" placeholder="Lastname" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
        </div>
        <div class="input-group">
            <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
        </div>
        <div class="input-group">
            <input type="text" name="username" placeholder="Username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
        </div>
        <div class="input-group">
            <input type="password" name="password" placeholder="Password" required>
        </div>
        <div class="input-group">
            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
        </div>
        <div class="input-group">
            <input type="text" name="phone" placeholder="Phone Number (Optional)" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
        </div>
        <button type="submit" class="submit-btn">Submit</button>
    </form>
    <p class="toggle-link">Already have an account? <a href="#" onclick="switchTab('login'); return false;">Sign in</a></p>
</div>

<div id="login-form" class="<?php echo $active_tab !== 'login' ? 'form-hidden' : ''; ?>">
    <h2 class="form-title">Login</h2>
    <p class="subtitle">Sign in to your account</p>
    <?php if ($error && $active_tab === 'login') { echo "<div class='error'>$error</div>"; } ?>
    <form method="POST">
        <input type="hidden" name="action" value="login">
        <div class="input-group">
            <input type="text" name="username" placeholder="Username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
        </div>
        <div class="input-group">
            <input type="password" name="password" placeholder="Password" required>
        </div>
        <button type="submit" class="submit-btn">Login</button>
    </form>
    <p class="toggle-link">Don't have an account? <a href="#" onclick="switchTab('register'); return false;">Register</a></p>
</div>    
    </div>

    <script>
    function switchTab(tabName) {
        const registerForm = document.getElementById('register-form');
        const loginForm = document.getElementById('login-form');
        
        const registerTab = document.querySelector('button.tab[onclick*="register"]');
        const loginTab = document.querySelector('button.tab[onclick*="login"]');

        if (tabName === 'register') {
            registerForm.classList.remove('form-hidden');
            loginForm.classList.add('form-hidden');
            
            registerTab.classList.add('active');
            loginTab.classList.remove('active');
        } else {
            loginForm.classList.remove('form-hidden');
            registerForm.classList.add('form-hidden');

            loginTab.classList.add('active');
            registerTab.classList.remove('active');
        }
    }

    // Also modify the links in your HTML to prevent the page from jumping
    // onclick="switchTab('login'); return false;"
    // onclick="switchTab('register'); return false;"
</script>
</body>
</html>
