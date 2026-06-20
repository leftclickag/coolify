<?php

namespace App\Jobs;

use App\Actions\Service\ScaleServiceApplication;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Jobs\PollServiceContainerStatsJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckServiceAutoscalingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of recent samples to average when making a scaling decision.
     * The job runs every minute, so 5 samples ≈ a 5-minute moving average.
     */
    private const SAMPLE_WINDOW = 5;

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

        // Keep monitor cache warm for any service that has been viewed recently —
        // identified by the presence of a (possibly stale) monitor cache entry.
        // This means the monitor page always has data without SSH on load.
        Service::whereHas('destination.server', function ($q): void {
            $q->where('is_reachable', true);
        })->pluck('id')->each(function (int $id) {
            $service = Service::find($id);
            if ($service && Cache::has(PollServiceContainerStatsJob::cacheKey($service->uuid))) {
                PollServiceContainerStatsJob::dispatch($id);
            }
        });
    }

    private function checkAndScale(ServiceApplication $application): void
    {
        try {
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
            ], $server, throwError: false, disableMultiplexing: true);

            $containerIds = collect(explode("\n", trim((string) $containerIds)))->filter()->values();

            if ($containerIds->isEmpty()) {
                return;
            }

            // Pull per-container CPU & memory stats (single snapshot, non-streaming)
            $statsRaw = instant_remote_process([
                'docker stats --no-stream --format \'{{json .}}\' '.$containerIds->implode(' ').' 2>/dev/null || true',
            ], $server, throwError: false, disableMultiplexing: true);

            $stats = collect(explode("\n", trim((string) $statsRaw)))
                ->filter()
                ->map(fn ($line) => json_decode($line, true))
                ->filter(fn ($item) => is_array($item) && isset($item['CPUPerc'], $item['MemPerc']));

            if ($stats->isEmpty()) {
                return;
            }

            // Current snapshot averaged across the running replicas
            $snapshotCpu = (float) $stats->avg(fn ($s) => (float) str_replace('%', '', $s['CPUPerc']));
            $snapshotMem = (float) $stats->avg(fn ($s) => (float) str_replace('%', '', $s['MemPerc']));

            // Push the snapshot into a rolling window and read back the smoothed averages.
            // This keeps a single transient spike from immediately triggering a scale event.
            [$avgCpu, $avgMem, $samplesCount] = $this->recordAndSmooth($application->id, $snapshotCpu, $snapshotMem);

            // Respect the cooldown window to avoid flapping. We still recorded the sample
            // above so the moving average stays continuous during the cooldown.
            if ($application->autoscale_last_scaled_at) {
                $elapsed = now()->diffInSeconds($application->autoscale_last_scaled_at);
                if ($elapsed < ($application->autoscale_cooldown_seconds ?? 300)) {
                    return;
                }
            }

            // Require a full window before scaling down (avoids over-eager shrink right
            // after startup). Scaling up may happen as soon as the average crosses.
            $windowFull = $samplesCount >= self::SAMPLE_WINDOW;

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
            } elseif ($windowFull && ! $holdScaleDown && $current > $min) {
                $newReplicas = $current - 1;
            }

            if ($newReplicas !== $current) {
                ScaleServiceApplication::run($application, $newReplicas);
                $this->resetWindow($application->id);
                Log::info(sprintf(
                    'Autoscaled service application [%s/%s] %d → %d (avg CPU %.1f%% / MEM %.1f%% over %d samples)',
                    $service->name, $serviceName, $current, $newReplicas, $avgCpu, $avgMem, $samplesCount
                ));
            }
        } catch (\Throwable $e) {
            Log::error("Autoscaling check failed for service application [{$application->name}]: {$e->getMessage()}");
        }
    }

    /**
     * Append a sample to the rolling window cache and return the smoothed averages.
     *
     * @return array{0: float, 1: float, 2: int}  [avgCpu, avgMem, sampleCount]
     */
    private function recordAndSmooth(int $applicationId, float $cpu, float $mem): array
    {
        $key = "autoscale:samples:{$applicationId}";
        $samples = Cache::get($key, []);
        if (! is_array($samples)) {
            $samples = [];
        }

        $samples[] = ['cpu' => $cpu, 'mem' => $mem];
        // Keep only the most recent SAMPLE_WINDOW samples
        if (count($samples) > self::SAMPLE_WINDOW) {
            $samples = array_slice($samples, -self::SAMPLE_WINDOW);
        }

        // Cache for long enough to survive between minute-runs, refreshed each time.
        Cache::put($key, $samples, now()->addMinutes(self::SAMPLE_WINDOW * 3));

        $count = count($samples);
        $avgCpu = $count > 0 ? array_sum(array_column($samples, 'cpu')) / $count : $cpu;
        $avgMem = $count > 0 ? array_sum(array_column($samples, 'mem')) / $count : $mem;

        return [$avgCpu, $avgMem, $count];
    }

    private function resetWindow(int $applicationId): void
    {
        Cache::forget("autoscale:samples:{$applicationId}");
    }
}
