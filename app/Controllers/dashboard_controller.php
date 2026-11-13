<?php
// ============================================================================
// Dashboard page. The money figures are only collected for roles that may see them.
// ============================================================================

require_once __DIR__ . '/../models/dashboard_model.php';
require_once __DIR__ . '/../models/category_model.php';
require_once __DIR__ . '/../services/dashboard_service.php';
require_once __DIR__ . '/../services/cash_service.php';

function handleDashboardRequest($action, $data, $conn) {
    require_role(['manager', 'cashier', 'stock_manager']);
    switch ($action) {
        case 'index':
            return show_dashboard_page($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function show_dashboard_page($conn): void
{
    $isManager = has_role(['manager']);
    $canSales  = has_role(['manager', 'cashier']);

    render('dashboard/index', [
        'title'          => 'Dashboard',
        'active'         => 'dashboard',
        'stock'          => get_stock_totals($conn),
        'figures'        => $isManager ? get_today_figures($conn) : null,
        'chart'          => $isManager ? get_sales_last_days(7, $conn) : [],
        'recent'         => $canSales ? get_recent_sales(6, $conn)['data'] : [],
        'lowStock'       => get_low_stock_products(6, $conn)['data'],
        'lowCount'       => count_low_stock_products($conn),
        'categoryCounts' => get_category_product_counts(6, $conn)['data'],
        'session'        => get_open_cash_session($conn),
        'canSales'       => $canSales,
    ]);
}
