<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiRunRecorder;
use App\Ai\PhotoVisionAgent;
use App\Contracts\PhotoExtractionRepositoryInterface;
use App\DTOs\PhotoExtractionRequest;
use App\DTOs\PhotoExtractionStatusCounts;
use App\Exceptions\ProviderNotConfiguredException;
use App\Jobs\ExtractPhotoJob;
use App\Rules\PhotoExtractionRules;
use Illuminate\Support\Facades\Validator;
use Throwable;

final readonly class PhotoExtractionService
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private PhotoExtractionRepositoryInterface $extractions,
        private AiRunRecorder $recorder,
    ) {}

    /**
     * The photos still to extract for the model and prompt version. With $fresh the rows of that model and prompt
     * version are deleted first, so every photo is pending again.
     *
     * @return list<int>
     */
    public function pending(PhotoExtractionRequest $request, ?int $limit, bool $fresh): array
    {
        if ($fresh) {
            $this->extractions->deleteFor($request);
        }

        return $this->extractions->pendingPhotoIds($request, $limit);
    }

    public function totalPhotos(): int
    {
        return $this->extractions->countPhotos();
    }

    /**
     * Create (or reset) the row as queued and queue its job.
     *
     * @return int|null the extraction id, or null when the photo is already queued or running for this model
     */
    public function queue(int $photoId, PhotoExtractionRequest $request): ?int
    {
        $extractionId = $this->extractions->queue($photoId, $request);

        if ($extractionId !== null) {
            ExtractPhotoJob::dispatch($extractionId);
        }

        return $extractionId;
    }

    /**
     * @param  list<int>  $extractionIds
     */
    public function statusCounts(array $extractionIds): PhotoExtractionStatusCounts
    {
        return $this->extractions->statusCounts($extractionIds);
    }

    /**
     * Ask the model up to MAX_ATTEMPTS times until its answer passes the rules, one ai_runs row per attempt.
     * A transport error is one attempt with no retry. The row always ends completed or failed.
     */
    public function extract(int $extractionId): void
    {
        $target = $this->extractions->findTarget($extractionId);

        if ($target === null) {
            return;
        }

        $agent = new PhotoVisionAgent($target->model);

        // A job runs on the provider it was queued for. If the configuration changed since, the stored provider and
        // model no longer describe the call, so the row fails and asks to be queued again instead of running elsewhere.
        if ($agent->providerName() !== $target->provider) {
            $this->extractions->fail($extractionId, ['transport' => ['ProviderChanged', "Queued for {$target->provider}, but photo-vision now runs on {$agent->providerName()}; queue it again."]], 0, [], 0);

            return;
        }

        $this->extractions->markRunning($extractionId);
        $startedAt = hrtime(true);
        $invocationIds = [];
        $errors = [];
        $attempts = 0;

        while ($attempts < self::MAX_ATTEMPTS) {
            $attempts++;
            $attemptStartedAt = hrtime(true);

            try {
                $response = $agent->describe($target->photoPath);
                $output = $response->toArray();

                if ($output === []) {
                    throw new \UnexpectedValueException('The model returned no structured output.');
                }
            } catch (ProviderNotConfiguredException $e) {
                $errors = $this->errorOf($e);
                break;
            } catch (Throwable $e) {
                $invocationIds[] = $this->recorder->recordRun(
                    'failed',
                    class_basename($agent),
                    $agent->providerName(),
                    $agent->modelName(),
                    $agent->promptVersion(),
                    $this->elapsedMs($attemptStartedAt),
                    null,
                    exception: $e,
                );
                // An empty or unparsable answer is bad output and is retried; anything else is a transport failure.
                if ($e instanceof \UnexpectedValueException) {
                    $errors = ['output' => [AiRunRecorder::safeMessage($e)]];

                    continue;
                }

                $errors = $this->errorOf($e);

                break;
            }

            $validator = Validator::make($output, PhotoExtractionRules::rules());
            $invalid = $validator->fails();

            $invocationIds[] = $this->recorder->recordRun(
                'completed',
                class_basename($agent),
                $agent->providerName(),
                $agent->modelName(),
                $agent->promptVersion(),
                $this->elapsedMs($attemptStartedAt),
                null,
                tokensIn: $response->usage->inputTokens,
                tokensOut: $response->usage->outputTokens,
                invocationId: $response->invocationId,
                validationFailed: $invalid,
            );

            if (! $invalid) {
                /** @var array<string, mixed> $data */
                $data = $validator->validated();
                $this->extractions->complete($extractionId, $data, $attempts, $invocationIds, $this->elapsedMs($startedAt));

                return;
            }

            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();
        }

        $this->extractions->fail($extractionId, $errors, $attempts, $invocationIds, $this->elapsedMs($startedAt));
    }

    /**
     * Ends a row whose job died without finishing (a timeout or a killed worker), so it is not left running.
     */
    public function abandon(int $extractionId, Throwable $exception): void
    {
        $this->extractions->fail($extractionId, $this->errorOf($exception), 0, [], 0);
    }

    /**
     * A failure that is not about the answer's content, under the key "transport" so the lab can tell it apart.
     *
     * @return array<string, list<string>>
     */
    private function errorOf(Throwable $exception): array
    {
        return ['transport' => [$exception::class, AiRunRecorder::safeMessage($exception)]];
    }

    private function elapsedMs(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
