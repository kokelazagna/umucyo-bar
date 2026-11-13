<?php
// ============================================================================
// Beverage categories (database queries only).
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';

function get_categories_with_counts($conn)
{
    $query = 'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
           FROM categories c ORDER BY c.created_at DESC, c.id DESC';
    return executeQuery($query, null, $conn);
}

// The categories list for the list view (paged). Manual count: the query has a subquery,
// so the automatic COUNT(*) rewrite in paged_query would produce invalid SQL.
function get_categories_page(int $page, int $perPage, $conn)
{
    $count = executeQuery('SELECT COUNT(*) AS total FROM categories', null, $conn);
    $total = (int)($count['data'][0]['total'] ?? 0);
    $offset = ($page - 1) * $perPage;
    $rows = executeQuery('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
           FROM categories c ORDER BY c.created_at DESC, c.id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset, null, $conn);
    return ['success' => $rows['success'], 'error' => $rows['error'], 'data' => $rows['data'], 'total' => $total];
}

function get_active_categories($conn)
{
    $query = "SELECT id, name FROM categories WHERE status = 'active' ORDER BY name";
    return executeQuery($query, null, $conn);
}

function find_category(int $id, $conn)
{
    $query = 'SELECT * FROM categories WHERE id = ?';
    return executeQuery($query, [$id], $conn);
}

function category_name_exists(string $name, int $excludeId, $conn): bool
{
    $query = 'SELECT COUNT(*) AS total FROM categories WHERE name = ? AND id <> ?';
    $result = executeQuery($query, [$name, $excludeId], $conn);
    return $result['success'] && (int)($result['data'][0]['total'] ?? 0) > 0;
}

function create_category(string $name, ?string $description, $conn)
{
    $query = 'INSERT INTO categories (name, description) VALUES (?, ?)';
    $result = executeQuery($query, [$name, $description !== '' ? $description : null], $conn);
    if ($result['success']) {
        $result['data'] = ['id' => mysqli_insert_id($conn)];
    }
    return $result;
}

function update_category(int $id, string $name, ?string $description, $conn)
{
    $query = 'UPDATE categories SET name = ?, description = ? WHERE id = ?';
    return executeQuery($query, [$name, $description !== '' ? $description : null, $id], $conn);
}

function set_category_status(int $id, string $status, $conn)
{
    $query = 'UPDATE categories SET status = ? WHERE id = ?';
    return executeQuery($query, [$status, $id], $conn);
}

// Number of products in each category, biggest first (shown on the dashboard).
function get_category_product_counts(int $limit, $conn)
{
    $query = 'SELECT c.name AS name, COUNT(p.id) AS total
           FROM categories c LEFT JOIN products p ON p.category_id = c.id
          GROUP BY c.id, c.name ORDER BY total DESC, c.name LIMIT ' . (int)$limit;
    return executeQuery($query, null, $conn);
}
