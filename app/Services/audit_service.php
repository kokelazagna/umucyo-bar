<?php
// ============================================================================
// Audit trail: writes important events (logins, price changes, cancellations...)
// to the audit_logs table.
// ============================================================================

require_once __DIR__ . '/../Models/audit_model.php';
require_once __DIR__ . '/../Helpers/php_helper.php';

function audit_log($conn, ?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
{
    try {
        insert_audit_log($userId, $action, $entityType, $entityId, $details, $conn);
    } catch (Throwable $e) {
        // Never break the app because logging failed
    }
}
