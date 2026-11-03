<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Tells a running stream whether the client has gone away. A seam so tests can abort after a chosen number of events.
 */
interface AbortWatcher
{
    public function aborted(): bool;
}
