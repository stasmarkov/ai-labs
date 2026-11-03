<?php

declare(strict_types=1);

use App\Contracts\AiRunRepositoryInterface;
use App\DTOs\AiRunRecord;
use Illuminate\Support\Str;

function drcRun(string $status, ?int $historyTurns = null, ?string $cacheKind = null): void
{
    app(AiRunRepositoryInterface::class)->save(new AiRunRecord(
        invocationId: (string) Str::uuid(),
        agent: 'DocsAnswerAgent',
        provider: 'fake',
        model: 'm',
        promptVersion: 'docs-answer@v1',
        tokensIn: null,
        tokensOut: null,
        ttftMs: null,
        latencyMs: 10,
        status: $status,
        cacheKind: $cacheKind,
        savedLatencyMs: $status === 'cached' ? 5 : null,
        origin: 'api',
        historyTurns: $historyTurns,
    ));
}

it('the cache hit-rate counts only runs the cache could have served or stored', function (): void {
    drcRun('completed');
    drcRun('completed');
    drcRun('cached', cacheKind: 'exact');
    // never cache-eligible: they carry history
    drcRun('completed', historyTurns: 2);
    drcRun('refused', historyTurns: 2);
    // the user left before an answer existed: nothing could be served or stored
    drcRun('stopped');
    drcRun('stopped', historyTurns: 2);
    drcRun('refused');
    drcRun('no_context');

    $counts = app(AiRunRepositoryInterface::class)->docsCacheRuns(null);

    expect($counts->completed)->toBe(2)
        ->and($counts->hitsByKind)->toBe(['exact' => 1]);
});
