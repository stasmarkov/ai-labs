<?php

declare(strict_types=1);

namespace App\Enums;

enum DocsSearchMode: string
{
    case Hybrid = 'hybrid';
    case Vector = 'vector';
    case Text = 'text';
}
