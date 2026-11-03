<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class RagGrade
{
    public const INVALID_JSON = 'invalid_json';

    public const SCHEMA_MISMATCH = 'schema_mismatch';

    public const PROVIDER_ERROR = 'provider_error';

    /**
     * @param  string|null  $failure  one of the failure constants when the answer stayed ungraded
     * @param  string|null  $invocationId  of the last grader call, which holds the result or the failure
     */
    public function __construct(
        public ?int $correctness,
        public ?int $groundedness,
        public ?string $reason,
        public ?string $failure,
        public ?string $invocationId,
        public int $attempts,
    ) {}

    public function isGraded(): bool
    {
        return $this->failure === null;
    }
}
