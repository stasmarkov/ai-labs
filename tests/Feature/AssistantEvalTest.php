<?php

declare(strict_types=1);

use App\Ai\FakeStreamProvider;
use App\Contracts\AssistantTurnRunnerInterface;
use App\DTOs\AssistantEvalCall;
use App\DTOs\AssistantEvalQuestion;
use App\DTOs\AssistantEvalTurn;
use App\Exceptions\RagEvalSetException;
use App\Models\Dealer;
use App\Models\Listing;
use App\Services\AssistantEvalRunner;
use App\Services\AssistantEvalScorer;
use App\Services\AssistantEvalSet;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

function assistantEvalRoot(): string
{
    return storage_path('framework/testing/assistant-eval');
}

/**
 * @param  array<string, string|int>  $filters
 */
function assistantEvalQuestion(string $id, array $filters = [], int $maxSteps = 1): AssistantEvalQuestion
{
    return new AssistantEvalQuestion("q-{$id}", "question {$id}", $filters !== [], $filters, $filters === [] ? 0 : $maxSteps);
}

/**
 * @param  list<array{0: array<string, mixed>, 1: string, 2?: list<int>}>  $calls
 */
function assistantEvalTurn(array $calls, string $answer = 'Done.', int $latencyMs = 100, string $status = AssistantEvalTurn::COMPLETED): AssistantEvalTurn
{
    return new AssistantEvalTurn($status, array_map(fn (array $call): AssistantEvalCall => new AssistantEvalCall($call[0], $call[1], $call[2] ?? []), $calls), $answer, $latencyMs);
}

/**
 * @param  list<AssistantEvalQuestion>  $questions
 * @param  list<AssistantEvalTurn>  $turns
 */
function assistantEvalBindRunner(array $questions, array $turns): void
{
    File::ensureDirectoryExists(assistantEvalRoot());
    File::put(assistantEvalRoot().'/questions.jsonl', implode("\n", array_map(fn (AssistantEvalQuestion $question): string => json_encode([
        'id' => $question->id,
        'question' => $question->question,
        'expects_tool' => $question->expectsTool,
        'expected_filters' => (object) $question->expectedFilters,
        'max_steps_ok' => $question->maxStepsOk,
    ], JSON_THROW_ON_ERROR), $questions))."\n");

    config(['ai.docs.eval_results_root' => assistantEvalRoot()]);
    app()->instance(AssistantTurnRunnerInterface::class, new class($turns) implements AssistantTurnRunnerInterface
    {
        private int $next = 0;

        /** @param list<AssistantEvalTurn> $turns */
        public function __construct(private readonly array $turns) {}

        public function run(AssistantEvalQuestion $question, string $model): AssistantEvalTurn
        {
            return $this->turns[$this->next++];
        }
    });
}

afterEach(function (): void {
    File::deleteDirectory(assistantEvalRoot());
});

it('AC-8 ships 30 questions with 18 searches, 6 follow-ups and 6 without a tool', function (): void {
    $questions = (new AssistantEvalSet)->load();
    $searches = array_filter($questions, fn (AssistantEvalQuestion $q): bool => $q->expectsTool && $q->history === []);
    $followUps = array_filter($questions, fn (AssistantEvalQuestion $q): bool => $q->expectsTool && $q->history !== []);
    $noTool = array_filter($questions, fn (AssistantEvalQuestion $q): bool => ! $q->expectsTool);

    expect($questions)->toHaveCount(30)
        ->and($searches)->toHaveCount(18)
        ->and($followUps)->toHaveCount(6)
        ->and($noTool)->toHaveCount(6)
        ->and(max(array_map(fn (AssistantEvalQuestion $q): int => $q->maxStepsOk, $questions)))->toBeLessThanOrEqual(4);
});

it('AC-8 computes precision, recall, validity, repair, filter accuracy, steps and latency on scripted turns', function (): void {
    $questions = [
        assistantEvalQuestion('a', ['make' => 'Audi', 'max_price' => 15000]),
        assistantEvalQuestion('b', ['city' => 'Berlin']),
        assistantEvalQuestion('c', ['fuel' => 'diesel']),
        assistantEvalQuestion('d', ['make' => 'Ford']),
        assistantEvalQuestion('e'),
        assistantEvalQuestion('f'),
    ];
    $turns = [
        assistantEvalTurn([[['make' => 'AUDI ', 'max_price' => 15000, 'limit' => 5], 'ok', [1]]], latencyMs: 100),
        assistantEvalTurn([[['city' => 'Brelin'], 'invalid'], [['city' => 'Berlin'], 'ok', [2]]], latencyMs: 200),
        assistantEvalTurn([[['fuel' => 'steam'], 'invalid'], [['fuel' => 'gas'], 'invalid'], [['fuel' => 'x'], 'other']], latencyMs: 300),
        assistantEvalTurn([], latencyMs: 400),
        assistantEvalTurn([[['make' => 'Fiat'], 'ok', [3]]], latencyMs: 500),
        assistantEvalTurn([], latencyMs: 600),
    ];

    $report = (new AssistantEvalScorer)->score('tiny', $questions, $turns);

    expect($report['tool_call_precision'])->toBe(['count' => 3, 'of' => 4, 'rate' => 0.75])
        ->and($report['tool_call_recall'])->toBe(['count' => 3, 'of' => 4, 'rate' => 0.75])
        ->and($report['argument_validity_first_try'])->toBe(['count' => 2, 'of' => 4, 'rate' => 0.5])
        ->and($report['repair_success'])->toBe(['count' => 1, 'of' => 2, 'rate' => 0.5])
        ->and($report['filter_accuracy'])->toBe(['count' => 2, 'of' => 4, 'rate' => 0.5])
        ->and($report['steps'])->toBe(['mean' => 1.17, 'max' => 3, 'limit' => 4, 'within_limit' => true, 'over_label' => 3])
        ->and($report['latency_ms'])->toBe(['count' => 6, 'p50' => 300, 'p95' => 600])
        ->and($report['failed'])->toBe(0);
});

it('AC-8 flags a turn that used more tool steps than the limit', function (): void {
    $five = array_fill(0, 5, [['make' => 'Audi'], 'ok', [1]]);

    $report = (new AssistantEvalScorer)->score('tiny', [assistantEvalQuestion('a', ['make' => 'Audi'])], [assistantEvalTurn($five)]);

    expect($report['steps']['max'])->toBe(5)->and($report['steps']['within_limit'])->toBeFalse();
});

it('NFR-3 counts an answer that links a listing id the tool did not return as hallucinated, and ignores plain numbers', function (): void {
    $questions = [assistantEvalQuestion('a', ['make' => 'Audi']), assistantEvalQuestion('b', ['make' => 'Audi']), assistantEvalQuestion('c', ['make' => 'Audi']), assistantEvalQuestion('d')];
    $turns = [
        assistantEvalTurn([[['make' => 'Audi'], 'ok', [11, 12]]], 'Take #11 or /listings/12.'),
        assistantEvalTurn([[['make' => 'Audi'], 'ok', [11, 12]]], 'Listing #11 and the one with id 99 are good.'),
        assistantEvalTurn([[['make' => 'Audi'], 'ok', [11]]], 'Nothing numbered here, only 2019 and 15000 euros.'),
        assistantEvalTurn([], 'See /listings/5.'),
    ];
    $scorer = new AssistantEvalScorer;

    $report = $scorer->score('tiny', $questions, $turns);

    // "#11" and "id 99" are not links, so they are never read as listing ids ("#1 pick" would otherwise count).
    expect($scorer->hallucinatedIds($turns[1]))->toBe([])
        ->and($scorer->hallucinatedIds($turns[3]))->toBe([5])
        ->and($report['answers_citing_only_returned_ids'])->toBe(['count' => 3, 'of' => 4, 'rate' => 0.75, 'hallucinated' => 1, 'hallucinated_rate' => 0.25]);
});

it('NFR-3 counts a "listing 999" as an unlinked mention, not as a hallucinated link', function (): void {
    $questions = [assistantEvalQuestion('a', ['make' => 'Audi']), assistantEvalQuestion('b', ['make' => 'Audi']), assistantEvalQuestion('c', ['make' => 'Audi'])];
    $turns = [
        assistantEvalTurn([[['make' => 'Audi'], 'ok', [11]]], 'Listing 999 is a bargain.'),
        assistantEvalTurn([[['make' => 'Audi'], 'ok', [11]]], 'Listing 11 is nearby.'),
        assistantEvalTurn([[['make' => 'Audi'], 'ok', [11]]], 'Take [listing 11](/listings/11).'),
    ];
    $scorer = new AssistantEvalScorer;

    $report = $scorer->score('tiny', $questions, $turns);

    expect($scorer->hallucinatedIds($turns[0]))->toBe([])
        ->and($scorer->unlinkedMentions($turns[0]))->toBe([999])
        ->and($scorer->unlinkedMentions($turns[1]))->toBe([11])
        ->and($scorer->unlinkedMentions($turns[2]))->toBe([])
        ->and($report['answers_citing_only_returned_ids']['hallucinated'])->toBe(0)
        ->and($report['unlinked_mentions'])->toBe(['count' => 2, 'of' => 3, 'rate' => 0.667]);
});

it('AC-8 leaves a failed turn out of the answer metrics and the latency', function (): void {
    $questions = [assistantEvalQuestion('a', ['make' => 'Audi']), assistantEvalQuestion('b', ['make' => 'Audi'])];
    $turns = [assistantEvalTurn([], '', 900, AssistantEvalTurn::FAILED), assistantEvalTurn([[['make' => 'Audi'], 'ok', [1]]], 'ok', 50)];

    $report = (new AssistantEvalScorer)->score('tiny', $questions, $turns);

    expect($report['failed'])->toBe(1)
        ->and($report['answers_citing_only_returned_ids']['of'])->toBe(1)
        ->and($report['latency_ms'])->toBe(['count' => 1, 'p50' => 50, 'p95' => 50]);
});

it('AC-8 writes run.json with the per-model metrics and exits clean within the step limit', function (): void {
    $questions = [assistantEvalQuestion('a', ['make' => 'Audi']), assistantEvalQuestion('b')];
    assistantEvalBindRunner($questions, [assistantEvalTurn([[['make' => 'Audi'], 'ok', [1]]], 'See #1.'), assistantEvalTurn([], 'Check the service history.')]);

    $this->artisan('assistant:eval', ['--questions' => assistantEvalRoot().'/questions.jsonl', '--output-dir' => assistantEvalRoot().'/out', '--models' => 'tiny'])
        ->assertSuccessful();

    $run = json_decode(File::get(assistantEvalRoot().'/out/run.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($run['metadata'])->toMatchArray(['question_set_version' => 'v1', 'questions' => 2, 'models' => ['tiny']])
        ->and($run['models'][0]['tool_call_recall']['rate'])->toEqual(1)
        ->and($run['models'][0]['answers_citing_only_returned_ids']['hallucinated'])->toBe(0)
        ->and($run['results'])->toHaveCount(2)
        ->and($run['results'][0]['calls'][0]['returned_ids'])->toBe([1]);
});

it('AC-8 fails the run when a question used more tool steps than the limit, after writing the results', function (): void {
    $questions = [assistantEvalQuestion('a', ['make' => 'Audi'])];
    assistantEvalBindRunner($questions, [assistantEvalTurn(array_fill(0, 5, [['make' => 'Audi'], 'ok', [1]]))]);

    $this->artisan('assistant:eval', ['--questions' => assistantEvalRoot().'/questions.jsonl', '--output-dir' => assistantEvalRoot().'/out', '--models' => 'tiny'])
        ->assertFailed();

    expect(File::exists(assistantEvalRoot().'/out/run.json'))->toBeTrue();
});

it('AC-8 refuses to overwrite run.json without --force', function (): void {
    $questions = [assistantEvalQuestion('a', ['make' => 'Audi'])];
    assistantEvalBindRunner($questions, [assistantEvalTurn([[['make' => 'Audi'], 'ok', [1]]])]);
    File::ensureDirectoryExists(assistantEvalRoot().'/out');
    File::put(assistantEvalRoot().'/out/run.json', '{}');

    $this->artisan('assistant:eval', ['--questions' => assistantEvalRoot().'/questions.jsonl', '--output-dir' => assistantEvalRoot().'/out', '--models' => 'tiny'])
        ->assertFailed();

    expect(File::get(assistantEvalRoot().'/out/run.json'))->toBe('{}');
});

it('AC-8 reads the tool calls of a real agent turn from the stream and returns the ids the tool gave', function (): void {
    Http::preventStrayRequests();
    $audi = Listing::factory()->for(Dealer::factory())->create(['make' => 'Audi']);
    $script = [['make' => 'Audi'], ['fuel' => 'steam'], 'Take [it](/listings/'.$audi->id.') or [this](/listings/999)'];
    $probe = new stdClass;
    $probe->steps = 0;

    app(AiManager::class)->extend('fake-stream', function (Application $app, array $config) use ($script, $probe): FakeStreamProvider {
        $provider = new FakeStreamProvider($config, $app->make(Dispatcher::class));

        return $provider->useTextGateway(new class($script, $probe) implements StepTextGateway
        {
            /** @param list<array<string, mixed>|string> $script */
            public function __construct(private readonly array $script, private readonly stdClass $probe) {}

            public function generateTextStep(TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): StepResponse
            {
                throw new RuntimeException('The assistant streams.');
            }

            public function generateStreamStep(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): Generator
            {
                $step = $this->script[$this->probe->steps++];
                $meta = new Meta($provider->name(), $model);
                $usage = new TextUsage(5, 3);

                yield (new StreamStart('s'.$this->probe->steps, $provider->name(), $model, time()))->withInvocationId($invocationId);

                if (is_string($step)) {
                    yield (new TextDelta('d1', 'm1', $step, time()))->withInvocationId($invocationId);

                    return new StepResponse($step, [], FinishReason::Stop, $usage, $meta);
                }

                $call = new ToolCall('call-'.$this->probe->steps, 'SearchListings', $step);
                yield (new ToolCallEvent('tc'.$this->probe->steps, $call, time()))->withInvocationId($invocationId);

                return new StepResponse('', [$call], FinishReason::ToolCalls, $usage, $meta);
            }
        });
    });
    app(AiManager::class)->forgetInstance('fake-stream');

    $turn = (new AssistantEvalRunner)->run(assistantEvalQuestion('a', ['make' => 'Audi']), 'tiny');

    expect($turn->status)->toBe(AssistantEvalTurn::COMPLETED)
        ->and(array_map(fn (AssistantEvalCall $call): string => $call->outcome, $turn->calls))->toBe(['ok', 'invalid'])
        ->and($turn->calls[0]->arguments)->toBe(['make' => 'Audi'])
        ->and($turn->calls[0]->returnedIds)->toBe([$audi->id])
        ->and((new AssistantEvalScorer)->hallucinatedIds($turn))->toBe([999]);
});

it('AC-8 labels 4 search questions with the unsupported words the answer must mention', function (): void {
    $labelled = array_values(array_filter((new AssistantEvalSet)->load(), fn (AssistantEvalQuestion $q): bool => $q->unsupported !== []));

    expect($labelled)->toHaveCount(4)
        ->and(array_filter($labelled, fn (AssistantEvalQuestion $q): bool => ! $q->expectsTool || $q->history !== []))->toBeEmpty();
});

it('AC-8 rejects an unsupported label that is malformed or on a question without a tool', function (string $label): void {
    File::ensureDirectoryExists(assistantEvalRoot());
    $path = assistantEvalRoot().'/unsupported.jsonl';
    File::put($path, '{"id":"as-01","question":"Q","expects_tool":'.($label === 'notool' ? 'false,"expected_filters":{}' : 'true,"expected_filters":{"make":"Audi"}').',"max_steps_ok":'.($label === 'notool' ? '0' : '1').',"unsupported":'.($label === 'notool' ? '["estate"]' : ($label === 'empty' ? '[""]' : '"estate"')).'}'."\n");

    expect(fn () => (new AssistantEvalSet)->load($path))->toThrow(RagEvalSetException::class);
})->with(['notool', 'empty', 'string']);

it('AC-8 reports the share of unsupported-filter answers that name the missing capability', function (): void {
    $asked = fn (string $id): AssistantEvalQuestion => new AssistantEvalQuestion("q-{$id}", "question {$id}", true, ['make' => 'Audi'], 1, [], ['estate', 'wagon']);
    $questions = [$asked('a'), $asked('b'), $asked('c'), assistantEvalQuestion('d', ['make' => 'Audi'])];
    $call = [[['make' => 'Audi'], 'ok', [1]]];
    $turns = [
        assistantEvalTurn($call, 'I cannot filter by Estate, here are some Audis.'),
        assistantEvalTurn($call, 'Here are some Audis.'),
        assistantEvalTurn($call, 'No wagon filter exists.'),
        assistantEvalTurn($call, 'Anything else.'),
    ];

    $report = (new AssistantEvalScorer)->score('tiny', $questions, $turns);

    expect($report['unsupported_mentioned'])->toBe(['count' => 2, 'of' => 3, 'rate' => 0.667]);
    expect((new AssistantEvalScorer)->score('tiny', [assistantEvalQuestion('d', ['make' => 'Audi'])], [assistantEvalTurn($call)])['unsupported_mentioned']['rate'])->toBeNull();
});

it('AC-8 matches a filter city through its aliases', function (): void {
    $scorer = new AssistantEvalScorer;

    expect($scorer->matches(['city' => 'Kyiv'], ['city' => 'kiev']))->toBeTrue()
        ->and($scorer->matches(['city' => 'Kyiv'], ['city' => ' Kiev ']))->toBeTrue()
        ->and($scorer->matches(['city' => 'Munich'], ['city' => 'München']))->toBeTrue()
        ->and($scorer->matches(['city' => 'Kyiv'], ['city' => 'Lviv']))->toBeFalse();
});

it('NFR-3 an unsupported wish counts only as a whole word, so "offered" never matches "red"', function (): void {
    $question = new AssistantEvalQuestion('q-red', 'red Fiat', true, ['make' => 'Fiat'], 1, [], ['red', 'colour']);
    $scorer = new AssistantEvalScorer;

    expect($scorer->mentionsUnsupported($question, assistantEvalTurn([], 'I offered three Fiats that were registered recently.')))->toBeFalse()
        ->and($scorer->mentionsUnsupported($question, assistantEvalTurn([], 'I cannot filter by colour, so red is not guaranteed.')))->toBeTrue();
});
