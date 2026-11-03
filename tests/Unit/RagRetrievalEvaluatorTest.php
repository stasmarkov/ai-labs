<?php

declare(strict_types=1);

use App\DTOs\DocsMatch;
use App\DTOs\RagEvalExpectation;
use App\DTOs\RagEvalQuestion;
use App\Services\RagRetrievalEvaluator;

function retrievalQuestion(string $id, string $path, string $evidence, string $type = RagEvalQuestion::ON_TOPIC): RagEvalQuestion
{
    return new RagEvalQuestion($id, $type, "question {$id}", 'answer', [new RagEvalExpectation('laravel', $path, [$evidence])], [], 1);
}

function retrievalMatch(int $id, string $path, string $body, string $source = 'laravel'): DocsMatch
{
    return new DocsMatch($id, $source, $path, 'H', $body, 0.1, $id, 0.5, null, null);
}

it('AC-3 hits only when the document and an evidence phrase both match', function (): void {
    $question = retrievalQuestion('q1', 'cache.md', 'Cache::remember');

    expect(RagRetrievalEvaluator::isHit($question, retrievalMatch(1, 'cache.md', 'Use `Cache::remember` here')))->toBeTrue()
        ->and(RagRetrievalEvaluator::isHit($question, retrievalMatch(2, 'cache.md', 'Nothing relevant')))->toBeFalse()
        ->and(RagRetrievalEvaluator::isHit($question, retrievalMatch(3, 'queues.md', 'Use Cache::remember')))->toBeFalse()
        ->and(RagRetrievalEvaluator::isHit($question, retrievalMatch(4, 'cache.md', 'Cache::remember', 'repo')))->toBeFalse();
});

it('AC-3 scores MRR as 1 over the rank of the first hit', function (): void {
    $question = retrievalQuestion('q1', 'cache.md', 'remember');
    $ranked = ['q1' => [
        retrievalMatch(1, 'other.md', 'remember'),
        retrievalMatch(2, 'cache.md', 'unrelated'),
        retrievalMatch(3, 'cache.md', 'remember the value'),
        retrievalMatch(4, 'cache.md', 'remember again'),
    ]];

    $at2 = RagRetrievalEvaluator::score([$question], 'vector', 2, $ranked);
    $at3 = RagRetrievalEvaluator::score([$question], 'vector', 3, $ranked);

    expect($at2->hits)->toBe(0)->and($at2->mrr)->toBe(0.0)->and($at2->missedIds)->toBe(['q1'])
        ->and($at3->hits)->toBe(1)->and($at3->mrr)->toEqualWithDelta(1 / 3, 1e-9)->and($at3->missedIds)->toBe([]);
});

it('AC-3 scores recall with mixed hits and lists the missed ids', function (): void {
    $questions = [
        retrievalQuestion('q1', 'a.md', 'alpha'),
        retrievalQuestion('q2', 'b.md', 'beta'),
        retrievalQuestion('q3', 'c.md', 'gamma'),
        retrievalQuestion('off', 'z.md', 'zeta', RagEvalQuestion::OFF_TOPIC),
    ];
    $ranked = [
        'q1' => [retrievalMatch(1, 'a.md', 'alpha')],
        'q2' => [retrievalMatch(2, 'x.md', 'beta'), retrievalMatch(3, 'b.md', 'beta here')],
        'q3' => [retrievalMatch(4, 'c.md', 'no phrase')],
        'off' => [retrievalMatch(5, 'z.md', 'zeta')],
    ];

    $cell = RagRetrievalEvaluator::score($questions, 'hybrid', 6, $ranked);

    expect($cell->questions)->toBe(3)
        ->and($cell->hits)->toBe(2)
        ->and($cell->recall())->toEqualWithDelta(2 / 3, 1e-9)
        ->and($cell->mrr)->toEqualWithDelta((1 + 1 / 2 + 0) / 3, 1e-9)
        ->and($cell->missedIds)->toBe(['q3'])
        ->and($cell->toArray()['mode'])->toBe('hybrid');
});

it('AC-3 counts a question with no ranking as missed', function (): void {
    $cell = RagRetrievalEvaluator::score([retrievalQuestion('q1', 'a.md', 'alpha')], 'text', 3, []);

    expect($cell->hits)->toBe(0)->and($cell->missedIds)->toBe(['q1']);
});
