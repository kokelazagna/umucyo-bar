<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This command is available only from the command line.');
}

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/config/connection.php';

mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(255) NOT NULL PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');

$files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

foreach ($files as $file) {
    $name = basename($file);
    $checksum = hash_file('sha256', $file);
    $statement = mysqli_prepare($conn, 'SELECT checksum FROM schema_migrations WHERE migration = ?');
    mysqli_stmt_bind_param($statement, 's', $name);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    $applied = mysqli_fetch_assoc($result) ?: null;

    if ($applied !== null) {
        if (!hash_equals($applied['checksum'], $checksum)) {
            fwrite(STDERR, "Migration checksum changed: {$name}\n");
            exit(1);
        }
        echo "Skipped {$name}\n";
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        fwrite(STDERR, "Migration is empty: {$name}\n");
        exit(1);
    }

    mysqli_begin_transaction($conn);
    try {
        if (!mysqli_multi_query($conn, $sql)) {
            throw new RuntimeException(mysqli_error($conn));
        }
        while (mysqli_more_results($conn)) {
            mysqli_next_result($conn);
            if (mysqli_errno($conn) !== 0) {
                throw new RuntimeException(mysqli_error($conn));
            }
        }
        $insert = mysqli_prepare($conn, 'INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)');
        mysqli_stmt_bind_param($insert, 'ss', $name, $checksum);
        mysqli_stmt_execute($insert);
        mysqli_commit($conn);
        echo "Applied {$name}\n";
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        app_log('error', 'Database migration failed', ['migration' => $name, 'error' => $error->getMessage()]);
        fwrite(STDERR, "Failed {$name}: {$error->getMessage()}\n");
        exit(1);
    }
}

echo "Migrations are current.\n";
