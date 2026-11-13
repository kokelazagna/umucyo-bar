<?php
// ============================================================================
// Products (with their extra selling units: crate, pack...).
// ============================================================================

require_once __DIR__ . '/../models/product_model.php';
require_once __DIR__ . '/../models/category_model.php';
require_once __DIR__ . '/../services/product_service.php';
require_once __DIR__ . '/../services/audit_service.php';
require_once __DIR__ . '/../validators/product_validator.php';

function handleProductRequest($action, $data, $conn) {
    require_role(['manager', 'stock_manager']);
    switch ($action) {
        case 'index':
            return product_index($conn);
        case 'create':
            return product_create($conn);
        case 'edit':
            return product_edit($conn);
        case 'store':
            return product_store($conn);
        case 'update':
            return product_update($conn);
        case 'toggle':
            return product_toggle($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function product_index($conn): void
{
    $filters = [
        'q'        => get_text('q'),
        'category' => get_int('category'),
        'status'   => get_text('status'),
        'low'      => !empty($_GET['low']),
    ];
    $page     = current_page();
    $paged    = search_products($filters, $page, PER_PAGE, $conn);
    $products = $paged['data'];
    $total    = $paged['total'];

    render('products/index', [
        'title'          => 'Products',
        'active'         => 'product',
        'products'       => $products,
        'total'          => $total,
        'page'           => $page,
        'unitsByProduct' => get_units_for_products(array_column($products, 'id'), $conn),
        'categories'     => get_active_categories($conn)['data'],
        'filters'        => $filters,
    ]);
}

function product_create($conn): void
{
    product_show_form('create', ['base_unit' => 'bottle', 'status' => 'active', 'min_stock' => 0], [], [], $conn);
}

function product_edit($conn): void
{
    $found = find_product(get_int('id'), $conn);
    if (!$found['success'] || empty($found['data'])) {
        flash('danger', 'Product not found.');
        redirect('product');
    }
    $product = $found['data'][0];
    $unitRows = [];
    foreach (get_extra_units((int)$product['id'], $conn)['data'] as $u) {
        $unitRows[] = ['id' => (int)$u['id'], 'name' => $u['unit_name'], 'factor' => $u['conversion_factor'], 'price' => $u['selling_price']];
    }
    product_show_form('edit', $product, $unitRows, [], $conn);
}

function product_store($conn): void
{
    product_save(null, $conn);
}

function product_update($conn): void
{
    product_save(post_int('id'), $conn);
}

function product_toggle($conn): void
{
    $id     = post_int('id');
    $found  = find_product($id, $conn);

    if (!$found['success'] || empty($found['data'])) {
        flash('danger', 'Product not found.');
    } else {
        $product   = $found['data'][0];
        $newStatus = $product['status'] === 'active' ? 'inactive' : 'active';
        set_product_status($id, $newStatus, $conn);
        audit_log($conn, current_user_id(), 'product_' . $newStatus, 'product', $id, $product['name']);
        flash('success', '"' . $product['name'] . '" is now ' . $newStatus . '.');
    }
    redirect('product');
}

// ---------- private helpers for this file ----------

// Reads the form, validates it and creates ($id = null) or updates ($id = product id).
function product_save(?int $id, $conn): void
{
    $existing = null;
    if ($id !== null) {
        $found = find_product($id, $conn);
        if (!$found['success'] || empty($found['data'])) {
            flash('danger', 'Product not found.');
            redirect('product');
        }
        $existing = $found['data'][0];
    }

    list($data, $unitRows, $errors) = product_collect_input($conn);

    if (!$errors) {
        try {
            if ($id === null) {
                $newId = create_product($data, $unitRows, $conn);
                audit_log($conn, current_user_id(), 'product_create', 'product', $newId, $data['name']);
                flash('success', 'Product "' . $data['name'] . '" created.');
            } else {
                update_product($id, $data, $unitRows, $conn);

                // Price changes are written to the audit log
                $changes = [];
                if ((float)$existing['selling_price'] != (float)$data['selling_price']) {
                    $changes[] = 'selling price ' . $existing['selling_price'] . ' -> ' . $data['selling_price'];
                }
                if ((float)$existing['purchase_price'] != (float)$data['purchase_price']) {
                    $changes[] = 'purchase price ' . $existing['purchase_price'] . ' -> ' . $data['purchase_price'];
                }
                if ($changes) {
                    audit_log($conn, current_user_id(), 'price_change', 'product', $id, $data['name'] . ': ' . implode('; ', $changes));
                }
                audit_log($conn, current_user_id(), 'product_update', 'product', $id, $data['name']);
                flash('success', 'Product "' . $data['name'] . '" updated.');
            }
            redirect('product');
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    // Validation or database error: show the form again with what the user typed
    $product = array_merge($existing ?? [], $data);
    if ($existing) {
        $product['id'] = $existing['id'];
    }
    product_show_form($id === null ? 'create' : 'edit', $product, $unitRows, $errors, $conn);
}

// Reads the form values and the extra unit rows of the product form.
function product_collect_input($conn): array
{
    $errors = [];
    $d = [
        'name'           => post_text('name'),
        'category_id'    => post_int('category_id'),
        'brand'          => post_text('brand'),
        'barcode'        => post_text('barcode'),
        'base_unit'      => post_text('base_unit', 'bottle'),
        'purchase_price' => post_text('purchase_price'),
        'selling_price'  => post_text('selling_price'),
        'min_stock'      => post_text('min_stock', '0'),
        'expiry_date'    => post_text('expiry_date'),
        'status'         => post_text('status', 'active'),
    ];

    if ((int)$d['category_id'] > 0) {
        $cat = find_category((int)$d['category_id'], $conn);
        if (!$cat['success'] || empty($cat['data'])) {
            $errors[] = 'Choose a category.';
            $d['category_id'] = 0;
        }
    }

    // Extra selling units (crate, pack...): the form sends several lists, one value per row
    $ids       = $_POST['unit_id']     ?? [];
    $names     = $_POST['unit_name']   ?? [];
    $factors   = $_POST['unit_factor'] ?? [];
    $prices    = $_POST['unit_price']  ?? [];
    $removeIds = array_map('intval', (array)($_POST['unit_remove'] ?? []));
    $rows = [];

    foreach ((array)$names as $i => $rawName) {
        $unitId = (int)($ids[$i] ?? 0);
        $name   = strtolower(trim((string)$rawName));
        $factor = trim((string)($factors[$i] ?? ''));
        $price  = trim((string)($prices[$i] ?? ''));

        if ($unitId === 0 && $factor === '' && $price === '') {
            continue; // empty new row
        }
        $remove = ($unitId > 0 && in_array($unitId, $removeIds, true));

        if (!$remove && $price === '') {
            // no price typed: use base price x units per pack
            $price = (is_numeric($d['selling_price']) && ctype_digit($factor))
                ? (string)((float)$d['selling_price'] * (int)$factor) : '0';
        }

        $rows[] = ['id' => $unitId, 'name' => $name, 'factor' => $factor, 'price' => $price, 'remove' => $remove];
    }

    // The validation rules live in the validator
    $errors = array_merge($errors, validate_product($d, $rows));

    // Everything is valid: convert the text values to the types saved in the database
    if (!$errors) {
        $d['purchase_price'] = number_format((float)$d['purchase_price'], 2, '.', '');
        $d['selling_price']  = number_format((float)$d['selling_price'], 2, '.', '');
        $d['min_stock']      = (int)$d['min_stock'];
        $d['brand']          = $d['brand'] !== '' ? $d['brand'] : null;
        $d['barcode']        = $d['barcode'] !== '' ? $d['barcode'] : null;
        $d['expiry_date']    = $d['expiry_date'] !== '' ? $d['expiry_date'] : null;
        foreach ($rows as $i => $row) {
            if (!$row['remove']) {
                $rows[$i]['factor'] = (int)$row['factor'];
                $rows[$i]['price']  = number_format((float)$row['price'], 2, '.', '');
            }
        }
    }

    return [$d, $rows, $errors];
}

function product_show_form(string $mode, array $product, array $unitRows, array $errors, $conn): void
{
    render('products/form', [
        'title'      => $mode === 'edit' ? 'Edit Product' : 'New Product',
        'active'     => 'product',
        'mode'       => $mode,
        'product'    => $product,
        'unitRows'   => $unitRows,
        'errors'     => $errors,
        'categories' => get_active_categories($conn)['data'],
    ]);
}
