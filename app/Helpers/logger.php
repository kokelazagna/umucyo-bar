<?php
// Structured application logging. Logs are intentionally kept outside public/.

function app_log(string $level, string $message, array $context = []): void
{
    $level = strtolower($level);
    $allowedLevels = ['debug', 'info', 'warning', 'error', 'critical'];
    if (!in_array($level, $allowedLevels, true)) {
        $level = 'error';
    }

    $redacted = ['password', 'password_hash', 'token', 'csrf', 'cookie', 'authorization'];
    foreach ($context as $key => &$value) {
        if (in_array(strtolower((string) $key), $redacted, true)) {
            $value = '[redacted]';
        }
    }
    unset($value);

    $entry = [
        'timestamp' => gmdate('c'),
        'environment' => defined('APP_ENV') ? APP_ENV : 'unknown',
        'level' => $level,
        'message' => $message,
        'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
        'context' => $context,
    ];
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    $directory = BASE_PATH . '/storage/logs';

    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        error_log($line);
        return;
    }
    if (@file_put_contents($directory . '/application-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX) === false) {
        error_log($line);
    }
}
