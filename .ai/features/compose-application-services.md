# Compose Application Per-Service UI

## Overview

When a Docker Compose file is imported from GitHub it creates an `Application` with `build_pack = 'dockercompose'`. Previously the only way to see individual container status was the Logs tab. This feature adds a `ApplicationDockerService` child model and a per-service card section to the Application configuration page, giving compose Applications the same service breakdown that template-based Services have always had.

---

## How It Works

### 1. Child records — `ApplicationDockerService`

Every time `Application::parse()` runs for a `dockercompose` buildpack (non-PR), the new helper `syncApplicationDockerServices()` is called. It:

1. Parses `docker_compose_raw` YAML
2. For each compose service: calls `isDatabaseImage()` to determine type (`application` or `database`)
3. Upserts one `ApplicationDockerService` row per service (creates, updates image/type, or restores soft-deleted)
4. Soft-deletes rows for services that are no longer in the compose file

### 2. Live status updates

`ComplexStatusCheck` (which runs on every server-push cycle) now calls two extra private methods when the application's `build_pack` is `dockercompose`:

- **`updateDockerServiceStatuses()`** — reads `com.docker.compose.service` labels from running containers and updates each matching `ApplicationDockerService.status`
- **`markDockerServicesExited()`** — sets all child rows to `exited` when the server is unreachable or no containers are found

### 3. Restart

`ApplicationDockerService::restart()` runs:

```bash
docker compose --project-name {app_uuid} --project-directory {workdir} restart {service_name}
```

This matches the project name used by `ApplicationDeploymentJob` when deploying compose Applications.

### 4. UI

In the Application configuration page (General tab), after the compose form, a "Services" section renders one `DockerServiceCard` per `ApplicationDockerService`. Each card shows:

- Service name and Docker image
- Current status (colour-coded border)
- Restart button (running containers only, requires `update` policy on the Application)

The `DockerServiceCard` Livewire component listens for `ApplicationStatusChanged` WebSocket events and refreshes its `ApplicationDockerService` model automatically.

---

## Database Schema

New table: `application_docker_services`

| Column | Type | Default | Purpose |
|---|---|---|---|
| `id` | bigint | — | Primary key |
| `uuid` | string | auto (CUID2) | Public identifier |
| `name` | string | — | Compose service name (`services.{name}`) |
| `human_name` | string, nullable | `null` | Friendly label (future use) |
| `description` | text, nullable | `null` | Description (future use) |
| `image` | string, nullable | `null` | Docker image from compose |
| `type` | enum('application','database') | `application` | Detected via `isDatabaseImage()` |
| `status` | string | `exited` | Live container status |
| `exclude_from_status` | boolean | `false` | Reserved for future exclusion logic |
| `last_online_at` | timestamp, nullable | `null` | Updated whenever status changes |
| `application_id` | FK → applications | — | Parent application (cascades on delete) |
| `deleted_at` | timestamp, nullable | `null` | Soft delete (restored when service reappears) |

---

## Key Files

| File | Purpose |
|---|---|
| `app/Models/ApplicationDockerService.php` | New model: child record per compose service |
| `database/migrations/2026_06_20_100000_create_application_docker_services_table.php` | Schema migration |
| `bootstrap/helpers/parsers.php` → `syncApplicationDockerServices()` | Creates/updates/deletes child rows from compose YAML |
| `app/Models/Application.php` → `parse()` | Calls sync after `applicationParser` (non-PR only) |
| `app/Models/Application.php` → `dockerServices()` | `hasMany(ApplicationDockerService)` relationship |
| `app/Actions/Shared/ComplexStatusCheck.php` | Updates per-service statuses from Docker container labels |
| `app/Livewire/Project/Application/DockerServiceCard.php` | Livewire component for per-service card |
| `resources/views/livewire/project/application/docker-service-card.blade.php` | Card blade |
| `resources/views/livewire/project/application/configuration.blade.php` | Shows "Services" section on General tab |

## Tests

| File | Covers |
|---|---|
| `tests/Feature/ApplicationDockerServicesTest.php` | `syncApplicationDockerServices`: creates rows, detects DB type, removes stale rows, updates image, no-op guards, soft-delete restore |

Run: `docker exec coolify ./vendor/bin/pest tests/Feature/ApplicationDockerServicesTest.php`

---

## Architecture Notes

This feature deliberately does **not** convert compose Applications into `Service` model instances. The `Application` model is kept as-is (including its git-pull deployment pipeline). `ApplicationDockerService` is a lightweight projection of the compose service list, used only for status display and individual restarts. It is not a replacement for `ServiceApplication` / `ServiceDatabase`, which belong to template-based `Service` resources and carry additional features (backups, per-service env vars, domain editing, settings page).

If full per-service management (individual env vars, domain assignment per service, etc.) is needed in the future, that would require adding git-source support to the `Service` model so compose-from-git can route through `serviceParser`.
