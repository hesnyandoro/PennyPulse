<?php
// Fetch user theme settings (requires $user_id to be set and config.php to be loaded)
function getUserTheme($conn, $user_id) {
    $query = "SELECT theme, language, email_notifications, in_app_notifications FROM user_settings WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Theme query prepare failed: " . $conn->error);
        return ['theme' => 'light', 'language' => 'en', 'email_notifications' => 0, 'in_app_notifications' => 0];
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc();
    
    if (!$settings) {
        // Create default settings
        $query = "INSERT INTO user_settings (user_id, theme, language, email_notifications, in_app_notifications) VALUES (?, 'light', 'en', 0, 0)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        return ['theme' => 'light', 'language' => 'en', 'email_notifications' => 0, 'in_app_notifications' => 0];
    }
    
    // Ensure all keys exist with default values
    $settings['theme'] = $settings['theme'] ?? 'light';
    $settings['language'] = $settings['language'] ?? 'en';
    $settings['email_notifications'] = $settings['email_notifications'] ?? 0;
    $settings['in_app_notifications'] = $settings['in_app_notifications'] ?? 0;
    
    return $settings;
}
?>