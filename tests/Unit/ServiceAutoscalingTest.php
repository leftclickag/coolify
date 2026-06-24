<?php

use App\Models\ServiceApplication;
use App\Services\ServiceAutoscaler;
use Illuminate\Support\Facades\Cache;

// These exercise ServiceAutoscaler directly (the real decision path, not a reimplementation).
// now(), the datetime cast on autoscale_last_scaled_at, and the cache all resolve facades, so
// bind to the booted TestCase (tests/Unit is not bound by default — see tests/Pest.php).
// No database is touched — models are used in-memory only.
uses(Tests\TestCase::class);

// ---------------------------------------------------------------------------
// decide(): pure scaling decision
// ---------------------------------------------------------------------------

it('does not scale when no thresholds are configured', function (): void {
    $result = (new ServiceAutoscaler)->decide(
        current: 2, min: 1, max: 5, cpuThreshold: null, memThreshold: null,
        avgCpu: 90.0, avgMem: 90.0, windowFull: true,
    );

    expect($result)->toBeNull();
});

it('scales up when CPU exceeds threshold', function (): void {
    $result = (new ServiceAutoscaler)->decide(
        current: 2, min: 1, max: 5, cpuThreshold: 80, memThreshold: null,
        avgCpu: 85.0, avgMem: 20.0, windowFull: true,
    );

    expect($result)->toBe(3);
});

it('scales up when memory exceeds threshold', function (): void {
    $result = (new ServiceAutoscaler)->decide(
        current: 2, min: 1, max: 5, cpuThreshold: null, memThreshold: 70,
        avgCpu: 5.0, avgMem: 75.0, windowFull: true,
    );

    expect($result)->toBe(3);
});

it('scales down when CPU is below half the threshold and the window is full', function (): void {
    $result = (new ServiceAutoscaler)->decide(
        current: 3, min: 1, max: 5, cpuThreshold: 80, memThreshold: null,
        avgCpu: 15.0, avgMem: 10.0, windowFull: true,
    );

    expect($result)->toBe(2);
});

it('does not scale down until the sample window is full', function (): void {
    $result = (new ServiceAutoscaler)->decide(
        current: 3, min: 1, max: 5, cpuThreshold: 80, memThreshold: null,
        avgCpu: 15.0, avgMem: 10.0, windowFull: false,
    );

    expect($result)->toBeNull();
});

it('does not scale below minimum replicas', function (): void {
    $result = (new ServiceAutoscaler)->decide(
        current: 1, min: 1, max: 5, cpuThreshold: 80, memThreshold: null,
        avgCpu: 5.0, avgMem: 5.0, windowFull: true,
    );

    expect($result)->toBeNull();
});

it('does not scale above maximum replicas', function (): void {
    $result = (new ServiceAutoscaler)->decide(
        current: 5, min: 1, max: 5, cpuThreshold: 80, memThreshold: null,
        avgCpu: 99.0, avgMem: 10.0, windowFull: true,
    );

    expect($result)->toBeNull();
});

it('holds scale-down when memory is still above half the threshold', function (): void {
    // CPU is low but memory is still above half of 70 (35).
    $result = (new ServiceAutoscaler)->decide(
        current: 3, min: 1, max: 5, cpuThreshold: 80, memThreshold: 70,
        avgCpu: 10.0, avgMem: 40.0, windowFull: true,
    );

    expect($result)->toBeNull();
});

// ---------------------------------------------------------------------------
// isInCooldown()
// ---------------------------------------------------------------------------

it('is not in cooldown when the application has never been scaled', function (): void {
    $application = new ServiceApplication([
        'autoscale_cooldown_seconds' => 300,
        'autoscale_last_scaled_at' => null,
    ]);

    expect((new ServiceAutoscaler)->isInCooldown($application))->toBeFalse();
});

it('is in cooldown shortly after a scale event', function (): void {
    $application = new ServiceApplication([
        'autoscale_cooldown_seconds' => 300,
        'autoscale_last_scaled_at' => now()->subSeconds(10),
    ]);

    expect((new ServiceAutoscaler)->isInCooldown($application))->toBeTrue();
});

it('is no longer in cooldown once the window has elapsed', function (): void {
    // Regression: Carbon 3 diffInSeconds() is signed, so a past timestamp yields a
    // negative value. Without abs() this read as "always in cooldown" and froze
    // autoscaling after the first scale event.
    $application = new ServiceApplication([
        'autoscale_cooldown_seconds' => 300,
        'autoscale_last_scaled_at' => now()->subSeconds(600),
    ]);

    expect((new ServiceAutoscaler)->isInCooldown($application))->toBeFalse();
});

// ---------------------------------------------------------------------------
// evaluate() sampling throttle
// ---------------------------------------------------------------------------

it('records at most one sample per evaluation window regardless of call frequency', function (): void {
    Cache::forget('autoscale:samples:4242');
    Cache::forget('autoscale:evaluated:4242');

    $application = new ServiceApplication([
        'replicas' => 2,
        'autoscale_min_replicas' => 1,
        'autoscale_max_replicas' => 5,
        'autoscale_cpu_threshold' => 80,
        'autoscale_memory_threshold' => null,
        'autoscale_cooldown_seconds' => 300,
        'autoscale_last_scaled_at' => null,
    ]);
    $application->id = 4242;

    $autoscaler = new ServiceAutoscaler;

    // Low load + an empty window means no scale (so no SSH), but a sample is recorded.
    $autoscaler->evaluate($application, 5.0, 5.0);
    // Immediate second call is throttled and must NOT record another sample.
    $autoscaler->evaluate($application, 90.0, 90.0);

    $samples = Cache::get('autoscale:samples:4242');

    expect($samples)->toBeArray()->toHaveCount(1);
});
