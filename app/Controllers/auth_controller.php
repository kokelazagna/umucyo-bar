<?php
// ============================================================================
// Login and logout.
// ============================================================================

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/audit_service.php';
require_once __DIR__ . '/../validators/user_validator.php';

function handleAuthRequest($action, $data, $conn) {
    switch ($action) {
        case 'login':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                return auth_login($conn);
            }
            return auth_show_login();
        case 'logout':
            return auth_logout($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function auth_show_login(): void
{
    if (is_logged_in()) {
        redirect('dashboard');
    }
    render('auth/login', ['title' => 'Sign in'], 'auth');
}

function auth_login($conn): void
{
    $username = post_text('username');
    $password = post_raw('password');

    $error = validate_login_input($username, $password);
    if ($error) {
        flash('danger', $error);
        redirect('auth/login');
    }

    if (login_user($conn, $username, $password)) {
        audit_log($conn, current_user_id(), 'login');
        redirect('dashboard');
    }

    audit_log($conn, null, 'login_failed', null, null, 'username: ' . $username);
    flash('danger', 'Invalid username or password.');
    redirect('auth/login');
}

function auth_logout($conn): void
{
    audit_log($conn, current_user_id(), 'logout');
    logout_user();
    flash('success', 'You have been logged out.');
    redirect('auth/login');
}
