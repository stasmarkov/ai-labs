<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\AbortWatcher;
use App\Ai\AiRunRecorder;
use App\Ai\ConnectionAbortWatcher;
use App\Ai\FakeStreamProvider;
use App\Ai\RecordAiRun;
use App\Ai\RecordEmbeddingsRun;
use App\Contracts\AiRunRepositoryInterface;
use App\Repositories\EloquentAiRunRepository;
use App\Services\PromptInjectionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiManager;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\EmbeddingsFailed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\StreamingAgent;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Register the "fake-stream" driver with the AI SDK manager.
     */
    public function register(): void
    {
        $this->app->bind(AiRunRepositoryInterface::class, EloquentAiRunRepository::class);
        $this->app->scoped(AiRunRecorder::class);
        $this->app->bind(AbortWatcher::class, ConnectionAbortWatcher::class);
        $this->app->singleton(PromptInjectionGuard::class, fn (): PromptInjectionGuard => PromptInjectionGuard::fromFile((string) config('ai.docs.answer.guard')));

        $this->callAfterResolving(AiManager::class, function (AiManager $manager): void {
            $manager->extend('fake-stream', fn (Application $app, array $config): FakeStreamProvider => new FakeStreamProvider(
                $config,
                $app->make(Dispatcher::class),
            ));
        });
    }

    /**
     * Log every streamed agent run and every embeddings call to ai_runs.
     */
    public function boot(): void
    {
        Event::listen(StreamingAgent::class, [RecordAiRun::class, 'onStreaming']);
        Event::listen(AgentStreamed::class, [RecordAiRun::class, 'onStreamed']);
        Event::listen(AgentFailed::class, [RecordAiRun::class, 'onFailed']);
        Event::listen(GeneratingEmbeddings::class, [RecordEmbeddingsRun::class, 'onGenerating']);
        Event::listen(EmbeddingsGenerated::class, [RecordEmbeddingsRun::class, 'onGenerated']);
        Event::listen(EmbeddingsFailed::class, [RecordEmbeddingsRun::class, 'onFailed']);

        // Each describe call holds a PHP worker and the GPU for seconds, so callers are limited per IP.
        RateLimiter::for('describe', fn (Request $request): Limit => Limit::perMinute((int) config('ai.describe_per_minute'))->by((string) $request->ip()));
        // Cloud calls cost money, so any request that resolves to a cloud provider also counts against this per-IP limit.
        RateLimiter::for('cloud-ai', fn (Request $request): Limit => Limit::perMinute((int) config('ai.cloud_per_minute'))->by((string) $request->ip()));
        RateLimiter::for('cloud-ai-global', fn (Request $request): Limit => Limit::perMinute((int) config('ai.cloud_global_per_minute'))->by('all'));
        RateLimiter::for('slow', fn (Request $request): Limit => Limit::perMinute((int) config('services.vinstub.per_minute'))->by((string) $request->ip()));
        RateLimiter::for('semantic-search', fn (Request $request): Limit => Limit::perMinute((int) config('ai.semantic_per_minute'))->by((string) $request->ip()));
        RateLimiter::for('nl-search', fn (Request $request): Limit => Limit::perMinute((int) config('ai.nl_search.per_minute'))->by((string) $request->ip()));
        RateLimiter::for('docs-search', fn (Request $request): Limit => Limit::perMinute((int) config('ai.docs.search_per_minute'))->by((string) $request->ip()));
        RateLimiter::for('docs-ask', fn (Request $request): Limit => Limit::perMinute((int) config('ai.docs.answer.per_minute'))->by((string) $request->ip()));
    }
}
