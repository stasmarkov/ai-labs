<?php

declare(strict_types=1);

/**
 * JSON errors keep the headers of their HTTP exception (SL-46 decision): a 429 says when
 * to retry, which the lab shows, and a 405 says which methods are allowed. Bodies are unchanged.
 */
it('MNC-2 a throttled JSON request keeps Retry-After and the rate-limit headers', function (): void {
    config(['ai.docs.search_per_minute' => 1]);

    $this->getJson('/api/docs/search?q=plain&mode=text')->assertOk();

    $this->getJson('/api/docs/search?q=plain&mode=text')
        ->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', '1')
        ->assertHeader('X-RateLimit-Remaining', '0');
});

it('MNC-2 a JSON 405 keeps the Allow header', function (): void {
    $this->postJson('/api/listings/semantic')
        ->assertStatus(405)
        ->assertJsonPath('success', false)
        ->assertHeader('Allow');
});
