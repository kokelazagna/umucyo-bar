<?php
// Dev router for the PHP built-in server: serves static files directly,
// routes everything else through route.php (the .htaccess rewrite).
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($uri !== '/' && $uri !== '' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}
require __DIR__ . '/route.php';
