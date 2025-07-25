<?php
// process_recurring.php
 // The script fetches all active recurring rules for the logged-in user.
// It then loops through each rule and calculates all the dates an expense should have been created, from the start_date up to today.
// The Crucial Check: For each potential due date, it runs a SELECT query to check if an expense with that recurring_expense_id and date already exists in the expenses table.
// If, and only if, no such expense exists, it proceeds to INSERT the new expense record.
// This logic ensures that even if the script runs multiple times a day, it will never create a duplicate entry.

require_once 'config.php';

function process_recurring_expenses($user_id) {
    global $conn;

    // Select all active recurring expense rules for the user
    $query = "SELECT * FROM recurring_expenses WHERE user_id = ? AND (end_date IS NULL OR end_date >= CURDATE())";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        // Handle query preparation error
        error_log("Prepare failed: (" . $conn->errno . ") " . $conn->error);
        return;
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $recurring_expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($recurring_expenses as $rule) {
        $start_date = new DateTime($rule['start_date']);
        $end_date = $rule['end_date'] ? new DateTime($rule['end_date']) : new DateTime(); // Today if no end date
        $today = new DateTime();
        
        // Ensure the end date is not in the future beyond today for processing
        if ($end_date > $today) {
            $end_date = $today;
        }

        $current_date = clone $start_date;

        // Loop through all potential due dates from the start until today/end_date
        while ($current_date <= $end_date) {
            $date_to_check = $current_date->format('Y-m-d');

            // 1. CHECK: See if an expense for this rule and date already exists
            $check_query = "SELECT id FROM expenses WHERE user_id = ? AND recurring_expense_id = ? AND date = ?";
            $check_stmt = $conn->prepare($check_query);
             if (!$check_stmt) {
                error_log("Prepare failed: (" . $conn->errno . ") " . $conn->error);
                continue; // Move to the next iteration
            }
            $check_stmt->bind_param('iis', $user_id, $rule['id'], $date_to_check);
            $check_stmt->execute();
            $result = $check_stmt->get_result();

            // 2. INSERT: If no expense exists (fetch() returns null), create it
            if ($result->fetch_assoc() === null) {
                $insert_query = "INSERT INTO expenses (user_id, category_id, amount, payment_method, description, date, recurring_expense_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_query);
                if (!$insert_stmt) {
                    error_log("Prepare failed: (" . $conn->errno . ") " . $conn->error);
                    continue; // Move to the next iteration
                }
                $insert_stmt->bind_param(
                    'iidsssi',
                    $user_id,
                    $rule['category_id'],
                    $rule['amount'],
                    $rule['payment_method'],
                    $rule['description'],
                    $date_to_check,
                    $rule['id']
                );
                $insert_stmt->execute();
            }
            
            // Move to the next potential due date
            $interval_string = 'P' . $rule['period'] . strtoupper(substr($rule['frequency'], 0, 1)); // e.g., P1D, P1W, P1M
            $current_date->add(new DateInterval($interval_string));
        }
    }
}

// Ensure user is logged in before processing
if (isset($_SESSION['user_id'])) {
    process_recurring_expenses($_SESSION['user_id']);
}
?>
