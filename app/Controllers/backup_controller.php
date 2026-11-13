<?php
// ============================================================================
// Backup and restore of the database (owner only).
// ============================================================================

require_once __DIR__ . '/../models/backup_model.php';
require_once __DIR__ . '/../services/backup_service.php';

function handleBackupRequest($action, $data, $conn) {
    require_role([]);
    switch ($action) {
        case 'index':
            return backup_index($conn);
        case 'download':
            return backup_download($conn);
        case 'restore':
            return backup_restore($conn);
        default:
            http_response_code(404);
            echo '<h1>404 - Page not found</h1>';
    }
}

function backup_index($conn): void
{
    $tables = [];
    foreach (get_backup_table_names($conn) as $table) {
        $tables[] = ['name' => $table, 'rows' => count_table_rows($table, $conn)];
    }
    $tablesTotal = count($tables);
    $tablesPage  = current_page();
    $tables      = array_slice($tables, ($tablesPage - 1) * PER_PAGE, PER_PAGE);
    render('backup/index', ['title' => 'Backup & Restore', 'active' => 'backup', 'tables' => $tables, 'tablesTotal' => $tablesTotal, 'tablesPage' => $tablesPage]);
}

function backup_download($conn): void
{
    $username = (string)(current_user()['username'] ?? '');
    $sql      = build_database_backup($username, $conn);
    audit_log($conn, current_user_id(), 'backup_download', null, null, count(get_backup_table_names($conn)) . ' tables, ' . strlen($sql) . ' bytes');

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="bssbms_backup_' . date('Ymd_His') . '.sql"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

function backup_restore($conn): void
{
    if (post_text('confirm') !== 'RESTORE') {
        flash('danger', 'Type RESTORE in the confirmation box to restore a backup.');
        redirect('backup');
    }

    $file = $_FILES['backup'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        flash('danger', 'Choose a backup (.sql) file to upload.');
        redirect('backup');
    }
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'sql' || $file['size'] > 50 * 1024 * 1024) {
        flash('danger', 'The file must be a .sql backup of at most 50 MB.');
        redirect('backup');
    }

    $username = (string)(current_user()['username'] ?? '');
    try {
        $result = restore_database_backup((string)file_get_contents($file['tmp_name']), $username, $conn);
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
        redirect('backup');
    }

    // The restored data may not contain the current session user: sign in again.
    logout_user();
    flash('success', 'Backup restored (' . $result['statements'] . ' statements). A safety copy of the previous data was saved as storage/backups/'
        . $result['safety_file'] . '. Please sign in again.');
    redirect('auth/login');
}
