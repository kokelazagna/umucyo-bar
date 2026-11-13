<?php
// ============================================================================
// Supplier form validation.
// ============================================================================

function validate_supplier(array $d): array
{
    $errors = [];
    if ($d['name'] === '' || mb_strlen($d['name']) > 120) {
        $errors[] = 'Supplier name is required (maximum 120 characters).';
    }
    if (mb_strlen($d['business_name']) > 150) {
        $errors[] = 'Business name is too long (maximum 150 characters).';
    }
    if ($d['phone'] !== '' && !preg_match('/^[0-9+\s-]{7,20}$/', $d['phone'])) {
        $errors[] = 'Phone number is not valid (digits, +, spaces, 7 to 20 characters).';
    }
    if ($d['email'] !== '' && (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($d['email']) > 120)) {
        $errors[] = 'Email address is not valid.';
    }
    if (mb_strlen($d['address']) > 255) {
        $errors[] = 'Address is too long (maximum 255 characters).';
    }
    if (!in_array($d['status'], ['active', 'inactive'], true)) {
        $d['status'] = 'active';
    }
    return $errors;
}

function validate_payment_amount(string $amount): ?string
{
    if (!is_numeric($amount) || (float)$amount <= 0) {
        return 'Enter a payment amount greater than 0.';
    }
    return null;
}

function validate_cancel_reason(string $reason): ?string
{
    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
        return 'Enter a reason for cancelling (3 to 255 characters).';
    }
    return null;
}
