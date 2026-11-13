<?php
// ============================================================================
// Settings: service areas, tables and the crate/pack conversions.
// ============================================================================

require_once __DIR__ . '/../models/settings_model.php';
require_once __DIR__ . '/../models/table_model.php';
require_once __DIR__ . '/../services/settings_service.php';
require_once __DIR__ . '/../services/audit_service.php';
require_once __DIR__ . '/../validators/settings_validator.php';

function handleSettingsRequest($action, $data, $conn) {
    require_role(['manager']);
    switch ($action) {
        case 'index':
            return settings_index($conn);
        case 'areaStore':
            return settings_area_store($conn);
        case 'areaUpdate':
            return settings_area_update($conn);
        case 'tableStore':
            return settings_table_store($conn);
        case 'tableUpdate':
            return settings_table_update($conn);
        case 'unitUpdate':
            return settings_unit_update($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function settings_index($conn): void
{
    render('settings/index', [
        'title'    => 'Settings',
        'active'   => 'settings',
        'areas'    => get_all_areas(false, $conn),
        'tables'   => get_floor_tables($conn),
        'products' => get_settings_products($conn),
        'history'  => get_unit_change_history(30, $conn)['data'],
    ]);
}

function settings_area_store($conn): void
{
    $name = post_text('name');
    $sort = post_int('sort_order');
    $error = validate_area($name);
    if ($error) {
        flash('danger', $error);
        redirect('settings');
    }
    $result = create_area($name, $sort, $conn);
    if ($result['success']) {
        audit_log($conn, (int)current_user_id(), 'area_create', 'bar_area', (int)$result['data']['id'], 'Area "' . $name . '" added.');
        flash('success', 'Service area added.');
    } else {
        flash('danger', 'That area name already exists.');
    }
    redirect('settings');
}

function settings_area_update($conn): void
{
    $id   = post_int('id');
    $name = post_text('name');
    $error = validate_area($name);
    if ($id <= 0 || $error) {
        flash('danger', $error ?? 'Enter an area name (2 to 80 characters).');
        redirect('settings');
    }
    $result = update_area($id, $name, post_int('sort_order'), !empty($_POST['active']), $conn);
    if ($result['success']) {
        audit_log($conn, (int)current_user_id(), 'area_update', 'bar_area', $id, 'Area updated to "' . $name . '".');
        flash('success', 'Service area saved.');
    } else {
        flash('danger', 'That area name already exists.');
    }
    redirect('settings');
}

function settings_table_store($conn): void
{
    $code = strtoupper(post_text('code'));
    $area = post_int('area_id');
    $cap  = post_int('capacity');
    $error = validate_table_form($code, $area, $cap);
    if ($error) {
        flash('danger', $error);
        redirect('settings');
    }
    $areaFound = find_area($area, $conn);
    if (!$areaFound['success'] || empty($areaFound['data'])) {
        flash('danger', 'Choose a service area for the table.');
        redirect('settings');
    }
    $result = create_table_row($code, $area, $cap, mb_substr(post_text('floor_position'), 0, 40), $conn);
    if ($result['success']) {
        audit_log($conn, (int)current_user_id(), 'table_create', 'bar_table', (int)$result['data']['id'], 'Table ' . $code . ' added.');
        flash('success', 'Table added to the floor.');
    } else {
        flash('danger', 'That table code already exists.');
    }
    redirect('settings');
}

function settings_table_update($conn): void
{
    $id   = post_int('id');
    $code = strtoupper(post_text('code'));
    $area = post_int('area_id');
    $cap  = post_int('capacity');
    if ($id <= 0 || validate_table_form($code, $area, $cap)) {
        flash('danger', 'Check the table code, area and capacity.');
        redirect('settings');
    }
    $result = update_table_row($id, $code, $area, $cap, mb_substr(post_text('floor_position'), 0, 40), !empty($_POST['active']), $conn);
    if ($result['success']) {
        audit_log($conn, (int)current_user_id(), 'table_update', 'bar_table', $id, 'Table ' . $code . ' updated.');
        flash('success', 'Table saved. Taking it out of service does not close an occupied tab.');
    } else {
        flash('danger', 'That table code already exists.');
    }
    redirect('settings');
}

function settings_unit_update($conn): void
{
    $unitId = post_int('unit_id');
    $factor = post_int('factor');
    $price  = post_text('price');
    $reason = post_text('reason');
    $error  = validate_unit_price($price);
    if ($error) {
        flash('danger', $error);
        redirect('settings');
    }
    try {
        update_unit_conversion($unitId, $factor, (float)$price, (int)current_user_id(), $reason ?: null, $conn);
        flash('success', 'Conversion saved. Completed sales and rounds keep their original factor and price.');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('settings');
}
