<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ai\OllamaServerInfo;
use App\DTOs\ModelOption;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OllamaServerInfoModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        config(['ai.providers.ollama.url' => 'http://ollama.test:11434']);
    }

    /**
     * @param  array<string, list<string>>  $capabilities
     */
    private function fakeShow(array $capabilities): void
    {
        Http::fake(['ollama.test:11434/api/tags' => Http::response(['models' => array_map(fn (string $name): array => ['name' => $name], array_keys($capabilities))])]);
        Http::fake(['ollama.test:11434/api/show' => fn ($request) => Http::response(['capabilities' => $capabilities[$request['model']]])]);
    }

    #[Test]
    public function ac_2_models_are_classed_from_show_capabilities(): void
    {
        $this->fakeShow([
            'qwen3.5:9b' => ['completion', 'tools'],
            'bge-m3:latest' => ['embedding'],
            'gemma4:12b' => ['completion', 'vision'],
            'rerank:1' => ['rerank'],
        ]);

        $models = (new OllamaServerInfo)->models();

        $this->assertContainsOnlyInstancesOf(ModelOption::class, $models);
        $this->assertSame([
            ['name' => 'qwen3.5:9b', 'kind' => 'text', 'vision' => false],
            ['name' => 'bge-m3', 'kind' => 'embedding', 'vision' => false], // the default tag is dropped, as configuration names it
            ['name' => 'gemma4:12b', 'kind' => 'text', 'vision' => true],
        ], array_map(fn (ModelOption $m): array => $m->toArray(), $models));
    }

    #[Test]
    public function ac_2_show_capabilities_are_cached_for_ten_minutes_per_model(): void
    {
        $this->fakeShow(['a:1' => ['completion']]);

        $info = new OllamaServerInfo;
        $info->models();
        Cache::forget('ai-lab:ollama-models');
        $info->models();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/show'));
        Http::assertSentCount(3);
    }

    #[Test]
    public function ac_2_the_name_guess_stands_in_when_show_fails(): void
    {
        Http::fake([
            'ollama.test:11434/api/tags' => Http::response(['models' => [['name' => 'bge-m3:latest'], ['name' => 'qwen3.5:9b']]]),
            'ollama.test:11434/api/show' => Http::response('boom', 500),
        ]);

        $this->assertSame(['embedding', 'text'], array_map(fn (ModelOption $m): string => $m->kind, (new OllamaServerInfo)->models()));
    }

    #[Test]
    public function ac_2_the_available_lists_take_a_comma_list_from_the_environment(): void
    {
        putenv('OPENAI_EMBED_MODELS_AVAILABLE=emb-a, emb-b');
        putenv('GEMINI_MODELS_AVAILABLE=g-a,g-b');

        try {
            $config = require config_path('ai.php');
        } finally {
            putenv('OPENAI_EMBED_MODELS_AVAILABLE');
            putenv('GEMINI_MODELS_AVAILABLE');
        }

        $this->assertSame(['emb-a', 'emb-b'], $config['providers']['openai']['models']['embeddings']['available']);
        $this->assertSame(['g-a', 'g-b'], $config['providers']['gemini']['models']['text']['available']);
        $this->assertSame(['gemini-embedding-2'], $config['providers']['gemini']['models']['embeddings']['available']);
    }

    #[Test]
    public function ac_2_models_are_cached_for_ten_seconds(): void
    {
        $this->fakeShow(['a:1' => ['completion']]);

        $info = new OllamaServerInfo;
        $info->models();
        $info->models();

        Http::assertSentCount(2);
    }

    #[Test]
    public function ac_2_an_unreachable_server_gives_an_empty_list(): void
    {
        Http::fake(['ollama.test:11434/api/tags' => Http::response('boom', 500)]);

        $this->assertSame([], (new OllamaServerInfo)->models());
    }

    #[Test]
    public function ac_2_cloud_providers_list_their_configured_available_models(): void
    {
        config(['ai.providers.openai.models.text.available' => ['gpt-a', 'gpt-b']]);
        config(['ai.providers.fake-stream.models.text.available' => null]);

        $info = new OllamaServerInfo;

        $this->assertSame(['gpt-a', 'gpt-b'], $info->available('openai'));
        $this->assertSame([], $info->available('fake-stream'));
        Http::assertNothingSent();
    }
}
