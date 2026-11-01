<?php

declare(strict_types=1);

it('MNC-1 every debug route answers like an unknown path for any method while disabled', function (string $method, string $uri): void {
    $unknown = $this->json($method, '/debug/not-a-route');
    $response = $this->json($method, $uri);

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getContent())->toBe($unknown->getContent())
        ->and($response->headers->has('Allow'))->toBeFalse();
})->with([
    ['GET', '/debug/runtime'],
    ['POST', '/debug/runtime'],
    ['GET', '/debug/sleep/1'],
    ['DELETE', '/debug/sleep/1'],
    ['GET', '/debug/worker'],
    ['POST', '/debug/worker'],
    ['GET', '/debug/leaks/dealer-scope'],
    ['PUT', '/debug/leaks/dealer-scope'],
]);

it('MNC-1 debug routes accept only GET and HEAD when enabled', function (): void {
    config(['app.debug_routes' => true]);

    $this->postJson('/debug/worker')->assertStatus(405);
    $this->getJson('/debug/worker')->assertOk();
});
