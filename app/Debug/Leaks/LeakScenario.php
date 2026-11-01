<?php

declare(strict_types=1);

namespace App\Debug\Leaks;

enum LeakScenario: string
{
    case DealerScope = 'dealer-scope';
    case PriceCache = 'price-cache';
    case NumberLocale = 'number-locale';
    case RequestAtBoot = 'request-at-boot';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $scenario): string => $scenario->value, self::cases());
    }
}
