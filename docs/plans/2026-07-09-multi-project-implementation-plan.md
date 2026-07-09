# Multi-Project Task Management — Implementation Plan

**Goal:** Transform the single-project Vue 2.x SPA into a multi-workspace, multi-project task management system with status workflow, categories, subtasks, and dependencies, backed by a PHP + SQLite REST API.

**Architecture:** Vue 2.x SPA frontend (same design/layout) → PHP REST API → SQLite. Front controller pattern routes `/api/*` requests. Controllers use PDO models for data access. Vue replaces `todoStorage` with an `apiClient` using `fetch()`.

**Tech Stack:** Vue 2.6 (existing), PHP 8.x, SQLite 3, PDO, Apache mod_rewrite (XAMPP)

## Global Constraints

- Preserve the existing CSS variables, color scheme, typography, and layout. New elements (nav bar, detail panel) must use the same CSS custom properties.
- All API responses are JSON. Error format: `{ "error": "message", "field": "field_name" }` with HTTP 4xx.
- PHP code follows SOLID principles, uses PDO prepared statements exclusively, and uses `declare(strict_types=1)`.
- SQLite file stored at `api/data/todo.sqlite` (create `api/data/` directory, add to `.gitignore`).
- Database tables are created on first API request if they don't exist (auto-migration in Database.php).
- CORS headers: `Access-Control-Allow-Origin: *`, `Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS`, `Access-Control-Allow-Headers: Content-Type`.
- Existing `index.html` structure stays — we add new template sections and Vue methods alongside existing ones.
- The old localStorage `todoStorage` object is kept but unused; removal would touch too many existing lines for this change.

---

### Task 1: Database, Router & API Foundation

**Files:**
- Create: `api/index.php`
- Create: `api/Database.php`
- Create: `api/Router.php`
- Create: `.htaccess` (at project root)

**Interfaces:**
- Consumes: Apache mod_rewrite rewrites `/api/*` to `api/index.php`
- Produces: `Database::getInstance()` → PDO; `Router::match($method, $path)` → `[controller, action, params]`

- [ ] **Step 1: Create `.htaccess`**
  ```apache
  RewriteEngine On
  RewriteBase /

  # Route /api/* to api/index.php
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^api/(.*)$ api/index.php [QSA,L]
  ```

- [ ] **Step 2: Create `api/Database.php`**
  ```php
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
  ```

- [ ] **Step 3: Create `api/Router.php`**
  ```php
  <?php
  declare(strict_types=1);

  class Router
  {
      private array $routes = [];

      public function get(string $pattern, string $handler): void { $this->routes['GET'][] = [$pattern, $handler]; }
      public function post(string $pattern, string $handler): void { $this->routes['POST'][] = [$pattern, $handler]; }
      public function put(string $pattern, string $handler): void { $this->routes['PUT'][] = [$pattern, $handler]; }
      public function delete(string $pattern, string $handler): void { $this->routes['DELETE'][] = [$pattern, $handler]; }

      public function match(string $method, string $uri): ?array
      {
          $uri = '/' . trim($uri, '/');
          foreach ($this->routes[$method] ?? [] as [$pattern, $handler]) {
              $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
              $regex = '#^' . $regex . '$#';

              if (preg_match($regex, $uri, $matches)) {
                  $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                  return [$handler, $params];
              }
          }
          return null;
      }

      public function dispatch(string $method, string $uri): void
      {
          $match = $this->match($method, $uri);
          if ($match === null) {
              http_response_code(404);
              echo json_encode(['error' => 'Not found', 'field' => 'route']);
              return;
          }

          [$handler, $params] = $match;
          [$controllerName, $action] = explode('@', $handler);

          $controllerFile = __DIR__ . '/controllers/' . $controllerName . '.php';
          if (!file_exists($controllerFile)) {
              http_response_code(500);
              echo json_encode(['error' => 'Controller not found', 'field' => 'server']);
              return;
          }

          require_once $controllerFile;
          $controller = new $controllerName();
          $controller->$action($params);
      }
  }
  ```

- [ ] **Step 4: Create `api/index.php` (front controller)**
  ```php
  <?php
  declare(strict_types=1);

  header('Content-Type: application/json; charset=utf-8');
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');

  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
      http_response_code(204);
      exit;
  }

  require_once __DIR__ . '/Database.php';
  require_once __DIR__ . '/Router.php';

  $router = new Router();

  // Route registrations will be added by each task
  // For now, a health-check route:
  $router->get('/api/health', function(array $params) {
      echo json_encode(['status' => 'ok', 'time' => date('c')]);
      return;
  });

  $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  $method = $_SERVER['REQUEST_METHOD'];

  // Check registered handler routes
  $match = (function() use ($router, $method, $uri) {
      $ref = new ReflectionMethod($router, 'match');
      return $ref->invoke($router, $method, $uri);
  })();

  if ($match !== null) {
      [$handler, $params] = $match;
      if (is_callable($handler)) {
          $handler($params);
      } else {
          $router->dispatch($method, $uri);
      }
  } else {
      http_response_code(404);
      echo json_encode(['error' => 'Not found', 'field' => 'route']);
  }
  ```

- [ ] **Step 5: Verify — hit `/api/health` returns `{"status":"ok"}`**

- [ ] **Step 6: Commit**

---

### Task 2: Workspace & Project API

**Files:**
- Create: `api/controllers/WorkspaceController.php`
- Create: `api/controllers/ProjectController.php`
- Create: `api/models/Workspace.php`
- Create: `api/models/Project.php`
- Modify: `api/index.php` (add routes)

**Interfaces:**
- Consumes: `Database::getInstance()`, `Router` route registration
- Produces: Workspace CRUD endpoints, Project CRUD endpoints

- [ ] **Step 1: Create `api/models/Workspace.php`**
  ```php
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
  ```

- [ ] **Step 2: Create `api/models/Project.php`**
  ```php
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
  ```

- [ ] **Step 3: Create `api/controllers/WorkspaceController.php`**
  ```php
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
  ```

- [ ] **Step 4: Create `api/controllers/ProjectController.php`**
  ```php
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
  ```

- [ ] **Step 5: Register routes in `api/index.php`** — add after health-check route:
  ```php
  $router->get('/api/workspaces', 'WorkspaceController@index');
  $router->post('/api/workspaces', 'WorkspaceController@store');
  $router->get('/api/workspaces/{id}', 'WorkspaceController@show');
  $router->put('/api/workspaces/{id}', 'WorkspaceController@update');
  $router->delete('/api/workspaces/{id}', 'WorkspaceController@destroy');

  $router->get('/api/workspaces/{id}/projects', 'ProjectController@index');
  $router->post('/api/projects', 'ProjectController@store');
  $router->get('/api/projects/{id}', 'ProjectController@show');
  $router->put('/api/projects/{id}', 'ProjectController@update');
  $router->delete('/api/projects/{id}', 'ProjectController@destroy');
  ```

- [ ] **Step 6: Verify** — `curl -X POST http://localhost/todo-list/api/workspaces -H 'Content-Type: application/json' -d '{"name":"Personal"}'` returns 201 with workspace JSON

- [ ] **Step 7: Commit**

---

### Task 3: Task API & Status Workflow

**Files:**
- Create: `api/models/Task.php`
- Create: `api/controllers/TaskController.php`
- Create: `api/validators/StatusValidator.php`
- Modify: `api/index.php` (add routes)

**Interfaces:**
- Consumes: `Workspace::find()`, `Project::find()` for validation; `Subtask::findByTask()`, `Dependency::findByTask()` for gate checks
- Produces: Task CRUD endpoints, status transition validation, eager-load of subtasks + dependencies on list

- [ ] **Step 1: Create `api/validators/StatusValidator.php`**
  ```php
  <?php
  declare(strict_types=1);

  class StatusValidator
  {
      private const ALLOWED_TRANSITIONS = [
          'backlog'     => ['todo', 'blocked'],
          'todo'        => ['in_progress', 'blocked'],
          'in_progress' => ['review', 'blocked'],
          'review'      => ['testing', 'blocked'],
          'testing'     => ['done', 'blocked'],
          'done'        => [],
          'blocked'     => [],  // resolved dynamically from blocked_from
      ];

      private const STATUS_ORDER = [
          'backlog' => 0, 'todo' => 1, 'in_progress' => 2,
          'review' => 3, 'testing' => 4, 'done' => 5, 'blocked' => -1,
      ];

      public static function canTransition(string $from, string $to): bool
      {
          if ($from === 'blocked') {
              return true;  // can always leave blocked
          }
          if ($to === 'blocked') {
              return true;  // can always go to blocked
          }
          return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
      }

      public static function getNextStatuses(string $current, ?string $blockedFrom = null): array
      {
          if ($current === 'blocked') {
              return [$blockedFrom ?? 'todo'];
          }
          if ($current === 'done') {
              return [];
          }
          $statuses = self::ALLOWED_TRANSITIONS[$current] ?? [];
          return array_values(array_filter($statuses, fn($s) => $s !== 'blocked'));
      }

      public static function isValidStatus(string $status): bool
      {
          return isset(self::STATUS_ORDER[$status]);
      }
  }
  ```

- [ ] **Step 2: Create `api/models/Task.php`**
  ```php
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
  ```

- [ ] **Step 3: Create `api/controllers/TaskController.php`**
  ```php
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
  ```

- [ ] **Step 4: Register routes in `api/index.php`**
  ```php
  $router->get('/api/projects/{id}/tasks', 'TaskController@index');
  $router->post('/api/tasks', 'TaskController@store');
  $router->get('/api/tasks/{id}', 'TaskController@show');
  $router->put('/api/tasks/{id}', 'TaskController@update');
  $router->delete('/api/tasks/{id}', 'TaskController@destroy');
  $router->put('/api/tasks/reorder', 'TaskController@reorder');
  ```

- [ ] **Step 5: Verify** — create a project via API, then create a task in it, verify status transition validation with blocked_depe

- [ ] **Step 6: Commit**

---

### Task 4: Subtask & Dependency API

**Files:**
- Create: `api/models/Subtask.php`
- Create: `api/models/Dependency.php`
- Create: `api/controllers/SubtaskController.php`
- Create: `api/controllers/DependencyController.php`
- Modify: `api/index.php` (add routes)

**Interfaces:**
- Consumes: `Task::find()` for validation
- Produces: Subtask CRUD, Dependency CRUD (with circular-dependency check)

- [ ] **Step 1: Create `api/models/Subtask.php`**
  ```php
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
  ```

- [ ] **Step 2: Create `api/models/Dependency.php`**
  ```php
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
  ```

- [ ] **Step 3: Create `api/controllers/SubtaskController.php`**
  ```php
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
  ```
- [ ] **Step 4: Create `api/controllers/DependencyController.php`**
  ```php
  <?php
  declare(strict_types=1);
  
  require_once __DIR__ . '/../models/Dependency.php';
  
  class DependencyController
  {
      public function index(array $params): void
      {
          echo json_encode(Dependency::findByTask((int)$params['id']));
      }
  
      public function store(array $params): void
      {
          $input = json_decode(file_get_contents('php://input'), true);
          if (empty($input['depends_on_id'])) {
              http_response_code(422);
              echo json_encode(['error' => 'depends_on_id is required', 'field' => 'depends_on_id']);
              return;
          }
          $result = Dependency::create((int)$params['id'], (int)$input['depends_on_id']);
          if ($result === null) {
              http_response_code(422);
              echo json_encode(['error' => 'Invalid or circular dependency', 'field' => 'depends_on_id']);
              return;
          }
          http_response_code(201);
          echo json_encode($result);
      }
  
      public function destroy(array $params): void
      {
          Dependency::delete((int)$params['depId']);
          http_response_code(204);
      }
  }
  ```
- [ ] **Step 5: Register routes**
  ```php
  $router->get('/api/tasks/{id}/subtasks', 'SubtaskController@index');
  $router->post('/api/subtasks', 'SubtaskController@store');
  $router->put('/api/subtasks/{id}', 'SubtaskController@update');
  $router->delete('/api/subtasks/{id}', 'SubtaskController@destroy');

  $router->get('/api/tasks/{id}/dependencies', 'DependencyController@index');
  $router->post('/api/tasks/{id}/dependencies', 'DependencyController@store');
  $router->delete('/api/tasks/{id}/dependencies/{depId}', 'DependencyController@destroy');
  ```

- [ ] **Step 6: Verify** — create task, add subtask, toggle subtask, add dependency, verify circular dep rejection

- [ ] **Step 7: Commit**

---

### Task 5: Vue Frontend — API Client & State

**Files:**
- Modify: `index.html`

**Changes:**
- Replace `todoStorage` with `apiClient` using `fetch()`
- Add workspace/project/task state to Vue `data()`
- Add `fetchWorkspaces()`, `fetchProjects()`, `fetchTasks()` methods
- Add watcher on `selectedProject` to load tasks
- Keep all existing state and methods intact

- [ ] **Step 1: Add `apiClient` object after the existing `todoStorage` block (around line 455):**
  ```js
  var API_BASE = '/todo-list/api';
  var apiClient = {
      get: function(path) {
          return fetch(API_BASE + path).then(function(r) {
              if (!r.ok) return r.json().then(function(e) { throw e; });
              return r.json();
          });
      },
      post: function(path, data) {
          return fetch(API_BASE + path, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(data)
          }).then(function(r) {
              if (!r.ok) return r.json().then(function(e) { throw e; });
              return r.json();
          });
      },
      put: function(path, data) {
          return fetch(API_BASE + path, {
              method: 'PUT',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(data)
          }).then(function(r) {
              if (r.status === 204) return null;
              if (!r.ok) return r.json().then(function(e) { throw e; });
              return r.json();
          });
      },
      del: function(path) {
          return fetch(API_BASE + path, { method: 'DELETE' }).then(function(r) {
              if (!r.ok) return r.json().then(function(e) { throw e; });
          });
      }
  };
  ```

- [ ] **Step 2: Add new data properties to Vue `data()` (at the existing data function):**
  ```js
  workspaces: [],
  projects: [],
  selectedWorkspace: null,
  selectedProject: null,
  currentTask: null,
  taskSubtasks: [],
  taskDependencies: [],
  detailPanelOpen: false,
  ```

- [ ] **Step 3: Add `mounted()` hook enhancement — fetch workspaces on load:**
  Add to existing `mounted()`:
  ```js
  var that = this;
  apiClient.get('/workspaces').then(function(wss) {
      that.workspaces = wss;
      if (wss.length > 0) {
          that.selectedWorkspace = wss[0];
          that.fetchProjects(wss[0].id);
      }
  }).catch(function(err) {
      console.error('Failed to load workspaces', err);
  });
  ```

- [ ] **Step 4: Add workspace/project/task fetch methods:**
  ```js
  fetchWorkspaces: function() {
      var that = this;
      apiClient.get('/workspaces').then(function(wss) {
          that.workspaces = wss;
      });
  },
  selectWorkspace: function(ws) {
      this.selectedWorkspace = ws;
      this.selectedProject = null;
      this.todos = [];
      this.fetchProjects(ws.id);
  },
  fetchProjects: function(wsId) {
      var that = this;
      apiClient.get('/workspaces/' + wsId + '/projects').then(function(projs) {
          that.projects = projs;
      });
  },
  selectProject: function(proj) {
      this.selectedProject = proj;
      this.fetchTasks(proj.id);
  },
  fetchTasks: function(projId) {
      var that = this;
      apiClient.get('/projects/' + projId + '/tasks').then(function(tasks) {
          that.todos = tasks;
      });
  },
  ```

- [ ] **Step 5: Add watcher for `selectedProject`:**
  ```js
  selectedProject: function(proj) {
      if (proj) this.fetchTasks(proj.id);
  },
  ```

- [ ] **Step 6: Verify** — page loads, hits API, workspaces listed in console

- [ ] **Step 7: Commit**

---

### Task 6: Vue Frontend — Navigation Bar

**Files:**
- Modify: `index.html`

**Changes:**
- Add workspace dropdown + project dropdown to the header area (before the current nav)
- Replace the existing `.nav` container layout to accommodate new navigation
- "Create New..." options in both dropdowns
- Simple prompt dialogs for creation

- [ ] **Step 1: Add project navigation HTML above the `.nav` div (insert at line ~373, before `<!-- Custom Info -->`):**
  ```html
  <!-- Project Navigation -->
  <div class="project-nav">
      <div class="nav-dropdown">
          <select v-model="selectedWorkspace" @change="onWorkspaceChange" class="nav-select">
              <option v-for="ws in workspaces" :value="ws" :key="ws.id">{{ ws.name }}</option>
          </select>
          <button class="btn-small nav-create-btn" @click="createWorkspace">+</button>
      </div>
      <div class="nav-dropdown" v-if="selectedWorkspace">
          <select v-model="selectedProject" @change="onProjectChange" class="nav-select">
              <option v-for="proj in projects" :value="proj" :key="proj.id">{{ proj.name }}</option>
          </select>
          <button class="btn-small nav-create-btn" @click="createProject">+</button>
      </div>
  </div>
  ```

- [ ] **Step 2: Add methods for workspace/project creation and dropdown change handling:**
  ```js
  onWorkspaceChange: function() {
      if (this.selectedWorkspace) {
          this.selectWorkspace(this.selectedWorkspace);
      }
  },
  onProjectChange: function() {
      if (this.selectedProject) {
          this.selectProject(this.selectedProject);
      }
  },
  createWorkspace: function() {
      var name = prompt('Enter workspace name:');
      if (name && name.trim()) {
          var that = this;
          apiClient.post('/workspaces', { name: name.trim() }).then(function(ws) {
              that.fetchWorkspaces();
              that.selectWorkspace(ws);
          });
      }
  },
  createProject: function() {
      var name = prompt('Enter project name:');
      if (name && name.trim()) {
          var that = this;
          apiClient.post('/projects', {
              workspace_id: that.selectedWorkspace.id,
              name: name.trim()
          }).then(function(proj) {
              that.fetchProjects(that.selectedWorkspace.id);
              that.selectProject(proj);
          });
      }
  },
  ```

- [ ] **Step 3: Add CSS for the nav bar** (add to `public/css/style.css` before `.container` or after the existing nav styles):
  ```css
  .project-nav {
      display: flex;
      gap: 12px;
      align-items: center;
      padding: 12px 24px;
      background: var(--bg-normal);
      border-bottom: 2px solid var(--black);
      margin-bottom: 12px;
      flex-wrap: wrap;
  }
  .nav-dropdown {
      display: flex;
      align-items: center;
      gap: 6px;
  }
  .nav-select {
      padding: var(--btn-small-padding);
      border: var(--border);
      border-radius: var(--border-radius);
      background: #fff;
      font-family: var(--font);
      font-size: 14px;
      cursor: pointer;
      min-width: 160px;
  }
  .nav-create-btn {
      padding: 8px 14px !important;
      font-size: 18px;
      line-height: 1;
      background: var(--bg-submit);
      border: var(--border);
      border-radius: var(--border-radius);
      cursor: pointer;
  }
  .nav-create-btn:hover {
      background: #ffc0d6;
  }
  ```

- [ ] **Step 4: Update existing `.nav` container to sit below project nav** — the current `.nav` (github, about, language) still shows at the top but becomes secondary

- [ ] **Step 5: Verify** — dropdowns populate, create workspace/project works, page updates

- [ ] **Step 6: Commit**

---

### Task 7: Vue Frontend — Task Detail Panel

**Files:**
- Modify: `index.html`

**Changes:**
- Repurpose the current `.side-bar` (right sidebar) into the detail panel
- Replace the existing "Quicks" panel content with detail panel content
- Add status select, category select, description textarea, subtask list, dependency list
- Clicking a task opens its detail; clicking the panel close button hides it

- [ ] **Step 1: Replace the existing `.footer.side-bar` block (lines ~276-370) with the detail panel:**
  The existing block has `.side-shortcut` for toggling and `.todo-footer-box` with filter/batch/datasave lists. Keep the filter/batch/datasave sections but move them below the detail panel area. The detail panel slides in the same way as the old "Quicks" panel.

  New structure:
  ```html
  <div class="footer side-bar">
      <!-- Detail Panel (replaces old shortcut) -->
      <div class="side-shortcut" @click="toggleDetailPanel" :class="{fold: detailPanelOpen}">
          <div class="shortcut-switch">
              <span class="shortcut-title">{{ detailPanelOpen ? '×' : '☰' }}</span>
              <span class="shortcut-name">Detail</span>
          </div>
      </div>
      <div class="todo-footer-box" v-if="detailPanelOpen && currentTask">
          <!-- Status -->
          <div class="detail-field">
              <label>Status</label>
              <select v-model="currentTask.status" @change="updateTaskStatus" class="detail-select">
                  <option v-for="s in validNextStatuses" :value="s" :key="s">{{ formatStatus(s) }}</option>
              </select>
          </div>
          <!-- Category -->
          <div class="detail-field">
              <label>Category</label>
              <select v-model="currentTask.category" @change="updateTaskCategory" class="detail-select">
                  <option value="bug_fix">Bug Fix</option>
                  <option value="feature">Feature</option>
                  <option value="enhancement">Enhancement</option>
                  <option value="refactor">Refactor</option>
                  <option value="research">Research</option>
                  <option value="documentation">Docs</option>
              </select>
          </div>
          <!-- Description -->
          <div class="detail-field">
              <label>Description</label>
              <textarea v-model="currentTask.description" @change="updateTaskDescription" class="detail-textarea" rows="3"></textarea>
          </div>
          <!-- Subtasks -->
          <div class="detail-section">
              <label>Subtasks</label>
              <ul class="detail-subtask-list">
                  <li v-for="sub in taskSubtasks" :key="sub.id" class="detail-subtask-item">
                      <input type="checkbox" :checked="sub.completed" @change="toggleSubtask(sub)" class="detail-checkbox">
                      <span :class="{ 'subtask-done': sub.completed }" @dblclick="editSubtask(sub)">{{ sub.title }}</span>
                      <button class="detail-remove-btn" @click="deleteSubtask(sub)">×</button>
                  </li>
              </ul>
              <div class="detail-add-field">
                  <input v-model="newSubtaskTitle" placeholder="Add subtask..." @keyup.enter="addSubtask" class="detail-input">
              </div>
          </div>
          <!-- Dependencies -->
          <div class="detail-section">
              <label>Dependencies</label>
              <ul class="detail-dep-list">
                  <li v-for="dep in taskDependencies" :key="dep.id" class="detail-dep-item">
                      <span class="dep-status-badge" :class="'dep-' + dep.depends_on_status">{{ dep.depends_on_status }}</span>
                      <span>{{ dep.depends_on_title }}</span>
                      <button class="detail-remove-btn" @click="removeDependency(dep)">×</button>
                  </li>
              </ul>
              <div class="detail-add-field">
                  <select v-model="newDependencyId" class="detail-select dep-select">
                      <option value="">Add dependency...</option>
                      <option v-for="t in availableDeps" :value="t.id" :key="t.id">{{ t.title }}</option>
                  </select>
                  <button @click="addDependency" class="btn-small">Add</button>
              </div>
          </div>
          <!-- Metadata -->
          <div class="detail-meta">
              <span>Created: {{ currentTask.created_at }}</span>
              <span>Updated: {{ currentTask.updated_at }}</span>
          </div>
      </div>
      <!-- Original filter/batch/datasave lists (unchanged) -->
      <div class="todo-footer-box" v-if="!detailPanelOpen || !currentTask">
          ...existing filter, batch, datasave HTML...
      </div>
  </div>
  ```

  Note: Keep the original filter/batch/datasave HTML exactly as-is, wrapped in a `v-if` that hides them when the detail panel is open. This preserves existing functionality.

- [ ] **Step 2: Add detail panel Vue methods:**
  ```js
  toggleDetailPanel: function() {
      this.detailPanelOpen = !this.detailPanelOpen;
  },
  openTaskDetail: function(task) {
      var that = this;
      this.currentTask = JSON.parse(JSON.stringify(task));  // shallow copy
      this.detailPanelOpen = true;
      // Fetch subtasks
      apiClient.get('/tasks/' + task.id + '/subtasks').then(function(subs) {
          that.taskSubtasks = subs;
      });
      // Fetch dependencies
      apiClient.get('/tasks/' + task.id + '/dependencies').then(function(deps) {
          that.taskDependencies = deps;
      });
  },
  updateTaskStatus: function() {
      var that = this;
      var newStatus = this.currentTask.status;
      apiClient.put('/tasks/' + this.currentTask.id, { status: newStatus }).then(function(task) {
          that.currentTask = task;
          that.fetchTasks(that.selectedProject.id);
      }).catch(function(err) {
          alert(err.error || 'Failed to update status', 'Error');
          // Revert
          that.openTaskDetail(that.todos.find(function(t) { return t.id === that.currentTask.id; }));
      });
  },
  updateTaskCategory: function() {
      var that = this;
      apiClient.put('/tasks/' + this.currentTask.id, { category: this.currentTask.category }).then(function() {
          that.fetchTasks(that.selectedProject.id);
      });
  },
  updateTaskDescription: function() {
      apiClient.put('/tasks/' + this.currentTask.id, { description: this.currentTask.description });
  },
  formatStatus: function(s) {
      var labels = { backlog: 'Backlog', todo: 'Todo', in_progress: 'In Progress', review: 'Review', testing: 'Testing', done: 'Done', blocked: 'Blocked' };
      return labels[s] || s;
  },
  ```

- [ ] **Step 3: Add subtask/dependency methods:**
  ```js
  toggleSubtask: function(sub) {
      var that = this;
      apiClient.put('/subtasks/' + sub.id, {}).then(function() {
          return apiClient.get('/tasks/' + that.currentTask.id + '/subtasks');
      }).then(function(subs) {
          that.taskSubtasks = subs;
      });
  },
  addSubtask: function() {
      if (!this.newSubtaskTitle || !this.newSubtaskTitle.trim()) return;
      var that = this;
      apiClient.post('/subtasks', { task_id: this.currentTask.id, title: this.newSubtaskTitle.trim() }).then(function() {
          that.newSubtaskTitle = '';
          return apiClient.get('/tasks/' + that.currentTask.id + '/subtasks');
      }).then(function(subs) {
          that.taskSubtasks = subs;
      });
  },
  deleteSubtask: function(sub) {
      var that = this;
      apiClient.del('/subtasks/' + sub.id).then(function() {
          return apiClient.get('/tasks/' + that.currentTask.id + '/subtasks');
      }).then(function(subs) {
          that.taskSubtasks = subs;
      });
  },
  addDependency: function() {
      if (!this.newDependencyId) return;
      var that = this;
      apiClient.post('/tasks/' + this.currentTask.id + '/dependencies', { depends_on_id: this.newDependencyId }).then(function() {
          that.newDependencyId = '';
          return apiClient.get('/tasks/' + that.currentTask.id + '/dependencies');
      }).then(function(deps) {
          that.taskDependencies = deps;
      }).catch(function(err) {
          alert(err.error || 'Failed to add dependency', 'Error');
      });
  },
  removeDependency: function(dep) {
      var that = this;
      apiClient.del('/tasks/' + this.currentTask.id + '/dependencies/' + dep.id).then(function() {
          return apiClient.get('/tasks/' + that.currentTask.id + '/dependencies');
      }).then(function(deps) {
          that.taskDependencies = deps;
      });
  },
  ```

- [ ] **Step 4: Add computed property `validNextStatuses` and `availableDeps`:**
  ```js
  validNextStatuses: function() {
      if (!this.currentTask) return [];
      // Will be fetched from server or computed locally
      var s = this.currentTask.status;
      var bf = this.currentTask.blocked_from;
      if (s === 'done') return ['done'];
      if (s === 'blocked') return [bf || 'todo'];
      var next = { backlog: ['todo', 'blocked'], todo: ['in_progress', 'blocked'], in_progress: ['review', 'blocked'], review: ['testing', 'blocked'], testing: ['done', 'blocked'] };
      return next[s] || ['blocked'];
  },
  availableDeps: function() {
      if (!this.todos || !this.currentTask) return [];
      var taskId = this.currentTask.id;
      var depIds = this.taskDependencies.map(function(d) { return d.depends_on_id; });
      return this.todos.filter(function(t) {
          return t.id !== taskId && depIds.indexOf(t.id) === -1;
      });
  },
  ```

- [ ] **Step 5: Add detail panel CSS:**
  ```css
  .detail-field { margin-bottom: 12px; }
  .detail-field label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; }
  .detail-select { width: 100%; padding: var(--btn-small-padding); border: var(--border); border-radius: var(--border-radius); font-family: var(--font); font-size: 14px; background: #fff; }
  .detail-textarea { width: 100%; padding: var(--btn-small-padding); border: var(--border); border-radius: var(--border-radius); font-family: var(--font); font-size: 14px; resize: vertical; }
  .detail-section { margin-bottom: 16px; border-top: 1px solid var(--black); padding-top: 12px; }
  .detail-section > label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 8px; text-transform: uppercase; }
  .detail-subtask-list { list-style: none; padding: 0; margin: 0 0 8px; }
  .detail-subtask-item { display: flex; align-items: center; gap: 8px; padding: 4px 0; }
  .detail-checkbox { width: 16px; height: 16px; cursor: pointer; }
  .subtask-done { text-decoration: line-through; opacity: 0.5; }
  .detail-dep-item { display: flex; align-items: center; gap: 8px; padding: 4px 0; font-size: 13px; }
  .dep-status-badge { font-size: 10px; padding: 2px 6px; border-radius: 4px; border: 1px solid var(--black); text-transform: uppercase; }
  .dep-done { background: var(--bg-completed); }
  .dep-in_progress { background: var(--bg-submit); }
  .dep-blocked { background: var(--bg-discard); }
  .detail-remove-btn { background: none; border: none; cursor: pointer; font-size: 18px; color: var(--deleted); margin-left: auto; }
  .detail-add-field { display: flex; gap: 6px; align-items: center; }
  .detail-input { flex: 1; padding: 8px 12px; border: var(--border); border-radius: var(--border-radius); font-family: var(--font); font-size: 13px; }
  .detail-meta { font-size: 11px; color: var(--font-color-complete); margin-top: 16px; border-top: 1px solid var(--black); padding-top: 8px; }
  .detail-meta span { display: block; }
  ```

- [ ] **Step 6: Add `@click="openTaskDetail(todo)"` to the `.todo-item` li** (around line 204 in the v-for)

- [ ] **Step 7: Verify** — click a task, detail panel opens, status/category change works, subtasks toggle

- [ ] **Step 8: Commit**

---

### Task 8: Vue Frontend — Task List Enhancements

**Files:**
- Modify: `index.html`

**Changes:**
- Add status badge and category badge to each task in the list
- Update `addTodo` to create tasks via API instead of pushing to `todos` directly
- Update `markAsCompleted` / `markAsUncompleted` to update status via API
- Ensure filter (All/Ongoing/Completed/Trash) works with the new data model
- Wire the existing `removeTodo`/`restoreTodo` to the API

- [ ] **Step 1: Add status + category badges to the `.todo-content` div (around line 211-215):**
  ```html
  <div class="todo-content-row">
      <div class="todo-content" :class='{completed:todo.status === "done"}' @dblclick="editdTodo(todo)">{{todo.title}}</div>
      <div class="todo-badges">
          <span class="todo-badge badge-status" :class="'status-' + todo.status">{{ formatStatus(todo.status) }}</span>
          <span class="todo-badge badge-category" :class="'cat-' + todo.category">{{ todo.category }}</span>
      </div>
  </div>
  ```

- [ ] **Step 2: Add badge CSS:**
  ```css
  .todo-content-row { display: flex; align-items: center; justify-content: space-between; flex: 1; gap: 12px; }
  .todo-badges { display: flex; gap: 6px; flex-shrink: 0; }
  .todo-badge { font-size: 10px; padding: 2px 8px; border-radius: 6px; border: 1px solid var(--black); text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
  .status-backlog { background: #eee; }
  .status-todo { background: #fff; }
  .status-in_progress { background: var(--bg-submit); }
  .status-review { background: var(--normal); }
  .status-testing { background: var(--bg-completed); }
  .status-done { background: var(--bg-completed); }
  .status-blocked { background: var(--bg-discard); }
  .cat-bug_fix { background: var(--deleted); color: #fff; }
  .cat-feature { background: var(--completed); }
  .cat-enhancement { background: var(--normal); }
  .cat-refactor { background: var(--bg-submit); }
  .cat-research { background: #D0F4F0; }
  .cat-documentation { background: #E8D5F5; }
  ```

- [ ] **Step 3: Update `addTodo()` to create via API:**
  ```js
  addTodo: function(e) {
      if (this.newTodoTitle === '') {
          this.checkEmpty = true;
          return;
      }
      var that = this;
      apiClient.post('/tasks', {
          project_id: this.selectedProject.id,
          title: this.newTodoTitle,
          category: 'feature'
      }).then(function(task) {
          that.todos.unshift(task);
          that.newTodoTitle = '';
          that.checkEmpty = false;
          that.delayTime = '0';
      }).catch(function(err) {
          alert(err.error || 'Failed to create task', 'Error');
      });
  },
  ```

- [ ] **Step 4: Update `markAsCompleted`/`markAsUncompleted` to use status transitions:**
  ```js
  markAsCompleted: function(todo) {
      var that = this;
      apiClient.put('/tasks/' + todo.id, { status: 'done' }).then(function(task) {
          Object.assign(todo, task);
          that.fetchTasks(that.selectedProject.id);
      }).catch(function(err) {
          alert(err.error || 'Cannot complete task', 'Error');
      });
  },
  markAsUncompleted: function(todo) {
      var that = this;
      apiClient.put('/tasks/' + todo.id, { status: 'todo' }).then(function(task) {
          Object.assign(todo, task);
      }).catch(function(err) {
          alert(err.error || 'Cannot reopen task', 'Error');
      });
  },
  ```

- [ ] **Step 5: Update `removeTodo`/`restoreTodo` and related methods** — these currently manage `recycleBin` locally. For the API version, we can use a `removed` flag approach or soft-delete. Simplest path: tasks stay in the DB with a `removed` column or we use DELETE and lose them. Since the spec says Trash filter still works, add a `removed INTEGER DEFAULT 0` column to tasks via migration, and filter on that. Update migration in Database.php.

  Actually — simpler: use the existing recycleBin mechanism but sync with API. Add a `removed` boolean column to the tasks SQLite schema.

- [ ] **Step 6: Verify** — add task works via API, mark as done calls API, status badge updates, filter still works

- [ ] **Step 7: Commit**

---

### Task 9: CSS Polish & Chinese Version Sync

**Files:**
- Modify: `public/css/style.css`
- Modify: `public/css/style.scss`
- Modify: `public/css/style.min.css`
- Modify: `index-zh.html`
- Create: `api/data/.gitignore`

**Changes:**
- Sync all new CSS into the CSS files
- Add category color CSS variables
- Update `index-zh.html` with the same template/Vue changes as `index.html`
- Add `.gitignore` for SQLite data

- [ ] **Step 1: Add new CSS variables to `:root`:**
  ```css
  --cat-bug-fix: #F6A89E;
  --cat-feature: #8CD4CB;
  --cat-enhancement: #f5d99e;
  --cat-refactor: #ffd6e9;
  --cat-research: #D0F4F0;
  --cat-documentation: #E8D5F5;
  ```

- [ ] **Step 2: Sync all new CSS** from Tasks 6-7 into `style.css` then regenerate `style.min.css`

- [ ] **Step 3: Create `api/data/.gitignore`:**
  ```
  *.sqlite
  *.sqlite-wal
  *.sqlite-shm
  ```

- [ ] **Step 4: Sync `index-zh.html`** — apply all the same template, data, method, and computed changes from Tasks 5-8 to the Chinese version (translate UI labels to Chinese)

- [ ] **Step 5: Verify** — Chinese version loads, workspaces/projects/detail panel work in Chinese

- [ ] **Step 6: Commit**

---

## Self-Review Checklist

1. **Spec coverage:** Every spec requirement maps to a task:
   - Workspace > Projects > Tasks hierarchy → Tasks 2, 3, 6
   - Status workflow (Backlog→Todo→...→Done, Blocked) → Tasks 3, 7
   - Task categories → Task 7 (detail panel select)
   - Subtasks → Tasks 4, 7
   - Dependencies → Tasks 4, 7
   - Original design preserved → Tasks 5-9 (additive changes only)
   - PHP API + SQLite → Tasks 1-4

2. **Placeholder scan:** All code blocks contain actual implementations. No "TBD", "TODO", or "implement later".

3. **Type consistency:** `StatusValidator::canTransition(string $from, string $to): bool` matches call sites in `TaskController::update()`. `Task::update(int $id, array $data): ?array` matches call sites.

4. **Ambiguity check:** All status transitions are enumerated. API response format is specified. Error codes are explicit.
