<?php
// ============================================================================
// Business rules for purchases. Every function that changes stock or money
// runs inside ONE database transaction (all or nothing).
// The queries live in purchase_model.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';
require_once __DIR__ . '/../models/purchase_model.php';
require_once __DIR__ . '/../models/stock_model.php';
require_once __DIR__ . '/cash_service.php';
require_once __DIR__ . '/audit_service.php';

// Reads a purchase and locks the row so nobody else can change it meanwhile.
function lock_purchase(int $id, $conn): array
{
    $result = find_purchase_row($id, $conn);
    if (!$result['success'] || empty($result['data'])) {
        throw new RuntimeException('Purchase not found.');
    }
    return $result['data'][0];
}

// Reads a product and locks the row (so two users cannot change its stock at once).
function lock_product_row(int $id, $conn): array
{
    $result = executeQuery('SELECT id, name, quantity_in_stock, avg_cost FROM products WHERE id = ? FOR UPDATE', [$id], $conn);
    if (!$result['success'] || empty($result['data'])) {
        throw new RuntimeException('A product in this purchase no longer exists.');
    }
    return $result['data'][0];
}

// Saves a new purchase as PENDING (stock is NOT increased yet).
//   $h:    supplier_id, purchase_date, notes, total, amount_paid, payment_method
//   $rows: product_id, unit_id, quantity, base_quantity, unit_cost, cost_per_base, total
// Returns ['id' => ..., 'reference' => ...]
function create_purchase(array $h, array $rows, int $userId, $conn): array
{
    // Two people saving at the same second could get the same reference: try again (max 3 times)
    for ($attempt = 1; ; $attempt++) {
        db_begin($conn);
        try {
            $ref = next_purchase_reference($conn);
            $inserted = insert_purchase($h, $ref, $userId, $conn);
            if (!$inserted['success']) {
                throw new RuntimeException('The purchase could not be saved. Please try again.');
            }
            $id = (int)$inserted['data']['id'];

            foreach ($rows as $r) {
                $item = insert_purchase_item($id, $r, $conn);
                if (!$item['success']) {
                    throw new RuntimeException('A purchase line could not be saved. Please try again.');
                }
            }

            if ((float)$h['amount_paid'] > 0) {
                record_purchase_payment(
                    ['id' => $id, 'reference_no' => $ref, 'total_cost' => $h['total'], 'amount_paid' => 0],
                    (float)$h['amount_paid'], $h['payment_method'], $userId, $conn
                );
            }

            db_commit($conn);
            return ['id' => $id, 'reference' => $ref];
        } catch (Throwable $e) {
            db_rollback($conn);
            $duplicateRef = strpos($e->getMessage(), 'reference_no') !== false;
            if (!$duplicateRef || $attempt >= 3) {
                throw $e;
            }
        }
    }
}

// Stock increases ONLY here (rule: a purchase must be confirmed = goods received).
function confirm_purchase(int $id, int $userId, $conn): void
{
    db_begin($conn);
    try {
        $purchase = lock_purchase($id, $conn);
        if ($purchase['status'] !== 'pending') {
            throw new RuntimeException('Only pending purchases can be confirmed.');
        }

        $items = get_purchase_item_rows($id, $conn);
        foreach ($items['data'] as $item) {
            $product = lock_product_row((int)$item['product_id'], $conn);
            $oldQty  = (int)$product['quantity_in_stock'];
            $oldAvg  = (float)$product['avg_cost'];
            $addQty  = (int)$item['base_quantity'];
            $cost    = (float)$item['cost_per_base_unit'];
            $newQty  = $oldQty + $addQty;
            // Weighted average cost
            $newAvg  = $newQty > 0 ? (($oldQty * $oldAvg) + ($addQty * $cost)) / $newQty : $cost;

            update_product_stock_cost($product['id'], $newQty, $newAvg, $cost, $conn);
            insert_stock_movement((int)$product['id'], 'purchase', $addQty, $newQty, $cost, 'purchase', $id,
                'Purchase ' . $purchase['reference_no'] . ' received', $userId, $conn);
        }

        confirm_purchase_row($id, $userId, $conn);
        db_commit($conn);
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}

// Cancelled purchases never increase stock. A confirmed one is reversed with movement records.
function cancel_purchase(int $id, string $reason, int $userId, $conn): void
{
    db_begin($conn);
    try {
        $purchase = lock_purchase($id, $conn);
        if ($purchase['status'] === 'cancelled') {
            throw new RuntimeException('This purchase is already cancelled.');
        }

        if ($purchase['status'] === 'confirmed') {
            $items = get_purchase_item_rows($id, $conn, 'DESC');
            foreach ($items['data'] as $item) {
                $product = lock_product_row((int)$item['product_id'], $conn);
                $qty     = (int)$product['quantity_in_stock'];
                $avg     = (float)$product['avg_cost'];
                $remove  = (int)$item['base_quantity'];
                $cost    = (float)$item['cost_per_base_unit'];

                if ($qty < $remove) {
                    throw new RuntimeException(
                        'Cannot cancel: only ' . $qty . ' of "' . $product['name'] . '" remain in stock but this purchase added '
                        . $remove . '. Some of it was already sold. Use a stock adjustment instead.'
                    );
                }
                $newQty = $qty - $remove;
                $newAvg = $newQty > 0 ? max(0, (($qty * $avg) - ($remove * $cost)) / $newQty) : $avg;

                update_product_stock_avg($product['id'], $newQty, $newAvg, $conn);
                insert_stock_movement((int)$product['id'], 'purchase_cancel', -$remove, $newQty, $cost, 'purchase', $id,
                    'Purchase ' . $purchase['reference_no'] . ' cancelled: ' . $reason, $userId, $conn);
            }
        }

        cancel_purchase_row($id, $reason, $userId, $conn);
        db_commit($conn);
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}

// Partial or full payment to the supplier.
function pay_purchase(int $id, float $amount, string $method, int $userId, $conn): void
{
    db_begin($conn);
    try {
        $purchase = lock_purchase($id, $conn);
        if ($purchase['status'] === 'cancelled') {
            throw new RuntimeException('Cannot pay a cancelled purchase.');
        }
        record_purchase_payment($purchase, $amount, $method, $userId, $conn);
        db_commit($conn);
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}

// Must be called inside a transaction.  $purchase: id, reference_no, total_cost, amount_paid
function record_purchase_payment(array $purchase, float $amount, string $method, int $userId, $conn): void
{
    $amount  = round($amount, 2);
    $total   = round((float)$purchase['total_cost'], 2);
    $newPaid = round((float)$purchase['amount_paid'] + $amount, 2);

    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than 0.');
    }
    if ($newPaid > $total) {
        throw new RuntimeException('Payment is more than the outstanding balance.');
    }

    // Cash paid to a supplier must come out of the till: it needs an open cash
    // session with enough cash in it (so the cash reconciliation stays correct).
    $session = null;
    if ($method === 'cash') {
        $session = get_open_cash_session($conn);
        if (!$session) {
            throw new RuntimeException('Open a cash session first: cash paid to a supplier must come out of the till.');
        }
        $summary = get_cash_summary((int)$session['id'], $conn);
        if ($amount > $summary['expected'] + 0.009) {
            throw new RuntimeException('Not enough cash in the drawer. Expected cash is ' . number_format($summary['expected'], 0) . ' RWF.');
        }
    }

    update_purchase_payment((int)$purchase['id'], $newPaid, $total, $conn);

    if ($method === 'cash' && $session) {
        record_cash_transaction((int)$session['id'], 'supplier_payment', 'out', $amount, 'purchase', (int)$purchase['id'],
            'Supplier payment ' . $purchase['reference_no'], $userId, $conn);
    }
    audit_log($conn, $userId, 'supplier_payment', 'purchase', (int)$purchase['id'],
        $purchase['reference_no'] . ': ' . number_format($amount, 0) . ' RWF by ' . $method);
}
