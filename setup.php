<?php
require_once 'config.php';

try {
    // ensures all tables are crated during setup to prevent errors
    // drop all table if they exist
    // Create users table
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL
    )");

    // Create categories table
    $conn->query("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        name VARCHAR(50) NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Create expenses table
    $conn->query("CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        category_id INT NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        description TEXT,
        date DATE NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
    )");

    $conn->query("CREATE INDEX IF NOT EXISTS idx_expenses_date ON expenses (date)");
    $conn->query("CREATE INDEX IF NOT EXISTS idx_expenses_amount ON expenses (amount)");
    $conn->query("CREATE INDEX IF NOT EXISTS idx_expenses_category_id ON expenses (category_id)");

    // Create budgets table
    $conn->query("CREATE TABLE IF NOT EXISTS budgets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        category_id INT NOT NULL,
        month DATE NOT NULL,
        budget_amount DECIMAL(10, 2) NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
        UNIQUE (user_id, category_id, month)
    )");

    // Create user_settings table
    $conn->query("CREATE TABLE IF NOT EXISTS user_settings (
        user_id INT PRIMARY KEY,
        theme VARCHAR(10) DEFAULT 'light',
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    echo "Database setup completed successfully!";
} catch (Exception $e) {
    echo "Error setting up database: " . $e->getMessage();
}
?>