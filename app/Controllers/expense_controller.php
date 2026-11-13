<?php
// ============================================================================
// Expenses (manager / owner).
// ============================================================================

require_once __DIR__ . '/../models/expense_model.php';
require_once __DIR__ . '/../services/expense_service.php';
require_once __DIR__ . '/../validators/expense_validator.php';

function handleExpenseRequest($action, $data, $conn) {
    require_role(['manager']);
    switch ($action) {
        case 'index':
            return expense_index($conn);
        case 'create':
            return expense_create($conn);
        case 'store':
            return expense_store($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function expense_index($conn): void
{
    $filters = [
        'category' => get_int('category'),
        'from'     => get_text('from'),
        'to'       => get_text('to'),
    ];
    $expenses = search_expenses($filters, current_page(), PER_PAGE, $conn)['data'];
    $expTotal = search_expenses($filters, current_page(), PER_PAGE, $conn)['total'];

    $total = 0.0;
    foreach ($expenses as $expense) {
        $total += (float)$expense['amount'];
    }

    render('expenses/index', [
        'title'      => 'Expenses',
        'active'     => 'expense',
        'expenses'   => $expenses,
        'total'      => $total,
        'categories' => get_expense_categories($conn)['data'],
        'filters'    => $filters,
    ]);
}

function expense_create($conn): void
{
    expense_show_form([
        'category_id' => 0, 'description' => '', 'amount' => '', 'payment_method' => 'cash',
        'expense_date' => date('Y-m-d'),
    ], [], $conn);
}

function expense_store($conn): void
{
    try {
        $categoryId = post_text('category_id') === '__other__'
            ? resolve_or_create_expense_category(post_text('category_other'), $conn)
            : post_int('category_id');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
        redirect('expense/create');
    }
    $d = [
        'category_id'    => $categoryId,
        'description'    => post_text('description'),
        'amount'         => post_text('amount'),
        'payment_method' => post_text('payment_method'),
        'expense_date'   => post_text('expense_date'),
    ];
    $errors = validate_expense($d);

    if (!$errors) {
        try {
            $d['amount'] = round((float)$d['amount'], 2);
            create_expense($d, (int)current_user_id(), $conn);
            flash('success', 'Expense recorded.');
            redirect('expense');
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
    expense_show_form($d, $errors, $conn);
}

// ---------- private helper for this file ----------

function expense_show_form(array $expense, array $errors, $conn): void
{
    render('expenses/create', [
        'title'      => 'New Expense',
        'active'     => 'expense',
        'e'          => $expense,
        'errors'     => $errors,
        'categories' => get_expense_categories($conn)['data'],
    ]);
}
