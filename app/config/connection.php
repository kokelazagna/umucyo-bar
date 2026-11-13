<?php
// ============================================================================
// The one connection to MySQL, used by every model (same pattern as ILMS).
// ============================================================================

$conn = mysqli_connect('localhost', 'root', '', 'bar_management_db');
if (!$conn) {
    die('Connection down');
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

define('DB_NAME', 'bar_management_db');
