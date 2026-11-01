<?php

declare(strict_types=1);

namespace App\Debug\Leaks;

/**
 * Remembers the first dealer it is given, a common memoisation pattern.
 */
final class CurrentDealer
{
    private ?int $dealer = null;

    public function resolve(int $dealer): int
    {
        return $this->dealer ??= $dealer;
    }
}
