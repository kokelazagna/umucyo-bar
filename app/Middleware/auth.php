<?php
// ============================================================================
// Middleware: session, flash messages, CSRF protection, login state and roles.
// The logged-in user is kept in $_SESSION['user']. The database questions of
// the login live in user_model.
// ============================================================================

require_once __DIR__ . '/../models/user_model.php';
require_once __DIR__ . '/../helpers/php_helper.php';

// Starts the PHP session and logs the user out after a period of inactivity.
function boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('BSSBMS_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Automatic session expiration after inactivity
    if (isset($_SESSION['_last_activity']) && (time() - $_SESSION['_last_activity']) > APP_SESSION_LIFETIME) {
        $wasLoggedIn = isset($_SESSION['user']);
        $_SESSION = [];
        session_regenerate_id(true);
        if ($wasLoggedIn) {
            flash('warning', 'Your session expired. Please log in again.');
        }
    }
    $_SESSION['_last_activity'] = time();
}

// ---------- Flash messages ----------
// A flash message is saved now and shown once on the next page.
// $type: 'success', 'danger' or 'warning'.

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

// Returns the waiting messages and clears them.
function flash_pull(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

// ---------- CSRF protection ----------
// Every POST form carries a secret token so other websites cannot submit it for the user.

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

// The hidden input to place inside every <form method="post">.
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    return hash_equals($_SESSION['_csrf'] ?? '', $_POST['_csrf'] ?? '');
}

// ---------- Login state and roles ----------

// Checks the username and password. On success the user is stored in the session.
function login_user($conn, string $username, string $password): bool
{
    $found = find_user_by_username($username, $conn);

    if (!$found['success'] || empty($found['data'])) {
        return false;
    }
    $user = $found['data'][0];
    if ($user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);               // prevents session fixation
    $_SESSION['user'] = [
        'id'        => (int)$user['id'],
        'full_name' => $user['full_name'],
        'username'  => $user['username'],
        'role'      => $user['role_name'],
    ];
    touch_last_login($conn, (int)$user['id']);
    return true;
}

// Re-reads the account from the database on every request, so a deactivated user
// is logged out immediately and role changes apply at once.
function sync_login($conn): bool
{
    $id = current_user_id();
    if (!$id) {
        return false;
    }
    $row = get_user_role_status($conn, $id);
    if (!$row['success'] || empty($row['data']) || $row['data'][0]['uStatus'] !== 'active') {
        logout_user();
        return false;
    }
    $_SESSION['user']['role']      = $row['data'][0]['uRoleName'];
    $_SESSION['user']['full_name'] = $row['data'][0]['uFullName'];
    return true;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

// The logged-in user as an array (id, full_name, username, role) or null.
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function current_user_id(): ?int
{
    return $_SESSION['user']['id'] ?? null;
}

function current_role(): ?string
{
    return $_SESSION['user']['role'] ?? null;
}

// $roles: list of allowed role names. The owner is always allowed.
//   has_role(['manager', 'cashier'])
function has_role(array $roles): bool
{
    $role = current_role();
    return $role !== null && ($role === 'owner' || in_array($role, $roles, true));
}

// Stops with 403 when the logged-in user has none of the roles (owner always passes).
function require_role(array $roles): void
{
    if (!has_role($roles)) {
        http_response_code(403);
        echo '<h1>403 - You do not have permission to open this page</h1>';
        exit;
    }
}

function logout_user(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}
