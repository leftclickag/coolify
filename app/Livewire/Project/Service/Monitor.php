<?php

namespace App\Livewire\Project\Service;

use App\Jobs\PollServiceContainerStatsJob;
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

    public bool $autoRefresh = false;

    public ?string $polledAt = null;

    public bool $pollPending = false;

    public function mount(Service $service): void
    {
        $this->service = $service;
        $this->loadFromCache();
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
        $cached = Cache::get(PollServiceContainerStatsJob::cacheKey($this->service->uuid));

        if ($cached) {
            $this->containers = $cached['containers'] ?? [];
            $this->traefikServices = $cached['traefik'] ?? [];
            $this->polledAt = $cached['polled_at'] ?? null;
            $this->pollPending = false;
        }
    }

    /**
     * Dispatch a background poll job and set "pending" state so the UI shows
     * a spinner. The UI auto-refreshes via wire:poll until data arrives.
     */
    public function requestRefresh(): void
    {
        PollServiceContainerStatsJob::dispatch($this->service->id);
        $this->pollPending = true;
    }

    /**
     * Called by wire:poll while pollPending is true — checks if fresh data landed.
     */
    public function checkForFreshData(): void
    {
        $cached = Cache::get(PollServiceContainerStatsJob::cacheKey($this->service->uuid));

        if (! $cached) {
            return;
        }

        // Consider data "fresh" if it arrived after we requested the poll
        $polledAt = $cached['polled_at'] ?? null;
        if ($polledAt && now()->subSeconds(PollServiceContainerStatsJob::CACHE_TTL_SECONDS / 2)->isBefore($polledAt)) {
            $this->loadFromCache();
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
