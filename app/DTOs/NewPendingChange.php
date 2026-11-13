<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * A change an outside client proposed, before it is stored: the write tool and its validated arguments, what the
 * developer will see, and when it stops being offered.
 */
final readonly class NewPendingChange
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $preview
     */
    public function __construct(
        public string $tool,
        public array $arguments,
        public array $preview,
        public CarbonImmutable $expiresAt,
        public string $source = 'mcp',
    ) {}
}
