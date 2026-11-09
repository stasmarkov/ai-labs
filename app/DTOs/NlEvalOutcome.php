<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class NlEvalOutcome
{
    public const COMPLETED = 'completed';

    public const INVALID = 'invalid';

    public const REFUSED = 'refused';

    public const FAILED = 'failed';

    /**
     * @param  array<string, mixed>|null  $extracted  the model's six fields; null when the call was refused or failed
     * @param  list<string>  $unsupported
     */
    public function __construct(
        public string $status,
        public ?array $extracted = null,
        public array $unsupported = [],
        public ?string $invocationId = null,
    ) {}

    public function hasModelOutput(): bool
    {
        return $this->extracted !== null;
    }
}
