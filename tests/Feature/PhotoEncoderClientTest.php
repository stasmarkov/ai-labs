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
        'ai.providers.photo-encoder.timeout' => 7,
    ]);
});

function encoderVectors(int $count, int $dimensions = 512): array
{
    return array_map(fn (int $index): array => ['index' => $index, 'embedding' => array_fill(0, $dimensions, 0.25)], range(0, $count - 1));
}

function encoderImages(int $count): array
{
    return array_map(fn (int $index): string => "image-bytes-{$index}", range(0, $count - 1));
}

it('AC-2 sends base64 data URLs to /embeddings with the model and returns one vector per image', function (): void {
    Http::fake(['encoder.test:7997/embeddings' => Http::response(['data' => encoderVectors(2)])]);

    $vectors = app(PhotoEncoderClient::class)->embed(encoderImages(2));

    expect($vectors)->toHaveCount(2)->and($vectors[0])->toHaveCount(512);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://encoder.test:7997/embeddings'
        && $request['model'] === 'openai/clip-vit-base-patch32'
        && $request['input'][0] === 'data:image/jpeg;base64,'.base64_encode('image-bytes-0')
        && count($request['input']) === 2);
});

it('AC-2 sends 20 images as batches of 16 and 4 and keeps their order', function (): void {
    Http::fake(['encoder.test:7997/embeddings' => function (Request $request) {
        $data = array_map(
            fn (int $index): array => ['index' => $index, 'embedding' => array_fill(0, 512, (float) strlen($request['input'][$index]))],
            array_keys($request['input']),
        );

        return Http::response(['data' => array_reverse($data)]);
    }]);

    $vectors = app(PhotoEncoderClient::class)->embed(encoderImages(20));

    expect($vectors)->toHaveCount(20);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => count($request['input']) === 16);
    Http::assertSent(fn (Request $request): bool => count($request['input']) === 4);
    expect($vectors[0][0])->toBe((float) strlen('data:image/jpeg;base64,'.base64_encode('image-bytes-0')));
});

it('AC-2 refuses a vector whose dimensions differ from the configured 512', function (): void {
    Http::fake(['encoder.test:7997/embeddings' => Http::response(['data' => encoderVectors(1, 768)])]);

    app(PhotoEncoderClient::class)->embed(encoderImages(1));
})->throws(PhotoEncoderException::class, 'returned 768 dimensions');

it('AC-2 refuses an answer with fewer vectors than images', function (): void {
    Http::fake(['encoder.test:7997/embeddings' => Http::response(['data' => encoderVectors(1)])]);

    app(PhotoEncoderClient::class)->embed(encoderImages(2));
})->throws(PhotoEncoderException::class, '1 vectors for 2 images');

it('AC-2 maps an error status to a typed exception without retrying it', function (): void {
    Http::fake(['encoder.test:7997/embeddings' => Http::response('boom', 500)]);

    expect(fn () => app(PhotoEncoderClient::class)->embed(encoderImages(1)))->toThrow(PhotoEncoderException::class, 'status 500');
    Http::assertSentCount(1);
});

it('AC-2 maps an unreachable encoder to a typed exception after retrying the connection', function (): void {
    $attempts = 0;
    Http::fake(['encoder.test:7997/embeddings' => function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('refused');
    }]);

    expect(fn () => app(PhotoEncoderClient::class)->embed(encoderImages(1)))->toThrow(PhotoEncoderException::class, 'did not answer')
        ->and($attempts)->toBe(2);
});

it('AC-2 refuses a feature switched to a provider that is not an image encoder', function (): void {
    config(['ai.features.embeddings-photos.provider' => 'ollama']);

    app(PhotoEncoderClient::class)->embed(encoderImages(1));
})->throws(PhotoEncoderException::class, 'not an image encoder');

it('AC-2 reports the label and health of the encoder', function (): void {
    Http::fake(['encoder.test:7997/health' => Http::response(['status' => 'ok'])]);

    $client = app(PhotoEncoderClient::class);

    expect($client->label())->toBe('photo-encoder/openai/clip-vit-base-patch32')
        ->and($client->dimensions())->toBe(512)
        ->and($client->healthy())->toBeTrue();
});

it('AC-2 reports an unreachable encoder as unhealthy', function (): void {
    Http::fake(['encoder.test:7997/health' => fn () => throw new ConnectionException('refused')]);

    expect(app(PhotoEncoderClient::class)->healthy())->toBeFalse();
});

it('MNC-4 every encoder call in the suite is faked', function (): void {
    Http::fake();

    app(PhotoEncoderClient::class)->healthy();

    Http::assertSentCount(1);
});
