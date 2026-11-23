<?php

declare(strict_types=1);

use App\Ai\PlaygroundAgent;
use App\Contracts\PlaygroundRunRepositoryInterface;
use App\DTOs\NewPlaygroundRun;
use App\DTOs\NewPlaygroundVersion;
use App\DTOs\PlaygroundResultRecord;
use App\Enums\PlaygroundResultStatus;
use App\Models\PlaygroundPrompt;
use App\Models\PlaygroundPromptVersion;
use App\Models\PlaygroundResult;
use App\Models\PlaygroundRun;
use App\Services\PlaygroundPromptService;
use App\Services\PlaygroundTargetCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;

const PLAYGROUND_PROMPTS_URL = '/api/playground/prompts';
const PLAYGROUND_RUNS_URL = '/api/playground/runs';

function endpointReply(string $text): TextResponse
{
    return new TextResponse($text, new TextUsage(10, 5), new Meta('ollama', 'any'));
}

/**
 * @return array<string, mixed>
 */
function runPayload(array $overrides = []): array
{
    return $overrides + [
        'system_prompt' => 'Answer in one line.',
        'input' => 'What is a clutch?',
        'targets' => [
            ['provider' => 'ollama', 'model' => 'qwen3.5:9b'],
            ['provider' => 'ollama', 'model' => 'gemma4:12b'],
        ],
    ];
}

function storedRun(string $output = 'stored answer'): string
{
    $run = app(PlaygroundRunRepositoryInterface::class)->create(new NewPlaygroundRun(
        promptVersionId: null,
        systemPrompt: 'Answer in one line.',
        input: 'What is a clutch?',
        inputFlags: [],
        temperature: 0.2,
        maxTokens: 512,
        versionLabel: 'playground:unsaved',
        results: [
            new PlaygroundResultRecord(1, 'ollama', 'qwen3.5:9b', PlaygroundResultStatus::Ok, $output, null, 10, 5, null, 120, 'inv-1'),
            new PlaygroundResultRecord(2, 'ollama', 'gemma4:12b', PlaygroundResultStatus::Failed, null, 'connection refused', null, null, null, 30, null),
        ],
    ));

    return $run->id;
}

beforeEach(function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    Cache::flush();
    $this->withoutVite();
    Http::preventStrayRequests();
    Http::fake([
        '*/api/tags' => Http::response(['models' => [
            ['name' => 'qwen3.5:9b', 'details' => ['family' => 'qwen']],
            ['name' => 'gemma4:12b', 'details' => ['family' => 'gemma']],
        ]]),
        '*/api/show' => Http::response(['capabilities' => ['completion']]),
    ]);
    config([
        'ai.providers.anthropic.key' => 'sk-ant-test-key-value',
        'ai.providers.openai.key' => null,
        'ai.providers.gemini.key' => null,
    ]);
});

it('AC-7 shows the prompt list, a prompt with its versions newest first and a version added through the endpoints', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $created = $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => '  Car Writer ', 'system_prompt' => 'Write one line.', 'note' => 'first', 'temperature' => 0.5, 'max_tokens' => 256])->assertCreated();
    $id = $created->json('id');

    expect($created->json())->toMatchArray(['name' => 'car writer'])
        ->and($created->json('versions'))->toHaveCount(1)
        ->and($created->json('versions.0'))->toMatchArray(['version' => 1, 'system_prompt' => 'Write one line.', 'note' => 'first', 'temperature' => 0.5, 'max_tokens' => 256])
        ->and($created->json('versions.0'))->toHaveKeys(['id', 'created_at']);

    $this->postJson(PLAYGROUND_PROMPTS_URL.'/'.$id.'/versions', ['system_prompt' => 'Write two lines.'])->assertCreated()->assertJsonPath('version', 2);

    $show = $this->getJson(PLAYGROUND_PROMPTS_URL.'/'.$id)->assertOk();
    expect(array_column($show->json('versions'), 'version'))->toBe([2, 1]);

    $list = $this->getJson(PLAYGROUND_PROMPTS_URL)->assertOk();
    expect($list->json())->toHaveCount(1)
        ->and($list->json('0'))->toMatchArray(['id' => $id, 'name' => 'car writer', 'latest_version' => 2, 'versions_count' => 2])
        ->and($list->json('0'))->toHaveKey('updated_at');
});

it('AC-7 maps the prompt rules to 422 and 404 with a stable code and 422 field errors', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    $id = $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'writer', 'system_prompt' => 'Write.'])->assertCreated()->json('id');

    $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'writer', 'system_prompt' => 'Other.'])->assertStatus(422)->assertJsonPath('code', 'name_taken');
    $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => '!!', 'system_prompt' => 'Other.'])->assertStatus(422)->assertJsonPath('code', 'invalid_name');
    $this->postJson(PLAYGROUND_PROMPTS_URL.'/'.$id.'/versions', ['system_prompt' => "Write.\r\n"])->assertStatus(422)->assertJsonPath('code', 'unchanged');
    $this->postJson(PLAYGROUND_PROMPTS_URL.'/999999/versions', ['system_prompt' => 'New.'])->assertNotFound();
    $this->getJson(PLAYGROUND_PROMPTS_URL.'/999999')->assertNotFound();
    $this->getJson(PLAYGROUND_PROMPTS_URL.'/abc')->assertNotFound();

    $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'valid name', 'system_prompt' => ''])->assertStatus(422)->assertJsonValidationErrors('system_prompt');
    $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'valid name', 'system_prompt' => 'x', 'temperature' => 2.5])->assertStatus(422)->assertJsonValidationErrors('temperature');
    $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'valid name', 'system_prompt' => 'x', 'max_tokens' => 4096])->assertStatus(422)->assertJsonValidationErrors('max_tokens');
    $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'valid name', 'system_prompt' => 'x', 'note' => str_repeat('n', 201)])->assertStatus(422)->assertJsonValidationErrors('note');
    $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'valid name', 'system_prompt' => str_repeat('s', 8001)])->assertStatus(422)->assertJsonValidationErrors('system_prompt');
    $this->post(PLAYGROUND_PROMPTS_URL, ['name' => 'valid name', 'system_prompt' => 'x'])->assertStatus(415);
});

it('AC-8 answers 404 for every prompt and run endpoint outside the local environment, writes nothing and calls no model', function (string $environment): void {
    $id = $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'writer', 'system_prompt' => 'Write.'])->assertCreated()->json('id');
    $runId = storedRun();
    app()->detectEnvironment(fn (): string => $environment);

    $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'other', 'system_prompt' => 'Write.'])->assertNotFound();
    $this->postJson(PLAYGROUND_PROMPTS_URL.'/'.$id.'/versions', ['system_prompt' => 'Newer.'])->assertNotFound();
    $this->deleteJson(PLAYGROUND_PROMPTS_URL.'/'.$id)->assertNotFound();
    $this->getJson(PLAYGROUND_PROMPTS_URL)->assertNotFound();
    $this->getJson(PLAYGROUND_PROMPTS_URL.'/'.$id)->assertNotFound();
    $this->getJson(PLAYGROUND_RUNS_URL)->assertNotFound();
    $this->getJson(PLAYGROUND_RUNS_URL.'/'.$runId)->assertNotFound();

    PlaygroundAgent::fake()->preventStrayPrompts();
    $this->postJson(PLAYGROUND_RUNS_URL, runPayload())->assertNotFound();
    PlaygroundAgent::assertNeverPrompted();

    expect(PlaygroundPrompt::query()->count())->toBe(1)
        ->and(PlaygroundPromptVersion::query()->count())->toBe(1)
        ->and(PlaygroundRun::query()->count())->toBe(1);
})->with(['production', 'testing', 'staging']);

it('AC-8 deletes a prompt locally with 204 and keeps its runs', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    $id = $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'writer', 'system_prompt' => 'Write.'])->assertCreated()->json('id');
    $versionId = $this->getJson(PLAYGROUND_PROMPTS_URL.'/'.$id)->json('versions.0.id');

    PlaygroundAgent::fake(fn (): TextResponse => endpointReply('ok'))->preventStrayPrompts();
    $runId = $this->postJson(PLAYGROUND_RUNS_URL, runPayload(['system_prompt' => null, 'prompt_version_id' => $versionId]))->assertOk()->json('id');

    $this->deleteJson(PLAYGROUND_PROMPTS_URL.'/'.$id)->assertNoContent();
    $this->deleteJson(PLAYGROUND_PROMPTS_URL.'/'.$id)->assertNotFound();

    $this->getJson(PLAYGROUND_RUNS_URL.'/'.$runId)->assertOk()->assertJsonPath('version_label', 'playground:writer@v1')->assertJsonPath('prompt_version_id', null);
});

it('NFR-2 AC-8 refuses a cross-site write with 403 after the local gate and before validation', function (): void {
    $crossSite = ['Sec-Fetch-Site' => 'cross-site'];

    app()->detectEnvironment(fn (): string => 'production');
    $this->postJson(PLAYGROUND_PROMPTS_URL, [], $crossSite)->assertNotFound();

    app()->detectEnvironment(fn (): string => 'local');
    $id = $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'writer', 'system_prompt' => 'Write.'])->assertCreated()->json('id');

    $this->postJson(PLAYGROUND_PROMPTS_URL, [], $crossSite)->assertForbidden();
    $this->postJson(PLAYGROUND_PROMPTS_URL.'/'.$id.'/versions', [], $crossSite)->assertForbidden();
    $this->deleteJson(PLAYGROUND_PROMPTS_URL.'/'.$id, [], $crossSite)->assertForbidden();
    $this->postJson(PLAYGROUND_RUNS_URL, [], $crossSite)->assertForbidden();

    expect(PlaygroundPrompt::query()->count())->toBe(1);

    $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'second', 'system_prompt' => 'Write.'], ['Sec-Fetch-Site' => 'same-origin'])->assertCreated();
});

it('refuses a cross-site read of the stored prompts and runs with 403, and still answers same-site reads', function (): void {
    $crossSite = ['Sec-Fetch-Site' => 'cross-site'];
    $id = $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'writer', 'system_prompt' => 'Write.'])->assertCreated()->json('id');
    $runId = (string) Str::uuid7();

    $this->getJson(PLAYGROUND_PROMPTS_URL, $crossSite)->assertForbidden();
    $this->getJson(PLAYGROUND_PROMPTS_URL.'/'.$id, $crossSite)->assertForbidden();
    $this->getJson(PLAYGROUND_RUNS_URL, $crossSite)->assertForbidden();
    $this->getJson(PLAYGROUND_RUNS_URL.'/'.$runId, $crossSite)->assertForbidden();

    $this->getJson(PLAYGROUND_PROMPTS_URL, ['Sec-Fetch-Site' => 'same-origin'])->assertOk()->assertJsonCount(1);
    $this->getJson(PLAYGROUND_PROMPTS_URL.'/'.$id)->assertOk();
    $this->getJson(PLAYGROUND_RUNS_URL)->assertOk();
});

it('NFR-2 runs a comparison with 200 and the run shape, and calls each target once', function (): void {
    $calls = 0;
    PlaygroundAgent::fake(function () use (&$calls): TextResponse {
        $calls++;

        return endpointReply('one line');
    })->preventStrayPrompts();

    $response = $this->postJson(PLAYGROUND_RUNS_URL, runPayload())->assertOk();
    $data = $response->json();

    expect($calls)->toBe(2)
        ->and($data)->toHaveKeys(['id', 'version_label', 'system_prompt', 'input', 'input_flags', 'temperature', 'max_tokens', 'created_at', 'results'])
        ->and($data['version_label'])->toBe('playground:unsaved')
        ->and($data['results'])->toHaveCount(2)
        ->and($data['results'][0])->toHaveKeys(['position', 'provider', 'model', 'status', 'output', 'error', 'tokens_in', 'tokens_out', 'ttft_ms', 'duration_ms', 'ai_run_invocation_id'])
        ->and($data['results'][0])->toMatchArray(['position' => 1, 'model' => 'qwen3.5:9b', 'status' => 'ok', 'output' => 'one line', 'tokens_in' => 10, 'tokens_out' => 5]);
});

it('AC-4 answers 422 with a field error and calls nothing for a bad request or an unlisted target', function (): void {
    config(['ai.playground.per_minute' => 100]);
    PlaygroundAgent::fake()->preventStrayPrompts();

    $this->postJson(PLAYGROUND_RUNS_URL, runPayload(['targets' => [['provider' => 'ollama', 'model' => 'qwen3.5:9b']]]))->assertStatus(422)->assertJsonPath('code', 'invalid_target_count');
    $this->postJson(PLAYGROUND_RUNS_URL, runPayload(['targets' => [['provider' => 'ollama', 'model' => 'qwen3.5:9b'], ['provider' => 'ollama', 'model' => 'nope:1b']]]))->assertStatus(422)->assertJsonPath('code', 'model_not_available')->assertJsonValidationErrors('targets.1.model');
    $this->postJson(PLAYGROUND_RUNS_URL, runPayload(['targets' => [['provider' => 'openai', 'model' => 'gpt-5'], ['provider' => 'ollama', 'model' => 'qwen3.5:9b']]]))->assertStatus(422)->assertJsonPath('code', 'provider_not_enabled');
    $this->postJson(PLAYGROUND_RUNS_URL, runPayload(['input' => str_repeat('a', 4001)]))->assertStatus(422)->assertJsonValidationErrors('input');
    $this->postJson(PLAYGROUND_RUNS_URL, runPayload(['max_tokens' => 4096]))->assertStatus(422)->assertJsonValidationErrors('max_tokens');
    $this->postJson(PLAYGROUND_RUNS_URL, runPayload(['system_prompt' => null]))->assertStatus(422)->assertJsonPath('code', 'source_required');
    $this->postJson(PLAYGROUND_RUNS_URL, runPayload(['targets' => 'x']))->assertStatus(422)->assertJsonValidationErrors('targets');
    $this->post(PLAYGROUND_RUNS_URL, runPayload())->assertStatus(415);

    expect(PlaygroundRun::query()->count())->toBe(0);
});

it('NFR-1 NFR-2 limits runs per minute per IP with 429 and does not limit reads or prompt writes', function (): void {
    config(['ai.playground.per_minute' => 2]);
    PlaygroundAgent::fake(fn (): TextResponse => endpointReply('ok'))->preventStrayPrompts();

    $this->postJson(PLAYGROUND_RUNS_URL, runPayload())->assertOk();
    $this->postJson(PLAYGROUND_RUNS_URL, runPayload())->assertOk();
    $this->postJson(PLAYGROUND_RUNS_URL, runPayload())->assertStatus(429);

    foreach (range(1, 5) as $_) {
        $this->getJson(PLAYGROUND_RUNS_URL)->assertOk();
    }

    expect(config('ai.playground.per_minute'))->toBe(2)->and(PlaygroundRun::query()->count())->toBe(2);
});

it('NFR-1 weighs a run by its cloud targets against the cloud limiter and leaves local targets out of it', function (): void {
    config(['ai.cloud_per_minute' => 3, 'ai.cloud_global_per_minute' => 60, 'ai.playground.per_minute' => 30]);
    PlaygroundAgent::fake(fn (): TextResponse => endpointReply('ok'))->preventStrayPrompts();
    $cloud = runPayload(['targets' => [['provider' => 'anthropic', 'model' => 'claude-a'], ['provider' => 'anthropic', 'model' => 'claude-b']]]);

    $this->postJson(PLAYGROUND_RUNS_URL, $cloud)->assertOk();
    expect(RateLimiter::attempts('cloud-ai:'.RateLimiter::limiter('cloud-ai')(request())->key))->toBe(2);

    $this->postJson(PLAYGROUND_RUNS_URL, $cloud)->assertStatus(429);
    $this->postJson(PLAYGROUND_RUNS_URL, runPayload())->assertOk();
    $this->postJson(PLAYGROUND_RUNS_URL, runPayload(['targets' => [['provider' => 'ollama', 'model' => 'qwen3.5:9b'], ['provider' => 'anthropic', 'model' => 'claude-a']]]))->assertOk();

    expect(RateLimiter::attempts('cloud-ai:'.RateLimiter::limiter('cloud-ai')(request())->key))->toBe(3)
        ->and(PlaygroundRun::query()->count())->toBe(3);
});

it('AC-7 opens a stored run through the API and the page with no model call and no outbound request', function (): void {
    $id = storedRun();
    Http::fake();
    PlaygroundAgent::fake()->preventStrayPrompts();

    $api = $this->getJson(PLAYGROUND_RUNS_URL.'/'.$id)->assertOk()->json();
    Http::assertNothingSent();

    $page = $this->get('/ai/playground/'.$id)->assertOk()->original->getData()['page']['props']['run'];
    Http::assertNotSent(fn ($request): bool => preg_match('#/(api/chat|api/generate|v1/chat|v1/messages)#', $request->url()) === 1);
    expect($api['results'])->toHaveCount(2)
        ->and($api['results'][0])->toMatchArray(['output' => 'stored answer', 'ai_run_invocation_id' => 'inv-1', 'duration_ms' => 120])
        ->and($api['results'][1])->toMatchArray(['status' => 'failed', 'output' => null, 'error' => 'connection refused'])
        ->and($page)->toEqual($api);
    expect(PlaygroundRun::query()->count())->toBe(1)->and(PlaygroundResult::query()->count())->toBe(2);
});

it('AC-7 answers 404 for an unknown run and for an id that is not a UUID', function (): void {
    $this->getJson(PLAYGROUND_RUNS_URL.'/'.Str::uuid())->assertNotFound();
    $this->getJson(PLAYGROUND_RUNS_URL.'/not-a-uuid')->assertNotFound();
    $this->getJson(PLAYGROUND_RUNS_URL.'/1')->assertNotFound();
    $this->get('/ai/playground/'.Str::uuid())->assertNotFound();
    $this->get('/ai/playground/not-a-uuid')->assertNotFound();
});

it('AC-7 lists the latest runs newest first with the input cut to 80 characters and a limit of 1 to 50', function (): void {
    $first = storedRun();
    $second = storedRun();
    PlaygroundRun::query()->whereKey($first)->update(['created_at' => now()->subMinute(), 'input' => str_repeat('i', 120)]);

    $list = $this->getJson(PLAYGROUND_RUNS_URL)->assertOk()->json();

    expect(array_column($list, 'id'))->toBe([$second, $first])
        ->and($list[0])->toHaveKeys(['id', 'version_label', 'input', 'models', 'created_at'])
        ->and($list[0]['models'])->toBe(['ollama:qwen3.5:9b', 'ollama:gemma4:12b'])
        ->and(mb_strlen($list[1]['input']))->toBe(80);

    $this->getJson(PLAYGROUND_RUNS_URL.'?limit=1')->assertOk()->assertJsonCount(1);
    $this->getJson(PLAYGROUND_RUNS_URL.'?limit=0')->assertStatus(422)->assertJsonValidationErrors('limit');
    $this->getJson(PLAYGROUND_RUNS_URL.'?limit=51')->assertStatus(422)->assertJsonValidationErrors('limit');
});

it('NFR-2 returns model output raw as JSON and shows it in the page props as data, never as markup', function (): void {
    $payload = '<script>alert(1)</script> & "quoted"';
    $id = storedRun($payload);

    $api = $this->getJson(PLAYGROUND_RUNS_URL.'/'.$id)->assertOk();
    expect($api->json('results.0.output'))->toBe($payload);

    $response = $this->get('/ai/playground/'.$id)->assertOk();
    $html = (string) preg_replace('#data-page="[^"]*"#s', '', $response->getContent());

    expect($response->original->getData()['page']['props']['run']['results'][0]['output'])->toBe($payload)
        ->and($html)->not->toContain('<script>alert(1)')
        ->and((string) file_get_contents(resource_path('js/ai/Pages/Lab/Playground.vue')))->not->toContain('v-html');
});

it('AC-8 gives the page its prompts, targets and limits', function (): void {
    app(PlaygroundPromptService::class)->create('writer', new NewPlaygroundVersion('Write.'));

    $local = $this->get('/ai/playground')->assertOk()->original->getData()['page']['props'];

    expect($local)->not->toHaveKey('canSave')
        ->and($local['localOnlyCode'])->toBe('local_only')
        ->and($local['prompts'])->toHaveCount(1)
        ->and($local['run'])->toBeNull()
        ->and($local['recent'])->toBe([])
        ->and(array_column($local['providers'], 'name'))->toContain('ollama')
        ->and(array_column($local['providers'], 'name'))->not->toContain('openai')
        ->and($local['defaultTargets'])->toBe([['provider' => 'ollama', 'model' => 'qwen3.5:9b'], ['provider' => 'ollama', 'model' => 'gemma4:12b']])
        ->and($local['limits'])->toMatchArray(['input' => 4000, 'systemPrompt' => 8000, 'maxTokens' => ['min' => 16, 'max' => 2048]]);

    $ollama = collect($local['providers'])->firstWhere('name', 'ollama');
    expect($ollama['models'])->toContain('qwen3.5:9b', 'gemma4:12b');
});

it('AC-8 puts Playground in the lab navigation locally', function (): void {
    $navigation = $this->get('/ai/playground')->viewData('page')['props']['lab']['navigation'];

    expect(array_column(collect($navigation)->pluck('items')->flatten(1)->all(), 'href'))->toContain('/ai/playground');
});

it('AC-1 answers 409 version_conflict when another save takes the next version number and adds no extra version row', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    $id = $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'writer', 'system_prompt' => 'Write.'])->assertCreated()->json('id');

    $raced = false;
    PlaygroundPromptVersion::creating(function (PlaygroundPromptVersion $version) use (&$raced): void {
        if ($raced) {
            return;
        }

        $raced = true;
        DB::table('playground_prompt_versions')->insert([
            'playground_prompt_id' => $version->playground_prompt_id,
            'version' => $version->version,
            'system_prompt' => 'Rival save.',
            'created_at' => now(),
        ]);
    });

    $this->postJson(PLAYGROUND_PROMPTS_URL.'/'.$id.'/versions', ['system_prompt' => 'Write more.'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'version_conflict');

    expect($raced)->toBeTrue()
        ->and(PlaygroundPromptVersion::query()->where('playground_prompt_id', $id)->pluck('version')->all())->toBe([1]);

    $this->postJson(PLAYGROUND_PROMPTS_URL.'/'.$id.'/versions', ['system_prompt' => 'Write more.'])->assertCreated()->assertJsonPath('version', 2);
});

it('AC-1 refuses a temperature with more than two decimals on saves and on runs, and accepts two', function (): void {
    PlaygroundAgent::fake(fn (): TextResponse => endpointReply('ok'))->preventStrayPrompts();
    $id = $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'writer', 'system_prompt' => 'Write.', 'temperature' => 0.25])->assertCreated()->json('id');

    $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'other', 'system_prompt' => 'Write.', 'temperature' => 0.201])->assertStatus(422)->assertJsonValidationErrors('temperature');
    $this->postJson(PLAYGROUND_PROMPTS_URL.'/'.$id.'/versions', ['system_prompt' => 'Newer.', 'temperature' => 0.201])->assertStatus(422)->assertJsonValidationErrors('temperature');
    $this->postJson(PLAYGROUND_RUNS_URL, runPayload(['temperature' => 0.201]))->assertStatus(422)->assertJsonValidationErrors('temperature');

    $this->postJson(PLAYGROUND_PROMPTS_URL.'/'.$id.'/versions', ['system_prompt' => 'Newer.', 'temperature' => 0.21])->assertCreated()->assertJsonPath('temperature', 0.21);
    $this->postJson(PLAYGROUND_RUNS_URL, runPayload(['temperature' => 0.21]))->assertOk()->assertJsonPath('temperature', 0.21);

    expect(PlaygroundPromptVersion::query()->count())->toBe(2);
});

it('AC-1 treats a save with the same text and the same temperature as unchanged, exactly', function (): void {
    $id = $this->postJson(PLAYGROUND_PROMPTS_URL, ['name' => 'writer', 'system_prompt' => 'Write.', 'temperature' => 0.3])->assertCreated()->json('id');

    $this->postJson(PLAYGROUND_PROMPTS_URL.'/'.$id.'/versions', ['system_prompt' => 'Write.', 'temperature' => 0.3])->assertStatus(422)->assertJsonPath('code', 'unchanged');
    $this->postJson(PLAYGROUND_PROMPTS_URL.'/'.$id.'/versions', ['system_prompt' => 'Write.', 'temperature' => 0.31])->assertCreated();
});

it('AC-2 offers at most three default targets, so the first run cannot be refused for having too many', function (): void {
    config([
        'ai.providers.anthropic.models.text.available' => ['claude-a', 'claude-b', 'claude-c', 'claude-d'],
        'ai.playground.default_targets' => ['anthropic:claude-a', 'anthropic:claude-b', 'ollama:qwen3.5:9b', 'anthropic:claude-d'],
    ]);

    $targets = app(PlaygroundTargetCatalog::class)->defaultTargets();

    expect($targets)->toBe([
        ['provider' => 'anthropic', 'model' => 'claude-a'],
        ['provider' => 'anthropic', 'model' => 'claude-b'],
        ['provider' => 'ollama', 'model' => 'qwen3.5:9b'],
    ]);
});
