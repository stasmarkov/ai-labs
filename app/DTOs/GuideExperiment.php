<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class GuideExperiment
{
    public function __construct(
        public string $title,
        /** @var list<array{id: string, text: string}> */
        public array $predictions,
        /** @var array{id: string, body: string, numbers?: string} */
        public array $answer,
        public string $page,
        /** @var array<string, string> */
        public array $params = [],
    ) {}
}
