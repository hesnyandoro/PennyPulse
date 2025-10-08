# Copilot Instructions for Expense Tracker

## Project Overview
- This is a PHP-based expense tracker web application.
- Major features: user authentication, expense management, recurring expenses, reporting, and budget setting.
- Data is stored in a relational database (see `config.php` for DB connection details).

## Key Components
- **Authentication:** `login.php`, `register.php`, `logout.php`, `change_password.php`, `delete_account.php`
- **Expense Management:** `add_expense.php`, `edit_expenses.php`, `view_expenses.php`, `delete_recurring_handler.php`, `edit_recurring_handler.php`, `process_recurring.php`
- **Budget & Settings:** `set_budget.php`, `settings.php`, `update_settings.php`, `update_profile.php`
- **Reports & Export:** `reports.php`, `export_data.php`
- **UI:** Shared HTML in `footer.html`, styles in `css/styles.css`, scripts in `js/scripts.js`
- **APIs:** Under `css/api/api/api/` (e.g., `expenses.php`, `user.php`)
- **Uploads:** Receipts stored in `uploads/receipts/`

## Patterns & Conventions
- PHP files are organized by feature, not strict MVC.
- API endpoints are nested under `css/api/api/api/` (nonstandard; check usage before refactoring).
- Use `config.php` for DB and global config.
- HTML is often embedded in PHP files; minimal use of templating.
- Receipts and uploads are stored in `uploads/receipts/`.

## Developer Workflows
- No build step; edit PHP/HTML/CSS/JS directly.
- Test locally by running a PHP server (e.g., `php -S localhost:8000`).
- Database setup: see `setup.php` and `test_db.php` for initialization and connection testing.
- Debugging: use `test_error.php` for error handling checks.

## Integration Points
- Database: configured in `config.php`.
- File uploads: handled in relevant PHP files, stored in `uploads/receipts/`.
- API: internal endpoints for AJAX/JS calls in `css/api/api/api/`.

## Examples
- To add a new expense type, update `categories.php` and relevant UI forms.
- To add a new API endpoint, follow the structure in `css/api/api/api/` and update JS as needed.

## Cautions
- The API directory structure is deeply nested and nonstandard; verify all usages before moving files.
- Minimal error handling in some scripts; check `test_error.php` for patterns.

---
For questions or unclear patterns, review the main PHP files and `config.php` for project-wide settings.
