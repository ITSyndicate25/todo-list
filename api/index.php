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
// Workspace routes
$router->get('/api/workspaces', 'WorkspaceController@index');
$router->post('/api/workspaces', 'WorkspaceController@store');
$router->get('/api/workspaces/{id}', 'WorkspaceController@show');
$router->put('/api/workspaces/{id}', 'WorkspaceController@update');
$router->delete('/api/workspaces/{id}', 'WorkspaceController@destroy');

// Project routes
$router->get('/api/workspaces/{id}/projects', 'ProjectController@index');
$router->post('/api/projects', 'ProjectController@store');
$router->get('/api/projects/{id}', 'ProjectController@show');
$router->put('/api/projects/{id}', 'ProjectController@update');
$router->delete('/api/projects/{id}', 'ProjectController@destroy');

// Task routes
$router->get('/api/projects/{id}/tasks', 'TaskController@index');
$router->post('/api/tasks', 'TaskController@store');
$router->get('/api/tasks/{id}', 'TaskController@show');
$router->put('/api/tasks/{id}', 'TaskController@update');
$router->delete('/api/tasks/{id}', 'TaskController@destroy');
$router->put('/api/tasks/reorder', 'TaskController@reorder');

// Subtask routes
$router->get('/api/tasks/{id}/subtasks', 'SubtaskController@index');
$router->post('/api/subtasks', 'SubtaskController@store');
$router->put('/api/subtasks/{id}', 'SubtaskController@update');
$router->delete('/api/subtasks/{id}', 'SubtaskController@destroy');

// Dependency routes
$router->get('/api/tasks/{id}/dependencies', 'DependencyController@index');
$router->post('/api/tasks/{id}/dependencies', 'DependencyController@store');
$router->delete('/api/tasks/{id}/dependencies/{depId}', 'DependencyController@destroy');

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
