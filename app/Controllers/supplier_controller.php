<?php
// ============================================================================
// Suppliers.
// ============================================================================

require_once __DIR__ . '/../models/supplier_model.php';
require_once __DIR__ . '/../models/purchase_model.php';
require_once __DIR__ . '/../services/audit_service.php';
require_once __DIR__ . '/../validators/supplier_validator.php';

function handleSupplierRequest($action, $data, $conn) {
    require_role(['manager', 'stock_manager']);
    switch ($action) {
        case 'index':
            return supplier_index($conn);
        case 'create':
            return supplier_create($conn);
        case 'edit':
            return supplier_edit($conn);
        case 'store':
            return supplier_store($conn);
        case 'update':
            return supplier_update($conn);
        case 'toggle':
            return supplier_toggle($conn);
        case 'history':
            return supplier_history($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function supplier_index($conn): void
{
    render('suppliers/index', [
        'title'     => 'Suppliers',
        'active'    => 'supplier',
        'suppliers' => get_suppliers_page(current_page(), PER_PAGE, $conn)['data'],
        'total'     => get_suppliers_page(current_page(), PER_PAGE, $conn)['total'],
        'page'      => current_page(),
    ]);
}

function supplier_create($conn): void
{
    supplier_show_form('create', ['status' => 'active'], [], $conn);
}

function supplier_edit($conn): void
{
    $found = find_supplier(get_int('id'), $conn);
    if (!$found['success'] || empty($found['data'])) {
        flash('danger', 'Supplier not found.');
        redirect('supplier');
    }
    supplier_show_form('edit', $found['data'][0], [], $conn);
}

function supplier_store($conn): void
{
    supplier_save(null, $conn);
}

function supplier_update($conn): void
{
    supplier_save(post_int('id'), $conn);
}

function supplier_toggle($conn): void
{
    $id     = post_int('id');
    $found  = find_supplier($id, $conn);

    if (!$found['success'] || empty($found['data'])) {
        flash('danger', 'Supplier not found.');
    } else {
        $supplier  = $found['data'][0];
        $newStatus = $supplier['status'] === 'active' ? 'inactive' : 'active';
        set_supplier_status($id, $newStatus, $conn);
        audit_log($conn, current_user_id(), 'supplier_' . $newStatus, 'supplier', $id, $supplier['name']);
        flash('success', '"' . $supplier['name'] . '" is now ' . $newStatus . '.');
    }
    redirect('supplier');
}

function supplier_history($conn): void
{
    $id       = get_int('id');
    $found    = find_supplier($id, $conn);
    if (!$found['success'] || empty($found['data'])) {
        flash('danger', 'Supplier not found.');
        redirect('supplier');
    }
    $supplier = $found['data'][0];
    $purchases = search_purchases(['q' => '', 'supplier' => $id, 'status' => '', 'from' => '', 'to' => ''], current_page(), PER_PAGE, $conn);
    $histTotal = $purchases['total'];

    render('suppliers/history', [
        'title'     => 'Supplier history: ' . $supplier['name'],
        'active'    => 'supplier',
        'supplier'  => $supplier,
        'summary'   => get_supplier_summary($id, $conn)['data'][0] ?? [],
        'purchases' => $purchases['data'],
    ]);
}

// ---------- private helpers for this file ----------

// Reads the form, validates it and creates ($id = null) or updates ($id = supplier id).
function supplier_save(?int $id, $conn): void
{
    if ($id !== null) {
        $found = find_supplier($id, $conn);
        if (!$found['success'] || empty($found['data'])) {
            flash('danger', 'Supplier not found.');
            redirect('supplier');
        }
    }

    $d = [
        'name'          => post_text('name'),
        'business_name' => post_text('business_name'),
        'phone'         => post_text('phone'),
        'email'         => post_text('email'),
        'address'       => post_text('address'),
        'status'        => post_text('status', 'active'),
    ];
    $errors = validate_supplier($d);

    if (!$errors) {
        foreach (['business_name', 'phone', 'email', 'address'] as $field) {
            $d[$field] = $d[$field] !== '' ? $d[$field] : null;   // empty text is saved as NULL
        }
        if ($id === null) {
            $result = create_supplier($d, $conn);
            audit_log($conn, current_user_id(), 'supplier_create', 'supplier', (int)$result['data']['id'], $d['name']);
            flash('success', 'Supplier "' . $d['name'] . '" added.');
        } else {
            update_supplier($id, $d, $conn);
            audit_log($conn, current_user_id(), 'supplier_update', 'supplier', $id, $d['name']);
            flash('success', 'Supplier "' . $d['name'] . '" updated.');
        }
        redirect('supplier');
    }

    // Errors: show the form again with what the user typed
    $supplier = $d;
    if ($id !== null) {
        $supplier['id'] = $id;
    }
    supplier_show_form($id === null ? 'create' : 'edit', $supplier, $errors, $conn);
}

function supplier_show_form(string $mode, array $supplier, array $errors, $conn): void
{
    render('suppliers/form', [
        'title'    => $mode === 'edit' ? 'Edit Supplier' : 'New Supplier',
        'active'   => 'supplier',
        'mode'     => $mode,
        'supplier' => $supplier,
        'errors'   => $errors,
    ]);
}
