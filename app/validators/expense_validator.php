<?php
// ============================================================================
// Expense form validation.
// ============================================================================

function validate_expense(array $d): array
{
    $errors = [];
    if ((int)$d['category_id'] <= 0) {
        $errors[] = 'Choose an expense category.';
    }
    if (mb_strlen($d['description']) < 3 || mb_strlen($d['description']) > 255) {
        $errors[] = 'Description is required (3 to 255 characters).';
    }
    if (!is_numeric($d['amount']) || (float)$d['amount'] <= 0 || (float)$d['amount'] > 1000000000) {
        $errors[] = 'Amount must be a number greater than 0.';
    }
    if (!in_array($d['payment_method'], payment_methods(), true)) {
        $errors[] = 'Choose a payment method.';
    }
    if (!is_valid_date($d['expense_date'])) {
        $errors[] = 'Expense date is not valid.';
    } elseif ($d['expense_date'] > date('Y-m-d')) {
        $errors[] = 'Expense date cannot be in the future.';
    }
    return $errors;
}
