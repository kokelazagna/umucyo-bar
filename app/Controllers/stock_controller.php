<?php
// ============================================================================
// Stock control: movement history, adjustment records and new adjustments.
// ============================================================================

require_once __DIR__ . '/../models/stock_model.php';
require_once __DIR__ . '/../models/product_model.php';
require_once __DIR__ . '/../services/stock_service.php';
require_once __DIR__ . '/../validators/stock_validator.php';

function handleStockRequest($action, $data, $conn) {
    require_role(['manager', 'stock_manager']);
    switch ($action) {
        case 'index':
            return stock_index($conn);
        case 'adjustments':
            return stock_adjustments($conn);
        case 'adjust':
            return stock_adjust($conn);
        case 'store':
            return stock_store($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function stock_index($conn): void
{
    $filters = [
        'product' => get_int('product'),
        'type'    => get_text('type'),
        'from'    => get_text('from'),
        'to'      => get_text('to'),
    ];
    render('stock/index', [
        'title'     => 'Stock Movements',
        'active'    => 'stock',
        'movements' => search_stock_movements($filters, current_page(), PER_PAGE, $conn)['data'],
        'movTotal'  => search_stock_movements($filters, current_page(), PER_PAGE, $conn)['total'],
        'products'  => get_product_names($conn)['data'],
        'types'     => stock_movement_types(),
        'filters'   => $filters,
        'canSales'  => has_role(['manager', 'cashier']),
    ]);
}

function stock_adjustments($conn): void
{
    $filters = [
        'type' => get_text('type'),
        'from' => get_text('from'),
        'to'   => get_text('to'),
    ];
    render('stock/adjustments', [
        'title'       => 'Stock Adjustments',
        'active'      => 'stock',
        'adjustments' => search_stock_adjustments($filters, current_page(), PER_PAGE, $conn)['data'],
        'adjTotal'    => search_stock_adjustments($filters, current_page(), PER_PAGE, $conn)['total'],
        'types'       => stock_adjustment_types(),
        'filters'     => $filters,
    ]);
}

function stock_adjust($conn): void
{
    $type = get_text('type');
    stock_show_form([
        'product_id' => get_int('product_id'),
        'type'       => in_array($type, stock_adjustment_types(), true) ? $type : 'damaged',
        'direction'  => 'decrease',
        'quantity'   => '',
        'reason'     => '',
        'unit_id'    => 0,
    ], [], $conn);
}

function stock_store($conn): void
{
    $d = [
        'product_id' => post_int('product_id'),
        'unit_id'    => post_int('unit_id'),
        'quantity'   => post_text('quantity'),
        'type'       => post_text('type'),
        'direction'  => post_text('direction', 'decrease'),
        'reason'     => post_text('reason'),
    ];
    $errors = validate_stock_adjustment($d);

    if (!$errors) {
        try {
            apply_stock_adjustment($d, (int)current_user_id(), $conn);
            flash('success', 'Stock adjustment recorded.');
            redirect('stock');
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
    stock_show_form($d, $errors, $conn);
}

// ---------- private helper for this file ----------

// Shows the adjustment form. The product list is also sent to the page's JavaScript.
function stock_show_form(array $d, array $errors, $conn): void
{
    $all            = get_all_products($conn)['data'];
    $unitsByProduct = get_units_for_products(array_column($all, 'id'), $conn);

    $products = [];
    foreach ($all as $p) {
        $units = [];
        foreach ($unitsByProduct[$p['id']] ?? [] as $u) {
            $units[] = ['id' => (int)$u['id'], 'name' => $u['unit_name'], 'factor' => (int)$u['conversion_factor']];
        }
        $products[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'base_unit' => $p['base_unit'],
                       'stock' => (int)$p['quantity_in_stock'], 'units' => $units];
    }

    render('stock/adjust', [
        'title'   => 'New Stock Adjustment',
        'active'  => 'stock',
        'd'       => $d,
        'errors'  => $errors,
        'expired' => get_expired_products_in_stock($conn)['data'],
        'payload' => ['products' => $products, 'selectedUnit' => (int)$d['unit_id']],
    ]);
}
