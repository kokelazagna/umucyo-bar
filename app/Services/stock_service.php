<?php
// ============================================================================
// Stock adjustments: damaged, expired, missing, returned products and manual
// corrections. Every adjustment needs a reason and a responsible user, and
// writes a stock movement. The queries live in stock_model.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';
require_once __DIR__ . '/../models/stock_model.php';
require_once __DIR__ . '/audit_service.php';

// $d: product_id, unit_id, quantity, type, direction (only used for 'correction'), reason
// Returns the id of the new adjustment record.
function apply_stock_adjustment(array $d, int $userId, $conn): int
{
    // Which kinds always decrease stock, and which always increase it
    $fixedDirection = ['damaged' => 'decrease', 'expired' => 'decrease', 'missing' => 'decrease', 'returned' => 'increase'];
    $type = $d['type'];

    if ($type === 'correction') {
        if (!in_array($d['direction'], ['increase', 'decrease'], true)) {
            throw new RuntimeException('Choose whether the correction increases or decreases stock.');
        }
        $direction = $d['direction'];
    } elseif (isset($fixedDirection[$type])) {
        $direction = $fixedDirection[$type];
    } else {
        throw new RuntimeException('Choose a valid adjustment type.');
    }

    $reason = trim($d['reason']);
    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
        throw new RuntimeException('A reason is required (3 to 255 characters).');
    }
    $qty = (int)$d['quantity'];
    if ($qty < 1 || $qty > 1000000) {
        throw new RuntimeException('Quantity must be a whole number of 1 or more.');
    }

    db_begin($conn);
    try {
        $unitResult = find_unit_of_product((int)$d['unit_id'], (int)$d['product_id'], $conn);
        if (!$unitResult['success'] || empty($unitResult['data'])) {
            throw new RuntimeException('Choose a valid unit for this product.');
        }
        $productResult = find_product_stock_row((int)$d['product_id'], $conn);
        if (!$productResult['success'] || empty($productResult['data'])) {
            throw new RuntimeException('Product not found.');
        }
        $product = $productResult['data'][0];

        $baseQty = $qty * (int)$unitResult['data'][0]['conversion_factor'];   // quantity in base units (bottles / cans)
        $stock   = (int)$product['quantity_in_stock'];
        if ($direction === 'decrease' && $baseQty > $stock) {
            throw new RuntimeException('Cannot remove ' . $baseQty . ' ' . $product['base_unit'] . '(s) of "' . $product['name']
                . '": only ' . $stock . ' in stock. Stock can never go below zero.');
        }
        $newStock = $direction === 'increase' ? $stock + $baseQty : $stock - $baseQty;
        $cost     = (float)$product['avg_cost'];   // stock is valued at the current weighted average cost

        update_product_stock((int)$product['id'], $newStock, $conn);

        $adjustment = insert_stock_adjustment($d, $baseQty, $reason, $userId, $conn);
        if (!$adjustment['success']) {
            throw new RuntimeException('The stock adjustment could not be saved. Please try again.');
        }
        $adjustmentId = (int)$adjustment['data']['id'];

        insert_adjustment_movement((int)$product['id'], $type, $direction === 'increase' ? $baseQty : -$baseQty, $newStock, $cost, $adjustmentId, $reason, $userId, $conn);

        audit_log($conn, $userId, 'stock_adjustment', 'product', (int)$product['id'],
            $product['name'] . ': ' . $type . ' ' . ($direction === 'increase' ? '+' : '-') . $baseQty . ' ' . $product['base_unit'] . '(s): ' . $reason);

        db_commit($conn);
        return $adjustmentId;
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}
