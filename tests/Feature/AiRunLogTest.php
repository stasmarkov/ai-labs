<?php

declare(strict_types=1);

use App\Ai\AiRunRecorder;
use App\Models\AiRun;
use App\Models\Dealer;
use App\Models\Listing;

/**
 * @return list<string>
 */
function describeLines(string $body): array
{
    return array_values(array_filter(explode("\n", $body), fn (string $line): bool => $line !== ''));
}

it('AC-5 a completed stream writes one ai_runs row', function (): void {
    $listing = Listing::factory()->for(Dealer::factory())->create();

    $this->get('/listings/'.$listing->id.'/describe')->streamedContent();

    expect(AiRun::count())->toBe(1);

    $run = AiRun::firstOrFail();
    expect($run->invocation_id)->not->toBeEmpty()
        ->and($run->agent)->toBe('CarDescriptionAgent')
        ->and($run->provider)->toBe('fake-stream')
        ->and($run->model)->toBe(config('ai.providers.fake-stream.models.text.default'))
        ->and($run->prompt_version)->toBe('car-description@v1')
        ->and($run->tokens_in)->toBeGreaterThan(0)
        ->and($run->tokens_out)->toBe(20)
        ->and($run->ttft_ms)->toBeInt()
        ->and($run->latency_ms)->toBeGreaterThanOrEqual($run->ttft_ms)
        ->and($run->status)->toBe('completed')
        ->and($run->error_class)->toBeNull()
        ->and($run->error_message)->toBeNull();
});

it('AC-4 the row records the provider and model currently configured', function (): void {
    config([
        'ai.default' => 'fake-instant',
        'ai.providers.fake-instant.models.text.default' => 'fake-slow-2',
    ]);
    $listing = Listing::factory()->for(Dealer::factory())->create();

    $this->get('/listings/'.$listing->id.'/describe')->streamedContent();

    $run = AiRun::firstOrFail();
    expect($run->provider)->toBe('fake-instant')->and($run->model)->toBe('fake-slow-2');
});

it('AC-6 a failing provider writes a failed ai_runs row', function (int $failAfter): void {
    config(['ai.providers.fake-stream.fail_after' => $failAfter]);
    $listing = Listing::factory()->for(Dealer::factory())->create();

    $body = $this->get('/listings/'.$listing->id.'/describe')->streamedContent();

    $lines = describeLines($body);
    expect(end($lines))->toBe('data: [DONE]');

    $error = json_decode(substr($lines[count($lines) - 2], 6), true);
    expect($error['type'])->toBe('error')
        ->and($error['message'])->toBe('The description could not be generated.');

    expect(AiRun::count())->toBe(1);

    $run = AiRun::firstOrFail();
    expect($run->status)->toBe('failed')
        ->and($run->error_class)->toBe(RuntimeException::class)
        ->and(strlen((string) $run->error_message))->toBeLessThanOrEqual(1000)
        ->and($run->error_message)->toContain("failed after {$failAfter} tokens")
        ->and($run->tokens_in)->toBeNull()
        ->and($run->tokens_out)->toBeNull()
        ->and($run->latency_ms)->toBeInt();

    $failAfter === 0 ? expect($run->ttft_ms)->toBeNull() : expect($run->ttft_ms)->toBeInt();
})->with([0, 5]);

it('AC-6 a long failure message is cut to 1000 characters', function (): void {
    $recorder = app(AiRunRecorder::class);
    $recorder->started('inv-1', 'CarDescriptionAgent', 'fake-stream', 'fake-slow-1', 'car-description@v1');
    $recorder->failed('inv-1', new RuntimeException(str_repeat('x', 5000)));

    expect(strlen((string) AiRun::firstOrFail()->error_message))->toBe(1000);
});

it('AC-2 an unknown listing writes no ai_runs row', function (): void {
    $this->get('/listings/999999/describe')->assertNotFound();

    expect(AiRun::count())->toBe(0);
});

it('AC-5 the run recorder is scoped to the request', function (): void {
    $first = app(AiRunRecorder::class);

    expect(app(AiRunRecorder::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(AiRunRecorder::class))->not->toBe($first);
});

it('AC-4 AI_PROVIDER and AI_MODEL from the environment pick the provider and model', function (): void {
    $original = config('ai');
    $previous = ['AI_PROVIDER' => getenv('AI_PROVIDER'), 'AI_MODEL' => getenv('AI_MODEL')];

    try {
        foreach (['AI_PROVIDER' => 'fake-instant', 'AI_MODEL' => 'fake-slow-2'] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        config(['ai' => require config_path('ai.php')]);
        $listing = Listing::factory()->for(Dealer::factory())->create();

        $this->get('/listings/'.$listing->id.'/describe')->streamedContent();

        $run = AiRun::firstOrFail();
        expect($run->provider)->toBe('fake-instant')
            ->and($run->model)->toBe('fake-slow-2')
            ->and($run->tokens_out)->toBe(20);
    } finally {
        foreach ($previous as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$value);
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
        config(['ai' => $original]);
    }
});
