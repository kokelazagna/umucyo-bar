<?php
// ============================================================================
// Suppliers (database queries only).
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';

// Every supplier with the number of purchases, total bought and amount still owed.
function get_suppliers_with_totals($conn)
{
    $query = "SELECT s.*,
                COUNT(p.id)                   AS purchase_count,
                COALESCE(SUM(p.total_cost),0) AS total_purchased,
                COALESCE(SUM(p.balance),0)    AS outstanding
           FROM suppliers s
           LEFT JOIN purchases p ON p.supplier_id = s.id AND p.status <> 'cancelled'
          GROUP BY s.id
          ORDER BY s.created_at DESC, s.id DESC";
    return executeQuery($query, null, $conn);
}

// The suppliers list for the list view (paged). Manual count: the query has a
// GROUP BY + LEFT JOIN, so the automatic COUNT(*) rewrite in paged_query would
// count the joined rows instead of the suppliers.
function get_suppliers_page(int $page, int $perPage, $conn)
{
    $count = executeQuery('SELECT COUNT(*) AS total FROM suppliers', null, $conn);
    $total = (int)($count['data'][0]['total'] ?? 0);
    $offset = ($page - 1) * $perPage;
    $rows = executeQuery("SELECT s.*,
                COUNT(p.id)                   AS purchase_count,
                COALESCE(SUM(p.total_cost),0) AS total_purchased,
                COALESCE(SUM(p.balance),0)    AS outstanding
           FROM suppliers s
           LEFT JOIN purchases p ON p.supplier_id = s.id AND p.status <> 'cancelled'
          GROUP BY s.id
          ORDER BY s.created_at DESC, s.id DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset, null, $conn);
    return ['success' => $rows['success'], 'error' => $rows['error'], 'data' => $rows['data'], 'total' => $total];
}

// All suppliers (for filters).
function get_supplier_list($conn)
{
    $query = 'SELECT id, name, status FROM suppliers ORDER BY name';
    return executeQuery($query, null, $conn);
}

// Only active suppliers (for the purchase form).
function get_active_suppliers($conn)
{
    $query = "SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name";
    return executeQuery($query, null, $conn);
}

function find_supplier(int $id, $conn)
{
    $query = 'SELECT * FROM suppliers WHERE id = ?';
    return executeQuery($query, [$id], $conn);
}

// SUP-0001, SUP-0002, ...
function next_supplier_code($conn): string
{
    $query = "SELECT COALESCE(MAX(CAST(SUBSTRING(supplier_code, 5) AS UNSIGNED)), 0) + 1 AS next_no
           FROM suppliers WHERE supplier_code LIKE 'SUP-%'";
    $result = executeQuery($query, null, $conn);
    $n = (int)($result['data'][0]['next_no'] ?? 1);
    return 'SUP-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function create_supplier(array $d, $conn)
{
    $query = 'INSERT INTO suppliers (supplier_code, name, business_name, phone, email, address, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)';
    $result = executeQuery($query, [next_supplier_code($conn), $d['name'], $d['business_name'], $d['phone'], $d['email'], $d['address'], $d['status']], $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}

function update_supplier(int $id, array $d, $conn)
{
    $query = 'UPDATE suppliers SET name = ?, business_name = ?, phone = ?, email = ?, address = ?, status = ? WHERE id = ?';
    return executeQuery($query, [$d['name'], $d['business_name'], $d['phone'], $d['email'], $d['address'], $d['status'], $id], $conn);
}

function set_supplier_status(int $id, string $status, $conn)
{
    $query = 'UPDATE suppliers SET status = ? WHERE id = ?';
    return executeQuery($query, [$status, $id], $conn);
}

// Totals over the supplier's non-cancelled purchases.
function get_supplier_summary(int $id, $conn)
{
    $query = "SELECT COUNT(*) AS purchase_count,
                COALESCE(SUM(total_cost),0)  AS total_purchased,
                COALESCE(SUM(amount_paid),0) AS total_paid,
                COALESCE(SUM(balance),0)     AS outstanding
           FROM purchases WHERE supplier_id = ? AND status <> 'cancelled'";
    return executeQuery($query, [$id], $conn);
}

// Finds a supplier by its exact name (case-insensitive); used by the
// "Other / New..." option so a typed name reuses the existing supplier.
function find_supplier_by_name(string $name, $conn)
{
    $query = 'SELECT id, name, status FROM suppliers WHERE LOWER(name) = ? LIMIT 1';
    return executeQuery($query, [mb_strtolower($name)], $conn);
}
