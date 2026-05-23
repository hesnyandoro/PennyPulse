-- SQL Script to create all database tables for PennyPulse Expense Tracker

-- Create users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    email VARCHAR(100),
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20)
);

-- Create categories table
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    name VARCHAR(50) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_global_category (name, user_id)
);

-- Insert default categories (available to all users)
INSERT IGNORE INTO categories (user_id, name) VALUES
    (NULL, 'Food & Dining'),
    (NULL, 'Transportation'),
    (NULL, 'Housing'),
    (NULL, 'Utilities'),
    (NULL, 'Healthcare'),
    (NULL, 'Entertainment'),
    (NULL, 'Shopping'),
    (NULL, 'Education'),
    (NULL, 'Travel'),
    (NULL, 'Savings'),
    (NULL, 'Personal Care'),
    (NULL, 'Other');

-- Create expenses table
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description TEXT,
    date DATE NOT NULL,
    merchant VARCHAR(255) DEFAULT NULL,
    payment_method ENUM('cash','credit_card','debit_card','mobile_payment','bank_transfer','mpesa') DEFAULT NULL,
    is_recurring TINYINT(1) DEFAULT 0,
    receipt_path VARCHAR(255) DEFAULT NULL,
    recurring_end_date DATE DEFAULT NULL,
    type VARCHAR(50) DEFAULT NULL,
    recurring_expense_id INT DEFAULT NULL,
    is_override TINYINT(1) DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (recurring_expense_id) REFERENCES recurring_expenses(id) ON DELETE SET NULL

-- Create recurring_expenses table
CREATE TABLE IF NOT EXISTS recurring_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT,
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(255),
    frequency ENUM('daily','weekly','monthly','yearly') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE,
    last_processed DATE,
    next_due_date DATE,
    is_active TINYINT(1) DEFAULT 1,
    merchant VARCHAR(255) DEFAULT NULL,
    payment_method ENUM('cash','credit_card','debit_card','mobile_payment','bank_transfer','mpesa') DEFAULT NULL,
    receipt_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- Create recurring_expense_exceptions table
CREATE TABLE IF NOT EXISTS recurring_expense_exceptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    recurring_expense_id INT NOT NULL,
    exception_date DATE NOT NULL,
    reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (recurring_expense_id) REFERENCES recurring_expenses(id) ON DELETE CASCADE
);

-- Create budgets table
CREATE TABLE IF NOT EXISTS budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    month DATE NOT NULL,
    budget_amount DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    UNIQUE (user_id, category_id, month)
);

-- Create user_settings table
CREATE TABLE IF NOT EXISTS user_settings (
    user_id INT PRIMARY KEY,
    theme VARCHAR(20) DEFAULT 'light',
    language VARCHAR(10) DEFAULT 'en',
    email_notifications TINYINT(1) DEFAULT 0,
    in_app_notifications TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);