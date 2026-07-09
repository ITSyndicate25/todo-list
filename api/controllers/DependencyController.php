<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Dependency.php';

class DependencyController
{
    public function index(array $params): void
    {
        echo json_encode(Dependency::findByTask((int)$params['id']));
    }

    public function store(array $params): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['depends_on_id'])) {
            http_response_code(422);
            echo json_encode(['error' => 'depends_on_id is required', 'field' => 'depends_on_id']);
            return;
        }
        $result = Dependency::create((int)$params['id'], (int)$input['depends_on_id']);
        if ($result === null) {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid or circular dependency', 'field' => 'depends_on_id']);
            return;
        }
        http_response_code(201);
        echo json_encode($result);
    }

    public function destroy(array $params): void
    {
        Dependency::delete((int)$params['depId']);
        http_response_code(204);
    }
}
