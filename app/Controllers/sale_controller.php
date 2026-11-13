<?php
// ============================================================================
// Sales history, receipts and sale cancellation.
// ============================================================================

require_once __DIR__ . '/../models/sale_model.php';
require_once __DIR__ . '/../services/sale_service.php';
require_once __DIR__ . '/../validators/supplier_validator.php';

function handleSaleRequest($action, $data, $conn) {
    require_role(['manager', 'cashier']);
    switch ($action) {
        case 'index':
            return sale_index($conn);
        case 'receipt':
            return sale_receipt($conn);
        case 'cancel':
            require_role(['manager']);
            return sale_cancel($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function sale_index($conn): void
{
    $filters = [
        'q'      => get_text('q'),
        'status' => get_text('status'),
        'from'   => get_text('from'),
        'to'     => get_text('to'),
    ];
    $page  = current_page();
    $paged = search_sales($filters, $page, PER_PAGE, $conn);
    render('sales/index', [
        'title'   => 'Sales History',
        'active'  => 'sale',
        'sales'   => $paged['data'],
        'total'   => $paged['total'],
        'page'    => $page,
        'filters' => $filters,
    ]);
}

function sale_receipt($conn): void
{
    $id       = get_int('id');
    $found    = find_sale($id, $conn);
    if (!$found['success'] || empty($found['data'])) {
        flash('danger', 'Sale not found.');
        redirect('sale');
    }
    $sale = $found['data'][0];
    render('sales/receipt', [
        'title'     => 'Receipt ' . $sale['receipt_no'],
        'active'    => 'sale',
        'sale'      => $sale,
        'items'     => get_sale_items($id, $conn)['data'],
        'payments'  => get_sale_payments($id, $conn)['data'],
        'canCancel' => has_role(['manager']) && $sale['status'] === 'completed',
        'isNew'     => !empty($_GET['new']),
    ]);
}

function sale_cancel($conn): void
{
    $id     = post_int('id');
    $reason = post_text('reason');
    $error  = validate_cancel_reason($reason);
    if ($error) {
        flash('danger', $error);
        redirect('sale/receipt?id=' . $id);
    }
    try {
        cancel_sale($id, $reason, (int)current_user_id(), $conn);
        flash('success', 'Sale cancelled. Stock was restored and the movements were recorded.');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('sale/receipt?id=' . $id);
}
