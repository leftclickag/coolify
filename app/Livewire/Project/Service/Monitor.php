<?php

namespace App\Livewire\Project\Service;

use App\Jobs\CollectServerContainerStatsJob;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Monitor extends Component
{
    #[Locked]
    public Service $service;

    /** @var array<int, array<string, mixed>> */
    public array $containers = [];

    /** @var array<string, array<string, mixed>> */
    public array $traefikServices = [];

    public ?string $polledAt = null;

    public bool $pollPending = false;

    public function mount(Service $service): void
    {
        $this->service = $service;
        $this->loadFromCache();
        // Kick off the first poll immediately on page load
        $this->requestRefresh();
    }

    public function getListeners(): array
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceStatusChanged" => 'requestRefresh',
        ];
    }

    /**
     * Read whatever the last poll wrote into cache — zero SSH, instant.
     */
    private function loadFromCache(): void
    {
        $cached = Cache::get(CollectServerContainerStatsJob::cacheKey($this->service->uuid));

        if ($cached) {
            $this->containers = $cached['containers'] ?? [];
            $this->traefikServices = $cached['traefik'] ?? [];
            $this->polledAt = $cached['polled_at'] ?? null;
        }
    }

    /**
     * Dispatch a background poll job and record when we dispatched it so we
     * don't stack up multiple overlapping polls.
     */
    public function requestRefresh(): void
    {
        $server = $this->service->destination?->server;
        if (! $server) {
            return;
        }

        // One per-server collector serves every service on the host; concurrent dispatches
        // (this page, other tabs, the scheduler) coalesce via the job's unique lock.
        CollectServerContainerStatsJob::dispatch($server->id);
        // Short TTL so the "Fetching…" spinner self-clears even if the run never reports back;
        // the collector also forgets this key as soon as it writes fresh data.
        Cache::put(self::pendingKey($this->service->uuid), now()->toIso8601String(), now()->addSeconds(15));
    }

    private static function pendingKey(string $uuid): string
    {
        return CollectServerContainerStatsJob::pendingKey($uuid);
    }

    /**
     * Called every 3s by wire:poll. Reads whatever is in cache and, if no
     * poll is currently running, dispatches a fresh one so the displayed
     * data stays live without requiring the user to click Refresh.
     */
    public function checkForFreshData(): void
    {
        $previousPollAt = Cache::get(self::pendingKey($this->service->uuid));
        $this->pollPending = $previousPollAt !== null;

        $this->loadFromCache();

        // If a poll isn't already in flight, kick off the next one.
        if (! $this->pollPending) {
            $this->requestRefresh();
            $this->pollPending = true;
        }
    }

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
