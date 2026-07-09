<?php
declare(strict_types=1);

class Dependency
{
    public static function findByTask(int $taskId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT td.*, t.title AS depends_on_title, t.status AS depends_on_status
            FROM task_dependencies td
            JOIN tasks t ON t.id = td.depends_on_id
            WHERE td.task_id = ?
        ");
        $stmt->execute([$taskId]);
        return $stmt->fetchAll();
    }

    public static function findBlocking(int $taskId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT td.*, t.title AS task_title, t.status AS task_status
            FROM task_dependencies td
            JOIN tasks t ON t.id = td.task_id
            WHERE td.depends_on_id = ?
        ");
        $stmt->execute([$taskId]);
        return $stmt->fetchAll();
    }

    public static function create(int $taskId, int $dependsOnId): ?array
    {
        // Prevent self-dependency
        if ($taskId === $dependsOnId) return null;

        // Prevent circular dependency: check if depends_on_id already depends on task_id
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT COUNT(*) FROM task_dependencies WHERE task_id = ? AND depends_on_id = ?");
        $stmt->execute([$dependsOnId, $taskId]);
        if ($stmt->fetchColumn() > 0) return null;

        try {
            $stmt = $db->prepare("INSERT INTO task_dependencies (task_id, depends_on_id) VALUES (?, ?)");
            $stmt->execute([$taskId, $dependsOnId]);
            return self::findByTask($taskId);
        } catch (PDOException $e) {
            return null;  // UNIQUE constraint or FK violation
        }
    }

    public static function delete(int $id): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM task_dependencies WHERE id = ?");
        $stmt->execute([$id]);
    }
}
