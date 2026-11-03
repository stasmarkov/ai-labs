<?php

declare(strict_types=1);

use App\DTOs\DocsMatch;
use App\Services\DocsAnswerContext;

/**
 * Reads the shipped config file itself, so an AI_DOCS_ANSWER_* value in the local .env cannot hide a changed default.
 */
function shippedAnswerConfig(): array
{
    $config = require base_path('config/ai.php');

    return $config['docs']['answer'];
}

it('AC-3 and AC-8 ship the spec defaults for the gate and the budget', function (): void {
    $answer = shippedAnswerConfig();

    expect($answer['min_similarity'])->toBe(0.5)
        ->and($answer['max_context_tokens'])->toBe(4000);
})->skip(fn (): bool => filled(env('AI_DOCS_ANSWER_MIN_SIMILARITY')) || filled(env('AI_DOCS_ANSWER_MAX_CONTEXT_TOKENS')), 'an env override is set');

it('AC-3 gate at the default keeps a 0.5 chunk and drops a 0.49 chunk', function (): void {
    $answer = shippedAnswerConfig();
    $match = fn (int $id, float $similarity): DocsMatch => new DocsMatch($id, 'repo', "doc{$id}.md", 'H', 'body', 0.1, $id, $similarity, null, null);

    $kept = DocsAnswerContext::select([$match(1, 0.5), $match(2, 0.49)], $answer['min_similarity'], $answer['max_context_tokens']);

    expect(array_map(fn (DocsMatch $m): int => $m->id, $kept))->toBe([1]);
})->skip(fn (): bool => filled(env('AI_DOCS_ANSWER_MIN_SIMILARITY')), 'an env override is set');
