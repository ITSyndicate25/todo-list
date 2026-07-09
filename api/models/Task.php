<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/StatusValidator.php';

class Task
{
    public static function findByProject(int $projectId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM tasks WHERE project_id = ? ORDER BY position ASC, created_at DESC");
        $stmt->execute([$projectId]);
        $tasks = $stmt->fetchAll();

        foreach ($tasks as &$task) {
            $task['subtasks'] = Subtask::findByTask((int)$task['id']);
            $task['dependencies'] = Dependency::findByTask((int)$task['id']);
        }
        return $tasks;
    }

    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(int $projectId, string $title, string $category = 'feature'): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO tasks (project_id, title, category) VALUES (?, ?, ?)");
        $stmt->execute([$projectId, $title, $category]);
        return self::find((int)$db->lastInsertId());
    }

    public static function update(int $id, array $data): ?array
    {
        $task = self::find($id);
        if (!$task) return null;

        $fields = [];
        $values = [];

        foreach (['title', 'description', 'status', 'category', 'position'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }

        // Handle blocked_from if status is changing
        if (isset($data['status']) && $data['status'] === 'blocked' && $task['status'] !== 'blocked') {
            $fields[] = "blocked_from = ?";
            $values[] = $task['status'];
        } elseif (isset($data['status']) && $data['status'] !== 'blocked') {
            $fields[] = "blocked_from = NULL";
        }

        if (empty($fields)) return $task;

        $fields[] = "updated_at = CURRENT_TIMESTAMP";
        $values[] = $id;

        $db = Database::getInstance();
        $stmt = $db->prepare("UPDATE tasks SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);

        return self::find($id);
    }

    public static function delete(int $id): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([$id]);
    }

    public static function reorder(array $order): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("UPDATE tasks SET position = ? WHERE id = ?");
        foreach ($order as $item) {
            $stmt->execute([$item['position'], $item['id']]);
        }
    }
}
