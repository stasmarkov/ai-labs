<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiRunRecorder;
use App\Ai\PhotoVisionAgent;
use App\DTOs\PhotoEvalOutcome;
use App\DTOs\PhotoEvalSample;
use App\Rules\PhotoExtractionRules;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Asks a model about one sample photo, live and outside the queue, with the retry rule of the extraction job.
 * It writes ai_runs rows with origin "eval" and no photo_extractions row.
 */
final readonly class PhotoEvalService
{
    public const ORIGIN = 'eval';

    public function __construct(
        private AiRunRecorder $recorder,
        private PhotoBodyMapper $bodies,
    ) {}

    public function evaluate(PhotoEvalSample $sample, string $model): PhotoEvalOutcome
    {
        $agent = new PhotoVisionAgent($model);
        $startedAt = hrtime(true);
        $attemptLatencies = [];
        $invocationIds = [];
        $data = null;

        while (count($attemptLatencies) < PhotoExtractionService::MAX_ATTEMPTS && $data === null) {
            $attemptStartedAt = hrtime(true);

            try {
                $response = $agent->describe($sample->path);
                $output = $response->toArray();

                if ($output === []) {
                    throw new \UnexpectedValueException('The model returned no structured output.');
                }
            } catch (Throwable $e) {
                $attemptLatencies[] = $this->elapsedMs($attemptStartedAt);
                $invocationIds[] = $this->recorder->recordRun(
                    'failed',
                    class_basename($agent),
                    $agent->providerName(),
                    $agent->modelName(),
                    $agent->promptVersion(),
                    end($attemptLatencies),
                    null,
                    exception: $e,
                    origin: self::ORIGIN,
                );

                if ($e instanceof \UnexpectedValueException) {
                    continue;
                }

                break;
            }

            $attemptLatencies[] = $this->elapsedMs($attemptStartedAt);
            $validator = Validator::make($output, PhotoExtractionRules::rules());
            $invalid = $validator->fails();
            $invocationIds[] = $this->recorder->recordRun(
                'completed',
                class_basename($agent),
                $agent->providerName(),
                $agent->modelName(),
                $agent->promptVersion(),
                end($attemptLatencies),
                null,
                tokensIn: $response->usage->inputTokens,
                tokensOut: $response->usage->outputTokens,
                invocationId: $response->invocationId,
                validationFailed: $invalid,
                origin: self::ORIGIN,
            );

            if (! $invalid) {
                /** @var array<string, mixed> $validated */
                $validated = $validator->validated();
                $data = $validated;
            }
        }

        return new PhotoEvalOutcome(
            $sample->photoId,
            count($attemptLatencies),
            $data,
            $this->elapsedMs($startedAt),
            $attemptLatencies,
            $this->bodies->forClass($sample->sourceClass),
            $invocationIds,
        );
    }

    private function elapsedMs(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
