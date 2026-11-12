<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiRunRecorder;
use App\Ai\PhotoBestPickAgent;
use App\Contracts\DuplicateJudgementRepositoryInterface;
use App\DTOs\DuplicateJudgeMember;
use App\DTOs\DuplicateJudgeRequest;
use App\DTOs\PhotoExtractionStatusCounts;
use App\Exceptions\ProviderNotConfiguredException;
use App\Jobs\JudgeDuplicateGroupJob;
use App\Rules\PhotoBestPickRules;
use App\Support\PhotoImageResizer;
use Throwable;

final readonly class DuplicateJudgementService
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private DuplicateJudgementRepositoryInterface $judgements,
        private AiRunRecorder $recorder,
    ) {}

    /**
     * @return list<int>
     */
    public function groupIds(string $label, ?DuplicateJudgeRequest $request = null): array
    {
        return $this->judgements->groupIds($label, $request);
    }

    /**
     * The provider, model and prompt version a judgement queued now would run with.
     */
    public function request(): DuplicateJudgeRequest
    {
        $agent = new PhotoBestPickAgent;

        return new DuplicateJudgeRequest($agent->providerName(), $agent->modelName(), $agent->promptVersion());
    }

    /**
     * Create (or reset) the judgement as queued and queue its job.
     *
     * @return int|null the judgement id, or null when the group is already queued or running for this model
     */
    public function queue(int $groupId, DuplicateJudgeRequest $request): ?int
    {
        $judgementId = $this->judgements->queue($groupId, $request);

        if ($judgementId !== null) {
            JudgeDuplicateGroupJob::dispatch($judgementId);
        }

        return $judgementId;
    }

    /**
     * @param  list<int>  $judgementIds
     */
    public function statusCounts(array $judgementIds): PhotoExtractionStatusCounts
    {
        return $this->judgements->statusCounts($judgementIds);
    }

    /**
     * Send the whole group in one call and ask up to MAX_ATTEMPTS times until the answer passes the rules (the pick is a
     * photo of the group), one ai_runs row per attempt. A transport error is one attempt with no retry. The row always
     * ends completed or failed.
     */
    public function judge(int $judgementId): void
    {
        $target = $this->judgements->findTarget($judgementId);

        if ($target === null) {
            return;
        }

        $agent = new PhotoBestPickAgent;

        // A job runs on the provider it was queued for; if the configuration changed since, the stored provider and model
        // no longer describe the call, so the row fails and asks to be queued again instead of running elsewhere.
        if ($agent->providerName() !== $target->provider || $agent->modelName() !== $target->model) {
            $this->judgements->fail($judgementId, ['transport' => ['ProviderChanged', "Queued for {$target->provider} {$target->model}, but photo-vision now runs on {$agent->providerName()} {$agent->modelName()}; queue it again."]], 0, [], 0);

            return;
        }

        $tooLarge = $this->boundsError($target->members);

        if ($tooLarge !== null) {
            $this->judgements->fail($judgementId, ['group' => [$tooLarge]], 0, [], 0);

            return;
        }

        $groupIds = array_map(fn (DuplicateJudgeMember $member): int => $member->photoId, $target->members);

        $this->judgements->markRunning($judgementId);
        $startedAt = hrtime(true);
        $invocationIds = [];
        $errors = [];
        $attempts = 0;

        while ($attempts < self::MAX_ATTEMPTS) {
            $attempts++;
            $attemptStartedAt = hrtime(true);

            try {
                $response = $agent->judge($target->members);
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

            $validator = PhotoBestPickRules::validator($output, $groupIds);
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
                /** @var array{best_photo_id: int, reasons: array<int, string>, issues: array<int, array{photo_id: int, problem: string}>} $data */
                $data = $validator->validated();
                $this->judgements->complete(
                    $judgementId,
                    (int) $data['best_photo_id'],
                    array_values($data['reasons']),
                    array_values($data['issues']),
                    $attempts,
                    $invocationIds,
                    $this->elapsedMs($startedAt),
                );

                return;
            }

            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();
        }

        $this->judgements->fail($judgementId, $errors, $attempts, $invocationIds, $this->elapsedMs($startedAt));
    }

    /**
     * Ends a row whose job died without finishing (a timeout or a killed worker), so it is not left running.
     */
    public function abandon(int $judgementId, Throwable $exception): void
    {
        $this->judgements->fail($judgementId, $this->errorOf($exception), 0, [], 0);
    }

    /**
     * A group is judged with 1 to 6 images of at most 1024 px on the long side; anything else is refused before a call.
     *
     * @param  list<DuplicateJudgeMember>  $members
     */
    private function boundsError(array $members): ?string
    {
        if ($members === [] || count($members) > PhotoBestPickAgent::MAX_PHOTOS) {
            return 'A group is judged with 1 to '.PhotoBestPickAgent::MAX_PHOTOS.' photos, this one has '.count($members).'.';
        }

        foreach ($members as $member) {
            if (max($member->width, $member->height) > PhotoImageResizer::MAX_SIDE) {
                return "Photo {$member->photoId} is larger than ".PhotoImageResizer::MAX_SIDE.' px.';
            }
        }

        return null;
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
