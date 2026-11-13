<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AdminTools\SearchListingsTool;
use App\Contracts\McpToolCallRepositoryInterface;
use App\DTOs\McpToolCallRecord;

/**
 * Logs every MCP tool call: the arguments the client sent, cut to a bounded size, and a summary of the result (counts
 * and ids, never the rows). The duration runs from the start timestamp the tool took with hrtime.
 */
final readonly class McpToolCallLogger
{
    public const MAX_ARGUMENT_CHARACTERS = 2000;

    private const SUMMARY_KEYS = ['total', 'id', 'tag', 'count', 'old_title', 'new_title', 'changed', 'already_tagged', 'status', 'error', 'change_id'];

    public function __construct(private McpToolCallRepositoryInterface $calls) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>|null  $summary
     * @param  string  $outcome  "ok", "error", "proposed" or "failed"
     */
    public function log(string $tool, array $arguments, string $outcome, ?array $summary, ?string $error, int $startedAt, ?string $pendingChangeId = null): void
    {
        $this->calls->record(new McpToolCallRecord(
            tool: $tool,
            arguments: $this->arguments($arguments),
            resultSummary: $summary,
            outcome: $outcome,
            error: $error,
            durationMs: (int) ((hrtime(true) - $startedAt) / 1_000_000),
            pendingChangeId: $pendingChangeId,
        ));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function summarise(array $result): array
    {
        $summary = array_intersect_key($result, array_flip(self::SUMMARY_KEYS));

        if (is_array($result['rows'] ?? null)) {
            $summary['shown'] = count($result['rows']);
            $summary['listing_ids'] = array_slice(array_values(array_filter(
                array_map(fn (mixed $row): mixed => is_array($row) ? ($row['id'] ?? null) : null, $result['rows']),
                is_int(...),
            )), 0, SearchListingsTool::MAX_LIMIT);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function arguments(array $arguments): array
    {
        $json = (string) json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return mb_strlen($json) <= self::MAX_ARGUMENT_CHARACTERS ? $arguments : ['truncated' => mb_substr($json, 0, self::MAX_ARGUMENT_CHARACTERS)];
    }
}
