<?php
// ============================================================================
// Business rules for sales. A sale is ONE database transaction:
// stock, stock movements, payments, cash movements and customer debt are saved
// together, or none of them are.
// The queries live in sale_model.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';
require_once __DIR__ . '/../models/sale_model.php';
require_once __DIR__ . '/../models/product_model.php';
require_once __DIR__ . '/../models/stock_model.php';
require_once __DIR__ . '/cash_service.php';
require_once __DIR__ . '/audit_service.php';

// Creates a sale.
//   $in: customer_id, discount, override_limit,
//        items[]    = product_id, unit_id, quantity
//        payments[] = method, amount, reference
//   $role: role of the seller (decides who may give discounts / override credit limits)
// Returns ['id' => ..., 'receipt' => ..., 'total' => ..., 'credit' => ...]
function create_sale(array $in, int $userId, string $role, $conn): array
{
    $session = get_open_cash_session($conn);
    if (!$session) {
        throw new RuntimeException('No cash session is open. Open one in Cash Management first.');
    }
    $isManager = in_array($role, ['owner', 'manager'], true);

    $items = $in['items'] ?? [];
    if (!$items) {
        throw new RuntimeException('The cart is empty.');
    }

    $discount = round((float)($in['discount'] ?? 0), 2);
    if ($discount < 0) {
        throw new RuntimeException('Discount cannot be negative.');
    }
    if ($discount > 0 && !$isManager) {
        throw new RuntimeException('Only a manager or the owner can give a discount.');
    }

    // Keep only payments with an amount greater than 0
    $payments = [];
    foreach (($in['payments'] ?? []) as $p) {
        if (!in_array($p['method'], ['cash', 'mobile_money', 'bank'], true)) {
            throw new RuntimeException('Invalid payment method.');
        }
        $amount = round((float)$p['amount'], 2);
        if ($amount <= 0) {
            continue;
        }
        $payments[] = ['method' => $p['method'], 'amount' => $amount, 'reference' => $p['reference'] ?? null];
    }
    $customerId = (int)($in['customer_id'] ?? 0);
    $override   = !empty($in['override_limit']);

    // Two people saving at the same second could get the same receipt number: try again (max 3 times)
    for ($attempt = 1; ; $attempt++) {
        db_begin($conn);
        try {
            // ---- 1. lock products, check stock, read prices from the database ----
            $lines    = [];
            $subtotal = 0.0;
            foreach ($items as $n => $item) {
                $lineNo = $n + 1;
                $qty    = (int)$item['quantity'];
                if ($qty < 1 || $qty > 100000) {
                    throw new RuntimeException("Line $lineNo: quantity must be a whole number from 1 to 100000.");
                }
                $unitResult = executeQuery('SELECT * FROM product_units WHERE id = ? AND product_id = ?', [(int)$item['unit_id'], (int)$item['product_id']], $conn);
                if (!$unitResult['success'] || empty($unitResult['data'])) {
                    throw new RuntimeException("Line $lineNo: invalid unit for this product.");
                }
                $unit = $unitResult['data'][0];
                $productResult = find_product_locked((int)$item['product_id'], $conn);
                if (!$productResult['success'] || empty($productResult['data']) || $productResult['data'][0]['status'] !== 'active') {
                    throw new RuntimeException("Line $lineNo: this product is not available for sale.");
                }
                $product = $productResult['data'][0];

                $baseQty = $qty * (int)$unit['conversion_factor'];
                $stock   = (int)$product['quantity_in_stock'];
                if ($stock < $baseQty) {
                    throw new RuntimeException('Not enough stock for "' . $product['name'] . '": ' . $stock . ' '
                        . $product['base_unit'] . '(s) available, ' . $baseQty . ' requested.');
                }
                $price = (float)$unit['selling_price'];
                if ($price <= 0) {
                    throw new RuntimeException('"' . $product['name'] . '" has no selling price for ' . $unit['unit_name'] . '.');
                }

                $newStock = $stock - $baseQty;
                update_product_stock((int)$product['id'], $newStock, $conn);

                $lineTotal = round($qty * $price, 2);
                $subtotal += $lineTotal;
                $lines[] = [
                    'product_id' => (int)$product['id'], 'unit_id' => (int)$unit['id'], 'quantity' => $qty,
                    'base_quantity' => $baseQty, 'unit_price' => $price, 'unit_cost' => (float)$product['avg_cost'],
                    'subtotal' => $lineTotal, 'cost_total' => round($baseQty * (float)$product['avg_cost'], 2),
                    'balance_after' => $newStock,
                ];
            }

            // ---- 2. totals, payments, credit ----
            $subtotal = round($subtotal, 2);
            if ($discount > $subtotal + 0.009) {
                throw new RuntimeException('Discount cannot be more than the subtotal.');
            }
            $total = round($subtotal - $discount, 2);
            if ($total <= 0) {
                throw new RuntimeException('The sale total must be greater than 0.');
            }
            $paid = 0.0;
            foreach ($payments as $p) {
                $paid += $p['amount'];
            }
            $paid = round($paid, 2);
            if ($paid > $total + 0.009) {
                throw new RuntimeException('Payments (' . number_format($paid, 0) . ' RWF) are more than the total ('
                    . number_format($total, 0) . ' RWF).');
            }
            $credit = round(max($total - $paid, 0), 2);   // the part the customer will pay later
            if ($credit < 0.01) {
                $credit = 0.0;
            }

            $customer = null;
            if ($customerId > 0) {
                $customerResult = executeQuery('SELECT * FROM customers WHERE id = ? FOR UPDATE', [$customerId], $conn);
                if (!$customerResult['success'] || empty($customerResult['data']) || $customerResult['data'][0]['status'] !== 'active') {
                    throw new RuntimeException('The selected customer is not active.');
                }
                $customer = $customerResult['data'][0];
            }
            if ($credit > 0) {
                if (!$customer) {
                    throw new RuntimeException('Select a customer to sell on credit, or record payments that cover the total.');
                }
                $limit = (float)$customer['credit_limit'];
                if ($limit <= 0) {
                    throw new RuntimeException($customer['full_name'] . ' is not authorized for credit (credit limit is 0).');
                }
                $newDebt = round((float)$customer['current_debt'] + $credit, 2);
                if ($newDebt > $limit + 0.009) {
                    if (!$override) {
                        throw new RuntimeException('Credit limit exceeded for ' . $customer['full_name'] . ': limit '
                            . number_format($limit, 0) . ' RWF, current debt ' . number_format((float)$customer['current_debt'], 0)
                            . ' RWF, this sale adds ' . number_format($credit, 0) . ' RWF.'
                            . ($isManager ? ' Tick "Authorize over limit" to allow it.' : ' Ask a manager to authorize it.'));
                    }
                    if (!$isManager) {
                        throw new RuntimeException('Only a manager or the owner can authorize a sale over the credit limit.');
                    }
                }
            }

            // ---- 3. save the sale ----
            $receipt = next_receipt_number($conn);
            $saleInserted = insert_sale([
                $receipt, $customer ? (int)$customer['id'] : null, $session['id'], $subtotal, $discount, $total,
                $paid, $credit, 'completed', $userId, $discount > 0 ? $userId : null,
            ], $conn);
            if (!$saleInserted['success']) {
                throw new RuntimeException('The sale could not be saved. Please try again.');
            }
            $saleId = (int)$saleInserted['data']['id'];

            foreach ($lines as $l) {
                // unit_cost = weighted average cost at the moment of sale (profit stays correct later)
                $item = insert_sale_item($saleId, $l, $conn);
                if (!$item['success']) {
                    throw new RuntimeException('A sale line could not be saved. Please try again.');
                }
                insert_stock_movement($l['product_id'], 'sale', -$l['base_quantity'], $l['balance_after'], $l['unit_cost'], 'sale', $saleId, 'Sale ' . $receipt, $userId, $conn);
            }

            foreach ($payments as $p) {
                $reference = ($p['reference'] !== null && $p['reference'] !== '') ? $p['reference'] : null;
                insert_payment($saleId, $p['method'], $p['amount'], $reference, $userId, $conn);
                if ($p['method'] === 'cash') {
                    record_cash_transaction((int)$session['id'], 'sale', 'in', $p['amount'], 'sale', $saleId, 'Sale ' . $receipt, $userId, $conn);
                }
            }

            if ($credit > 0) {
                insert_customer_debt((int)$customer['id'], $saleId, $credit, $conn);
                update_customer_debt((int)$customer['id'], (float)$customer['current_debt'] + $credit, $conn);
                if ($override && round((float)$customer['current_debt'] + $credit, 2) > (float)$customer['credit_limit'] + 0.009) {
                    audit_log($conn, $userId, 'credit_limit_override', 'sale', $saleId, $receipt . ' for ' . $customer['full_name']);
                }
            }

            if ($discount > 0) {
                audit_log($conn, $userId, 'discount', 'sale', $saleId, $receipt . ': ' . number_format($discount, 0) . ' RWF off ' . number_format($subtotal, 0));
            }
            audit_log($conn, $userId, 'sale_create', 'sale', $saleId, $receipt . ' total ' . number_format($total, 0) . ' RWF');

            db_commit($conn);
            return ['id' => $saleId, 'receipt' => $receipt, 'total' => $total, 'credit' => $credit];
        } catch (Throwable $e) {
            db_rollback($conn);
            $duplicateReceipt = strpos($e->getMessage(), 'receipt_no') !== false;
            if (!$duplicateReceipt || $attempt >= 3) {
                throw $e;
            }
        }
    }
}

// Completed sales are NEVER deleted. They are cancelled, which restores the stock
// with reversal records, reverses the customer debt and refunds the cash portion.
function cancel_sale(int $saleId, string $reason, int $userId, $conn): void
{
    db_begin($conn);
    try {
        $saleResult = find_sale_row($saleId, $conn);
        if (!$saleResult['success'] || empty($saleResult['data'])) {
            throw new RuntimeException('Sale not found.');
        }
        $sale = $saleResult['data'][0];
        if ($sale['status'] === 'open') {
            throw new RuntimeException('This is still an OPEN table bill. Void the served items (with a reason) or close the table session instead of cancelling.');
        }
        if ($sale['status'] !== 'completed') {
            throw new RuntimeException('This sale is already cancelled.');
        }

        // Credit part: remove the debt (only possible when nothing was repaid yet)
        $debtResult = executeQuery('SELECT * FROM customer_debts WHERE sale_id = ? FOR UPDATE', [$saleId], $conn);
        $debt = $debtResult['data'][0] ?? null;
        if ($debt) {
            if ((float)$debt['paid_amount'] > 0) {
                throw new RuntimeException('This credit sale already has debt repayments, so it cannot be cancelled.');
            }
            $customerResult = executeQuery('SELECT * FROM customers WHERE id = ? FOR UPDATE', [(int)$debt['customer_id']], $conn);
            $customer = $customerResult['data'][0] ?? null;
            $newDebt  = $customer ? max(0, round((float)$customer['current_debt'] - (float)$debt['original_amount'], 2)) : 0;
            if ($customer) {
                update_customer_debt((int)$customer['id'], $newDebt, $conn);
            }
            executeQuery("UPDATE customer_debts SET status = 'cancelled', balance = 0 WHERE id = ?", [$debt['id']], $conn);
        }

        // Cash refund (needs an open cash session)
        $cashPaid = sum_cash_paid($saleId, $conn);
        if ($cashPaid > 0) {
            $session = get_open_cash_session($conn);
            if (!$session) {
                throw new RuntimeException('Open a cash session first: this sale was paid with cash, which must be refunded from the till.');
            }
            record_cash_transaction((int)$session['id'], 'refund', 'out', $cashPaid, 'sale', $saleId,
                'Refund for cancelled sale ' . $sale['receipt_no'], $userId, $conn);
        }

        // Stock reversal with movement records. Lines voided while the table
        // bill was open were already restored at that time, so only ACTIVE
        // lines are reversed here.
        $items = executeQuery("SELECT * FROM sale_items WHERE sale_id = ? AND line_status = 'active' ORDER BY id", [$saleId], $conn);
        foreach ($items['data'] as $item) {
            $productResult = find_product_locked((int)$item['product_id'], $conn);
            $product = $productResult['data'][0] ?? null;
            if (!$product) {
                throw new RuntimeException('A product of this sale no longer exists.');
            }
            $newQty  = (int)$product['quantity_in_stock'] + (int)$item['base_quantity'];
            update_product_stock((int)$product['id'], $newQty, $conn);
            insert_stock_movement((int)$product['id'], 'sale_cancel', (int)$item['base_quantity'], $newQty, (float)$item['unit_cost'], 'sale', $saleId,
                'Sale ' . $sale['receipt_no'] . ' cancelled: ' . $reason, $userId, $conn);
        }

        cancel_sale_row($saleId, $reason, $userId, $conn);

        // A cancelled table bill reopens its table session so the
        // tab can be corrected and settled again.
        if ($sale['table_session_id']) {
            $tsessionResult = executeQuery('SELECT * FROM table_sessions WHERE id = ? FOR UPDATE', [(int)$sale['table_session_id']], $conn);
            $tsession = $tsessionResult['data'][0] ?? null;
            if ($tsession && $tsession['status'] === 'closed') {
                reopen_table_session((int)$tsession['id'], $conn);
                set_table_status((int)$tsession['table_id'], 'occupied', $conn);
                audit_log($conn, $userId, 'table_session_reopen', 'table_session', (int)$tsession['id'],
                    'Reopened after cancelling bill ' . $sale['receipt_no']);
            }
        }

        audit_log($conn, $userId, 'sale_cancel', 'sale', $saleId, $sale['receipt_no'] . ': ' . $reason);
        db_commit($conn);
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}
