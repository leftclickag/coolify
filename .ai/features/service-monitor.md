# Service Monitor Tab

A live monitoring dashboard for all containers in a Docker Compose service stack.
Accessible at **Project → Service → Monitor** in the sidebar.

---

## What it shows

### Summary cards
Total containers / Running (green) / Starting (amber) / Stopped (red) — counts update on each refresh.

### Per-compose-service container table

One section per compose service name (e.g. "nginx", "worker"), showing all replicas:

| Column | Source |
|---|---|
| # | `com.docker.compose.container-number` label |
| Container | Full container name |
| Status | `docker ps` State field + health extracted from Status string |
| CPU | `docker stats --no-stream` CPUPerc, with inline bar (green→amber→red at 50%/80%) |
| Memory | MemUsage + MemPerc, same bar |
| Net I/O | NetIO from docker stats |
| PIDs | PIDs from docker stats |

Stats are only fetched for **running** containers; stopped/exited rows show `—`.

### Traefik routing table (best-effort)

Queries `http://localhost:8080/api/http/services` inside the proxy container:

```bash
docker exec coolify-proxy wget -qO- 'http://localhost:8080/api/http/services'
```

Filters services whose name contains the service UUID, then shows:
- Service name
- Status (enabled/disabled)
- Backends up / total

If the Traefik API is unreachable (e.g. production with `--api.insecure=false` and no external access), the section is silently omitted and a note is shown instead.

---

## Refresh behaviour

- **`wire:init="loadData"`** — data loads asynchronously after page render (no blocking)
- **Auto-refresh toggle** — checkbox enables `wire:poll.10000ms` for a live 10-second update cycle; off by default to avoid unnecessary SSH calls
- **Refresh button** — manual one-shot refresh
- **`ServiceStatusChanged` event listener** — automatically reloads when a deployment completes

---

## Data flow

```
Browser (Livewire) → loadData()
  └─ instant_remote_process: docker ps -a --filter label=com.docker.compose.project={uuid}
  └─ instant_remote_process: docker stats --no-stream (running containers only)
  └─ instant_remote_process: docker exec coolify-proxy wget .../api/http/services
```

All three calls are sequential and synchronous (`instant_remote_process`). The page renders immediately (loading state) and the data appears once all three resolve. This typically takes 2–4 seconds.

---

## Key files

| File | Purpose |
|---|---|
| `app/Livewire/Project/Service/Monitor.php` | Component — data fetching, merging, summary computed property |
| `resources/views/livewire/project/service/monitor.blade.php` | Blade view — summary cards, per-service tables, Traefik section |
| `routes/web.php` | `project.service.monitor` route |
| `resources/views/livewire/project/service/configuration.blade.php` | Monitor nav item + route handler |

---

## Known limitations

| Limitation | Notes |
|---|---|
| Traefik API | Only works if `--api.insecure=true` (dev) or proxy is accessible internally. In production, the `wget` runs inside the proxy container so it always hits `localhost:8080`. |
| Sequential SSH calls | Three sequential `instant_remote_process` calls — takes ~2–4s. Could be parallelised in future. |
| Stats on stopped containers | `docker stats` only works on running containers; stopped replicas show `—` for CPU/MEM. |
