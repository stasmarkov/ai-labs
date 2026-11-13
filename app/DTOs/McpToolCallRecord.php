<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * One MCP tool call as logged: the arguments the client sent and a summary of the result, never the result itself.
 */
final readonly class McpToolCallRecord
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>|null  $resultSummary
     * @param  string  $outcome  "ok", "error", "proposed" or "failed"
     */
    public function __construct(
        public string $tool,
        public array $arguments,
        public ?array $resultSummary,
        public string $outcome,
        public ?string $error,
        public int $durationMs,
        public ?string $pendingChangeId,
        public ?CarbonImmutable $createdAt = null,
    ) {}
}
