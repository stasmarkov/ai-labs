<?php

declare(strict_types=1);

it('AC-6 returns runtime info when enabled', function (): void {
    config()->set('app.debug_routes', true);

    $this->getJson('/debug/runtime')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['sapi', 'php_version', 'opcache_enabled', 'xdebug_loaded', 'server_software']])
        ->assertJsonPath('data.php_version', PHP_VERSION)
        ->assertJsonPath('data.sapi', PHP_SAPI);

    $data = $this->getJson('/debug/runtime')->json('data');

    expect($data['sapi'])->toBeString()
        ->and($data['php_version'])->toBeString()
        ->and($data['opcache_enabled'])->toBeBool()
        ->and($data['xdebug_loaded'])->toBe(extension_loaded('xdebug'))
        ->and($data['server_software'] === null || is_string($data['server_software']))->toBeTrue();
});

it('AC-7 MNC-3 is not found when disabled', function (): void {
    config()->set('app.debug_routes', false);

    $unknown = $this->getJson('/debug/unregistered-path');
    $response = $this->getJson('/debug/runtime');

    $response->assertNotFound();
    expect($response->getStatusCode())->toBe($unknown->getStatusCode())
        ->and($response->getContent())->toBe($unknown->getContent());
});

it('AC-7 is not found like an unknown path without an Accept header', function (): void {
    config()->set('app.debug_routes', false);

    $unknown = $this->get('/debug/not-a-route');
    $response = $this->get('/debug/runtime');

    $response->assertNotFound();
    expect($response->getStatusCode())->toBe($unknown->getStatusCode())
        ->and($response->getContent())->toBe($unknown->getContent());
});

it('MNC-3 is disabled by default in the test environment', function (): void {
    $this->getJson('/debug/runtime')->assertNotFound();
});

it('AC-3 runtime reports whether xdebug is loaded in the serving SAPI', function (): void {
    config()->set('app.debug_routes', true);

    $this->getJson('/debug/runtime')
        ->assertOk()
        ->assertJsonPath('data.xdebug_loaded', extension_loaded('xdebug'));
});
