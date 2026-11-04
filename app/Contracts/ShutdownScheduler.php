<?php

declare(strict_types=1);

namespace App\Contracts;

use Closure;

/**
 * Runs a callback at the very end of the PHP request. A seam so tests can run it on demand.
 */
interface ShutdownScheduler
{
    public function defer(Closure $callback): void;
}
