<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Project.php';

class ProjectController
{
    public function index(array $params): void
    {
        echo json_encode(Project::findByWorkspace((int)$params['id']));
    }

    public function show(array $params): void
    {
        $proj = Project::find((int)$params['id']);
        if (!$proj) {
            http_response_code(404);
            echo json_encode(['error' => 'Project not found', 'field' => 'id']);
            return;
        }
        echo json_encode($proj);
    }

    public function store(array $params): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['name'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Project name is required', 'field' => 'name']);
            return;
        }
        $proj = Project::create(
            (int)$input['workspace_id'],
            $input['name'],
            $input['description'] ?? '',
            $input['color'] ?? '#f5d99e'
        );
        http_response_code(201);
        echo json_encode($proj);
    }

    public function update(array $params): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $proj = Project::find((int)$params['id']);
        if (!$proj) {
            http_response_code(404);
            echo json_encode(['error' => 'Project not found', 'field' => 'id']);
            return;
        }
        $updated = Project::update(
            (int)$params['id'],
            $input['name'] ?? $proj['name'],
            $input['description'] ?? $proj['description'],
            $input['color'] ?? $proj['color'] ?? '#f5d99e'
        );
        echo json_encode($updated);
    }

    public function destroy(array $params): void
    {
        Project::delete((int)$params['id']);
        http_response_code(204);
    }
}
