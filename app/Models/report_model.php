<?php
// ============================================================================
// The 15 business reports (database queries only, nothing is changed).
// The report assembly (titles, columns, totals, summaries) is business logic
// and lives in report_service.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';

function query_daily_sales(string $a, string $b, $conn)
{
    $query = "SELECT s.receipt_no AS receipt, s.sale_date AS time, COALESCE(c.full_name, 'Walk-in') AS customer,
                u.full_name AS cashier, s.subtotal, s.discount, s.total_amount AS total, s.amount_paid AS paid, s.credit_amount AS credit
           FROM sales s
           LEFT JOIN customers c ON c.id = s.customer_id
           LEFT JOIN users u     ON u.id = s.sold_by
          WHERE s.status = 'completed' AND s.sale_date BETWEEN ? AND ? ORDER BY s.id";
    return executeQuery($query, [$a, $b], $conn);
}

function query_payments_by_method(string $a, string $b, $conn)
{
    $query = "SELECT p.method, SUM(p.amount) AS amount FROM payments p JOIN sales s ON s.id = p.sale_id
          WHERE s.status = 'completed' AND s.sale_date BETWEEN ? AND ? GROUP BY p.method";
    return executeQuery($query, [$a, $b], $conn);
}

function query_count_cancelled_sales(string $a, string $b, $conn)
{
    $query = "SELECT COUNT(*) AS total FROM sales WHERE status = 'cancelled' AND sale_date BETWEEN ? AND ?";
    return executeQuery($query, [$a, $b], $conn);
}

// $groupBy = the SQL expression of the period column (week start or month).
function query_grouped_sales(string $groupBy, string $a, string $b, $conn)
{
    $query = "SELECT $groupBy AS period, COUNT(*) AS sales, SUM(subtotal) AS subtotal, SUM(discount) AS discount,
                SUM(total_amount) AS revenue, SUM(credit_amount) AS credit
           FROM sales WHERE status = 'completed' AND sale_date BETWEEN ? AND ?
          GROUP BY period ORDER BY period";
    return executeQuery($query, [$a, $b], $conn);
}

function query_product_sales(string $a, string $b, $conn)
{
    $query = "SELECT p.product_code AS code, p.name AS product, SUM(i.base_quantity) AS qty, SUM(i.subtotal) AS revenue,
                SUM(i.cost_total) AS cost, SUM(i.subtotal) - SUM(i.cost_total) AS profit
           FROM sale_items i JOIN sales s ON s.id = i.sale_id JOIN products p ON p.id = i.product_id
          WHERE s.status = 'completed' AND s.sale_date BETWEEN ? AND ?
          GROUP BY p.id ORDER BY revenue DESC";
    return executeQuery($query, [$a, $b], $conn);
}

function query_purchases(string $from, string $to, $conn)
{
    $query = "SELECT p.reference_no AS ref, p.purchase_date AS date, s.name AS supplier, p.status, p.total_cost AS total,
                p.amount_paid AS paid, p.balance
           FROM purchases p JOIN suppliers s ON s.id = p.supplier_id
          WHERE p.status <> 'cancelled' AND p.purchase_date BETWEEN ? AND ? ORDER BY p.purchase_date, p.id";
    return executeQuery($query, [$from, $to], $conn);
}

function query_stock_balance($conn)
{
    $query = "SELECT p.product_code AS code, p.name AS product, c.name AS category, p.base_unit AS unit, p.quantity_in_stock AS qty,
                p.avg_cost AS avg_cost, ROUND(p.quantity_in_stock * p.avg_cost, 2) AS value, p.selling_price AS price,
                ROUND(p.quantity_in_stock * p.selling_price, 2) AS retail, p.status
           FROM products p JOIN categories c ON c.id = p.category_id ORDER BY p.name";
    return executeQuery($query, null, $conn);
}

function query_low_stock($conn)
{
    $query = "SELECT p.product_code AS code, p.name AS product, c.name AS category, p.base_unit AS unit, p.quantity_in_stock AS qty,
                p.min_stock AS min_stock, GREATEST(p.min_stock - p.quantity_in_stock, 0) AS shortfall
           FROM products p JOIN categories c ON c.id = p.category_id
          WHERE p.status = 'active' AND p.quantity_in_stock <= p.min_stock
          ORDER BY (p.min_stock - p.quantity_in_stock) DESC, p.name";
    return executeQuery($query, null, $conn);
}

function query_expired($conn)
{
    $query = "SELECT p.product_code AS code, p.name AS product, p.expiry_date AS expiry, DATEDIFF(p.expiry_date, CURDATE()) AS days,
                p.quantity_in_stock AS qty, p.base_unit AS unit, ROUND(p.quantity_in_stock * p.avg_cost, 2) AS value
           FROM products p
          WHERE p.expiry_date IS NOT NULL AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND p.quantity_in_stock > 0
          ORDER BY p.expiry_date";
    return executeQuery($query, null, $conn);
}

function query_damaged(string $a, string $b, $conn)
{
    $query = "SELECT a.created_at AS date, p.product_code AS code, p.name AS product, a.adjustment_type AS type, a.base_quantity AS qty,
                ROUND(a.base_quantity * COALESCE(m.unit_cost, 0), 2) AS value, a.reason, u.full_name AS user
           FROM stock_adjustments a
           JOIN products p ON p.id = a.product_id
           LEFT JOIN users u ON u.id = a.adjusted_by
           LEFT JOIN stock_movements m ON m.reference_type = 'adjustment' AND m.reference_id = a.id
          WHERE a.adjustment_type IN ('damaged', 'expired', 'missing') AND a.created_at BETWEEN ? AND ?
          ORDER BY a.id";
    return executeQuery($query, [$a, $b], $conn);
}

function query_supplier_balances($conn)
{
    $query = "SELECT s.supplier_code AS code, s.name AS supplier, s.phone, COUNT(p.id) AS purchases, SUM(p.total_cost) AS total,
                SUM(p.amount_paid) AS paid, SUM(p.balance) AS balance
           FROM suppliers s JOIN purchases p ON p.supplier_id = s.id AND p.status <> 'cancelled'
          GROUP BY s.id HAVING SUM(p.balance) > 0 ORDER BY balance DESC";
    return executeQuery($query, null, $conn);
}

function query_customer_debts($conn)
{
    $query = "SELECT c.customer_code AS code, c.full_name AS customer, c.phone, c.credit_limit AS credit_limit, c.current_debt AS debt,
                (SELECT MIN(d.created_at) FROM customer_debts d WHERE d.customer_id = c.id AND d.status IN ('open', 'partial')) AS oldest,
                c.status
           FROM customers c WHERE c.current_debt > 0 ORDER BY c.current_debt DESC";
    return executeQuery($query, null, $conn);
}

function query_expenses(string $from, string $to, $conn)
{
    $query = "SELECT e.expense_code AS code, e.expense_date AS date, c.name AS category, e.description, e.payment_method AS method,
                u.full_name AS user, e.amount
           FROM expenses e JOIN expense_categories c ON c.id = e.expense_category_id LEFT JOIN users u ON u.id = e.recorded_by
          WHERE e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date, e.id";
    return executeQuery($query, [$from, $to], $conn);
}

function query_expenses_by_category(string $from, string $to, $conn)
{
    $query = "SELECT c.name, SUM(e.amount) AS amt FROM expenses e JOIN expense_categories c ON c.id = e.expense_category_id
          WHERE e.expense_date BETWEEN ? AND ? GROUP BY c.id ORDER BY c.id";
    return executeQuery($query, [$from, $to], $conn);
}

// Revenue (after discounts) and cost of goods sold, one row per day.
function query_revenue_by_day(string $a, string $b, $conn)
{
    $query = "SELECT DATE(sale_date) AS d, COUNT(*) AS sales, SUM(total_amount) AS revenue
           FROM sales WHERE status = 'completed' AND sale_date BETWEEN ? AND ? GROUP BY d";
    return executeQuery($query, [$a, $b], $conn);
}

function query_cost_by_day(string $a, string $b, $conn)
{
    $query = "SELECT DATE(s.sale_date) AS d, SUM(i.cost_total) AS cogs
           FROM sale_items i JOIN sales s ON s.id = i.sale_id
          WHERE s.status = 'completed' AND s.sale_date BETWEEN ? AND ? GROUP BY d";
    return executeQuery($query, [$a, $b], $conn);
}

function query_stock_write_off(string $a, string $b, $conn): float
{
    $query = "SELECT COALESCE(SUM(a.base_quantity * COALESCE(m.unit_cost, 0)), 0) AS total
           FROM stock_adjustments a LEFT JOIN stock_movements m ON m.reference_type = 'adjustment' AND m.reference_id = a.id
          WHERE a.adjustment_type IN ('damaged', 'expired', 'missing') AND a.created_at BETWEEN ? AND ?";
    $result = executeQuery($query, [$a, $b], $conn);
    return (float)($result['data'][0]['total'] ?? 0);
}

function query_cash_reconciliation(string $a, string $b, $conn)
{
    $query = "SELECT s.id, s.opened_at AS opened, s.status, s.opening_balance AS opening,
                COALESCE(SUM(CASE WHEN t.txn_type = 'sale' THEN t.amount END), 0)             AS sales,
                COALESCE(SUM(CASE WHEN t.txn_type = 'debt_payment' THEN t.amount END), 0)     AS debts,
                COALESCE(SUM(CASE WHEN t.txn_type = 'deposit' THEN t.amount END), 0)          AS deposits,
                COALESCE(SUM(CASE WHEN t.txn_type = 'supplier_payment' THEN t.amount END), 0) AS suppliers,
                COALESCE(SUM(CASE WHEN t.txn_type = 'expense' THEN t.amount END), 0)          AS expenses,
                COALESCE(SUM(CASE WHEN t.txn_type = 'owner_withdrawal' THEN t.amount END), 0) AS withdrawals,
                COALESCE(SUM(CASE WHEN t.txn_type = 'refund' THEN t.amount END), 0)           AS refunds,
                s.counted_closing AS counted
           FROM cash_sessions s LEFT JOIN cash_transactions t ON t.cash_session_id = s.id
          WHERE s.opened_at BETWEEN ? AND ?
          GROUP BY s.id ORDER BY s.id";
    return executeQuery($query, [$a, $b], $conn);
}
