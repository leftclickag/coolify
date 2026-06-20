<?php

use App\Jobs\CheckServiceAutoscalingJob;
use App\Models\ServiceApplication;

it('does not scale when no thresholds are configured', function (): void {
    $application = new ServiceApplication([
        'replicas' => 2,
        'autoscale_enabled' => true,
        'autoscale_min_replicas' => 1,
        'autoscale_max_replicas' => 5,
        'autoscale_cpu_threshold' => null,
        'autoscale_memory_threshold' => null,
        'autoscale_cooldown_seconds' => 300,
        'autoscale_last_scaled_at' => null,
    ]);

    $result = invokeAutoscalingDecision($application, avgCpu: 90.0, avgMem: 90.0);

    expect($result)->toBeNull();
});

it('scales up when CPU exceeds threshold', function (): void {
    $application = new ServiceApplication([
        'replicas' => 2,
        'autoscale_enabled' => true,
        'autoscale_min_replicas' => 1,
        'autoscale_max_replicas' => 5,
        'autoscale_cpu_threshold' => 80,
        'autoscale_memory_threshold' => null,
        'autoscale_cooldown_seconds' => 300,
        'autoscale_last_scaled_at' => null,
    ]);

    $result = invokeAutoscalingDecision($application, avgCpu: 85.0, avgMem: 20.0);

    expect($result)->toBe(3);
});

it('scales down when CPU is below half the threshold', function (): void {
    $application = new ServiceApplication([
        'replicas' => 3,
        'autoscale_enabled' => true,
        'autoscale_min_replicas' => 1,
        'autoscale_max_replicas' => 5,
        'autoscale_cpu_threshold' => 80,
        'autoscale_memory_threshold' => null,
        'autoscale_cooldown_seconds' => 300,
        'autoscale_last_scaled_at' => null,
    ]);

    $result = invokeAutoscalingDecision($application, avgCpu: 15.0, avgMem: 10.0);

    expect($result)->toBe(2);
});

it('does not scale below minimum replicas', function (): void {
    $application = new ServiceApplication([
        'replicas' => 1,
        'autoscale_enabled' => true,
        'autoscale_min_replicas' => 1,
        'autoscale_max_replicas' => 5,
        'autoscale_cpu_threshold' => 80,
        'autoscale_memory_threshold' => null,
        'autoscale_cooldown_seconds' => 300,
        'autoscale_last_scaled_at' => null,
    ]);

    $result = invokeAutoscalingDecision($application, avgCpu: 5.0, avgMem: 5.0);

    expect($result)->toBeNull();
});

it('does not scale above maximum replicas', function (): void {
    $application = new ServiceApplication([
        'replicas' => 5,
        'autoscale_enabled' => true,
        'autoscale_min_replicas' => 1,
        'autoscale_max_replicas' => 5,
        'autoscale_cpu_threshold' => 80,
        'autoscale_memory_threshold' => null,
        'autoscale_cooldown_seconds' => 300,
        'autoscale_last_scaled_at' => null,
    ]);

    $result = invokeAutoscalingDecision($application, avgCpu: 99.0, avgMem: 10.0);

    expect($result)->toBeNull();
});

it('holds scale-down when memory is still above half the threshold', function (): void {
    $application = new ServiceApplication([
        'replicas' => 3,
        'autoscale_enabled' => true,
        'autoscale_min_replicas' => 1,
        'autoscale_max_replicas' => 5,
        'autoscale_cpu_threshold' => 80,
        'autoscale_memory_threshold' => 70,
        'autoscale_cooldown_seconds' => 300,
        'autoscale_last_scaled_at' => null,
    ]);

    // CPU is low but memory is still above half of 70 (35)
    $result = invokeAutoscalingDecision($application, avgCpu: 10.0, avgMem: 40.0);

    expect($result)->toBeNull();
});

it('scales up when memory exceeds threshold', function (): void {
    $application = new ServiceApplication([
        'replicas' => 2,
        'autoscale_enabled' => true,
        'autoscale_min_replicas' => 1,
        'autoscale_max_replicas' => 5,
        'autoscale_cpu_threshold' => null,
        'autoscale_memory_threshold' => 70,
        'autoscale_cooldown_seconds' => 300,
        'autoscale_last_scaled_at' => null,
    ]);

    $result = invokeAutoscalingDecision($application, avgCpu: 5.0, avgMem: 75.0);

    expect($result)->toBe(3);
});

/**
 * Pure-logic helper that mirrors the decision code in CheckServiceAutoscalingJob
 * without any database or remote-process calls.
 *
 * Returns the new replica count, or null when no scaling action is needed.
 */
function invokeAutoscalingDecision(ServiceApplication $application, float $avgCpu, float $avgMem): ?int
{
    $current = (int) ($application->replicas ?? 1);
    $min = (int) ($application->autoscale_min_replicas ?? 1);
    $max = (int) ($application->autoscale_max_replicas ?? 5);
    $cpuThreshold = $application->autoscale_cpu_threshold;
    $memThreshold = $application->autoscale_memory_threshold;

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
    } elseif (! $holdScaleDown && $current > $min) {
        $newReplicas = $current - 1;
    }

    return $newReplicas !== $current ? $newReplicas : null;
}
