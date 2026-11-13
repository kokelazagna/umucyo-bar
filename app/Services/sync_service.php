<?php
// ============================================================================
// Offline sync (server side).
// The offline device queues actions in IndexedDB and pushes them here when the
// connection returns. THE SERVER STAYS THE AUTHORITY: every action is executed
// by the SAME business functions used online (create_sale, add_draft_item,
// serve_draft_round, settle_table_session, create_expense), so stock, prices,
// credit limits, cash session, roles and transactions are all re-checked.
// Every queued action carries a unique client_id (idempotency key) recorded in
// sync_log with a UNIQUE index: a retry can never be executed twice.
// The client engine lives in public/assets/js/offline.js.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';
require_once __DIR__ . '/../models/product_model.php';
require_once __DIR__ . '/../models/category_model.php';
require_once __DIR__ . '/../models/customer_model.php';
require_once __DIR__ . '/../models/expense_model.php';
require_once __DIR__ . '/../services/sale_service.php';
require_once __DIR__ . '/../services/table_service.php';
require_once __DIR__ . '/../services/expense_service.php';
require_once __DIR__ . '/../services/cash_service.php';
require_once __DIR__ . '/../services/audit_service.php';

const SYNC_ACTION_TYPES = ['pos_sale', 'table_draft_add', 'table_draft_update', 'table_serve', 'table_settle', 'expense_create'];

// ---------------------------------------------------------------------------
// Snapshot for the offline cache: products + units + prices, categories,
// customers (with limits and debts), the open cash session and the expense
// categories. NO passwords or password hashes are ever included.
// ---------------------------------------------------------------------------
function sync_snapshot($conn): array
{
    // Products with their selling units (same shape as the POS page uses).
    $products = [];
    $active   = get_active_products($conn)['data'];
    $unitsByProduct = get_units_for_products(array_column($active, 'id'), $conn);
    foreach ($active as $p) {
        $units = [];
        foreach ($unitsByProduct[$p['id']] ?? [] as $u) {
            $units[] = [
                'id'     => (int)$u['id'],
                'name'   => $u['unit_name'],
                'factor' => (int)$u['conversion_factor'],
                'price'  => (float)$u['selling_price'],
            ];
        }
        if (!$units) {
            continue;   // a product without units cannot be sold
        }
        $products[] = [
            'id' => (int)$p['id'], 'code' => $p['product_code'], 'name' => $p['name'],
            'brand' => $p['brand'], 'barcode' => $p['barcode'], 'base_unit' => $p['base_unit'],
            'category' => $p['category_name'],
            'stock' => (int)$p['quantity_in_stock'], 'units' => $units,
        ];
    }

    $categories = [];
    foreach (get_active_categories($conn)['data'] as $c) {
        $categories[] = ['id' => (int)$c['id'], 'name' => $c['name']];
    }

    $customers = [];
    foreach (get_active_customers($conn)['data'] as $c) {
        $customers[] = [
            'id' => (int)$c['id'], 'code' => $c['customer_code'], 'name' => $c['full_name'],
            'phone' => $c['phone'], 'limit' => (float)$c['credit_limit'], 'debt' => (float)$c['current_debt'],
        ];
    }

    $expenseCategories = [];
    foreach (get_expense_categories($conn)['data'] as $c) {
        $expenseCategories[] = ['id' => (int)$c['id'], 'name' => $c['name']];
    }

    $session = get_open_cash_session($conn);
    $cashSession = $session ? [
        'id'        => (int)$session['id'],
        'opened_at' => $session['opened_at'],
        'expected'  => (float)(get_cash_summary((int)$session['id'], $conn)['expected'] ?? 0),
    ] : null;

    return [
        'products'           => $products,
        'categories'         => $categories,
        'customers'          => $customers,
        'expense_categories' => $expenseCategories,
        'cash_session'       => $cashSession,
        'server_time'        => date('Y-m-d H:i:s'),
    ];
}

// ---------------------------------------------------------------------------
// Processes a batch of queued actions IN ORDER. Returns one result per action:
//   ['status' => 'accepted'|'rejected'|'duplicate', 'client_id' => ..., 'message' => ...]
// ---------------------------------------------------------------------------
function sync_process_batch(array $actions, int $userId, string $role, $conn): array
{
    $results = [];
    foreach (array_slice($actions, 0, 50, false) as $item) {   // max 50 per push
        $results[] = sync_process_one(is_array($item) ? $item : [], $userId, $role, $conn);
    }
    return $results;
}

function sync_process_one(array $item, int $userId, string $role, $conn): array
{
    $clientId = trim((string)($item['client_id'] ?? ''));
    $type     = (string)($item['type'] ?? '');
    $payload  = is_array($item['payload'] ?? null) ? $item['payload'] : [];
    $madeAt   = (string)($item['created_at'] ?? '');

    if (!preg_match('/^[A-Za-z0-9._-]{8,64}$/', $clientId)) {
        return ['status' => 'rejected', 'client_id' => $clientId, 'message' => 'Invalid idempotency key (this action cannot be accepted).'];
    }
    if (!in_array($type, SYNC_ACTION_TYPES, true)) {
        return ['status' => 'rejected', 'client_id' => $clientId, 'message' => 'This offline action type is not allowed on the server.'];
    }

    // ---- idempotency: record the key BEFORE running the action ----
    // The UNIQUE index on client_id is the guarantee: if the insert fails, the
    // action was already received and must NOT run again.
    $madeAtSql = preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $madeAt) ? str_replace('T', ' ', substr($madeAt, 0, 19)) : null;
    $inserted = executeQuery(
        'INSERT INTO sync_log (client_id, user_id, action_type, payload, status, client_created_at)
         VALUES (?, ?, ?, ?, \'processing\', ?)',
        [$clientId, $userId, $type, json_encode($item, JSON_UNESCAPED_UNICODE), $madeAtSql],
        $conn
    );
    if (!$inserted['success']) {
        // Already recorded: return what happened the first time (never run it again).
        $prev = executeQuery('SELECT status, message FROM sync_log WHERE client_id = ?', [$clientId], $conn);
        $row  = $prev['data'][0] ?? null;
        if ($row && in_array($row['status'], ['accepted', 'rejected'], true)) {
            return ['status' => 'duplicate', 'client_id' => $clientId,
                    'message' => 'Already synced earlier (' . $row['status'] . '). Nothing was done again.'];
        }
        return ['status' => 'duplicate', 'client_id' => $clientId,
                'message' => 'This action is already being processed or its result is unknown. Check the server records before re-creating it.'];
    }

    // ---- run the action through the SAME online business rules ----
    try {
        $result = sync_run_action($type, $payload, $userId, $role, $conn);
        $message = (string)($result['message'] ?? 'Accepted.');
        $refType = $result['ref_type'] ?? null;
        $refId   = isset($result['ref_id']) ? (int)$result['ref_id'] : null;
        executeQuery(
            'UPDATE sync_log SET status = \'accepted\', message = ?, result_ref_type = ?, result_ref_id = ? WHERE client_id = ?',
            [mb_substr($message, 0, 500), $refType, $refId, $clientId],
            $conn
        );
        audit_log($conn, $userId, 'sync_' . $type, $refType, $refId,
            'Synced from offline queue (key ' . $clientId . '): ' . $message);
        return ['status' => 'accepted', 'client_id' => $clientId, 'message' => $message, 'ref' => $result];
    } catch (Throwable $e) {
        $message = $e->getMessage() ?: 'The server rejected this action.';
        executeQuery(
            'UPDATE sync_log SET status = \'rejected\', message = ? WHERE client_id = ?',
            [mb_substr($message, 0, 500), $clientId],
            $conn
        );
        return ['status' => 'rejected', 'client_id' => $clientId, 'message' => $message];
    }
}

// Maps one queued action to its online service function. Any business rule
// failure throws RuntimeException, which becomes a rejection WITH a reason.
function sync_run_action(string $type, array $p, int $userId, string $role, $conn): array
{
    switch ($type) {

        case 'pos_sale':
            // Offline sales are CASH-ONLY territory: walk-in customer, cash or
            // Mobile Money, fully paid. Everything else is rejected with a reason.
            $payments = [];
            foreach ((array)($p['payments'] ?? []) as $pay) {
                $method = (string)($pay['method'] ?? '');
                if ($method === 'bank') {
                    throw new RuntimeException('Bank payments are online only. This sale was rejected.');
                }
                if (!in_array($method, ['cash', 'mobile_money'], true)) {
                    throw new RuntimeException('Invalid payment method in the offline sale.');
                }
                $amount = round((float)($pay['amount'] ?? 0), 2);
                if ($amount > 0) {
                    $payments[] = ['method' => $method, 'amount' => $amount, 'reference' => mb_substr((string)($pay['reference'] ?? ''), 0, 60)];
                }
            }
            $items = [];
            foreach ((array)($p['items'] ?? []) as $i => $line) {
                $items[] = [
                    'product_id' => (int)($line['product_id'] ?? 0),
                    'unit_id'    => (int)($line['unit_id'] ?? 0),
                    'quantity'   => (int)($line['quantity'] ?? 0),
                ];
            }
            $paid = 0.0;
            foreach ($payments as $pay) {
                $paid += $pay['amount'];
            }
            $subtotal = 0.0;
            foreach ($items as $line) {
                $subtotal += $line['quantity'];   // only for a rough credit check below
            }
            if (!count($items)) {
                throw new RuntimeException('The offline sale had no items.');
            }
            if (!count($payments)) {
                throw new RuntimeException('Offline sales must be fully paid (cash or Mobile Money). Credit sales are online only.');
            }
            $customerId = (int)($p['customer_id'] ?? 0);
            if ($customerId !== 0) {
                throw new RuntimeException('Offline sales can only be for the walk-in customer. Credit sales are online only.');
            }
            $result = create_sale([
                'customer_id'    => 0,
                'discount'       => round((float)($p['discount'] ?? 0), 2),
                'override_limit' => false,
                'items'          => $items,
                'payments'       => $payments,
            ], $userId, $role, $conn);
            return ['ref_type' => 'sale', 'ref_id' => (int)$result['id'],
                    'message' => 'Sale ' . $result['receipt'] . ' accepted (' . number_format((float)$result['total'], 0) . ' RWF)'];

        case 'table_draft_add':
            $itemId = add_draft_item(
                (int)($p['session_id'] ?? 0),
                (int)($p['product_id'] ?? 0),
                (int)($p['unit_id'] ?? 0),
                (int)($p['quantity'] ?? 0),
                $userId, $conn
            );
            return ['ref_type' => 'order_item', 'ref_id' => $itemId, 'message' => 'Draft item added to the table tab.'];

        case 'table_draft_update':
            update_draft_item(
                (int)($p['session_id'] ?? 0),
                (int)($p['item_id'] ?? 0),
                (int)($p['quantity'] ?? 0),
                $userId, $conn
            );
            return ['ref_type' => 'order_item', 'ref_id' => (int)($p['item_id'] ?? 0),
                    'message' => ((int)($p['quantity'] ?? 0) === 0) ? 'Draft item removed.' : 'Draft item quantity updated.'];

        case 'table_serve':
            $served = serve_draft_round((int)($p['session_id'] ?? 0), $userId, $conn);
            return ['ref_type' => 'sale', 'ref_id' => (int)($served['sale_id'] ?? 0),
                    'message' => 'Serving round confirmed on the table tab.'];

        case 'table_settle':
            $payments = [];
            foreach ((array)($p['payments'] ?? []) as $pay) {
                $method = (string)($pay['method'] ?? '');
                if ($method === 'bank') {
                    throw new RuntimeException('Bank payments are online only. This settlement was rejected.');
                }
                if (!in_array($method, ['cash', 'mobile_money'], true)) {
                    throw new RuntimeException('Invalid payment method in the offline settlement.');
                }
                $amount = round((float)($pay['amount'] ?? 0), 2);
                if ($amount > 0) {
                    $payments[] = ['method' => $method, 'amount' => $amount, 'reference' => mb_substr((string)($pay['reference'] ?? ''), 0, 60)];
                }
            }
            $customerId = (int)($p['customer_id'] ?? 0);
            if ($customerId !== 0) {
                throw new RuntimeException('Settling a tab on customer credit is online only. This settlement was rejected.');
            }
            if (!count($payments)) {
                throw new RuntimeException('A settlement needs at least one payment (cash or Mobile Money while offline).');
            }
            $isManager = in_array($role, ['owner', 'manager'], true);
            $result = settle_table_session(
                (int)($p['session_id'] ?? 0),
                $payments,
                0,                                              // walk-in only while offline
                $isManager ? round((float)($p['discount'] ?? 0), 2) : 0.0,
                false,
                $userId, $isManager, $conn
            );
            return ['ref_type' => 'sale', 'ref_id' => (int)($result['sale_id'] ?? 0),
                    'message' => 'Table tab payment recorded (synced).'];

        case 'expense_create':
            $method = (string)($p['payment_method'] ?? '');
            if ($method === 'bank') {
                throw new RuntimeException('Bank expenses are online only. This expense was rejected.');
            }
            if (!in_array($method, ['cash', 'mobile_money'], true)) {
                throw new RuntimeException('Invalid payment method in the offline expense.');
            }
            $categoryId = (int)($p['category_id'] ?? 0);
            $cat = executeQuery('SELECT id FROM expense_categories WHERE id = ?', [$categoryId], $conn);
            if (!$cat['success'] || !count($cat['data'])) {
                throw new RuntimeException('The expense category no longer exists on the server.');
            }
            $date = (string)($p['expense_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new RuntimeException('The expense date is not valid.');
            }
            $amount = round((float)($p['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new RuntimeException('The expense amount must be greater than 0.');
            }
            $id = create_expense([
                'category_id'    => $categoryId,
                'description'    => mb_substr(trim((string)($p['description'] ?? '')), 0, 255),
                'amount'         => $amount,
                'payment_method' => $method,
                'expense_date'   => $date,
            ], $userId, $conn);
            return ['ref_type' => 'expense', 'ref_id' => $id, 'message' => 'Expense accepted (' . number_format($amount, 0) . ' RWF).'];
    }

    throw new RuntimeException('Unknown offline action type.');
}
