<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\GuideFormatType;

final readonly class GuideFormatFile
{
    /**
     * @param  list<GuideFormatItem>  $items
     * @param  array<string, array{file: string, startLine: int, endLine: int, code: string}>  $snippets  Each code reference the items use, resolved once, in order of first use.
     */
    public function __construct(
        public GuideFormatType $type,
        public array $items,
        public array $snippets,
    ) {}
}
