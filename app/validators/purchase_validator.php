<?php
// ============================================================================
// Purchase form validation (format rules; supplier/product lookups stay in the controller).
// ============================================================================

function validate_purchase_header(array $h): array
{
    $errors = [];
    if (!is_valid_date($h['purchase_date'])) {
        $errors[] = 'Purchase date is not valid.';
    } elseif ($h['purchase_date'] > date('Y-m-d')) {
        $errors[] = 'Purchase date cannot be in the future.';
    }
    if (mb_strlen($h['notes']) > 255) {
        $errors[] = 'Notes are too long (maximum 255 characters).';
    }
    if (!in_array($h['payment_method'], payment_methods(), true)) {
        $errors[] = 'Choose a payment method.';
        $h['payment_method'] = 'cash';
    }
    return $errors;
}

// One purchase line: quantity must be a whole number, cost a number of 0 or more.
function validate_purchase_line(int $lineNo, string $qty, string $cost): array
{
    $errors = [];
    if (!ctype_digit($qty) || (int)$qty < 1 || (int)$qty > 100000) {
        $errors[] = "Line $lineNo: quantity must be a whole number from 1 to 100000.";
    }
    if (!is_numeric($cost) || (float)$cost < 0 || (float)$cost > 100000000) {
        $errors[] = "Line $lineNo: unit cost must be a number of 0 or more.";
    }
    return $errors;
}

function validate_purchase_amount_paid(array $h, float $total): array
{
    $errors = [];
    if (!is_numeric($h['amount_paid']) || (float)$h['amount_paid'] < 0) {
        $errors[] = 'Amount paid must be a number of 0 or more.';
    } elseif ((float)$h['amount_paid'] > $total) {
        $errors[] = 'Amount paid cannot be more than the purchase total.';
    }
    return $errors;
}
