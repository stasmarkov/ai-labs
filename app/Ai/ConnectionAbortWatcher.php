<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * PHP notices a closed client connection only when output is written, so ask after each flush.
 */
final class ConnectionAbortWatcher implements AbortWatcher
{
    public function aborted(): bool
    {
        return connection_aborted() === 1;
    }
}
