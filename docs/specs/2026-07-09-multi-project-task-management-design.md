# Multi-Project Task Management — Design Spec

**Date:** 2026-07-09
**Status:** Approved for implementation

## Overview

Extend the existing Vue 2.x SPA todo-list into a multi-project, multi-workspace task management system. The frontend remains Vue 2.x (preserving the existing design and layout). A PHP REST API with SQLite replaces localStorage for data persistence.

## Architecture

```
Browser (Vue 2.x SPA)
    │
    ├── /api/* ──→ PHP Front Controller (api/index.php)
    │                  └── Router
    │                       ├── WorkspaceController
    │                       ├── ProjectController
    │                       ├── TaskController
    │                       ├── SubtaskController
    │                       └── DependencyController
    │                              └── Models (SQLite via PDO)
    │
    └── public/css/ ──→ Static assets (CSS, images, Vue lib)
```

## Data Model

### SQLite Schema

```sql
CREATE TABLE workspaces (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    description TEXT DEFAULT '',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE projects (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name         TEXT NOT NULL,
    description  TEXT DEFAULT '',
    color        TEXT DEFAULT '#f5d99e',
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tasks (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id   INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title        TEXT NOT NULL,
    description  TEXT DEFAULT '',
    status       TEXT DEFAULT 'backlog'
                     CHECK(status IN ('backlog','todo','in_progress','review','testing','done','blocked')),
    category     TEXT DEFAULT 'feature'
                     CHECK(category IN ('bug_fix','feature','enhancement','refactor','research','documentation')),
    position     INTEGER DEFAULT 0,
    blocked_from TEXT DEFAULT NULL,    -- originating status when moved to 'blocked'
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE subtasks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id    INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
    title      TEXT NOT NULL,
    completed  INTEGER DEFAULT 0,
    position   INTEGER DEFAULT 0
);

CREATE TABLE task_dependencies (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id         INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
    depends_on_id   INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
    UNIQUE(task_id, depends_on_id)
);
```

### JSON Representation

```json
// Workspace
{ "id": 1, "name": "Work", "description": "", "project_count": 3, "created_at": "..." }

// Project
{ "id": 1, "workspace_id": 1, "name": "Laravel API", "description": "", "color": "#f5d99e",
  "task_count": 12, "created_at": "..." }

// Task
{ "id": 1, "project_id": 1, "title": "Build auth middleware", "description": "...",
  "status": "in_progress", "category": "feature", "position": 0,
  "subtasks": [{ "id": 1, "title": "Write tests", "completed": false, "position": 0 }],
  "dependencies": [{ "id": 2, "title": "Setup JWT library", "status": "done" }],
  "created_at": "..." }

// Subtask
{ "id": 1, "task_id": 1, "title": "Write tests", "completed": false, "position": 0 }

// Dependency
{ "id": 1, "task_id": 1, "depends_on_id": 2, "depends_on_title": "Setup JWT library",
  "depends_on_status": "done" }
```

## API Endpoints

Base: `/api/`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/workspaces` | List workspaces |
| POST | `/workspaces` | Create workspace |
| PUT | `/workspaces/{id}` | Update workspace |
| DELETE | `/workspaces/{id}` | Delete workspace (cascade) |
| GET | `/workspaces/{id}/projects` | List projects in workspace |
| POST | `/projects` | Create project |
| PUT | `/projects/{id}` | Update project |
| DELETE | `/projects/{id}` | Delete project (cascade) |
| GET | `/projects/{id}/tasks` | List tasks in project (with subtasks + deps) |
| POST | `/tasks` | Create task |
| PUT | `/tasks/{id}` | Update task |
| DELETE | `/tasks/{id}` | Delete task |
| PUT | `/tasks/reorder` | Batch update task positions |
| GET | `/tasks/{id}/subtasks` | List subtasks |
| POST | `/subtasks` | Create subtask |
| PUT | `/subtasks/{id}` | Update subtask |
| DELETE | `/subtasks/{id}` | Delete subtask |
| GET | `/tasks/{id}/dependencies` | List dependencies |
| POST | `/tasks/{id}/dependencies` | Add dependency |
| DELETE | `/tasks/{id}/dependencies/{depId}` | Remove dependency |

Error responses: `{ "error": "message", "field": "field_name" }` with HTTP 400/422/404.

## Status Workflow

### Allowed Transitions

```
backlog → todo → in_progress → review → testing → done
  ↑        ↑        ↑            ↑         ↑
  └────────┴────────┴────────────┴─────────┘
                       │
                   blocked ← (from any status)
                   blocked → (back to originating status, or 'todo')
```

### Rules

1. **Forward-only stepping**: A task can move to the next status in sequence, or stay. Skipping is rejected.
2. **`blocked` is universal**: Any status → `blocked`. Return from `blocked` restores the `blocked_from` status.
3. **Dependency gate to `in_progress`**: All dependencies must be `done`. If not → 422.
4. **Subtask gate to `done`**: All subtasks must be `completed`. If any open → 422.
5. **After `done`**: No further transitions (terminal status).

## UI Layout

### Top Navigation Bar (replaces current nav)

```
┌──────────────────────────────────────────────────────────┐
│ [Workspace ▼]  [Project ▼]          [GitHub] [About] [EN/中]│
└──────────────────────────────────────────────────────────┘
```

- Workspace dropdown lists all workspaces; selecting it loads its projects
- Project dropdown lists projects in the selected workspace; selecting it loads its tasks
- Both dropdowns have a "Create New..." option at the bottom

### Task List (unchanged from current design)

- Same styling: cream background, check circles, drag-reorder, inline editing
- Each task shows: title (bold), status badge (colored pill), category badge (colored pill)
- Clicking a task opens the detail panel

### Detail Panel (repurposed right sidebar)

Slides in from right (replaces current "Quicks" panel):

```
┌── DETAIL ──────────────┐
│ Status: [▼ in_progress] │
│ Category: [▼ feature]   │
│                         │
│ Description:            │
│ ┌─────────────────────┐│
│ │ Write auth middleware││
│ └─────────────────────┘│
│                         │
│ ── Subtasks ──          │
│ ☐ Write tests          │
│ ☐ Add route            │
│ [+ Add subtask]        │
│                         │
│ ── Dependencies ──      │
│ ⏳ Setup JWT library   │
│ [Add dependency...]    │
│                         │
│ Created: 2026-07-09     │
│ Updated: 2026-07-09     │
└─────────────────────────┘
```

### Filter Tabs (unchanged)

```
[All] [Ongoing] [Completed] [Trash]
```

- Filter applies within the currently selected project
- "Trash" shows tasks with `removed` flag (same pattern as current)

## Task Categories

Seven categories, each with a color:

| Category | Label | Color |
|----------|-------|-------|
| `bug_fix` | Bug Fix | `#F6A89E` (current deleted red) |
| `feature` | Feature | `#8CD4CB` (current completed teal) |
| `enhancement` | Enhancement | `#f5d99e` (current normal yellow) |
| `refactor` | Refactor | `#ffd6e9` (current submit pink) |
| `research` | Research | `#D0F4F0` (lighter teal) |
| `documentation` | Docs | `#E8D5F5` (lavender) |

## Subtasks

- Subtasks are simple checklist items under a task
- No nested subtasks (one level only)
- Parent task cannot be moved to `done` if any subtask is incomplete
- Position reorderable within the subtask list

## Task Dependencies

- A dependency links one task to another within the same project
- Circular dependencies are rejected on creation
- A task cannot move to `in_progress` until all its dependencies are `done`
- Dependencies are directional: A depends on B means B must be done before A can start

## PHP Backend Architecture

### File Structure

```
api/
├── index.php               ← Front controller: CORS headers, routes all /api/* requests
├── Database.php            ← Singleton SQLite PDO connection (lazy-initialized)
├── Router.php              ← Simple path + method router
├── controllers/
│   ├── WorkspaceController.php
│   ├── ProjectController.php
│   ├── TaskController.php
│   ├── SubtaskController.php
│   └── DependencyController.php
├── models/
│   ├── Workspace.php
│   ├── Project.php
│   ├── Task.php
│   ├── Subtask.php
│   └── Dependency.php
├── validators/
│   └── StatusValidator.php
└── data/
    └── todo.sqlite
```

### Design Principles

- PSR-4-like namespacing (`App\Controllers`, `App\Models`, `App\Validators`)
- Each controller extends a `BaseController` with JSON response helpers
- Models are thin data access objects using PDO prepared statements
- `StatusValidator` is a standalone class with no side effects — takes current status, target status, returns validation result
- SQLite database file stored in `api/data/` directory (gitignored)
- `.htaccess` rewrites `/api/*` to `/api/index.php`

## Vue Frontend Changes

### New/Modified Data Properties

```js
data: {
    workspaces: [],
    projects: [],
    selectedWorkspace: null,
    selectedProject: null,

    // Todo data structure (extended)
    todos: [],             // tasks for current project
    currentTask: null,     // task currently open in detail panel
    subtasks: [],
    dependencies: [],

    // Existing properties preserved
    newTodoTitle: '',
    editedTodo: null,
    intention: 'all',
    // ...
}
```

### New Methods

- `fetchWorkspaces()`, `selectWorkspace(ws)`, `createWorkspace(name)`
- `fetchProjects(wsId)`, `selectProject(proj)`, `createProject(name, wsId)`
- `fetchTasks(projId)`, `createTask(title, projId)`
- `updateTaskStatus(task, newStatus)` — calls API with validation
- `toggleSubtask(subtask)`, `addSubtask(taskId, title)`
- `addDependency(taskId, depTaskId)`, `removeDependency(depId)`

### API Client

Replace `todoStorage` (localStorage) with an `apiClient` object that uses `fetch()`:

```js
var apiClient = {
    get: function(path) { return fetch('/api' + path).then(r => r.json()); },
    post: function(path, data) { return fetch('/api' + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(r => r.json()); },
    put: function(path, data) { ... },
    delete: function(path) { ... }
};
```

### Watchers

- `selectedProject` — triggers `fetchTasks()`
- `todos` — triggers no localStorage save (API is source of truth)

## Error Handling

- API returns structured JSON errors: `{ "error": "Cannot move to 'in_progress': task #3 'Setup JWT' is not done", "field": "status" }`
- Vue catches these and shows the existing custom alert dialog
- Network errors show "Connection error, please check your server" alert
- 404 on load shows workspace selector (no crash)

## Migration Path

1. Create SQLite database and run migrations on first API call
2. Existing localStorage data: no automatic migration (users can export from old version and import via the existing import feature)
3. New users start with a default workspace "Personal" and a default project "My Tasks"

## Non-Goals

- No user authentication (remains single-user, local)
- No real-time sync (polling or WebSockets)
- No file attachments on tasks
- No calendar/timeline view
- No email notifications
