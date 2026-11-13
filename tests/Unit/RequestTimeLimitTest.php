<?php

declare(strict_types=1);

use App\Support\RequestTimeLimit;

afterEach(function (): void {
    set_time_limit(0);
});

it('raises a finite limit that is lower than the one asked for', function (): void {
    set_time_limit(30);

    RequestTimeLimit::atLeast(150);

    expect((int) ini_get('max_execution_time'))->toBe(150);
});

it('never turns an unlimited run into a limited one', function (): void {
    set_time_limit(0);

    RequestTimeLimit::atLeast(150);

    expect((int) ini_get('max_execution_time'))->toBe(0);
});

it('keeps a limit that is already higher', function (): void {
    set_time_limit(300);

    RequestTimeLimit::atLeast(150);

    expect((int) ini_get('max_execution_time'))->toBe(300);
});
