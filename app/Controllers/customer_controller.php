<?php
// ============================================================================
// Customers, credit limits and debt statements.
// ============================================================================

require_once __DIR__ . '/../models/customer_model.php';
require_once __DIR__ . '/../services/audit_service.php';
require_once __DIR__ . '/../validators/customer_validator.php';

function handleCustomerRequest($action, $data, $conn) {
    require_role(['manager', 'cashier']);
    switch ($action) {
        case 'index':
            return customer_index($conn);
        case 'create':
            return customer_create($conn);
        case 'edit':
            return customer_edit($conn);
        case 'store':
            return customer_store($conn);
        case 'update':
            return customer_update($conn);
        case 'toggle':
            require_role(['manager']);
            return customer_toggle($conn);
        case 'statement':
            return customer_statement($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function customer_index($conn): void
{
    $filters = [
        'q'      => get_text('q'),
        'status' => get_text('status'),
        'debt'   => !empty($_GET['debt']),
    ];
    render('customers/index', [
        'title'     => 'Customers & Debts',
        'active'    => 'customer',
        'customers' => search_customers($filters, current_page(), PER_PAGE, $conn)['data'],
        'total'     => search_customers($filters, current_page(), PER_PAGE, $conn)['total'],
        'page'      => current_page(),
        'filters'   => $filters,
        'isManager' => has_role(['manager']),
    ]);
}

function customer_create($conn): void
{
    customer_show_form('create', ['status' => 'active', 'credit_limit' => 0], [], $conn);
}

function customer_edit($conn): void
{
    $found = find_customer(get_int('id'), $conn);
    if (!$found['success'] || empty($found['data'])) {
        flash('danger', 'Customer not found.');
        redirect('customer');
    }
    customer_show_form('edit', $found['data'][0], [], $conn);
}

function customer_store($conn): void
{
    customer_save(null, $conn);
}

function customer_update($conn): void
{
    customer_save(post_int('id'), $conn);
}

function customer_toggle($conn): void
{
    $id     = post_int('id');
    $found  = find_customer($id, $conn);

    if (!$found['success'] || empty($found['data'])) {
        flash('danger', 'Customer not found.');
    } else {
        $customer  = $found['data'][0];
        $newStatus = $customer['status'] === 'active' ? 'inactive' : 'active';
        set_customer_status($id, $newStatus, $conn);
        audit_log($conn, current_user_id(), 'customer_' . $newStatus, 'customer', $id, $customer['full_name']);
        flash('success', '"' . $customer['full_name'] . '" is now ' . $newStatus . '.');
    }
    redirect('customer');
}

function customer_statement($conn): void
{
    $id       = get_int('id');
    $found    = find_customer($id, $conn);
    if (!$found['success'] || empty($found['data'])) {
        flash('danger', 'Customer not found.');
        redirect('customer');
    }
    $customer = $found['data'][0];
    $debts    = get_customer_debts($id, (int)($_GET['debts_page'] ?? 1), PER_PAGE, $conn);
    $payments = get_customer_payments($id, (int)($_GET['pay_page'] ?? 1), PER_PAGE, $conn);

    // Ledger: credit sales add to the balance, payments reduce it
    $ledger = [];
    foreach ($debts as $d) {
        if ($d['status'] === 'cancelled') {
            continue;
        }
        $ledger[] = ['date' => $d['created_at'], 'kind' => 0, 'id' => (int)$d['id'], 'type' => 'Credit sale',
                     'ref' => $d['receipt_no'], 'debit' => (float)$d['original_amount'], 'credit' => 0.0];
    }
    foreach ($payments as $p) {
        $ledger[] = ['date' => $p['paid_at'], 'kind' => 1, 'id' => (int)$p['id'], 'type' => 'Payment (' . $p['method'] . ')',
                     'ref' => trim(($p['reference'] ?: '') . ' ' . ($p['notes'] ?: '')), 'debit' => 0.0, 'credit' => (float)$p['amount']];
    }
    usort($ledger, 'compare_ledger_rows');

    // Running balance after each row
    $balance = 0.0;
    foreach ($ledger as $i => $row) {
        $balance += $row['debit'] - $row['credit'];
        $ledger[$i]['balance'] = $balance;
    }

    render('customers/statement', [
        'title'     => 'Statement: ' . $customer['full_name'],
        'active'    => 'customer',
        'customer'  => $customer,
        'debts'     => $debts,
        'ledger'    => $ledger,
        'purchases' => get_customer_sales($id, (int)($_GET['sales_page'] ?? 1), PER_PAGE, $conn)['data'],
        'debts_total'  => $debts['total'],
        'payments_total' => $payments['total'],
        'sales_total'  => get_customer_sales($id, (int)($_GET['sales_page'] ?? 1), PER_PAGE, $conn)['total'],
    ]);
}

// ---------- private helpers for this file ----------

// Sorts ledger rows by date, then credit sales before payments, then id.
function compare_ledger_rows(array $a, array $b): int
{
    return [$a['date'], $a['kind'], $a['id']] <=> [$b['date'], $b['kind'], $b['id']];
}

// Reads the form, validates it and creates ($id = null) or updates ($id = customer id).
function customer_save(?int $id, $conn): void
{
    $existing = null;
    if ($id !== null) {
        $found = find_customer($id, $conn);
        if (!$found['success'] || empty($found['data'])) {
            flash('danger', 'Customer not found.');
            redirect('customer');
        }
        $existing = $found['data'][0];
    }
    $isManager = has_role(['manager']);

    $d = [
        'full_name'    => post_text('full_name'),
        'phone'        => post_text('phone'),
        'address'      => post_text('address'),
        'credit_limit' => $existing ? $existing['credit_limit'] : 0,
        'status'       => $existing ? $existing['status'] : 'active',
    ];
    $errors = validate_customer($d, $isManager);

    // Only a manager or the owner can set the credit limit and status
    if ($isManager) {
        $limit = post_text('credit_limit', '0');
        if ($limit === '') {
            $limit = '0';
        }
        $limitError = validate_credit_limit($limit);
        if ($limitError) {
            $errors[] = $limitError;
        } else {
            $d['credit_limit'] = number_format((float)$limit, 2, '.', '');
        }
        $status = post_text('status', 'active');
        $d['status'] = in_array($status, ['active', 'inactive', 'blocked'], true) ? $status : 'active';
    }

    if (!$errors) {
        $d['phone']   = $d['phone'] !== '' ? $d['phone'] : null;
        $d['address'] = $d['address'] !== '' ? $d['address'] : null;
        if ($id === null) {
            $result = create_customer($d, $conn);
            audit_log($conn, current_user_id(), 'customer_create', 'customer', (int)$result['data']['id'], $d['full_name']);
            flash('success', 'Customer "' . $d['full_name'] . '" registered.');
        } else {
            update_customer($id, $d, $conn);
            if ($existing && (float)$existing['credit_limit'] != (float)$d['credit_limit']) {
                audit_log($conn, current_user_id(), 'credit_limit_change', 'customer', $id,
                    $d['full_name'] . ': ' . $existing['credit_limit'] . ' -> ' . $d['credit_limit']);
            }
            audit_log($conn, current_user_id(), 'customer_update', 'customer', $id, $d['full_name']);
            flash('success', 'Customer "' . $d['full_name'] . '" updated.');
        }
        redirect('customer');
    }

    // Errors: show the form again with what the user typed
    $customer = $d;
    if ($existing) {
        $customer['id']            = $existing['id'];
        $customer['customer_code'] = $existing['customer_code'];
        $customer['current_debt']  = $existing['current_debt'];
    }
    customer_show_form($id === null ? 'create' : 'edit', $customer, $errors, $conn);
}

function customer_show_form(string $mode, array $customer, array $errors, $conn): void
{
    render('customers/form', [
        'title'     => $mode === 'edit' ? 'Edit Customer' : 'New Customer',
        'active'    => 'customer',
        'mode'      => $mode,
        'customer'  => $customer,
        'errors'    => $errors,
        'isManager' => has_role(['manager']),
    ]);
}
