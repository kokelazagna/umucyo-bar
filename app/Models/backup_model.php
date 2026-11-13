<?php
// ============================================================================
// Backup and restore of the database (database queries only).
// The backup / restore rules live in backup_service.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';

// Names of all tables in the database (customers, products, ...).
function get_backup_table_names($conn): array
{
    $query = 'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name';
    $result = executeQuery($query, [DB_NAME], $conn);
    $names = [];
    foreach ($result['data'] as $row) {
        $names[] = $row['name'] ?? $row['TABLE_NAME'];
    }
    return $names;
}

// The CREATE TABLE statement of one table.
function get_create_table(string $table, $conn)
{
    $query = 'SHOW CREATE TABLE `' . $table . '`';
    return fetchRaw($query, $conn);
}

// All rows of one table (for the INSERT statements of the backup).
function get_all_table_rows(string $table, $conn)
{
    $query = 'SELECT * FROM `' . $table . '`';
    return fetchRaw($query, $conn);
}

// Number of rows of one table (shown on the backup page).
function count_table_rows(string $table, $conn): int
{
    $result = fetchRaw('SELECT COUNT(*) AS total FROM `' . $table . '`', $conn);
    return (int)($result['data'][0]['total'] ?? 0);
}

// Runs one checked statement of a backup file (DROP / CREATE / INSERT / SET).
function run_backup_statement(string $statement, $conn)
{
    return fetchRaw($statement, $conn);
}
