<?php
// Development router for PHP built-in server
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/api/#', $uri)) {
    require __DIR__ . '/index.php';
    return true;
}

$filePath = __DIR__ . '/..' . $uri;
if (is_file($filePath)) {
    return false;
}

$indexPath = __DIR__ . '/../index.html';
if (is_file($indexPath)) {
    require $indexPath;
    return true;
}

return false;
