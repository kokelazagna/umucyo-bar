<?php
// ============================================================================
// Customers and their credit history (database queries only).
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';

// $f: q (search text), status, debt (true = only customers who owe money)
// Paged: returns ['success','data','total'].
function search_customers(array $f, int $page, int $perPage, $conn)
{
    $query = 'SELECT * FROM customers WHERE 1 = 1';
    $params = [];
    if (($f['q'] ?? '') !== '') {
        $query .= ' AND (full_name LIKE ? OR phone LIKE ? OR customer_code LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($params, $like, $like, $like);
    }
    if (in_array($f['status'] ?? '', ['active', 'inactive', 'blocked'], true)) {
        $query .= ' AND status = ?';
        $params[] = $f['status'];
    }
    if (!empty($f['debt'])) {
        $query .= ' AND current_debt > 0';
    }
    $query .= ' ORDER BY created_at DESC, id DESC';
    return paged_query($query, $params, $page, $perPage, $conn);
}

// Active customers (for the POS customer list).
function get_active_customers($conn)
{
    $query = "SELECT id, customer_code, full_name, phone, credit_limit, current_debt
           FROM customers WHERE status = 'active' ORDER BY full_name";
    return executeQuery($query, null, $conn);
}

function find_customer(int $id, $conn)
{
    $query = 'SELECT * FROM customers WHERE id = ?';
    return executeQuery($query, [$id], $conn);
}

// CUS-0001, CUS-0002, ...
function next_customer_code($conn): string
{
    $query = "SELECT COALESCE(MAX(CAST(SUBSTRING(customer_code, 5) AS UNSIGNED)), 0) + 1 AS next_no
           FROM customers WHERE customer_code LIKE 'CUS-%'";
    $result = executeQuery($query, null, $conn);
    $n = (int)($result['data'][0]['next_no'] ?? 1);
    return 'CUS-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function create_customer(array $d, $conn)
{
    $query = 'INSERT INTO customers (customer_code, full_name, phone, address, credit_limit, status) VALUES (?, ?, ?, ?, ?, ?)';
    $result = executeQuery($query, [next_customer_code($conn), $d['full_name'], $d['phone'], $d['address'], $d['credit_limit'], $d['status']], $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}

// current_debt is never edited here (it only changes through sales and repayments).
function update_customer(int $id, array $d, $conn)
{
    $query = 'UPDATE customers SET full_name = ?, phone = ?, address = ?, credit_limit = ?, status = ? WHERE id = ?';
    return executeQuery($query, [$d['full_name'], $d['phone'], $d['address'], $d['credit_limit'], $d['status'], $id], $conn);
}

function set_customer_status(int $id, string $status, $conn)
{
    $query = 'UPDATE customers SET status = ? WHERE id = ?';
    return executeQuery($query, [$status, $id], $conn);
}

// Credit sales of one customer (paginated - statement table 1).
function get_customer_debts(int $id, int $page, int $perPage, $conn)
{
    $query = 'SELECT d.*, s.receipt_no, s.sale_date
           FROM customer_debts d JOIN sales s ON s.id = d.sale_id
          WHERE d.customer_id = ? ORDER BY d.id';
    return paged_query($query, [$id], $page, $perPage, $conn);
}

// Repayments made by one customer (paginated - statement table 2).
function get_customer_payments(int $id, int $page, int $perPage, $conn)
{
    $query = 'SELECT p.*, u.full_name AS received_name
           FROM debt_payments p LEFT JOIN users u ON u.id = p.received_by
          WHERE p.customer_id = ? ORDER BY p.id';
    return paged_query($query, [$id], $page, $perPage, $conn);
}

// The latest sales of one customer (paginated - statement table 3).
function get_customer_sales(int $id, int $page, int $perPage, $conn)
{
    $query = 'SELECT id, receipt_no, sale_date, total_amount, amount_paid, credit_amount, status
           FROM sales WHERE customer_id = ? ORDER BY id DESC';
    return paged_query($query, [$id], $page, $perPage, $conn);
}

// Finds a customer by exact name (case-insensitive); used by the
// "Other / New..." option so a typed name reuses the existing customer.
function find_customer_by_name(string $name, $conn)
{
    $query = 'SELECT id, full_name, status FROM customers WHERE LOWER(full_name) = ? LIMIT 1';
    return executeQuery($query, [mb_strtolower($name)], $conn);
}
