<?php
// ============================================================================
// Settings rules: changing a selling unit's conversion factor and price.
// Every change is recorded in product_price_history and audited. The base unit
// is always factor 1; its price is the product's selling price.
// The queries live in settings_model.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';
require_once __DIR__ . '/../models/settings_model.php';
require_once __DIR__ . '/audit_service.php';

// Changes one selling unit's factor and/or price, records the previous values
// and audits the action. Completed transactions keep their own
// conversion_factor_snapshot / unit_price_snapshot, so history is never rewritten.
function update_unit_conversion(int $unitId, int $factor, float $price, int $userId, ?string $reason, $conn): array
{
    if ($factor < 1 || $factor > 1000) {
        throw new RuntimeException('The conversion factor must be a whole number from 1 to 1000.');
    }
    if ($price < 0) {
        throw new RuntimeException('The price cannot be negative.');
    }

    db_begin($conn);
    try {
        $unitResult = find_unit_with_product($unitId, $conn);
        if (!$unitResult['success'] || empty($unitResult['data'])) {
            throw new RuntimeException('That selling unit was not found.');
        }
        $unit = $unitResult['data'][0];

        $isBase = (int)$unit['is_base'] === 1;
        if ($isBase && $factor !== 1) {
            throw new RuntimeException('The base unit (' . $unit['unit_name'] . ') always has a factor of 1.');
        }
        if (!$isBase && $factor < 2) {
            throw new RuntimeException('A crate or pack must contain at least 2 ' . $unit['base_unit'] . 's.');
        }

        $oldFactor = (int)$unit['conversion_factor'];
        $oldPrice  = (float)$unit['selling_price'];

        if ($isBase) {
            update_product_selling_price((int)$unit['product_id'], $price, $conn);
            update_unit_row($unitId, $unit['unit_name'], 1, $price, $conn);
        } else {
            update_unit_row($unitId, $unit['unit_name'], $factor, $price, $conn);
        }

        insert_price_history([
            (int)$unit['product_id'], $unitId, $unit['unit_name'], $factor, $price,
            $oldFactor, $oldPrice, 'unit_changed', $reason ? mb_substr($reason, 0, 255) : null, $userId,
        ], $conn);

        audit_log($conn, $userId, 'unit_conversion_change', 'product', (int)$unit['product_id'],
            $unit['product_name'] . ' / ' . $unit['unit_name'] . ': ' . $oldFactor . ' -> ' . $factor
            . ', price ' . number_format($oldPrice, 0) . ' -> ' . number_format($price, 0) . ' RWF'
            . ($reason ? ' (' . $reason . ')' : ''));

        db_commit($conn);
        return ['product_id' => (int)$unit['product_id'], 'factor' => $factor, 'price' => $price];
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}
