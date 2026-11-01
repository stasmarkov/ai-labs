<?php

declare(strict_types=1);

namespace App\Debug\Leaks;

enum LeakMode: string
{
    case Leak = 'leak';
    case Fixed = 'fixed';
}
