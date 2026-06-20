<?php

/**
 * Tests for extractContainerPortForExpose() used when scaling a service application
 * above 1 replica (host port bindings get converted to `expose` entries).
 */
it('extracts the container port from a host:container mapping', function (): void {
    expect(extractContainerPortForExpose('3000:80'))->toBe('80');
});

it('extracts the container port from an ip:host:container mapping', function (): void {
    expect(extractContainerPortForExpose('0.0.0.0:3000:80'))->toBe('80');
});

it('extracts the container port from a host range mapping', function (): void {
    expect(extractContainerPortForExpose('3000-3019:80'))->toBe('80');
});

it('returns a container-only port unchanged', function (): void {
    expect(extractContainerPortForExpose('80'))->toBe('80');
});

it('preserves the protocol suffix', function (): void {
    expect(extractContainerPortForExpose('3000:80/tcp'))->toBe('80/tcp');
    expect(extractContainerPortForExpose('53:53/udp'))->toBe('53/udp');
});

it('handles long-form array port definitions', function (): void {
    expect(extractContainerPortForExpose(['target' => 80, 'published' => 3000]))->toBe('80');
    expect(extractContainerPortForExpose(['target' => 80, 'published' => 3000, 'protocol' => 'tcp']))->toBe('80/tcp');
});

it('returns null for empty or invalid input', function (): void {
    expect(extractContainerPortForExpose(''))->toBeNull();
    expect(extractContainerPortForExpose(['published' => 3000]))->toBeNull();
});

it('handles numeric input', function (): void {
    expect(extractContainerPortForExpose(8080))->toBe('8080');
});
