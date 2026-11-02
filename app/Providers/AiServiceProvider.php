<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\AiRunRecorder;
use App\Ai\FakeStreamProvider;
use App\Ai\RecordAiRun;
use App\Contracts\AiRunRepositoryInterface;
use App\Repositories\EloquentAiRunRepository;
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

        $this->callAfterResolving(AiManager::class, function (AiManager $manager): void {
            $manager->extend('fake-stream', fn (Application $app, array $config): FakeStreamProvider => new FakeStreamProvider(
                $config,
                $app->make(Dispatcher::class),
            ));
        });
    }

    /**
     * Log every streamed agent run to ai_runs.
     */
    public function boot(): void
    {
        Event::listen(StreamingAgent::class, [RecordAiRun::class, 'onStreaming']);
        Event::listen(AgentStreamed::class, [RecordAiRun::class, 'onStreamed']);
        Event::listen(AgentFailed::class, [RecordAiRun::class, 'onFailed']);

        // Each describe call holds a PHP worker and the GPU for seconds, so callers are limited per IP.
        RateLimiter::for('describe', fn (Request $request): Limit => Limit::perMinute((int) config('ai.describe_per_minute'))->by((string) $request->ip()));
    }
}
