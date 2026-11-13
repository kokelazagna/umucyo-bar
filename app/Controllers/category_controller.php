<?php
// ============================================================================
// Beverage categories.
// ============================================================================

require_once __DIR__ . '/../models/category_model.php';
require_once __DIR__ . '/../services/audit_service.php';

function handleCategoryRequest($action, $data, $conn) {
    require_role(['manager', 'stock_manager']);
    switch ($action) {
        case 'index':
            return category_index($conn);
        case 'store':
            return category_store($conn);
        case 'update':
            return category_update($conn);
        case 'toggle':
            return category_toggle($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function category_index($conn): void
{
    render('categories/index', [
        'title'      => 'Beverage Categories',
        'active'     => 'category',
        'categories' => get_categories_page(current_page(), PER_PAGE, $conn)['data'],
        'total'      => get_categories_page(current_page(), PER_PAGE, $conn)['total'],
        'page'       => current_page(),
    ]);
}

function category_store($conn): void
{
    $name = post_text('name');
    $desc = post_text('description');

    if ($name === '' || mb_strlen($name) > 80) {
        flash('danger', 'Category name is required (maximum 80 characters).');
    } elseif (category_name_exists($name, 0, $conn)) {
        flash('danger', 'A category with this name already exists.');
    } else {
        $result = create_category($name, mb_substr($desc, 0, 255), $conn);
        audit_log($conn, current_user_id(), 'category_create', 'category', (int)$result['data']['id'], $name);
        flash('success', 'Category "' . $name . '" added.');
    }
    redirect('category');
}

function category_update($conn): void
{
    $id   = post_int('id');
    $name = post_text('name');
    $desc = post_text('description');

    $found = find_category($id, $conn);
    if (!$found['success'] || empty($found['data'])) {
        flash('danger', 'Category not found.');
    } elseif ($name === '' || mb_strlen($name) > 80) {
        flash('danger', 'Category name is required (maximum 80 characters).');
    } elseif (category_name_exists($name, $id, $conn)) {
        flash('danger', 'Another category already uses this name.');
    } else {
        update_category($id, $name, mb_substr($desc, 0, 255), $conn);
        audit_log($conn, current_user_id(), 'category_update', 'category', $id, $name);
        flash('success', 'Category updated.');
    }
    redirect('category');
}

function category_toggle($conn): void
{
    $id   = post_int('id');
    $found = find_category($id, $conn);

    if (!$found['success'] || empty($found['data'])) {
        flash('danger', 'Category not found.');
    } else {
        $category  = $found['data'][0];
        $newStatus = $category['status'] === 'active' ? 'inactive' : 'active';
        set_category_status($id, $newStatus, $conn);
        audit_log($conn, current_user_id(), 'category_' . $newStatus, 'category', $id, $category['name']);
        flash('success', 'Category "' . $category['name'] . '" is now ' . $newStatus . '.');
    }
    redirect('category');
}
