<?php
/**
 * Health Check Endpoint for AWS Elastic Beanstalk & Container Orchestration
 * 
 * This endpoint is used by:
 * - Docker HEALTHCHECK
 * - AWS Load Balancer Target Groups
 * - Kubernetes readiness/liveness probes
 * - Monitoring systems (Datadog, CloudWatch, etc.)
 */

// Set appropriate headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Define health status
$status = 'healthy';
$checks = [];
$http_code = 200;

try {
    // 1. Check if application directory is writable
    if (!is_writable(__DIR__ . '/uploads')) {
        $checks['uploads_writable'] = false;
        $status = 'degraded';
    } else {
        $checks['uploads_writable'] = true;
    }

    // 2. Check database connection (if config exists)
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
        
        try {
            // Attempt a simple query to verify the application's mysqli connection.
            if (isset($conn) && $conn instanceof mysqli) {
                $result = $conn->query('SELECT 1');
                $checks['database'] = true;
            } else {
                $checks['database'] = false;
                $status = 'degraded';
            }
        } catch (Exception $e) {
            $checks['database'] = false;
            $checks['database_error'] = $e->getMessage();
            $status = 'unhealthy';
            $http_code = 503;
        }
    }

    // 3. Check PHP version
    $checks['php_version'] = phpversion();
    $checks['php_memory_limit'] = ini_get('memory_limit');

    // 4. Check system resources
    $checks['disk_usage_percent'] = round((disk_total_space("/") - disk_free_space("/")) / disk_total_space("/") * 100, 2);

    if ($checks['disk_usage_percent'] > 90) {
        $status = 'degraded';
    }

    // 5. Check environment
    $checks['environment'] = getenv('APP_ENV') ?: 'unknown';
    $checks['timestamp'] = date('c');

} catch (Exception $e) {
    $status = 'unhealthy';
    $checks['error'] = $e->getMessage();
    $http_code = 503;
}

// Response payload
$response = [
    'status' => $status,
    'checks' => $checks,
    'timestamp' => time()
];

// Set HTTP status code
http_response_code($http_code);

// Return JSON response
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit($http_code === 200 ? 0 : 1);
?>
