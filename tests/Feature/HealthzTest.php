<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('AC-2 healthz answers ok with no database, session, cookie or http call', function () {
    Http::preventStrayRequests();
    Http::fake();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $response = $this->getJson('/healthz')->assertOk()->assertExactJson(['status' => 'ok']);

    expect($response->headers->getCookies())->toBeEmpty()
        ->and($response->headers->has('Set-Cookie'))->toBeFalse()
        ->and($queries)->toBe([])
        ->and(app('session')->driver()->isStarted())->toBeFalse();
    Http::assertNothingSent();
});

test('MNC-1 healthz is an addition and leaves the up route intact', function () {
    $this->get('/up')->assertOk();
});
