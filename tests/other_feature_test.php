<?php
define("DEBUG", true);
// Tests the "Other / New..." option in all four forms (plain POST, no AJAX changes).
$ck = '/tmp/ck_other.txt'; @unlink($ck);
function req($url, $ck, $post = null) { return curl_req($url, $ck, $post); } function curl_req($url, $ck, $post = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $ck, CURLOPT_COOKIEFILE => $ck,
        CURLOPT_POST => $post !== null, CURLOPT_POSTFIELDS => $post]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $body];
}
function field($page, $name) {
    if (preg_match('/<input[^>]*name="' . $name . '"[^>]*value="([^"]*)"/', $page, $m)) return $m[1];
    return null;
}
function db($q) { $c = mysqli_connect('localhost','root','','bar_management_db'); $r = mysqli_query($c, $q); if ($r === true) return [['ok' => 1]]; if ($r instanceof mysqli_result) return mysqli_fetch_all($r, MYSQLI_ASSOC); return [['ok' => 0]]; }

// login as owner (fetch the login page first to get the CSRF token)
[, $page] = req('http://127.0.0.1:8132/auth/login', $ck);
$csrf = field($page, '_csrf');
req('http://127.0.0.1:8132/auth/login', $ck, ['username' => 'admin', 'password' => 'Admin@123', '_csrf' => $csrf]);
[, $page] = req('http://127.0.0.1:8132/purchase/create', $ck);
$csrf = field($page, '_csrf');

// 1. Purchase with Other supplier
$prod = db("SELECT p.id, p.name, pu.id AS unit_id, pu.selling_price FROM products p JOIN product_units pu ON pu.product_id = p.id WHERE p.status='active' LIMIT 1")[0];
$cat = db("SELECT id FROM categories WHERE status='active' LIMIT 1")[0]['id'];
req('http://127.0.0.1:8132/purchase/store', $ck, [
    '_csrf' => $csrf, 'supplier_id' => '__other__', 'supplier_other' => 'Other Test Supplier',
    'purchase_date' => date('Y-m-d'), 'payment_method' => 'mobile_money', 'amount_paid' => '0',
    'product_id[0]' => $prod['id'], 'unit_id[0]' => $prod['unit_id'], 'quantity[0]' => '2', 'unit_cost[0]' => $prod['selling_price'],
]);
$sup = db("SELECT id, supplier_code, name FROM suppliers WHERE name = 'Other Test Supplier'");
echo "1. purchase+other supplier: " . (!empty($sup) ? "OK " . $sup[0]['supplier_code'] : "FAIL") . "\n";
$pid = db("SELECT id, status FROM purchases ORDER BY id DESC LIMIT 1")[0];
echo "   purchase created: " . ($pid['status'] !== '' ? "OK id={$pid['id']} status={$pid['status']}" : "FAIL") . "\n";

// 2. Reuse: the same name in a second purchase must NOT create a second supplier
[, $page] = req('http://127.0.0.1:8132/purchase/create', $ck);
$csrf = field($page, '_csrf');
req('http://127.0.0.1:8132/purchase/store', $ck, [
    '_csrf' => $csrf, 'supplier_id' => '__other__', 'supplier_other' => 'Other Test Supplier',
    'purchase_date' => date('Y-m-d'), 'payment_method' => 'mobile_money', 'amount_paid' => '0',
    'product_id[0]' => $prod['id'], 'unit_id[0]' => $prod['unit_id'], 'quantity[0]' => '1', 'unit_cost[0]' => $prod['selling_price'],
]);
$n = db("SELECT COUNT(*) n FROM suppliers WHERE name = 'Other Test Supplier'")[0]['n'];
echo "2. reuse same name: " . ((int)$n === 1 ? "OK (still 1 supplier)" : "FAIL ($n suppliers)") . "\n";

// 3. Purchase with Other but empty name -> must be refused with the message
[$code3, $page] = req('http://127.0.0.1:8132/purchase/create', $ck);
$csrf = field($page, '_csrf');
if (DEBUG) { echo "3. csrf: " . substr($csrf ?? '', 0, 10) . " len: " . strlen($page) . " code: $code3\n"; }
[, $body] = req('http://127.0.0.1:8132/purchase/store', $ck, [
    '_csrf' => $csrf, 'supplier_id' => '__other__', 'supplier_other' => '',
    'purchase_date' => date('Y-m-d'), 'payment_method' => 'mobile_money', 'amount_paid' => '0',
    'product_id[0]' => $prod['id'], 'unit_id[0]' => $prod['unit_id'], 'quantity[0]' => '1', 'unit_cost[0]' => $prod['selling_price'],
]);
if (DEBUG) { echo "3. debug: " . substr($body, 0, 300) . "\n"; }
echo "3. empty name refused: " . (strpos($body, 'Type the supplier name') !== false ? "OK" : "FAIL") . "\n";

// 4. Expense with Other category
[, $page] = req('http://127.0.0.1:8132/expense/create', $ck);
$csrf = field($page, '_csrf');
req('http://127.0.0.1:8132/expense/store', $ck, [
    '_csrf' => $csrf, 'category_id' => '__other__', 'category_other' => 'Birthday Night',
    'description' => 'Other test expense', 'amount' => '1000', 'payment_method' => 'cash', 'expense_date' => date('Y-m-d'),
]);
$cat2 = db("SELECT id, name FROM expense_categories WHERE name = 'Birthday Night'");
echo "4. expense+other category: " . (!empty($cat2) ? "OK" : "FAIL") . "\n";
$exp = db("SELECT description, amount FROM expenses ORDER BY id DESC LIMIT 1")[0];
echo "   expense recorded: " . ($exp['description'] === 'Other test expense' ? "OK" : "FAIL") . "\n";

// 5. Table settle with Other customer
$table = db("SELECT id FROM bar_tables WHERE status='available' ORDER BY id LIMIT 1")[0]['id'];
db("UPDATE bar_tables SET status='available' WHERE id=" . (int)$table);           // make sure it is free
[, $body] = req('http://127.0.0.1:8132/table', $ck);
$cs = field($body, '_csrf');
req('http://127.0.0.1:8132/table/open', $ck, ['_csrf' => $cs, 'table_id' => $table, 'waiter_id' => '', 'waiter_name' => 'Tester']);
$sid = db("SELECT id FROM table_sessions WHERE table_id = " . (int)$table . " AND status='open' ORDER BY id DESC LIMIT 1")[0]['id'];
[, $body] = req('http://127.0.0.1:8132/table/session?id=' . $sid, $ck);
$tok = field($body, 'table_add');
req('http://127.0.0.1:8132/table/add', $ck, ['session_id' => $sid, 'table_token' => $tok, 'product_id' => $prod['id'], 'unit_id' => $prod['unit_id'], 'quantity' => '2']);
[, $body] = req('http://127.0.0.1:8132/table/session?id=' . $sid, $ck);
$tok = field($body, 'table_token');
req('http://127.0.0.1:8132/table/serve', $ck, ['session_id' => $sid, 'table_token' => $tok]);
[, $body] = req('http://127.0.0.1:8132/table/session?id=' . $sid, $ck);
$tok = field($body, 'table_token');
req('http://127.0.0.1:8132/table/settle', $ck, [
    'session_id' => $sid, 'table_token' => $tok, 'cash_amount' => '0', 'momo_amount' => '0', 'bank_amount' => '0',
    'customer_id' => '__other__', 'customer_other' => 'Other Test Customer',
]);
$cust = db("SELECT id, customer_code, full_name FROM customers WHERE full_name = 'Other Test Customer'");
echo "5. settle+other customer: " . (!empty($cust) ? "OK " . $cust[0]['customer_code'] : "FAIL") . "\n";
$ss = db("SELECT status, sale_id FROM table_sessions WHERE id = " . (int)$sid)[0];
echo "   session closed: " . ($ss['status'] === 'closed' ? "OK" : "FAIL status={$ss['status']}") . "\n";

// 6. POS checkout (AJAX endpoint) with Other customer
[, $page] = req('http://127.0.0.1:8132/pos', $ck);
$tok = field($page, 'pos_token');
db("UPDATE bar_tables SET status='available' WHERE id=" . (int)$table);
$ch = curl_init('http://127.0.0.1:8132/pos/checkout');
$fd = http_build_query([
    'pos_token' => $tok, 'customer_id' => '__other__', 'customer_other' => 'Other POS Customer',
    'items' => json_encode([['product_id' => $prod['id'], 'unit_id' => $prod['unit_id'], 'quantity' => '1', 'unit_id_direct' => $prod['unit_id']]]),
    'payments' => json_encode([['method' => 'cash', 'amount' => '5000']]),
]);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $ck, CURLOPT_COOKIEFILE => $ck, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fd]);
$res = json_decode(curl_exec($ch), true); curl_close($ch);
$pcust = db("SELECT id, customer_code FROM customers WHERE full_name = 'Other POS Customer'");
echo "6. POS checkout+other customer: " . (!empty($res['ok']) && $res['ok'] ? "OK receipt" : "FAIL " . ($res['message'] ?? 'no reply')) . "\n";
echo "   customer created: " . (!empty($pcust) ? "OK " . $pcust[0]['customer_code'] : "FAIL") . "\n";
echo "DONE\n";
