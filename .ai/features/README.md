# Leftclick Fork — Feature Documentation

Documentation for features added/changed in this fork of Coolify.

| Doc | Summary |
|---|---|
| [autoscaling.md](autoscaling.md) | Autoscaling for Docker Compose services — manual + automatic replica scaling based on CPU/memory, host-port→`expose` conversion, metric smoothing, replica-aware status, live UI monitoring |
| [custom-build-deployment.md](custom-build-deployment.md) | Installing/updating the fork by building the image locally from source (no registry), and how the built-in auto-update is intercepted |
| [service-monitor.md](service-monitor.md) | Live monitoring tab for all containers in a service stack — per-replica CPU/MEM bars, status, net I/O, Traefik backend health, auto-refresh |
| [stale-mux-socket-fix.md](stale-mux-socket-fix.md) | Root-cause fix for SSH exit-255 errors after Coolify container restart — `SshMultiplexingHelper::removeMuxFile()` now force-removes stale socket files |
