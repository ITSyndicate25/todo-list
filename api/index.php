<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Router.php';

// Initialize database schema on first request
Database::getInstance();

$router = new Router();

// Route registrations will be added by each task
// For now, a health-check route:
$router->get('/api/health', function(array $params) {
    echo json_encode(['status' => 'ok', 'time' => date('c')]);
    return;
});

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Check registered handler routes
$match = (function() use ($router, $method, $uri) {
    $ref = new ReflectionMethod($router, 'match');
    return $ref->invoke($router, $method, $uri);
})();

if ($match !== null) {
    [$handler, $params] = $match;
    if (is_callable($handler)) {
        $handler($params);
    } else {
        $router->dispatch($method, $uri);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found', 'field' => 'route']);
}
