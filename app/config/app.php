<?php
// Application configuration shared by web requests, CLI tools and tests.

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
if (!defined('APP_PATH')) {
    define('APP_PATH', BASE_PATH . '/app');
}

function load_environment_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        if (strlen($value) >= 2 && $value[0] === $value[strlen($value) - 1]
            && ($value[0] === '"' || $value[0] === "'")) {
            $value = substr($value, 1, -1);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

function app_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}

function app_env_bool(string $key, bool $default = false): bool
{
    $value = app_env($key);
    if ($value === null) {
        return $default;
    }
    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
}

load_environment_file(BASE_PATH . '/.env');

defined('APP_ENV') || define('APP_ENV', app_env('APP_ENV', 'production'));
defined('APP_NAME') || define('APP_NAME', app_env('APP_NAME', 'Umucyo Bar - BSSBMS'));
defined('APP_TIMEZONE') || define('APP_TIMEZONE', app_env('APP_TIMEZONE', 'Africa/Kigali'));
defined('APP_CURRENCY') || define('APP_CURRENCY', app_env('APP_CURRENCY', 'RWF'));
defined('APP_SESSION_LIFETIME') || define('APP_SESSION_LIFETIME', (int) app_env('APP_SESSION_LIFETIME', '1800'));
defined('APP_DEBUG') || define('APP_DEBUG', app_env_bool('APP_DEBUG', false));

function configure_error_handling(): void
{
    static $configured = false;
    if ($configured) {
        return;
    }
    $configured = true;

    date_default_timezone_set(APP_TIMEZONE);
    error_reporting(E_ALL);
    ini_set('display_errors', APP_DEBUG ? '1' : '0');
    ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
    ini_set('log_errors', '1');

    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        if (function_exists('app_log')) {
            app_log('warning', 'PHP runtime warning', compact('severity', 'message', 'file', 'line'));
        }
        return false;
    });

    register_shutdown_function(function (): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
            && function_exists('app_log')) {
            app_log('critical', 'PHP fatal error', $error);
        }
    });
}
