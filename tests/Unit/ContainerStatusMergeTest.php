<?php

use App\Services\ContainerStatusAggregator;

/**
 * Tests for ContainerStatusAggregator::mergeStatusStrings(), used to combine the
 * status of multiple replicas that share the same Docker Compose service name so a
 * failed replica is not masked by a healthy one.
 */
it('returns the new status when there is no existing status', function (): void {
    $aggregator = new ContainerStatusAggregator;

    expect($aggregator->mergeStatusStrings(null, 'running:healthy'))->toBe('running:healthy');
    expect($aggregator->mergeStatusStrings('', 'running:healthy'))->toBe('running:healthy');
});

it('keeps healthy when all replicas are healthy', function (): void {
    $aggregator = new ContainerStatusAggregator;

    expect($aggregator->mergeStatusStrings('running:healthy', 'running:healthy'))->toBe('running:healthy');
});

it('marks degraded when one replica is exited and another running', function (): void {
    $aggregator = new ContainerStatusAggregator;

    expect($aggregator->mergeStatusStrings('running:healthy', 'exited'))->toBe('degraded:unhealthy');
});

it('marks unhealthy when one replica is unhealthy', function (): void {
    $aggregator = new ContainerStatusAggregator;

    expect($aggregator->mergeStatusStrings('running:healthy', 'running:unhealthy'))->toBe('running:unhealthy');
});

it('reports unknown health when one replica health is unknown', function (): void {
    $aggregator = new ContainerStatusAggregator;

    expect($aggregator->mergeStatusStrings('running:healthy', 'running:unknown'))->toBe('running:unknown');
});

it('preserves restarting by default', function (): void {
    $aggregator = new ContainerStatusAggregator;

    expect($aggregator->mergeStatusStrings('running:healthy', 'restarting:unknown'))->toBe('restarting:unknown');
});

it('can fold three replicas by merging pairwise', function (): void {
    $aggregator = new ContainerStatusAggregator;

    // running + running => running:healthy ; then + exited => degraded
    $step1 = $aggregator->mergeStatusStrings('running:healthy', 'running:healthy');
    $step2 = $aggregator->mergeStatusStrings($step1, 'exited');

    expect($step2)->toBe('degraded:unhealthy');
});
