<?php
// ============================================================================
// Users and roles (database queries only).
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';

// Used by the login: finds the user by username OR email (an exact username match wins).
function find_user_by_username(string $login, $conn)
{
    $query = 'SELECT u.*, r.name AS role_name
           FROM users u
           JOIN roles r ON r.id = u.role_id
          WHERE u.username = ? OR u.email = ?
          ORDER BY (u.username = ?) DESC
          LIMIT 1';
    return executeQuery($query, [$login, $login, $login], $conn);
}

// Used by the middleware after login: reads only the status and the role name.
function get_user_role_status($conn, int $id)
{
    $query = 'SELECT u.status AS uStatus, u.full_name AS uFullName, r.name AS uRoleName
           FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?';
    return executeQuery($query, [$id], $conn);
}

function touch_last_login($conn, int $id)
{
    $query = 'UPDATE users SET last_login_at = NOW() WHERE id = ?';
    return executeQuery($query, [$id], $conn);
}

function get_all_users($conn)
{
    $query = 'SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.full_name';
    return executeQuery($query, null, $conn);
}

// The users list for the list view (paged). The dropdowns use get_all_users().
function get_users_page(int $page, int $perPage, $conn)
{
    $query = 'SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.created_at DESC, u.id DESC';
    return paged_query($query, null, $page, $perPage, $conn);
}

function find_user(int $id, $conn)
{
    $query = 'SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?';
    return executeQuery($query, [$id], $conn);
}

function get_roles($conn)
{
    $query = 'SELECT id, name FROM roles ORDER BY id';
    return executeQuery($query, null, $conn);
}

// $excludeId lets an edited user keep their own username/email.
function username_exists(string $username, int $excludeId, $conn): bool
{
    $query = 'SELECT COUNT(*) AS total FROM users WHERE username = ? AND id <> ?';
    $result = executeQuery($query, [$username, $excludeId], $conn);
    return $result['success'] && (int)($result['data'][0]['total'] ?? 0) > 0;
}

function email_exists(string $email, int $excludeId, $conn): bool
{
    $query = 'SELECT COUNT(*) AS total FROM users WHERE email = ? AND id <> ?';
    $result = executeQuery($query, [$email, $excludeId], $conn);
    return $result['success'] && (int)($result['data'][0]['total'] ?? 0) > 0;
}

function count_active_owners(int $excludeId, $conn): int
{
    $query = "SELECT COUNT(*) AS total FROM users u JOIN roles r ON r.id = u.role_id
          WHERE r.name = 'owner' AND u.status = 'active' AND u.id <> ?";
    $result = executeQuery($query, [$excludeId], $conn);
    return (int)($result['data'][0]['total'] ?? 0);
}

function create_user(array $d, string $hash, $conn)
{
    $query = 'INSERT INTO users (role_id, full_name, username, email, phone, password_hash, status) VALUES (?, ?, ?, ?, ?, ?, ?)';
    $result = executeQuery($query, [$d['role_id'], $d['full_name'], $d['username'], $d['email'], $d['phone'], $hash, $d['status']], $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}

// $hash = new password hash, or null to keep the current password.
function update_user(int $id, array $d, ?string $hash, $conn)
{
    $query    = 'UPDATE users SET role_id = ?, full_name = ?, username = ?, email = ?, phone = ?, status = ?';
    $params = [$d['role_id'], $d['full_name'], $d['username'], $d['email'], $d['phone'], $d['status']];
    if ($hash !== null) {
        $query .= ', password_hash = ?';
        $params[] = $hash;
    }
    $query .= ' WHERE id = ?';
    $params[] = $id;
    return executeQuery($query, $params, $conn);
}

function set_user_status(int $id, string $status, $conn)
{
    $query = 'UPDATE users SET status = ? WHERE id = ?';
    return executeQuery($query, [$status, $id], $conn);
}
