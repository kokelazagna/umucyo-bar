<?php
// ============================================================================
// Receiving a debt repayment from a customer.
// ============================================================================

require_once __DIR__ . '/../services/debt_service.php';
require_once __DIR__ . '/../validators/customer_validator.php';

function handleDebtRequest($action, $data, $conn) {
    require_role(['manager', 'cashier']);
    switch ($action) {
        case 'pay':
            return debt_pay($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function debt_pay($conn): void
{
    $customerId = post_int('customer_id');
    $amount     = post_text('amount');
    $method     = post_text('payment_method');
    $reference  = mb_substr(post_text('reference'), 0, 60);
    $notes      = mb_substr(post_text('notes'), 0, 255);

    $errors = validate_debt_payment($amount, $method);
    if ($errors) {
        flash('danger', $errors[0]);
    } else {
        try {
            $result = pay_customer_debt($customerId, (float)$amount, $method, $reference, $notes, (int)current_user_id(), $conn);
            flash('success', 'Payment of ' . rwf($amount) . ' recorded. Remaining debt: ' . rwf($result['remaining']) . '.');
        } catch (RuntimeException $ex) {
            flash('danger', $ex->getMessage());
        }
    }
    redirect('customer/statement?id=' . $customerId);
}
