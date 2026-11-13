<?php

declare(strict_types=1);

use App\Ai\AdminAssistantLoop;
use App\Ai\AdminConversation;
use App\Ai\AdminConversationStore;
use App\Ai\AdminTools\GetListingTool;
use App\Ai\AdminTools\SearchListingsTool;
use App\Ai\AdminTools\UpdateTitleTool;
use App\DTOs\AdminPendingAction;
use App\Models\AiRun;
use App\Models\AiToolStep;
use App\Models\Listing;
use App\Models\ListingTag;
use App\Services\AdminAssistantService;
use App\Services\AiSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * An object whose __wakeup shows that it was really instantiated.
 */
final class ReviewFixProbe
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}

beforeEach(function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Cache::flush();
    RateLimiter::clear('admin-assistant');
    Carbon::setTestNow();
    ReviewFixProbe::$woken = false;
    app()->detectEnvironment(fn (): string => 'local');
    config(['ai.admin_assistant.enabled' => true, 'ai.admin_assistant.per_minute' => 50, 'ai.features.admin-assistant.provider' => 'ollama']);
    Http::swap(new Factory);
    Http::preventStrayRequests();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * @param  list<array{0: string, 1: array<string, mixed>}>  $calls
 * @return array<string, mixed>
 */
function rfTools(array $calls): array
{
    return [
        'choices' => [['message' => [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => array_map(fn (array $call, int $index): array => [
                'id' => 'call_'.$index,
                'type' => 'function',
                'function' => ['name' => $call[0], 'arguments' => json_encode((object) $call[1])],
            ], $calls, array_keys($calls)),
        ]]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ];
}

/**
 * @return array<string, mixed>
 */
function rfText(string $content): array
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]];
}

/**
 * @param  list<mixed>  $answers
 */
function rfFake(array $answers): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    $sequence = Http::sequence();

    foreach ($answers as $answer) {
        $sequence->push($answer);
    }

    Http::fake(['*/v1/chat/completions' => $sequence]);
}

/**
 * @return array{0: string, 1: string}
 */
function rfPropose(int $listingId): array
{
    rfFake([rfTools([['updateTitle', ['id' => $listingId, 'title' => 'Clean A4 avant']]])]);
    $reply = app(AdminAssistantService::class)->ask(null, 'Rename it');

    return [$reply->conversationId, $reply->turn->pending->id];
}

it('FIX-2 sends any unknown or malformed action id to the service and answers 409 action_not_pending', function (string $actionId): void {
    $listing = Listing::factory()->create();
    [$conversationId] = rfPropose($listing->id);

    foreach (['confirm', 'reject'] as $decision) {
        $this->postJson("/api/admin-assistant/actions/{$actionId}/{$decision}", ['conversation_id' => $conversationId])
            ->assertStatus(409)->assertJsonPath('code', 'action_not_pending');
    }
})->with([
    'with a dash' => ['abc-def-123'],
    'one hundred characters' => [str_repeat('a', 100)],
    'a dotted id' => ['a.b'],
]);

it('FIX-3 keeps the decision as 200 and reports the partial steps, the failed run and a code when the model fails afterwards', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId, $actionId] = rfPropose($listing->id);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['*/v1/chat/completions' => Http::sequence()
        ->push(rfTools([['countByTag', ['tag' => 'suv']]]))
        ->push(['error' => 'boom'], 500)]);

    $response = $this->postJson("/api/admin-assistant/actions/{$actionId}/confirm", ['conversation_id' => $conversationId])->assertOk();

    expect($response->json('data.code'))->toBe('model_unavailable')
        ->and($response->json('data.answer'))->toStartWith('The decision was recorded')
        ->and(array_column($response->json('data.steps'), 'outcome'))->toBe(['confirmed', 'ok'])
        ->and($response->json('data.run_ids'))->toHaveCount(2)
        ->and(AiRun::query()->where('invocation_id', $response->json('data.run_ids.1'))->value('status'))->toBe('failed')
        ->and($listing->fresh()->title)->toBe('Clean A4 avant');
});

it('FIX-3 reports model_unsupported as the code of a decision whose model cannot go on', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId, $actionId] = rfPropose($listing->id);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['*/v1/chat/completions' => Http::response(['error' => ['message' => 'registry.ollama.ai/library/x does not support tools']], 400)]);

    $this->postJson("/api/admin-assistant/actions/{$actionId}/reject", ['conversation_id' => $conversationId])
        ->assertOk()->assertJsonPath('data.code', 'model_unsupported')->assertJsonPath('data.steps.0.outcome', 'rejected');
});

it('FIX-4 reports the expiry first in steps and logs it once, even when the model then fails twice', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId] = rfPropose($listing->id);
    Carbon::setTestNow(now()->addMinutes(11));
    $service = app(AdminAssistantService::class);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['*/v1/chat/completions' => fn () => throw new ConnectionException('refused')]);

    expect(fn () => $service->ask($conversationId, 'Hello'))->toThrow(RuntimeException::class);
    expect(fn () => $service->ask($conversationId, 'Hello'))->toThrow(RuntimeException::class);

    expect(AiToolStep::query()->where('error', 'expired')->count())->toBe(1)
        ->and(app(AdminConversationStore::class)->find($conversationId)->pending)->toBeNull();

    rfFake([rfText('Fresh.')]);
    $reply = $service->ask($conversationId, 'Hello');

    expect($reply->turn->answer)->toBe('Fresh.')
        ->and(AiToolStep::query()->where('error', 'expired')->count())->toBe(1);
});

it('FIX-4 puts the expired step first in the steps of the request that dropped it', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId] = rfPropose($listing->id);
    Carbon::setTestNow(now()->addMinutes(11));
    rfFake([rfTools([['countByTag', ['tag' => 'suv']]]), rfText('None.')]);

    $reply = app(AdminAssistantService::class)->ask($conversationId, 'Count suv');

    expect(array_map(fn ($step) => [$step->tool, $step->outcome, $step->error], $reply->turn->steps))
        ->toBe([['updateTitle', 'rejected', 'expired'], ['countByTag', 'ok', null]]);
});

it('FIX-4 checks the provider before it drops an expired action', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId] = rfPropose($listing->id);
    Carbon::setTestNow(now()->addMinutes(11));
    config(['ai.features.admin-assistant.provider' => 'anthropic']);

    expect(fn () => app(AdminAssistantService::class)->ask($conversationId, 'Hello'))->toThrow(RuntimeException::class);

    expect(AiToolStep::query()->where('error', 'expired')->count())->toBe(0)
        ->and(app(AdminConversationStore::class)->find($conversationId)->pending)->not->toBeNull();
});

it('FIX-5 reads a stored conversation back, pending action included', function (): void {
    $store = app(AdminConversationStore::class);
    $conversation = AdminConversation::start();
    $conversation->messages[] = ['role' => 'user', 'content' => 'Hi'];
    $conversation->pending = new AdminPendingAction('abc', 'updateTitle', ['id' => 1], 'call_1', ['id' => 1], CarbonImmutable::now()->addMinutes(5), 'run-1');
    $store->save($conversation);

    $found = $store->find($conversation->id);

    expect($found)->toBeInstanceOf(AdminConversation::class)
        ->and($found->pending)->toBeInstanceOf(AdminPendingAction::class)
        ->and($found->pending->expiresAt->isFuture())->toBeTrue();
});

it('FIX-5 does not instantiate a class that is not the conversations own', function (): void {
    $conversation = AdminConversation::start();
    $conversation->messages[] = ['role' => 'user', 'content' => new ReviewFixProbe];
    Cache::put('admin-assistant:conversation:'.$conversation->id, serialize($conversation), 60);
    $other = (string) Str::uuid();
    Cache::put('admin-assistant:conversation:'.$other, serialize(new ReviewFixProbe), 60);

    $store = app(AdminConversationStore::class);

    Cache::put('admin-assistant:conversation:swapped', str_replace('O:'.strlen(AdminPendingAction::class).':"'.AdminPendingAction::class.'"', 'O:'.strlen(ReviewFixProbe::class).':"'.ReviewFixProbe::class.'"', serialize(tap(AdminConversation::start(), function (AdminConversation $c): void {
        $c->pending = new AdminPendingAction('a', 't', [], 'c', [], CarbonImmutable::now(), 'r');
    }))), 60);

    expect($store->find('swapped'))->toBeNull()
        ->and($store->find($other))->toBeNull()
        ->and($store->find($conversation->id))->toBeNull()
        ->and(ReviewFixProbe::$woken)->toBeFalse();
});

it('FIX-6 answers 404 when a feature that is not offered is saved or reset', function (): void {
    config(['ai.admin_assistant.enabled' => false]);

    $this->put('/ai/settings/admin-assistant', ['provider' => 'ollama', 'model' => 'qwen3.5:9b'])->assertNotFound();
    $this->delete('/ai/settings/admin-assistant')->assertNotFound();
});

it('FIX-7 answers every tool call beyond eight with not_run and logs no step for it', function (): void {
    $calls = array_map(fn (int $i): array => ['countByTag', ['tag' => 'tag-'.$i.'x']], range(1, 12));
    rfFake([rfTools($calls), rfText('Done.')]);
    $conversation = AdminConversation::start();

    $turn = app(AdminAssistantLoop::class)->ask($conversation, 'Count everything');

    $tools = array_values(array_filter($conversation->messages, fn (array $message): bool => $message['role'] === 'tool'));

    expect($turn->steps)->toHaveCount(8)
        ->and($tools)->toHaveCount(12)
        ->and($tools[11]['content'])->toContain('not_run')
        ->and(AiToolStep::count())->toBe(8)
        ->and($turn->limitReached)->toBeTrue();
});

it('FIX-7 starts the window at the last user message when a long tool chain follows it', function (): void {
    $conversation = AdminConversation::start();
    $conversation->messages[] = ['role' => 'user', 'content' => 'old'];
    $conversation->messages[] = ['role' => 'assistant', 'content' => 'old answer'];
    $conversation->messages[] = ['role' => 'user', 'content' => 'the question'];
    $conversation->messages[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => array_map(fn (int $i): array => ['id' => 'c'.$i, 'type' => 'function', 'function' => ['name' => 'countByTag', 'arguments' => '{}']], range(1, 45))];

    for ($i = 1; $i <= 45; $i++) {
        $conversation->messages[] = ['role' => 'tool', 'tool_call_id' => 'c'.$i, 'content' => '<tool_result>{}</tool_result>'];
    }

    rfFake([rfText('Fine.')]);
    app(AdminAssistantLoop::class)->resume($conversation);

    $sent = Http::recorded()[0][0]->data()['messages'];

    expect($sent[1])->toMatchArray(['role' => 'user', 'content' => 'the question'])
        ->and($sent[2]['role'])->toBe('assistant');
});

it('FIX-8 trims the stored messages at save to the last eighty, starting at a user message', function (): void {
    $store = app(AdminConversationStore::class);
    $conversation = AdminConversation::start();

    for ($i = 0; $i < 70; $i++) {
        $conversation->messages[] = ['role' => 'user', 'content' => 'q'.$i];
        $conversation->messages[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c'.$i, 'type' => 'function', 'function' => ['name' => 'countByTag', 'arguments' => '{}']]]];
        $conversation->messages[] = ['role' => 'tool', 'tool_call_id' => 'c'.$i, 'content' => '<tool_result>{}</tool_result>'];
    }

    $store->save($conversation);
    $found = $store->find($conversation->id);

    expect(count($found->messages))->toBeLessThanOrEqual(80)
        ->and($found->messages[0]['role'])->toBe('user')
        ->and($found->messages[array_key_last($found->messages)]['tool_call_id'])->toBe('c69');
});

it('FIX-9 always sends think false and strips a leading or unclosed think block', function (): void {
    config(['ai.providers.ollama.request_options.think' => true]);
    rfFake([rfText("hidden reasoning</think>\nThe answer."), rfText("Visible.\n<think>never finished"), rfText('<think>a</think>Plain.')]);
    $loop = app(AdminAssistantLoop::class);

    $first = $loop->ask(AdminConversation::start(), 'One');
    $second = $loop->ask(AdminConversation::start(), 'Two');
    $third = $loop->ask(AdminConversation::start(), 'Three');

    expect(Http::recorded()[0][0]->data()['think'])->toBeFalse()
        ->and($first->answer)->toBe('The answer.')
        ->and($second->answer)->toBe('Visible.')
        ->and($third->answer)->toBe('Plain.');
});

it('FIX-10 rejects titles that are only invisible, line separators, or without a letter or digit', function (string $title): void {
    $tool = app(UpdateTitleTool::class);
    $listing = Listing::factory()->create();

    expect($tool->validate(['id' => $listing->id, 'title' => $title]))->not->toBe([]);
})->with([
    'only NBSP' => ["\u{00A0}\u{00A0}\u{00A0}\u{00A0}"],
    'only hangul filler' => ["\u{3164}\u{3164}\u{3164}\u{3164}"],
    'line separator inside' => ["Fine\u{2028}title"],
    'paragraph separator inside' => ["Fine\u{2029}title"],
    'only punctuation' => ['!!!???'],
]);

it('FIX-10 trims Unicode whitespace around a title and stores it trimmed', function (): void {
    $tool = app(UpdateTitleTool::class);
    $listing = Listing::factory()->create(['title' => null]);
    $arguments = ['id' => $listing->id, 'title' => "\u{00A0} Clean A4 \u{3000}"];

    expect($tool->validate($arguments))->toBe([])
        ->and($tool->preview($arguments)['new_title'])->toBe('Clean A4')
        ->and($tool->run($arguments)['new_title'])->toBe('Clean A4');
});

it('FIX-11 shows at most twenty tags of a listing and says how many there are', function (): void {
    $listing = Listing::factory()->create();

    foreach (range(1, 150) as $i) {
        ListingTag::factory()->for($listing)->create(['tag' => 'tag-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
    }

    $detail = app(GetListingTool::class)->run(['id' => $listing->id]);
    $search = app(SearchListingsTool::class)->run([]);

    expect($detail['tags'])->toHaveCount(20)->and($detail['tags_total'])->toBe(150)
        ->and($search['rows'][0]['tags'])->toHaveCount(20)->and($search['rows'][0]['tags_total'])->toBe(150);
});

it('FIX-12 keeps the exchanges that completed when a later model call fails, on ask', function (): void {
    $listing = Listing::factory()->create();
    Http::fake(['*/v1/chat/completions' => Http::sequence()->push(rfTools([['getListing', ['id' => $listing->id]]]))->push(['error' => 'boom'], 500)]);
    $conversation = AdminConversation::start();

    expect(fn () => app(AdminAssistantLoop::class)->ask($conversation, 'Look'))->toThrow(RuntimeException::class);

    expect(array_column($conversation->messages, 'role'))->toBe(['user', 'assistant', 'tool']);
});

it('FIX-12 saves that partial state through the service, so the next question sees it', function (): void {
    $listing = Listing::factory()->create();
    Http::fake(['*/v1/chat/completions' => Http::sequence()
        ->push(rfText('First.'))
        ->push(rfTools([['getListing', ['id' => $listing->id]]]))
        ->push(['error' => 'boom'], 500)]);
    $service = app(AdminAssistantService::class);
    $id = $service->ask(null, 'Hello')->conversationId;

    expect(fn () => $service->ask($id, 'Look'))->toThrow(RuntimeException::class);

    expect(array_column(app(AdminConversationStore::class)->find($id)->messages, 'role'))->toBe(['user', 'assistant', 'user', 'assistant', 'tool']);
});

it('FIX-12 drops a question whose first model call failed, so it can be asked again', function (): void {
    Http::fake(['*/v1/chat/completions' => Http::response(['error' => 'boom'], 500)]);
    $conversation = AdminConversation::start();

    expect(fn () => app(AdminAssistantLoop::class)->ask($conversation, 'Hello'))->toThrow(RuntimeException::class);

    expect($conversation->messages)->toBe([]);
});

it('FIX-13 answers 409 conversation_busy while another request holds the conversation', function (): void {
    config(['ai.admin_assistant.lock_wait_seconds' => 0]);
    rfFake([rfText('First.')]);
    $id = app(AdminAssistantService::class)->ask(null, 'Hello')->conversationId;
    $lock = Cache::lock('admin-assistant:conversation:'.$id.':lock', 30);
    expect($lock->get())->toBeTrue();

    $this->postJson('/api/admin-assistant/ask', ['question' => 'Again', 'conversation_id' => $id])
        ->assertStatus(409)->assertJsonPath('code', 'conversation_busy');
    $this->postJson('/api/admin-assistant/actions/'.str_repeat('a', 32).'/confirm', ['conversation_id' => $id])
        ->assertStatus(409)->assertJsonPath('code', 'conversation_busy');

    $lock->release();
});

it('FIX-14 offers and accepts only Ollama providers for the admin-assistant setting', function (): void {
    $settings = app(AiSettingsService::class);
    $offered = array_column($settings->options('admin-assistant'), 'name');

    expect($offered)->toBe(['ollama'])
        ->and($settings->modelError('admin-assistant', 'fake-stream', 'x'))->not->toBeNull()
        ->and(array_column($settings->options('assistant'), 'name'))->toContain('fake-stream');
});
