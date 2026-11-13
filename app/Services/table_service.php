<?php
// ============================================================================
// Table service: open/resume table sessions, server-persisted draft rounds,
// "confirm served" with exactly-once stock deduction, audited voids, settlement
// with split payments / partial payments / authorised credit, table transfer
// and merge. The queries live in table_model.
//
// Rules implemented here:
//  - Draft items NEVER touch stock. Confirming a round as SERVED deducts
//    stock once and creates the bill obligation (sale_items on an OPEN sale).
//  - The open sale of a session IS the table tab. Open tabs are operational
//    receivables and are reported separately from customer credit debts.
//  - A table can have only ONE open session; enforced by locking the table
//    row (FOR UPDATE) inside the transaction.
//  - Completed records are reversed with audited compensating events, never
//    deleted.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';
require_once __DIR__ . '/../models/table_model.php';
require_once __DIR__ . '/../models/sale_model.php';
require_once __DIR__ . '/../models/product_model.php';
require_once __DIR__ . '/../models/stock_model.php';
require_once __DIR__ . '/cash_service.php';
require_once __DIR__ . '/audit_service.php';

// ---------- Opening / closing sessions ----------

// Opens a session for a table. No customer name is required or recorded.
function open_table_session(int $tableId, int $waiterId, int $userId, $conn): int
{
    db_begin($conn);
    try {
        $tableResult = find_table($tableId, $conn);
        $table = $tableResult['data'][0] ?? null;
        if (!$table || !$table['active']) {
            throw new RuntimeException('Table not found or not active.');
        }
        if ($table['status'] === 'out_of_service') {
            throw new RuntimeException('Table ' . $table['code'] . ' is out of service.');
        }
        $openSession = find_open_session_of_table($tableId, $conn);
        if (!empty($openSession['data'])) {
            throw new RuntimeException('Table ' . $table['code'] . ' already has an open session (session #' . $openSession['data'][0]['id'] . ').');
        }

        if ($waiterId > 0) {
            $waiter = find_waiter($waiterId, $conn);
            if (empty($waiter['data'])) {
                throw new RuntimeException('Choose an active waiter.');
            }
        }

        $inserted = insert_table_session($tableId, $userId, $waiterId > 0 ? $waiterId : null, $conn);
        if (!$inserted['success']) {
            throw new RuntimeException('The table session could not be opened. Please try again.');
        }
        $sessionId = (int)$inserted['data']['id'];
        set_table_status($tableId, 'occupied', $conn);

        audit_log($conn, $userId, 'table_session_open', 'table_session', $sessionId,
            'Table ' . $table['code'] . ' opened' . ($waiterId > 0 ? ' (waiter assigned)' : ''));
        db_commit($conn);
        return $sessionId;
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}

// Closes a session that was opened by mistake and has nothing served and no
// payments. Anything that has been served must be settled or voided first.
function close_empty_table_session(int $sessionId, int $userId, $conn): void
{
    db_begin($conn);
    try {
        $session = find_table_session_row($sessionId, $conn);
        if (!$session['success'] || empty($session['data']) || $session['data'][0]['status'] !== 'open') {
            throw new RuntimeException('This table session is not open.');
        }
        $served = count_served_items($sessionId, $conn);
        $sale = find_open_sale_of_session($sessionId, $conn);
        if ($served > 0 || !empty($sale['data'])) {
            throw new RuntimeException('This table already has served items or a bill. Settle the bill (or void the items) instead of closing it.');
        }

        $sessionRow = $session['data'][0];
        $table = find_table_code((int)$sessionRow['table_id'], $conn);
        close_table_session($sessionId, $userId, 'Closed with nothing served', $conn);
        set_table_status((int)$sessionRow['table_id'], 'available', $conn);

        audit_log($conn, $userId, 'table_session_close', 'table_session', $sessionId,
            'Table ' . ($table['data'][0]['code'] ?? '#' . $sessionRow['table_id']) . ' closed empty.');
        db_commit($conn);
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}

// ---------- Draft rounds (no stock movement) ----------

function get_or_create_draft_order(int $sessionId, int $userId, $conn): int
{
    $existing = find_draft_order($sessionId, $conn);
    if (!empty($existing['data'])) {
        return (int)$existing['data'][0]['id'];
    }
    $inserted = insert_draft_order($sessionId, $userId, $conn);
    if (!$inserted['success']) {
        throw new RuntimeException('The draft round could not be started. Please try again.');
    }
    return (int)$inserted['data']['id'];
}

// Adds a line to the current draft round. Stock is NOT touched here.
function add_draft_item(int $sessionId, int $productId, int $unitId, int $quantity, int $userId, $conn): int
{
    if ($quantity < 1 || $quantity > 100000) {
        throw new RuntimeException('Quantity must be a whole number from 1 to 100000.');
    }
    db_begin($conn);
    try {
        $session = find_table_session_row($sessionId, $conn);
        if (!$session['success'] || empty($session['data']) || $session['data'][0]['status'] !== 'open') {
            throw new RuntimeException('This table session is not open.');
        }
        $productResult = find_product($productId, $conn);
        $product = $productResult['data'][0] ?? null;
        if (!$product || $product['status'] !== 'active') {
            throw new RuntimeException('This product is not available.');
        }
        $unitResult = executeQuery('SELECT * FROM product_units WHERE id = ? AND product_id = ?', [$unitId, $productId], $conn);
        if (!$unitResult['success'] || empty($unitResult['data'])) {
            throw new RuntimeException('Invalid unit for this product.');
        }
        $unit = $unitResult['data'][0];
        $price = (float)$unit['selling_price'];
        if ($price <= 0) {
            throw new RuntimeException('"' . $product['name'] . '" has no selling price for ' . $unit['unit_name'] . '.');
        }

        $orderId = get_or_create_draft_order($sessionId, $userId, $conn);
        $baseQty = $quantity * (int)$unit['conversion_factor'];

        $item = insert_draft_item($orderId, $productId, $unitId, (int)$unit['conversion_factor'], $price,
            (float)$product['avg_cost'], $quantity, $baseQty, $conn);
        if (!$item['success']) {
            throw new RuntimeException('The item could not be added. Please try again.');
        }
        $itemId = (int)$item['data']['id'];
        db_commit($conn);
        return $itemId;
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}

// Updates the quantity of a draft line (0 removes it). Draft lines only.
function update_draft_item(int $sessionId, int $itemId, int $quantity, int $userId, $conn): void
{
    db_begin($conn);
    try {
        $rowResult = find_draft_item($itemId, $conn);
        $row = $rowResult['data'][0] ?? null;
        if (!$row || (int)$row['table_session_id'] !== $sessionId) {
            throw new RuntimeException('Draft item not found for this table.');
        }
        if ($row['state'] !== 'draft' || $row['order_state'] !== 'draft') {
            throw new RuntimeException('Only unserved draft items can be changed.');
        }
        if ($quantity <= 0) {
            delete_draft_item($itemId, $conn);
        } elseif ($quantity > 100000) {
            throw new RuntimeException('Quantity must be a whole number from 1 to 100000.');
        } else {
            $newBase = $quantity * (int)$row['conversion_factor_snapshot'];
            update_draft_item_row($itemId, $quantity, $newBase, $conn);
        }
        db_commit($conn);
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}

// Removes every draft line of the session (clear the unserved round).
function clear_draft_items(int $sessionId, int $userId, $conn): void
{
    db_begin($conn);
    try {
        $drafts = get_draft_item_ids($sessionId, $conn);
        foreach ($drafts as $d) {
            delete_draft_item((int)$d['id'], $conn);
        }
        db_commit($conn);
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}

// ---------- Confirm served (the ONLY point where stock is deducted) ------

// Confirms every draft line of the session as SERVED:
//  - locks the products, validates stock (never negative),
//  - deducts stock ONCE per line with one stock movement,
//  - appends the lines to the session's OPEN sale (the tab),
//  - creates the open sale on the first serving round.
// Repeats are impossible: the draft lines change state inside the same
// transaction, so a retry finds nothing left to serve.
function serve_draft_round(int $sessionId, int $userId, $conn): array
{
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        db_begin($conn);
        try {
            $sessionResult = find_session_with_table($sessionId, $conn);
            $session = $sessionResult['data'][0] ?? null;
            if (!$session || $session['status'] !== 'open') {
                throw new RuntimeException('This table session is not open.');
            }

            $draftItems = get_draft_items_to_serve($sessionId, $conn);
            if (!$draftItems) {
                throw new RuntimeException('There are no unserved draft items to serve.');
            }

            // The tab: one OPEN sale per session, created on the first serving round.
            $sale = find_open_sale_of_session_locked($sessionId, $conn);
            $saleId = null;
            $receipt = null;
            if (!empty($sale['data'])) {
                $saleId = (int)$sale['data'][0]['id'];
                $receipt = $sale['data'][0]['receipt_no'];
            } else {
                $receipt = next_receipt_number($conn);
                $inserted = insert_open_tab_sale($receipt, $sessionId, $userId, $conn);
                if (!$inserted['success']) {
                    throw new RuntimeException('The bill could not be started. Please try again.');
                }
                $saleId = (int)$inserted['data']['id'];
            }

            $subtotalDelta = 0.0;
            $servedCount   = 0;
            foreach ($draftItems as $item) {
                $productResult = find_product_locked((int)$item['product_id'], $conn);
                $product = $productResult['data'][0] ?? null;
                if (!$product || $product['status'] !== 'active') {
                    throw new RuntimeException('"' . ($product['name'] ?? 'Product') . '" is no longer available. Remove it from the round.');
                }
                $stock   = (int)$product['quantity_in_stock'];
                $baseQty = (int)$item['base_quantity'];
                if ($stock < $baseQty) {
                    throw new RuntimeException('Not enough stock for "' . $product['name'] . '": ' . $stock . ' '
                        . $product['base_unit'] . '(s) available, ' . $baseQty . ' requested.');
                }

                $newStock = $stock - $baseQty;
                update_product_stock((int)$product['id'], $newStock, $conn);

                // Cost of goods is the weighted average cost at the moment of serving.
                $avgCost   = (float)$product['avg_cost'];
                $price     = (float)$item['unit_price_snapshot'];
                $lineTotal = round((int)$item['quantity'] * $price, 2);
                $subtotalDelta += $lineTotal;

                insert_tab_sale_item($saleId, [
                    'product_id' => (int)$item['product_id'], 'unit_id' => (int)$item['unit_id'],
                    'quantity' => (int)$item['quantity'], 'base_quantity' => $baseQty,
                    'unit_price' => $price, 'unit_cost' => $avgCost, 'subtotal' => $lineTotal,
                    'cost_total' => round($baseQty * $avgCost, 2),
                ], (int)$item['id'], $conn);

                insert_stock_movement((int)$product['id'], 'sale', -$baseQty, $newStock, $avgCost, 'sale', $saleId,
                    'Served at table ' . $session['table_code'] . ' (round #' . $item['order_id'] . ')', $userId, $conn);

                mark_order_items_served((int)$item['id'], $userId, $conn);
                $servedCount++;
            }

            // Close the draft order; a new draft is created on the next addition.
            close_draft_order($sessionId, $conn);

            // Running totals of the open tab.
            add_open_sale_totals($saleId, $subtotalDelta, $conn);

            audit_log($conn, $userId, 'table_serve', 'sale', $saleId,
                $receipt . ': served ' . $servedCount . ' line(s) at table ' . $session['table_code']);
            db_commit($conn);
            return ['sale_id' => $saleId, 'receipt' => $receipt, 'served' => $servedCount];
        } catch (Throwable $e) {
            db_rollback($conn);
            $duplicateReceipt = strpos($e->getMessage(), 'receipt_no') !== false;
            if (!$duplicateReceipt || $attempt >= 3) {
                throw $e;
            }
        }
    }
    throw new RuntimeException('The serving round could not be saved. Please try again.');
}

// ---------- Void a served line (audited compensating event) ----------

// Only allowed while the bill is still OPEN (before settlement). Restores the
// stock with a reversal movement; the line stays in history as 'void'.
function void_served_item(int $sessionId, int $saleItemId, string $reason, int $userId, $conn): array
{
    $reason = trim($reason);
    if (mb_strlen($reason) < 3) {
        throw new RuntimeException('A reason of at least 3 characters is required to void a served item.');
    }
    db_begin($conn);
    try {
        $sale = find_open_sale_of_session_locked($sessionId, $conn);
        if (empty($sale['data'])) {
            throw new RuntimeException('This table has no open bill to void from.');
        }
        $sale = $sale['data'][0];
        $lineResult = find_sale_item($saleItemId, (int)$sale['id'], $conn);
        $line = $lineResult['data'][0] ?? null;
        if (!$line || $line['line_status'] !== 'active') {
            throw new RuntimeException('This line is not an active served item.');
        }

        void_sale_item($saleItemId, $conn);
        void_order_item((int)$line['order_item_id'], $conn);

        $productResult = find_product_locked((int)$line['product_id'], $conn);
        $product = $productResult['data'][0] ?? null;
        if (!$product) {
            throw new RuntimeException('The product of this line no longer exists.');
        }
        $newQty  = (int)$product['quantity_in_stock'] + (int)$line['base_quantity'];
        update_product_stock((int)$product['id'], $newQty, $conn);
        insert_stock_movement((int)$product['id'], 'sale_cancel', (int)$line['base_quantity'], $newQty, (float)$line['unit_cost'], 'sale', (int)$sale['id'],
            'Void served item (' . $line['quantity'] . ' x) from bill ' . $sale['receipt_no'] . ': ' . $reason, $userId, $conn);

        // Recompute the tab totals without the voided line.
        $subtotal = sum_active_sale_items((int)$sale['id'], $conn);
        $discount = (float)$sale['discount'];
        update_sale_totals((int)$sale['id'], $subtotal, $subtotal - $discount, $conn);

        audit_log($conn, $userId, 'table_item_void', 'sale', (int)$sale['id'],
            $sale['receipt_no'] . ': voided line #' . $saleItemId . ' (' . (int)$line['quantity'] . ' x): ' . $reason);
        db_commit($conn);
        return [
            'subtotal' => round($subtotal, 2),
            'total'    => round($subtotal - $discount, 2),
        ];
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}

// ---------- Settlement (payments, split, partial, credit) ----------

// Records one settlement event for the session's open tab:
//  - one or more payments (cash / mobile_money / bank), with references for
//    electronic methods;
//  - optional manager discount applied at settlement;
//  - optional transfer of the remaining balance to an authorised credit
//    account (registered customer) with credit-limit checks;
//  - when the balance reaches zero the sale is completed, the session is
//    closed and the table returns to Available.
// A settlement that does not cover everything keeps the tab open: a second
// settlement can follow.
function settle_table_session(
    int $sessionId,
    array $payments,
    int $customerId,
    float $discount,
    bool $creditOverride,
    int $userId,
    bool $isManager,
    $conn
): array {
    db_begin($conn);
    try {
        $sessionResult = find_session_with_table($sessionId, $conn);
        $session = $sessionResult['data'][0] ?? null;
        if (!$session || $session['status'] !== 'open') {
            throw new RuntimeException('This table session is not open.');
        }
        $saleResult = find_open_sale_of_session_locked($sessionId, $conn);
        if (empty($saleResult['data'])) {
            throw new RuntimeException('Nothing has been served on this table yet, so there is nothing to settle.');
        }
        $sale = $saleResult['data'][0];
        $activeItems = count_active_sale_items_rows((int)$sale['id'], $conn);
        if ($activeItems === 0) {
            throw new RuntimeException('This bill has no active served items. Void history is kept; open a new round to sell more.');
        }

        $subtotal = (float)$sale['subtotal'];

        // ---- discount (manager authorises it; audited) ----
        $discount = round($discount, 2);
        if ($discount > $subtotal + 0.009) {
            throw new RuntimeException('Discount cannot be more than the subtotal.');
        }
        $total = round($subtotal - $discount, 2);
        if ($total < 0) {
            $total = 0.0;
        }
        $alreadyPaid = round((float)$sale['amount_paid'], 2);
        if ($alreadyPaid > $total + 0.009) {
            throw new RuntimeException('The discount would make the total (' . number_format($total, 0)
                . ' RWF) less than what was already paid (' . number_format($alreadyPaid, 0) . ' RWF). Use a smaller discount.');
        }

        // ---- payments ----
        $paidNow = 0.0;
        foreach ($payments as $n => $p) {
            $lineNo = $n + 1;
            $amount = round((float)($p['amount'] ?? 0), 2);
            $method = (string)($p['method'] ?? '');
            if ($amount <= 0) {
                throw new RuntimeException("Payment line $lineNo: amount must be greater than 0.");
            }
            if (!in_array($method, ['cash', 'mobile_money', 'bank'], true)) {
                throw new RuntimeException("Payment line $lineNo: choose a valid payment method.");
            }
            if (in_array($method, ['mobile_money', 'bank'], true) && trim((string)($p['reference'] ?? '')) === '') {
                throw new RuntimeException("Payment line $lineNo: a transaction reference is required for $method.");
            }
            $paidNow += $amount;
        }
        $paidNow = round($paidNow, 2);

        // ---- credit for the remaining balance (only when a customer is chosen) ----
        $balanceAfterPayments = round($total - $alreadyPaid - $paidNow, 2);
        // With no customer selected the unpaid amount simply stays as the open
        // tab balance; it is NOT forced onto a credit account.
        $credit = $customerId > 0 ? round(max($balanceAfterPayments, 0), 2) : 0.0;
        if ($credit < 0.01) {
            $credit = 0.0;
        }
        if ($paidNow <= 0 && $credit <= 0) {
            throw new RuntimeException('Record at least one payment, or choose a credit customer for the unpaid balance.');
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
                throw new RuntimeException('Select an authorised credit customer to leave ' . number_format($credit, 0)
                    . ' RWF unpaid, or record payments that cover the bill.');
            }
            $limit = (float)$customer['credit_limit'];
            if ($limit <= 0) {
                throw new RuntimeException($customer['full_name'] . ' is not authorized for credit (credit limit is 0).');
            }
            $newDebt = round((float)$customer['current_debt'] + $credit, 2);
            if ($newDebt > $limit + 0.009) {
                if (!$creditOverride) {
                    throw new RuntimeException('Credit limit exceeded for ' . $customer['full_name'] . ': limit '
                        . number_format($limit, 0) . ' RWF, current debt ' . number_format((float)$customer['current_debt'], 0)
                        . ' RWF, this bill adds ' . number_format($credit, 0) . ' RWF.'
                        . ($isManager ? ' Tick "Authorize over limit" to allow it.' : ' Ask a manager to authorize it.'));
                }
                if (!$isManager) {
                    throw new RuntimeException('Only a manager or the owner can authorize a bill over the credit limit.');
                }
            }
        }

        // ---- an open cash session is required for any cash payment ----
        $cashNeeded = 0.0;
        foreach ($payments as $p) {
            if (($p['method'] ?? '') === 'cash') {
                $cashNeeded += (float)$p['amount'];
            }
        }
        $cashSession = null;
        if ($cashNeeded > 0) {
            $cashSession = get_open_cash_session($conn);
            if (!$cashSession) {
                throw new RuntimeException('Open a cash session first: cash payments must be recorded in the till.');
            }
        }

        // ---- save the settlement ----
        if ($paidNow > 0 || $credit > 0) {
            foreach ($payments as $p) {
                $amount    = round((float)$p['amount'], 2);
                $reference = (($p['reference'] ?? '') !== '') ? trim((string)$p['reference']) : null;
                insert_payment((int)$sale['id'], $p['method'], $amount, $reference, $userId, $conn);
                if ($p['method'] === 'cash') {
                    record_cash_transaction(
                        (int)$cashSession['id'], 'sale', 'in', $amount, 'sale', (int)$sale['id'],
                        'Table bill ' . $sale['receipt_no'] . ' (table ' . $session['table_code'] . ')', $userId, $conn
                    );
                }
            }
        }

        $newPaid = round($alreadyPaid + $paidNow, 2);
        update_sale_settlement((int)$sale['id'], $subtotal, $discount, $total, $newPaid, $credit, $discount > 0 ? $userId : $sale['discount_by'], $conn);

        if ($credit > 0) {
            insert_customer_debt((int)$customer['id'], (int)$sale['id'], $credit, $conn);
            update_customer_debt((int)$customer['id'], (float)$customer['current_debt'] + $credit, $conn);
            if ($creditOverride && round((float)$customer['current_debt'] + $credit, 2) > (float)$customer['credit_limit'] + 0.009) {
                audit_log($conn, $userId, 'credit_limit_override', 'sale', (int)$sale['id'],
                    $sale['receipt_no'] . ' for ' . $customer['full_name'] . ' (table ' . $session['table_code'] . ')');
            }
        }
        if ($discount > 0) {
            audit_log($conn, $userId, 'discount', 'sale', (int)$sale['id'],
                $sale['receipt_no'] . ': ' . number_format($discount, 0) . ' RWF off ' . number_format($subtotal, 0)
                . ' (table ' . $session['table_code'] . ')');
        }

        $outstanding = round($total - $newPaid - $credit, 2);
        $closed = false;
        if ($outstanding <= 0.009) {
            // Never strand an unserved draft round on a closed table.
            $openDrafts = count_draft_items($sessionId, $conn);
            if ($openDrafts > 0) {
                throw new RuntimeException('The table still has ' . $openDrafts
                    . ' unserved draft item(s). Serve them (they are not billed) or remove them before closing the bill.');
            }
            // Fully settled: complete the sale, close the session, free the table.
            $cashSessionId = $cashSession ? (int)$cashSession['id'] : null;
            complete_sale((int)$sale['id'], $cashSessionId, $conn);
            close_table_session_settled($sessionId, $userId, $conn);
            close_session_orders($sessionId, $conn);
            set_table_status((int)$session['table_id'], 'available', $conn);
            $closed = true;
            audit_log($conn, $userId, 'table_settle', 'sale', (int)$sale['id'],
                $sale['receipt_no'] . ' settled for table ' . $session['table_code'] . ': total '
                . number_format($total, 0) . ' RWF, paid ' . number_format($newPaid, 0) . ' RWF, credit '
                . number_format($credit, 0) . ' RWF');
        } else {
            audit_log($conn, $userId, 'table_partial_payment', 'sale', (int)$sale['id'],
                $sale['receipt_no'] . ': received ' . number_format($paidNow, 0) . ' RWF, outstanding '
                . number_format($outstanding, 0) . ' RWF (table ' . $session['table_code'] . ')');
        }

        db_commit($conn);
        return [
            'sale_id'     => (int)$sale['id'],
            'receipt'     => $sale['receipt_no'],
            'total'       => $total,
            'paid'        => $newPaid,
            'credit'      => $credit,
            'outstanding' => $outstanding,
            'closed'      => $closed,
        ];
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}

// Number of active served lines of a sale.
function count_active_sale_items_rows(int $saleId, $conn): int
{
    $result = executeQuery("SELECT COUNT(*) AS total FROM sale_items WHERE sale_id = ? AND line_status = 'active'", [$saleId], $conn);
    return (int)($result['data'][0]['total'] ?? 0);
}

// ---------- Transfer and merge ----------

// Moves an open session to another table. History (orders, items, payments,
// stock movements) keeps pointing at the same session, so nothing is lost.
function transfer_table_session(int $sessionId, int $targetTableId, string $reason, int $userId, $conn): void
{
    $reason = trim($reason);
    if (mb_strlen($reason) < 3) {
        throw new RuntimeException('A reason of at least 3 characters is required to transfer a table.');
    }
    db_begin($conn);
    try {
        $session = find_table_session_row($sessionId, $conn);
        if (!$session['success'] || empty($session['data']) || $session['data'][0]['status'] !== 'open') {
            throw new RuntimeException('This table session is not open.');
        }
        $session = $session['data'][0];
        $sourceResult = find_table((int)$session['table_id'], $conn);
        $source = $sourceResult['data'][0] ?? null;
        $targetResult = find_table($targetTableId, $conn);
        $target = $targetResult['data'][0] ?? null;
        if (!$target || !$target['active']) {
            throw new RuntimeException('Target table not found or not active.');
        }
        if ((int)$target['id'] === (int)$source['id']) {
            throw new RuntimeException('Choose a different table.');
        }
        if ($target['status'] === 'out_of_service') {
            throw new RuntimeException('Table ' . $target['code'] . ' is out of service.');
        }
        $targetOpen = find_open_session_of_table((int)$target['id'], $conn);
        if (!empty($targetOpen['data'])) {
            throw new RuntimeException('Table ' . $target['code'] . ' already has an open session.');
        }

        move_session_to_table($sessionId, (int)$target['id'], $conn);
        set_table_status((int)$source['id'], 'available', $conn);
        set_table_status((int)$target['id'], 'occupied', $conn);

        audit_log($conn, $userId, 'table_transfer', 'table_session', $sessionId,
            'Session moved from ' . $source['code'] . ' to ' . $target['code'] . ': ' . $reason);
        db_commit($conn);
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}

// Merges the source session into the target session. Draft orders and the
// open bill (including its payments) move to the target; the source session
// is closed and its table freed. The source sale row (when the target also
// already had one) is marked cancelled with a reference to the surviving
// receipt - records are never deleted.
function merge_table_sessions(int $sourceSessionId, int $targetSessionId, string $reason, int $userId, $conn): array
{
    $reason = trim($reason);
    if (mb_strlen($reason) < 3) {
        throw new RuntimeException('A reason of at least 3 characters is required to merge tables.');
    }
    db_begin($conn);
    try {
        $sourceResult = find_session_with_table($sourceSessionId, $conn);
        $targetResult = find_session_with_table($targetSessionId, $conn);
        $source = $sourceResult['data'][0] ?? null;
        $target = $targetResult['data'][0] ?? null;
        if (!$source || $source['status'] !== 'open') {
            throw new RuntimeException('The source table has no open session.');
        }
        if (!$target || $target['status'] !== 'open') {
            throw new RuntimeException('The target table has no open session.');
        }
        if ((int)$source['id'] === (int)$target['id']) {
            throw new RuntimeException('Choose two different tables.');
        }

        // 1. Orders (draft + history) follow the target session.
        move_sessions_orders((int)$source['id'], (int)$target['id'], $conn);

        $sourceSale = find_open_sale_of_session_locked((int)$source['id'], $conn);
        $targetSale = find_open_sale_of_session_locked((int)$target['id'], $conn);
        $keptReceipt = null;

        if (!empty($sourceSale['data']) && empty($targetSale['data'])) {
            // The whole bill simply moves to the target session.
            move_sales_to_session((int)$sourceSale['data'][0]['id'], (int)$target['id'], $conn);
            $keptReceipt = $sourceSale['data'][0]['receipt_no'];
        } elseif (!empty($sourceSale['data']) && !empty($targetSale['data'])) {
            // Two open bills: move the active lines and the payments, then close
            // the source sale as a merged record (no stock effect: the items move).
            move_sale_items((int)$sourceSale['data'][0]['id'], (int)$targetSale['data'][0]['id'], $conn);
            move_payments((int)$sourceSale['data'][0]['id'], (int)$targetSale['data'][0]['id'], $conn);

            $subtotal = sum_active_sale_items((int)$targetSale['data'][0]['id'], $conn);
            $paid = sum_payments((int)$targetSale['data'][0]['id'], $conn);
            update_sale_totals((int)$targetSale['data'][0]['id'], $subtotal, $subtotal - (float)$targetSale['data'][0]['discount'], $conn);
            executeQuery(
                'UPDATE sales SET amount_paid = ? WHERE id = ?',
                [round($paid, 2), (int)$targetSale['data'][0]['id']],
                $conn
            );
            mark_sale_merged((int)$sourceSale['data'][0]['id'], $userId,
                'Merged into receipt ' . $targetSale['data'][0]['receipt_no'] . ': ' . $reason, $conn);
            $keptReceipt = $targetSale['data'][0]['receipt_no'];
        }

        close_table_session((int)$source['id'], $userId,
            'Merged into session #' . $target['id'] . ' (' . $target['table_code'] . '): ' . $reason, $conn);
        set_table_status((int)$source['table_id'], 'available', $conn);

        audit_log($conn, $userId, 'table_merge', 'table_session', (int)$target['id'],
            'Session #' . $source['id'] . ' (table ' . $source['table_code'] . ') merged into session #'
            . $target['id'] . ' (table ' . $target['table_code'] . '): ' . $reason);
        db_commit($conn);
        return ['target_session' => (int)$target['id'], 'receipt' => $keptReceipt];
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}
