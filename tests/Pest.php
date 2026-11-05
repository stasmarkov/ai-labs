<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(LazilyRefreshDatabase::class)->beforeEach(fn () => Http::preventStrayRequests())->in('Feature');

/**
 * The detail page prints the server time at render (data-price-rendered-ms), which differs on every request;
 * bodies that must otherwise be identical are compared with it masked, as bench/parity.sh does.
 */
function maskRenderTime(string $html): string
{
    return (string) preg_replace('/data-price-rendered-ms="\d+"/', 'data-price-rendered-ms="N"', $html);
}
