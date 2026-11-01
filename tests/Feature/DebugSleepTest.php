<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;

it('AC-8 sleeps and returns the slept seconds when enabled', function (): void {
    config()->set('app.debug_routes', true);
    Sleep::fake();

    $this->getJson('/debug/sleep/3')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.slept_seconds', 3);

    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([Sleep::for(3)->seconds()]);
});

it('AC-8 accepts the range boundaries', function (int $seconds): void {
    config()->set('app.debug_routes', true);
    Sleep::fake();

    $this->getJson('/debug/sleep/'.$seconds)->assertOk()->assertJsonPath('data.slept_seconds', $seconds);
})->with([1, 120]);

it('AC-8 rejects seconds out of range with 422', function (int $seconds): void {
    config()->set('app.debug_routes', true);
    Sleep::fake();

    $this->getJson('/debug/sleep/'.$seconds)->assertStatus(422);

    Sleep::assertNeverSlept();
})->with([0, 121]);

it('AC-8 does not match non-numeric seconds', function (): void {
    config()->set('app.debug_routes', true);

    $this->getJson('/debug/sleep/abc')->assertNotFound();
});

it('AC-8 MNC-3 is not found like an unknown path when disabled', function (): void {
    config()->set('app.debug_routes', false);
    Sleep::fake();

    $unknown = $this->getJson('/debug/unregistered-path');
    $response = $this->getJson('/debug/sleep/3');

    $response->assertNotFound();
    expect($response->getContent())->toBe($unknown->getContent());
    Sleep::assertNeverSlept();
});

it('AC-8 MNC-3 is not found when disabled even for out-of-range seconds', function (): void {
    config()->set('app.debug_routes', false);

    $this->getJson('/debug/sleep/999')->assertNotFound();
});

it('AC-8 MNC-3 an overflowing seconds value is a 404 while disabled and a 422 while enabled', function (): void {
    $this->getJson('/debug/sleep/99999999999999999999')->assertNotFound();

    config(['app.debug_routes' => true]);
    Sleep::fake();

    $this->getJson('/debug/sleep/99999999999999999999')->assertStatus(422);
    $this->getJson('/debug/sleep/0')->assertStatus(422);
});
