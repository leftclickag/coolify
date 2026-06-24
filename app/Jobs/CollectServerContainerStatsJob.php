<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\ServiceApplication;
use App\Services\ServiceAutoscaler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Collects container stats for an entire server in a single pass — one `docker ps -a`
 * plus one `docker stats --no-stream` for ALL containers — then:
 *   1. writes the per-service Monitor cache (one entry per Compose project / service uuid),
 *   2. drives autoscaling for that server's autoscale-enabled service applications.
 *
 * This replaces the old per-service PollServiceContainerStatsJob (N SSH calls) and the
 * autoscaler's separate per-app `docker stats` calls (another N). Both subsystems now share
 * this single per-server collection, and runs are parallelised across the queue (one job per
 * server) so a slow/hung host can't starve the others.
 *
 * Dispatched by:
 *   - CheckServiceAutoscalingJob (every minute, for servers with autoscale apps or live monitors)
 *   - Monitor::requestRefresh() (when a service Monitor page is open)
 *
 * ShouldBeUnique keys on the server id, so concurrent dispatches (the scheduler plus any number
 * of open Monitor tabs on the same server) coalesce into one run.
 */
class CollectServerContainerStatsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60;

    /** Lock auto-expires as a crash-safety cap; it is normally released on completion. */
    public int $uniqueFor = 120;

    /** Cache TTL — kept longer than the poll interval so a failed run does not blank the UI. */
    public const CACHE_TTL_SECONDS = 120;

    public const CACHE_KEY_PREFIX = 'service:container-stats:';

    public function __construct(public readonly int $serverId)
    {
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return 'collect-server-stats:'.$this->serverId;
    }

    public static function cacheKey(string $serviceUuid): string
    {
        return self::CACHE_KEY_PREFIX.$serviceUuid;
    }

    public static function pendingKey(string $serviceUuid): string
    {
        return self::CACHE_KEY_PREFIX.'pending:'.$serviceUuid;
    }

    public function handle(): void
    {
        $server = Server::find($this->serverId);
        if (! $server || ! $server->isFunctional()) {
            return;
        }

        try {
            // 1. One docker ps + one docker stats for the WHOLE server.
            $psRaw = instant_remote_process([
                "docker ps -a --format '{{json .}}' 2>/dev/null || true",
            ], $server, false);

            $rawContainers = collect(explode("\n", trim((string) $psRaw)))
                ->filter()
                ->map(fn ($l) => json_decode($l, true))
                ->filter(fn ($r) => is_array($r));

            if ($rawContainers->isEmpty()) {
                return;
            }

            $runningNames = $rawContainers
                ->filter(fn ($c) => str_contains(strtolower((string) data_get($c, 'State', '')), 'running'))
                ->pluck('Names')->filter()->values();

            $statsMap = collect();
            if ($runningNames->isNotEmpty()) {
                $statsRaw = instant_remote_process([
                    'docker stats --no-stream --format \'{{json .}}\' 2>/dev/null || true',
                ], $server, false);

                collect(explode("\n", trim((string) $statsRaw)))
                    ->filter()
                    ->map(fn ($l) => json_decode($l, true))
                    ->filter(fn ($r) => is_array($r))
                    ->each(fn ($s) => $statsMap->put(ltrim((string) data_get($s, 'Name', ''), '/'), $s));
            }

            // 2. Normalise + group by Compose project (= service uuid).
            $byService = [];
            $traefikNeeded = false;

            foreach ($rawContainers as $c) {
                $labels = collect(explode(',', (string) data_get($c, 'Labels', '')))
                    ->mapWithKeys(function ($pair) {
                        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');

                        return [trim($k) => trim($v)];
                    });

                $project = $labels->get('com.docker.compose.project');
                if (! $project) {
                    continue; // not a Compose-managed container
                }
                // Note: foreign Compose stacks on the same host also match. Their cache keys are
                // never read (no Service has that uuid — uuids are random CUID2s) and autoscaling
                // only acts on known autoscale apps, so they are harmless. We intentionally do NOT
                // gate on coolify.managed here: a wrong assumption about that label would blank
                // both the monitor and autoscaling, whereas a few unread cache keys cost nothing.

                if ($labels->get('traefik.enable') === 'true') {
                    $traefikNeeded = true;
                }

                $name = ltrim((string) data_get($c, 'Names', ''), '/');
                $stats = $statsMap->get($name, []);
                $statusStr = (string) data_get($c, 'Status', '');

                $health = null;
                if (str_contains($statusStr, '(healthy)')) {
                    $health = 'healthy';
                } elseif (str_contains($statusStr, '(unhealthy)')) {
                    $health = 'unhealthy';
                } elseif (str_contains($statusStr, '(starting)')) {
                    $health = 'starting';
                }

                $byService[$project][] = [
                    'id' => substr((string) data_get($c, 'ID', ''), 0, 12),
                    'name' => $name,
                    'service' => $labels->get('com.docker.compose.service', ''),
                    'replica' => (int) $labels->get('com.docker.compose.container-number', 1),
                    'image' => data_get($c, 'Image', ''),
                    'state' => data_get($c, 'State', 'unknown'),
                    'health' => $health,
                    'status_text' => $statusStr,
                    'cpu' => (float) str_replace('%', '', data_get($stats, 'CPUPerc', '0')),
                    'mem_usage' => data_get($stats, 'MemUsage', '—'),
                    'mem_percent' => (float) str_replace('%', '', data_get($stats, 'MemPerc', '0')),
                    'net_io' => data_get($stats, 'NetIO', '—'),
                    'pids' => (int) data_get($stats, 'PIDs', 0),
                    '_stats_found' => $stats !== [],
                ];
            }

            // 3. Traefik (best-effort) — one call per server, only when something uses the proxy.
            $traefikByUuid = $traefikNeeded
                ? $this->collectTraefik($server, array_keys($byService))
                : [];

            // 4. Write the per-service Monitor cache (same shape the Monitor blade expects).
            $polledAt = now()->toIso8601String();
            foreach ($byService as $uuid => $records) {
                $sorted = collect($records)->sortBy(['service', 'replica'])->values()->toArray();
                Cache::put(self::cacheKey($uuid), [
                    'containers' => $sorted,
                    'traefik' => $traefikByUuid[$uuid] ?? [],
                    'polled_at' => $polledAt,
                ], self::CACHE_TTL_SECONDS);
                Cache::forget(self::pendingKey($uuid));
            }

            // 5. Drive autoscaling for this server's autoscale-enabled apps from the same data.
            $this->runAutoscaling($byService);
        } catch (\Throwable $e) {
            Log::warning("CollectServerContainerStatsJob failed for server {$this->serverId}: {$e->getMessage()}");
        }
    }

    /**
     * @param  array<int, string>  $uuids
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function collectTraefik(Server $server, array $uuids): array
    {
        $traefikRaw = instant_remote_process([
            "docker exec coolify-proxy wget -qO- 'http://localhost:8080/api/http/services' 2>/dev/null || echo '__traefik_unavailable__'",
        ], $server, false);

        if (str_contains((string) $traefikRaw, '__traefik_unavailable__')) {
            return [];
        }

        $parsed = json_decode(trim((string) $traefikRaw), true);
        if (! is_array($parsed)) {
            return [];
        }

        $result = [];
        foreach ($parsed as $svc) {
            $svcName = (string) data_get($svc, 'name', '');
            foreach ($uuids as $uuid) {
                if (! str_contains($svcName, $uuid)) {
                    continue;
                }
                $servers = data_get($svc, 'loadBalancer.servers', []);
                $result[$uuid][$svcName] = [
                    'name' => $svcName,
                    'status' => data_get($svc, 'status', 'unknown'),
                    'servers_total' => count($servers),
                    'servers_up' => collect($servers)->where('status', 'UP')->count(),
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $byService  Keyed by service uuid.
     */
    private function runAutoscaling(array $byService): void
    {
        $apps = ServiceApplication::where('autoscale_enabled', true)
            ->with('service.destination.server')
            ->get()
            // Resolve the server in PHP — destination is a morphTo and cannot be filtered via whereHas.
            ->filter(fn (ServiceApplication $app) => optional(optional(optional($app->service)->destination)->server)->id === $this->serverId);

        if ($apps->isEmpty()) {
            return;
        }

        $autoscaler = app(ServiceAutoscaler::class);

        foreach ($apps as $app) {
            $uuid = $app->service?->uuid;
            if (! $uuid || ! isset($byService[$uuid])) {
                continue;
            }

            // Average across the running replicas of this Compose service only.
            $running = collect($byService[$uuid])
                ->where('service', $app->name)
                ->filter(fn ($c) => $c['_stats_found'] === true);

            if ($running->isEmpty()) {
                continue;
            }

            $autoscaler->evaluate(
                $app,
                (float) $running->avg('cpu'),
                (float) $running->avg('mem_percent'),
            );
        }
    }
}
