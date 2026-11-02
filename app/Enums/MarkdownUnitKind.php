<?php

declare(strict_types=1);

namespace App\Enums;

enum MarkdownUnitKind: string
{
    case Heading = 'heading';
    case Paragraph = 'paragraph';
    case Items = 'items';
    case Table = 'table';
    case Code = 'code';
}
