<?php
// ============================================================================
// Database backup and restore (business logic, owner only).
//
// A backup is one plain text .sql file written by build_database_backup():
// for every table it contains DROP TABLE, CREATE TABLE and INSERT statements,
// each on ONE line. That fixed format lets restore_database_backup() check every
// line before it runs anything. The queries live in backup_model.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';
require_once __DIR__ . '/../models/backup_model.php';
require_once __DIR__ . '/audit_service.php';

// The first line of every backup file made by this application.
function backup_header(): string
{
    return '-- BSSBMS BACKUP';
}

// Writes a value as SQL text: NULL stays NULL, everything else becomes a quoted, escaped string.
function sql_quote_value($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    $escape = ["\\" => "\\\\", "'" => "\\'", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "\x1a" => "\\Z"];
    return "'" . strtr((string)$value, $escape) . "'";
}

// Returns the complete backup as text.
function build_database_backup(string $createdBy, $conn): string
{
    $lines   = [];
    $lines[] = backup_header();
    $lines[] = '-- Database: ' . DB_NAME;
    $lines[] = '-- Created: ' . date('Y-m-d H:i:s') . ' by ' . $createdBy;
    $lines[] = 'SET FOREIGN_KEY_CHECKS=0;';

    foreach (get_backup_table_names($conn) as $table) {
        $create = get_create_table($table, $conn);
        $ddl    = str_replace(["\r\n", "\n", "\r"], ' ', (string)($create['data'][0]['Create Table'] ?? ''));

        $lines[] = 'DROP TABLE IF EXISTS `' . $table . '`;';
        $lines[] = $ddl . ';';

        $rows = get_all_table_rows($table, $conn)['data'];
        foreach (array_chunk($rows, 100) as $chunk) {
            $columns = '`' . implode('`,`', array_keys($chunk[0])) . '`';
            $values  = [];
            foreach ($chunk as $row) {
                $values[] = '(' . implode(',', array_map('sql_quote_value', array_values($row))) . ')';
            }
            $lines[] = 'INSERT INTO `' . $table . '` (' . $columns . ') VALUES ' . implode(',', $values) . ';';
        }
    }
    $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
    return implode("\n", $lines) . "\n";
}

// Saves backup text in the storage/backups folder and returns the file path.
function save_backup_file(string $sql, string $prefix): string
{
    $folder = BASE_PATH . '/storage/backups';
    if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
        throw new RuntimeException('The folder storage/backups could not be created, so no safety copy could be saved.');
    }
    $path = $folder . '/' . $prefix . '-' . date('Ymd-His') . '.sql';
    if (file_put_contents($path, $sql) === false) {
        throw new RuntimeException('The safety copy could not be written to ' . $path . '.');
    }
    return $path;
}

// Checks a backup file line by line and returns the statements to run.
// Only four kinds of statements are accepted, only for tables that exist, and the
// file must contain every table, so a wrong or damaged file cannot harm the database.
function read_backup_statements(string $sql, $conn): array
{
    $lines = preg_split('/\r\n|\n|\r/', $sql);
    if (!$lines || trim($lines[0]) !== backup_header()) {
        throw new RuntimeException('This is not a backup made by this application (the first line is wrong).');
    }

    $allowed    = get_backup_table_names($conn);
    $statements = [];
    $createdFor = [];

    foreach ($lines as $number => $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '--') === 0) {
            continue;
        }
        $where = 'line ' . ($number + 1);

        if (preg_match('/^SET FOREIGN_KEY_CHECKS=[01];$/', $line)) {
            $statements[] = $line;
            continue;
        }
        if (!preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([A-Za-z0-9_]+)`/', $line, $m) || substr($line, -1) !== ';') {
            throw new RuntimeException('The backup file has an unexpected statement on ' . $where . '. Nothing was changed.');
        }
        if (!in_array($m[2], $allowed, true)) {
            throw new RuntimeException('The backup file mentions an unknown table "' . $m[2] . '" on ' . $where . '. Nothing was changed.');
        }
        if ($m[1] === 'CREATE TABLE') {
            $createdFor[$m[2]] = true;
        }
        $statements[] = $line;
    }

    foreach ($allowed as $table) {
        if (empty($createdFor[$table])) {
            throw new RuntimeException('The backup file is incomplete: table "' . $table . '" is missing. Nothing was changed.');
        }
    }
    return $statements;
}

// Replaces ALL data with the content of a backup file.
// A safety copy of the current data is saved first. Because MySQL cannot undo
// DROP / CREATE TABLE, a restore is not "all or nothing": if it fails halfway, restore the
// safety copy (storage/backups/before-restore-*.sql) the same way.
function restore_database_backup(string $sql, string $restoredBy, $conn): array
{
    $statements = read_backup_statements($sql, $conn);   // checks everything before touching the database
    $safetyFile = save_backup_file(build_database_backup($restoredBy, $conn), 'before-restore');

    foreach ($statements as $statement) {
        run_backup_statement($statement, $conn);
    }
    run_backup_statement('SET FOREIGN_KEY_CHECKS=1;', $conn);

    audit_log($conn, null, 'backup_restore', null, null,
        'Database restored by ' . $restoredBy . '. Safety copy: ' . basename($safetyFile));
    return ['statements' => count($statements), 'safety_file' => basename($safetyFile)];
}
