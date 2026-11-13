<?php

declare(strict_types=1);

use App\Ai\AiProviders;
use App\Models\Listing;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Cache::flush();
    RateLimiter::clear('admin-assistant');
    app()->detectEnvironment(fn (): string => 'local');
    config(['ai.admin_assistant.enabled' => true, 'ai.admin_assistant.per_minute' => 20, 'ai.features.admin-assistant.provider' => 'ollama']);
    Http::preventStrayRequests();
});

/**
 * @return array<string, mixed>
 */
function modelAnswers(string $content = 'Hello.'): array
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]];
}

it('AC-11 answers 404 outside the local environment', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);

    $this->postJson('/api/admin-assistant/ask', ['question' => 'Hi'])->assertNotFound();
    $this->postJson('/api/admin-assistant/actions/abc/confirm', ['conversation_id' => (string) Str::uuid()])->assertNotFound();
    $this->postJson('/api/admin-assistant/actions/abc/reject', ['conversation_id' => (string) Str::uuid()])->assertNotFound();
    Http::assertNothingSent();
})->with(['production', 'staging', 'testing']);

it('MNC-4 answers 404 when AI_ADMIN_ASSISTANT is off, even locally', function (): void {
    config(['ai.admin_assistant.enabled' => false]);

    $this->postJson('/api/admin-assistant/ask', ['question' => 'Hi'])->assertNotFound();
    Http::assertNothingSent();
});

it('MNC-4 offers no settings row outside local or when switched off', function (): void {
    $providers = app(AiProviders::class);

    expect($providers->offeredFeatures())->toHaveKey(AiProviders::ADMIN_ASSISTANT, 'text');

    config(['ai.admin_assistant.enabled' => false]);
    expect($providers->offeredFeatures())->not->toHaveKey(AiProviders::ADMIN_ASSISTANT);

    config(['ai.admin_assistant.enabled' => true]);
    app()->detectEnvironment(fn (): string => 'production');
    expect($providers->offeredFeatures())->not->toHaveKey(AiProviders::ADMIN_ASSISTANT)
        ->and(AiProviders::features())->not->toHaveKey(AiProviders::ADMIN_ASSISTANT);
});

it('NFR-1 refuses a cross-site request with 403 before any model call', function (): void {
    $this->postJson('/api/admin-assistant/ask', ['question' => 'Hi'], ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
    $this->postJson('/api/admin-assistant/actions/abc/confirm', ['conversation_id' => (string) Str::uuid()], ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
    $this->postJson('/api/admin-assistant/actions/abc/reject', ['conversation_id' => (string) Str::uuid()], ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
    Http::assertNothingSent();
});

it('NFR-1 refuses a form body with 415', function (): void {
    $this->post('/api/admin-assistant/ask', ['question' => 'Hi'], ['Accept' => 'application/json'])->assertStatus(415);
    $this->post('/api/admin-assistant/actions/abc/confirm', ['conversation_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertStatus(415);
    Http::assertNothingSent();
});

it('AC-11 answers 429 over the limit', function (): void {
    config(['ai.admin_assistant.per_minute' => 2]);
    Http::fake(['*/v1/chat/completions' => Http::response(modelAnswers())]);

    $this->postJson('/api/admin-assistant/ask', ['question' => 'One'])->assertOk();
    $this->postJson('/api/admin-assistant/ask', ['question' => 'Two'])->assertOk();
    $this->postJson('/api/admin-assistant/ask', ['question' => 'Three'])->assertStatus(429);
});

it('AC-11 validates the question and the ids with 422', function (): void {
    $this->postJson('/api/admin-assistant/ask', [])->assertStatus(422)->assertJsonPath('success', false)->assertJsonStructure(['errors' => ['question']]);
    $this->postJson('/api/admin-assistant/ask', ['question' => str_repeat('a', 501)])->assertStatus(422);
    $this->postJson('/api/admin-assistant/ask', ['question' => 'Hi', 'conversation_id' => 'nope'])->assertStatus(422)->assertJsonStructure(['errors' => ['conversation_id']]);
    $this->postJson('/api/admin-assistant/actions/abc/confirm', [])->assertStatus(422)->assertJsonStructure(['errors' => ['conversation_id']]);
});

it('AC-11 answers the reply shape and keeps the conversation going', function (): void {
    Http::fake(['*/v1/chat/completions' => Http::response(modelAnswers('Hi there.'))]);

    $response = $this->postJson('/api/admin-assistant/ask', ['question' => 'Hello'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('status', 200)
        ->assertJsonPath('data.answer', 'Hi there.')
        ->assertJsonPath('data.steps', [])
        ->assertJsonPath('data.pending_action', null)
        ->assertJsonPath('data.limit_reached', false)
        ->assertJsonStructure(['data' => ['conversation_id', 'answer', 'steps', 'pending_action', 'limit_reached', 'run_ids']]);

    $this->postJson('/api/admin-assistant/ask', ['question' => 'Again', 'conversation_id' => $response->json('data.conversation_id')])
        ->assertOk()
        ->assertJsonPath('data.conversation_id', $response->json('data.conversation_id'));
});

it('AC-5 answers 404 conversation_not_found on ask and confirm for an unknown conversation', function (): void {
    $this->postJson('/api/admin-assistant/ask', ['question' => 'Hi', 'conversation_id' => (string) Str::uuid()])
        ->assertStatus(404)->assertJsonPath('code', 'conversation_not_found');
    $this->postJson('/api/admin-assistant/actions/abc123/confirm', ['conversation_id' => (string) Str::uuid()])
        ->assertStatus(404)->assertJsonPath('code', 'conversation_not_found');
    Http::assertNothingSent();
});

it('AC-5 answers 409 action_not_pending for an unknown, used, expired and other-conversation action', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    $toolCall = ['choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
        'id' => 'call_1', 'type' => 'function',
        'function' => ['name' => 'updateTitle', 'arguments' => json_encode(['id' => $listing->id, 'title' => 'Fresh title'])],
    ]]]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]];
    Http::fake(['*/v1/chat/completions' => Http::sequence()->push($toolCall)->push($toolCall)->push(modelAnswers('Done.'))->push($toolCall)]);
    $propose = function (): array {
        $ask = $this->postJson('/api/admin-assistant/ask', ['question' => 'Rename it'])->assertOk();

        return [$ask->json('data.conversation_id'), $ask->json('data.pending_action.action_id')];
    };

    [$conversation, $action] = $propose();
    [$other] = $propose();

    $this->postJson('/api/admin-assistant/actions/'.str_repeat('a', 32).'/confirm', ['conversation_id' => $conversation])
        ->assertStatus(409)->assertJsonPath('code', 'action_not_pending');
    $this->postJson("/api/admin-assistant/actions/{$action}/confirm", ['conversation_id' => $other])
        ->assertStatus(409)->assertJsonPath('code', 'action_not_pending');

    $this->postJson("/api/admin-assistant/actions/{$action}/confirm", ['conversation_id' => $conversation])->assertOk();
    $this->postJson("/api/admin-assistant/actions/{$action}/confirm", ['conversation_id' => $conversation])
        ->assertStatus(409)->assertJsonPath('code', 'action_not_pending');

    [$expiring, $expiringAction] = $propose();
    Carbon::setTestNow(now()->addMinutes(11));
    $this->postJson("/api/admin-assistant/actions/{$expiringAction}/confirm", ['conversation_id' => $expiring])
        ->assertStatus(409)->assertJsonPath('code', 'action_not_pending');
    Carbon::setTestNow();
});

it('NFR-1 writes only through confirm and reports the decision', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    Http::fake(['*/v1/chat/completions' => Http::sequence()
        ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
            'id' => 'call_1', 'type' => 'function',
            'function' => ['name' => 'updateTitle', 'arguments' => json_encode(['id' => $listing->id, 'title' => 'Fresh title'])],
        ]]]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]])
        ->push(modelAnswers('Done.'))]);

    $ask = $this->postJson('/api/admin-assistant/ask', ['question' => 'Rename it'])->assertOk()->assertJsonPath('data.pending_action.tool', 'updateTitle');
    $conversation = $ask->json('data.conversation_id');
    $action = $ask->json('data.pending_action.action_id');

    expect($listing->fresh()->title)->toBeNull();

    $this->postJson("/api/admin-assistant/actions/{$action}/confirm", ['conversation_id' => $conversation])
        ->assertOk()->assertJsonPath('data.answer', 'Done.');

    expect($listing->fresh()->title)->toBe('Fresh title');

    $this->postJson("/api/admin-assistant/actions/{$action}/confirm", ['conversation_id' => $conversation])
        ->assertStatus(409)->assertJsonPath('code', 'action_not_pending');
});

it('AC-8 refuses confirm and reject with 422 when the provider is not Ollama, and keeps the action', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    Http::fake(['*/v1/chat/completions' => Http::sequence()
        ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
            'id' => 'call_1', 'type' => 'function',
            'function' => ['name' => 'updateTitle', 'arguments' => json_encode(['id' => $listing->id, 'title' => 'Fresh title'])],
        ]]]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]])
        ->push(modelAnswers('Done.'))]);

    $ask = $this->postJson('/api/admin-assistant/ask', ['question' => 'Rename it'])->assertOk();
    $conversation = $ask->json('data.conversation_id');
    $action = $ask->json('data.pending_action.action_id');

    config(['ai.features.admin-assistant.provider' => 'fake-stream']);

    foreach (['confirm', 'reject'] as $decision) {
        $this->postJson("/api/admin-assistant/actions/{$action}/{$decision}", ['conversation_id' => $conversation])
            ->assertStatus(422)->assertJsonPath('code', 'provider_not_supported');
    }

    expect($listing->fresh()->title)->toBeNull();
    Http::assertSentCount(1);

    config(['ai.features.admin-assistant.provider' => 'ollama']);

    $this->postJson("/api/admin-assistant/actions/{$action}/confirm", ['conversation_id' => $conversation])
        ->assertOk()->assertJsonPath('data.answer', 'Done.');

    expect($listing->fresh()->title)->toBe('Fresh title');
});

it('NFR-2 raises the PHP time limit to 150 seconds before the loop runs', function (string $path): void {
    Http::fake(['*/v1/chat/completions' => Http::response(modelAnswers())]);
    $before = ini_get('max_execution_time');
    ini_set('max_execution_time', '30');

    try {
        $this->postJson($path, ['question' => 'Hello', 'conversation_id' => (string) Str::uuid()]);

        expect(ini_get('max_execution_time'))->toBe('150');
    } finally {
        ini_set('max_execution_time', (string) $before);
    }
})->with([
    'ask' => '/api/admin-assistant/ask',
    'confirm' => '/api/admin-assistant/actions/'.'a1b2c3/confirm',
    'reject' => '/api/admin-assistant/actions/'.'a1b2c3/reject',
]);
