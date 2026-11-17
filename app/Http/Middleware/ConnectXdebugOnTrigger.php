<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\WorkerRecycle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A worker runs its request handler inside one long PHP request, so Xdebug's own
 * trigger check (which runs once, at start) never sees the per-request query or cookie.
 * This starts the debug session explicitly for worker requests that carry a trigger (XDEBUG_TRIGGER, XDEBUG_SESSION
 * or XDEBUG_SESSION_START in the query, the POST body, a cookie or the request environment), and only with the value
 * of xdebug.trigger_value when that is set, compared in constant time.
 *
 * Xdebug has no call that ends a session: it lasts as long as the PHP script, which in a worker is the whole worker.
 * So a request that opened a session asks for a worker recycle (WorkerRecycle): the worker ends once the response is
 * sent and FrankenPHP starts a fresh one, and the next request on that thread runs without the debugger. The cost is
 * one worker boot per debugged request.
 * Classic mode is left to Xdebug itself, which checks the trigger at the start of every request.
 */
final class ConnectXdebugOnTrigger
{
    public const string TRIGGER = 'XDEBUG_TRIGGER';

    /** @var list<string> */
    public const array TRIGGERS = [self::TRIGGER, 'XDEBUG_SESSION', 'XDEBUG_SESSION_START'];

    /**
     * @param  (Closure(): bool)|null  $connect
     * @param  (Closure(): bool)|null  $available
     * @param  (Closure(): bool)|null  $worker
     * @param  (Closure(): bool)|null  $active
     * @param  string|null  $triggerValue  null reads xdebug.trigger_value; a comma separated list is accepted
     */
    public function __construct(
        private readonly ?Closure $connect = null,
        private readonly ?Closure $available = null,
        private readonly ?Closure $worker = null,
        private readonly ?Closure $active = null,
        private readonly ?string $triggerValue = null,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isAvailable() && $this->isWorker() && ! $this->isDebuggerActive() && $this->isTriggered($request)) {
            $connected = $this->connect === null ? xdebug_connect_to_client() : ($this->connect)();

            if ($connected) {
                WorkerRecycle::request();
            }
        }

        return $next($request);
    }

    private function isAvailable(): bool
    {
        return $this->available === null
            ? function_exists('xdebug_connect_to_client')
            : ($this->available)();
    }

    private function isWorker(): bool
    {
        return $this->worker === null
            ? WorkerRecycle::isInWorkerLoop()
            : ($this->worker)();
    }

    private function isDebuggerActive(): bool
    {
        if ($this->active !== null) {
            return ($this->active)();
        }

        return function_exists('xdebug_is_debugger_active') && xdebug_is_debugger_active();
    }

    private function isTriggered(Request $request): bool
    {
        $expected = array_filter(explode(',', $this->triggerValue ?? (string) ini_get('xdebug.trigger_value')), static fn (string $value): bool => $value !== '');

        foreach (self::TRIGGERS as $name) {
            foreach ([$request->query, $request->request, $request->cookies, $request->server] as $bag) {
                if (! $bag->has($name)) {
                    continue;
                }

                $given = $bag->all()[$name];

                if ($expected === []) {
                    return true;
                }

                if (is_string($given)) {
                    foreach ($expected as $value) {
                        if (hash_equals($value, $given)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }
}
