<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Http::preventStrayRequests();

    config([
        'ai.default' => 'fake-stream',
        'ai.default_for_embeddings' => 'ollama',
        'ai.features' => [
            'describe' => ['provider' => null, 'model' => null],
            'nl-search' => ['provider' => null, 'model' => null],
            'docs-answer' => ['provider' => null, 'model' => null],
            'rag-grader' => ['provider' => null, 'model' => null],
            'embeddings-listings' => ['provider' => null, 'model' => null],
            'embeddings-docs' => ['provider' => null, 'model' => null],
        ],
        'ai.providers.anthropic.key' => 'sk-ant-secret-select-test',
        'ai.providers.openai.key' => null,
        'ai.providers.gemini.key' => null,
    ]);
});

it('AC-3 the describe, NL search and chat pages offer the enabled text providers by name without keys', function (string $url, string $component): void {
    $response = $this->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('enabledTextProviders', [
                ['name' => 'fake-stream', 'configured' => true, 'hasKey' => true],
                ['name' => 'fake-instant', 'configured' => false, 'hasKey' => true],
                ['name' => 'ollama', 'configured' => false, 'hasKey' => true],
                ['name' => 'anthropic', 'configured' => false, 'hasKey' => true],
            ]));

    expect($response->getContent())->not->toContain('sk-ant-secret-select-test');
})->with([
    'describe' => ['/ai/describe', 'Lab/Describe'],
    'nl search' => ['/ai/nl-search', 'Lab/NlSearch'],
    'chat' => ['/ai/chat', 'Lab/Chat'],
]);

it('AC-3 a provider without a key is not offered', function (): void {
    config(['ai.providers.anthropic.key' => null]);

    $this->get('/ai/describe')->assertInertia(fn (Assert $page) => $page
        ->has('enabledTextProviders', 3)
        ->where('enabledTextProviders.0.name', 'fake-stream')
        ->where('enabledTextProviders.1.name', 'fake-instant')
        ->where('enabledTextProviders.2.name', 'ollama'));
});

it('AC-3 the page marks the feature provider as configured', function (): void {
    config(['ai.features.describe.provider' => 'anthropic']);

    $this->get('/ai/describe')->assertInertia(fn (Assert $page) => $page
        ->where('enabledTextProviders.3', ['name' => 'anthropic', 'configured' => true, 'hasKey' => true])
        ->where('enabledTextProviders.0.configured', false));
});

it('AC-3 a configured provider without a key is listed with a no-key flag and no key value', function (): void {
    config([
        'ai.features.describe.provider' => 'openai',
        'ai.providers.openai.key' => null,
    ]);

    $response = $this->get('/ai/describe')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('enabledTextProviders', 5)
            ->where('enabledTextProviders.0.configured', false)
            ->where('enabledTextProviders.4', ['name' => 'openai', 'configured' => true, 'hasKey' => false])
            ->where('enabledTextProviders.3.hasKey', true));

    expect($response->getContent())->not->toContain('sk-ant-secret-select-test');
});

it('AC-3 the search pages show the embeddings provider and model as text', function (string $url): void {
    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('embeddingsProvider', [
            'provider' => 'ollama',
            'model' => config('ai.providers.ollama.models.embeddings.default'),
        ]));
})->with(['/ai/semantic', '/ai/docs']);
