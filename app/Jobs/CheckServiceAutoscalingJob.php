<?php

namespace App\Jobs;

use App\Models\Service;
use App\Models\ServiceApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduled every minute. Resolves the distinct, reachable servers that need a stats pass —
 * those hosting an autoscale-enabled service application, and those hosting a service whose
 * Monitor page has been viewed recently (a live cache entry) — and dispatches one
 * CollectServerContainerStatsJob per server.
 *
 * The heavy lifting (SSH, stats parsing, autoscaling decisions, Monitor cache writes) happens
 * in the per-server collector, so it parallelises across queue workers instead of running as
 * one long sequential job. Servers are resolved in PHP rather than via whereHas because
 * Service::destination is a morphTo (which whereHas cannot traverse).
 */
class CheckServiceAutoscalingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Crash-safety cap; the lock is normally released on completion. */
    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return 'check-service-autoscaling';
    }

    public function handle(): void
    {
        $serverIds = collect();

        // Servers hosting autoscale-enabled applications.
        ServiceApplication::where('autoscale_enabled', true)
            ->with('service.destination.server.settings')
            ->get()
            ->each(function (ServiceApplication $app) use ($serverIds): void {
                $server = $app->service?->destination?->server;
                if ($server?->isFunctional()) {
                    $serverIds->push($server->id);
                }
            });

        // Servers hosting a service whose Monitor was viewed recently (live cache entry),
        // so the page keeps refreshing without the user clicking Refresh.
        Service::with('destination.server.settings')
            ->get()
            ->each(function (Service $service) use ($serverIds): void {
                if (! Cache::has(CollectServerContainerStatsJob::cacheKey($service->uuid))) {
                    return;
                }
                $server = $service->destination?->server;
                if ($server?->isFunctional()) {
                    $serverIds->push($server->id);
                }
            });

        $serverIds->filter()->unique()->each(
            fn ($id) => CollectServerContainerStatsJob::dispatch((int) $id)
        );
    }
}
