<?php

declare(strict_types=1);

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('BR-01 returns the constant ping envelope', function (): void {
    $this->getJson('/api/ping')
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'status' => 200,
            'message' => 'pong',
            'data' => ['ping' => 'pong'],
        ]);
});

it('BR-01 ping does no database or cache work and sets no cookie', function (): void {
    $this->get('/');
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $cacheEvents = 0;
    foreach ([CacheHit::class, CacheMissed::class, KeyWritten::class] as $event) {
        Event::listen($event, function () use (&$cacheEvents): void {
            $cacheEvents++;
        });
    }

    $response = $this->get('/api/ping');

    $response->assertOk();
    expect($queries)->toBe([])
        ->and($cacheEvents)->toBe(0)
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
});

it('BR-01 health route answers 200', function (): void {
    $this->get('/up')->assertOk();
});
