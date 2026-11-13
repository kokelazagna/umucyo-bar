<?php
// ============================================================================
// Operating expenses. Cash expenses leave the cash drawer, so they need an open
// cash session. Owner withdrawals are NOT expenses (they live in Cash Management).
// The queries live in expense_model and cash_service.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';
require_once __DIR__ . '/../models/expense_model.php';
require_once __DIR__ . '/cash_service.php';
require_once __DIR__ . '/audit_service.php';

// Returns the id of the expense category with this name; creates it when it
// does not exist yet (used by the "Other / New..." option in the expense form).
function resolve_or_create_expense_category(string $name, $conn): int
{
    $name = trim($name);
    if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        throw new RuntimeException('Type the category name (2 to 80 characters).');
    }
    $found = find_expense_category_by_name($name, $conn);
    if ($found['success'] && !empty($found['data'])) {
        return (int)$found['data'][0]['id'];
    }
    $created = insert_expense_category($name, $conn);
    if (!$created['success']) {
        throw new RuntimeException('The category could not be created. Please try again.');
    }
    $id = (int)$created['data']['id'];
    audit_log($conn, current_user_id(), 'expense_category_create', 'expense_category', $id, $name . ' (added from the expense form)');
    return $id;
}

// $d: category_id, description, amount, payment_method, expense_date
function create_expense(array $d, int $userId, $conn): int
{
    $session = get_open_cash_session($conn);

    db_begin($conn);
    try {
        if ($d['payment_method'] === 'cash') {
            if (!$session) {
                throw new RuntimeException('Open a cash session first: cash expenses are paid from the till.');
            }
            $sum = get_cash_summary((int)$session['id'], $conn);
            if ($d['amount'] > $sum['expected'] + 0.009) {
                throw new RuntimeException('Not enough cash in the drawer. Expected cash is ' . number_format($sum['expected'], 0) . ' RWF.');
            }
        }

        $inserted = insert_expense($d, $session ? (int)$session['id'] : null, $userId, $conn);
        if (!$inserted['success']) {
            throw new RuntimeException('The expense could not be saved. Please try again.');
        }
        $id = (int)$inserted['data']['id'];

        if ($d['payment_method'] === 'cash') {
            record_cash_transaction((int)$session['id'], 'expense', 'out', $d['amount'], 'expense', $id, $d['description'], $userId, $conn);
        }
        audit_log($conn, $userId, 'expense_create', 'expense', $id, number_format($d['amount'], 0) . ' RWF: ' . $d['description']);

        db_commit($conn);
        return $id;
    } catch (Throwable $e) {
        db_rollback($conn);
        throw $e;
    }
}
