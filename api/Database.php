<?php
declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;
    private const DB_PATH = __DIR__ . '/data/todo.sqlite';

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dir = dirname(self::DB_PATH);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            self::$instance = new PDO('sqlite:' . self::DB_PATH, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA foreign_keys=ON');
            self::runMigrations();
        }
        return self::$instance;
    }

    private static function runMigrations(): void
    {
        $db = self::$instance;
        $db->exec("
            CREATE TABLE IF NOT EXISTS workspaces (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL,
                description TEXT DEFAULT '',
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS projects (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                name         TEXT NOT NULL,
                description  TEXT DEFAULT '',
                color        TEXT DEFAULT '#f5d99e',
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS tasks (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id   INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                title        TEXT NOT NULL,
                description  TEXT DEFAULT '',
                status       TEXT DEFAULT 'backlog'
                    CHECK(status IN ('backlog','todo','in_progress','review','testing','done','blocked')),
                category     TEXT DEFAULT 'feature'
                    CHECK(category IN ('bug_fix','feature','enhancement','refactor','research','documentation')),
                position     INTEGER DEFAULT 0,
                blocked_from TEXT DEFAULT NULL,
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS subtasks (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id    INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
                title      TEXT NOT NULL,
                completed  INTEGER DEFAULT 0,
                position   INTEGER DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS task_dependencies (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id         INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
                depends_on_id   INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
                UNIQUE(task_id, depends_on_id)
            );
        ");
    }
}
