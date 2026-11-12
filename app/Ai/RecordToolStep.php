<?php

declare(strict_types=1);

namespace App\Ai;

use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * Logs each tool call of an agent run next to its ai_runs row. The result is reduced to a summary (counts and listing
 * ids), never stored whole, and long argument strings are cut.
 */
final readonly class RecordToolStep
{
    private const MAX_ARGUMENT_LENGTH = 200;

    private const MAX_IDS = 10;

    /**
     * The recorder is taken from the current container, as RecordAiRun does, so Octane's long-lived dispatcher cannot
     * hand a stale scoped instance to a later request.
     */
    private function recorder(): AiRunRecorder
    {
        return app(AiRunRecorder::class);
    }

    public function onInvoked(ToolInvoked $event): void
    {
        $result = is_string($event->result) ? json_decode($event->result, true) : null;
        $result = is_array($result) ? $result : null;
        $error = $result !== null && is_string($result['error'] ?? null) ? $result['error'] : null;

        $this->recorder()->toolStep(
            $event->invocationId,
            ToolNameResolver::resolve($event->tool),
            $this->arguments($event->arguments),
            $error === null ? 'ok' : 'error',
            $error,
            $result === null ? null : $this->summary($result),
            (int) round($event->time),
        );
    }

    public function onFailed(ToolFailed $event): void
    {
        $this->recorder()->toolStep(
            $event->invocationId,
            ToolNameResolver::resolve($event->tool),
            $this->arguments($event->arguments),
            'failed',
            $event->exception::class,
            null,
            (int) round($event->time),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function arguments(array $arguments): array
    {
        return array_map(fn (mixed $value): mixed => is_string($value) ? mb_substr($value, 0, self::MAX_ARGUMENT_LENGTH) : $value, $arguments);
    }

    /**
     * @param  array<array-key, mixed>  $result
     * @return array<string, mixed>
     */
    private function summary(array $result): array
    {
        $summary = [];

        foreach (['total', 'shown'] as $key) {
            if (is_int($result[$key] ?? null)) {
                $summary[$key] = $result[$key];
            }
        }

        if (is_array($result['listings'] ?? null)) {
            $summary['listing_ids'] = array_slice(array_values(array_filter(
                array_map(fn (mixed $listing): mixed => is_array($listing) ? ($listing['id'] ?? null) : null, $result['listings']),
                is_int(...),
            )), 0, self::MAX_IDS);
        }

        if (is_array($result['errors'] ?? null)) {
            $summary['invalid_fields'] = array_slice(array_map(strval(...), array_keys($result['errors'])), 0, self::MAX_IDS);
        }

        return $summary;
    }
}
