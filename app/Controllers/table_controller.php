<?php
// ============================================================================
// Floor & tables: the floor view, the live table tab, opening a table, draft
// rounds, confirming served drinks, voids, settlement, transfer and merge.
//
// The rules live in table_service. These functions only validate the
// form, call the service and show a message.
// ============================================================================

require_once __DIR__ . '/../models/table_model.php';
require_once __DIR__ . '/../models/product_model.php';
require_once __DIR__ . '/../models/customer_model.php';
require_once __DIR__ . '/../services/table_service.php';
require_once __DIR__ . '/../services/sale_service.php';
require_once __DIR__ . '/../services/customer_service.php';

// ---------- One-time form tokens (protect against a double submit) ---------

function table_action_token(string $action): string
{
    if (empty($_SESSION['table_tokens'][$action])) {
        $_SESSION['table_tokens'][$action] = bin2hex(random_bytes(16));
    }
    return $_SESSION['table_tokens'][$action];
}

function table_token_ok(string $action): bool
{
    $sent  = post_text('token');
    $saved = $_SESSION['table_tokens'][$action] ?? '';
    unset($_SESSION['table_tokens'][$action]);
    return $saved !== '' && hash_equals($saved, $sent);
}

function table_token_fail(int $sessionId = 0): void
{
    flash('warning', 'That form was already submitted. Reload the page and check the latest state.');
    redirect($sessionId > 0 ? 'table/session?id=' . $sessionId : 'table');
}

function handleTableRequest($action, $data, $conn) {
    require_role(['manager', 'cashier']);
    switch ($action) {
        case 'index':
            return table_index($conn);
        case 'session':
            return table_session($conn);
        case 'open':
            return table_open($conn);
        case 'addItem':
            return table_add($conn);
        case 'updateItem':
            return table_update_item($conn);
        case 'removeItem':
            return table_remove_item($conn);
        case 'clearDraft':
            return table_clear_draft($conn);
        case 'serve':
            return table_serve($conn);
        case 'void':
            require_role(['manager']);
            return table_void($conn);
        case 'settle':
            return table_settle($conn);
        case 'transfer':
            require_role(['manager']);
            return table_transfer($conn);
        case 'merge':
            require_role(['manager']);
            return table_merge($conn);
        case 'close':
            return table_close($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function table_index($conn): void
{
    render('tables/index', [
        'title'     => 'Floor & Tables',
        'active'    => 'table',
        'areas'     => get_all_areas(true, $conn),
        'tables'    => get_floor_tables($conn),
        'tabs'      => get_open_tabs($conn),
        'staff'     => get_active_staff($conn),
        'token'     => table_action_token('table_open'),
        'canManage' => has_role(['manager']),
    ]);
}

function table_session($conn): void
{
    $id  = get_int('id');
    $tab = get_session_view($id, $conn);
    if (!$tab) {
        flash('danger', 'Table tab not found.');
        redirect('table');
    }

    // Every sellable product/unit pair, for the draft-round grid (same shape as the POS).
    $products = get_active_products($conn)['data'];
    $units    = get_units_for_products(array_column($products, 'id'), $conn);
    $draftProducts = [];
    foreach ($products as $p) {
        $pu = [];
        foreach ($units[$p['id']] ?? [] as $u) {
            $pu[] = [
                'id'     => (int)$u['id'],
                'name'   => $u['unit_name'],
                'factor' => (int)$u['conversion_factor'],
                'price'  => (float)$u['selling_price'],
            ];
        }
        if (!$pu) { continue; }   // a product without units cannot be sold
        $draftProducts[] = [
            'id'       => (int)$p['id'],
            'name'     => $p['name'],
            'category' => $p['category_name'],
            'base_unit'=> $p['base_unit'],
            'stock'    => (int)$p['quantity_in_stock'],
            'units'    => $pu,
        ];
    }

    $available = [];
    foreach (get_floor_tables($conn) as $t) {
        if ($t['status'] === 'available' && (int)$t['active'] === 1) {
            $available[] = $t;
        }
    }
    $otherTabs = [];
    foreach (get_open_tabs($conn) as $t) {
        if ((int)$t['session_id'] !== $id) {
            $otherTabs[] = $t;
        }
    }

    render('tables/session', [
        'title'      => 'Table ' . $tab['table_code'],
        'active'     => 'table',
        'tab'        => $tab,
        'draftProducts' => $draftProducts,
        'customers'  => get_active_customers($conn)['data'],
        'available'  => $available,
        'otherTabs'  => $otherTabs,
        'payments'   => $tab['sale'] ? get_sale_payments((int)$tab['sale']['id'], $conn)['data'] : [],
        'staff'      => get_active_staff($conn),
        'canManage'  => has_role(['manager']),
        'isManager'  => has_role(['manager']),
        'tokens'     => [
            'add'      => table_action_token('table_add'),
            'update'   => table_action_token('table_update'),
            'serve'    => table_action_token('table_serve'),
            'void'     => table_action_token('table_void'),
            'settle'   => table_action_token('table_settle'),
            'transfer' => table_action_token('table_transfer'),
            'merge'    => table_action_token('table_merge'),
            'close'    => table_action_token('table_close'),
        ],
    ]);
}

function table_open($conn): void
{
    if (!table_token_ok('table_open')) {
        table_token_fail();
    }
    $tableId = post_int('table_id');
    try {
        $sessionId = open_table_session($tableId, post_int('waiter_id'), (int)current_user_id(), $conn);
        flash('success', 'Table opened. Add the first round; stock moves only when the round is confirmed served.');
        redirect('table/session?id=' . $sessionId);
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('table');
}

function table_add($conn): void
{
    $sessionId = post_int('session_id');
    if (!table_token_ok('table_add')) {
        table_token_fail($sessionId);
    }

    $pair = explode(':', post_text('product_unit'));
    $productId = (int)($pair[0] ?? 0);
    $unitId    = (int)($pair[1] ?? 0);

    try {
        add_draft_item($sessionId, $productId, $unitId, post_int('quantity'), (int)current_user_id(), $conn);
        flash('success', 'Added to the draft round. Stock is not deducted until the round is confirmed served.');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('table/session?id=' . $sessionId);
}

function table_update_item($conn): void
{
    $sessionId = post_int('session_id');
    if (!table_token_ok('table_update')) {
        table_token_fail($sessionId);
    }
    try {
        update_draft_item($sessionId, post_int('item_id'), post_int('quantity'), (int)current_user_id(), $conn);
        flash('success', 'Draft quantity updated.');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('table/session?id=' . $sessionId);
}

function table_remove_item($conn): void
{
    $sessionId = post_int('session_id');
    if (!table_token_ok('table_update')) {
        table_token_fail($sessionId);
    }
    try {
        update_draft_item($sessionId, post_int('item_id'), 0, (int)current_user_id(), $conn);
        flash('success', 'Draft item removed.');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('table/session?id=' . $sessionId);
}

function table_clear_draft($conn): void
{
    $sessionId = post_int('session_id');
    if (!table_token_ok('table_update')) {
        table_token_fail($sessionId);
    }
    try {
        clear_draft_items($sessionId, (int)current_user_id(), $conn);
        flash('success', 'Draft round cleared. Nothing was served and stock did not move.');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('table/session?id=' . $sessionId);
}

function table_serve($conn): void
{
    $sessionId = post_int('session_id');
    if (!table_token_ok('table_serve')) {
        table_token_fail($sessionId);
    }
    try {
        $result = serve_draft_round($sessionId, (int)current_user_id(), $conn);
        flash('success', 'Round served: ' . (int)$result['served'] . ' line(s) deducted once from stock and added to receipt '
            . $result['receipt'] . '.');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('table/session?id=' . $sessionId);
}

function table_void($conn): void
{
    $sessionId = post_int('session_id');
    if (!table_token_ok('table_void')) {
        table_token_fail($sessionId);
    }
    try {
        void_served_item($sessionId, post_int('sale_item_id'), post_text('reason'), (int)current_user_id(), $conn);
        flash('success', 'Served line voided. Stock was returned and the movement was recorded.');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('table/session?id=' . $sessionId);
}

function table_settle($conn): void
{
    $sessionId = post_int('session_id');
    if (!table_token_ok('table_settle')) {
        table_token_fail($sessionId);
    }

    $payments = [];
    $cash = post_text('cash_amount', '0');
    $momo = post_text('momo_amount', '0');
    $bank = post_text('bank_amount', '0');
    if ($cash !== '' && is_numeric($cash) && (float)$cash > 0) {
        $payments[] = ['method' => 'cash', 'amount' => (float)$cash, 'reference' => ''];
    }
    if ($momo !== '' && is_numeric($momo) && (float)$momo > 0) {
        $payments[] = ['method' => 'mobile_money', 'amount' => (float)$momo, 'reference' => post_text('momo_ref')];
    }
    if ($bank !== '' && is_numeric($bank) && (float)$bank > 0) {
        $payments[] = ['method' => 'bank', 'amount' => (float)$bank, 'reference' => post_text('bank_ref')];
    }

    $discount = post_text('discount', '0');
    if (!is_numeric($discount)) {
        $discount = '0';
    }

    $isManager = has_role(['manager']);
    try {
        // The "Other / New customer..." option in the dropdown: the typed name
        // is created on the spot (or the existing customer with that name is reused).
        $customerId = post_text('customer_id') === '__other__'
            ? resolve_or_create_customer(post_text('customer_other'), $conn)
            : post_int('customer_id');
        $result = settle_table_session(
            $sessionId,
            $payments,
            $customerId,
            $isManager ? (float)$discount : 0.0,
            $isManager && !empty($_POST['override_limit']),
            (int)current_user_id(),
            $isManager,
            $conn
        );

        if ($result['closed']) {
            flash('success', 'Tab settled. Receipt ' . $result['receipt'] . ' completed and the table is free.');
            redirect('sale/receipt?id=' . $result['sale_id'] . '&new=1');
        }
        flash('warning', 'Payment recorded. Outstanding: ' . number_format((float)$result['outstanding'], 0)
            . ' RWF. The table stays occupied until the bill is fully paid.');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('table/session?id=' . $sessionId);
}

function table_transfer($conn): void
{
    $sessionId = post_int('session_id');
    if (!table_token_ok('table_transfer')) {
        table_token_fail($sessionId);
    }
    try {
        transfer_table_session($sessionId, post_int('target_table_id'), post_text('reason'), (int)current_user_id(), $conn);
        flash('success', 'Tab transferred. The orders, bill and payments moved with it.');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('table/session?id=' . $sessionId);
}

function table_merge($conn): void
{
    $targetId = post_int('session_id');
    if (!table_token_ok('table_merge')) {
        table_token_fail($targetId);
    }
    try {
        $result = merge_table_sessions(post_int('source_session_id'), $targetId, post_text('reason'), (int)current_user_id(), $conn);
        flash('success', 'Tabs merged into this bill. The other table is free and history was kept.');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('table/session?id=' . $targetId);
}

function table_close($conn): void
{
    $sessionId = post_int('session_id');
    if (!table_token_ok('table_close')) {
        table_token_fail($sessionId);
    }
    try {
        close_empty_table_session($sessionId, (int)current_user_id(), $conn);
        flash('success', 'Empty tab closed and the table is available again.');
        redirect('table');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('table/session?id=' . $sessionId);
}
