<?php
// Fetch user theme settings (requires $user_id to be set and config.php to be loaded)
function getUserTheme($conn, $user_id) {
    $defaults = ['theme' => 'light', 'language' => 'en', 'email_notifications' => 0, 'in_app_notifications' => 0];

    try {
        $query = "SELECT theme, language, email_notifications, in_app_notifications FROM user_settings WHERE user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $settings = $stmt->get_result()->fetch_assoc();

        if (!$settings) {
            // Create default settings for a first-time user
            $insertStmt = $conn->prepare(
                "INSERT INTO user_settings (user_id, theme, language, email_notifications, in_app_notifications) VALUES (?, 'light', 'en', 0, 0)"
            );
            $insertStmt->bind_param('i', $user_id);
            $insertStmt->execute();
            return $defaults;
        }

        // Ensure all keys exist with default values (covers NULL columns)
        return [
            'theme' => $settings['theme'] ?? $defaults['theme'],
            'language' => $settings['language'] ?? $defaults['language'],
            'email_notifications' => $settings['email_notifications'] ?? $defaults['email_notifications'],
            'in_app_notifications' => $settings['in_app_notifications'] ?? $defaults['in_app_notifications'],
        ];

    } catch (mysqli_sql_exception $e) {
        error_log("getUserTheme failed for user_id={$user_id}: " . $e->getMessage());
        return $defaults;
    }
}
?>