<?php

declare(strict_types=1);

use App\Contracts\DocsSearchRepositoryInterface;
use App\DTOs\DocsHit;
use App\DTOs\RagEvalExpectation;
use App\DTOs\RagEvalQuestion;
use App\Enums\DocsSearchMode;
use App\Exceptions\RagEvalOptionException;
use App\Services\RagRetrievalEvaluator;
use Laravel\Ai\Embeddings;

/**
 * @return list<float>
 */
function evaluatorVector(): array
{
    return [1.0, ...array_fill(0, 1023, 0.0)];
}

function fakeDocsRepository(): DocsSearchRepositoryInterface
{
    return new class implements DocsSearchRepositoryInterface
    {
        public int $nearestCalls = 0;

        public int $textCalls = 0;

        public function nearest(array $vector, string $model, int $limit, int $efSearch): array
        {
            $this->nearestCalls++;

            return array_slice([
                new DocsHit(1, 'laravel', 'other.md', 'H', 'nothing', 0.8),
                new DocsHit(2, 'laravel', 'cache.md', 'H', 'Use Cache::remember', 0.7),
            ], 0, $limit);
        }

        public function textMatches(string $query, int $limit): array
        {
            $this->textCalls++;

            return array_slice([new DocsHit(2, 'laravel', 'cache.md', 'H', 'Use Cache::remember', 0.4)], 0, $limit);
        }
    };
}

/**
 * @return list<RagEvalQuestion>
 */
function evaluatorQuestions(): array
{
    return [
        new RagEvalQuestion('q1', RagEvalQuestion::ON_TOPIC, 'how to cache', 'a', [new RagEvalExpectation('laravel', 'cache.md', ['Cache::remember'])], [], 1),
        new RagEvalQuestion('off', RagEvalQuestion::OFF_TOPIC, 'pasta recipe', 'a', [], [], 2),
    ];
}

it('AC-3 embeds each question once and searches each mode once at the largest k', function (): void {
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => evaluatorVector(), $prompt->inputs));
    $repository = fakeDocsRepository();
    $this->app->instance(DocsSearchRepositoryInterface::class, $repository);

    $result = app(RagRetrievalEvaluator::class)->evaluate(
        evaluatorQuestions(),
        [DocsSearchMode::Vector, DocsSearchMode::Text],
        [1, 2, 6],
    );

    Embeddings::assertGenerated(fn ($prompt): bool => true);
    expect($repository->nearestCalls)->toBe(2)
        ->and($repository->textCalls)->toBe(2)
        ->and($result->cells)->toHaveCount(6)
        ->and($result->cells[0]->hits)->toBe(0)
        ->and($result->cells[1]->hits)->toBe(1)
        ->and($result->cells[1]->mrr)->toBe(0.5)
        ->and($result->cells[3]->mode)->toBe('text')
        ->and($result->cells[3]->hits)->toBe(1)
        ->and($result->topSimilarity)->toBe(['q1' => 0.8, 'off' => 0.8]);
});

it('AC-4 takes the vector top similarity even when vector mode is not requested', function (): void {
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => evaluatorVector(), $prompt->inputs));
    $this->app->instance(DocsSearchRepositoryInterface::class, fakeDocsRepository());

    $result = app(RagRetrievalEvaluator::class)->evaluate(evaluatorQuestions(), [DocsSearchMode::Text], [3]);

    expect($result->topSimilarity)->toBe(['q1' => 0.8, 'off' => 0.8]);
});

it('AC-3 rejects a k above the hybrid depth', function (): void {
    config(['ai.docs.rrf_depth' => 10]);

    app(RagRetrievalEvaluator::class)->evaluate(evaluatorQuestions(), [DocsSearchMode::Hybrid], [6, 11]);
})->throws(RagEvalOptionException::class, 'rrf_depth');

it('AC-3 names the missing modes, not the missing k, for an empty mode list', function (): void {
    app(RagRetrievalEvaluator::class)->evaluate(evaluatorQuestions(), [], [3]);
})->throws(RagEvalOptionException::class, 'retrieval mode');
