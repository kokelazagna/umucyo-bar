<?php
// ============================================================================
// Sales history (database queries only).
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';

// $f: q (receipt number), status, from, to
function search_sales(array $f, int $page, int $perPage, $conn)
{
    $query = 'SELECT s.*, c.full_name AS customer_name, u.full_name AS seller_name
              FROM sales s
              LEFT JOIN customers c ON c.id = s.customer_id
              LEFT JOIN users u     ON u.id = s.sold_by
             WHERE 1 = 1';
    $params = [];
    if (($f['q'] ?? '') !== '') {
        $query .= ' AND s.receipt_no LIKE ?';
        $params[] = '%' . $f['q'] . '%';
    }
    if (in_array($f['status'] ?? '', ['completed', 'cancelled'], true)) {
        $query .= ' AND s.status = ?';
        $params[] = $f['status'];
    }
    if (looks_like_date($f['from'] ?? '')) {
        $query .= ' AND s.sale_date >= ?';
        $params[] = $f['from'] . ' 00:00:00';
    }
    if (looks_like_date($f['to'] ?? '')) {
        $query .= ' AND s.sale_date <= ?';
        $params[] = $f['to'] . ' 23:59:59';
    }
    $query .= ' ORDER BY s.id DESC';
    return paged_query($query, $params, $page, $perPage, $conn);
}

function find_sale(int $id, $conn)
{
    $query = 'SELECT s.*, c.full_name AS customer_name, c.phone AS customer_phone,
                u.full_name AS seller_name, x.full_name AS cancelled_name
           FROM sales s
           LEFT JOIN customers c ON c.id = s.customer_id
           LEFT JOIN users u     ON u.id = s.sold_by
           LEFT JOIN users x     ON x.id = s.cancelled_by
          WHERE s.id = ?';
    return executeQuery($query, [$id], $conn);
}

function get_sale_items(int $saleId, $conn)
{
    $query = 'SELECT i.*, p.name AS product_name, u.unit_name
           FROM sale_items i
           JOIN products p      ON p.id = i.product_id
           JOIN product_units u ON u.id = i.unit_id
          WHERE i.sale_id = ? ORDER BY i.id';
    return executeQuery($query, [$saleId], $conn);
}

function get_sale_payments(int $saleId, $conn)
{
    $query = 'SELECT * FROM payments WHERE sale_id = ? ORDER BY id';
    return executeQuery($query, [$saleId], $conn);
}

// ---------- rows used by the sale service (business logic) transactions ----------

function find_sale_row(int $id, $conn)
{
    $query = 'SELECT * FROM sales WHERE id = ?';
    return executeQuery($query, [$id], $conn);
}

// REC-20261107-0001, REC-20261107-0002, ... (the counter restarts every day)
function next_receipt_number($conn): string
{
    $prefix = 'REC-' . date('Ymd') . '-';
    $query = 'SELECT COALESCE(MAX(CAST(SUBSTRING(receipt_no, 14) AS UNSIGNED)), 0) + 1 AS next_no FROM sales WHERE receipt_no LIKE ?';
    $result = executeQuery($query, [$prefix . '%'], $conn);
    $n = (int)($result['data'][0]['next_no'] ?? 1);
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function insert_sale(array $values, $conn)
{
    $query = "INSERT INTO sales
                   (receipt_no, customer_id, cash_session_id, subtotal, discount, total_amount,
                    amount_paid, credit_amount, status, sold_by, discount_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $result = executeQuery($query, $values, $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}

function insert_open_tab_sale(string $receipt, int $sessionId, int $userId, $conn)
{
    $query = "INSERT INTO sales
                       (receipt_no, table_session_id, subtotal, discount, total_amount, amount_paid,
                        credit_amount, status, sold_by)
                     VALUES (?, ?, 0, 0, 0, 0, 0, 'open', ?)";
    $result = executeQuery($query, [$receipt, $sessionId, $userId], $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}

function update_product_stock(int $productId, int $newStock, $conn)
{
    $query = 'UPDATE products SET quantity_in_stock = ? WHERE id = ?';
    return executeQuery($query, [$newStock, $productId], $conn);
}

function insert_sale_item(int $saleId, array $l, $conn)
{
    $query = 'INSERT INTO sale_items
                       (sale_id, product_id, unit_id, quantity, base_quantity, unit_price, unit_cost, subtotal, cost_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
    return executeQuery($query, [
        $saleId, $l['product_id'], $l['unit_id'], $l['quantity'], $l['base_quantity'],
        $l['unit_price'], $l['unit_cost'], $l['subtotal'], $l['cost_total'],
    ], $conn);
}

function insert_tab_sale_item(int $saleId, array $l, ?int $orderItemId, $conn)
{
    $query = 'INSERT INTO sale_items
                       (sale_id, product_id, unit_id, quantity, base_quantity, unit_price, unit_cost,
                        subtotal, cost_total, line_status, order_item_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    return executeQuery($query, [
        $saleId, $l['product_id'], $l['unit_id'], $l['quantity'], $l['base_quantity'],
        $l['unit_price'], $l['unit_cost'], $l['subtotal'], $l['cost_total'], 'active', $orderItemId,
    ], $conn);
}

function insert_payment(int $saleId, string $method, float $amount, ?string $reference, int $userId, $conn)
{
    $query = 'INSERT INTO payments (sale_id, method, amount, reference, received_by) VALUES (?, ?, ?, ?, ?)';
    return executeQuery($query, [$saleId, $method, $amount, $reference, $userId], $conn);
}

function insert_customer_debt(int $customerId, int $saleId, float $credit, $conn)
{
    $query = "INSERT INTO customer_debts (customer_id, sale_id, original_amount, paid_amount, balance, status)
                     VALUES (?, ?, ?, 0, ?, 'open')";
    return executeQuery($query, [$customerId, $saleId, $credit, $credit], $conn);
}

function update_customer_debt(int $customerId, float $newDebt, $conn)
{
    $query = 'UPDATE customers SET current_debt = ? WHERE id = ?';
    return executeQuery($query, [round($newDebt, 2), $customerId], $conn);
}

function cancel_sale_row(int $saleId, string $reason, int $userId, $conn)
{
    $query = "UPDATE sales SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?";
    return executeQuery($query, [$userId, $reason, $saleId], $conn);
}

function sum_cash_paid(int $saleId, $conn): float
{
    $query = "SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE sale_id = ? AND method = 'cash'";
    $result = executeQuery($query, [$saleId], $conn);
    return (float)($result['data'][0]['total'] ?? 0);
}
