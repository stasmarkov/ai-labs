<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The smallest piece the chunker packs: a paragraph, list item, sentence,
 * code block or table. The joiner is what precedes it when it is not first.
 */
final readonly class MarkdownAtom
{
    public function __construct(
        public string $text,
        public string $joiner,
        public bool $overlappable,
    ) {}
}
