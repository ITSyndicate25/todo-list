<?php
declare(strict_types=1);

class Workspace
{
    public static function all(): array
    {
        $db = Database::getInstance();
        $stmt = $db->query("
            SELECT w.*, (SELECT COUNT(*) FROM projects WHERE workspace_id = w.id) AS project_count
            FROM workspaces w ORDER BY w.created_at DESC
        ");
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM workspaces WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(string $name, string $description = ''): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO workspaces (name, description) VALUES (?, ?)");
        $stmt->execute([$name, $description]);
        return self::find((int)$db->lastInsertId());
    }

    public static function update(int $id, string $name, string $description = ''): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("UPDATE workspaces SET name = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$name, $description, $id]);
        return self::find($id);
    }

    public static function delete(int $id): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM workspaces WHERE id = ?");
        $stmt->execute([$id]);
    }
}
