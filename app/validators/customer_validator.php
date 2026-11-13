<?php
// ============================================================================
// Customer form validation (format rules; the credit limit check for managers
// is a rule of its own below).
// ============================================================================

function validate_customer(array $d, bool $isManager): array
{
    $errors = [];
    if ($d['full_name'] === '' || mb_strlen($d['full_name']) > 120) {
        $errors[] = 'Full name is required (maximum 120 characters).';
    }
    if ($d['phone'] !== '' && !preg_match('/^[0-9+\s-]{7,20}$/', $d['phone'])) {
        $errors[] = 'Phone number is not valid (digits, +, spaces, 7 to 20 characters).';
    }
    if (mb_strlen($d['address']) > 255) {
        $errors[] = 'Address is too long (maximum 255 characters).';
    }
    // Only a manager or the owner can set the credit limit and status
    if ($isManager && isset($d['credit_limit']) && !is_numeric($d['credit_limit'])) {
        $errors[] = 'Credit limit must be a number of 0 or more.';
    }
    return $errors;
}

function validate_credit_limit(string $limit): ?string
{
    if (!is_numeric($limit) || (float)$limit < 0 || (float)$limit > 100000000) {
        return 'Credit limit must be a number of 0 or more.';
    }
    return null;
}

function validate_debt_payment(string $amount, string $method): array
{
    $errors = [];
    if (!is_numeric($amount) || (float)$amount <= 0) {
        $errors[] = 'Enter a payment amount greater than 0.';
    }
    return $errors;
}
