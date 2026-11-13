<?php
// ============================================================================
// Database helpers (same pattern as ILMS app/helpers/php_helper.php).
// Every SQL query in the project goes through executeQuery(): the values are
// always bound as ? placeholders, which protects the application from SQL
// injection. Every result has the same shape:
//     ['success' => bool, 'error' => bool, 'data' => mixed, 'msg' => string]
// ============================================================================

// Runs a paged SELECT: returns the rows of one page plus the total row count.
//   $query   = the full 'SELECT ... ORDER BY ...' (WITHOUT a LIMIT)
//   $params  = the same bound parameters for both the count and the page
//   $page    = 1-based page number, $perPage = rows per page
// Returns ['success' => bool, 'error' => bool, 'data' => rows, 'total' => full count].
function paged_query(string $query, ?array $params, int $page, int $perPage, $conn)
{
    $page    = max(1, $page);
    $perPage = max(1, $perPage);
    // The row count is the same query with COUNT(*) in place of the column list.
    $countQuery = preg_replace('/SELECT .*? FROM /s', 'SELECT COUNT(*) AS total FROM ', $query, 1);
    $count      = executeQuery($countQuery, $params, $conn);
    $total      = (int)($count['data'][0]['total'] ?? 0);
    $offset     = ($page - 1) * $perPage;
    $rows       = executeQuery($query . ' LIMIT ' . $perPage . ' OFFSET ' . $offset, $params, $conn);
    return ['success' => $rows['success'], 'error' => $rows['error'], 'data' => $rows['data'], 'total' => $total];
}

function prepared($query, $conn, $type = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        throw new Exception('prepare failed: ' . mysqli_error($conn));
    }
    if ($type && $params) {
        mysqli_stmt_bind_param($stmt, $type, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('execute failed: ' . mysqli_stmt_error($stmt));
    }
    return $stmt;
}

function getPreparedData($values)
{
    $vals = array_values($values);
    $type = '';
    foreach ($vals as $val) {
        if (is_null($val)) {
            $type .= 's'; // NULL values are treated as strings
        } elseif (is_int($val)) {
            $type .= 'i';
        } elseif (is_float($val)) {
            $type .= 'd';
        } elseif (is_bool($val)) {
            $type .= 'i'; // Boolean as integer
        } else {
            $type .= 's';
        }
    }
    return ['values' => $vals, 'types' => $type];
}

function executeQuery($query, $values, $conn)
{
    try {
        $operations = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];
        $queryType = '';
        foreach ($operations as $ops) {
            if (stripos($query, $ops) === 0) {
                $queryType = $ops;
                break;
            }
        }

        if (!in_array($queryType, $operations)) {
            return ['success' => false, 'error' => true, 'data' => null, 'msg' => 'unknown operation'];
        }

        if (!is_null($values)) {
            $data = getPreparedData($values);
            $tp = $data['types'];
            $vls = $data['values'];
        } else {
            $tp = null;
            $vls = null;
        }

        $stmt = prepared($query, $conn, $tp, $vls);
        if (!$stmt) {
            return ['success' => false, 'error' => true, 'data' => null, 'msg' => 'prepared stmt failed'];
        }

        switch ($queryType) {
            case 'SELECT':
                $result = mysqli_stmt_get_result($stmt);
                $rows = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
                return count($rows) > 0
                    ? ['success' => true, 'error' => false, 'data' => $rows, 'msg' => 'data found']
                    : ['success' => true, 'error' => false, 'data' => [], 'msg' => 'no record found'];

            case 'INSERT':
                return mysqli_stmt_affected_rows($stmt) > 0
                    ? ['success' => true, 'error' => false, 'data' => null, 'msg' => 'insert success']
                    : ['success' => false, 'error' => true, 'data' => null, 'msg' => 'insert failed'];

            case 'UPDATE':
                return mysqli_stmt_affected_rows($stmt) > 0
                    ? ['success' => true, 'error' => false, 'data' => null, 'msg' => 'update success']
                    : ['success' => true, 'error' => false, 'data' => null, 'msg' => 'No changes made'];

            case 'DELETE':
                return mysqli_stmt_affected_rows($stmt) > 0
                    ? ['success' => true, 'error' => false, 'data' => null, 'msg' => 'delete success']
                    : ['success' => false, 'error' => true, 'data' => null, 'msg' => 'delete failed'];
        }
    } catch (Exception $error) {
        return ['success' => false, 'error' => true, 'data' => null, 'msg' => 'Error thrown: ' . $error->getMessage()];
    }
}

// Runs one ready-made SQL statement that is not a normal SELECT/INSERT/
// UPDATE/DELETE (SHOW TABLES, SHOW CREATE TABLE...). Same result shape.
function fetchRaw($query, $conn)
{
    try {
        $result = mysqli_query($conn, $query);
        if ($result === false) {
            return ['success' => false, 'error' => true, 'data' => null, 'msg' => 'query failed: ' . mysqli_error($conn)];
        }
        if ($result === true) {
            return ['success' => true, 'error' => false, 'data' => [], 'msg' => 'query success'];
        }
        $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
        mysqli_free_result($result);
        return count($rows) > 0
            ? ['success' => true, 'error' => false, 'data' => $rows, 'msg' => 'data found']
            : ['success' => true, 'error' => false, 'data' => [], 'msg' => 'no record found'];
    } catch (Exception $error) {
        return ['success' => false, 'error' => true, 'data' => null, 'msg' => 'Error thrown: ' . $error->getMessage()];
    }
}

// Cleans a text input (used on the values coming from the user).
function validateInpt($input)
{
    if (is_null($input) || empty($input)) return '';
    $input = trim($input);
    $input = stripslashes($input);
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    $input = str_replace(chr(0), '', $input);
    return $input;
}

// ---------- Transactions: "all or nothing" ----------
// Usage:   db_begin($conn);
//          try { ...changes...; db_commit($conn); }
//          catch (Throwable $e) { db_rollback($conn); throw $e; }

function db_begin($conn): void
{
    mysqli_begin_transaction($conn);
    $GLOBALS['_tx_active'][spl_object_id($conn)] = true;
}

function db_commit($conn): void
{
    mysqli_commit($conn);
    unset($GLOBALS['_tx_active'][spl_object_id($conn)]);
}

// Safe to call even when no transaction is open.
function db_rollback($conn): void
{
    if (!empty($GLOBALS['_tx_active'][spl_object_id($conn)])) {
        mysqli_rollback($conn);
        unset($GLOBALS['_tx_active'][spl_object_id($conn)]);
    }
}

// A human message for a database error. In debug mode the real error is shown.
function db_error_message($conn, string $fallback): string
{
    return APP_DEBUG ? 'Database error: ' . mysqli_error($conn) : $fallback;
}
