<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Task.php';
require_once __DIR__ . '/../models/Subtask.php';
require_once __DIR__ . '/../models/Dependency.php';
require_once __DIR__ . '/../validators/StatusValidator.php';

class TaskController
{
    public function index(array $params): void
    {
        echo json_encode(Task::findByProject((int)$params['id']));
    }

    public function show(array $params): void
    {
        $task = Task::find((int)$params['id']);
        if (!$task) {
            http_response_code(404);
            echo json_encode(['error' => 'Task not found', 'field' => 'id']);
            return;
        }
        $task['subtasks'] = Subtask::findByTask((int)$task['id']);
        $task['dependencies'] = Dependency::findByTask((int)$task['id']);
        echo json_encode($task);
    }

    public function store(array $params): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['title'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Task title is required', 'field' => 'title']);
            return;
        }
        $task = Task::create((int)$input['project_id'], $input['title'], $input['category'] ?? 'feature');
        http_response_code(201);
        echo json_encode($task);
    }

    public function update(array $params): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $task = Task::find((int)$params['id']);
        if (!$task) {
            http_response_code(404);
            echo json_encode(['error' => 'Task not found', 'field' => 'id']);
            return;
        }

        // Validate status transition
        if (isset($input['status']) && $input['status'] !== $task['status']) {
            $newStatus = $input['status'];

            if (!StatusValidator::isValidStatus($newStatus)) {
                http_response_code(422);
                echo json_encode(['error' => "Invalid status: $newStatus", 'field' => 'status']);
                return;
            }

            if (!StatusValidator::canTransition($task['status'], $newStatus)) {
                http_response_code(422);
                echo json_encode(['error' => "Cannot transition from '{$task['status']}' to '$newStatus'", 'field' => 'status']);
                return;
            }

            // Dependency gate: moving to 'in_progress'
            if ($newStatus === 'in_progress') {
                $deps = Dependency::findByTask((int)$task['id']);
                $incomplete = array_filter($deps, fn($d) => $d['depends_on_status'] !== 'done');
                if (!empty($incomplete)) {
                    $blockers = array_map(fn($d) => "'{$d['depends_on_title']}'", $incomplete);
                    http_response_code(422);
                    echo json_encode(['error' => 'Dependencies must be done: ' . implode(', ', $blockers), 'field' => 'status']);
                    return;
                }
            }

            // Subtask gate: moving to 'done'
            if ($newStatus === 'done') {
                $subtasks = Subtask::findByTask((int)$task['id']);
                $incomplete = array_filter($subtasks, fn($s) => !$s['completed']);
                if (!empty($incomplete)) {
                    http_response_code(422);
                    echo json_encode(['error' => 'All subtasks must be completed', 'field' => 'status']);
                    return;
                }
            }
        }

        $updated = Task::update((int)$params['id'], $input);
        echo json_encode($updated);
    }

    public function destroy(array $params): void
    {
        Task::delete((int)$params['id']);
        http_response_code(204);
    }

    public function reorder(array $params): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(422);
            echo json_encode(['error' => 'Order array required', 'field' => 'body']);
            return;
        }
        Task::reorder($input);
        http_response_code(204);
    }
}
