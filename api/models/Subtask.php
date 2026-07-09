<?php
declare(strict_types=1);

class Subtask
{
    public static function findByTask(int $taskId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM subtasks WHERE task_id = ? ORDER BY position ASC");
        $stmt->execute([$taskId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM subtasks WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(int $taskId, string $title): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO subtasks (task_id, title) VALUES (?, ?)");
        $stmt->execute([$taskId, $title]);
        return self::find((int)$db->lastInsertId());
    }

    public static function toggle(int $id): ?array
    {
        $sub = self::find($id);
        if (!$sub) return null;
        $db = Database::getInstance();
        $stmt = $db->prepare("UPDATE subtasks SET completed = ? WHERE id = ?");
        $stmt->execute([$sub['completed'] ? 0 : 1, $id]);
        return self::find($id);
    }

    public static function update(int $id, string $title): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("UPDATE subtasks SET title = ? WHERE id = ?");
        $stmt->execute([$title, $id]);
        return self::find($id);
    }

    public static function delete(int $id): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM subtasks WHERE id = ?");
        $stmt->execute([$id]);
    }
}
