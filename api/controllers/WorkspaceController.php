<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Workspace.php';

class WorkspaceController
{
    public function index(array $params): void
    {
        echo json_encode(Workspace::all());
    }

    public function show(array $params): void
    {
        $ws = Workspace::find((int)$params['id']);
        if (!$ws) {
            http_response_code(404);
            echo json_encode(['error' => 'Workspace not found', 'field' => 'id']);
            return;
        }
        echo json_encode($ws);
    }

    public function store(array $params): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['name'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Workspace name is required', 'field' => 'name']);
            return;
        }
        $ws = Workspace::create($input['name'], $input['description'] ?? '');
        http_response_code(201);
        echo json_encode($ws);
    }

    public function update(array $params): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['name'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Workspace name is required', 'field' => 'name']);
            return;
        }
        $ws = Workspace::update((int)$params['id'], $input['name'], $input['description'] ?? '');
        if (!$ws) {
            http_response_code(404);
            echo json_encode(['error' => 'Workspace not found', 'field' => 'id']);
            return;
        }
        echo json_encode($ws);
    }

    public function destroy(array $params): void
    {
        Workspace::delete((int)$params['id']);
        http_response_code(204);
    }
}
