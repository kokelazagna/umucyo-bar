<?php
// ============================================================================
// Cash management: open the till, deposits, withdrawals, close the till.
// ============================================================================

require_once __DIR__ . '/../services/cash_service.php';
require_once __DIR__ . '/../validators/settings_validator.php';

function handleCashRequest($action, $data, $conn) {
    require_role(['manager', 'cashier']);
    switch ($action) {
        case 'index':
            return cash_index($conn);
        case 'session':
            return cash_session($conn);
        case 'open':
            return cash_open($conn);
        case 'close':
            return cash_close($conn);
        case 'deposit':
            require_role(['manager']);
            return cash_deposit($conn);
        case 'withdraw':
            require_role(['manager']);
            return cash_withdraw($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function cash_index($conn): void
{
    $current = get_open_cash_session($conn);
    if ($current) {
        redirect('cash/session?id=' . (int)$current['id']);
    }

    $history   = get_cash_history(current_page(), PER_PAGE, $conn);
    $suggested = 0;          // suggested opening balance = cash counted when the last session closed
    foreach ($history['data'] as $h) {
        if ($h['status'] === 'closed') {
            $suggested = $h['counted_closing'];
            break;
        }
    }
    render('cash/index', [
        'title'     => 'Cash Management',
        'active'    => 'cash',
        'history'   => $history['data'],
        'total'     => $history['total'],
        'suggested' => $suggested,
    ]);
}

function cash_session($conn): void
{
    $id      = get_int('id');
    $session = find_cash_session($id, $conn);
    if (!$session) {
        flash('danger', 'Cash session not found.');
        redirect('cash');
    }
    render('cash/session', [
        'title'     => 'Cash Session #' . $session['id'],
        'active'    => 'cash',
        'cs'        => $session,
        'sum'       => get_cash_summary($id, $conn),
        'txns'      => get_cash_transactions($id, (int)($_GET['txn_page'] ?? 1), PER_PAGE, $conn),
        'txnTotal'  => get_cash_transactions($id, 1, 1, $conn)['total'],
        'isManager' => has_role(['manager']),
    ]);
}

function cash_open($conn): void
{
    $amount = post_text('opening_balance');
    if (!is_numeric($amount) || (float)$amount < 0 || (float)$amount > 1000000000) {
        flash('danger', 'Enter the opening cash balance (0 or more).');
        redirect('cash');
    }
    try {
        $id = open_cash_session((float)$amount, (int)current_user_id(), $conn);
        flash('success', 'Cash session opened. You can now sell.');
        redirect('cash/session?id=' . $id);
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
        redirect('cash');
    }
}

function cash_close($conn): void
{
    $id      = post_int('id');
    $counted = post_text('counted');
    $notes   = post_text('notes');
    if (!is_numeric($counted) || (float)$counted < 0) {
        flash('danger', 'Enter the cash you counted in the drawer.');
        redirect('cash/session?id=' . $id);
    }
    if (mb_strlen($notes) > 255) {
        flash('danger', 'The note is too long (maximum 255 characters).');
        redirect('cash/session?id=' . $id);
    }
    try {
        $result = close_cash_session($id, (float)$counted, $notes !== '' ? $notes : null, (int)current_user_id(), $conn);
        flash('success', 'Cash session closed. Variance: ' . number_format($result['variance'], 0) . ' RWF.');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('cash/session?id=' . $id);
}

function cash_deposit($conn): void
{
    $id     = post_int('id');
    $amount = post_text('amount');
    $desc   = post_text('description');
    if (!is_numeric($amount) || (float)$amount <= 0) {
        flash('danger', 'Enter a deposit amount greater than 0.');
    } else {
        try {
            deposit_cash($id, (float)$amount, $desc !== '' ? mb_substr($desc, 0, 255) : 'Cash deposit', (int)current_user_id(), $conn);
            flash('success', 'Deposit recorded.');
        } catch (RuntimeException $ex) {
            flash('danger', $ex->getMessage());
        }
    }
    redirect('cash/session?id=' . $id);
}

function cash_withdraw($conn): void
{
    $id     = post_int('id');
    $amount = post_text('amount');
    $desc   = post_text('description');
    if (!is_numeric($amount) || (float)$amount <= 0) {
        flash('danger', 'Enter a withdrawal amount greater than 0.');
    } elseif (validate_withdrawal_reason($desc)) {
        flash('danger', validate_withdrawal_reason($desc));
    } else {
        try {
            withdraw_cash($id, (float)$amount, $desc, (int)current_user_id(), $conn);
            flash('success', 'Owner withdrawal recorded (it is not counted as a business expense).');
        } catch (RuntimeException $ex) {
            flash('danger', $ex->getMessage());
        }
    }
    redirect('cash/session?id=' . $id);
}
