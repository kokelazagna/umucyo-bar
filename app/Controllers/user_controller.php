<?php
// ============================================================================
// Users and the audit log (owner only).
// ============================================================================

require_once __DIR__ . '/../models/user_model.php';
require_once __DIR__ . '/../models/audit_model.php';
require_once __DIR__ . '/../services/audit_service.php';
require_once __DIR__ . '/../validators/user_validator.php';

function handleUserRequest($action, $data, $conn) {
    require_role([]);   // owner only (the owner is always allowed, so an empty list means only the owner)
    switch ($action) {
        case 'index':
            return user_index($conn);
        case 'create':
            return user_create($conn);
        case 'edit':
            return user_edit($conn);
        case 'store':
            return user_store($conn);
        case 'update':
            return user_update($conn);
        case 'toggle':
            return user_toggle($conn);
        case 'audit':
            return user_audit($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function user_index($conn): void
{
    render('users/index', [
        'title'  => 'Users',
        'active' => 'user',
        'users'  => get_users_page(current_page(), PER_PAGE, $conn)['data'],
        'total'  => get_users_page(current_page(), PER_PAGE, $conn)['total'],
        'page'   => current_page(),
        'me'     => (int)current_user_id(),
    ]);
}

function user_create($conn): void
{
    user_show_form('create', ['status' => 'active', 'role_id' => 0], [], $conn);
}

function user_edit($conn): void
{
    $found = find_user(get_int('id'), $conn);
    if (!$found['success'] || empty($found['data'])) {
        flash('danger', 'User not found.');
        redirect('user');
    }
    user_show_form('edit', $found['data'][0], [], $conn);
}

function user_store($conn): void
{
    user_save(null, $conn);
}

function user_update($conn): void
{
    user_save(post_int('id'), $conn);
}

function user_toggle($conn): void
{
    $id     = post_int('id');
    $found  = find_user($id, $conn);

    if (!$found['success'] || empty($found['data'])) {
        flash('danger', 'User not found.');
    } elseif ($id === (int)current_user_id()) {
        flash('danger', 'You cannot deactivate your own account.');
    } else {
        $user = $found['data'][0];
        $newStatus = $user['status'] === 'active' ? 'inactive' : 'active';
        if ($newStatus === 'inactive' && $user['role_name'] === 'owner' && count_active_owners($id, $conn) < 1) {
            flash('danger', 'You cannot deactivate the last active owner.');
        } else {
            set_user_status($id, $newStatus, $conn);
            audit_log($conn, current_user_id(), 'user_' . $newStatus, 'user', $id, $user['username']);
            flash('success', 'User "' . $user['username'] . '" is now ' . $newStatus . '.');
        }
    }
    redirect('user');
}

function user_audit($conn): void
{
    $filters = [
        'action' => get_text('action'),
        'user'   => get_int('user'),
        'from'   => get_text('from'),
        'to'     => get_text('to'),
    ];
    render('users/audit', [
        'title'   => 'Audit Log',
        'active'  => 'user/audit',
        'logs'    => search_audit_logs($filters, (int)($_GET['log_page'] ?? 1), PER_PAGE, $conn)['data'],
        'logTotal' => search_audit_logs($filters, (int)($_GET['log_page'] ?? 1), PER_PAGE, $conn)['total'],
        'actions' => array_column(get_audit_actions($conn), 'action'),
        'users'   => get_all_users($conn)['data'],
        'filters' => $filters,
    ]);
}

// ---------- private helpers for this file ----------

// Reads the form, validates it and creates ($id = null) or updates ($id = user id).
function user_save(?int $id, $conn): void
{
    $existing = null;
    if ($id !== null) {
        $found = find_user($id, $conn);
        if (!$found['success'] || empty($found['data'])) {
            flash('danger', 'User not found.');
            redirect('user');
        }
        $existing = $found['data'][0];
    }
    $isSelf = $existing && (int)$existing['id'] === (int)current_user_id();

    $d = [
        'full_name' => post_text('full_name'),
        'username'  => post_text('username'),
        'email'     => post_text('email'),
        'phone'     => post_text('phone'),
        'role_id'   => post_int('role_id'),
        'status'    => post_text('status', 'active'),
    ];
    if ($isSelf) { // you cannot change your own role or status (prevents locking yourself out)
        $d['role_id'] = (int)$existing['role_id'];
        $d['status']  = $existing['status'];
    }
    $password  = post_raw('password');
    $password2 = post_raw('password2');
    $errors = [];

    // role id => role name, e.g. [1 => 'owner', 2 => 'manager']
    $roles = [];
    foreach (get_roles($conn)['data'] as $r) {
        $roles[(int)$r['id']] = $r['name'];
    }

    $errors = validate_user($d, $password, $password2, $existing, $roles);

    if ($d['email'] !== '') {
        if (email_exists($d['email'], (int)$id, $conn)) {
            $errors[] = 'This email is already used by another user.';
        }
    }
    if (username_exists($d['username'], (int)$id, $conn)) {
        $errors[] = 'This username is already taken.';
    }

    // A password is required for a new user; when editing it is optional (empty = keep the old one)
    $hash = null;
    if ($existing === null || $password !== '' || $password2 !== '') {
        if (!in_array('Password must be at least 8 characters with at least one letter and one digit.', $errors)
            && !in_array('The two passwords do not match.', $errors)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
        }
    }

    // Keep at least one active owner
    if ($existing && $existing['role_name'] === 'owner' && !$errors) {
        $stillOwner = isset($roles[$d['role_id']]) && $roles[$d['role_id']] === 'owner';
        if ((!$stillOwner || $d['status'] !== 'active') && count_active_owners((int)$id, $conn) < 1) {
            $errors[] = 'There must always be at least one active owner.';
        }
    }

    if (!$errors) {
        $d['email'] = $d['email'] !== '' ? $d['email'] : null;
        $d['phone'] = $d['phone'] !== '' ? $d['phone'] : null;
        if ($existing === null) {
            $result = create_user($d, $hash, $conn);
            audit_log($conn, current_user_id(), 'user_create', 'user', (int)$result['data']['id'], $d['username'] . ' (' . $roles[$d['role_id']] . ')');
            flash('success', 'User "' . $d['username'] . '" created.');
        } else {
            update_user((int)$id, $d, $hash, $conn);
            if ((int)$existing['role_id'] !== $d['role_id']) {
                audit_log($conn, current_user_id(), 'role_change', 'user', (int)$id, $d['username'] . ': ' . $existing['role_name'] . ' -> ' . $roles[$d['role_id']]);
            }
            if ($hash !== null) {
                audit_log($conn, current_user_id(), 'password_reset', 'user', (int)$id, $d['username']);
            }
            audit_log($conn, current_user_id(), 'user_update', 'user', (int)$id, $d['username']);
            flash('success', 'User "' . $d['username'] . '" updated.');
        }
        redirect('user');
    }

    // Errors: show the form again with what the user typed
    $user = $d;
    if ($existing) {
        $user['id'] = $existing['id'];
    }
    user_show_form($existing ? 'edit' : 'create', $user, $errors, $conn);
}

function user_show_form(string $mode, array $user, array $errors, $conn): void
{
    render('users/form', [
        'title'  => $mode === 'edit' ? 'Edit User' : 'New User',
        'active' => 'user',
        'mode'   => $mode,
        'user'   => $user,
        'roles'  => get_roles($conn)['data'],
        'errors' => $errors,
        'self'   => ($mode === 'edit' && (int)($user['id'] ?? 0) === (int)current_user_id()),
    ]);
}
