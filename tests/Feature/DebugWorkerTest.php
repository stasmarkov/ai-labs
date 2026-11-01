<?php

declare(strict_types=1);

use Tests\Support\OctaneRunner;

test('AC-5 worker endpoint reports pid, ids, memory and a request counter', function (): void {
    [$first, $second] = OctaneRunner::worker(['/debug/worker', '/debug/worker']);

    expect($first['status'])->toBe(200)
        ->and($first['body']['data'])->toHaveKeys(['pid', 'worker_id', 'app_id', 'memory_bytes', 'peak_memory_bytes', 'worker_hits', 'first_seen_at', 'octane'])
        ->and($first['body']['data']['pid'])->toBeInt()
        ->and($first['body']['data']['memory_bytes'])->toBeGreaterThan(0)
        ->and($first['body']['data']['worker_hits'])->toBe(1)
        ->and($second['body']['data']['worker_hits'])->toBe(2)
        ->and($second['body']['data']['pid'])->toBe($first['body']['data']['pid'])
        ->and($second['body']['data']['worker_id'])->toBe($first['body']['data']['worker_id'])
        ->and($first['body']['data']['first_seen_at'])->toBeString()->not->toBeEmpty()
        ->and($second['body']['data']['first_seen_at'])->toBe($first['body']['data']['first_seen_at'])
        ->and($first['body']['data']['octane'])->toBeTrue()
        ->and($first['body']['data']['app_id'])->toBeInt();
});

test('AC-5 worker endpoint restarts its counter on every classic boot', function (): void {
    [$first, $second] = OctaneRunner::classic(['/debug/worker', '/debug/worker']);

    expect($first['body']['data']['worker_hits'])->toBe(1)
        ->and($second['body']['data']['worker_hits'])->toBe(1)
        ->and($second['body']['data']['worker_id'])->not->toBe($first['body']['data']['worker_id'])
        ->and($first['body']['data']['octane'])->toBeFalse()
        ->and($first['body']['data']['first_seen_at'])->toBeString();
});

test('MNC-1 worker endpoint is a 404 when debug routes are off', function (): void {
    config(['app.debug_routes' => false]);

    $this->getJson('/debug/worker')->assertNotFound();
});
