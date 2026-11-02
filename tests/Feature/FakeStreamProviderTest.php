<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;

use function Laravel\Ai\agent;

const FAKE_STREAM_PROMPT = "Car: 2019 Land Rover Defender\nMileage: 42000 km\nWrite a short ad.";

/**
 * @return list<object>
 */
function fakeStreamEvents(string $prompt = FAKE_STREAM_PROMPT): array
{
    $events = [];

    foreach (agent(instructions: 'Describe the car.')->stream($prompt) as $event) {
        $events[] = $event;
    }

    return $events;
}

it('AC-3 fake-stream emits the configured number of deltas', function (): void {
    $events = fakeStreamEvents();
    $deltas = array_values(array_filter($events, fn ($event): bool => $event instanceof TextDelta));

    expect($deltas)->toHaveCount(20)
        ->and($deltas[0]->delta)->toBe('2019');

    foreach (array_slice($deltas, 1) as $delta) {
        expect($delta->delta)->toStartWith(' ');
    }

    $text = implode('', array_map(fn (TextDelta $delta): string => $delta->delta, $deltas));
    expect($text)->toStartWith('2019 Land Rover Defender');

    $end = collect($events)->first(fn ($event): bool => $event instanceof StreamEnd);
    // Tokens in count the instructions ("Describe the car." = 3 words) plus the prompt.
    expect($end->usage->inputTokens)->toBe(3 + str_word_count(FAKE_STREAM_PROMPT, 0, '0123456789:'))
        ->and($end->usage->outputTokens)->toBe(20);
});

it('AC-3 fake-stream returns the same text when not streamed', function (): void {
    $streamed = TextDelta::combine(fakeStreamEvents());

    $response = agent(instructions: 'Describe the car.')->prompt(FAKE_STREAM_PROMPT);

    expect($response->text)->toBe($streamed)
        ->and($response->usage->outputTokens)->toBe(20);
});

it('AC-3 fake-stream throws after the configured number of tokens', function (): void {
    config(['ai.providers.fake-stream.fail_after' => 5]);

    $deltas = 0;

    try {
        foreach (agent(instructions: 'Describe the car.')->stream(FAKE_STREAM_PROMPT) as $event) {
            if ($event instanceof TextDelta) {
                $deltas++;
            }
        }
        $this->fail('The stream should have thrown.');
    } catch (RuntimeException $exception) {
        expect($deltas)->toBe(5);
    }
});

it('AC-4 provider and model come from config', function (): void {
    $start = collect(fakeStreamEvents())->first(fn ($event): bool => $event instanceof StreamStart);

    expect($start->provider)->toBe('fake-stream')
        ->and($start->model)->toBe(config('ai.providers.fake-stream.models.text.default'));

    config([
        'ai.default' => 'fake-instant',
        'ai.providers.fake-instant.models.text.default' => 'fake-slow-2',
    ]);

    $start = collect(fakeStreamEvents())->first(fn ($event): bool => $event instanceof StreamStart);

    expect($start->provider)->toBe('fake-instant')
        ->and($start->model)->toBe('fake-slow-2');

    $response = agent(instructions: 'Describe the car.')->prompt(FAKE_STREAM_PROMPT);
    expect($response->meta->provider)->toBe('fake-instant')
        ->and($response->meta->model)->toBe('fake-slow-2');
});

it('AC-3 fake-stream waits only between tokens, not before the first', function (): void {
    config(['ai.providers.fake-stream.tokens' => 3, 'ai.providers.fake-stream.delay_ms' => 100]);

    $started = hrtime(true);
    $firstAt = null;
    foreach (agent(instructions: 'Describe the car.')->stream(FAKE_STREAM_PROMPT) as $event) {
        if ($event instanceof TextDelta && $firstAt === null) {
            $firstAt = hrtime(true);
        }
    }
    $total = intdiv(hrtime(true) - $started, 1_000_000);

    expect(intdiv($firstAt - $started, 1_000_000))->toBeLessThan(80)
        ->and($total)->toBeGreaterThanOrEqual(200)->toBeLessThan(290);
});

it('AC-3 empty fake-stream env values fall back to the defaults', function (): void {
    $names = ['AI_FAKE_TOKENS', 'AI_FAKE_TOKEN_DELAY_MS', 'AI_FAKE_FAIL_AFTER'];
    $saved = array_map(fn (string $name): array => [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)], $names);

    try {
        foreach ($names as $name) {
            $_ENV[$name] = $_SERVER[$name] = '';
            putenv($name.'=');
        }
        $config = require config_path('ai.php');
    } finally {
        foreach ($names as $i => $name) {
            [$env, $server, $process] = $saved[$i];
            $env === null ? Arr::forget($_ENV, $name) : $_ENV[$name] = $env;
            $server === null ? Arr::forget($_SERVER, $name) : $_SERVER[$name] = $server;
            putenv($process === false ? $name : $name.'='.$process);
        }
    }

    expect($config['providers']['fake-stream']['tokens'])->toBe(200)
        ->and($config['providers']['fake-stream']['delay_ms'])->toBe(50)
        ->and($config['providers']['fake-stream']['fail_after'])->toBeNull();
});
