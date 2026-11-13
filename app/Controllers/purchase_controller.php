<?php
// ============================================================================
// Purchases from suppliers.
// Flow: save (PENDING) -> confirm when goods arrive (stock increases) -> pay the supplier.
// ============================================================================

require_once __DIR__ . '/../models/purchase_model.php';
require_once __DIR__ . '/../models/supplier_model.php';
require_once __DIR__ . '/../models/product_model.php';
require_once __DIR__ . '/../services/purchase_service.php';
require_once __DIR__ . '/../services/supplier_service.php';
require_once __DIR__ . '/../services/audit_service.php';
require_once __DIR__ . '/../validators/purchase_validator.php';

function handlePurchaseRequest($action, $data, $conn) {
    require_role(['manager', 'stock_manager']);
    switch ($action) {
        case 'index':
            return purchase_index($conn);
        case 'create':
            return purchase_create($conn);
        case 'store':
            return purchase_store($conn);
        case 'show':
            return purchase_show($conn);
        case 'confirm':
            return purchase_confirm($conn);
        case 'cancel':
            return purchase_cancel($conn);
        case 'pay':
            return purchase_pay($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function purchase_index($conn): void
{
    $filters = [
        'q'        => get_text('q'),
        'supplier' => get_int('supplier'),
        'status'   => get_text('status'),
        'from'     => get_text('from'),
        'to'       => get_text('to'),
    ];
    render('purchases/index', [
        'title'     => 'Purchases',
        'active'    => 'purchase',
        'purchases' => search_purchases($filters, current_page(), PER_PAGE, $conn)['data'],
        'total'     => search_purchases($filters, current_page(), PER_PAGE, $conn)['total'],
        'page'      => current_page(),
        'suppliers' => get_supplier_list($conn)['data'],
        'filters'   => $filters,
    ]);
}

function purchase_create($conn): void
{
    purchase_show_form(
        ['supplier_id' => 0, 'purchase_date' => date('Y-m-d'), 'notes' => '', 'amount_paid' => '0', 'payment_method' => 'cash'],
        [['product_id' => 0, 'unit_id' => 0, 'quantity' => '', 'unit_cost' => '']],
        [],
        $conn
    );
}

function purchase_store($conn): void
{
    list($header, $rows, $errors) = purchase_collect_input($conn);

    if (!$errors) {
        try {
            $result = create_purchase($header, $rows, (int)current_user_id(), $conn);
            audit_log($conn, current_user_id(), 'purchase_create', 'purchase', $result['id'], $result['reference'] . ' total ' . $header['total']);
            flash('success', 'Purchase ' . $result['reference'] . ' saved as PENDING. Confirm it when the goods are received to add the stock.');
            redirect('purchase/show?id=' . $result['id']);
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
    purchase_show_form($header, $rows, $errors, $conn);
}

function purchase_show($conn): void
{
    $id       = get_int('id');
    $found    = find_purchase($id, $conn);
    if (!$found['success'] || empty($found['data'])) {
        flash('danger', 'Purchase not found.');
        redirect('purchase');
    }
    render('purchases/show', [
        'title'    => 'Purchase ' . $found['data'][0]['reference_no'],
        'active'   => 'purchase',
        'purchase' => $found['data'][0],
        'items'    => get_purchase_items($id, $conn)['data'],
    ]);
}

function purchase_confirm($conn): void
{
    $id = post_int('id');
    try {
        confirm_purchase($id, (int)current_user_id(), $conn);
        audit_log($conn, current_user_id(), 'purchase_confirm', 'purchase', $id);
        flash('success', 'Purchase confirmed. Stock has been increased.');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('purchase/show?id=' . $id);
}

function purchase_cancel($conn): void
{
    $id     = post_int('id');
    $reason = post_text('reason');
    $error  = validate_cancel_reason($reason);
    if ($error) {
        flash('danger', $error);
        redirect('purchase/show?id=' . $id);
    }
    try {
        cancel_purchase($id, $reason, (int)current_user_id(), $conn);
        audit_log($conn, current_user_id(), 'purchase_cancel', 'purchase', $id, $reason);
        flash('success', 'Purchase cancelled. Any stock it added has been reversed.');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('purchase/show?id=' . $id);
}

function purchase_pay($conn): void
{
    $id     = post_int('id');
    $amount = post_text('amount');
    $method = post_text('payment_method');

    $error = validate_payment_amount($amount);
    if ($error) {
        flash('danger', $error);
    } elseif (!in_array($method, payment_methods(), true)) {
        flash('danger', 'Choose a payment method.');
    } else {
        try {
            pay_purchase($id, (float)$amount, $method, (int)current_user_id(), $conn);
            flash('success', 'Payment of ' . rwf($amount) . ' recorded.');
        } catch (RuntimeException $ex) {
            flash('danger', $ex->getMessage());
        }
    }
    redirect('purchase/show?id=' . $id);
}

// ---------- private helpers for this file ----------

// The "Other / New supplier..." option in the dropdown: the typed name is
// created on the spot (or the existing supplier with that name is reused).
function purchase_resolve_supplier_id($conn): array
{
    if (post_text('supplier_id') !== '__other__') {
        return [post_int('supplier_id'), null];
    }
    try {
        return [resolve_or_create_supplier(post_text('supplier_other'), $conn), null];
    } catch (RuntimeException $ex) {
        return [0, $ex->getMessage()];
    }
}

// Reads and checks the purchase form. Returns [header, item rows, errors].
function purchase_collect_input($conn): array
{
    $errors = [];
    [$supplierId, $supplierError] = purchase_resolve_supplier_id($conn);
    $h = [
        'supplier_id'    => $supplierId,
        'purchase_date'  => post_text('purchase_date'),
        'notes'          => post_text('notes'),
        'amount_paid'    => post_text('amount_paid', '0'),
        'payment_method' => post_text('payment_method', 'cash'),
        'total'          => 0,
    ];

    if ($supplierError !== null) {
        $errors[] = $supplierError;
    } else {
        $supplier = find_supplier($h['supplier_id'], $conn);
        if (!$supplier['success'] || empty($supplier['data']) || $supplier['data'][0]['status'] !== 'active') {
            $errors[] = 'Choose an active supplier.';
        }
    }
    $errors = array_merge($errors, validate_purchase_header($h));

    // Valid products and their units, loaded from the database: product id => product
    $products = [];
    foreach (get_active_products($conn)['data'] as $p) {
        $products[(int)$p['id']] = $p;
    }
    $unitsByProduct = get_units_for_products(array_keys($products), $conn);

    // The form sends several lists, one value per line of the purchase
    $productIds = (array)($_POST['product_id'] ?? []);
    $unitIds    = (array)($_POST['unit_id'] ?? []);
    $quantities = (array)($_POST['quantity'] ?? []);
    $costs      = (array)($_POST['unit_cost'] ?? []);
    $rows  = [];
    $total = 0.0;

    foreach ($productIds as $i => $rawProductId) {
        $productId = (int)$rawProductId;
        $unitId    = (int)($unitIds[$i] ?? 0);
        $qty       = trim((string)($quantities[$i] ?? ''));
        $cost      = trim((string)($costs[$i] ?? ''));

        $row = ['product_id' => $productId, 'unit_id' => $unitId, 'quantity' => $qty, 'unit_cost' => $cost];
        if ($productId === 0 && $qty === '' && $cost === '') {
            continue; // empty row
        }
        $lineNo = count($rows) + 1;

        $unit = null;
        if (!isset($products[$productId])) {
            $errors[] = "Line $lineNo: choose an active product.";
        } else {
            foreach ($unitsByProduct[$productId] ?? [] as $u) {
                if ((int)$u['id'] === $unitId) {
                    $unit = $u;
                }
            }
            if (!$unit) {
                $errors[] = "Line $lineNo: choose a valid unit for this product.";
            }
        }
        $errors = array_merge($errors, validate_purchase_line($lineNo, $qty, $cost));

        if ($unit && ctype_digit($qty) && is_numeric($cost)) {
            $factor = (int)$unit['conversion_factor'];
            $row['base_quantity'] = (int)$qty * $factor;                 // quantity in bottles / cans
            $row['cost_per_base'] = round((float)$cost / $factor, 4);    // cost of ONE bottle / can
            $row['total']         = round((int)$qty * (float)$cost, 2);
            $row['unit_cost']     = number_format((float)$cost, 2, '.', '');
            $total += $row['total'];
        }
        $rows[] = $row;
    }

    if (!$rows) {
        $errors[] = 'Add at least one product to the purchase.';
        $rows[] = ['product_id' => 0, 'unit_id' => 0, 'quantity' => '', 'unit_cost' => ''];
    }

    $h['total'] = number_format($total, 2, '.', '');
    $errors = array_merge($errors, validate_purchase_amount_paid($h, $total));
    $h['notes'] = $h['notes'] !== '' ? $h['notes'] : null;

    return [$h, $rows, $errors];
}

// Shows the purchase form. The product list is also sent to the page's JavaScript.
function purchase_show_form(array $header, array $rows, array $errors, $conn): void
{
    $active         = get_active_products($conn)['data'];
    $unitsByProduct = get_units_for_products(array_column($active, 'id'), $conn);

    $jsProducts = [];
    foreach ($active as $p) {
        $units = [];
        foreach ($unitsByProduct[$p['id']] ?? [] as $u) {
            $units[] = [
                'id'     => (int)$u['id'],
                'name'   => $u['unit_name'],
                'factor' => (int)$u['conversion_factor'],
                'cost'   => round((float)$p['purchase_price'] * (int)$u['conversion_factor'], 2),
            ];
        }
        $jsProducts[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'base_unit' => $p['base_unit'], 'units' => $units];
    }

    render('purchases/create', [
        'title'     => 'New Purchase',
        'active'    => 'purchase',
        'h'         => $header,
        'rows'      => $rows,
        'errors'    => $errors,
        'suppliers' => get_active_suppliers($conn)['data'],
        'products'  => $jsProducts,
    ]);
}
