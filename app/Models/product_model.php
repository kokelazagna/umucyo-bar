<?php
// ============================================================================
// Products and their selling units (database queries only).
// The create / update rules (with their transaction) live in product_service.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';

// The units a product can be sold in, and the ones that can be its base unit.
function product_unit_names(): array
{
    return ['bottle', 'can', 'crate', 'pack'];
}

function product_base_units(): array
{
    return ['bottle', 'can'];
}

// $f: q (search text), category (id), status ('active'/'inactive'/''), low (true = low stock only)
// $page = 1-based page, $perPage = rows per page. Returns ['data' => rows, 'total' => full count].
function search_products(array $f, int $page, int $perPage, $conn)
{
    $query = 'SELECT p.*, c.name AS category_name
              FROM products p
              JOIN categories c ON c.id = p.category_id
             WHERE 1 = 1';
    $params = [];

    if (($f['q'] ?? '') !== '') {
        $query .= ' AND (p.name LIKE ? OR p.brand LIKE ? OR p.barcode LIKE ? OR p.product_code LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if (($f['category'] ?? 0) > 0) {
        $query .= ' AND p.category_id = ?';
        $params[] = $f['category'];
    }
    if (in_array($f['status'] ?? '', ['active', 'inactive'], true)) {
        $query .= ' AND p.status = ?';
        $params[] = $f['status'];
    }
    if (!empty($f['low'])) {
        $query .= ' AND p.quantity_in_stock <= p.min_stock';
    }
    $query .= ' ORDER BY p.created_at DESC, p.id DESC';

    return paged_query($query, $params, $page, $perPage, $conn);
}

// All ACTIVE products (used by the POS, purchases and stock forms).
function get_active_products($conn)
{
    return search_products(['q' => '', 'category' => 0, 'status' => 'active', 'low' => false], 1, 1000, $conn);
}

// Every product, active or not.
function get_all_products($conn)
{
    return search_products(['q' => '', 'category' => 0, 'status' => '', 'low' => false], 1, 1000, $conn);
}

// Selling units of several products at once.  Result: [product_id => [unit, unit, ...]]
function get_units_for_products(array $ids, $conn): array
{
    if (!$ids) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $result = executeQuery("SELECT * FROM product_units WHERE product_id IN ($marks) ORDER BY conversion_factor", array_values($ids), $conn);

    $grouped = [];
    foreach ($result['data'] as $u) {
        $grouped[$u['product_id']][] = $u;
    }
    return $grouped;
}

function find_product(int $id, $conn)
{
    $query = 'SELECT * FROM products WHERE id = ?';
    return executeQuery($query, [$id], $conn);
}

// The selling units except the base unit (crate, pack...).
function get_extra_units(int $productId, $conn)
{
    $query = 'SELECT * FROM product_units WHERE product_id = ? AND is_base = 0 ORDER BY conversion_factor';
    return executeQuery($query, [$productId], $conn);
}

// id, name and code of every product (for drop-downs).
function get_product_names($conn)
{
    $query = 'SELECT id, name, product_code FROM products ORDER BY id DESC';
    return executeQuery($query, null, $conn);
}

// Products that are past their expiry date but still in stock.
function get_expired_products_in_stock($conn)
{
    $query = 'SELECT id, name, quantity_in_stock, base_unit, expiry_date FROM products
          WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND quantity_in_stock > 0 ORDER BY expiry_date';
    return executeQuery($query, null, $conn);
}

// PRD-0001, PRD-0002, ...
function next_product_code($conn): string
{
    $query = "SELECT COALESCE(MAX(CAST(SUBSTRING(product_code, 5) AS UNSIGNED)), 0) + 1 AS next_no
           FROM products WHERE product_code LIKE 'PRD-%'";
    $result = executeQuery($query, null, $conn);
    $n = (int)($result['data'][0]['next_no'] ?? 1);
    return 'PRD-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function insert_product(array $d, $conn)
{
    $query = 'INSERT INTO products
               (product_code, category_id, name, brand, barcode, base_unit,
                purchase_price, selling_price, min_stock, expiry_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $result = executeQuery($query, [
        next_product_code($conn), $d['category_id'], $d['name'], $d['brand'], $d['barcode'], $d['base_unit'],
        $d['purchase_price'], $d['selling_price'], $d['min_stock'], $d['expiry_date'], $d['status'],
    ], $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}

function insert_product_unit(int $productId, string $name, $factor, $price, int $isBase, $conn)
{
    $query = 'INSERT INTO product_units (product_id, unit_name, conversion_factor, selling_price, is_base)
                    VALUES (?, ?, ?, ?, ?)';
    return executeQuery($query, [$productId, $name, $factor, $price, $isBase], $conn);
}

function delete_product_unit(int $unitId, int $productId, $conn)
{
    $query = 'DELETE FROM product_units WHERE id = ? AND product_id = ? AND is_base = 0';
    return executeQuery($query, [$unitId, $productId], $conn);
}

function update_base_unit(int $productId, string $baseUnit, $price, $conn)
{
    $query = 'UPDATE product_units SET unit_name = ?, selling_price = ? WHERE product_id = ? AND is_base = 1';
    return executeQuery($query, [$baseUnit, $price, $productId], $conn);
}

function update_product_unit(int $unitId, int $productId, string $name, int $factor, $price, $conn)
{
    $query = 'UPDATE product_units SET unit_name = ?, conversion_factor = ?, selling_price = ?
                      WHERE id = ? AND product_id = ? AND is_base = 0';
    return executeQuery($query, [$name, $factor, $price, $unitId, $productId], $conn);
}

function update_product_row(int $id, array $d, $conn)
{
    $query = 'UPDATE products
                SET category_id = ?, name = ?, brand = ?, barcode = ?, base_unit = ?,
                    purchase_price = ?, selling_price = ?, min_stock = ?, expiry_date = ?, status = ?
              WHERE id = ?';
    return executeQuery($query, [
        $d['category_id'], $d['name'], $d['brand'], $d['barcode'], $d['base_unit'],
        $d['purchase_price'], $d['selling_price'], $d['min_stock'], $d['expiry_date'], $d['status'], $id,
    ], $conn);
}

// ---------- rows used by the services (business logic) transactions ----------

function find_product_locked(int $productId, $conn)
{
    $query = 'SELECT id, name, status, base_unit, quantity_in_stock, avg_cost FROM products WHERE id = ? FOR UPDATE';
    return executeQuery($query, [$productId], $conn);
}

function set_product_status(int $id, string $status, $conn)
{
    $query = 'UPDATE products SET status = ? WHERE id = ?';
    return executeQuery($query, [$status, $id], $conn);
}
