<?php

namespace App\Jobs;

use App\Actions\Service\ScaleServiceApplication;
use App\Models\ServiceApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckServiceAutoscalingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $applications = ServiceApplication::where('autoscale_enabled', true)
            ->whereHas('service.destination.server', function ($query): void {
                $query->where('is_reachable', true);
            })
            ->get();

        foreach ($applications as $application) {
            $this->checkAndScale($application);
        }
    }

    private function checkAndScale(ServiceApplication $application): void
    {
        try {
            // Respect the cooldown window to avoid flapping
            if ($application->autoscale_last_scaled_at) {
                $elapsed = now()->diffInSeconds($application->autoscale_last_scaled_at);
                if ($elapsed < ($application->autoscale_cooldown_seconds ?? 300)) {
                    return;
                }
            }

            // At least one threshold must be configured
            if ($application->autoscale_cpu_threshold === null && $application->autoscale_memory_threshold === null) {
                return;
            }

            $service = $application->service;
            $server = $service->destination->server;
            $uuid = $service->uuid;
            $serviceName = $application->name;

            // Find all containers belonging to this compose service using Docker labels
            $containerIds = instant_remote_process([
                "docker ps --filter label=com.docker.compose.service={$serviceName} --filter label=com.docker.compose.project={$uuid} -q 2>/dev/null || true",
            ], $server, false);

            $containerIds = collect(explode("\n", trim((string) $containerIds)))->filter()->values();

            if ($containerIds->isEmpty()) {
                return;
            }

            // Pull per-container CPU & memory stats (single snapshot, non-streaming)
            $statsRaw = instant_remote_process([
                'docker stats --no-stream --format \'{{json .}}\' '.$containerIds->implode(' ').' 2>/dev/null || true',
            ], $server, false);

            $stats = collect(explode("\n", trim((string) $statsRaw)))
                ->filter()
                ->map(fn ($line) => json_decode($line, true))
                ->filter(fn ($item) => is_array($item) && isset($item['CPUPerc'], $item['MemPerc']));

            if ($stats->isEmpty()) {
                return;
            }

            $avgCpu = $stats->avg(fn ($s) => (float) str_replace('%', '', $s['CPUPerc']));
            $avgMem = $stats->avg(fn ($s) => (float) str_replace('%', '', $s['MemPerc']));

            $current = (int) ($application->replicas ?? 1);
            $min = (int) ($application->autoscale_min_replicas ?? 1);
            $max = (int) ($application->autoscale_max_replicas ?? 5);
            $cpuThreshold = $application->autoscale_cpu_threshold;
            $memThreshold = $application->autoscale_memory_threshold;

            $scaleUp = false;
            // Assume scale-down is safe unless a threshold says otherwise
            $holdScaleDown = false;

            if ($cpuThreshold !== null) {
                if ($avgCpu >= $cpuThreshold) {
                    $scaleUp = true;
                }
                // Hold scale-down if still above 50 % of the threshold
                if ($avgCpu >= ($cpuThreshold * 0.5)) {
                    $holdScaleDown = true;
                }
            }

            if ($memThreshold !== null) {
                if ($avgMem >= $memThreshold) {
                    $scaleUp = true;
                }
                if ($avgMem >= ($memThreshold * 0.5)) {
                    $holdScaleDown = true;
                }
            }

            $newReplicas = $current;
            if ($scaleUp && $current < $max) {
                $newReplicas = $current + 1;
            } elseif (! $holdScaleDown && $current > $min) {
                $newReplicas = $current - 1;
            }

            if ($newReplicas !== $current) {
                ScaleServiceApplication::run($application, $newReplicas);
                Log::info("Autoscaled service application [{$service->name}/{$serviceName}] {$current} → {$newReplicas} (CPU {$avgCpu}% / MEM {$avgMem}%)");
            }
        } catch (\Throwable $e) {
            Log::error("Autoscaling check failed for service application [{$application->name}]: {$e->getMessage()}");
        }
    }
}
