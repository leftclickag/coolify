<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Monitor extends Component
{
    #[Locked]
    public Service $service;

    /** @var array<int, array<string, mixed>> */
    public array $containers = [];

    /** @var array<string, array<string, mixed>> Keyed by compose service name */
    public array $traefikServices = [];

    public bool $loaded = false;

    public bool $autoRefresh = false;

    public ?string $lastUpdated = null;

    public ?string $error = null;

    public function mount(Service $service): void
    {
        $this->service = $service;
    }

    public function getListeners(): array
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceStatusChanged" => 'loadData',
        ];
    }

    /**
     * Fetch container status + stats from the server and Traefik routing info.
     * Called via wire:init and the Refresh button.
     */
    public function loadData(): void
    {
        $this->error = null;

        try {
            $server = $this->service->destination->server;

            if (! $server->isFunctional()) {
                $this->error = 'Server is not reachable.';
                $this->loaded = true;

                return;
            }

            $uuid = $this->service->uuid;

            // ── 1. List all containers in this compose project ───────────────────
            $psRaw = instant_remote_process([
                "docker ps -a --filter label=com.docker.compose.project={$uuid} --format '{{json .}}' 2>/dev/null || true",
            ], $server, false);

            $psLines = collect(explode("\n", trim((string) $psRaw)))
                ->filter()
                ->map(fn ($l) => json_decode($l, true))
                ->filter(fn ($r) => is_array($r));

            if ($psLines->isEmpty()) {
                $this->containers = [];
                $this->loaded = true;
                $this->lastUpdated = now()->format('H:i:s');

                return;
            }

            // ── 2. Pull stats for running containers only ────────────────────────
            $runningIds = $psLines
                ->filter(fn ($c) => str_contains(strtolower(data_get($c, 'State', '')), 'running'))
                ->pluck('ID')
                ->filter()
                ->values();

            $statsMap = collect();
            if ($runningIds->isNotEmpty()) {
                $statsRaw = instant_remote_process([
                    'docker stats --no-stream --format \'{{json .}}\' '.$runningIds->implode(' ').' 2>/dev/null || true',
                ], $server, false);

                collect(explode("\n", trim((string) $statsRaw)))
                    ->filter()
                    ->map(fn ($l) => json_decode($l, true))
                    ->filter(fn ($r) => is_array($r))
                    ->each(fn ($s) => $statsMap->put(substr(data_get($s, 'ID', ''), 0, 12), $s));
            }

            // ── 3. Merge ps + stats rows ─────────────────────────────────────────
            $this->containers = $psLines->map(function ($c) use ($statsMap) {
                $shortId = substr(data_get($c, 'ID', ''), 0, 12);
                $stats = $statsMap->get($shortId, []);

                $state = data_get($c, 'State', 'unknown');
                $statusStr = data_get($c, 'Status', '');

                // Extract health from the Status string ("Up 2 hours (healthy)")
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
                    'id' => $shortId,
                    'name' => ltrim(data_get($c, 'Names', ''), '/'),
                    'service' => $labels->get('com.docker.compose.service', ''),
                    'replica' => (int) $labels->get('com.docker.compose.container-number', 1),
                    'image' => data_get($c, 'Image', ''),
                    'state' => $state,
                    'health' => $health,
                    'status_text' => $statusStr,
                    'cpu' => (float) str_replace('%', '', data_get($stats, 'CPUPerc', '0')),
                    'mem_usage' => data_get($stats, 'MemUsage', '—'),
                    'mem_percent' => (float) str_replace('%', '', data_get($stats, 'MemPerc', '0')),
                    'net_io' => data_get($stats, 'NetIO', '—'),
                    'pids' => (int) data_get($stats, 'PIDs', 0),
                ];
            })->sortBy(['service', 'replica'])->values()->toArray();

            // ── 4. Traefik service health (best-effort) ──────────────────────────
            $traefikRaw = instant_remote_process([
                "docker exec coolify-proxy wget -qO- 'http://localhost:8080/api/http/services' 2>/dev/null || echo '__traefik_unavailable__'",
            ], $server, false);

            $traefikData = [];
            if (! str_contains((string) $traefikRaw, '__traefik_unavailable__')) {
                $parsed = json_decode(trim((string) $traefikRaw), true);
                if (is_array($parsed)) {
                    foreach ($parsed as $svc) {
                        $name = data_get($svc, 'name', '');
                        // Match services that belong to this compose project
                        if (str_contains($name, $uuid)) {
                            $serversList = data_get($svc, 'loadBalancer.servers', []);
                            $traefikData[$name] = [
                                'name' => $name,
                                'status' => data_get($svc, 'status', 'unknown'),
                                'servers_total' => count($serversList),
                                'servers_up' => collect($serversList)->where('status', 'UP')->count(),
                            ];
                        }
                    }
                }
            }
            $this->traefikServices = $traefikData;

        } catch (\Throwable $e) {
            $this->error = 'Failed to load data: '.$e->getMessage();
        }

        $this->loaded = true;
        $this->lastUpdated = now()->format('H:i:s');
    }

    /** Summary counts derived from the loaded containers array. */
    public function getSummaryProperty(): array
    {
        $containers = collect($this->containers);

        return [
            'total' => $containers->count(),
            'running' => $containers->where('state', 'running')->count(),
            'starting' => $containers->filter(fn ($c) => in_array($c['state'], ['created', 'restarting']))->count(),
            'stopped' => $containers->filter(fn ($c) => in_array($c['state'], ['exited', 'dead']))->count(),
        ];
    }

    public function render()
    {
        return view('livewire.project.service.monitor', [
            'summary' => $this->summary,
        ]);
    }
}
