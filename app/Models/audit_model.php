<?php
// ============================================================================
// Audit log: reading the history of important events.
// (Writing to it is done by audit_log() in audit_service.)
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';

// $f: action, user (id), from, to
// $page and $perPage are kept so the controller call does not change,
// but they are ignored: the audit log returns ALL matching rows (no paging).
// Returns ['success','error','data','total'] - total is the number of rows returned.
function search_audit_logs(array $f, int $page, int $perPage, $conn)
{
    $query = 'SELECT a.*, u.full_name, u.username
              FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE 1 = 1';
    $params = [];
    if (($f['action'] ?? '') !== '') {
        $query .= ' AND a.action = ?';
        $params[] = $f['action'];
    }
    if (($f['user'] ?? 0) > 0) {
        $query .= ' AND a.user_id = ?';
        $params[] = $f['user'];
    }
    if (looks_like_date($f['from'] ?? '')) {
        $query .= ' AND a.created_at >= ?';
        $params[] = $f['from'] . ' 00:00:00';
    }
    if (looks_like_date($f['to'] ?? '')) {
        $query .= ' AND a.created_at <= ?';
        $params[] = $f['to'] . ' 23:59:59';
    }
    $query .= ' ORDER BY a.id DESC';

    $res = executeQuery($query, $params, $conn);
    return [
        'success' => $res['success'],
        'error'   => $res['error'],
        'data'    => $res['data'] ?? [],
        'total'   => count($res['data'] ?? []),
    ];
}

// Every action name found in the log (for the filter drop-down).
function get_audit_actions($conn): array
{
    $query = 'SELECT DISTINCT action FROM audit_logs ORDER BY action';
    return executeQuery($query, null, $conn)['data'];
}

function insert_audit_log(?int $userId, string $action, ?string $entityType, ?int $entityId, ?string $details, $conn)
{
    $query = 'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)';
    return executeQuery($query, [$userId, $action, $entityType, $entityId, $details, $_SERVER['REMOTE_ADDR'] ?? null], $conn);
}