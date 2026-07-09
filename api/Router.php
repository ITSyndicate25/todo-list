<?php
declare(strict_types=1);

class Router
{
    private array $routes = [];

    public function get(string $pattern, string|callable $handler): void { $this->routes['GET'][] = [$pattern, $handler]; }
    public function post(string $pattern, string|callable $handler): void { $this->routes['POST'][] = [$pattern, $handler]; }
    public function put(string $pattern, string|callable $handler): void { $this->routes['PUT'][] = [$pattern, $handler]; }
    public function delete(string $pattern, string|callable $handler): void { $this->routes['DELETE'][] = [$pattern, $handler]; }

    public function match(string $method, string $uri): ?array
    {
        $uri = '/' . trim($uri, '/');
        foreach ($this->routes[$method] ?? [] as [$pattern, $handler]) {
            $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
            $regex = '#^' . $regex . '$#';

            if (preg_match($regex, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return [$handler, $params];
            }
        }
        return null;
    }

    public function dispatch(string $method, string $uri): void
    {
        $match = $this->match($method, $uri);
        if ($match === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found', 'field' => 'route']);
            return;
        }

        [$handler, $params] = $match;
        [$controllerName, $action] = explode('@', $handler);

        $controllerFile = __DIR__ . '/controllers/' . $controllerName . '.php';
        if (!file_exists($controllerFile)) {
            http_response_code(500);
            echo json_encode(['error' => 'Controller not found', 'field' => 'server']);
            return;
        }

        require_once $controllerFile;
        $controller = new $controllerName();
        $controller->$action($params);
    }
}
