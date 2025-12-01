<?php
// Fetch user theme settings (requires $user_id to be set and config.php to be loaded)
function getUserTheme($conn, $user_id) {
    $query = "SELECT theme FROM user_settings WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Theme query prepare failed: " . $conn->error);
        return ['theme' => 'light'];
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc();
    
    if (!$settings) {
        // Create default settings
        $query = "INSERT INTO user_settings (user_id, theme) VALUES (?, 'light')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        return ['theme' => 'light'];
    }
    
    return $settings;
}
?>