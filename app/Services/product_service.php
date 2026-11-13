<?php
// ============================================================================
// Product rules: creating and updating a product with its selling units is ONE
// transaction (all or nothing). The queries live in product_model.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';
require_once __DIR__ . '/../models/product_model.php';
require_once __DIR__ . '/audit_service.php';

// Creates the product and its units in ONE transaction.
// $units = extra selling units, each ['name', 'factor', 'price']
function create_product(array $d, array $units, $conn): int
{
    db_begin($conn);
    try {
        $inserted = insert_product($d, $conn);
        if (!$inserted['success']) {
            throw new RuntimeException(product_error_message($inserted['msg']));
        }
        $productId = (int)$inserted['data']['id'];

        $base = insert_product_unit($productId, $d['base_unit'], 1, $d['selling_price'], 1, $conn);
        if (!$base['success']) {
            throw new RuntimeException('The product could not be saved with its base unit.');
        }
        foreach ($units as $u) {
            $row = insert_product_unit($productId, $u['name'], $u['factor'], $u['price'], 0, $conn);
            if (!$row['success']) {
                throw new RuntimeException(product_error_message($row['msg']));
            }
        }

        db_commit($conn);
        return $productId;
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}

// Updates the product and its units in ONE transaction.
// NOTE: stock quantity and avg_cost are never edited here.
// $units rows: ['id', 'name', 'factor', 'price', 'remove']
function update_product(int $id, array $d, array $units, $conn): void
{
    db_begin($conn);
    try {
        $updated = update_product_row($id, $d, $conn);
        if (!$updated['success']) {
            throw new RuntimeException(product_error_message($updated['msg']));
        }

        // 1. removals first (frees unit names)
        foreach ($units as $u) {
            if ($u['id'] > 0 && $u['remove']) {
                $deleted = delete_product_unit($u['id'], $id, $conn);
                if (!$deleted['success']) {
                    throw new RuntimeException('A selling unit that is already used in purchases or sales cannot be removed.');
                }
            }
        }

        // 2. base unit follows the product's base unit and selling price
        update_base_unit($id, $d['base_unit'], $d['selling_price'], $conn);

        // 3. update existing / insert new extra units
        foreach ($units as $u) {
            if ($u['remove']) {
                continue;
            }
            if ($u['id'] > 0) {
                $row = update_product_unit($u['id'], $id, $u['name'], $u['factor'], $u['price'], $conn);
            } else {
                $row = insert_product_unit($id, $u['name'], $u['factor'], $u['price'], 0, $conn);
            }
            if (!$row['success']) {
                throw new RuntimeException(product_error_message($row['msg']));
            }
        }

        db_commit($conn);
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}

// Turns a database error message into a message a user can understand.
function product_error_message(string $dbMessage): string
{
    if (strpos($dbMessage, 'barcode') !== false) {
        return 'This barcode is already used by another product.';
    }
    if (strpos($dbMessage, 'uq_product_unit') !== false) {
        return 'Each selling unit can be listed only once per product.';
    }
    return db_error_message(null, 'Database error. Please try again.');
}
