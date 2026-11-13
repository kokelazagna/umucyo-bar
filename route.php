<?php
// ============================================================================
// Front controller: every request enters here (same pattern as ILMS route.php).
// The address is  route.php?url=module/action  (the .htaccess rewrites every
// pretty URL to this). The module picks the controller file, the action picks
// the function inside it.
// ============================================================================

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);               // project folder (used for storage/backups)
}
if (!defined('APP_PATH')) {
    define('APP_PATH', BASE_PATH . '/app');
}

// ---- application settings (was config/app.php) ----
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Umucyo Bar - BSSBMS');
}
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Africa/Kigali');
}
if (!defined('APP_CURRENCY')) {
    define('APP_CURRENCY', 'RWF');
}
if (!defined('APP_SESSION_LIFETIME')) {
    define('APP_SESSION_LIFETIME', 1800);       // seconds of inactivity before auto logout (30 min)
}
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', true);                  // set to false in production
}

date_default_timezone_set(APP_TIMEZONE);
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

ob_start();

require_once __DIR__ . '/app/config/connection.php';
require_once __DIR__ . '/app/middleware/auth.php';
require_once __DIR__ . '/app/helpers/php_helper.php';
require_once __DIR__ . '/app/helpers/app_helper.php';

boot_session();                             // starts the PHP session

// ---- which module/action is requested? ----
$url = isset($_GET['url']) ? trim($_GET['url']) : '';
$url = explode('/', $url);
$module = $url[0] ?? '';
$action = $url[1] ?? '';
$id     = $url[2] ?? '';
if ($module === '') {                       // the home page is the dashboard
    $module = 'dashboard';
}
if ($action === '') {
    $action = 'index';
}

$allowedModules = ['dashboard', 'auth', 'category', 'product', 'supplier', 'purchase', 'pos',
                   'sale', 'table', 'settings', 'cash', 'customer', 'debt', 'expense',
                   'stock', 'report', 'backup', 'user', 'sync'];
if (!in_array($module, $allowedModules)) {
    http_response_code(404);
    echo '<h1>404 - Page not found</h1><p><a href="' . e(url('dashboard')) . '">Back to dashboard</a></p>';
    exit;
}

// ---- public routes: pages open without a login ----
$publicRoutes = [
    'auth' => ['login'],
];
$isPublicRoute = isset($publicRoutes[$module]) && in_array($action, $publicRoutes[$module]);

if (!$isPublicRoute) {
    if (!is_logged_in()) {
        redirect('auth/login');
    }
    // Re-reads the account on every request, so a deactivated user is logged
    // out immediately and role changes apply at once.
    if (!sync_login($conn)) {
        flash('warning', 'Your account is no longer active. Please log in again.');
        redirect('auth/login');
    }
}

// ---- the form data of the request ----
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
// JSON bodies (the offline sync endpoints) are merged into $_POST so the same
// CSRF check and the same $data flow apply to them.
if (stripos($contentType, 'application/json') !== false) {
    $jsonBody = json_decode(file_get_contents('php://input'), true);
    if (is_array($jsonBody)) {
        $_POST = array_merge($_POST, $jsonBody);
    }
}

// ---- CSRF check on every POST (the hidden token in each form) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(403);   // 403 (not 419): mod_fcgid returns 500 for non-standard status codes
    echo '<h1>Session expired or invalid form token. Go back and try again.</h1>';
    exit;
}

// ---- load the controller of this module ----
$controller = __DIR__ . "/app/controllers/{$module}_controller.php";
if (!file_exists($controller)) {
    http_response_code(500);
    echo '<h1>500 - The controller "' . e($module) . '" does not exist</h1>';
    exit;
}
require_once $controller;

// ---- the form data of the request ----
if (strpos($contentType, 'multipart/form-data') !== false) {
    $data = $_POST;
} else {
    $data = $_POST;
}

// ---- run the controller function ----
try {
    switch ($module) {
        case 'dashboard':    handleDashboardRequest($action, $data, $conn);   break;
        case 'auth':         handleAuthRequest($action, $data, $conn);        break;
        case 'category':     handleCategoryRequest($action, $data, $conn);    break;
        case 'product':      handleProductRequest($action, $data, $conn);     break;
        case 'supplier':     handleSupplierRequest($action, $data, $conn);    break;
        case 'purchase':     handlePurchaseRequest($action, $data, $conn);    break;
        case 'pos':          handlePosRequest($action, $data, $conn);         break;
        case 'sale':         handleSaleRequest($action, $data, $conn);        break;
        case 'table':        handleTableRequest($action, $data, $conn);       break;
        case 'settings':     handleSettingsRequest($action, $data, $conn);    break;
        case 'cash':         handleCashRequest($action, $data, $conn);        break;
        case 'customer':     handleCustomerRequest($action, $data, $conn);    break;
        case 'debt':         handleDebtRequest($action, $data, $conn);        break;
        case 'expense':      handleExpenseRequest($action, $data, $conn);     break;
        case 'stock':        handleStockRequest($action, $data, $conn);       break;
        case 'report':       handleReportRequest($action, $data, $conn);      break;
        case 'backup':       handleBackupRequest($action, $data, $conn);      break;
        case 'user':         handleUserRequest($action, $data, $conn);        break;
        case 'sync':         handleSyncRequest($action, $data, $conn);        break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo '<h1>500 - Server error</h1>';
    if (APP_DEBUG) {
        echo '<pre>' . e($e->getMessage()) . '</pre>';
    }
}
