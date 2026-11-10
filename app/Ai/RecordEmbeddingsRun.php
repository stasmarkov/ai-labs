<?php

declare(strict_types=1);

namespace App\Ai;

use App\Exceptions\InvalidEmbeddingException;
use Laravel\Ai\Events\EmbeddingsFailed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;

final readonly class RecordEmbeddingsRun
{
    private const AGENT = 'embeddings';

    private function recorder(): AiRunRecorder
    {
        return app(AiRunRecorder::class);
    }

    public function onGenerating(GeneratingEmbeddings $event): void
    {
        $this->recorder()->started(
            $event->invocationId,
            $this->recorder()->embeddingsAgent(self::AGENT),
            $event->provider->name(),
            $event->model,
            null,
            count($event->prompt),
        );
    }

    public function onGenerated(EmbeddingsGenerated $event): void
    {
        // A vector of the wrong length is unusable, so the call counts as failed although the model answered.
        $expected = (int) config('ai.providers.'.$event->provider->name().'.models.embeddings.dimensions');

        foreach ($event->response->embeddings as $vector) {
            if ($expected > 0 && count($vector) !== $expected) {
                $this->recorder()->failed($event->invocationId, InvalidEmbeddingException::wrongLength($expected, count($vector)));

                return;
            }
        }

        $inputTokens = $event->response->usage->inputTokens;

        $this->recorder()->completed($event->invocationId, $inputTokens > 0 ? $inputTokens : null, null);
    }

    public function onFailed(EmbeddingsFailed $event): void
    {
        $this->recorder()->failed($event->invocationId, $event->exception);
    }
}
