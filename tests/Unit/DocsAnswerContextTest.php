<?php

declare(strict_types=1);

use App\DTOs\DocsMatch;
use App\Services\DocsAnswerContext;

function contextMatch(int $id, ?float $similarity, ?int $textRank, string $body = 'body'): DocsMatch
{
    return new DocsMatch($id, 'repo', "doc{$id}.md", 'H', $body, 0.1, $similarity === null ? null : $id, $similarity, $textRank, $textRank === null ? null : 0.5);
}

function ids(array $matches): array
{
    return array_map(fn (DocsMatch $m): int => $m->id, $matches);
}

it('AC-3 gate keeps vector-only and text-only chunks and drops the rest', function (): void {
    $matches = [
        contextMatch(1, 0.6, null),
        contextMatch(2, 0.2, 1),
        contextMatch(3, null, 2),
        contextMatch(4, 0.3, null),
    ];

    expect(ids(DocsAnswerContext::select($matches, 0.45, 1000)))->toBe([1, 2, 3]);
});

it('AC-3 gate returns nothing when no chunk passes', function (): void {
    expect(DocsAnswerContext::select([contextMatch(1, 0.1, null), contextMatch(2, null, null)], 0.45, 1000))->toBe([]);
});

it('AC-8 keeps the context within budget dropping the lowest ranked first', function (): void {
    $body = str_repeat('a', 400);
    $matches = [contextMatch(1, 0.9, 1, $body), contextMatch(2, 0.8, 2, $body), contextMatch(3, 0.7, 3, $body)];

    expect(ids(DocsAnswerContext::select($matches, 0.45, 250)))->toBe([1, 2]);
    expect(ids(DocsAnswerContext::select($matches, 0.45, 100)))->toBe([1]);
});

it('AC-8 keeps and cuts an oversize top chunk', function (): void {
    $matches = [contextMatch(1, 0.9, 1, str_repeat('a', 1000)), contextMatch(2, 0.8, 2, 'short')];

    $kept = DocsAnswerContext::select($matches, 0.45, 50);

    expect(ids($kept))->toBe([1])
        ->and(mb_strlen($kept[0]->body))->toBe(200);
});

it('AC-8 cuts an oversize top chunk at the last whitespace before the budget', function (): void {
    $matches = [contextMatch(1, 0.9, 1, str_repeat('héllo wörld ', 100))];

    $body = DocsAnswerContext::select($matches, 0.45, 50)[0]->body;

    expect(mb_strlen($body))->toBeLessThanOrEqual(200)
        ->and(mb_check_encoding($body, 'UTF-8'))->toBeTrue()
        ->and($body)->toMatch('/(héllo|wörld)$/u')
        ->and(preg_match('/^(héllo wörld )*(héllo|héllo wörld)$/u', $body))->toBe(1);
});

it('AC-3 does not let a full-text hit alone pass when the question uses OR or NOT operators', function (string $question, bool $operators): void {
    $matches = [contextMatch(1, 0.3, 1)];

    expect(DocsAnswerContext::usesSearchOperators($question))->toBe($operators)
        ->and(ids(DocsAnswerContext::select($matches, 0.5, 1000, ! $operators)))->toBe($operators ? [] : [1]);
})->with([
    ['who won the world cup or the final', true],
    ['rate limiting -redis', true],
    ['how does rate limiting work', false],
    ['which order do providers boot in', false],
]);
