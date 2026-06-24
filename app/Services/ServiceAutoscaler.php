<?php

namespace App\Services;

use App\Actions\Service\ScaleServiceApplication;
use App\Models\ServiceApplication;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether a service application should scale based on a moving average of
 * CPU/memory samples, and performs the scale. Extracted from the scheduled job so the
 * decision path is unit-testable without any SSH or queue involvement.
 *
 * Sampling cadence is self-throttled (see EVALUATE_EVERY_SECONDS): the per-server stats
 * collector that calls evaluate() may run far more often than once a minute when a service
 * is being watched on the Monitor page, but autoscaling must behave identically whether or
 * not a tab is open — so each app is evaluated at most once per ~minute.
 */
class ServiceAutoscaler
{
    /**
     * Number of recent samples to average. Evaluation is throttled to ~once/minute,
     * so 5 samples ≈ a 5-minute moving average.
     */
    public const SAMPLE_WINDOW = 5;

    /**
     * Minimum seconds between evaluations of a single application, making the sampling
     * cadence independent of how frequently the collector runs.
     */
    private const EVALUATE_EVERY_SECONDS = 55;

    /**
     * Record the latest snapshot for an application and scale it if warranted.
     * Returns the new replica count when a scale happened, otherwise null.
     */
    public function evaluate(ServiceApplication $application, float $snapshotCpu, float $snapshotMem): ?int
    {
        // At least one threshold must be configured.
        if ($application->autoscale_cpu_threshold === null && $application->autoscale_memory_threshold === null) {
            return null;
        }

        // Throttle so the moving-average window represents a stable time span regardless
        // of how often the collector calls us.
        if ($this->recentlyEvaluated($application->id)) {
            return null;
        }
        $this->markEvaluated($application->id);

        // Push the snapshot into the rolling window and read back the smoothed averages.
        // Recorded even during cooldown so the average stays continuous.
        [$avgCpu, $avgMem, $samplesCount] = $this->recordAndSmooth($application->id, $snapshotCpu, $snapshotMem);

        if ($this->isInCooldown($application)) {
            return null;
        }

        $current = (int) ($application->replicas ?? 1);
        $newReplicas = $this->decide(
            current: $current,
            min: (int) ($application->autoscale_min_replicas ?? 1),
            max: (int) ($application->autoscale_max_replicas ?? 5),
            cpuThreshold: $application->autoscale_cpu_threshold,
            memThreshold: $application->autoscale_memory_threshold,
            avgCpu: $avgCpu,
            avgMem: $avgMem,
            windowFull: $samplesCount >= self::SAMPLE_WINDOW,
        );

        if ($newReplicas === null) {
            return null;
        }

        ScaleServiceApplication::run($application, $newReplicas);
        $this->resetWindow($application->id);

        Log::info(sprintf(
            'Autoscaled service application [%s] %d → %d (avg CPU %.1f%% / MEM %.1f%% over %d samples)',
            $application->name, $current, $newReplicas, $avgCpu, $avgMem, $samplesCount
        ));

        return $newReplicas;
    }

    /**
     * Pure scaling decision. Returns the target replica count, or null when no change.
     *
     * Scale up as soon as an average crosses its threshold; scale down only once a full
     * window has elapsed and no metric is still above half its threshold (anti-flap).
     */
    public function decide(
        int $current,
        int $min,
        int $max,
        ?int $cpuThreshold,
        ?int $memThreshold,
        float $avgCpu,
        float $avgMem,
        bool $windowFull,
    ): ?int {
        if ($cpuThreshold === null && $memThreshold === null) {
            return null;
        }

        $scaleUp = false;
        $holdScaleDown = false;

        if ($cpuThreshold !== null) {
            if ($avgCpu >= $cpuThreshold) {
                $scaleUp = true;
            }
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

        return $newReplicas !== $current ? $newReplicas : null;
    }

    /**
     * Whether the application is still within its scaling cooldown window.
     *
     * Carbon 3 returns a signed value from diffInSeconds(), so a past timestamp yields a
     * negative number; abs() keeps the comparison correct (otherwise it would always read
     * as "in cooldown" and permanently halt scaling after the first scale event).
     */
    public function isInCooldown(ServiceApplication $application, ?CarbonInterface $now = null): bool
    {
        if (! $application->autoscale_last_scaled_at) {
            return false;
        }

        $now ??= now();
        $elapsed = abs($now->diffInSeconds($application->autoscale_last_scaled_at));

        return $elapsed < (int) ($application->autoscale_cooldown_seconds ?? 300);
    }

    /**
     * Append a sample to the rolling window cache and return the smoothed averages.
     *
     * @return array{0: float, 1: float, 2: int}  [avgCpu, avgMem, sampleCount]
     */
    public function recordAndSmooth(int $applicationId, float $cpu, float $mem): array
    {
        $key = "autoscale:samples:{$applicationId}";
        $samples = Cache::get($key, []);
        if (! is_array($samples)) {
            $samples = [];
        }

        $samples[] = ['cpu' => $cpu, 'mem' => $mem];
        if (count($samples) > self::SAMPLE_WINDOW) {
            $samples = array_slice($samples, -self::SAMPLE_WINDOW);
        }

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

    private function recentlyEvaluated(int $applicationId): bool
    {
        return Cache::has("autoscale:evaluated:{$applicationId}");
    }

    private function markEvaluated(int $applicationId): void
    {
        Cache::put("autoscale:evaluated:{$applicationId}", true, self::EVALUATE_EVERY_SECONDS);
    }
}
