<?php

declare(strict_types=1);

use App\Ai\AiProviders;
use App\Ai\OllamaServerInfo;
use App\Contracts\AiSettingsRepositoryInterface;
use App\DTOs\AiSettingChoice;
use App\Models\AiSetting;
use App\Models\Dealer;
use App\Models\Listing;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->withoutVite();
    Cache::flush();
    app()->detectEnvironment(fn (): string => 'local');
    config([
        'ai.default' => 'fake-stream',
        'ai.settings_ui' => true,
        'ai.features.describe.provider' => null,
        'ai.features.describe.model' => null,
        'ai.providers.anthropic.key' => 'sk-secret-anthropic-value',
        'ai.providers.openai.key' => null,
        'ai.providers.gemini.key' => null,
    ]);
    Http::fake([
        '*/api/tags' => Http::response(['models' => [
            ['name' => 'qwen3.5:9b', 'details' => ['family' => 'qwen']],
            ['name' => 'gemma4:12b', 'details' => ['family' => 'gemma']],
            ['name' => 'bge-m3', 'details' => ['family' => 'bert']],
        ]]),
        '*/api/show' => fn ($request) => Http::response(['capabilities' => match ($request['model']) {
            'bge-m3' => ['embedding'],
            'gemma4:12b' => ['completion', 'vision'],
            default => ['completion'],
        }]),
    ]);
});

it('AC-2 the photo-vision row offers only the vision model', function (): void {
    $this->get('/ai/settings')->assertInertia(fn (Assert $page) => $page
        ->where('rows.4.feature', 'photo-vision')
        ->where('rows.4.providers', fn ($providers) => collect($providers)->firstWhere('name', 'ollama')['models'] === ['gemma4:12b']));
});

it('AC-4 the page lists every feature with enabled providers and the right models, and no key value', function (): void {
    $response = $this->get('/ai/settings');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Lab/Settings', false)
        ->has('rows', 9)
        ->where('rows.0.feature', 'describe')
        ->where('rows.0.effective.source', 'default')
        ->where('rows.0.providers', fn ($providers) => collect($providers)->pluck('name')->contains('ollama')
            && collect($providers)->firstWhere('name', 'ollama')['models'] === ['qwen3.5:9b', 'gemma4:12b']
            && ! collect($providers)->pluck('name')->contains('openai'))
        ->where('rows.5.feature', 'assistant')
        ->where('rows.6.feature', 'embeddings-listings')
        ->where('rows.6.providers', fn ($providers) => collect($providers)->pluck('name')->all() === ['ollama']
            && collect($providers)->firstWhere('name', 'ollama')['models'] === ['bge-m3']));

    expect($response->getContent())->not->toContain('sk-secret-anthropic-value');
});

it('NFR-2 the page and the props carry no key value', function (): void {
    $content = $this->get('/ai/settings')->getContent();

    expect($content)->not->toContain('sk-secret-anthropic-value')
        ->and($content)->toContain('anthropic');
});

it('AC-6 the settings routes answer 404 outside local', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);

    $this->get('/ai/settings')->assertNotFound();
    $this->put('/ai/settings/describe', ['provider' => 'ollama', 'model' => 'gemma4:12b'])->assertNotFound();
    $this->delete('/ai/settings/describe')->assertNotFound();
    expect(AiSetting::query()->count())->toBe(0);
})->with(['testing', 'production']);

it('AC-6 the settings routes answer 404 when the flag is off', function (): void {
    config(['ai.settings_ui' => false]);

    $this->get('/ai/settings')->assertNotFound();
    $this->put('/ai/settings/describe', ['provider' => 'ollama', 'model' => 'gemma4:12b'])->assertNotFound();
    $this->delete('/ai/settings/describe')->assertNotFound();
});

it('AC-4 a valid save stores the choice and flashes a message for that feature', function (): void {
    $this->put('/ai/settings/describe', ['provider' => 'ollama', 'model' => 'gemma4:12b'])
        ->assertRedirect('/ai/settings')
        ->assertSessionHas('settings.feature', 'describe');

    expect(app(AiSettingsRepositoryInterface::class)->all()['describe'])->toEqual(new AiSettingChoice('describe', 'ollama', 'gemma4:12b'));
});

it('AC-4 invalid saves store nothing', function (string $feature, array $payload, string $field): void {
    $this->put("/ai/settings/{$feature}", $payload)
        ->assertSessionHasErrors($field, errorBag: "settings-{$feature}");

    expect(AiSetting::query()->count())->toBe(0);
})->with([
    'provider not enabled' => ['describe', ['provider' => 'openai', 'model' => 'gpt-6-luna'], 'model'],
    'unknown provider' => ['describe', ['provider' => 'nope', 'model' => 'x'], 'model'],
    'model not installed' => ['describe', ['provider' => 'ollama', 'model' => 'llama9:1b'], 'model'],
    'embedding model for a text feature' => ['describe', ['provider' => 'ollama', 'model' => 'bge-m3'], 'model'],
    'text model for an embeddings feature' => ['embeddings-docs', ['provider' => 'ollama', 'model' => 'qwen3.5:9b'], 'model'],
    'cloud id with a bad character' => ['describe', ['provider' => 'anthropic', 'model' => 'bad id!'], 'model'],
    'missing model' => ['describe', ['provider' => 'ollama'], 'model'],
    'missing provider' => ['describe', ['model' => 'gemma4:12b'], 'provider'],
    'embedding change without the acknowledgement' => ['embeddings-docs', ['provider' => 'ollama', 'model' => 'bge-m3x'], 'model'],
]);

it('AC-4 an unknown feature is a 404 and stores nothing', function (): void {
    $this->put('/ai/settings/nope', ['provider' => 'ollama', 'model' => 'gemma4:12b'])->assertNotFound();
    $this->delete('/ai/settings/nope')->assertNotFound();

    expect(AiSetting::query()->count())->toBe(0);
});

it('AC-4 a cloud provider accepts an id outside its list that matches the pattern', function (): void {
    $this->put('/ai/settings/describe', ['provider' => 'anthropic', 'model' => 'claude-next-1'])->assertSessionHasNoErrors();

    expect(app(AiSettingsRepositoryInterface::class)->all()['describe']->model)->toBe('claude-next-1');
});

it('AC-4 changing an embeddings model needs the re-index acknowledgement', function (): void {
    config(['ai.providers.ollama.models.embeddings.default' => 'other-embed']);

    $this->put('/ai/settings/embeddings-docs', ['provider' => 'ollama', 'model' => 'bge-m3'])
        ->assertSessionHasErrors('reindex_acknowledged', errorBag: 'settings-embeddings-docs');
    expect(AiSetting::query()->count())->toBe(0);

    $this->put('/ai/settings/embeddings-docs', ['provider' => 'ollama', 'model' => 'bge-m3', 'reindex_acknowledged' => true])
        ->assertSessionHasNoErrors();
    expect(AiSetting::query()->count())->toBe(1);
});

it('AC-4 Ollama unreachable accepts only configured ids', function (): void {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['*/api/tags' => Http::response('', 500)]);

    $this->put('/ai/settings/describe', ['provider' => 'ollama', 'model' => 'gemma4:12b'])
        ->assertSessionHasErrors('model', errorBag: 'settings-describe');

    $this->put('/ai/settings/describe', ['provider' => 'ollama', 'model' => 'qwen3.5:9b'])->assertSessionHasNoErrors();
});

it('AC-3 a save equal to configuration deletes the row and says nothing was saved', function (): void {
    app(AiSettingsRepositoryInterface::class)->save(new AiSettingChoice('describe', 'ollama', 'gemma4:12b'));
    config(['ai.default' => 'ollama']);

    $this->put('/ai/settings/describe', ['provider' => 'ollama', 'model' => 'qwen3.5:9b'])
        ->assertSessionHas('settings.message', fn (string $message): bool => str_contains($message, 'nothing saved'));

    expect(AiSetting::query()->count())->toBe(0);
});

it('AC-3 a reset deletes the row and redirects with a flash', function (): void {
    app(AiSettingsRepositoryInterface::class)->save(new AiSettingChoice('describe', 'ollama', 'gemma4:12b'));

    $this->delete('/ai/settings/describe')
        ->assertRedirect('/ai/settings')
        ->assertSessionHas('settings.feature', 'describe');

    expect(AiSetting::query()->count())->toBe(0);
});

it('AC-2 the page marks a saved provider that is no longer enabled', function (): void {
    app(AiSettingsRepositoryInterface::class)->save(new AiSettingChoice('describe', 'openai', 'gpt-6-luna'));

    $this->get('/ai/settings')->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.savedProviderEnabled', false)
        ->where('rows.0.effective.source', 'default'));
});

it('AC-5 an embeddings row carries the counts, the re-index command and a warning for vectors of another model', function (): void {
    $dealer = Dealer::factory()->create();
    $listings = Listing::factory()->count(3)->for($dealer)->create();

    foreach ($listings as $index => $listing) {
        DB::table('listing_documents')->insert([
            'listing_id' => $listing->id,
            'body_type' => 'sedan',
            'description' => "text {$index}",
            'embedding' => $index < 2 ? '['.implode(',', array_fill(0, 1024, 0.5)).']' : null,
            'embedding_model' => $index === 0 ? 'ollama/bge-m3' : ($index === 1 ? 'ollama/old-embed' : null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->get('/ai/settings')->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.index', null)
        ->where('rows.6.feature', 'embeddings-listings')
        ->where('rows.6.index.total', 3)
        ->where('rows.6.index.indexed', 2)
        ->where('rows.6.index.current', 1)
        ->where('rows.6.index.foreign', 1)
        ->where('rows.6.index.warning', true)
        ->where('rows.6.index.command', 'ddev artisan listings:embed --fresh --wait')
        ->where('rows.7.feature', 'embeddings-docs')
        ->where('rows.7.index.indexed', 0)
        ->where('rows.7.index.warning', false)
        ->where('rows.7.index.command', 'ddev artisan docs:index'));
});

it('AC-2 the navigation lists Settings only where the settings are available', function (): void {
    $this->get('/ai/settings')->assertInertia(fn (Assert $page) => $page
        ->where('lab.navigation', fn ($navigation) => collect($navigation)->contains(['href' => '/ai/settings', 'label' => 'Settings'])));

    config(['ai.settings_ui' => false]);

    $this->get('/ai')->assertInertia(fn (Assert $page) => $page
        ->where('lab.navigation', fn ($navigation) => ! collect($navigation)->pluck('label')->contains('Settings')));
});

it('AC-2 a saved model that is not installed carries the flag, the pull command and stays in the select', function (): void {
    config(['ai.default' => 'ollama']);
    app(AiSettingsRepositoryInterface::class)->save(new AiSettingChoice('describe', 'ollama', 'llama9:1b'));

    $this->get('/ai/settings')->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.effective.model', 'llama9:1b')
        ->where('rows.0.effective.source', 'settings')
        ->where('rows.0.effective.installed', false)
        ->where('rows.0.effective.pullCommand', 'ollama pull llama9:1b')
        ->where('rows.0.providers', fn ($providers) => in_array('llama9:1b', collect($providers)->firstWhere('name', 'ollama')['models'], true)));

    $this->put('/ai/settings/describe', ['provider' => 'ollama', 'model' => 'llama8:1b'])->assertSessionHasErrors('model', errorBag: 'settings-describe');
});

it('AC-2 an installed model has no pull command', function (): void {
    config(['ai.default' => 'ollama', 'ai.features.describe.model' => 'qwen3.5:9b']);

    $this->get('/ai/settings')->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.effective.installed', true)
        ->where('rows.0.effective.pullCommand', null));
});

it('AC-2 the model carries its own source when another level supplied it', function (): void {
    config(['ai.default' => 'ollama', 'ai.features.describe.model' => null]);

    $this->get('/ai/settings')->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.effective.source', 'default')
        ->where('rows.0.effective.modelSource', 'default'));

    config(['ai.features.describe.provider' => 'ollama']);

    $this->get('/ai/settings')->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.effective.source', 'configuration')
        ->where('rows.0.effective.modelSource', 'default'));

    config(['ai.features.describe.model' => 'qwen3.5:9b']);

    $this->get('/ai/settings')->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.effective.modelSource', 'configuration'));
});

it('AC-5 the request level keeps its source while the model is labelled with its own', function (): void {
    config(['ai.default' => 'ollama']);

    $resolved = app(AiProviders::class)->resolve('describe', 'anthropic');

    expect($resolved->source)->toBe('request')->and($resolved->modelSource)->toBe('default');
});

it('AC-2 treats an untagged Ollama id and its :latest tag as the same installed model', function (): void {
    expect(OllamaServerInfo::canonical('bge-m3:latest'))->toBe('bge-m3')
        ->and(OllamaServerInfo::canonical('qwen3.5:9b'))->toBe('qwen3.5:9b');
});

it('AC-5 after saving another embeddings model the row counts 0 current vectors and shows the warning and command', function (): void {
    config(['ai.providers.openai.key' => 'sk-test-openai']);
    $listings = Listing::factory()->count(2)->for(Dealer::factory()->create())->create();

    foreach ($listings as $index => $listing) {
        DB::table('listing_documents')->insert([
            'listing_id' => $listing->id,
            'body_type' => 'sedan',
            'description' => "text {$index}",
            'embedding' => '['.implode(',', array_fill(0, 1024, 0.5)).']',
            'embedding_model' => 'ollama/bge-m3',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $choice = ['provider' => 'openai', 'model' => 'text-embedding-3-small'];

    $this->put('/ai/settings/embeddings-listings', $choice)->assertSessionHasErrors('reindex_acknowledged', errorBag: 'settings-embeddings-listings');
    expect(AiSetting::query()->count())->toBe(0);

    $this->put('/ai/settings/embeddings-listings', [...$choice, 'reindex_acknowledged' => '1'])->assertSessionHasNoErrors();

    $this->get('/ai/settings')->assertInertia(fn (Assert $page) => $page
        ->where('rows.6.feature', 'embeddings-listings')
        ->where('rows.6.effective.model', 'text-embedding-3-small')
        ->where('rows.6.index.current', 0)
        ->where('rows.6.index.foreign', 2)
        ->where('rows.6.index.warning', true)
        ->where('rows.6.index.command', 'ddev artisan listings:embed --fresh --wait'));
});

it('AC-7 the embeddings-photos row offers only the image encoder and its models', function (): void {
    $this->get('/ai/settings')->assertInertia(fn (Assert $page) => $page
        ->where('rows.8.feature', 'embeddings-photos')
        ->where('rows.8.kind', 'image-embeddings')
        ->where('rows.8.effective.provider', 'photo-encoder')
        ->where('rows.8.effective.model', 'openai/clip-vit-base-patch32')
        ->where('rows.8.providers', fn ($providers) => collect($providers)->pluck('name')->all() === ['photo-encoder']
            && collect($providers)->firstWhere('name', 'photo-encoder')['models'] === ['openai/clip-vit-base-patch32', 'laion/CLIP-ViT-B-32-laion2B-s34B-b79K'])
        ->where('rows.8.index.table', 'listing_photos')
        ->where('rows.8.index.command', 'ddev artisan photos:embed --fresh --wait'));
});

it('AC-7 the text and embeddings rows do not offer the image encoder', function (): void {
    $this->get('/ai/settings')->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.providers', fn ($providers) => ! collect($providers)->pluck('name')->contains('photo-encoder'))
        ->where('rows.6.providers', fn ($providers) => ! collect($providers)->pluck('name')->contains('photo-encoder')));
});

it('AC-7 changing the photo encoder model needs the re-index acknowledgement', function (): void {
    $this->put('/ai/settings/embeddings-photos', ['provider' => 'photo-encoder', 'model' => 'laion/CLIP-ViT-B-32-laion2B-s34B-b79K'])
        ->assertSessionHasErrors('reindex_acknowledged', errorBag: 'settings-embeddings-photos');
    expect(AiSetting::query()->count())->toBe(0);

    $this->put('/ai/settings/embeddings-photos', ['provider' => 'photo-encoder', 'model' => 'laion/CLIP-ViT-B-32-laion2B-s34B-b79K', 'reindex_acknowledged' => true])
        ->assertSessionHasNoErrors();
    expect(AiSetting::query()->count())->toBe(1);
});

it('AC-7 a model the encoder does not offer is refused for embeddings-photos', function (): void {
    $this->put('/ai/settings/embeddings-photos', ['provider' => 'photo-encoder', 'model' => 'unlisted/model', 'reindex_acknowledged' => true])
        ->assertSessionHasErrors('model', errorBag: 'settings-embeddings-photos');

    $this->put('/ai/settings/embeddings-photos', ['provider' => 'ollama', 'model' => 'bge-m3', 'reindex_acknowledged' => true])
        ->assertSessionHasErrors('model', errorBag: 'settings-embeddings-photos');
});
