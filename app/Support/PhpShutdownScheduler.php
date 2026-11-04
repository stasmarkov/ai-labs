<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\ShutdownScheduler;
use Closure;

/**
 * register_shutdown_function runs after fastcgi_finish_request, after a streamed body ends and after an abort, on every SAPI.
 */
final class PhpShutdownScheduler implements ShutdownScheduler
{
    public function defer(Closure $callback): void
    {
        register_shutdown_function($callback);
    }
}
