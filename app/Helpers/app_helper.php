<?php
// ============================================================================
// Helper functions used everywhere in the application (views, controllers).
// ============================================================================

// ---------- URLs ----------

// Folder of the front controller, e.g. /bssbms3mod
function base_url(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (substr($script, -9) !== 'route.php') {
        // e.g. the PHP built-in server reports the request path instead of route.php
        $script = '/route.php';
    }
    return rtrim(dirname($script), '/');
}

// url('product/store') -> /bssbms3mod/product/store
function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

// asset('css/design.css') -> /bssbms3mod/public/assets/css/design.css
function asset(string $path): string
{
    return url('public/assets/' . ltrim($path, '/'));
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

// ---------- Output ----------

// Escape a value before printing it in HTML (prevents XSS).
// Use it on EVERY value printed in a view.
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Escaped value of a form field: field($user, 'phone') -> the user's phone, or '' when missing.
function field(array $row, string $key, $default = ''): string
{
    return e($row[$key] ?? $default);
}

// 1500 -> "1,500 RWF"
function rwf($amount): string
{
    return number_format((float)$amount, 0) . ' RWF';
}

// Shows a page: the view file is placed inside a layout file.
//   $view   = file in app/views, e.g. 'products/index'
//   $data   = variables the view can use, e.g. ['title' => 'Products']
//   $layout = file in app/views/layouts ('main' or 'auth')
function render(string $view, array $data = [], string $layout = 'main'): void
{
    extract($data, EXTR_SKIP);                 // turns ['title' => 'x'] into $title

    ob_start();
    require APP_PATH . '/views/' . $view . '.php';
    $content = ob_get_clean();                 // the page body, used by the layout

    require APP_PATH . '/views/layouts/' . $layout . '.php';
    exit;
}

// Answers a fetch() request (the POS checkout) with JSON and stops.
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ---------- Reading form and URL input ----------
// get_* read from the address bar (?id=5), post_* read from submitted forms.
// The *_text functions trim spaces and always give back a string.

function get_text(string $key, string $default = ''): string
{
    return (isset($_GET[$key]) && is_string($_GET[$key])) ? trim($_GET[$key]) : $default;
}

function post_text(string $key, string $default = ''): string
{
    return (isset($_POST[$key]) && is_string($_POST[$key])) ? trim($_POST[$key]) : $default;
}

// Like post_text() but WITHOUT trimming: used for passwords (spaces may be part of them).
function post_raw(string $key): string
{
    return (isset($_POST[$key]) && is_string($_POST[$key])) ? $_POST[$key] : '';
}

function get_int(string $key): int
{
    return (int)($_GET[$key] ?? 0);
}

function post_int(string $key): int
{
    return (int)($_POST[$key] ?? 0);
}

// ---------- Validation helpers ----------

// True for a real calendar date written as YYYY-MM-DD (2026-02-30 is NOT valid).
function is_valid_date(string $value): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return false;
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

// True when the text only LOOKS like YYYY-MM-DD (used for search filters).
function looks_like_date($value): bool
{
    return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
}

// The ways a customer or the bar can pay.
function payment_methods(): array
{
    return ['cash', 'mobile_money', 'bank'];
}

// ---------- Pagination ----------

// Rows shown per page on every list (change this one number to adjust them all).
define('PER_PAGE', 12);

// The page number requested in the address bar (?page=2). Page 1 by default.
function current_page(): int
{
    $p = (int)($_GET['page'] ?? 1);
    return $p > 0 ? $p : 1;
}

// Link to the same list (keeping every filter already typed) but on another page.
function page_url(int $page): string
{
    $qs = $_GET;
    $qs['page'] = $page;
    return parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?' . http_build_query($qs);
}

// Builds everything the shared pagination partial needs (pure logic, no echo).
//   $total   = all rows that match the filters
//   $page    = the page being shown (1 = first)
//   $perPage = rows per page (PER_PAGE)
function pagination_parts(int $total, int $page, int $perPage): array
{
    $perPage = max(1, $perPage);
    $pages   = max(1, (int)ceil($total / $perPage));
    $page    = min(max(1, $page), $pages);
    $from    = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
    $to      = min($page * $perPage, $total);

    // Page numbers to show: first, the ones around the current page, last,
    // with 0 marking a "..." gap (like the reference design).
    $nums = [1];
    for ($i = max(2, $page - 1); $i <= min($pages - 1, $page + 1); $i++) {
        $nums[] = $i;
    }
    if ($pages > 1) {
        $nums[] = $pages;
    }
    $nums = array_values(array_unique($nums));
    sort($nums);
    $parts = [];
    $prev  = 0;
    foreach ($nums as $n) {
        if ($n - $prev > 1) {
            $parts[] = 0;
        }
        $parts[] = $n;
        $prev = $n;
    }
    return ['pages' => $pages, 'page' => $page, 'from' => $from, 'to' => $to,
            'total' => $total, 'parts' => $parts];
}

// Renders the shared pagination partial under a list table (used by EVERY list).
// Hidden completely when the table has 12 rows or fewer.
function render_pagination(int $total, int $page, int $perPage, string $what = 'rows'): void
{
    if ($total <= $perPage) {
        return; // everything fits on one page - no pagination shown
    }
    $pag            = pagination_parts($total, $page, $perPage);
    $pag['what']    = $what;
    extract($pag, EXTR_SKIP);
    require APP_PATH . '/views/partials/pagination.php';
}

