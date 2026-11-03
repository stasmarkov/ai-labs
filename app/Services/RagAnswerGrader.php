<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiRunRecorder;
use App\Ai\RagGraderAgent;
use App\DTOs\DocsAnswerResult;
use App\DTOs\RagEvalQuestion;
use App\DTOs\RagGrade;
use App\Exceptions\RagGraderReplyException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

final readonly class RagAnswerGrader
{
    public const ATTEMPTS = 2;

    /**
     * Grades an answered result with the given model. A reply that is not {correctness, groundedness, reason} in range, or
     * a failed call, is tried once more; if the second try fails too the answer stays ungraded and says why. Every call
     * writes an ai_runs row.
     */
    public function grade(RagEvalQuestion $question, DocsAnswerResult $result, string $model): RagGrade
    {
        $recorder = app(AiRunRecorder::class);
        $agent = new RagGraderAgent($model);
        $prompt = $agent->promptFor($question, $result->text, $result->sources);
        $retrieved = array_map(fn ($match): int => $match->id, $result->sources);
        $failure = RagGrade::PROVIDER_ERROR;
        $invocationId = null;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $startedAt = hrtime(true);
            $response = null;

            try {
                $response = $agent->prompt($prompt, model: $model);
                $grade = $this->validate($response);
                $invocationId = $this->record($recorder, $agent, 'completed', $startedAt, $retrieved, $response);

                return new RagGrade($grade['correctness'], $grade['groundedness'], $grade['reason'], null, $invocationId, $attempt);
            } catch (RagGraderReplyException $e) {
                $failure = $e->failure;
                $invocationId = $this->record($recorder, $agent, 'failed', $startedAt, $retrieved, $response, $e);
            } catch (Throwable $e) {
                $failure = RagGrade::PROVIDER_ERROR;
                $invocationId = $this->record($recorder, $agent, 'failed', $startedAt, $retrieved, null, $e);
            }
        }

        return new RagGrade(null, null, null, $failure, $invocationId, self::ATTEMPTS);
    }

    /**
     * @return array{correctness: int, groundedness: int, reason: string}
     *
     * @throws RagGraderReplyException
     */
    private function validate(StructuredAgentResponse $response): array
    {
        $data = $response->structured;

        if ($data === []) {
            throw RagGraderReplyException::because(RagGrade::INVALID_JSON);
        }

        foreach (['correctness', 'groundedness'] as $key) {
            if (! isset($data[$key]) || ! is_int($data[$key]) || $data[$key] < 0 || $data[$key] > 2) {
                throw RagGraderReplyException::because(RagGrade::SCHEMA_MISMATCH);
            }
        }

        if (! isset($data['reason']) || ! is_string($data['reason'])) {
            throw RagGraderReplyException::because(RagGrade::SCHEMA_MISMATCH);
        }

        return [
            'correctness' => $data['correctness'],
            'groundedness' => $data['groundedness'],
            'reason' => mb_substr($data['reason'], 0, RagGraderAgent::MAX_REASON_LENGTH),
        ];
    }

    /**
     * @param  list<int>  $retrieved
     */
    private function record(AiRunRecorder $recorder, RagGraderAgent $agent, string $status, int $startedAt, array $retrieved, ?StructuredAgentResponse $response, ?Throwable $exception = null): string
    {
        return $recorder->recordRun(
            $status,
            class_basename($agent),
            $agent->providerName(),
            $agent->modelName(),
            $agent->promptVersion(),
            intdiv(hrtime(true) - $startedAt, 1_000_000),
            $retrieved,
            null,
            $response?->usage->inputTokens,
            $response?->usage->outputTokens,
            $response?->invocationId,
            $exception,
        );
    }
}
