<?php

declare(strict_types=1);

use App\Ai\AiProviders;
use App\Ai\ProviderChoice;
use App\Contracts\AiSettingsRepositoryInterface;
use App\DTOs\AiSettingChoice;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\Dealer;
use App\Models\Listing;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Cache::flush();
    app()->detectEnvironment(fn (): string => 'local');
    config([
        'ai.default' => 'fake-stream',
        'ai.features.describe.provider' => null,
        'ai.features.describe.model' => null,
        'ai.settings_ui' => true,
    ]);
});

function resolver(): AiProviders
{
    return app(AiProviders::class);
}

function saveSetting(string $feature, string $provider, string $model): void
{
    app(AiSettingsRepositoryInterface::class)->save(new AiSettingChoice($feature, $provider, $model));
}

it('AC-1 a saved setting wins over configuration and is labelled settings', function (): void {
    config(['ai.features.describe.provider' => 'fake-stream', 'ai.features.describe.model' => 'fake-slow-1']);
    saveSetting('describe', 'ollama', 'gemma4:12b');

    $resolved = resolver()->resolve('describe');

    expect($resolved->choice)->toEqual(new ProviderChoice('ollama', 'gemma4:12b', 'ollama'))
        ->and($resolved->source)->toBe('settings')
        ->and(resolver()->forFeature('describe'))->toEqual($resolved->choice);
});

it('AC-1 a save and a reset are seen by the next call in the same process without a reboot', function (): void {
    $before = resolver();

    expect($before->resolve('describe')->source)->toBe('default');

    saveSetting('describe', 'ollama', 'gemma4:12b');
    expect($before->forFeature('describe')->model)->toBe('gemma4:12b');

    app(AiSettingsRepositoryInterface::class)->reset('describe');
    expect($before->forFeature('describe')->provider)->toBe('fake-stream');
});

it('NFR-1 resolving never writes to config', function (): void {
    saveSetting('describe', 'ollama', 'gemma4:12b');
    $before = config('ai');

    resolver()->resolve('describe');

    expect(config('ai'))->toBe($before);
});

it('MNC-1 with no saved settings configuration then default decide as before', function (): void {
    expect(resolver()->resolve('describe')->source)->toBe('default');

    config(['ai.features.describe.model' => 'fake-instant-1']);

    $resolved = resolver()->resolve('describe');

    // Only the model is configured, so the provider (and the row) comes from the default; the model has its own label.
    expect($resolved->source)->toBe('default')
        ->and($resolved->modelSource)->toBe('configuration')
        ->and($resolved->choice->model)->toBe('fake-instant-1');
});

it('AC-7 a request override wins over a saved setting', function (): void {
    saveSetting('describe', 'ollama', 'gemma4:12b');

    $resolved = resolver()->resolve('describe', 'fake-instant');

    expect($resolved->source)->toBe('request')
        ->and($resolved->choice->provider)->toBe('fake-instant');
});

it('MNC-2 the lab selector still names a provider with no saved setting', function (): void {
    $resolved = resolver()->resolve('describe', 'fake-instant');

    expect($resolved->source)->toBe('request')
        ->and(resolver()->forFeature('describe', 'fake-instant')->provider)->toBe('fake-instant');
});

it('AC-6 a non-local environment ignores saved settings', function (): void {
    saveSetting('describe', 'ollama', 'gemma4:12b');
    app()->detectEnvironment(fn (): string => 'production');

    $resolved = resolver()->resolve('describe');

    expect($resolved->source)->toBe('default')
        ->and($resolved->choice->provider)->toBe('fake-stream');
});

it('MNC-4 the settings switch off ignores saved settings even locally', function (): void {
    saveSetting('describe', 'ollama', 'gemma4:12b');
    config(['ai.settings_ui' => false]);

    expect(resolver()->resolve('describe')->source)->toBe('default');
});

it('AC-6 a saved setting naming a provider that is not enabled falls back to configuration', function (): void {
    config(['ai.providers.anthropic.key' => null]);
    saveSetting('describe', 'anthropic', 'claude-haiku-5-5');

    $resolved = resolver()->resolve('describe');

    expect($resolved->source)->toBe('default')
        ->and($resolved->choice->provider)->toBe('fake-stream');
});

it('AC-7 MNC-2 a request naming the configured provider takes the configured model while another provider is saved', function (): void {
    config(['ai.features.describe.provider' => 'fake-stream', 'ai.features.describe.model' => 'fake-configured-9']);
    saveSetting('describe', 'ollama', 'gemma4:12b');

    $resolved = resolver()->resolve('describe', 'fake-stream');

    expect($resolved->source)->toBe('request')
        ->and($resolved->choice->provider)->toBe('fake-stream')
        ->and($resolved->choice->model)->toBe('fake-configured-9');
});

it('AC-7 MNC-2 a request naming a provider that matches neither level takes that provider default model', function (): void {
    config(['ai.features.describe.provider' => 'fake-stream', 'ai.features.describe.model' => 'fake-configured-9']);
    saveSetting('describe', 'ollama', 'gemma4:12b');

    $resolved = resolver()->resolve('describe', 'fake-instant');

    expect($resolved->source)->toBe('request')
        ->and($resolved->choice->provider)->toBe('fake-instant')
        ->and($resolved->choice->model)->toBe((string) config('ai.providers.fake-instant.models.text.default'));
});

it('NFR-3 the resolver reads the ai-settings:v1 cache key once per request however many features it resolves', function (): void {
    $reads = 0;
    Event::listen([CacheHit::class, CacheMissed::class], function (object $event) use (&$reads): void {
        if ($event->key === 'ai-settings:v1') {
            $reads++;
        }
    });

    foreach (['describe', 'describe', 'embeddings-docs', 'photo-vision'] as $feature) {
        resolver()->forFeature($feature);
        resolver()->resolve($feature);
    }

    expect($reads)->toBe(1);
});

it('NFR-3 the settings are cached under ai-settings:v1 for one hour', function (): void {
    expect(AiSettingsRepositoryInterface::CACHE_KEY)->toBe('ai-settings:v1')
        ->and(AiSettingsRepositoryInterface::CACHE_TTL)->toBe(3600);

    Cache::spy();
    Cache::shouldReceive('remember')->once()->with('ai-settings:v1', 3600, Mockery::type(Closure::class))->andReturn([]);

    app(AiSettingsRepositoryInterface::class)->all();
});

it('NFR-1 a new request scope drops the memo so a worker sees a change made elsewhere', function (): void {
    expect(resolver()->resolve('describe')->source)->toBe('default');

    AiSetting::query()->create(['feature' => 'describe', 'provider' => 'ollama', 'model' => 'gemma4:12b']);
    Cache::forget('ai-settings:v1');
    app()->forgetScopedInstances();

    expect(resolver()->resolve('describe')->source)->toBe('settings');
});

it('AC-1 the describe run row records the saved provider and model, and the reset restores the configured one', function (): void {
    $listing = Listing::factory()->for(Dealer::factory())->create();
    config(['ai.providers.fake-instant.models.text.available' => ['fake-saved-1']]);

    saveSetting('describe', 'fake-instant', 'fake-saved-1');
    $this->get('/listings/'.$listing->id.'/describe')->assertOk()->streamedContent();

    $saved = AiRun::query()->latest('id')->firstOrFail();
    expect($saved->provider)->toBe('fake-instant')
        ->and($saved->model)->toBe('fake-saved-1');

    app(AiSettingsRepositoryInterface::class)->reset('describe');
    $this->get('/listings/'.$listing->id.'/describe')->assertOk()->streamedContent();

    $reset = AiRun::query()->latest('id')->firstOrFail();
    expect($reset->id)->not->toBe($saved->id)
        ->and($reset->provider)->toBe('fake-stream')
        ->and($reset->model)->toBe('fake-slow-1');
});

it('reads saved settings back from a cache store that refuses to unserialize objects', function (): void {
    config(['cache.default' => 'file', 'cache.stores.file.path' => storage_path('framework/cache/settings-test-'.uniqid())]);
    app(AiSettingsRepositoryInterface::class)->save(new AiSettingChoice('describe', 'fake-instant', 'fake-instant-1'));

    // A fresh repository instance, as on the next request: the first read fills the cache, the second comes from it.
    app()->forgetInstance(AiSettingsRepositoryInterface::class);
    app(AiSettingsRepositoryInterface::class)->all();
    app()->forgetInstance(AiSettingsRepositoryInterface::class);
    $saved = app(AiSettingsRepositoryInterface::class)->all()['describe'];

    expect($saved)->toBeInstanceOf(AiSettingChoice::class)->and($saved->model)->toBe('fake-instant-1');
});

it('ignores a stored model id that does not look like a model id', function (): void {
    AiSetting::query()->create(['feature' => 'describe', 'provider' => 'ollama', 'model' => 'x; rm -rf /']);
    Cache::forget(AiSettingsRepositoryInterface::CACHE_KEY);
    app()->forgetInstance(AiSettingsRepositoryInterface::class);

    expect(app(AiSettingsRepositoryInterface::class)->all())->not->toHaveKey('describe');
});
