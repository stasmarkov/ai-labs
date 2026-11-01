<?php

declare(strict_types=1);

namespace App\Debug\Leaks;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;

/**
 * Caches formatted prices in the cache store, keyed by amount and locale.
 */
final class CachedPriceFormatter
{
    /**
     * @return array{locale: string, formatted: string}
     */
    public function format(int $amountCents, string $locale): array
    {
        return Cache::remember(
            "lab:price:{$amountCents}:{$locale}",
            60,
            fn (): array => [
                'locale' => $locale,
                'formatted' => (string) Number::currency($amountCents / 100, 'UAH', $locale),
            ],
        );
    }
}
