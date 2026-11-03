<?php

declare(strict_types=1);

namespace App\Enums;

enum DocsCacheKind: string
{
    case Exact = 'exact';
    case Semantic = 'semantic';
}
