<?php
// ============================================================================
// Expenses (database queries only).
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';

function get_expense_categories($conn)
{
    $query = "SELECT id, name FROM expense_categories WHERE status = 'active' ORDER BY id";
    return executeQuery($query, null, $conn);
}

function find_expense_category(int $id, $conn)
{
    $query = "SELECT * FROM expense_categories WHERE id = ? AND status = 'active'";
    return executeQuery($query, [$id], $conn);
}

// $f: category (id), from, to
// Paged: returns ['success','data','total'].
function search_expenses(array $f, int $page, int $perPage, $conn)
{
    $query = 'SELECT e.*, c.name AS category_name, u.full_name AS recorded_name
              FROM expenses e
              JOIN expense_categories c ON c.id = e.expense_category_id
              LEFT JOIN users u         ON u.id = e.recorded_by
             WHERE 1 = 1';
    $params = [];
    if (($f['category'] ?? 0) > 0) {
        $query .= ' AND e.expense_category_id = ?';
        $params[] = $f['category'];
    }
    if (looks_like_date($f['from'] ?? '')) {
        $query .= ' AND e.expense_date >= ?';
        $params[] = $f['from'];
    }
    if (looks_like_date($f['to'] ?? '')) {
        $query .= ' AND e.expense_date <= ?';
        $params[] = $f['to'];
    }
    $query .= ' ORDER BY e.expense_date DESC, e.id DESC';
    return paged_query($query, $params, $page, $perPage, $conn);
}

// EXP-0001, EXP-0002, ...
function next_expense_code($conn): string
{
    $query = "SELECT COALESCE(MAX(CAST(SUBSTRING(expense_code, 5) AS UNSIGNED)), 0) + 1 AS next_no
           FROM expenses WHERE expense_code LIKE 'EXP-%'";
    $result = executeQuery($query, null, $conn);
    $n = (int)($result['data'][0]['next_no'] ?? 1);
    return 'EXP-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function insert_expense(array $d, ?int $sessionId, int $userId, $conn)
{
    $query = 'INSERT INTO expenses
               (expense_code, expense_category_id, cash_session_id, description, amount, payment_method, expense_date, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
    $result = executeQuery($query, [
        next_expense_code($conn), $d['category_id'], $sessionId, $d['description'],
        $d['amount'], $d['payment_method'], $d['expense_date'], $userId,
    ], $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}

// Finds an expense category by exact name (case-insensitive).
function find_expense_category_by_name(string $name, $conn)
{
    $query = 'SELECT id, name, status FROM expense_categories WHERE LOWER(name) = ? LIMIT 1';
    return executeQuery($query, [mb_strtolower($name)], $conn);
}

function insert_expense_category(string $name, $conn)
{
    $query = "INSERT INTO expense_categories (name, status) VALUES (?, 'active')";
    $result = executeQuery($query, [$name], $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}
