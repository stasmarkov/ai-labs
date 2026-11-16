<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class GuideStep
{
    /**
     * @param  list<string>  $moreSnippets  further snippets of a step that covers more than one piece of code
     */
    public function __construct(
        public string $title,
        public string $body,
        public ?string $event = null,
        public ?string $snippet = null,
        public array $moreSnippets = [],
    ) {}
}
