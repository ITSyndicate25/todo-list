<?php
declare(strict_types=1);

class Project
{
    public static function findByWorkspace(int $workspaceId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT p.*, (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) AS task_count
            FROM projects p WHERE p.workspace_id = ? ORDER BY p.created_at DESC
        ");
        $stmt->execute([$workspaceId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(int $workspaceId, string $name, string $description = '', string $color = '#f5d99e'): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO projects (workspace_id, name, description, color) VALUES (?, ?, ?, ?)");
        $stmt->execute([$workspaceId, $name, $description, $color]);
        return self::find((int)$db->lastInsertId());
    }

    public static function update(int $id, string $name, string $description = '', string $color = '#f5d99e'): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("UPDATE projects SET name = ?, description = ?, color = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$name, $description, $color, $id]);
        return self::find($id);
    }

    public static function delete(int $id): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->execute([$id]);
    }
}
