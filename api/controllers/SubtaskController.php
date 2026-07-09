<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Subtask.php';

class SubtaskController
{
    public function index(array $params): void
    {
        echo json_encode(Subtask::findByTask((int)$params['id']));
    }

    public function store(array $params): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['title'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Subtask title is required', 'field' => 'title']);
            return;
        }
        $sub = Subtask::create((int)$input['task_id'], $input['title']);
        http_response_code(201);
        echo json_encode($sub);
    }

    public function update(array $params): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        // If body is empty or no title field, treat as toggle
        if (empty($input['title'])) {
            $sub = Subtask::toggle((int)$params['id']);
        } else {
            $sub = Subtask::update((int)$params['id'], $input['title']);
        }
        if (!$sub) {
            http_response_code(404);
            echo json_encode(['error' => 'Subtask not found', 'field' => 'id']);
            return;
        }
        echo json_encode($sub);
    }

    public function destroy(array $params): void
    {
        Subtask::delete((int)$params['id']);
        http_response_code(204);
    }
}
