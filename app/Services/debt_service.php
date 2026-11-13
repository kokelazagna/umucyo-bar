<?php
// ============================================================================
// Customer debt repayments. The oldest debt is paid first.
// The queries live in customer_model and cash_service.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';
require_once __DIR__ . '/../models/customer_model.php';
require_once __DIR__ . '/../models/sale_model.php';
require_once __DIR__ . '/cash_service.php';
require_once __DIR__ . '/audit_service.php';

// Returns ['payment_id' => ..., 'remaining' => debt left after this payment]
function pay_customer_debt(int $customerId, float $amount, string $method, ?string $reference, ?string $notes, int $userId, $conn): array
{
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than 0.');
    }
    if (!in_array($method, ['cash', 'mobile_money', 'bank'], true)) {
        throw new RuntimeException('Choose a payment method.');
    }

    $session = get_open_cash_session($conn);
    if ($method === 'cash' && !$session) {
        throw new RuntimeException('Open a cash session first: cash received must be recorded in the till.');
    }

    db_begin($conn);
    try {
        $customerResult = executeQuery('SELECT * FROM customers WHERE id = ? FOR UPDATE', [$customerId], $conn);
        if (!$customerResult['success'] || empty($customerResult['data'])) {
            throw new RuntimeException('Customer not found.');
        }
        $customer = $customerResult['data'][0];

        $debts = executeQuery(
            "SELECT * FROM customer_debts WHERE customer_id = ? AND status IN ('open', 'partial') ORDER BY id FOR UPDATE",
            [$customerId],
            $conn
        );

        $owed = 0.0;
        foreach ($debts['data'] as $d) {
            $owed += (float)$d['balance'];
        }
        $owed = round($owed, 2);
        if ($owed <= 0) {
            throw new RuntimeException('This customer has no outstanding debt.');
        }
        if ($amount > $owed + 0.009) {
            throw new RuntimeException('The payment (' . number_format($amount, 0) . ' RWF) is more than the customer owes ('
                . number_format($owed, 0) . ' RWF).');
        }

        // allocate the payment to the oldest debts first
        $left = $amount;
        foreach ($debts['data'] as $d) {
            if ($left <= 0.009) {
                break;
            }
            $pay     = round(min($left, (float)$d['balance']), 2);
            $newPaid = round((float)$d['paid_amount'] + $pay, 2);
            $newBal  = round((float)$d['balance'] - $pay, 2);
            $status  = $newBal <= 0.009 ? 'paid' : 'partial';
            if ($status === 'paid') {
                $newBal = 0.0;
            }
            executeQuery(
                'UPDATE customer_debts SET paid_amount = ?, balance = ?, status = ? WHERE id = ?',
                [$newPaid, $newBal, $status, $d['id']],
                $conn
            );
            $left = round($left - $pay, 2);
        }

        $payment = executeQuery(
            'INSERT INTO debt_payments (customer_id, customer_debt_id, cash_session_id, amount, method, reference, received_by, notes)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?)',
            [
                $customerId, $session ? $session['id'] : null, $amount, $method,
                ($reference !== null && $reference !== '') ? $reference : null, $userId,
                ($notes !== null && $notes !== '') ? $notes : null,
            ],
            $conn
        );
        if (!$payment['success']) {
            throw new RuntimeException('The payment could not be saved. Please try again.');
        }
        $paymentId = $conn->insert_id;

        $newDebt = max(0, round((float)$customer['current_debt'] - $amount, 2));
        update_customer_debt($customerId, $newDebt, $conn);

        if ($method === 'cash') {
            record_cash_transaction((int)$session['id'], 'debt_payment', 'in', $amount, 'customer', $customerId,
                'Debt payment from ' . $customer['full_name'], $userId, $conn);
        }
        audit_log($conn, $userId, 'debt_payment', 'customer', $customerId,
            $customer['full_name'] . ': ' . number_format($amount, 0) . ' RWF by ' . $method);

        db_commit($conn);
        return ['payment_id' => $paymentId, 'remaining' => $newDebt];
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}
