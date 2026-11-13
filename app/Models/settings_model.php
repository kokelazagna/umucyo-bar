<?php
// ============================================================================
// Settings: product units and the audited conversion / price changes.
// Database queries only - the change rules live in settings_service.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';

// Products with their units nested, for the settings table.
function get_settings_products($conn): array
{
    $products = executeQuery(
        'SELECT id, product_code, name, base_unit, selling_price, status
           FROM products ORDER BY name',
        null,
        $conn
    );
    $units = executeQuery(
        'SELECT u.*, p.name AS product_name
           FROM product_units u JOIN products p ON p.id = u.product_id
          ORDER BY p.name, u.is_base DESC, u.conversion_factor',
        null,
        $conn
    );

    $byProduct = [];
    foreach ($units['data'] as $u) {
        $byProduct[(int)$u['product_id']][] = $u;
    }
    $list = [];
    foreach ($products['data'] as $p) {
        $p['units'] = $byProduct[(int)$p['id']] ?? [];
        $list[] = $p;
    }
    return $list;
}

// One selling unit with its product (used by the service transaction).
function find_unit_with_product(int $unitId, $conn)
{
    $query = 'SELECT u.*, p.name AS product_name, p.base_unit
               FROM product_units u JOIN products p ON p.id = u.product_id
              WHERE u.id = ? FOR UPDATE';
    return executeQuery($query, [$unitId], $conn);
}

function update_product_selling_price(int $productId, float $price, $conn)
{
    $query = 'UPDATE products SET selling_price = ? WHERE id = ?';
    return executeQuery($query, [$price, $productId], $conn);
}

function update_unit_row(int $unitId, string $unitName, ?int $factor, float $price, $conn)
{
    $query = $factor === null
        ? 'UPDATE product_units SET unit_name = ?, selling_price = ? WHERE id = ?'
        : 'UPDATE product_units SET unit_name = ?, conversion_factor = ?, selling_price = ? WHERE id = ?';
    $params = $factor === null
        ? [$unitName, $price, $unitId]
        : [$unitName, $factor, $price, $unitId];
    return executeQuery($query, $params, $conn);
}

function insert_price_history(array $values, $conn)
{
    $query = 'INSERT INTO product_price_history
               (product_id, unit_id, unit_name, conversion_factor, selling_price,
                previous_factor, previous_price, change_kind, reason, changed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    return executeQuery($query, $values, $conn);
}

// Recent conversion / price changes for the settings screen.
function get_unit_change_history(int $limit, $conn)
{
    $query = 'SELECT h.*, p.name AS product_name, u.full_name AS changed_by_name
           FROM product_price_history h
           JOIN products p  ON p.id = h.product_id
           LEFT JOIN users u ON u.id = h.changed_by
          ORDER BY h.id DESC LIMIT ' . max(1, min($limit, 200));
    return executeQuery($query, null, $conn);
}
