<?php
// The one MySQL connection used by every model. Credentials come from .env.

require_once __DIR__ . '/app.php';
require_once dirname(__DIR__) . '/Helpers/logger.php';
configure_error_handling();

$dbHost = app_env('DB_HOST', 'localhost');
$dbPort = (int) app_env('DB_PORT', '3306');
$dbName = app_env('DB_DATABASE', 'bar_management_db');
$dbUser = app_env('DB_USERNAME', 'root');
$dbPassword = app_env('DB_PASSWORD', '');

mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_init();
if ($conn) {
    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
}

if (!$conn || !@mysqli_real_connect($conn, $dbHost, $dbUser, $dbPassword, $dbName, $dbPort)) {
    app_log('critical', 'Database connection failed', [
        'host' => $dbHost,
        'port' => $dbPort,
        'database' => $dbName,
        'mysqli_error' => $conn ? mysqli_connect_error() : 'mysqli_init failed',
    ]);
    http_response_code(503);
    exit(APP_DEBUG ? 'Database connection unavailable.' : 'Service temporarily unavailable.');
}

mysqli_set_charset($conn, 'utf8mb4');
defined('DB_NAME') || define('DB_NAME', $dbName);
