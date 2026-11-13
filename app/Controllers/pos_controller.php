<?php
// ============================================================================
// Point of Sale: the selling screen and the checkout.
// (The checkout keeps answering the POS JavaScript with JSON, as before.)
// ============================================================================

require_once __DIR__ . '/../models/product_model.php';
require_once __DIR__ . '/../models/customer_model.php';
require_once __DIR__ . '/../services/sale_service.php';
require_once __DIR__ . '/../services/cash_service.php';
require_once __DIR__ . '/../services/customer_service.php';

function handlePosRequest($action, $data, $conn) {
    require_role(['manager', 'cashier']);
    switch ($action) {
        case 'index':
            return pos_index($conn);
        case 'checkout':
            return pos_checkout($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function pos_index($conn): void
{
    $session = get_open_cash_session($conn);
    if (!$session) {
        flash('warning', 'Open a cash session before selling.');
        redirect('cash');
    }

    // Active products with their selling units (a product without units cannot be sold)
    $active         = get_active_products($conn)['data'];
    $unitsByProduct = get_units_for_products(array_column($active, 'id'), $conn);

    $products = [];
    foreach ($active as $p) {
        $units = [];
        foreach ($unitsByProduct[$p['id']] ?? [] as $u) {
            $units[] = [
                'id'     => (int)$u['id'],
                'name'   => $u['unit_name'],
                'factor' => $u['conversion_factor'],
                'price'  => (float)$u['selling_price'],
            ];
        }
        if (!$units) {
            continue;
        }
        $products[] = [
            'id' => (int)$p['id'], 'code' => $p['product_code'], 'name' => $p['name'],
            'brand' => $p['brand'], 'barcode' => $p['barcode'], 'base_unit' => $p['base_unit'],
            'category' => $p['category_name'],
            'stock' => (int)$p['quantity_in_stock'], 'units' => $units,
        ];
    }

    $customers = [];
    foreach (get_active_customers($conn)['data'] as $c) {
        $customers[] = [
            'id' => (int)$c['id'], 'name' => $c['full_name'], 'phone' => $c['phone'],
            'limit' => (float)$c['credit_limit'], 'debt' => (float)$c['current_debt'],
        ];
    }

    // One-time token: protects against submitting the same sale twice
    $_SESSION['pos_token'] = bin2hex(random_bytes(16));

    render('pos/index', [
        'title'   => 'Point of Sale',
        'active'  => 'pos',
        'session' => $session,
        'token'   => $_SESSION['pos_token'],
        'payload' => [
            'products'    => $products,
            'customers'   => $customers,
            'isManager'   => has_role(['manager']),
            'checkoutUrl' => url('pos/checkout'),
        ],
    ]);
}

// The checkout (called with fetch() from pos.js, always answers in JSON)
function pos_checkout($conn): void
{
    $token = post_text('checkout_token');
    if (empty($_SESSION['pos_token']) || !hash_equals($_SESSION['pos_token'], $token)) {
        json_response(['ok' => false, 'message' => 'This sale form is out of date or was already submitted. Check Sales History, then reload the POS page.'], 409);
    }

    // The cart lines: the form sends one list per field
    $productIds = (array)($_POST['product_id'] ?? []);
    $unitIds    = (array)($_POST['unit_id'] ?? []);
    $quantities = (array)($_POST['quantity'] ?? []);
    $items = [];
    foreach ($productIds as $i => $productId) {
        $qty = trim((string)($quantities[$i] ?? ''));
        $items[] = [
            'product_id' => (int)$productId,
            'unit_id'    => (int)($unitIds[$i] ?? 0),
            'quantity'   => ctype_digit($qty) ? (int)$qty : 0,
        ];
    }

    // The payments (a sale can be paid with several methods)
    $methods = (array)($_POST['pay_method'] ?? []);
    $amounts = (array)($_POST['pay_amount'] ?? []);
    $refs    = (array)($_POST['pay_ref'] ?? []);
    $payments = [];
    foreach ($methods as $i => $method) {
        $amount = trim((string)($amounts[$i] ?? ''));
        if ($amount === '') {
            continue;
        }
        if (!is_numeric($amount) || (float)$amount < 0) {
            json_response(['ok' => false, 'message' => 'A payment amount is not valid.'], 422);
        }
        $payments[] = [
            'method'    => (string)$method,
            'amount'    => (float)$amount,
            'reference' => mb_substr(trim((string)($refs[$i] ?? '')), 0, 60),
        ];
    }

    $discount = post_text('discount');
    if ($discount === '') {
        $discount = '0';
    }
    if (!is_numeric($discount)) {
        json_response(['ok' => false, 'message' => 'The discount is not a valid number.'], 422);
    }

    try {
        // The "Other / New customer..." option in the dropdown: the typed name
        // is created on the spot (or the existing customer with that name is reused).
        $customerId = post_text('customer_id') === '__other__'
            ? resolve_or_create_customer(post_text('customer_other'), $conn)
            : post_int('customer_id');
        $result = create_sale([
            'customer_id'    => $customerId,
            'discount'       => (float)$discount,
            'override_limit' => !empty($_POST['override_limit']),
            'items'          => $items,
            'payments'       => $payments,
        ], (int)current_user_id(), (string)current_role(), $conn);

        unset($_SESSION['pos_token']);
        json_response(['ok' => true, 'receipt_url' => url('sale/receipt?id=' . $result['id'] . '&new=1')]);
    } catch (RuntimeException $ex) {
        json_response(['ok' => false, 'message' => $ex->getMessage()], 422);
    }
}
