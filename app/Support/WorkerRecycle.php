<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Lets a request ask its worker to stop once the response is sent. The worker script (public/frankenphp-worker.php,
 * used by frankenphp-worker and frankenphp-octane) runs its request loop through loop(); when the flag is set the loop
 * ends, the script returns, and FrankenPHP starts a fresh worker on that thread, as it does at the request limit.
 * The Xdebug trigger middleware uses it: a DBGp session lives as long as the PHP script, so ending the worker is the
 * only way to close the session before the next request on that thread.
 */
final class WorkerRecycle
{
    private static bool $requested = false;

    private static bool $inWorkerLoop = false;

    public static function request(): void
    {
        self::$requested = true;
    }

    public static function isRequested(): bool
    {
        return self::$requested;
    }

    public static function reset(): void
    {
        self::$requested = false;
    }

    /**
     * True while loop() handles a request, so only in a worker started from public/frankenphp-worker.php. Octane passes
     * its markers (LARAVEL_OCTANE) as process environment, which a request does not see in $_SERVER, so this flag is
     * the reliable test for "this request runs in a long-lived worker".
     */
    public static function isInWorkerLoop(): bool
    {
        return self::$inWorkerLoop;
    }

    /**
     * Handles requests until the request limit, until FrankenPHP has no more requests for this worker, or until a
     * request asked for a recycle. Returns the number of requests handled.
     *
     * @param  Closure(): bool  $handleNext  waits for and handles one request; false when the worker must stop
     */
    public static function loop(Closure $handleNext, int $maxRequests): int
    {
        $handled = 0;
        self::$inWorkerLoop = true;

        try {
            while ($handled < $maxRequests && ! self::$requested && $handleNext()) {
                $handled++;
            }
        } finally {
            self::$inWorkerLoop = false;
        }

        return $handled;
    }
}
