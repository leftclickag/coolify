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

**No SSH on page load.** The monitor reads from Redis cache. SSH runs only in a background job.

Collection is **per server, not per service**: a single `CollectServerContainerStatsJob`
runs one `docker ps -a` + one `docker stats` for ALL of that server's containers, then writes
one cache entry per Compose project (service uuid). This same job also drives autoscaling, so
the two subsystems share one collection pass. The job is `ShouldBeUnique` keyed on the server,
so the scheduler and any number of open Monitor tabs on the same host coalesce into one run.

```
[every minute]  CheckServiceAutoscalingJob  (dispatcher, ShouldBeUnique)
                └─ for each reachable server with autoscale apps or a live monitor cache:
                   CollectServerContainerStatsJob::dispatch(serverId)
                      └─ docker ps -a            (1 SSH for the whole server)
                      └─ docker stats --no-stream (1 SSH for the whole server)
                      └─ docker exec coolify-proxy wget  (1 SSH, only if a service has FQDNs)
                      └─ Cache::put('service:container-stats:{uuid}', ..., 120s)  per service
                      └─ ServiceAutoscaler::evaluate() per autoscale app (throttled to ~1/min)

[page load]     Monitor::mount()
                └─ Cache::get('service:container-stats:{uuid}')  ← instant, no SSH

[Refresh / poll] Monitor::requestRefresh()
                └─ CollectServerContainerStatsJob::dispatch(serverId)  ← coalesced
                └─ wire:poll.8000ms='checkForFreshData'  ← UI polls cache until data lands
```

**Cache warm-up**: the dispatcher dispatches a collector for any server hosting a service whose
monitor cache key already exists (i.e. the Monitor tab has been opened before), so once a
service is viewed, subsequent visits show data immediately without any SSH wait.

---

## Key files

| File | Purpose |
|---|---|
| `app/Jobs/CollectServerContainerStatsJob.php` | Per-server stats collection (SSH); writes per-service cache + drives autoscaling |
| `app/Jobs/CheckServiceAutoscalingJob.php` | Scheduled dispatcher — fans out one collector per relevant server |
| `app/Services/ServiceAutoscaler.php` | Scaling decision + sampling/cooldown/throttle logic (unit-tested) |
| `app/Livewire/Project/Service/Monitor.php` | Component — reads cache, dispatches collector, summary computed property |
| `resources/views/livewire/project/service/monitor.blade.php` | Blade view — summary cards, per-service tables, Traefik section |
| `routes/web.php` | `project.service.monitor` route |
| `resources/views/livewire/project/service/configuration.blade.php` | Monitor nav item + route handler |

---

## Known limitations

| Limitation | Notes |
|---|---|
| Traefik API | Only works if `--api.insecure=true` (dev) or proxy is accessible internally. In production, the `wget` runs inside the proxy container so it always hits `localhost:8080`. |
| Per-server SSH | Each collector makes up to 3 `instant_remote_process` calls (ps, stats, optional Traefik) for the *whole* server. Collectors run one-per-server in parallel across queue workers. |
| Stats on stopped containers | `docker stats` only works on running containers; stopped replicas show `—` for CPU/MEM. |
