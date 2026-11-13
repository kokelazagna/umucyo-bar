<?php
// ============================================================================
// Stock movement history and stock adjustment records (database queries only).
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';

// Every kind of stock movement that can be stored.
function stock_movement_types(): array
{
    return ['purchase', 'sale', 'sale_cancel', 'purchase_cancel', 'damaged', 'expired', 'missing', 'returned', 'correction'];
}

// The kinds of manual stock adjustment.
function stock_adjustment_types(): array
{
    return ['damaged', 'expired', 'missing', 'returned', 'correction'];
}

// $f: product (id), type, from, to
// Paged: returns ['success','data','total'].
function search_stock_movements(array $f, int $page, int $perPage, $conn)
{
    $query = 'SELECT m.*, p.name AS product_name, p.product_code, p.base_unit, u.full_name AS user_name
              FROM stock_movements m
              JOIN products p   ON p.id = m.product_id
              LEFT JOIN users u ON u.id = m.created_by
             WHERE 1 = 1';
    $params = [];
    if (($f['product'] ?? 0) > 0) {
        $query .= ' AND m.product_id = ?';
        $params[] = $f['product'];
    }
    if (in_array($f['type'] ?? '', stock_movement_types(), true)) {
        $query .= ' AND m.movement_type = ?';
        $params[] = $f['type'];
    }
    if (looks_like_date($f['from'] ?? '')) {
        $query .= ' AND m.created_at >= ?';
        $params[] = $f['from'] . ' 00:00:00';
    }
    if (looks_like_date($f['to'] ?? '')) {
        $query .= ' AND m.created_at <= ?';
        $params[] = $f['to'] . ' 23:59:59';
    }
    $query .= ' ORDER BY m.id DESC';
    return paged_query($query, $params, $page, $perPage, $conn);
}

// $f: type, from, to
// Paged: returns ['success','data','total'].
function search_stock_adjustments(array $f, int $page, int $perPage, $conn)
{
    $query = "SELECT a.*, p.name AS product_name, p.product_code, p.base_unit, u.full_name AS user_name,
                   m.unit_cost, ROUND(a.base_quantity * COALESCE(m.unit_cost, 0), 2) AS value
              FROM stock_adjustments a
              JOIN products p   ON p.id = a.product_id
              LEFT JOIN users u ON u.id = a.adjusted_by
              LEFT JOIN stock_movements m ON m.reference_type = 'adjustment' AND m.reference_id = a.id
             WHERE 1 = 1";
    $params = [];
    if (in_array($f['type'] ?? '', stock_adjustment_types(), true)) {
        $query .= ' AND a.adjustment_type = ?';
        $params[] = $f['type'];
    }
    if (looks_like_date($f['from'] ?? '')) {
        $query .= ' AND a.created_at >= ?';
        $params[] = $f['from'] . ' 00:00:00';
    }
    if (looks_like_date($f['to'] ?? '')) {
        $query .= ' AND a.created_at <= ?';
        $params[] = $f['to'] . ' 23:59:59';
    }
    $query .= ' ORDER BY a.id DESC';
    return paged_query($query, $params, $page, $perPage, $conn);
}

// ---------- rows used by the stock service transaction ----------

function find_unit_of_product(int $unitId, int $productId, $conn)
{
    $query = 'SELECT * FROM product_units WHERE id = ? AND product_id = ?';
    return executeQuery($query, [$unitId, $productId], $conn);
}

function find_product_stock_row(int $productId, $conn)
{
    $query = 'SELECT id, name, base_unit, quantity_in_stock, avg_cost FROM products WHERE id = ? FOR UPDATE';
    return executeQuery($query, [$productId], $conn);
}

function insert_stock_movement(int $productId, string $type, int $change, int $balanceAfter, float $unitCost, string $refType, ?int $refId, string $reason, int $userId, $conn)
{
    $query = 'INSERT INTO stock_movements
           (product_id, movement_type, quantity_change, balance_after, unit_cost, reference_type, reference_id, reason, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
    return executeQuery($query, [$productId, $type, $change, $balanceAfter, $unitCost, $refType, $refId, $reason, $userId], $conn);
}

function insert_stock_adjustment(array $d, int $baseQty, string $reason, int $userId, $conn)
{
    $query = 'INSERT INTO stock_adjustments (product_id, adjustment_type, direction, base_quantity, reason, adjusted_by)
             VALUES (?, ?, ?, ?, ?, ?)';
    $result = executeQuery($query, [$d['product_id'], $d['type'], $d['direction'], $baseQty, $reason, $userId], $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}

function insert_adjustment_movement(int $productId, string $type, int $change, int $newStock, float $cost, int $adjustmentId, string $reason, int $userId, $conn)
{
    $query = "INSERT INTO stock_movements
               (product_id, movement_type, quantity_change, balance_after, unit_cost, reference_type, reference_id, reason, created_by)
             VALUES (?, ?, ?, ?, ?, 'adjustment', ?, ?, ?)";
    return executeQuery($query, [$productId, $type, $change, $newStock, $cost, $adjustmentId, $reason, $userId], $conn);
}
