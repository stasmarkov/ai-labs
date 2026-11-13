<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * A stored pending change. `status` is the stored value: a pending row past `expiresAt` is still "pending" here until
 * a decision on it is attempted.
 */
final readonly class PendingChangeRecord
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>|null  $result
     */
    public function __construct(
        public string $id,
        public string $source,
        public string $tool,
        public array $arguments,
        public array $preview,
        public string $status,
        public ?array $result,
        public ?string $error,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $decidedAt,
        public CarbonImmutable $createdAt,
    ) {}
}
