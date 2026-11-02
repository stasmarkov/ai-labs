<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\MarkdownUnitKind;

final readonly class MarkdownUnit
{
    public function __construct(
        public MarkdownUnitKind $kind,
        public string $text,
        public int $level = 0,
    ) {}
}
