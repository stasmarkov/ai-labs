<?php

declare(strict_types=1);

use App\Contracts\PlaygroundPromptRepositoryInterface;
use App\Contracts\PlaygroundRunRepositoryInterface;
use App\DTOs\NewPlaygroundRun;
use App\DTOs\NewPlaygroundVersion;
use App\DTOs\PlaygroundResultRecord;
use App\Enums\PlaygroundResultStatus;
use App\Exceptions\PlaygroundPromptException;
use App\Models\PlaygroundPrompt;
use App\Models\PlaygroundPromptVersion;
use App\Models\PlaygroundResult;
use App\Models\PlaygroundRun;
use App\Services\PlaygroundPromptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->service = app(PlaygroundPromptService::class);
});

it('AC-1 saves a new prompt as version 1 and the next saves as 2 and 3, leaving earlier versions as they were', function (): void {
    $prompt = $this->service->create('Car Pitch', new NewPlaygroundVersion('Sell the car.', 'first'));

    expect($prompt->name)->toBe('car pitch')
        ->and($prompt->versions)->toHaveCount(1)
        ->and($prompt->versions[0]->version)->toBe(1)
        ->and($prompt->versions[0]->label())->toBe('playground:car pitch@v1');

    $second = $this->service->addVersion($prompt->id, new NewPlaygroundVersion('Sell the car briefly.', 'shorter', 0.0, 256));
    $third = $this->service->addVersion($prompt->id, new NewPlaygroundVersion('Sell the car in one line.'));

    expect($second->version)->toBe(2)->and($third->version)->toBe(3);

    $versions = $this->service->find($prompt->id)->versions;

    expect(array_map(fn ($version): int => $version->version, $versions))->toBe([3, 2, 1])
        ->and($versions[2]->systemPrompt)->toBe('Sell the car.')
        ->and($versions[2]->note)->toBe('first')
        ->and($versions[2]->temperature)->toBe(0.2)
        ->and($versions[2]->maxTokens)->toBe(512)
        ->and($versions[1]->temperature)->toBe(0.0)
        ->and($versions[1]->maxTokens)->toBe(256);
});

it('AC-1 has no update path, so a stored version is never rewritten by a save', function (): void {
    $prompt = $this->service->create('immutable', new NewPlaygroundVersion('Original text.'));
    $this->service->addVersion($prompt->id, new NewPlaygroundVersion('Changed text.'));

    $first = PlaygroundPromptVersion::query()->where('playground_prompt_id', $prompt->id)->where('version', 1)->firstOrFail();

    expect($first->system_prompt)->toBe('Original text.')
        ->and(PlaygroundPromptVersion::query()->count())->toBe(2)
        ->and(get_class_methods(PlaygroundPromptRepositoryInterface::class))->not->toContain('updateVersion', 'update')
        ->and(array_filter(get_class_methods(PlaygroundPromptService::class), fn (string $method): bool => str_starts_with($method, 'update')))->toBe([])
        ->and(PlaygroundPromptVersion::UPDATED_AT)->toBeNull();
});

it('AC-1 refuses a save that changes neither the text nor the settings with the reason unchanged', function (): void {
    $prompt = $this->service->create('stable', new NewPlaygroundVersion('Same text.', null, 0.5, 300));

    foreach ([
        new NewPlaygroundVersion("  Same text.\r\n", null, 0.5, 300),
        new NewPlaygroundVersion('Same text.', 'only a note', 0.5, 300),
    ] as $same) {
        try {
            $this->service->addVersion($prompt->id, $same);
            $this->fail('An unchanged save was stored.');
        } catch (PlaygroundPromptException $exception) {
            expect($exception->reason)->toBe('unchanged');
        }
    }

    expect(PlaygroundPromptVersion::query()->count())->toBe(1);

    $this->service->addVersion($prompt->id, new NewPlaygroundVersion('Same text.', null, 0.6, 300));
    $this->service->addVersion($prompt->id, new NewPlaygroundVersion('Same text.', null, 0.6, 400));

    expect(PlaygroundPromptVersion::query()->count())->toBe(3);
});

it('AC-1 allows text equal to an older version and numbers it as a new version', function (): void {
    $prompt = $this->service->create('revert', new NewPlaygroundVersion('Text A.'));
    $this->service->addVersion($prompt->id, new NewPlaygroundVersion('Text B.'));

    $again = $this->service->addVersion($prompt->id, new NewPlaygroundVersion('Text A.'));

    expect($again->version)->toBe(3);
});

it('AC-1 refuses a save for a prompt that does not exist', function (): void {
    try {
        $this->service->addVersion(999999, new NewPlaygroundVersion('Text.'));
        $this->fail('A version was stored for an unknown prompt.');
    } catch (PlaygroundPromptException $exception) {
        expect($exception->reason)->toBe('not_found');
    }
});

it('AC-1 lowercases and trims a name and keeps only the allowed shapes', function (string $name, ?string $reason): void {
    try {
        $prompt = $this->service->create($name, new NewPlaygroundVersion('Text.'));
        expect($reason)->toBeNull()->and($prompt->name)->toBe(PlaygroundPromptService::normaliseName($name));
    } catch (PlaygroundPromptException $exception) {
        expect($exception->reason)->toBe($reason);
    }
})->with([
    'plain' => ['pitch', null],
    'upper and padded' => ['  Car Pitch  ', null],
    'dots dashes underscores' => ['a.b-c_d e', null],
    'minimum length' => ['abc', null],
    'maximum length' => [str_repeat('a', 80), null],
    'too short' => ['ab', 'invalid_name'],
    'too long' => [str_repeat('a', 81), 'invalid_name'],
    'empty' => ['   ', 'invalid_name'],
    'leading dash' => ['-abc', 'invalid_name'],
    'trailing dot' => ['abc.', 'invalid_name'],
    'slash' => ['a/b/c', 'invalid_name'],
    'html' => ['<b>abc</b>', 'invalid_name'],
    'accent' => ['café', 'invalid_name'],
]);

it('AC-1 refuses a name that is taken, whatever its case', function (): void {
    $this->service->create('taken', new NewPlaygroundVersion('Text.'));

    try {
        $this->service->create(' TAKEN ', new NewPlaygroundVersion('Other.'));
        $this->fail('A second prompt took the name.');
    } catch (PlaygroundPromptException $exception) {
        expect($exception->reason)->toBe('name_taken');
    }

    expect(PlaygroundPrompt::query()->count())->toBe(1);
});

it('AC-1 lists the prompts with their latest version and version count', function (): void {
    $first = $this->service->create('first one', new NewPlaygroundVersion('A.'));
    $this->service->addVersion($first->id, new NewPlaygroundVersion('B.'));
    $this->service->create('second one', new NewPlaygroundVersion('C.'));

    $list = $this->service->all();

    expect($list)->toHaveCount(2)
        ->and(collect($list)->firstWhere('name', 'first one')->latestVersion)->toBe(2)
        ->and(collect($list)->firstWhere('name', 'first one')->versionsCount)->toBe(2)
        ->and(collect($list)->firstWhere('name', 'second one')->latestVersion)->toBe(1);
});

it('AC-7 keeps a stored run, its results and its label when the prompt is deleted', function (): void {
    $prompt = $this->service->create('doomed', new NewPlaygroundVersion('Text.'));
    $version = $prompt->versions[0];

    $run = app(PlaygroundRunRepositoryInterface::class)->create(new NewPlaygroundRun(
        promptVersionId: $version->id,
        systemPrompt: $version->systemPrompt,
        input: 'Hello',
        inputFlags: ['ignore previous'],
        temperature: 0.2,
        maxTokens: 512,
        versionLabel: $version->label(),
        results: [
            new PlaygroundResultRecord(1, 'ollama', 'qwen3.5:9b', PlaygroundResultStatus::Ok, 'Hi', null, 10, 5, null, 120, 'inv-1'),
            new PlaygroundResultRecord(2, 'ollama', 'gemma4:12b', PlaygroundResultStatus::Failed, null, 'boom', null, null, null, 30, null),
        ],
    ));

    $this->service->delete($prompt->id);

    $kept = app(PlaygroundRunRepositoryInterface::class)->find($run->id);

    expect(PlaygroundPrompt::query()->count())->toBe(0)
        ->and(PlaygroundPromptVersion::query()->count())->toBe(0)
        ->and(PlaygroundRun::query()->count())->toBe(1)
        ->and(PlaygroundResult::query()->count())->toBe(2)
        ->and($kept->promptVersionId)->toBeNull()
        ->and($kept->versionLabel)->toBe('playground:doomed@v1')
        ->and($kept->inputFlags)->toBe(['ignore previous'])
        ->and($kept->results)->toHaveCount(2)
        ->and($kept->results[0]->output)->toBe('Hi')
        ->and($kept->results[1]->status)->toBe(PlaygroundResultStatus::Failed);
});

it('AC-7 reads a stored run back by id and lists the history with the input cut to 80 characters', function (): void {
    $runs = app(PlaygroundRunRepositoryInterface::class);

    $stored = $runs->create(new NewPlaygroundRun(null, 'Sys', str_repeat('x', 200), [], 0.2, 512, 'playground:unsaved', [
        new PlaygroundResultRecord(1, 'ollama', 'qwen3.5:9b', PlaygroundResultStatus::Ok, '<b>raw</b>', null, null, null, null, 10, null),
        new PlaygroundResultRecord(2, 'ollama', 'gemma4:12b', PlaygroundResultStatus::Timeout, null, 'timed out', null, null, null, 60000, null),
    ]));

    $history = $runs->recent(20);

    expect($runs->find($stored->id)->results[0]->output)->toBe('<b>raw</b>')
        ->and($runs->find('not-a-uuid'))->toBeNull()
        ->and($history)->toHaveCount(1)
        ->and(mb_strlen($history[0]->input))->toBe(80)
        ->and($history[0]->models)->toBe(['ollama:qwen3.5:9b', 'ollama:gemma4:12b']);
});

it('AC-7 builds the history from a bounded query: the first 80 characters of the input cut in SQL, and no system prompt or full input', function (): void {
    $runs = app(PlaygroundRunRepositoryInterface::class);
    $runs->create(new NewPlaygroundRun(null, 'A long system prompt '.str_repeat('s', 500), str_repeat('é', 200), [], 0.2, 512, 'playground:unsaved', [
        new PlaygroundResultRecord(1, 'ollama', 'qwen3.5:9b', PlaygroundResultStatus::Ok, 'out', null, null, null, null, 10, null),
        new PlaygroundResultRecord(2, 'ollama', 'gemma4:12b', PlaygroundResultStatus::Ok, 'out', null, null, null, null, 10, null),
    ]));

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $history = $runs->recent(20);

    $runQuery = collect($queries)->first(fn (string $sql): bool => str_contains($sql, 'from "playground_runs"'));
    $resultQuery = collect($queries)->first(fn (string $sql): bool => str_contains($sql, 'from "playground_results"'));

    expect($history[0]->input)->toBe(str_repeat('é', 80))
        ->and($runQuery)->toContain('substring(')->not->toContain('system_prompt')->not->toContain('select *')->not->toContain('"playground_runs"."input"')
        ->and($resultQuery)->not->toContain('output')->not->toContain('select *');
});

it('AC-1 binds both repository contracts', function (): void {
    expect(app(PlaygroundPromptRepositoryInterface::class))->toBeInstanceOf(PlaygroundPromptRepositoryInterface::class)
        ->and(app(PlaygroundRunRepositoryInterface::class))->toBeInstanceOf(PlaygroundRunRepositoryInterface::class);
});

it('AC-1 rounds a temperature to two decimals in the DTO and compares the rounded value exactly for unchanged', function (): void {
    expect((new NewPlaygroundVersion('Text.', null, 0.2004))->temperature)->toBe(0.2)
        ->and((new NewPlaygroundVersion('Text.', null, 0.1 + 0.2))->temperature)->toBe(0.3)
        ->and((new NewPlaygroundVersion('Text.', null, 1.999))->temperature)->toBe(2.0);

    $prompt = $this->service->create('rounding', new NewPlaygroundVersion('Same text.', null, 0.3, 300));

    expect(fn () => $this->service->addVersion($prompt->id, new NewPlaygroundVersion('Same text.', null, 0.1 + 0.2, 300)))
        ->toThrow(PlaygroundPromptException::class);

    $stored = $this->service->addVersion($prompt->id, new NewPlaygroundVersion('Same text.', null, 0.31, 300));

    expect($stored->version)->toBe(2)->and($stored->temperature)->toBe(0.31);
});

it('AC-1 validates the version fields in the service, so a direct call cannot store an empty prompt or break a CHECK constraint', function (array $fields): void {
    $version = new NewPlaygroundVersion(...$fields);

    expect(fn () => $this->service->create('direct call', $version))->toThrow(ValidationException::class);

    $prompt = $this->service->create('direct call', new NewPlaygroundVersion('Fine.'));

    expect(fn () => $this->service->addVersion($prompt->id, $version))->toThrow(ValidationException::class)
        ->and(PlaygroundPromptVersion::query()->count())->toBe(1);
})->with([
    'empty prompt' => [['systemPrompt' => "  \n "]],
    'prompt too long' => [['systemPrompt' => str_repeat('a', 8001)]],
    'note too long' => [['systemPrompt' => 'x', 'note' => str_repeat('n', 201)]],
    'temperature too high' => [['systemPrompt' => 'x', 'temperature' => 2.5]],
    'temperature negative' => [['systemPrompt' => 'x', 'temperature' => -0.1]],
    'max tokens too high' => [['systemPrompt' => 'x', 'maxTokens' => 4096]],
    'max tokens too low' => [['systemPrompt' => 'x', 'maxTokens' => 15]],
    'a NUL byte' => [['systemPrompt' => "bad\0text"]],
]);

it('AC-1 stores the limits themselves through the service', function (): void {
    $prompt = $this->service->create('limits', new NewPlaygroundVersion(str_repeat('a', 8000), str_repeat('n', 200), 2.0, 2048));

    expect($prompt->versions[0]->maxTokens)->toBe(2048)->and($prompt->versions[0]->temperature)->toBe(2.0);

    $this->service->addVersion($prompt->id, new NewPlaygroundVersion('b', null, 0.0, 16));
});
