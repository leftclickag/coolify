# Autoscaling for Docker Compose Services

## Overview

Coolify can automatically scale individual service applications (containers within a Docker Compose stack) up or down based on live CPU and memory usage. Scaling is non-Swarm and works with any standard Docker Compose service.

---

## How It Works

### 1. Replica count and `deploy.replicas`

Each `ServiceApplication` has a `replicas` field (default `1`). When `replicas > 1`, the compose file is generated differently:

| `replicas` | Compose output |
|---|---|
| `1` (default) | `container_name: {service_name}-{service_uuid}` set; no `deploy` key |
| `> 1` | `container_name` omitted; `deploy.replicas: N` injected |

Docker Compose forbids static `container_name` when there are multiple replicas. With `deploy.replicas` the CLI auto-numbers containers: `{service_uuid}-{service_name}-1`, `-2`, `-3`, etc.

### 2. Scaling command

`ScaleServiceApplication` (`app/Actions/Service/ScaleServiceApplication.php`):

1. Clamps `replicas` between `autoscale_min_replicas` and `autoscale_max_replicas`
2. Updates `replicas` and `autoscale_last_scaled_at` in the DB
3. Calls `$service->parse()` — regenerates `docker_compose` with `deploy.replicas: N` (or back to `container_name` if going to 1)
4. Calls `$service->saveComposeConfigs()` — writes new compose to the server
5. Runs the scale command, targeting **only the named service**:

```bash
docker compose --project-directory {workdir} \
  -f {workdir}/docker-compose.yml \
  --project-name {service_uuid} \
  up -d --force-recreate \
  --scale {service_name}={replicas} \
  {service_name}
```

`--force-recreate` on the named service ensures the container is renamed correctly when scaling from 1 → N for the first time (the initial container has the old static name). Other services in the stack are never touched.

### 3. Autoscaling job

`CheckServiceAutoscalingJob` runs every minute via Laravel Scheduler.

For each `ServiceApplication` with `autoscale_enabled = true` on a reachable server:

1. **Container discovery** — uses Docker labels to find all replicas:
   ```bash
   docker ps \
     --filter label=com.docker.compose.service={name} \
     --filter label=com.docker.compose.project={uuid} -q
   ```
2. **Stats collection** — `docker stats --no-stream --format '{{json .}}'` on those container IDs
3. **Rolling-window smoothing** — the current snapshot (avg CPU/MEM across replicas) is pushed
   into a cache-backed rolling window (`autoscale:samples:{id}`, last `SAMPLE_WINDOW = 5` samples).
   The scaling decision uses the **moving average**, so a single transient spike does not trigger
   scaling. Samples are recorded even during cooldown so the average stays continuous.
4. **Cooldown check** — if `now() - autoscale_last_scaled_at < autoscale_cooldown_seconds`, skip
   (after recording the sample).
5. **Decision logic** (uses smoothed averages):
   - Scale **up** by 1 if `avgCpu >= cpuThreshold` OR `avgMem >= memThreshold`
   - Scale **down** by 1 if avg is below **50 %** of both thresholds (hysteresis) AND the window
     is full (`>= SAMPLE_WINDOW` samples) — avoids over-eager shrink right after startup
   - Neither if at min/max bounds or no threshold is configured
   - On a successful scale, the sample window is reset

### Host port handling when scaling

When `replicas > 1`, `serviceParser()` converts any host port bindings (`3000:80`) into
`expose` entries via `extractContainerPortForExpose()`. Host ports cannot be shared across
replicas, so the proxy (Traefik) load-balances across them instead. If the service has no
FQDN it stays internal-only (reachable by other containers on the Docker network). Scaling
back to 1 replica restores the original host port mapping automatically, because
`docker_compose_raw` remains the source of truth and the conversion only happens at parse
time when replicas > 1.

### Replica-aware status

The status pipeline (`GetContainersStatus` and `PushServerUpdateJob`) previously keyed each
container status by its `com.docker.compose.service` label, so multiple replicas of the same
service overwrote each other (last-writer-wins). Now each new replica status is merged into the
existing one via `ContainerStatusAggregator::mergeStatusStrings()`, so a failed replica is no
longer masked by a healthy one (e.g. running + exited => `degraded:unhealthy`). This preserves
the existing exclusion logic and `calculateExcludedStatusFromStrings()` contract.

---

## Database Schema

Fields added to `service_applications`:

| Column | Type | Default | Purpose |
|---|---|---|---|
| `replicas` | int | `1` | Current/desired replica count |
| `autoscale_enabled` | bool | `false` | Enable automatic scaling |
| `autoscale_min_replicas` | int | `1` | Never scale below this |
| `autoscale_max_replicas` | int | `5` | Never scale above this |
| `autoscale_cpu_threshold` | smallint, nullable | `null` | CPU % to trigger scale-up |
| `autoscale_memory_threshold` | smallint, nullable | `null` | Memory % to trigger scale-up |
| `autoscale_cooldown_seconds` | int | `300` | Minimum seconds between scale events |
| `autoscale_last_scaled_at` | timestamp, nullable | `null` | Tracks cooldown window |

---

## UI

In the Coolify interface: **Project → Service → [Application Name] → Settings → Autoscaling**

- **Manual scaling**: set a replica count and click "Apply Now"
- **Automatic scaling**: toggle + configure thresholds and bounds
- **Live monitoring**: the tab shows running replicas vs desired replicas and whether
  autoscaling is on. The running count is loaded async (`wire:init="loadReplicaStatus"`)
  via `docker ps --filter label=com.docker.compose.service=... --filter status=running | wc -l`,
  with a manual Refresh button.

## Logs

`app/Livewire/Project/Shared/Logs.php` enumerates container names per replica. When a
service application has `replicas > 1`, it lists the Docker Compose auto-numbered names
(`{uuid}-{name}-1`, `-2`, …) instead of the single static `{name}-{uuid}` name, so each
replica's logs are individually selectable.

---

## Limitations

| Limitation | Impact |
|---|---|
| Services with `container_name` explicitly set in the user's raw compose YAML | Coolify overrides it during parsing — the user-defined name is replaced by Coolify's when replicas > 1 |
| Host port bindings (e.g. `ports: ["3000:80"]`) | Automatically converted to `expose` when scaling > 1 (see "Host port handling"). Without a domain the service becomes internal-only while scaled |
| Volumes with `bind` mounts using the same host path | Multiple replicas writing to the same bind mount can cause data corruption depending on the application |
| `overlay` network driver / `deploy.mode: replicated` | Require Docker Swarm. Use `bridge` and omit `mode` for non-Swarm autoscaling |

---

## Key Files

| File | Purpose |
|---|---|
| `app/Actions/Service/ScaleServiceApplication.php` | Core scale action |
| `app/Jobs/CheckServiceAutoscalingJob.php` | Scheduled autoscaling check |
| `app/Livewire/Project/Service/Autoscaling.php` | UI component |
| `resources/views/livewire/project/service/autoscaling.blade.php` | Blade view |
| `bootstrap/helpers/parsers.php` → `serviceParser()` | Injects `deploy.replicas`, removes `container_name`, converts host ports to `expose` |
| `bootstrap/helpers/parsers.php` → `extractContainerPortForExpose()` | Helper to derive container port from a compose port definition |
| `app/Services/ContainerStatusAggregator.php` → `mergeStatusStrings()` | Merges replica statuses for replica-aware status |
| `app/Models/ServiceApplication.php` | Model with new autoscaling fields |
| `database/migrations/2026_06_20_000001_add_autoscaling_to_service_applications.php` | Schema migration |

## Tests

| File | Covers |
|---|---|
| `tests/Unit/ServiceAutoscalingTest.php` | Scale up/down decision logic, min/max bounds, hysteresis |
| `tests/Unit/ServiceScalingPortConversionTest.php` | `extractContainerPortForExpose()` port parsing |
| `tests/Unit/ContainerStatusMergeTest.php` | `mergeStatusStrings()` replica status merging |

Run: `docker exec coolify ./vendor/bin/pest tests/Unit/ServiceAutoscalingTest.php tests/Unit/ServiceScalingPortConversionTest.php tests/Unit/ContainerStatusMergeTest.php`
