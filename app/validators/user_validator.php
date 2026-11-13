<?php
// ============================================================================
// User form validation (format rules; the username / email uniqueness checks
// stay in the controller because they need the database).
// ============================================================================

function validate_user(array $d, string $password, string $password2, ?array $existing, array $roles): array
{
    $errors = [];

    if (mb_strlen($d['full_name']) < 2 || mb_strlen($d['full_name']) > 120) {
        $errors[] = 'Full name is required (2 to 120 characters).';
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $d['username'])) {
        $errors[] = 'Username must be 3 to 50 characters: letters, digits, dot, dash or underscore.';
    }
    if ($d['email'] !== '' && (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($d['email']) > 120)) {
        $errors[] = 'Email address is not valid.';
    }
    if ($d['phone'] !== '' && !preg_match('/^[0-9+\s-]{7,20}$/', $d['phone'])) {
        $errors[] = 'Phone number is not valid.';
    }

    if (!isset($roles[$d['role_id']])) {
        $errors[] = 'Choose a role.';
    }
    if (!in_array($d['status'], ['active', 'inactive'], true)) {
        $d['status'] = 'active';
    }

    // A password is required for a new user; when editing it is optional (empty = keep the old one)
    if ($existing === null || $password !== '' || $password2 !== '') {
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Password must be at least 8 characters with at least one letter and one digit.';
        } elseif ($password !== $password2) {
            $errors[] = 'The two passwords do not match.';
        }
    }

    return $errors;
}

function validate_login_input(string $username, string $password): ?string
{
    if ($username === '' || $password === '') {
        return 'Enter your username and password.';
    }
    return null;
}
