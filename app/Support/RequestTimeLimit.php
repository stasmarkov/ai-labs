<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Raises PHP's execution limit for one long request without ever lowering it: a limit of 0 (unlimited, as in the
 * console and the test runner) stays unlimited, and a limit already above the one asked for is kept.
 */
final class RequestTimeLimit
{
    public static function atLeast(int $seconds): void
    {
        $current = (int) ini_get('max_execution_time');

        if ($current === 0 || $current >= $seconds) {
            return;
        }

        set_time_limit($seconds);
    }
}
