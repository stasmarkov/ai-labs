<?php

declare(strict_types=1);

use App\Models\AiRun;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

it('embeddings use the configured provider, model and dimension', function (): void {
    expect(config('ai.default_for_embeddings'))->toBe('ollama')
        ->and(config('ai.providers.ollama.models.embeddings.default'))->toBe('bge-m3')
        ->and(config('ai.providers.ollama.models.embeddings.dimensions'))->toBe(1024);

    Embeddings::fake();

    $response = Embeddings::for(['one', 'two'])->generate();

    Embeddings::assertGenerated(fn ($prompt): bool => $prompt->provider->name() === 'ollama'
        && $prompt->model === 'bge-m3'
        && $prompt->dimensions === 1024);
    expect($response->embeddings)->toHaveCount(2)->and($response->embeddings[0])->toHaveCount(1024);
});

it('the embeddings provider and model follow the environment keys', function (): void {
    config([
        'ai.default_for_embeddings' => 'ollama',
        'ai.providers.ollama.models.embeddings.default' => 'other-embed',
    ]);
    Embeddings::fake();

    Embeddings::for(['one'])->generate();

    Embeddings::assertGenerated(fn ($prompt): bool => $prompt->model === 'other-embed');
});

it('AC-5 embedding calls write ai_runs rows', function (): void {
    Embeddings::fake();

    Embeddings::for(['one', 'two', 'three'])->generate();

    expect(AiRun::count())->toBe(1);

    $run = AiRun::firstOrFail();
    expect($run->invocation_id)->not->toBeEmpty()
        ->and($run->agent)->toBe('embeddings')
        ->and($run->provider)->toBe('ollama')
        ->and($run->model)->toBe('bge-m3')
        ->and($run->inputs)->toBe(3)
        ->and($run->tokens_in)->toBeNull()
        ->and($run->tokens_out)->toBeNull()
        ->and($run->status)->toBe('completed')
        ->and($run->latency_ms)->toBeInt()
        ->and($run->error_class)->toBeNull();

    Embeddings::fake(fn () => throw new RuntimeException('model is down'));

    expect(fn () => Embeddings::for(['one', 'two'])->generate())->toThrow(RuntimeException::class);

    expect(AiRun::count())->toBe(2);

    $failed = AiRun::where('status', 'failed')->firstOrFail();
    expect($failed->agent)->toBe('embeddings')
        ->and($failed->inputs)->toBe(2)
        ->and($failed->error_class)->toBe(RuntimeException::class)
        ->and($failed->error_message)->toBe('model is down');
});

it('AC-5 tokens in equals the count Ollama reports', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*/api/embed' => Http::response([
        'embeddings' => [array_fill(0, 1024, 0.1), array_fill(0, 1024, 0.2)],
        'prompt_eval_count' => 17,
    ])]);

    Embeddings::for(['one', 'two'])->generate();

    $run = AiRun::firstOrFail();
    expect($run->status)->toBe('completed')
        ->and($run->tokens_in)->toBe(17)
        ->and($run->tokens_out)->toBeNull();
});
