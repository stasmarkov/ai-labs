<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How one docs request uses the answer cache: not at all, store only (cache:false), or look up and store.
 */
enum DocsCacheMode: string
{
    case Off = 'off';
    case Bypass = 'bypass';
    case Lookup = 'lookup';
}
