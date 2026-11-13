<?php
// Renders the v2.1 screens through the real front controller (route.php), one
// fresh process per page, and fails on PHP notices or missing content.
// Run from the project folder:   php tests/render.php
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('APP_DEBUG', false);

$mode = $argv[1] ?? 'run';

if ($mode === 'page') {
    require APP_PATH . '/config/connection.php';
    require APP_PATH . '/helpers/php_helper.php';
    require APP_PATH . '/helpers/app_helper.php';
    require APP_PATH . '/middleware/auth.php';
    require APP_PATH . '/services/audit_service.php';
    require APP_PATH . '/services/cash_service.php';
    require APP_PATH . '/services/stock_service.php';
    require APP_PATH . '/services/product_service.php';
    require APP_PATH . '/services/settings_service.php';
    require APP_PATH . '/services/purchase_service.php';
    require APP_PATH . '/services/sale_service.php';
    require APP_PATH . '/services/table_service.php';
    require APP_PATH . '/services/backup_service.php';
    require APP_PATH . '/services/dashboard_service.php';
    require APP_PATH . '/services/report_service.php';
    require APP_PATH . '/services/debt_service.php';
    require APP_PATH . '/services/expense_service.php';
    require APP_PATH . '/validators/product_validator.php';
    require APP_PATH . '/validators/supplier_validator.php';
    require APP_PATH . '/validators/purchase_validator.php';
    require APP_PATH . '/validators/customer_validator.php';
    require APP_PATH . '/validators/expense_validator.php';
    require APP_PATH . '/validators/user_validator.php';
    require APP_PATH . '/validators/settings_validator.php';
    require APP_PATH . '/validators/stock_validator.php';
    require APP_PATH . '/helpers/xlsx_helper.php';

    date_default_timezone_set('Africa/Kigali');

    $path = $argv[2] ?? '/dashboard';
    $uid  = (int)($argv[3] ?? 1);

    boot_session();
    $user = executeQuery('SELECT u.id, u.full_name, u.username, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid], $conn);
    $_SESSION['user'] = [
        'id' => (int)$user['data'][0]['id'], 'full_name' => $user['data'][0]['full_name'],
        'username' => $user['data'][0]['username'], 'role' => $user['data'][0]['role'],
    ];

    $query = parse_url($path, PHP_URL_QUERY);
    $_GET = [];
    if ($query) { parse_str($query, $_GET); }
    $_GET['url'] = trim(parse_url($path, PHP_URL_PATH), '/');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['SCRIPT_NAME'] = '/bssbms3mod/route.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['QUERY_STRING'] = $query ?: '';

    // run the front controller (route.php) with this request
    require BASE_PATH . '/route.php';
    exit(0);
}

$areaId = 0;
$tableId = 0;
$sessionId = 0;
$fail = 0;

function inspect(string $label, string $html, array $must): void
{
    global $fail;
    $bad = preg_match('/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught|Undefined variable|Undefined array key|Undefined index)/i', $html, $m);
    $missing = [];
    foreach ($must as $needle) {
        if (stripos($html, $needle) === false) { $missing[] = $needle; }
    }
    if (!$bad && !$missing) {
        echo "OK    $label (" . strlen($html) . " bytes)\n";
        return;
    }
    $fail++;
    echo "FAIL  $label";
    if ($bad) {
        $pos = strpos($html, $m[0]);
        echo ' - ' . substr(preg_replace('/\s+/', ' ', strip_tags(substr($html, $pos, 400))), 0, 240);
    }
    if ($missing) { echo ' - missing: ' . implode(', ', $missing); }
    echo "\n";
}

function page(string $path, int $uid = 1): string
{
    return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' page '
        . escapeshellarg($path) . ' ' . $uid . ' 2>&1');
}

require APP_PATH . '/config/connection.php';
require APP_PATH . '/helpers/php_helper.php';
require APP_PATH . '/helpers/app_helper.php';
require APP_PATH . '/models/product_model.php';
require APP_PATH . '/models/table_model.php';
require APP_PATH . '/services/table_service.php';

try {
    $areaResult  = create_area('Render Area ' . substr(bin2hex(random_bytes(2)), 0, 4), 98, $conn);
    $areaId      = (int)$areaResult['data']['id'];
    $tableResult = create_table_row('RT' . substr(bin2hex(random_bytes(2)), 0, 4), $areaId, 4, 'R', $conn);
    $tableId     = (int)$tableResult['data']['id'];
    $sessionId   = open_table_session($tableId, 0, 1, $conn);

    $product = get_active_products($conn)['data'][0] ?? null;
    if ($product) {
        $units = get_units_for_products([(int)$product['id']], $conn);
        $unit  = $units[(int)$product['id']][0] ?? null;
        if ($unit) { add_draft_item($sessionId, (int)$product['id'], (int)$unit['id'], 1, 1, $conn); }
    }

    inspect('/dashboard', page('/dashboard'), ['Dashboard']);
    inspect('/table', page('/table'), ['Floor', 'Open tab']);
    inspect('/table/session', page('/table/session?id=' . $sessionId), ['Add to the draft round', 'Confirm served', 'Settle the bill']);
    inspect('/settings (owner)', page('/settings'), ['Service areas', 'Unit conversions and prices', 'Recent conversion / price changes']);
    inspect('/table as cashier', page('/table', 3), ['Floor &amp; Tables']);
    inspect('/settings blocked for cashier', page('/settings', 3), ['403']);
    inspect('/settings allowed for manager', page('/settings', 2), ['Service areas']);

    $saleId = executeQuery('SELECT id FROM sales ORDER BY id DESC LIMIT 1', null, $conn);
    if (!empty($saleId['data'])) {
        inspect('/sale/receipt', page('/sale/receipt?id=' . (int)$saleId['data'][0]['id']), ['Receipt']);
    }
} catch (Throwable $e) {
    $fail++;
    echo 'FATAL ' . $e->getMessage() . "\n";
} finally {
    if ($sessionId) {
        $orderIds = executeQuery('SELECT id FROM orders WHERE table_session_id = ?', [$sessionId], $conn);
        if (!empty($orderIds['data'])) {
            $ids = array_column($orderIds['data'], 'id');
            executeQuery('DELETE FROM order_items WHERE order_id IN (' . implode(',', array_map('intval', $ids)) . ')', null, $conn);
            executeQuery('DELETE FROM orders WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')', null, $conn);
        }
        executeQuery('DELETE FROM table_sessions WHERE id = ?', [$sessionId], $conn);
    }
    if ($tableId) { executeQuery('DELETE FROM bar_tables WHERE id = ?', [$tableId], $conn); }
    if ($areaId)  { executeQuery('DELETE FROM bar_areas WHERE id = ?', [$areaId], $conn); }
}

echo $fail === 0 ? "All screens rendered cleanly.\n" : "$fail check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
