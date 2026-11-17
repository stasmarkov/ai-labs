<?php

declare(strict_types=1);

use App\Http\Middleware\ConnectXdebugOnTrigger;
use App\Support\WorkerRecycle;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

function runXdebugTriggerMiddleware(Request $request, ?callable $connect, bool $loaded = true, bool $worker = true, bool $active = false, string $triggerValue = ''): int
{
    $calls = 0;
    $middleware = new ConnectXdebugOnTrigger(
        connect: function () use (&$calls, $connect): bool {
            $calls++;

            return $connect === null || $connect();
        },
        available: fn (): bool => $loaded,
        worker: fn (): bool => $worker,
        active: fn (): bool => $active,
        triggerValue: $triggerValue,
    );
    $middleware->handle($request, fn (): Response => new Response);

    return $calls;
}

beforeEach(fn () => WorkerRecycle::reset());
afterEach(fn () => WorkerRecycle::reset());

it('AC-4 connects to the debugger for a request with the XDEBUG_TRIGGER query', function (): void {
    expect(runXdebugTriggerMiddleware(Request::create('/api/ping?XDEBUG_TRIGGER=1'), null))->toBe(1);
});

it('AC-4 connects to the debugger for a request with the XDEBUG_TRIGGER cookie', function (): void {
    $request = Request::create('/api/ping', cookies: ['XDEBUG_TRIGGER' => '1']);

    expect(runXdebugTriggerMiddleware($request, null))->toBe(1);
});

it('MNC-3 never connects for a request without the trigger', function (): void {
    expect(runXdebugTriggerMiddleware(Request::create('/api/ping'), null))->toBe(0);
});

it('MNC-3 never connects when Xdebug is not loaded, even with the trigger', function (): void {
    expect(runXdebugTriggerMiddleware(Request::create('/api/ping?XDEBUG_TRIGGER=1'), null, loaded: false))->toBe(0);
});

it('AC-4 connects for the XDEBUG_SESSION cookie, the XDEBUG_SESSION_START query and a POST XDEBUG_TRIGGER', function (): void {
    expect(runXdebugTriggerMiddleware(Request::create('/api/ping', cookies: ['XDEBUG_SESSION' => 'PHPSTORM']), null))->toBe(1)
        ->and(runXdebugTriggerMiddleware(Request::create('/api/ping?XDEBUG_SESSION_START=PHPSTORM'), null))->toBe(1)
        ->and(runXdebugTriggerMiddleware(Request::create('/api/ping', 'POST', ['XDEBUG_TRIGGER' => '1']), null))->toBe(1);
});

it('MNC-3 with xdebug.trigger_value set only a request that sends that value connects', function (): void {
    expect(runXdebugTriggerMiddleware(Request::create('/api/ping?XDEBUG_TRIGGER=x'), null, triggerValue: 'secret'))->toBe(0)
        ->and(runXdebugTriggerMiddleware(Request::create('/api/ping', cookies: ['XDEBUG_TRIGGER' => 'x']), null, triggerValue: 'secret'))->toBe(0)
        ->and(runXdebugTriggerMiddleware(Request::create('/api/ping?XDEBUG_TRIGGER[]=secret'), null, triggerValue: 'secret'))->toBe(0)
        ->and(runXdebugTriggerMiddleware(Request::create('/api/ping?XDEBUG_TRIGGER=secret'), null, triggerValue: 'secret'))->toBe(1)
        ->and(runXdebugTriggerMiddleware(Request::create('/api/ping?XDEBUG_TRIGGER=b'), null, triggerValue: 'a,b'))->toBe(1);
});

it('does not connect a second time outside worker mode or while a debugger session is active', function (): void {
    $request = Request::create('/api/ping?XDEBUG_TRIGGER=1');

    expect(runXdebugTriggerMiddleware($request, null, worker: false))->toBe(0)
        ->and(runXdebugTriggerMiddleware($request, null, active: true))->toBe(0);
});

it('AC-4 connects for a trigger in the request environment, like native Xdebug', function (): void {
    expect(runXdebugTriggerMiddleware(Request::create('/api/ping', server: ['XDEBUG_TRIGGER' => '1']), null))->toBe(1)
        ->and(runXdebugTriggerMiddleware(Request::create('/api/ping', server: ['XDEBUG_SESSION' => 'x']), null, triggerValue: 'secret'))->toBe(0)
        ->and(runXdebugTriggerMiddleware(Request::create('/api/ping', server: ['XDEBUG_SESSION' => 'secret']), null, triggerValue: 'secret'))->toBe(1);
});

it('MNC-3 a triggered worker request that opened a session recycles the worker after the response', function (): void {
    runXdebugTriggerMiddleware(Request::create('/api/ping?XDEBUG_TRIGGER=1'), null);

    expect(WorkerRecycle::isRequested())->toBeTrue();
});

it('MNC-3 no recycle when the connect fails, in classic mode or without a trigger', function (): void {
    runXdebugTriggerMiddleware(Request::create('/api/ping?XDEBUG_TRIGGER=1'), fn (): bool => false);
    runXdebugTriggerMiddleware(Request::create('/api/ping?XDEBUG_TRIGGER=1'), null, worker: false);
    runXdebugTriggerMiddleware(Request::create('/api/ping'), null);

    expect(WorkerRecycle::isRequested())->toBeFalse();
});

it('MNC-3 the worker loop stops right after the request that asked for a recycle', function (): void {
    $handled = 0;
    $count = WorkerRecycle::loop(function () use (&$handled): bool {
        $handled++;
        if ($handled === 2) {
            WorkerRecycle::request();
        }

        return true;
    }, 500);

    expect($count)->toBe(2)->and($handled)->toBe(2);
});

it('the worker loop keeps the request limit and stops when FrankenPHP has no more requests', function (): void {
    $handled = 0;

    expect(WorkerRecycle::loop(function () use (&$handled): bool {
        $handled++;

        return true;
    }, 3))->toBe(3)
        ->and(WorkerRecycle::loop(fn (): bool => false, 3))->toBe(0);
});

it('MNC-3 the shared worker script runs its requests through the recycle loop', function (): void {
    $script = file_get_contents(base_path('public/frankenphp-worker.php'));

    expect($script)->toContain('WorkerRecycle::loop(')
        ->and($script)->toContain('frankenphp_handle_request($handleRequest)')
        ->and($script)->not->toContain("require __DIR__.'/../vendor/laravel/octane/bin/frankenphp-worker.php'");
});

it('MNC-3 a request counts as a worker request only while the worker loop runs it', function (): void {
    $seen = [];
    WorkerRecycle::loop(function () use (&$seen): bool {
        $seen[] = WorkerRecycle::isInWorkerLoop();

        return count($seen) < 2;
    }, 500);

    expect($seen)->toBe([true, true])
        ->and(WorkerRecycle::isInWorkerLoop())->toBeFalse();
});

it('MNC-3 by default the middleware treats a request outside the worker loop as classic and leaves it to Xdebug', function (): void {
    $calls = 0;
    $middleware = new ConnectXdebugOnTrigger(
        connect: function () use (&$calls): bool {
            $calls++;

            return true;
        },
        available: fn (): bool => true,
        active: fn (): bool => false,
    );
    $middleware->handle(Request::create('/api/ping?XDEBUG_TRIGGER=1'), fn (): Response => new Response);

    expect($calls)->toBe(0);
});
