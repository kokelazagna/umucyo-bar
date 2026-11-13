<?php
// ---------------------------------------------------------------------------
// Acceptance test for the v2.1 floor operations.
// Run from the project folder:   php tests/acceptance.php
//
// It creates its own area, tables, product and cash session, drives the real
// service functions, then removes everything it created.
// ---------------------------------------------------------------------------
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('APP_DEBUG', false);

require APP_PATH . '/config/connection.php';
require APP_PATH . '/helpers/php_helper.php';
require APP_PATH . '/helpers/app_helper.php';
require APP_PATH . '/middleware/auth.php';
require APP_PATH . '/services/audit_service.php';
require APP_PATH . '/services/cash_service.php';
require APP_PATH . '/services/stock_service.php';
require APP_PATH . '/services/product_service.php';
require APP_PATH . '/services/settings_service.php';
require APP_PATH . '/services/table_service.php';
require APP_PATH . '/services/sale_service.php';

date_default_timezone_set('Africa/Kigali');

// small helpers so the test reads like before (all through executeQuery)
function db_value(string $sql, array $p = []) {
    global $conn;
    $r = executeQuery($sql, $p, $conn);
    return !empty($r['data']) ? array_values($r['data'][0])[0] : null;
}
function db_all(string $sql, array $p = []) {
    global $conn;
    return executeQuery($sql, $p, $conn)['data'] ?? [];
}
function db_run(string $sql, array $p = []) {
    global $conn;
    executeQuery($sql, $p, $conn);
}
function db_column(string $sql, array $p = []) {
    global $conn;
    $rows = executeQuery($sql, $p, $conn)['data'] ?? [];
    $out = [];
    foreach ($rows as $row) { $out[] = array_values($row)[0]; }
    return $out;
}

$pass = 0;
$fail = 0;
$notes = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $notes;
    if ($ok) {
        $pass++;
        echo "PASS  $name\n";
    } else {
        $fail++;
        $notes[] = $name . ($detail !== '' ? ' - ' . $detail : '');
        echo "FAIL  $name" . ($detail !== '' ? " - $detail" : '') . "\n";
    }
}

function expect_runtime(callable $fn): ?string
{
    try {
        $fn();
        return null;
    } catch (RuntimeException $e) {
        return $e->getMessage();
    } catch (Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }
}

$areaId = 0;
$tableIds = [];
$productId = 0;
$sessionIds = [];
$saleIds = [];
$createdCash = false;
$cashSessionId = 0;
$userId = 1;

$productId = 0;
$baseUnitId = 0;
$crateUnitId = 0;

try {
    $areaResult = create_area('Test Floor ' . substr(bin2hex(random_bytes(3)), 0, 6), 99, $conn);
    $areaId = (int)$areaResult['data']['id'];

    foreach (['TT1', 'TT2', 'TT3', 'TT4'] as $code) {
        $result = create_table_row($code . substr(bin2hex(random_bytes(2)), 0, 4), $areaId, 4, 'T', $conn);
        $tableIds[$code] = (int)$result['data']['id'];
    }

    $productId = create_product([
        'category_id'    => 1,
        'name'           => 'Test ONLY Primus ' . substr(bin2hex(random_bytes(2)), 0, 4),
        'brand'          => 'Test',
        'barcode'        => null,
        'base_unit'      => 'bottle',
        'purchase_price' => '700.00',
        'selling_price'  => '1000.00',
        'min_stock'      => 0,
        'expiry_date'    => null,
        'status'         => 'active',
    ], [
        ['name' => 'crate', 'factor' => 20, 'price' => '19000.00'],
    ], $conn);

    $units = db_all('SELECT * FROM product_units WHERE product_id = ? ORDER BY is_base DESC', [$productId]);
    foreach ($units as $u) {
        if ((int)$u['is_base'] === 1) { $baseUnitId = (int)$u['id']; }
        if ($u['unit_name'] === 'crate') { $crateUnitId = (int)$u['id']; }
    }
    check('Fixture: bottle and crate units exist', $baseUnitId > 0 && $crateUnitId > 0);

    apply_stock_adjustment([
        'product_id' => $productId,
        'unit_id'    => $baseUnitId,
        'quantity'   => 100,
        'type'       => 'returned',
        'direction'  => 'increase',
        'reason'     => 'Acceptance test fixture stock',
    ], $userId, $conn);

    $stock = function () use ($productId, $conn): int {
        $r = executeQuery('SELECT quantity_in_stock FROM products WHERE id = ?', [$productId], $conn);
        return (int)($r['data'][0]['quantity_in_stock'] ?? 0);
    };
    $moves = function () use ($productId, $conn): int {
        $r = executeQuery('SELECT COUNT(*) AS c FROM stock_movements WHERE product_id = ?', [$productId], $conn);
        return (int)($r['data'][0]['c'] ?? 0);
    };

    check('Fixture: 100 bottles in stock', $stock() === 100, 'stock=' . $stock());

    $cash = get_open_cash_session($conn);
    if (!$cash) {
        $cashSessionId = open_cash_session(0, $userId, $conn);
        $createdCash = true;
    } else {
        $cashSessionId = (int)$cash['id'];
    }

    // ---- Open, draft, serve ------------------------------------------------
    $sessionA = open_table_session($tableIds['TT1'], 0, $userId, $conn);
    $sessionIds[] = $sessionA;
    $statusResult = executeQuery('SELECT status FROM bar_tables WHERE id = ?', [$tableIds['TT1']], $conn);
    check('Open tab without a customer marks the table occupied',
        $statusResult['data'][0]['status'] === 'occupied');

    $stockBefore = $stock();
    add_draft_item($sessionA, $productId, $crateUnitId, 1, $userId, $conn);
    $view = get_session_view($sessionA, $conn);
    check('Draft round does not change stock', $stock() === $stockBefore, 'stock=' . $stock());
    check('Draft shows one unserved line', count($view['draft_items']) === 1);
    check('Draft line stores the factor snapshot (20)', (int)$view['draft_items'][0]['conversion_factor_snapshot'] === 20);

    $movesBefore = $moves();
    $served = serve_draft_round($sessionA, $userId, $conn);
    $saleIds[] = (int)$served['sale_id'];
    check('Serving 1 crate deducts 20 bottles', $stock() === $stockBefore - 20, 'stock=' . $stock());
    check('Serving writes exactly one stock movement', $moves() === $movesBefore + 1, 'movements=' . $moves());

    $stockAfterServe = $stock();
    $movesAfterServe = $moves();
    $err = expect_runtime(function () use ($sessionA, $userId, $conn) { serve_draft_round($sessionA, $userId, $conn); });
    check('Repeated serving is refused', $err !== null, $err ?? 'no exception');
    check('Repeated serving deducts nothing', $stock() === $stockAfterServe && $moves() === $movesAfterServe);

    // Second round, served separately
    add_draft_item($sessionA, $productId, $baseUnitId, 3, $userId, $conn);
    serve_draft_round($sessionA, $userId, $conn);
    check('Two served rounds deduct 23 bottles in total', $stock() === $stockBefore - 23, 'stock=' . $stock());

    $view = get_session_view($sessionA, $conn);
    check('Open-tab bill is 22,000 RWF', abs((float)$view['sale']['total_amount'] - 22000) < 0.01, 'bill=' . ($view['sale']['total_amount'] ?? '?'));
    check('Sale has one line per served item line', count($view['sale']['items']) === 2);

    // ---- Conversion change keeps history ----------------------------------
    update_unit_conversion($crateUnitId, 24, 19000, $userId, 'Acceptance test conversion change', $conn);
    $snapshot = (int)db_value(
        'SELECT oi.conversion_factor_snapshot AS v FROM order_items oi
           JOIN orders o ON o.id = oi.order_id
          WHERE o.table_session_id = ? AND oi.unit_id = ? LIMIT 1',
        [$sessionA, $crateUnitId]
    );
    check('Changing a conversion keeps the served history at 20', $snapshot === 20, 'snapshot=' . $snapshot);
    check('Conversion change is recorded in price history',
        (int)db_value('SELECT COUNT(*) AS c FROM product_price_history WHERE product_id = ?', [$productId]) >= 1);

    // ---- Partial payment keeps the tab open -------------------------------
    $partial = settle_table_session($sessionA, [
        ['method' => 'cash', 'amount' => 10000, 'reference' => ''],
    ], 0, 0, false, $userId, true, $conn);
    check('Partial payment leaves an outstanding balance', !$partial['closed'] && $partial['outstanding'] > 0,
        'outstanding=' . $partial['outstanding']);
    check('Table stays occupied after a partial payment',
        db_value('SELECT status AS v FROM table_sessions WHERE id = ?', [$sessionA]) === 'open');

    $final = settle_table_session($sessionA, [
        ['method' => 'mobile_money', 'amount' => 12000, 'reference' => 'MOMO-TEST-1'],
    ], 0, 0, false, $userId, true, $conn);
    check('Final payment closes the tab', $final['closed'] === true);
    check('Table returns to available',
        db_value('SELECT status AS v FROM bar_tables WHERE id = ?', [$tableIds['TT1']]) === 'available');
    check('Sale is completed', db_value('SELECT status AS v FROM sales WHERE id = ?', [(int)$served['sale_id']]) === 'completed');
    check('Settlement did not deduct stock again', $stock() === $stockBefore - 23, 'stock=' . $stock());
    check('Two payments are recorded on the bill',
        (int)db_value('SELECT COUNT(*) AS c FROM payments WHERE sale_id = ?', [(int)$served['sale_id']]) === 2);
    check('Cash drawer holds only the cash payment',
        abs((float)db_value("SELECT COALESCE(SUM(amount),0) AS v FROM cash_transactions WHERE cash_session_id = ? AND txn_type = 'sale' AND reference_type = 'sale' AND reference_id = ?", [$cashSessionId, (int)$served['sale_id']]) - 10000) < 0.01);

    // ---- Void a served line restores stock --------------------------------
    $sessionB = open_table_session($tableIds['TT2'], 0, $userId, $conn);
    $sessionIds[] = $sessionB;
    add_draft_item($sessionB, $productId, $baseUnitId, 2, $userId, $conn);
    $servedB = serve_draft_round($sessionB, $userId, $conn);
    $saleIds[] = (int)$servedB['sale_id'];
    check('Second tab deducted 2 bottles', $stock() === $stockBefore - 25, 'stock=' . $stock());

    $lineId = (int)db_value("SELECT id AS v FROM sale_items WHERE sale_id = ? AND line_status = 'active' LIMIT 1", [(int)$servedB['sale_id']]);
    void_served_item($sessionB, $lineId, 'Acceptance test void', $userId, $conn);
    check('Voiding a served line restores stock', $stock() === $stockBefore - 23, 'stock=' . $stock());
    check('Voided line stays in history',
        db_value('SELECT line_status AS v FROM sale_items WHERE id = ?', [$lineId]) === 'void');

    // ---- Transfer and merge ------------------------------------------------
    $sessionC = open_table_session($tableIds['TT3'], 0, $userId, $conn);
    $sessionIds[] = $sessionC;
    $sessionD = open_table_session($tableIds['TT4'], 0, $userId, $conn);
    $sessionIds[] = $sessionD;
    add_draft_item($sessionD, $productId, $baseUnitId, 1, $userId, $conn);

    transfer_table_session($sessionC, $tableIds['TT1'], 'Acceptance transfer', $userId, $conn);
    check('Transfer moves the tab to the target table',
        (int)db_value('SELECT table_id AS v FROM table_sessions WHERE id = ?', [$sessionC]) === $tableIds['TT1']);
    check('Transfer frees the old table',
        db_value('SELECT status AS v FROM bar_tables WHERE id = ?', [$tableIds['TT3']]) === 'available');

    merge_table_sessions($sessionD, $sessionC, 'Acceptance merge', $userId, $conn);
    check('Merge closes the source tab',
        db_value('SELECT status AS v FROM table_sessions WHERE id = ?', [$sessionD]) === 'closed');
    check('Merge frees the source table',
        db_value('SELECT status AS v FROM bar_tables WHERE id = ?', [$tableIds['TT4']]) === 'available');
    check('Merge moves the draft round to the target tab',
        (int)db_value("SELECT COUNT(*) AS c FROM order_items oi JOIN orders o ON o.id = oi.order_id
                        WHERE o.table_session_id = ? AND oi.state = 'draft'", [$sessionC]) === 1);

} catch (Throwable $e) {
    $fail++;
    echo 'FATAL ' . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    try {
        if ($productId) {
            $saleIds = array_values(array_unique(array_filter(array_map('intval', $saleIds))));
            if ($saleIds) {
                $sin = implode(',', $saleIds);
                db_run("DELETE FROM cash_transactions WHERE reference_type = 'sale' AND reference_id IN ($sin)");
                db_run("DELETE FROM payments WHERE sale_id IN ($sin)");
                db_run("DELETE FROM sale_items WHERE sale_id IN ($sin)");
            }
            $orderIds = db_column('SELECT id FROM orders WHERE table_session_id IS NOT NULL AND table_session_id IN ('
                . 'SELECT id FROM table_sessions WHERE table_id IN (' . implode(',', array_map('intval', $tableIds)) . '))');
            if ($orderIds) {
                $oin = implode(',', array_map('intval', $orderIds));
                db_run("DELETE FROM order_items WHERE order_id IN ($oin)");
                db_run("DELETE FROM orders WHERE id IN ($oin)");
            }
            db_run('DELETE FROM payments WHERE sale_id IN (SELECT id FROM sales WHERE table_session_id IN ('
                . 'SELECT id FROM table_sessions WHERE table_id IN (' . implode(',', array_map('intval', $tableIds)) . ')))');
            db_run('DELETE FROM sale_items WHERE sale_id IN (SELECT id FROM sales WHERE table_session_id IN ('
                . 'SELECT id FROM table_sessions WHERE table_id IN (' . implode(',', array_map('intval', $tableIds)) . ')))');
            db_run('DELETE FROM sales WHERE table_session_id IN (SELECT id FROM table_sessions WHERE table_id IN ('
                . implode(',', array_map('intval', $tableIds)) . '))');
            db_run('DELETE FROM table_sessions WHERE table_id IN (' . implode(',', array_map('intval', $tableIds)) . ')');
            db_run('DELETE FROM bar_tables WHERE id IN (' . implode(',', array_map('intval', $tableIds)) . ')');
            db_run('DELETE FROM stock_movements WHERE product_id = ?', [$productId]);
            db_run('DELETE FROM stock_adjustments WHERE product_id = ?', [$productId]);
            db_run('DELETE FROM product_price_history WHERE product_id = ?', [$productId]);
            db_run('DELETE FROM product_units WHERE product_id = ?', [$productId]);
            db_run('DELETE FROM products WHERE id = ?', [$productId]);
        }
        if ($areaId) {
            db_run('DELETE FROM bar_areas WHERE id = ?', [$areaId]);
        }
        if ($createdCash && $cashSessionId) {
            db_run('DELETE FROM cash_transactions WHERE cash_session_id = ?', [$cashSessionId]);
            db_run('DELETE FROM cash_sessions WHERE id = ?', [$cashSessionId]);
        }
    } catch (Throwable $e) {
        echo 'CLEANUP WARNING: ' . $e->getMessage() . "\n";
    }
}

echo "\n----------------------------------------------\n";
echo "Passed: $pass   Failed: $fail\n";
if ($fail > 0) {
    foreach ($notes as $n) { echo ' - ' . $n . "\n"; }
}
exit($fail === 0 ? 0 : 1);
