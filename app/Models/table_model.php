<?php
// ============================================================================
// Floor & seating: bar areas, tables, table sessions, serving rounds (draft /
// served) and open tabs. Database queries only - the business rules live in
// table_service.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';

// ---------- Areas ----------

function get_all_areas(bool $activeOnly, $conn): array
{
    $query = 'SELECT * FROM bar_areas';
    if ($activeOnly) {
        $query .= ' WHERE active = 1';
    }
    $query .= ' ORDER BY sort_order, name';
    return executeQuery($query, null, $conn)['data'];
}

function find_area(int $id, $conn)
{
    $query = 'SELECT * FROM bar_areas WHERE id = ?';
    return executeQuery($query, [$id], $conn);
}

function count_tables_in_area(int $areaId, $conn): int
{
    $result = executeQuery('SELECT COUNT(*) AS total FROM bar_tables WHERE area_id = ?', [$areaId], $conn);
    return (int)($result['data'][0]['total'] ?? 0);
}

function create_area(string $name, int $sortOrder, $conn)
{
    $query = 'INSERT INTO bar_areas (name, sort_order, active) VALUES (?, ?, 1)';
    $result = executeQuery($query, [$name, $sortOrder], $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}

function update_area(int $id, string $name, int $sortOrder, bool $active, $conn)
{
    $query = 'UPDATE bar_areas SET name = ?, sort_order = ?, active = ? WHERE id = ?';
    return executeQuery($query, [$name, $sortOrder, $active ? 1 : 0, $id], $conn);
}

// ---------- Tables ----------

// Every table with its area, the open session (if any) and the outstanding
// bill of that session's open sale, ready for the floor view.
function get_floor_tables($conn): array
{
    $query = "SELECT t.*, a.name AS area_name, a.sort_order AS area_sort,
                s.id AS session_id, s.status AS session_status, s.opened_at AS session_opened_at,
                w.full_name AS waiter_name,
                o.id AS sale_id, o.receipt_no AS sale_receipt, o.subtotal AS sale_subtotal,
                o.discount AS sale_discount, o.total_amount AS sale_total, o.amount_paid AS sale_paid,
                (SELECT COUNT(*) FROM orders ord WHERE ord.table_session_id = s.id AND ord.state = 'draft') AS draft_orders
           FROM bar_tables t
           JOIN bar_areas a  ON a.id = t.area_id
           LEFT JOIN table_sessions s ON s.table_id = t.id AND s.status = 'open'
           LEFT JOIN users w ON w.id = s.assigned_waiter_id
           LEFT JOIN sales o ON o.table_session_id = s.id AND o.status = 'open'
          ORDER BY a.sort_order, a.name, t.created_at DESC, t.id DESC";
    return executeQuery($query, null, $conn)['data'];
}

function find_table(int $id, $conn)
{
    $query = 'SELECT * FROM bar_tables WHERE id = ?';
    return executeQuery($query, [$id], $conn);
}

function find_table_by_code(string $code, $conn)
{
    $query = 'SELECT * FROM bar_tables WHERE code = ?';
    return executeQuery($query, [$code], $conn);
}

function create_table_row(string $code, int $areaId, int $capacity, string $position, $conn)
{
    $query = 'INSERT INTO bar_tables (code, area_id, capacity, status, active, floor_position) VALUES (?, ?, ?, ?, 1, ?)';
    $result = executeQuery($query, [$code, $areaId, $capacity, 'available', $position], $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}

function update_table_row(int $id, string $code, int $areaId, int $capacity, string $position, bool $active, $conn)
{
    $query = 'UPDATE bar_tables SET code = ?, area_id = ?, capacity = ?, floor_position = ?, active = ? WHERE id = ?';
    return executeQuery($query, [$code, $areaId, $capacity, $position, $active ? 1 : 0, $id], $conn);
}

// ---------- Table sessions ----------

function get_open_session_for_table(int $tableId, $conn)
{
    $query = "SELECT * FROM table_sessions WHERE table_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1";
    return executeQuery($query, [$tableId], $conn);
}

function find_table_session(int $id, $conn)
{
    $query = "SELECT s.*, t.code AS table_code, t.area_id AS area_id, a.name AS area_name
           FROM table_sessions s
           JOIN bar_tables t ON t.id = s.table_id
           JOIN bar_areas a  ON a.id = t.area_id
          WHERE s.id = ?";
    return executeQuery($query, [$id], $conn);
}

// One session with everything the POS / floor needs: table, waiter, the open
// sale (the tab), what was served and what is still owed.
function get_session_view(int $sessionId, $conn): ?array
{
    $found = find_table_session($sessionId, $conn);
    if (!$found['success'] || empty($found['data'])) {
        return null;
    }
    $session = $found['data'][0];
    $session['sale'] = executeQuery(
        "SELECT * FROM sales WHERE table_session_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1",
        [$sessionId],
        $conn
    )['data'][0] ?? null;
    if ($session['sale']) {
        $session['sale']['items'] = executeQuery(
            "SELECT si.*, p.name AS product_name, p.base_unit AS base_unit, u.unit_name
               FROM sale_items si
               JOIN products p      ON p.id = si.product_id
               JOIN product_units u ON u.id = si.unit_id
              WHERE si.sale_id = ? AND si.line_status = 'active'
              ORDER BY si.id",
            [(int)$session['sale']['id']],
            $conn
        )['data'];
    }
    $session['draft_items'] = executeQuery(
        "SELECT oi.*, ord.id AS order_id, p.name AS product_name, p.base_unit AS base_unit, u.unit_name
           FROM order_items oi
           JOIN orders ord        ON ord.id = oi.order_id AND ord.state = 'draft'
           JOIN products p        ON p.id = oi.product_id
           JOIN product_units u   ON u.id = oi.unit_id
          WHERE ord.table_session_id = ?
          ORDER BY oi.id",
        [$sessionId],
        $conn
    )['data'];
    $session['orders'] = executeQuery(
        "SELECT o.*, u.full_name AS created_by_name, COUNT(oi.id) AS item_count
           FROM orders o
           LEFT JOIN users u ON u.id = o.created_by
           LEFT JOIN order_items oi ON oi.order_id = o.id AND oi.state = 'served'
          WHERE o.table_session_id = ?
          GROUP BY o.id ORDER BY o.id",
        [$sessionId],
        $conn
    )['data'];
    return $session;
}

// ---------- Open tabs (report + floor) ----------

// Every OPEN table session with its open sale and outstanding balance.
function get_open_tabs($conn): array
{
    $query = "SELECT s.id AS session_id, s.opened_at, s.assigned_waiter_id, w.full_name AS waiter_name,
                t.code AS table_code, t.id AS table_id, a.name AS area_name,
                o.id AS sale_id, o.receipt_no, o.sale_date, o.subtotal, o.discount,
                o.total_amount, o.amount_paid,
                (o.total_amount - o.amount_paid) AS balance,
                TIMESTAMPDIFF(MINUTE, s.opened_at, NOW()) AS open_minutes
           FROM table_sessions s
           JOIN bar_tables t    ON t.id = s.table_id
           JOIN bar_areas a     ON a.id = t.area_id
           LEFT JOIN users w    ON w.id = s.assigned_waiter_id
           LEFT JOIN sales o    ON o.table_session_id = s.id AND o.status = 'open'
          WHERE s.status = 'open'
          ORDER BY s.opened_at DESC, s.id DESC";
    return executeQuery($query, null, $conn)['data'];
}

// Sales by table / area for a date range (completed settlements only).
function get_table_sales_by_area(string $from, string $to, $conn): array
{
    $query = "SELECT a.name AS area, t.code AS table_code, COUNT(s.id) AS sales,
                COALESCE(SUM(s.total_amount), 0) AS revenue,
                COALESCE(SUM(s.credit_amount), 0) AS credit
           FROM sales s
           JOIN table_sessions ts ON ts.id = s.table_session_id
           JOIN bar_tables t      ON t.id = ts.table_id
           JOIN bar_areas a       ON a.id = t.area_id
          WHERE s.status = 'completed' AND s.sale_date BETWEEN ? AND ?
          GROUP BY t.id ORDER BY a.sort_order, t.code";
    return executeQuery($query, [$from . ' 00:00:00', $to . ' 23:59:59'], $conn)['data'];
}

// Summarised by area for the same period.
function get_table_sales_area_totals(string $from, string $to, $conn): array
{
    $query = "SELECT a.name AS area, COUNT(s.id) AS sales, COALESCE(SUM(s.total_amount), 0) AS revenue
           FROM sales s
           JOIN table_sessions ts ON ts.id = s.table_session_id
           JOIN bar_tables t      ON t.id = ts.table_id
           JOIN bar_areas a       ON a.id = t.area_id
          WHERE s.status = 'completed' AND s.sale_date BETWEEN ? AND ?
          GROUP BY a.id ORDER BY a.sort_order";
    return executeQuery($query, [$from . ' 00:00:00', $to . ' 23:59:59'], $conn)['data'];
}

// Waiter assignment options: active users.
function get_active_staff($conn): array
{
    $query = "SELECT id, full_name, role_id FROM users WHERE status = 'active' ORDER BY full_name";
    return executeQuery($query, null, $conn)['data'];
}

// ---------- rows used by the table service transactions ----------

function find_table_session_row(int $sessionId, $conn)
{
    $query = "SELECT * FROM table_sessions WHERE id = ? FOR UPDATE";
    return executeQuery($query, [$sessionId], $conn);
}

function find_open_session_of_table(int $tableId, $conn)
{
    $query = "SELECT id FROM table_sessions WHERE table_id = ? AND status = 'open' FOR UPDATE";
    return executeQuery($query, [$tableId], $conn);
}

function find_waiter(int $waiterId, $conn)
{
    $query = "SELECT id FROM users WHERE id = ? AND status = 'active'";
    return executeQuery($query, [$waiterId], $conn);
}

function insert_table_session(int $tableId, int $userId, ?int $waiterId, $conn)
{
    $query = 'INSERT INTO table_sessions (table_id, opened_by, assigned_waiter_id, status) VALUES (?, ?, ?, ?)';
    $result = executeQuery($query, [$tableId, $userId, $waiterId, 'open'], $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}

function set_table_status(int $tableId, string $status, $conn)
{
    $query = 'UPDATE bar_tables SET status = ? WHERE id = ?';
    return executeQuery($query, [$status, $tableId], $conn);
}

function close_table_session(int $sessionId, int $userId, string $notes, $conn)
{
    $query = "UPDATE table_sessions SET status = 'closed', closed_at = NOW(), closed_by = ?, notes = ? WHERE id = ?";
    return executeQuery($query, [$userId, $notes, $sessionId], $conn);
}

function count_served_items(int $sessionId, $conn): int
{
    $query = "SELECT COUNT(*) AS total FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE o.table_session_id = ? AND oi.state = 'served'";
    $result = executeQuery($query, [$sessionId], $conn);
    return (int)($result['data'][0]['total'] ?? 0);
}

function find_open_sale_of_session(int $sessionId, $conn)
{
    $query = "SELECT * FROM sales WHERE table_session_id = ? AND status = 'open'";
    return executeQuery($query, [$sessionId], $conn);
}

function find_open_sale_of_session_locked(int $sessionId, $conn)
{
    $query = "SELECT * FROM sales WHERE table_session_id = ? AND status = 'open' FOR UPDATE";
    return executeQuery($query, [$sessionId], $conn);
}

function find_table_code(int $tableId, $conn)
{
    $query = 'SELECT code FROM bar_tables WHERE id = ?';
    return executeQuery($query, [$tableId], $conn);
}

// ---------- Draft rounds (rows for the service) ----------

function find_draft_order(int $sessionId, $conn)
{
    $query = "SELECT id FROM orders WHERE table_session_id = ? AND state = 'draft' ORDER BY id DESC LIMIT 1";
    return executeQuery($query, [$sessionId], $conn);
}

function insert_draft_order(int $sessionId, int $userId, $conn)
{
    $query = "INSERT INTO orders (table_session_id, sales_channel, created_by, state) VALUES (?, 'TABLE', ?, 'draft')";
    $result = executeQuery($query, [$sessionId, $userId], $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}

function insert_draft_item(int $orderId, int $productId, int $unitId, int $factor, float $price, float $avgCost, int $quantity, int $baseQty, $conn)
{
    $query = "INSERT INTO order_items
               (order_id, product_id, unit_id, conversion_factor_snapshot, unit_price_snapshot,
                unit_cost_snapshot, quantity, base_quantity, state)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft')";
    $result = executeQuery($query, [$orderId, $productId, $unitId, $factor, $price, $avgCost, $quantity, $baseQty], $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}

function find_draft_item(int $itemId, $conn)
{
    $query = "SELECT oi.*, o.state AS order_state, o.table_session_id
               FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE oi.id = ? FOR UPDATE";
    return executeQuery($query, [$itemId], $conn);
}

function delete_draft_item(int $itemId, $conn)
{
    $query = 'DELETE FROM order_items WHERE id = ?';
    return executeQuery($query, [$itemId], $conn);
}

function update_draft_item_row(int $itemId, int $quantity, int $newBase, $conn)
{
    $query = 'UPDATE order_items SET quantity = ?, base_quantity = ? WHERE id = ?';
    return executeQuery($query, [$quantity, $newBase, $itemId], $conn);
}

function get_draft_item_ids(int $sessionId, $conn): array
{
    $query = "SELECT oi.id FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE o.table_session_id = ? AND oi.state = 'draft' AND o.state = 'draft' FOR UPDATE";
    return executeQuery($query, [$sessionId], $conn)['data'];
}

function count_draft_items(int $sessionId, $conn): int
{
    $query = "SELECT COUNT(*) AS total FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE o.table_session_id = ? AND o.state = 'draft' AND oi.state = 'draft'";
    $result = executeQuery($query, [$sessionId], $conn);
    return (int)($result['data'][0]['total'] ?? 0);
}

// ---------- Confirm served (rows for the service) ----------

function find_session_with_table(int $sessionId, $conn)
{
    $query = "SELECT s.*, t.code AS table_code FROM table_sessions s
                   JOIN bar_tables t ON t.id = s.table_id
                  WHERE s.id = ? FOR UPDATE";
    return executeQuery($query, [$sessionId], $conn);
}

function get_draft_items_to_serve(int $sessionId, $conn): array
{
    $query = "SELECT oi.*, o.id AS order_id FROM order_items oi JOIN orders o ON o.id = oi.order_id
                  WHERE o.table_session_id = ? AND o.state = 'draft' AND oi.state = 'draft'
                  ORDER BY oi.product_id, oi.id FOR UPDATE";
    return executeQuery($query, [$sessionId], $conn)['data'];
}

function mark_order_items_served(int $itemId, int $userId, $conn)
{
    $query = "UPDATE order_items SET state = 'served', served_at = NOW(), served_by = ? WHERE id = ?";
    return executeQuery($query, [$userId, $itemId], $conn);
}

function close_draft_order(int $sessionId, $conn)
{
    $query = "UPDATE orders SET state = 'served' WHERE table_session_id = ? AND state = 'draft'";
    return executeQuery($query, [$sessionId], $conn);
}

function add_open_sale_totals(int $saleId, float $subtotalDelta, $conn)
{
    $query = "UPDATE sales SET subtotal = subtotal + ?, total_amount = total_amount + ? WHERE id = ?";
    return executeQuery($query, [round($subtotalDelta, 2), round($subtotalDelta, 2), $saleId], $conn);
}

// ---------- Void a served line (rows for the service) ----------

function find_sale_item(int $saleItemId, int $saleId, $conn)
{
    $query = 'SELECT * FROM sale_items WHERE id = ? AND sale_id = ? FOR UPDATE';
    return executeQuery($query, [$saleItemId, $saleId], $conn);
}

function void_sale_item(int $saleItemId, $conn)
{
    $query = "UPDATE sale_items SET line_status = 'void' WHERE id = ?";
    return executeQuery($query, [$saleItemId], $conn);
}

function void_order_item(int $orderItemId, $conn)
{
    $query = "UPDATE order_items SET state = 'void' WHERE id = ?";
    return executeQuery($query, [$orderItemId], $conn);
}

function sum_active_sale_items(int $saleId, $conn): float
{
    $query = "SELECT COALESCE(SUM(subtotal), 0) AS total FROM sale_items WHERE sale_id = ? AND line_status = 'active'";
    $result = executeQuery($query, [$saleId], $conn);
    return (float)($result['data'][0]['total'] ?? 0);
}

function update_sale_totals(int $saleId, float $subtotal, float $total, $conn)
{
    $query = 'UPDATE sales SET subtotal = ?, total_amount = ? WHERE id = ?';
    return executeQuery($query, [round($subtotal, 2), round($total, 2), $saleId], $conn);
}

// ---------- Settlement (rows for the service) ----------

function update_sale_settlement(int $saleId, float $subtotal, float $discount, float $total, float $newPaid, float $credit, ?int $discountBy, $conn)
{
    $query = 'UPDATE sales SET subtotal = ?, discount = ?, total_amount = ?, amount_paid = ?, credit_amount = ?, discount_by = ? WHERE id = ?';
    return executeQuery($query, [$subtotal, $discount, $total, $newPaid, $credit, $discountBy, $saleId], $conn);
}

function complete_sale(int $saleId, ?int $cashSessionId, $conn)
{
    $query = "UPDATE sales SET status = 'completed', cash_session_id = ? WHERE id = ?";
    return executeQuery($query, [$cashSessionId, $saleId], $conn);
}

function close_session_orders(int $sessionId, $conn)
{
    $query = "UPDATE orders SET state = 'closed' WHERE table_session_id = ? AND state = 'draft'";
    return executeQuery($query, [$sessionId], $conn);
}

function close_table_session_settled(int $sessionId, int $userId, $conn)
{
    $query = "UPDATE table_sessions SET status = 'closed', closed_at = NOW(), closed_by = ? WHERE id = ?";
    return executeQuery($query, [$userId, $sessionId], $conn);
}

// ---------- Transfer and merge (rows for the service) ----------

function move_session_to_table(int $sessionId, int $targetTableId, $conn)
{
    $query = 'UPDATE table_sessions SET table_id = ? WHERE id = ?';
    return executeQuery($query, [$targetTableId, $sessionId], $conn);
}

function move_sessions_orders(int $sourceSessionId, int $targetSessionId, $conn)
{
    $query = 'UPDATE orders SET table_session_id = ? WHERE table_session_id = ?';
    return executeQuery($query, [$targetSessionId, $sourceSessionId], $conn);
}

function move_sales_to_session(int $saleId, int $sessionId, $conn)
{
    $query = 'UPDATE sales SET table_session_id = ? WHERE id = ?';
    return executeQuery($query, [$sessionId, $saleId], $conn);
}

function move_sale_items(int $fromSaleId, int $toSaleId, $conn)
{
    $query = 'UPDATE sale_items SET sale_id = ? WHERE sale_id = ?';
    return executeQuery($query, [$toSaleId, $fromSaleId], $conn);
}

function move_payments(int $fromSaleId, int $toSaleId, $conn)
{
    $query = 'UPDATE payments SET sale_id = ? WHERE sale_id = ?';
    return executeQuery($query, [$toSaleId, $fromSaleId], $conn);
}

function sum_payments(int $saleId, $conn): float
{
    $query = 'SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE sale_id = ?';
    $result = executeQuery($query, [$saleId], $conn);
    return (float)($result['data'][0]['total'] ?? 0);
}

function mark_sale_merged(int $saleId, int $userId, string $reason, $conn)
{
    $query = "UPDATE sales SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?";
    return executeQuery($query, [$userId, $reason, $saleId], $conn);
}

function reopen_table_session(int $sessionId, $conn)
{
    $query = "UPDATE table_sessions SET status = 'open', closed_at = NULL, closed_by = NULL, notes = 'Reopened after bill cancellation' WHERE id = ?";
    return executeQuery($query, [$sessionId], $conn);
}

function get_product_unit_history(int $productId, int $limit, $conn): array
{
    $query = "SELECT h.*, u.full_name AS changed_by_name
           FROM product_price_history h
           LEFT JOIN users u ON u.id = h.changed_by
          WHERE h.product_id = ?
          ORDER BY h.id DESC LIMIT " . (int)$limit;
    return executeQuery($query, [$productId], $conn)['data'];
}
