<?php

namespace App\Jobs;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Polls `docker ps` + `docker stats` for all containers in a service stack
 * and writes the result into cache.  The Monitor Livewire component reads
 * from this cache, so no SSH call is ever made during a page load.
 *
 * Dispatched by:
 *   - CheckServiceAutoscalingJob (every minute, for autoscale-enabled services)
 *   - ServiceMonitor::requestRefresh() (manual Refresh button in the UI)
 *   - Kernel scheduler (every minute for ALL services that have been viewed)
 */
class PollServiceContainerStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 30;

    /** Cache TTL — kept longer than the poll interval so a failed run does not blank the UI. */
    public const CACHE_TTL_SECONDS = 120;

    public const CACHE_KEY_PREFIX = 'service:container-stats:';

    public function __construct(public readonly int $serviceId) {}

    public static function cacheKey(string $serviceUuid): string
    {
        return self::CACHE_KEY_PREFIX.$serviceUuid;
    }

    public function handle(): void
    {
        $service = Service::find($this->serviceId);
        if (! $service) {
            return;
        }

        $server = $service->destination?->server;
        if (! $server || ! $server->isFunctional()) {
            return;
        }

        $uuid = $service->uuid;

        try {
            // 1. List all containers for this compose project
            $psRaw = instant_remote_process([
                "docker ps -a --filter label=com.docker.compose.project={$uuid} --format '{{json .}}' 2>/dev/null || true",
            ], $server, false);

            $containers = collect(explode("\n", trim((string) $psRaw)))
                ->filter()
                ->map(fn ($l) => json_decode($l, true))
                ->filter(fn ($r) => is_array($r));

            if ($containers->isEmpty()) {
                Cache::put(self::cacheKey($uuid), ['containers' => [], 'traefik' => [], 'polled_at' => now()->toIso8601String()], self::CACHE_TTL_SECONDS);

                return;
            }

            // 2. Stats for running containers only — match by Name (docker ps and docker stats
            // both report the Name field consistently; the ID field format differs between
            // the two commands in modern Docker versions, so it cannot be used as a join key).
            $runningNames = $containers
                ->filter(fn ($c) => str_contains(strtolower(data_get($c, 'State', '')), 'running'))
                ->pluck('Names')->filter()->values();

            $statsMap = collect();
            if ($runningNames->isNotEmpty()) {
                // docker stats accepts container names as positional args
                $statsRaw = instant_remote_process([
                    'docker stats --no-stream --format \'{{json .}}\' '.$runningNames->implode(' ').' 2>/dev/null || true',
                ], $server, false);

                collect(explode("\n", trim((string) $statsRaw)))
                    ->filter()
                    ->map(fn ($l) => json_decode($l, true))
                    ->filter(fn ($r) => is_array($r))
                    // Normalise name: docker stats prefixes with '/', docker ps may or may not
                    ->each(fn ($s) => $statsMap->put(ltrim((string) data_get($s, 'Name', ''), '/'), $s));
            }

            // 3. Merge and normalise
            $result = $containers->map(function ($c) use ($statsMap) {
                $name = ltrim((string) data_get($c, 'Names', ''), '/');
                $stats = $statsMap->get($name, []);
                $statusStr = data_get($c, 'Status', '');

                $health = null;
                if (str_contains($statusStr, '(healthy)')) {
                    $health = 'healthy';
                } elseif (str_contains($statusStr, '(unhealthy)')) {
                    $health = 'unhealthy';
                } elseif (str_contains($statusStr, '(starting)')) {
                    $health = 'starting';
                }

                $labels = collect(explode(',', data_get($c, 'Labels', '')))
                    ->mapWithKeys(function ($pair) {
                        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');

                        return [trim($k) => trim($v)];
                    });

                return [
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
            })->sortBy(['service', 'replica'])->values()->toArray();

            // 4. Traefik (best-effort)
            $traefikData = [];
            $traefikRaw = instant_remote_process([
                "docker exec coolify-proxy wget -qO- 'http://localhost:8080/api/http/services' 2>/dev/null || echo '__traefik_unavailable__'",
            ], $server, false);

            if (! str_contains((string) $traefikRaw, '__traefik_unavailable__')) {
                $parsed = json_decode(trim((string) $traefikRaw), true);
                if (is_array($parsed)) {
                    foreach ($parsed as $svc) {
                        $name = data_get($svc, 'name', '');
                        if (str_contains($name, $uuid)) {
                            $servers = data_get($svc, 'loadBalancer.servers', []);
                            $traefikData[$name] = [
                                'name' => $name,
                                'status' => data_get($svc, 'status', 'unknown'),
                                'servers_total' => count($servers),
                                'servers_up' => collect($servers)->where('status', 'UP')->count(),
                            ];
                        }
                    }
                }
            }

            Cache::put(self::cacheKey($uuid), [
                'containers' => $result,
                'traefik' => $traefikData,
                'polled_at' => now()->toIso8601String(),
            ], self::CACHE_TTL_SECONDS);

        } catch (\Throwable $e) {
            Log::warning("PollServiceContainerStatsJob failed for service {$uuid}: {$e->getMessage()}");
        } finally {
            // Always clear the pending flag so the UI knows it can dispatch the next poll
            Cache::forget(self::CACHE_KEY_PREFIX.'pending:'.$uuid);
        }
    }
}
