<?php

declare(strict_types=1);

namespace App\Debug\Leaks;

use Illuminate\Support\Number;

/**
 * Caches formatted prices in a static array keyed by amount only, so the entry survives every request in a worker.
 */
final class StaticPriceFormatter
{
    /** @var array<int, array{locale: string, formatted: string}> */
    private static array $cache = [];

    /**
     * @return array{locale: string, formatted: string}
     */
    public function format(int $amountCents, string $locale): array
    {
        return self::$cache[$amountCents] ??= [
            'locale' => $locale,
            'formatted' => (string) Number::currency($amountCents / 100, 'UAH', $locale),
        ];
    }

    public static function entries(): int
    {
        return count(self::$cache);
    }
}
