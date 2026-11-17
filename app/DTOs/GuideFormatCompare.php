<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class GuideFormatCompare
{
    /**
     * @param  array<string, string>  $outcomes  Learning outcome id to its text.
     * @param  list<array{name: string, meaning: string}>  $rubric
     * @param  list<GuideFormatMeta>  $entries  The five formats, in the order of the compare page.
     */
    public function __construct(
        public array $outcomes,
        public array $rubric,
        public GuideFormatMeta $baseline,
        public array $entries,
    ) {}
}
