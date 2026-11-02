<?php

declare(strict_types=1);

namespace App\Ai;

use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\StreamingAgent;

final readonly class RecordAiRun
{
    /**
     * Octane's event dispatcher keeps the booted application, so a constructor-injected scoped recorder would not be
     * the one the request's SSE protocol reports to. The recorder is taken from the current container instead.
     */
    private function recorder(): AiRunRecorder
    {
        return app(AiRunRecorder::class);
    }

    public function onStreaming(StreamingAgent $event): void
    {
        $agent = $event->prompt->agent;

        $this->recorder()->started(
            $event->invocationId,
            class_basename($agent),
            $event->prompt->provider()->name(),
            $event->prompt->model,
            $agent instanceof VersionedPrompt ? $agent->promptVersion() : null,
        );
    }

    public function onStreamed(AgentStreamed $event): void
    {
        $this->recorder()->completed(
            $event->invocationId,
            $event->response->usage->inputTokens,
            $event->response->usage->outputTokens,
        );
    }

    public function onFailed(AgentFailed $event): void
    {
        $this->recorder()->failed($event->invocationId, $event->exception);
    }
}
