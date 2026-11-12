<?php

declare(strict_types=1);

use App\Ai\AiProviders;
use App\Ai\BuyerAssistantAgent;
use App\Ai\Tools\ReadOnlyTool;
use App\Ai\Tools\SearchListings;
use App\DTOs\DocsChatTurn;
use App\Models\AiRun;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Messages\Message;

it('AC-2 caps the steps and the tool caps the searches at four', function (): void {
    $steps = (new ReflectionClass(BuyerAssistantAgent::class))->getAttributes(MaxSteps::class)[0]->newInstance()->value;

    expect($steps)->toBeLessThanOrEqual(6)->and(SearchListings::MAX_CALLS)->toBe(4)->and(SearchListings::MAX_LIMIT)->toBe(10);
});

it('AC-6 offers read-only tools only', function (): void {
    $tools = [...(new BuyerAssistantAgent)->tools()];

    expect($tools)->not->toBeEmpty()->each->toBeInstanceOf(ReadOnlyTool::class);
    expect(array_map(fn (object $tool): string => $tool::class, $tools))->toBe([SearchListings::class]);
});

it('AC-7 takes the provider and model from configuration', function (): void {
    config([
        'ai.providers.second' => ['driver' => 'ollama', 'models' => ['text' => ['default' => 'tiny']]],
        'ai.features.assistant.provider' => 'second',
        'ai.features.assistant.model' => 'tool-model',
    ]);
    $agent = new BuyerAssistantAgent;

    expect($agent->providerName())->toBe('second')->and($agent->modelName())->toBe('tool-model');
});

it('AC-7 a choice saved on /ai/settings drives the agent and the recorded run', function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Cache::flush();
    Http::preventStrayRequests();
    app()->detectEnvironment(fn (): string => 'local');
    config([
        'ai.default' => 'fake-stream',
        'ai.settings_ui' => true,
        'ai.features.assistant.provider' => null,
        'ai.features.assistant.model' => null,
    ]);

    $this->put('/ai/settings/assistant', ['provider' => 'fake-instant', 'model' => 'fake-instant-1'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('settings.feature', 'assistant');
    $agent = new BuyerAssistantAgent;

    expect($agent->providerName())->toBe('fake-instant')->and($agent->modelName())->toBe('fake-instant-1');

    $this->postJson('/api/assistant/ask', ['question' => 'a diesel BMW'])->assertOk()->streamedContent();

    expect(AiRun::sole())->provider->toBe('fake-instant')->model->toBe('fake-instant-1')->agent->toBe('BuyerAssistantAgent');
});

it('AC-7 lists the assistant as a text feature on the default provider', function (): void {
    config(['ai.features.assistant.provider' => null, 'ai.features.assistant.model' => null]);

    expect(AiProviders::features())->toHaveKey('assistant', 'text')
        ->and((new BuyerAssistantAgent)->providerName())->toBe((string) config('ai.default'));
});

it('MNC-4 reads the versioned prompt and keeps the history as messages without a network call', function (): void {
    Http::preventStrayRequests();
    $agent = new BuyerAssistantAgent(history: [new DocsChatTurn('user', 'a diesel BMW'), new DocsChatTurn('assistant', 'Which city?')]);
    $messages = [...$agent->messages()];

    expect($agent->promptVersion())->toBe('buyer-assistant@v3')
        ->and($agent->instructions())->toContain('SearchListings')->toContain('distance')->toContain('another currency')->toContain('/listings/{id}')
        ->and($messages)->toHaveCount(2)->each->toBeInstanceOf(Message::class)
        ->and($messages[1]->content)->toBe('Which city?');
});

it('AC-1 runs a faked agent prompt offline', function (): void {
    Http::preventStrayRequests();
    BuyerAssistantAgent::fake(['Listing 1 matches.']);

    $response = (new BuyerAssistantAgent)->prompt('a diesel BMW');

    expect($response->text)->toBe('Listing 1 matches.');
    BuyerAssistantAgent::assertPrompted('a diesel BMW');
});
