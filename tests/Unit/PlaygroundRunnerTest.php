<?php

declare(strict_types=1);

use App\Ai\PlaygroundAgent;
use App\DTOs\PlaygroundRunRequest;
use App\DTOs\PlaygroundTarget;
use App\Exceptions\PlaygroundRunException;
use App\Services\PlaygroundRunner;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function fakeTargets(int $count = 2): array
{
    return array_slice([
        new PlaygroundTarget('fake-instant', 'fake-instant-1'),
        new PlaygroundTarget('fake-stream', 'fake-slow-1'),
        new PlaygroundTarget('ollama', 'qwen3.5:9b'),
        new PlaygroundTarget('ollama', 'gemma4:12b'),
    ], 0, $count);
}

function refusalOf(PlaygroundRunRequest $request): PlaygroundRunException
{
    try {
        app(PlaygroundRunner::class)->run($request);
    } catch (PlaygroundRunException $e) {
        return $e;
    }

    throw new RuntimeException('The run was not refused.');
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    PlaygroundAgent::fake()->preventStrayPrompts();
});

it('NFR-1 refuses more than 3 targets and fewer than 2', function (): void {
    expect(refusalOf(new PlaygroundRunRequest(null, 'Be brief.', 'Hi', fakeTargets(4)))->reason)->toBe('invalid_target_count')
        ->and(refusalOf(new PlaygroundRunRequest(null, 'Be brief.', 'Hi', fakeTargets(1)))->reason)->toBe('invalid_target_count');
});

it('NFR-1 refuses max_tokens above 2048 and an input above 4000 characters', function (): void {
    $tooManyTokens = refusalOf(new PlaygroundRunRequest(null, 'Be brief.', 'Hi', fakeTargets(), maxTokens: 2049));
    $tooLong = refusalOf(new PlaygroundRunRequest(null, 'Be brief.', str_repeat('a', 4001), fakeTargets()));

    expect($tooManyTokens->field)->toBe('max_tokens')
        ->and($tooLong->field)->toBe('input');
});

it('AC-2 needs exactly one prompt source', function (): void {
    expect(refusalOf(new PlaygroundRunRequest(null, null, 'Hi', fakeTargets()))->reason)->toBe('source_required')
        ->and(refusalOf(new PlaygroundRunRequest(1, 'Be brief.', 'Hi', fakeTargets()))->reason)->toBe('source_ambiguous');
});
