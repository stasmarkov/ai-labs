<?php

declare(strict_types=1);

use App\Ai\PhotoEncoderClient;
use App\Exceptions\PhotoEncoderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'ai.features.embeddings-photos.provider' => 'photo-encoder',
        'ai.features.embeddings-photos.model' => null,
        'ai.providers.photo-encoder.url' => 'http://encoder.test:7997',
    ]);
});

function textVector(int $dimensions = 512, float $value = 3.0): array
{
    return ['data' => [['index' => 0, 'embedding' => array_fill(0, $dimensions, $value)]]];
}

it('AC-1 sends the text with modality text and the configured model, and returns a unit vector', function (): void {
    Http::fake(['encoder.test:7997/embeddings' => Http::response(textVector())]);

    $vector = app(PhotoEncoderClient::class)->embedText('a photo of a red estate');

    expect($vector)->toHaveCount(512)
        ->and(sqrt(array_sum(array_map(fn (float $v): float => $v * $v, $vector))))->toEqualWithDelta(1.0, 1e-9);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://encoder.test:7997/embeddings'
        && $request['model'] === 'openai/clip-vit-base-patch32'
        && $request['modality'] === 'text'
        && $request['input'] === ['a photo of a red estate']);
});

it('AC-1 sends the model chosen for embeddings-photos', function (): void {
    config(['ai.features.embeddings-photos.model' => 'laion/CLIP-ViT-B-32-laion2B-s34B-b79K']);
    Http::fake(['encoder.test:7997/embeddings' => Http::response(textVector())]);

    app(PhotoEncoderClient::class)->embedText('a photo of a van');

    Http::assertSent(fn (Request $request): bool => $request['model'] === 'laion/CLIP-ViT-B-32-laion2B-s34B-b79K');
});

it('AC-1 raises when the text vector has the wrong dimensions', function (): void {
    Http::fake(['encoder.test:7997/embeddings' => Http::response(textVector(384))]);

    app(PhotoEncoderClient::class)->embedText('a photo of a van');
})->throws(PhotoEncoderException::class, '384 dimensions');

it('AC-1 raises on an error status, an empty answer and an unreachable encoder', function (): void {
    $calls = 0;
    Http::fake(['encoder.test:7997/embeddings' => function () use (&$calls) {
        return match (++$calls) {
            1 => Http::response([], 500),
            2 => Http::response(['data' => []]),
            default => throw new ConnectionException('refused'),
        };
    }]);

    $client = app(PhotoEncoderClient::class);

    expect(fn () => $client->embedText('a'))->toThrow(PhotoEncoderException::class, 'status 500')
        ->and(fn () => $client->embedText('a'))->toThrow(PhotoEncoderException::class)
        ->and(fn () => $client->embedText('a'))->toThrow(PhotoEncoderException::class, 'did not answer');
});

it('MNC-3 reads the photo search config and the prompt template exists', function (): void {
    expect(config('ai.photo_search.prompt'))->toBe('photo-search.v1')
        ->and(config('ai.photo_search.limit'))->toBe(12)
        ->and(config('ai.photo_search.candidates'))->toBe(40)
        ->and(config('ai.photo_search.min_score'))->toBeFloat()
        ->and(trim((string) file_get_contents(resource_path('prompts/photo-search.v1.md'))))->toBe('a photo of {query}');
});

it('AC-3 attempts a text encode once when the encoder is unreachable and raises PhotoEncoderException', function (): void {
    $attempts = 0;
    Http::fake(['encoder.test:7997/embeddings' => function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('refused');
    }]);

    expect(fn () => app(PhotoEncoderClient::class)->embedText('a photo of a van'))
        ->toThrow(PhotoEncoderException::class, 'did not answer')
        ->and($attempts)->toBe(1);
});

it('AC-3 gives a text encode the text timeout, capped by the provider timeout', function (): void {
    $timeouts = [];
    Http::fake(['encoder.test:7997/embeddings' => function ($request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'] ?? null;

        return Http::response(textVector());
    }]);
    $client = app(PhotoEncoderClient::class);

    expect(config('ai.providers.photo-encoder.text_timeout'))->toBe(10);

    $client->embedText('a photo of a van');
    config(['ai.providers.photo-encoder.timeout' => 4]);
    $client->embedText('a photo of a van');

    expect($timeouts)->toBe([10, 4]);
});
