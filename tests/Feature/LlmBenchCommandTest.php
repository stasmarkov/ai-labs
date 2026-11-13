<?php

declare(strict_types=1);

use App\Contracts\AiRunRepositoryInterface;
use App\Models\AiRun;
use App\Models\Dealer;
use App\Models\Listing;
use App\Services\ListingDetailService;
use App\Services\LlmEvalFigures;
use App\Support\LlmBenchRedactor;
use App\Support\LlmContextBench;
use App\Support\LlmSpeedBench;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

function llmBenchRoot(): string
{
    return storage_path('framework/testing/llm-bench');
}

/**
 * @param  list<string>  $contents
 */
function llmStream(array $contents, int $evalCount, int $evalDurationNs, int $promptCount = 100, int $promptDurationNs = 50_000_000, int $loadNs = 0): string
{
    $lines = array_map(fn (string $content): string => json_encode(['message' => ['role' => 'assistant', 'content' => $content], 'done' => false]), $contents);
    $lines[] = json_encode(['message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'load_duration' => $loadNs, 'prompt_eval_count' => $promptCount, 'prompt_eval_duration' => $promptDurationNs, 'eval_count' => $evalCount, 'eval_duration' => $evalDurationNs]);

    return implode("\n", $lines)."\n";
}

/**
 * @param  list<float>  $ticks
 */
function llmClock(array $ticks): void
{
    $queue = $ticks;
    app()->bind(LlmSpeedBench::class, fn () => new LlmSpeedBench(app(ListingDetailService::class), function () use (&$queue): float {
        return array_shift($queue) ?? 0.0;
    }));
}

beforeEach(function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    Http::preventStrayRequests();
    config(['ai.docs.eval_results_root' => llmBenchRoot(), 'ai.providers.ollama.url' => 'http://u:p4ss@ollama.test:11434']);
    File::deleteDirectory(llmBenchRoot());
    File::ensureDirectoryExists(llmBenchRoot());
    Listing::factory()->for(Dealer::factory()->create())->create(['id' => 1]);
});

afterEach(function (): void {
    File::deleteDirectory(llmBenchRoot());
});

function llmEvalFixture(string $feature, string $folder, array $data): void
{
    File::ensureDirectoryExists(llmBenchRoot()."/{$feature}/{$folder}");
    File::put(llmBenchRoot()."/{$feature}/{$folder}/run.json", json_encode($data));
}

function llmRunFile(): array
{
    $files = File::glob(llmBenchRoot().'/llm/*/run.json');

    return json_decode((string) File::get($files[0]), true);
}

test('AC-1 speed run records medians, minimum, maximum and tokens per second from scripted streams', function (): void {
    // Each stream reads the clock twice: at the start and at the first content chunk.
    llmClock([0, 500, 1000, 1100, 2000, 2300, 3000, 3200]);
    Http::fake([
        '*/api/version' => Http::response(['version' => '0.40.16']),
        '*/api/ps' => Http::sequence()
            ->push(['models' => [['name' => 'old:1b', 'model' => 'old:1b']]])
            ->push(['models' => [['name' => 'qwen3.5:9b', 'model' => 'qwen3.5:9b', 'size' => 8_000_000_000, 'size_vram' => 7_500_000_000]]]),
        '*/api/generate' => Http::response(['done' => true]),
        '*/api/chat' => Http::sequence()
            ->push(llmStream(['a', 'b'], 100, 1_000_000_000, loadNs: 2_500_000_000))
            ->push(llmStream(['a', 'b'], 100, 1_000_000_000))
            ->push(llmStream(['a'], 200, 1_000_000_000))
            ->push(llmStream(['a'], 150, 1_000_000_000, 100, 25_000_000)),
    ]);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--runs' => 3, '--only' => 'speed'])->assertSuccessful();

    $speed = llmRunFile()['models']['qwen3.5:9b']['speed'];

    expect($speed['load_ms'])->toEqual(2500.0)
        ->and($speed['cold_ttft_ms'])->toEqual(500.0)
        ->and($speed['ttft_ms'])->toEqual(['median' => 200.0, 'min' => 100.0, 'max' => 300.0])
        ->and($speed['generation_tokens_per_s'])->toEqual(['median' => 150.0, 'min' => 100.0, 'max' => 200.0])
        ->and($speed['prompt_eval_tokens_per_s']['median'])->toEqual(2000.0)
        ->and($speed['runs'])->toBe(3)
        ->and(llmRunFile()['metadata']['ollama_version'])->toBe('0.40.16')
        ->and(json_encode(llmRunFile()))->not->toContain('p4ss');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/generate') && $request['model'] === 'old:1b' && $request['keep_alive'] === 0);
});

test('AC-1 memory is read from api ps with only the measured model loaded', function (): void {
    llmClock(array_fill(0, 6, 1.0));
    Http::fake([
        '*/api/version' => Http::response(['version' => '0.40.16']),
        '*/api/ps' => Http::sequence()
            ->push(['models' => []])
            ->push(['models' => [['name' => 'gemma4:12b', 'size' => 12_000_000_000, 'size_vram' => 11_000_000_000]]]),
        '*/api/chat' => Http::response(llmStream(['x'], 10, 1_000_000_000)),
    ]);

    $this->artisan('llm:bench', ['--models' => 'gemma4:12b', '--runs' => 2, '--only' => 'speed'])->assertSuccessful();

    $speed = llmRunFile()['models']['gemma4:12b']['speed'];

    expect($speed['size'])->toBe(12_000_000_000)->and($speed['size_vram'])->toBe(11_000_000_000);
});

test('AC-1 every speed request starts with its own marker so the prompt cache cannot hide the prompt evaluation', function (): void {
    llmClock(array_fill(0, 6, 1.0));
    Http::fake([
        '*/api/version' => Http::response(['version' => '0.40.16']),
        '*/api/ps' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(llmStream(['x'], 10, 1_000_000_000)),
    ]);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--runs' => 2, '--only' => 'speed'])->assertSuccessful();

    $starts = [];
    Http::assertSent(function (Request $request) use (&$starts): bool {
        if (str_ends_with($request->url(), '/api/chat')) {
            $starts[] = substr((string) $request['messages'][0]['content'], 0, 40);
        }

        return true;
    });

    expect($starts)->toHaveCount(3)->and(array_unique($starts))->toHaveCount(3);
});

test('AC-4 eval figures are copied with source and date and null with a reason for a missing model', function (): void {
    llmClock(array_fill(0, 6, 1.0));
    llmEvalFixture('moderation', '2026-11-12', ['summary' => [
        'qwen3.5:9b' => ['json_valid_first_try' => ['rate' => 0.99], 'best_f1' => ['accuracy' => 0.91]],
    ]]);
    llmEvalFixture('moderation', '2026-11-01', ['summary' => [
        'gemma4:12b' => ['json_valid_first_try' => ['rate' => 0.5], 'best_f1' => ['accuracy' => 0.5]],
    ]]);
    llmEvalFixture('assistant', '2026-11-12', ['models' => [
        ['model' => 'qwen3.5:9b', 'argument_validity_first_try' => ['rate' => 1], 'filter_accuracy' => ['rate' => 0.958]],
    ]]);
    llmEvalFixture('nl-search', '2026-11-09', ['models' => [
        ['model' => 'qwen3.5:9b', 'queries' => 40, 'exact_match' => ['rate' => 0.95], 'validation_422' => ['count' => 0, 'rate' => 0], 'refused' => 0, 'failed' => 0],
    ]]);
    llmEvalFixture('photo-vision', '2026-11-11', ['models' => [
        ['model' => 'qwen3.5:9b', 'valid_first_attempt' => ['rate' => 1], 'body_type_agreement' => ['rate' => 0.82]],
    ]]);
    $before = collect(File::allFiles(llmBenchRoot()))->mapWithKeys(fn ($file): array => [$file->getPathname() => md5_file($file->getPathname())])->all();

    Http::fake([
        '*/api/version' => Http::response(['version' => '0.40.16']),
        '*/api/ps' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(llmStream(['x'], 10, 1_000_000_000)),
    ]);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b,gemma4:12b', '--runs' => 1, '--only' => 'speed'])->assertSuccessful();

    $evals = llmRunFile()['models'];

    expect($evals['qwen3.5:9b']['evals']['moderation'])->toBe([
        'json_valid' => 0.99, 'accuracy' => 0.91, 'accuracy_metric' => 'accuracy_at_best_f1',
        'source' => 'bench/results/moderation/2026-11-12/run.json', 'date' => '2026-11-12',
    ])
        ->and($evals['qwen3.5:9b']['evals']['assistant']['accuracy'])->toBe(0.958)
        ->and($evals['qwen3.5:9b']['evals']['nl-search']['json_valid'])->toEqual(1)
        ->and($evals['qwen3.5:9b']['evals']['photo-vision']['accuracy'])->toBe(0.82)
        ->and($evals['qwen3.5:9b']['evals']['photo-vision']['accuracy_metric'])->toBe('body_type_agreement')
        ->and($evals['gemma4:12b']['evals']['moderation']['date'])->toBe('2026-11-01')
        ->and($evals['gemma4:12b']['evals']['assistant']['json_valid'])->toBeNull()
        ->and($evals['gemma4:12b']['evals']['assistant']['source'])->toBeNull()
        ->and($evals['gemma4:12b']['evals']['assistant']['reason'])->toContain('gemma4:12b');

    $after = collect(File::allFiles(llmBenchRoot()))->reject(fn ($file): bool => str_contains($file->getPathname(), '/llm/'))->mapWithKeys(fn ($file): array => [$file->getPathname() => md5_file($file->getPathname())])->all();

    expect($after)->toBe($before);
});

test('AC-1 an existing day is refused without force and replaced with it', function (): void {
    llmClock(array_fill(0, 12, 1.0));
    Http::fake([
        '*/api/version' => Http::response(['version' => '0.40.16']),
        '*/api/ps' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(llmStream(['x'], 10, 1_000_000_000)),
    ]);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--runs' => 1, '--only' => 'speed'])->assertSuccessful();
    $file = File::glob(llmBenchRoot().'/llm/*/run.json')[0];
    File::put($file, '{"marker":true}');

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--runs' => 1, '--only' => 'speed'])->assertFailed();
    expect(File::get($file))->toBe('{"marker":true}');

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--runs' => 1, '--only' => 'speed', '--force' => true])->assertSuccessful();
    expect(json_decode((string) File::get($file), true))->toHaveKey('models');
});

test('NFR-3 an unreachable Ollama fails without a file and without leaking the url', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to http://u:p4ss@ollama.test:11434'));

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--runs' => 1, '--only' => 'speed'])
        ->expectsOutputToContain('Ollama did not answer')
        ->assertFailed();

    expect(File::glob(llmBenchRoot().'/llm/*/run.json'))->toBe([]);
});

/**
 * Answers the context prompts the way a model would: the prompt_eval_count of each call comes from $counts in order,
 * and the answer repeats the code of the first sentence only when $recalls says so for that call. $windows are the
 * context_length values /api/ps reports after the first and the second call.
 *
 * @param  list<int>  $counts
 * @param  list<bool>  $recalls
 * @param  list<int>|null  $windows
 */
function llmContextFake(array $counts, array $recalls, ?array $windows = null): void
{
    $call = 0;
    $ps = Http::sequence()->push(['models' => []]);

    foreach ($windows ?? [] as $window) {
        $ps->push(['models' => [['name' => 'qwen3.5:9b', 'model' => 'qwen3.5:9b', 'context_length' => $window]]]);
    }

    Http::fake([
        '*/api/version' => Http::response(['version' => '0.40.16']),
        '*/api/ps' => $windows === null ? Http::response(['models' => []]) : $ps,
        '*/api/generate' => Http::response(['done' => true]),
        '*/api/chat' => function (Request $request) use (&$call, $counts, $recalls) {
            preg_match('/The code is ([A-Z0-9]{6})\./', (string) $request['messages'][0]['content'], $match);
            $index = $call++;

            return Http::response(['message' => ['role' => 'assistant', 'content' => $recalls[$index] ? 'It was '.strtolower($match[1]).'.' : 'I do not know.'], 'done' => true, 'prompt_eval_count' => $counts[$index]]);
        },
    ]);
}

test('AC-2 context check sends one prompt of about 1.5 times num_ctx at the configured and at twice num_ctx', function (): void {
    config(['ai.providers.ollama.request_options.num_ctx' => 4096]);
    llmContextFake([4096, 6200], [false, true]);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--only' => 'context'])->assertSuccessful();

    $context = llmRunFile()['models']['qwen3.5:9b']['context'];

    expect($context['num_ctx'])->toBe(4096)
        ->and($context['estimated_prompt_tokens'])->toBeBetween(6000, 6300)
        ->and($context['configured']['num_ctx'])->toBe(4096)
        ->and($context['doubled']['num_ctx'])->toBe(8192);

    $chats = [];
    Http::assertSent(function (Request $request) use (&$chats): bool {
        if (str_ends_with($request->url(), '/api/chat')) {
            $chats[] = $request;
        }

        return true;
    });

    expect($chats)->toHaveCount(2)
        ->and($chats[0]['options']['num_ctx'])->toBe(4096)
        ->and($chats[1]['options']['num_ctx'])->toBe(8192)
        ->and($chats[0]['messages'][0]['content'])->toStartWith('The code is ')
        ->and($chats[0]['messages'][0]['content'])->toBe($chats[1]['messages'][0]['content'])
        ->and($chats[0]['think'])->toBeFalse()
        ->and(config('ai.providers.ollama.request_options.num_ctx'))->toBe(4096);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/generate') && $request['keep_alive'] === 0);
});

test('AC-2 truncation is detected when the prompt evaluated at the configured num_ctx is shorter than at twice num_ctx', function (): void {
    config(['ai.providers.ollama.request_options.num_ctx' => 4096]);
    llmContextFake([4096, 6200], [false, true]);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--only' => 'context'])->assertSuccessful();

    $context = llmRunFile()['models']['qwen3.5:9b']['context'];

    expect($context['configured']['prompt_eval_count'])->toBe(4096)
        ->and($context['configured']['truncated'])->toBeTrue()
        ->and($context['doubled']['prompt_eval_count'])->toBe(6200)
        ->and($context['doubled']['truncated'])->toBeFalse()
        ->and($context['over_window'])->toBeTrue();
});

test('AC-2 recall matches the code in the answer without regard to case and is false when it is missing', function (): void {
    config(['ai.providers.ollama.request_options.num_ctx' => 4096]);
    llmContextFake([4096, 6200], [false, true]);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--only' => 'context'])->assertSuccessful();

    $context = llmRunFile()['models']['qwen3.5:9b']['context'];

    expect($context['configured']['recalled'])->toBeFalse()->and($context['doubled']['recalled'])->toBeTrue();
});

test('AC-2 a prompt that fits keeps truncated false and an Ollama error leaves truncated and recalled null', function (): void {
    config(['ai.providers.ollama.request_options.num_ctx' => 4096]);
    $calls = 0;
    Http::fake([
        '*/api/version' => Http::response(['version' => '0.40.16']),
        '*/api/ps' => Http::response(['models' => []]),
        '*/api/generate' => Http::response(['done' => true]),
        '*/api/chat' => function () use (&$calls) {
            return ++$calls === 1
                ? Http::response(['error' => 'llama runner process has terminated'], 500)
                : Http::response(['message' => ['content' => 'x'], 'done' => true, 'prompt_eval_count' => 6200]);
        },
    ]);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--only' => 'context'])->assertSuccessful();

    $context = llmRunFile()['models']['qwen3.5:9b']['context'];

    expect($context['configured']['error'])->toContain('500')
        ->and($context['configured']['truncated'])->toBeNull()
        ->and($context['configured']['recalled'])->toBeNull()
        ->and($context['doubled']['error'])->toBeNull()
        ->and($context['doubled']['truncated'])->toBeFalse();
});

test('AC-2 the marker code is the same on the same day and differs between days', function (): void {
    $bench = app(LlmContextBench::class);

    expect($bench->code('2026-11-13'))->toMatch('/^[A-Z0-9]{6}$/')
        ->and($bench->code('2026-11-13'))->toBe($bench->code('2026-11-13'))
        ->and($bench->code('2026-11-14'))->not->toBe($bench->code('2026-11-13'));
});

/**
 * Plays the three faults of the failover matrix: a connection that is refused on the closed port, a 404 for the model
 * that is not pulled, and a timeout for everything else. The provider key is echoed back in the 404 body.
 */
function llmFailoverFake(?array &$seen = null): void
{
    $seen = [];
    Http::fake(function (Request $request) use (&$seen) {
        $url = $request->url();
        $seen[] = ['url' => $url, 'model' => $request['model'] ?? null, 'keep_alive' => $request['keep_alive'] ?? null];

        if (str_contains($url, '/api/version')) {
            return Http::response(['version' => '0.40.16']);
        }

        if (str_contains($url, '/api/ps')) {
            return Http::response(['models' => []]);
        }

        if (str_contains($url, '/api/generate')) {
            return Http::response(['done' => true]);
        }

        if (str_contains($url, '127.0.0.1:9')) {
            throw new ConnectionException('cURL error 7: Failed to connect to 127.0.0.1 port 9 after 0 ms: Connection refused');
        }

        if (($request['model'] ?? null) === 'llm-bench-missing:0') {
            return Http::response(['error' => "model 'llm-bench-missing:0' not found (key sk-test-secret-123)"], 404);
        }

        throw new ConnectionException('cURL error 28: Operation timed out after 1001 milliseconds with 0 bytes received');
    });
}

test('AC-3 failover records a row for each fault and entry point', function (): void {
    config(['ai.providers.ollama.key' => 'sk-test-secret-123']);
    llmFailoverFake();

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--only' => 'failover'])->assertSuccessful();

    $failover = llmRunFile()['models']['qwen3.5:9b']['failover'];

    expect(array_keys($failover))->toBe(['unreachable', 'model_missing', 'timeout']);

    foreach ($failover as $fault => $entries) {
        expect(array_keys($entries))->toBe(['describe', 'nl-search', 'moderation', 'assistant']);

        foreach ($entries as $entry => $row) {
            expect($row['status'])->toBeInt()->toBeGreaterThanOrEqual(200)
                ->and($row['time_to_fail_ms'])->toBeInt()->toBeGreaterThanOrEqual(0)
                ->and($row['failed_run_written'])->toBeTrue("{$fault} {$entry}")
                ->and($row['client']['kind'])->toBeIn(['json', 'sse'])
                ->and($row['invocation_ids'])->not->toBeEmpty();
        }
    }

    $nl = $failover['unreachable']['nl-search'];
    expect($nl['status'])->toBe(503)
        ->and($nl['client']['kind'])->toBe('json')
        ->and($nl['client']['message'])->toBe('Natural-language search is unavailable right now.')
        ->and($failover['model_missing']['moderation']['status'])->toBe(503)
        ->and($failover['timeout']['describe']['client'])->toMatchArray(['kind' => 'sse', 'event' => 'error'])
        ->and($failover['timeout']['assistant']['client']['event'])->toBe('error');
});

test('AC-3 failover sends each fault to the place it names and restores every config value afterwards', function (): void {
    $before = config()->all();
    llmFailoverFake($seen);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--only' => 'failover'])->assertSuccessful();

    expect(config()->all())->toBe($before);

    expect(collect($seen)->filter(fn (array $call): bool => str_contains($call['url'], '127.0.0.1:9'))->all())->toHaveCount(4)
        ->and(collect($seen)->where('model', 'llm-bench-missing:0')->all())->toHaveCount(4)
        ->and(collect($seen)->filter(fn (array $call): bool => str_ends_with($call['url'], '/api/generate') && $call['keep_alive'] === 0)->all())->not->toBeEmpty();
});

test('AC-3 failover reports the status of a model that answers 404 as the client sees it', function (): void {
    llmFailoverFake();

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--only' => 'failover'])->assertSuccessful();

    $rows = llmRunFile()['models']['qwen3.5:9b']['failover']['model_missing'];

    expect($rows['nl-search']['status'])->toBe(503)
        ->and($rows['moderation']['status'])->toBe(503)
        ->and($rows['describe']['status'])->toBe(200)
        ->and($rows['describe']['client']['event'])->toBe('error');
});

test('NFR-2 no key, Authorization value or key-bearing url reaches run.json, the output or the ai_runs messages of the failover', function (): void {
    config([
        'ai.providers.ollama.key' => 'sk-test-secret-123',
        'ai.providers.anthropic.key' => 'sk-ant-test-secret-456',
        'ai.providers.ollama.url' => 'http://u:p4ss@ollama.test:11434',
    ]);
    llmFailoverFake();

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--only' => 'context,failover'])->doesntExpectOutputToContain('p4ss')->doesntExpectOutputToContain('sk-test-secret-123')->doesntExpectOutputToContain('sk-ant-test-secret-456')->assertSuccessful();

    $written = (string) File::get(File::glob(llmBenchRoot().'/llm/*/run.json')[0]);

    foreach (['sk-test-secret-123', 'sk-ant-test-secret-456', 'p4ss', 'Authorization', 'Bearer'] as $secret) {
        expect($written)->not->toContain($secret);
    }

    expect(AiRun::count())->toBeGreaterThan(0);

    foreach (AiRun::all() as $run) {
        expect((string) $run->error_message)->not->toContain('sk-test-secret-123')->not->toContain('p4ss');
    }
});

test('NFR-2 the redactor removes url credentials, bearer tokens and configured keys', function (): void {
    config(['ai.providers.ollama.key' => 'plain-secret-value']);

    $text = LlmBenchRedactor::clean('GET http://u:p4ss@host:11434/api/chat failed, Authorization: Bearer abc.def, key plain-secret-value');

    expect($text)->not->toContain('p4ss')->not->toContain('abc.def')->not->toContain('plain-secret-value')->toContain('host:11434');
});

test('AC-4 nl-search validity does not count failed, refused or rejected queries as valid', function (): void {
    llmEvalFixture('nl-search', '2026-11-12', ['models' => [
        ['model' => 'qwen3.5:9b', 'queries' => 40, 'exact_match' => ['rate' => 0], 'validation_422' => ['count' => 0, 'rate' => 0], 'refused' => 0, 'failed' => 40],
        ['model' => 'gemma4:12b', 'queries' => 40, 'exact_match' => ['rate' => 0.5], 'validation_422' => ['count' => 4, 'rate' => 0.1], 'refused' => 2, 'failed' => 4],
        ['model' => 'old:1b', 'exact_match' => ['rate' => 0.5], 'validation_422' => ['rate' => 0]],
    ]]);

    $evals = app(LlmEvalFigures::class);

    expect($evals->forModel('qwen3.5:9b')['nl-search']['json_valid'])->toEqual(0)
        ->and($evals->forModel('gemma4:12b')['nl-search']['json_valid'])->toEqual(0.75)
        ->and($evals->forModel('old:1b')['nl-search']['json_valid'])->toBeNull()
        ->and($evals->forModel('old:1b')['nl-search']['reason'])->toContain('json_valid');
});

test('AC-4 photo-vision accuracy is the body type agreement, and null with the right reason when the run has none', function (): void {
    llmEvalFixture('photo-vision', '2026-11-11', ['models' => [
        ['model' => 'gemma4:12b', 'valid_first_attempt' => ['rate' => 1], 'body_type_agreement' => ['rate' => 0.74]],
        ['model' => 'qwen3.5:9b', 'valid_first_attempt' => ['rate' => 0.86]],
    ]]);

    $evals = app(LlmEvalFigures::class);

    expect($evals->forModel('gemma4:12b')['photo-vision'])->toMatchArray(['json_valid' => 1, 'accuracy' => 0.74, 'accuracy_metric' => 'body_type_agreement'])
        ->and($evals->forModel('gemma4:12b')['photo-vision'])->not->toHaveKey('reason')
        ->and($evals->forModel('qwen3.5:9b')['photo-vision']['accuracy'])->toBeNull()
        ->and($evals->forModel('qwen3.5:9b')['photo-vision']['reason'])->toBe('the vision eval reports validity only for this model');
});

test('AC-1 the speed table is printed with one row per model and num_ctx is in the run file', function (): void {
    llmClock(array_fill(0, 8, 1.0));
    Http::fake([
        '*/api/version' => Http::response(['version' => '0.40.16']),
        '*/api/ps' => Http::response(['models' => []]),
        '*/api/chat' => fn () => Http::response(llmStream(['x'], 123, 1_000_000_000)),
    ]);

    $exit = Artisan::call('llm:bench', ['--models' => 'qwen3.5:9b,gemma4:12b', '--runs' => 1, '--only' => 'speed']);
    $rows = collect(explode("\n", Artisan::output()))->filter(fn (string $line): bool => str_starts_with($line, '|') && str_contains($line, '123'));

    expect($exit)->toBe(0)
        ->and($rows)->toHaveCount(2)
        ->and($rows->filter(fn (string $line): bool => str_contains($line, 'qwen3.5:9b'))->count())->toBe(1)
        ->and($rows->filter(fn (string $line): bool => str_contains($line, 'gemma4:12b'))->count())->toBe(1);

    expect(llmRunFile()['metadata']['num_ctx'])->toBe(8192);
});

test('AC-1 a stream with no content chunk is a failed sample that is counted, explained and left out of the statistics', function (): void {
    llmClock([0, 500, 1000, 1100, 2000]);
    Http::fake([
        '*/api/version' => Http::response(['version' => '0.40.16']),
        '*/api/ps' => Http::response(['models' => []]),
        '*/api/chat' => Http::sequence()
            ->push(llmStream(['a'], 100, 1_000_000_000))
            ->push(llmStream(['a'], 100, 1_000_000_000))
            ->push(json_encode(['error' => 'llama runner crashed, api_key=hunter2hunter2'])."\n"),
    ]);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--runs' => 2, '--only' => 'speed'])->assertSuccessful();

    $speed = llmRunFile()['models']['qwen3.5:9b']['speed'];

    expect($speed['failed_runs'])->toBe(1)
        ->and($speed['ttft_ms'])->toEqual(['median' => 100.0, 'min' => 100.0, 'max' => 100.0])
        ->and($speed['errors'])->toHaveCount(1)
        ->and($speed['errors'][0])->toContain('llama runner crashed')->not->toContain('hunter2hunter2');
});

test('AC-1 the bench asks for its own keep_alive even when the app config says 0, so warm runs stay warm', function (): void {
    config(['ai.providers.ollama.request_options.keep_alive' => 0]);
    llmClock(array_fill(0, 8, 1.0));
    Http::fake([
        '*/api/version' => Http::response(['version' => '0.40.16']),
        '*/api/ps' => Http::response(['models' => []]),
        '*/api/chat' => fn () => Http::response(llmStream(['x'], 10, 1_000_000_000)),
    ]);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--runs' => 1, '--only' => 'speed'])->assertSuccessful();

    $kept = [];
    Http::assertSent(function (Request $request) use (&$kept): bool {
        if (str_ends_with($request->url(), '/api/chat')) {
            $kept[] = $request['keep_alive'];
        }

        return true;
    });

    expect($kept)->toBe(['5m', '5m']);
});

test('AC-2 a count that stays the same at twice num_ctx while over the effective window means a context shift is suspected', function (): void {
    config(['ai.providers.ollama.request_options.num_ctx' => 4096]);
    llmContextFake([6200, 6200], [true, true], [4096, 8192]);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--only' => 'context'])->assertSuccessful();

    $context = llmRunFile()['models']['qwen3.5:9b']['context'];

    expect($context['effective_context_length'])->toBe(4096)
        ->and($context['over_window'])->toBeTrue()
        ->and($context['configured']['truncated'])->toBeNull()
        ->and($context['configured']['context_shift_suspected'])->toBeTrue()
        ->and($context['configured']['num_ctx_applied'])->toBeTrue()
        ->and($context['doubled']['effective_context_length'])->toBe(8192)
        ->and($context['doubled']['num_ctx_applied'])->toBeTrue()
        ->and($context['doubled']['context_shift_suspected'])->toBeFalse()
        ->and($context['doubled']['truncated'])->toBeFalse();
});

test('AC-2 a doubled request that the runner did not apply is not reported as fitting', function (): void {
    config(['ai.providers.ollama.request_options.num_ctx' => 4096]);
    llmContextFake([6200, 6200], [true, true], [4096, 4096]);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--only' => 'context'])->assertSuccessful();

    $doubled = llmRunFile()['models']['qwen3.5:9b']['context']['doubled'];

    expect($doubled['num_ctx_applied'])->toBeFalse()
        ->and($doubled['context_shift_suspected'])->toBeTrue()
        ->and($doubled['truncated'])->toBeNull();
});

test('AC-2 a count that stays the same inside a window larger than num_ctx is not truncated and shows num_ctx was not applied', function (): void {
    config(['ai.providers.ollama.request_options.num_ctx' => 4096]);
    llmContextFake([6200, 6200], [true, true], [32768, 32768]);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--only' => 'context'])->assertSuccessful();

    $context = llmRunFile()['models']['qwen3.5:9b']['context'];

    expect($context['over_window'])->toBeFalse()
        ->and($context['configured']['truncated'])->toBeFalse()
        ->and($context['configured']['context_shift_suspected'])->toBeFalse()
        ->and($context['configured']['num_ctx_applied'])->toBeFalse();
});

test('AC-3 the timeout fault unloads the model before every entry point and sends the longest inputs the validators accept', function (): void {
    llmFailoverFake($seen);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--only' => 'failover'])->assertSuccessful();

    expect(collect($seen)->filter(fn (array $call): bool => str_ends_with($call['url'], '/api/generate') && $call['keep_alive'] === 0)->all())->toHaveCount(4);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/chat') && strlen((string) json_encode($request['messages'])) > 2000);

    $timeout = llmRunFile()['models']['qwen3.5:9b']['failover']['timeout'];

    foreach ($timeout as $row) {
        expect($row['answered'])->toBeFalse();
    }
});

test('AC-3 the failed run message is stored, cleaned again, and flagged when the cleaning changed it', function (): void {
    llmFailoverFake();

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--only' => 'failover'])->assertSuccessful();

    $row = llmRunFile()['models']['qwen3.5:9b']['failover']['model_missing']['nl-search'];

    expect($row['run_error_messages'])->toHaveCount(1)
        ->and($row['run_error_messages'][0])->toContain('llm-bench-missing')
        ->and($row['message_redacted'])->toBeBool()
        ->and($row)->not->toHaveKey('run_errors');

    $run = AiRun::query()->where('invocation_id', $row['invocation_ids'][0])->firstOrFail();
    $run->forceFill(['error_message' => 'failed at http://ollama.test/api?api_key=hunter2hunter2'])->save();

    expect(app(AiRunRepositoryInterface::class)->storedErrorMessage($row['invocation_ids'][0]))->toContain('hunter2hunter2')
        ->and(app(AiRunRepositoryInterface::class)->storedErrorMessage('missing'))->toBeNull()
        ->and(LlmBenchRedactor::clean((string) $run->error_message))->not->toContain('hunter2hunter2');
});

test('AC-1 a missing listing 1 is one specific error for every section; the sections still write, with an error each, and the exit is non-zero', function (): void {
    Listing::query()->whereKey(1)->delete();
    Http::fake([
        '*/api/version' => Http::response(['version' => '0.40.16']),
        '*/api/ps' => Http::response(['models' => []]),
        '*/api/generate' => Http::response(['done' => true]),
        '*/api/chat' => Http::response(['message' => ['content' => 'x'], 'done' => true, 'prompt_eval_count' => 6200]),
    ]);

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--runs' => 1])
        ->expectsOutputToContain('Listing 1 is needed')
        ->assertFailed();

    $model = llmRunFile()['models']['qwen3.5:9b'];

    expect($model['speed']['error'])->toContain('Listing 1 is needed')
        ->and($model['failover']['error'])->toContain('Listing 1 is needed')
        ->and($model['context']['configured']['prompt_eval_count'])->toBe(6200)
        ->and($model['evals'])->toHaveKey('nl-search');
});

test('NFR-1 the command refuses to run outside the local environment', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    Http::fake();

    $this->artisan('llm:bench', ['--models' => 'qwen3.5:9b', '--only' => 'speed'])
        ->expectsOutputToContain('local environment')
        ->assertExitCode(2);

    Http::assertNothingSent();
    expect(File::glob(llmBenchRoot().'/llm/*/run.json'))->toBe([]);
});

test('NFR-2 the redactor covers bearer tokens, key headers, key query parameters, url user info and key shapes', function (string $input, string $secret): void {
    expect(LlmBenchRedactor::clean($input))->not->toContain($secret);
})->with([
    'bearer' => ['failed with Bearer abcDEF123.xyz_456 in header', 'abcDEF123'],
    'authorization header' => ['Authorization: Basic dXNlcjpwYXNz', 'dXNlcjpwYXNz'],
    'authorization json' => ['{"Authorization":"Bearer tok-123456789"}', 'tok-123456789'],
    'x-api-key header' => ['x-api-key: topsecretvalue99', 'topsecretvalue99'],
    'api-key header' => ['api-key=topsecretvalue99 sent', 'topsecretvalue99'],
    'key query' => ['GET https://h.test/v1/models?key=topsecretvalue99&alt=json', 'topsecretvalue99'],
    'api_key query' => ['GET https://h.test/v1?x=1&api_key=topsecretvalue99', 'topsecretvalue99'],
    'userinfo without password' => ['http://tokenvalue12345@host.test:11434/api', 'tokenvalue12345'],
    'userinfo with password containing at' => ['http://u:p@ss@host.test:11434/api', 'p@ss'],
    'sk key' => ['invalid key sk-proj-abcdefghijklmnop', 'abcdefghijklmnop'],
    'sk-ant key' => ['invalid key sk-ant-api03-abcdefghijklmnop', 'abcdefghijklmnop'],
    'AIza key' => ['bad key AIzaSyA1234567890abcdefghij', 'A1234567890abcdefghij'],
]);

test('NFR-2 the redactor keeps the harmless parts of a message', function (): void {
    expect(LlmBenchRedactor::clean('GET http://u:p4ss@host.test:11434/api/chat?model=qwen3.5:9b answered 500'))
        ->toBe('GET http://host.test:11434/api/chat?model=qwen3.5:9b answered 500');
});
