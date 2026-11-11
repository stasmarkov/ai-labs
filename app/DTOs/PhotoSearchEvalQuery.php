<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoSearchEvalQuery
{
    /**
     * @param  array{make?: string, model?: string, body_type?: string, colour?: string}  $rule  every field must match
     */
    public function __construct(
        public string $id,
        public string $query,
        public array $rule,
    ) {}

    public function usesColour(): bool
    {
        return isset($this->rule['colour']);
    }
}
