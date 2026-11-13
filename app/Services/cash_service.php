<?php
// ============================================================================
// Cash sessions: the till is opened with an opening balance, every cash movement
// is recorded in cash_transactions, and the session is closed with a counted amount.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';
require_once __DIR__ . '/audit_service.php';

// The open cash session, or none. Only ONE session can be open at a time (one till).
function get_open_cash_session($conn)
{
    $result = executeQuery("SELECT * FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1", null, $conn);
    return $result['data'][0] ?? null;
}

function find_cash_session(int $id, $conn)
{
    $result = executeQuery(
        'SELECT s.*, ou.full_name AS opened_name, cu.full_name AS closed_name
           FROM cash_sessions s
           LEFT JOIN users ou ON ou.id = s.opened_by
           LEFT JOIN users cu ON cu.id = s.closed_by
          WHERE s.id = ?',
        [$id],
        $conn
    );
    return $result['data'][0] ?? null;
}

// The cash sessions, newest first (paged).
function get_cash_history(int $page, int $perPage, $conn): array
{
    $count = executeQuery('SELECT COUNT(*) AS total FROM cash_sessions', null, $conn);
    $total = (int)($count['data'][0]['total'] ?? 0);
    $offset = ($page - 1) * $perPage;
    $rows = executeQuery(
        'SELECT s.*, ou.full_name AS opened_name
           FROM cash_sessions s LEFT JOIN users ou ON ou.id = s.opened_by
          ORDER BY s.id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset,
        null,
        $conn
    );
    return ['success' => $rows['success'], 'error' => $rows['error'], 'data' => $rows['data'], 'total' => $total];
}

function open_cash_session(float $opening, int $userId, $conn): int
{
    if ($opening < 0) {
        throw new RuntimeException('Opening balance cannot be negative.');
    }
    if (get_open_cash_session($conn)) {
        throw new RuntimeException('A cash session is already open. Close it first.');
    }
    $result = executeQuery(
        "INSERT INTO cash_sessions (opened_by, opening_balance, status) VALUES (?, ?, 'open')",
        [$userId, round($opening, 2)],
        $conn
    );
    if (!$result['success']) {
        throw new RuntimeException('The cash session could not be opened. Please try again.');
    }
    $id = $conn->insert_id;
    audit_log($conn, $userId, 'cash_session_open', 'cash_session', $id, 'Opening balance ' . number_format($opening, 0) . ' RWF');
    return $id;
}

// Records one cash movement. Call it inside the caller's transaction when needed.
//   $type: sale, debt_payment, deposit, supplier_payment, expense, owner_withdrawal, refund
//   $direction: 'in' (money enters the drawer) or 'out'
function record_cash_transaction(int $sessionId, string $type, string $direction, float $amount, ?string $refType, ?int $refId, ?string $desc, int $userId, $conn): void
{
    executeQuery(
        'INSERT INTO cash_transactions
           (cash_session_id, txn_type, direction, amount, reference_type, reference_id, description, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$sessionId, $type, $direction, round($amount, 2), $refType, $refId, $desc, $userId],
        $conn
    );
}

// Totals of a session. Expected cash = opening + cash in - cash out
function get_cash_summary(int $sessionId, $conn): array
{
    $session = find_cash_session($sessionId, $conn);
    $totals  = ['sale' => 0.0, 'debt_payment' => 0.0, 'deposit' => 0.0,
                'supplier_payment' => 0.0, 'expense' => 0.0, 'owner_withdrawal' => 0.0, 'refund' => 0.0];

    $rows = executeQuery(
        'SELECT txn_type, SUM(amount) AS total FROM cash_transactions WHERE cash_session_id = ? GROUP BY txn_type',
        [$sessionId],
        $conn
    );
    foreach ($rows['data'] as $row) {
        $totals[$row['txn_type']] = (float)$row['total'];
    }

    $opening = (float)$session['opening_balance'];
    $in      = $totals['sale'] + $totals['debt_payment'] + $totals['deposit'];
    $out     = $totals['supplier_payment'] + $totals['expense'] + $totals['owner_withdrawal'] + $totals['refund'];

    $totals['opening']   = $opening;
    $totals['total_in']  = $in;
    $totals['total_out'] = $out;
    $totals['expected']  = round($opening + $in - $out, 2);
    return $totals;
}

// The movements of one session (paged).
function get_cash_transactions(int $sessionId, int $page, int $perPage, $conn): array
{
    $count = executeQuery('SELECT COUNT(*) AS total FROM cash_transactions WHERE cash_session_id = ?', [$sessionId], $conn);
    $total = (int)($count['data'][0]['total'] ?? 0);
    $offset = ($page - 1) * $perPage;
    $rows = executeQuery(
        'SELECT t.*, u.full_name FROM cash_transactions t
           LEFT JOIN users u ON u.id = t.recorded_by
          WHERE t.cash_session_id = ? ORDER BY t.id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset,
        [$sessionId],
        $conn
    );
    return ['success' => $rows['success'], 'error' => $rows['error'], 'data' => $rows['data'], 'total' => $total];
}

// Closes the session with the cash that was counted. A difference needs a note.
function close_cash_session(int $sessionId, float $counted, ?string $notes, int $userId, $conn): array
{
    if ($counted < 0) {
        throw new RuntimeException('Counted cash cannot be negative.');
    }
    db_begin($conn);
    try {
        $session = executeQuery('SELECT * FROM cash_sessions WHERE id = ? FOR UPDATE', [$sessionId], $conn);
        if (!$session['success'] || empty($session['data']) || $session['data'][0]['status'] !== 'open') {
            throw new RuntimeException('This cash session is not open.');
        }
        $sum      = get_cash_summary($sessionId, $conn);
        $variance = round($counted - $sum['expected'], 2);
        if (abs($variance) > 0.009 && ($notes === null || trim($notes) === '')) {
            throw new RuntimeException('The counted cash differs from the expected cash by ' . number_format($variance, 0)
                . ' RWF. Enter a note explaining the difference.');
        }
        executeQuery(
            "UPDATE cash_sessions
                SET closed_by = ?, closed_at = NOW(), expected_closing = ?, counted_closing = ?,
                    variance = ?, status = 'closed', notes = ?
              WHERE id = ?",
            [$userId, $sum['expected'], round($counted, 2), $variance, $notes, $sessionId],
            $conn
        );
        db_commit($conn);
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }

    audit_log($conn, $userId, 'cash_session_close', 'cash_session', $sessionId,
        'Expected ' . number_format($sum['expected'], 0) . ', counted ' . number_format($counted, 0) . ', variance ' . number_format($variance, 0));
    return ['expected' => $sum['expected'], 'variance' => $variance];
}

// Money added to the drawer.
function deposit_cash(int $sessionId, float $amount, string $desc, int $userId, $conn): void
{
    assert_cash_session_open($sessionId, $conn);
    if ($amount <= 0) {
        throw new RuntimeException('Deposit amount must be greater than 0.');
    }
    record_cash_transaction($sessionId, 'deposit', 'in', $amount, null, null, $desc, $userId, $conn);
    audit_log($conn, $userId, 'cash_deposit', 'cash_session', $sessionId, number_format($amount, 0) . ' RWF: ' . $desc);
}

// Owner withdrawals are NOT business expenses (they never reduce profit).
function withdraw_cash(int $sessionId, float $amount, string $desc, int $userId, $conn): void
{
    assert_cash_session_open($sessionId, $conn);
    if ($amount <= 0) {
        throw new RuntimeException('Withdrawal amount must be greater than 0.');
    }
    $sum = get_cash_summary($sessionId, $conn);
    if ($amount > $sum['expected'] + 0.009) {
        throw new RuntimeException('Not enough cash in the drawer. Expected cash is ' . number_format($sum['expected'], 0) . ' RWF.');
    }
    record_cash_transaction($sessionId, 'owner_withdrawal', 'out', $amount, null, null, $desc, $userId, $conn);
    audit_log($conn, $userId, 'owner_withdrawal', 'cash_session', $sessionId, number_format($amount, 0) . ' RWF: ' . $desc);
}

// Stops with an error when the session does not exist or is already closed.
function assert_cash_session_open(int $sessionId, $conn): void
{
    $session = find_cash_session($sessionId, $conn);
    if (!$session || $session['status'] !== 'open') {
        throw new RuntimeException('This cash session is not open.');
    }
}
