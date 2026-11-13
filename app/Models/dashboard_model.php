<?php
// ============================================================================
// Numbers shown on the dashboard (database queries only).
// The chart assembly lives in dashboard_service (business logic).
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';

function get_dashboard_counts($conn): array
{
    $counts = [];
    foreach (['products', 'suppliers', 'customers', 'users'] as $table) {
        $result = executeQuery("SELECT COUNT(*) AS total FROM {$table}", null, $conn);
        $counts[$table] = (int)($result['data'][0]['total'] ?? 0);
    }
    return $counts;
}

// ---------- the figures of the real dashboard ----------

// Today's money figures. "Today" is the database date (CURDATE()), the same clock
// that stamps every sale.
function get_today_figures($conn): array
{
    $value = function (string $q, array $p = []) use ($conn) {
        return executeQuery($q, $p, $conn);
    };

    $sales = (float)($value("SELECT COALESCE(SUM(total_amount), 0) AS total FROM sales WHERE status = 'completed' AND DATE(sale_date) = CURDATE()")['data'][0]['total'] ?? 0);
    $cogs  = (float)($value("SELECT COALESCE(SUM(i.cost_total), 0) AS total FROM sale_items i JOIN sales s ON s.id = i.sale_id
          WHERE s.status = 'completed' AND DATE(s.sale_date) = CURDATE()")['data'][0]['total'] ?? 0);
    $expenses = (float)($value('SELECT COALESCE(SUM(amount), 0) AS total FROM expenses WHERE expense_date = CURDATE()')['data'][0]['total'] ?? 0);
    $cash     = (float)($value(
        "SELECT COALESCE((SELECT SUM(p.amount) FROM payments p JOIN sales s ON s.id = p.sale_id
                           WHERE s.status = 'completed' AND p.method = 'cash' AND DATE(p.paid_at) = CURDATE()), 0)\n            + COALESCE((SELECT SUM(amount) FROM debt_payments WHERE method = 'cash' AND DATE(paid_at) = CURDATE()), 0) AS total"
    )['data'][0]['total'] ?? 0);

    return [
        'sales'     => $sales,
        'sales_n'   => (int)($value("SELECT COUNT(*) AS total FROM sales WHERE status = 'completed' AND DATE(sale_date) = CURDATE()")['data'][0]['total'] ?? 0),
        'purchases' => (float)($value("SELECT COALESCE(SUM(total_cost), 0) AS total FROM purchases WHERE status <> 'cancelled' AND purchase_date = CURDATE()")['data'][0]['total'] ?? 0),
        'cash'      => $cash,
        'expenses'  => $expenses,
        'gross'     => $sales - $cogs,
        'net'       => $sales - $cogs - $expenses,
        'debts'     => (float)($value('SELECT COALESCE(SUM(current_debt), 0) AS total FROM customers')['data'][0]['total'] ?? 0),
    ];
}

// All products, all units in stock and what that stock cost.
function get_stock_totals($conn): array
{
    $products = (int)(executeQuery('SELECT COUNT(*) AS total FROM products', null, $conn)['data'][0]['total'] ?? 0);
    $units    = (float)(executeQuery('SELECT COALESCE(SUM(quantity_in_stock), 0) AS total FROM products', null, $conn)['data'][0]['total'] ?? 0);
    $value    = (float)(executeQuery('SELECT COALESCE(SUM(quantity_in_stock * avg_cost), 0) AS total FROM products', null, $conn)['data'][0]['total'] ?? 0);
    return ['products' => $products, 'units' => $units, 'value' => $value];
}

// Sales per day (raw rows), grouped by the database. The chart assembly
// (labels, percentages) is business logic and lives in dashboard_service.
function get_sales_by_day(string $from, string $to, $conn)
{
    $query = "SELECT DATE(sale_date) AS d, SUM(total_amount) AS t FROM sales
          WHERE status = 'completed' AND DATE(sale_date) >= ? AND DATE(sale_date) <= ? GROUP BY d";
    return executeQuery($query, [$from, $to], $conn);
}

// The database's today (the same clock that stamps every sale).
function get_database_today($conn): string
{
    $result = executeQuery('SELECT CURDATE() AS today', null, $conn);
    return (string)($result['data'][0]['today'] ?? date('Y-m-d'));
}

function get_recent_sales(int $limit, $conn)
{
    $query = "SELECT s.id, s.receipt_no, s.sale_date, s.total_amount, s.credit_amount, s.status, COALESCE(c.full_name, 'Walk-in') AS customer
           FROM sales s LEFT JOIN customers c ON c.id = s.customer_id ORDER BY s.id DESC LIMIT " . (int)$limit;
    return executeQuery($query, null, $conn);
}

// Active products at or below their minimum stock, the emptiest first.
function get_low_stock_products(int $limit, $conn)
{
    $query = "SELECT name, quantity_in_stock AS qty, min_stock, base_unit FROM products
          WHERE status = 'active' AND quantity_in_stock <= min_stock
          ORDER BY (min_stock - quantity_in_stock) DESC, name LIMIT " . (int)$limit;
    return executeQuery($query, null, $conn);
}

function count_low_stock_products($conn): int
{
    $result = executeQuery("SELECT COUNT(*) AS total FROM products WHERE status = 'active' AND quantity_in_stock <= min_stock", null, $conn);
    return (int)($result['data'][0]['total'] ?? 0);
}
