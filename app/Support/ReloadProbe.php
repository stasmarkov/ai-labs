<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The value /debug/reload-probe returns. bench/reload-latency.sh overwrites MARKER with a unique string, times how long
 * a running server takes to return it, and puts the original back on exit; the committed value stays 'initial'.
 */
final class ReloadProbe
{
    public const MARKER = 'initial';
}
