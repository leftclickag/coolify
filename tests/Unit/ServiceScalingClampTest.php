<?php

use App\Actions\Service\ScaleServiceApplication;

// clampReplicas() is pure integer math, but the class pulls in the laravel-actions
// trait; bind to the booted TestCase so the file runs regardless (tests/Unit is not
// bound by default — see tests/Pest.php). No database is touched.
uses(Tests\TestCase::class);

it('leaves an in-range replica count unchanged', function (): void {
    expect(ScaleServiceApplication::clampReplicas(3))->toBe(3);
});

it('does not cap manual scaling at the autoscale max (5)', function (): void {
    // Regression: ScaleServiceApplication used to clamp every scale to
    // autoscale_max_replicas (default 5), silently capping the manual
    // "Apply Now" path even with autoscaling disabled.
    expect(ScaleServiceApplication::clampReplicas(10))->toBe(10);
});

it('clamps to the system maximum of 50', function (): void {
    expect(ScaleServiceApplication::clampReplicas(50))->toBe(50);
    expect(ScaleServiceApplication::clampReplicas(51))->toBe(50);
    expect(ScaleServiceApplication::clampReplicas(999))->toBe(ScaleServiceApplication::MAX_REPLICAS);
});

it('clamps zero and negative counts up to 1', function (): void {
    expect(ScaleServiceApplication::clampReplicas(0))->toBe(1);
    expect(ScaleServiceApplication::clampReplicas(-5))->toBe(1);
});
