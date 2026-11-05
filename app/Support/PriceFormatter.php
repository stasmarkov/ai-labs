<?php

declare(strict_types=1);

namespace App\Support;

final class PriceFormatter
{
    public static function format(int $priceCents, string $currency): string
    {
        return number_format($priceCents / 100, 2, '.', ',').' '.$currency;
    }
}
