<?php
// ============================================================================
// Purchases from suppliers (database queries only).
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';

// $f: q (reference), supplier (id), status, from, to
// Paged: returns ['success','data','total'] - used by the purchases list
// AND by the supplier history view (both paginate).
function search_purchases(array $f, int $page, int $perPage, $conn)
{
    $query = 'SELECT p.*, s.name AS supplier_name
              FROM purchases p
              JOIN suppliers s ON s.id = p.supplier_id
             WHERE 1 = 1';
    $params = [];
    if (($f['q'] ?? '') !== '') {
        $query .= ' AND p.reference_no LIKE ?';
        $params[] = '%' . $f['q'] . '%';
    }
    if (($f['supplier'] ?? 0) > 0) {
        $query .= ' AND p.supplier_id = ?';
        $params[] = $f['supplier'];
    }
    if (in_array($f['status'] ?? '', ['pending', 'confirmed', 'cancelled'], true)) {
        $query .= ' AND p.status = ?';
        $params[] = $f['status'];
    }
    if (looks_like_date($f['from'] ?? '')) {
        $query .= ' AND p.purchase_date >= ?';
        $params[] = $f['from'];
    }
    if (looks_like_date($f['to'] ?? '')) {
        $query .= ' AND p.purchase_date <= ?';
        $params[] = $f['to'];
    }
    $query .= ' ORDER BY p.purchase_date DESC, p.id DESC';
    return paged_query($query, $params, $page, $perPage, $conn);
}

function find_purchase(int $id, $conn)
{
    $query = 'SELECT p.*, s.name AS supplier_name, s.business_name, s.phone AS supplier_phone,
                cu.full_name AS created_name, fu.full_name AS confirmed_name, xu.full_name AS cancelled_name
           FROM purchases p
           JOIN suppliers s      ON s.id  = p.supplier_id
           LEFT JOIN users cu    ON cu.id = p.created_by
           LEFT JOIN users fu    ON fu.id = p.confirmed_by
           LEFT JOIN users xu    ON xu.id = p.cancelled_by
          WHERE p.id = ?';
    return executeQuery($query, [$id], $conn);
}

// One purchase row with no joins (used inside the service transactions).
function find_purchase_row(int $id, $conn)
{
    $query = 'SELECT * FROM purchases WHERE id = ?';
    return executeQuery($query, [$id], $conn);
}

function get_purchase_items(int $purchaseId, $conn)
{
    $query = 'SELECT i.*, pr.name AS product_name, pr.product_code, pr.base_unit, u.unit_name
           FROM purchase_items i
           JOIN products pr      ON pr.id = i.product_id
           JOIN product_units u  ON u.id  = i.unit_id
          WHERE i.purchase_id = ?
          ORDER BY i.id';
    return executeQuery($query, [$purchaseId], $conn);
}

// The raw item rows of a purchase (used by the service transactions).
function get_purchase_item_rows(int $purchaseId, $conn, string $order = 'ASC')
{
    $query = "SELECT * FROM purchase_items WHERE purchase_id = ? ORDER BY id " . ($order === 'DESC' ? 'DESC' : 'ASC');
    return executeQuery($query, [$purchaseId], $conn);
}

// PUR-20261107-0001, PUR-20261107-0002, ... (the counter restarts every day)
function next_purchase_reference($conn): string
{
    $prefix = 'PUR-' . date('Ymd') . '-';
    $query = 'SELECT COALESCE(MAX(CAST(SUBSTRING(reference_no, 14) AS UNSIGNED)), 0) + 1 AS next_no
           FROM purchases WHERE reference_no LIKE ?';
    $result = executeQuery($query, [$prefix . '%'], $conn);
    $n = (int)($result['data'][0]['next_no'] ?? 1);
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function insert_purchase(array $h, string $ref, int $userId, $conn)
{
    $query = "INSERT INTO purchases
                   (reference_no, supplier_id, purchase_date, total_cost, amount_paid, balance, status, notes, created_by)
                 VALUES (?, ?, ?, ?, 0, ?, 'pending', ?, ?)";
    $result = executeQuery($query, [$ref, $h['supplier_id'], $h['purchase_date'], $h['total'], $h['total'], $h['notes'], $userId], $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}

function insert_purchase_item(int $purchaseId, array $r, $conn)
{
    $query = 'INSERT INTO purchase_items
                       (purchase_id, product_id, unit_id, quantity, base_quantity, unit_cost, cost_per_base_unit, total_cost)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
    return executeQuery($query, [$purchaseId, $r['product_id'], $r['unit_id'], $r['quantity'], $r['base_quantity'],
                     $r['unit_cost'], $r['cost_per_base'], $r['total']], $conn);
}

function update_purchase_payment(int $purchaseId, float $newPaid, float $total, $conn)
{
    $query = 'UPDATE purchases SET amount_paid = ?, balance = ? WHERE id = ?';
    return executeQuery($query, [$newPaid, round($total - $newPaid, 2), $purchaseId], $conn);
}

function confirm_purchase_row(int $id, int $userId, $conn)
{
    $query = "UPDATE purchases SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW() WHERE id = ?";
    return executeQuery($query, [$userId, $id], $conn);
}

function cancel_purchase_row(int $id, string $reason, int $userId, $conn)
{
    $query = "UPDATE purchases SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?";
    return executeQuery($query, [$userId, $reason, $id], $conn);
}

function update_product_stock_cost(int $productId, int $newQty, float $newAvg, float $purchasePrice, $conn)
{
    $query = 'UPDATE products SET quantity_in_stock = ?, avg_cost = ?, purchase_price = ? WHERE id = ?';
    return executeQuery($query, [$newQty, round($newAvg, 4), round($purchasePrice, 2), $productId], $conn);
}

function update_product_stock_avg(int $productId, int $newQty, float $newAvg, $conn)
{
    $query = 'UPDATE products SET quantity_in_stock = ?, avg_cost = ? WHERE id = ?';
    return executeQuery($query, [$newQty, round($newAvg, 4), $productId], $conn);
}

