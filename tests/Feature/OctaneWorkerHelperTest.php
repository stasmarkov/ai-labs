<?php

declare(strict_types=1);

use Tests\Support\OctaneRunner;

test('AC-6 the worker helper answers two requests from one booted worker', function (): void {
    $responses = OctaneRunner::worker(['/debug/runtime', '/debug/runtime']);

    expect($responses)->toHaveCount(2)
        ->and($responses[0]['status'])->toBe(200)
        ->and($responses[1]['status'])->toBe(200)
        ->and($responses[1]['body']['data']['sapi'])->toBe('cli');
});

test('AC-7 the classic helper answers each request from a fresh boot', function (): void {
    $responses = OctaneRunner::classic(['/debug/runtime', '/debug/runtime']);

    expect($responses)->toHaveCount(2)
        ->and($responses[0]['status'])->toBe(200)
        ->and($responses[1]['status'])->toBe(200);
});
