<?php

declare(strict_types=1);

namespace App\Ai;

use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\StreamingAgent;

final readonly class RecordAiRun
{
    public function __construct(private AiRunRecorder $recorder) {}

    public function onStreaming(StreamingAgent $event): void
    {
        $agent = $event->prompt->agent;

        $this->recorder->started(
            $event->invocationId,
            class_basename($agent),
            $event->prompt->provider()->name(),
            $event->prompt->model,
            $agent instanceof VersionedPrompt ? $agent->promptVersion() : null,
        );
    }

    public function onStreamed(AgentStreamed $event): void
    {
        $this->recorder->completed(
            $event->invocationId,
            $event->response->usage->inputTokens,
            $event->response->usage->outputTokens,
        );
    }

    public function onFailed(AgentFailed $event): void
    {
        $this->recorder->failed($event->invocationId, $event->exception);
    }
}
