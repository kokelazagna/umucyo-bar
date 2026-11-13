<?php
// ============================================================================
// Stock adjustment form validation (format rules; the stock rules themselves
// live in stock_service).
// ============================================================================

function validate_stock_adjustment(array $d): array
{
    $errors = [];
    if (!ctype_digit($d['quantity']) || (int)$d['quantity'] < 1) {
        $errors[] = 'Quantity must be a whole number of 1 or more.';
    }
    if ((int)$d['product_id'] <= 0) {
        $errors[] = 'Choose a product.';
    }
    return $errors;
}
