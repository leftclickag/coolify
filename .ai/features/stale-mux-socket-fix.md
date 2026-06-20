# Stale SSH Mux Socket Fix

## Symptom

After a Coolify container restart (e.g. via the Update button, manual rebuild, or
container crash), the first few SSH-driven actions from the UI failed with:

```
SSH command failed with exit code: 255
```

This affected restarts, the monitor tab, the autoscaling refresh button, server
health checks, proxy operations, file storage edits, and basically anything that
synchronously calls `instant_remote_process()` during a Livewire request.

## Root cause

Coolify maintains long-lived SSH ControlMaster connections to managed servers for
performance. The mux socket file lives at:

```
/var/www/html/storage/app/ssh/mux/mux_<server-uuid>
```

This path is on a Docker volume, so it **survives container restarts**.

The SSH multiplexing helper had a self-healing mechanism in
`SshMultiplexingHelper::ensureMultiplexedConnection()`:
1. `masterConnectionExists()` runs `ssh -O check` against the socket
2. If that returns non-zero, `removeMuxFile()` is called and a new master is
   established via `establishNewMultiplexedConnection()`

**But `removeMuxFile()` only sent `ssh -O exit`** to gracefully close the master.
On a stale socket that command fails silently (no master to talk to), and the
socket file was never actually `unlink`'d from disk. SSH with `ControlMaster=auto`
then refuses to create a new master over the existing file, so the recovery
silently failed and every subsequent SSH call returned exit 255.

The retry handler (`SshRetryable`) considers exit 255 retryable, so each call
also burned 3 retry attempts with exponential backoff before giving up — making
the user-visible latency 10–30 seconds before the 500.

## Fix

`SshMultiplexingHelper::removeMuxFile()` now force-removes the socket file from
disk after the polite exit attempt, so the self-healing flow actually works:

```php
Process::run(self::muxControlCommand($server, 'exit'));
$muxSocket = self::muxSocket($server);
if ($muxSocket !== '' && $muxSocket !== '/') {
    Process::run("rm -f {$muxSocket}");
}
self::clearConnectionMetadata($server);
```

This is a single-line root-cause fix that resolves the issue for **every** SSH
call site in the codebase — no per-call-site patches needed.

## Scope of affected call sites (audit results)

These were all affected by the stale-socket bug and are now fixed transitively:

### Synchronous SSH in Livewire request path (would 500)
- `Models/ServiceApplication::restart()`
- `Models/ServiceDatabase::restart()` (via `remote_process` — queued, low-risk but still)
- `Models/LocalFileVolume` — ~8 calls (storage tab)
- `Models/Server` — proxy config, OS info, unmanaged container ops, restart
- `Models/Service` — `saveComposeConfigs`, cleanup
- `helpers/services.php::getFilesystemVolumesFromServer()` (called from `Index::mount`)
- `Livewire/Project/Shared/Terminal`
- `Livewire/Project/Application/Rollback`
- `Livewire/Project/Application/DeploymentNavbar` (cancel deployment)
- `Livewire/Project/Application/Previews` (delete preview)
- `Livewire/Project/Database/Postgresql/General`
- `Livewire/Project/Database/ImportForm`
- `Livewire/Server/Proxy/DynamicConfigurations`
- `Livewire/Server/Proxy/NewDynamicConfiguration`
- `Livewire/Server/Proxy/DynamicConfigurationNavbar`
- `Livewire/Server/Destinations`
- `Livewire/Destination/Show`
- `Livewire/SettingsBackup`
- `Livewire/Upgrade`
- `Livewire/Server/ValidateAndInstall`
- `Livewire/Boarding/Index`
- `Livewire/Server/CloudflareTunnel`
- `Livewire/Server/CaCertificate/Show`
- Fork-specific: `Monitor`, `Autoscaling`, `CheckServiceAutoscalingJob`,
  `PollServiceContainerStatsJob`

### Already-safe patterns (not affected)
- `remote_process()` — dispatches via `CoolifyTask` queue
- Anything in `app/Jobs/*` — runs async
- `throwError: false` calls — fail silently without 500

## Key file

| File | Change |
|---|---|
| `app/Helpers/SshMultiplexingHelper.php` | `removeMuxFile()` force-removes the socket file |
