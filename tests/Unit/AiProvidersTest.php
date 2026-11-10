<?php

declare(strict_types=1);

use App\Ai\AiProviders;
use App\Ai\ProviderChoice;
use App\Exceptions\ProviderNotConfiguredException;
use App\Exceptions\ProviderNotEnabledException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

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
        'ai.providers.anthropic.key' => null,
        'ai.providers.openai.key' => null,
        'ai.providers.gemini.key' => null,
    ]);
});

function providers(): AiProviders
{
    return new AiProviders;
}

it('AC-1 and MNC-1 fall back to the default provider and its model with nothing configured', function (): void {
    foreach (['describe', 'nl-search', 'docs-answer', 'rag-grader'] as $feature) {
        expect(providers()->forFeature($feature))->toEqual(new ProviderChoice('fake-stream', 'fake-slow-1', 'fake-stream'));
    }

    expect(providers()->forFeature('embeddings-listings'))->toEqual(new ProviderChoice('ollama', 'bge-m3', 'ollama'))
        ->and(providers()->forFeature('embeddings-docs')->model)->toBe('bge-m3');

    config(['ai.default' => 'ollama']);

    expect(providers()->forFeature('describe'))->toEqual(new ProviderChoice('ollama', 'qwen3.5:9b', 'ollama'));
});

it('AC-1 runs one feature on a cloud provider while the others keep theirs', function (): void {
    config(['ai.providers.anthropic.key' => 'k', 'ai.features.describe.provider' => 'anthropic']);

    expect(providers()->forFeature('describe'))->toEqual(new ProviderChoice('anthropic', 'claude-haiku-5-5', 'anthropic'))
        ->and(providers()->forFeature('nl-search')->provider)->toBe('fake-stream');
});

it('AC-1 applies the feature model only on the configured provider', function (): void {
    config(['ai.providers.openai.key' => 'k', 'ai.features.describe.provider' => 'openai', 'ai.features.describe.model' => 'custom-1']);

    expect(providers()->forFeature('describe')->model)->toBe('custom-1')
        ->and(providers()->forFeature('describe', 'fake-instant')->model)->toBe('fake-instant-1');

    config(['ai.features.describe.provider' => null, 'ai.features.describe.model' => 'on-default']);

    expect(providers()->forFeature('describe')->model)->toBe('on-default');
});

it('AC-2 does not enable a cloud provider without a key', function (): void {
    expect(providers()->enabled())->not->toContain('anthropic', 'openai', 'gemini')
        ->and(providers()->enabledTextProviders())->toBe(['fake-stream', 'fake-instant', 'ollama']);

    config(['ai.providers.gemini.key' => '']);
    expect(providers()->isEnabled('gemini'))->toBeFalse();

    config(['ai.providers.gemini.key' => 'k']);
    expect(providers()->enabled())->toContain('gemini');
});

it('AC-2 refuses a feature configured with a key-less cloud provider', function (): void {
    config(['ai.features.docs-answer.provider' => 'openai']);

    expect(fn () => providers()->forFeature('docs-answer'))->toThrow(ProviderNotConfiguredException::class)
        ->and(providers()->configured('docs-answer')->provider)->toBe('openai');
});

it('AC-2 validates an override against the enabled text providers', function (): void {
    expect(fn () => providers()->forFeature('describe', 'anthropic'))->toThrow(ProviderNotEnabledException::class)
        ->and(fn () => providers()->forFeature('describe', 'nope'))->toThrow(ProviderNotEnabledException::class)
        ->and(fn () => providers()->forFeature('embeddings-docs', 'fake-instant'))->toThrow(ProviderNotEnabledException::class);

    config(['ai.providers.anthropic.key' => 'k']);

    expect(providers()->forFeature('describe', 'anthropic')->model)->toBe('claude-haiku-5-5')
        ->and(providers()->forFeature('describe', 'fake-stream')->provider)->toBe('fake-stream');
});

it('AC-1 hides the fakes outside local and testing', function (): void {
    $this->app['env'] = 'production';

    expect(providers()->enabled())->toBe(['ollama']);
});

it('AC-1 rejects an unknown feature', function (): void {
    expect(fn () => providers()->forFeature('nope'))->toThrow(InvalidArgumentException::class);
});

it('AC-1 reports capabilities from the SDK providers and the map', function (): void {
    $capabilities = providers()->capabilities();

    expect($capabilities['anthropic'])->toBe(['text' => true, 'streaming' => true, 'structured' => true, 'embeddings' => null, 'cloud' => true, 'keyVariable' => 'ANTHROPIC_API_KEY'])
        ->and($capabilities['openai']['embeddings'])->toBe(1024)
        ->and($capabilities['gemini']['embeddings'])->toBe(1024)
        ->and($capabilities['ollama'])->toMatchArray(['cloud' => false, 'keyVariable' => null, 'embeddings' => 1024])
        ->and($capabilities['fake-stream'])->toMatchArray(['text' => true, 'embeddings' => null, 'cloud' => false]);
});

it('NFR-1 returns no key value from any method', function (): void {
    $secrets = ['sk-ant-secret-1', 'sk-openai-secret-2', 'gem-secret-3'];
    config([
        'ai.providers.anthropic.key' => $secrets[0],
        'ai.providers.openai.key' => $secrets[1],
        'ai.providers.gemini.key' => $secrets[2],
        'ai.features.describe.provider' => 'anthropic',
    ]);

    $returned = json_encode([
        providers()->enabled(),
        providers()->enabledTextProviders(),
        providers()->capabilities(),
        providers()->forFeature('describe'),
        providers()->forFeature('nl-search', 'openai'),
        providers()->configured('describe'),
        providers()->hasKey('gemini'),
    ], JSON_THROW_ON_ERROR);

    foreach ($secrets as $secret) {
        expect($returned)->not->toContain($secret);
    }

    config(['ai.features.docs-answer.provider' => 'ollama']);
    try {
        providers()->forFeature('docs-answer', 'unknown');
    } catch (ProviderNotEnabledException $e) {
        foreach ($secrets as $secret) {
            expect($e->getMessage())->not->toContain($secret);
        }
    }
});

it('MNC-1 forFeature accepts a configured fake outside local and testing but rejects it as an override', function (): void {
    $this->app['env'] = 'production';

    expect(providers()->forFeature('describe')->provider)->toBe('fake-stream')
        ->and(providers()->enabledTextProviders())->toBe(['ollama'])
        ->and(fn () => providers()->forFeature('describe', 'fake-instant'))->toThrow(ProviderNotEnabledException::class);
});
