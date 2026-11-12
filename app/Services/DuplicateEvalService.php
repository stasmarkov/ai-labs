<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiRunRecorder;
use App\Ai\PhotoBestPickAgent;
use App\Contracts\DuplicateEvalRepositoryInterface;
use App\DTOs\DuplicateEvalOutcome;
use App\DTOs\DuplicateEvalPhoto;
use App\DTOs\DuplicateGroupDraft;
use App\DTOs\DuplicateJudgeMember;
use App\Rules\PhotoBestPickRules;
use Throwable;

/**
 * Calibrates the duplicate cutoff against generated copies and asks a model to pick the original of each group, live and
 * outside the queue. It writes ai_runs rows with origin "eval" and no photo_duplicate_judgements row.
 */
final readonly class DuplicateEvalService
{
    public const ORIGIN = 'eval';

    /** Every threshold from 0.90 to 0.99 in steps of 0.01. */
    public const THRESHOLDS = [0.90, 0.91, 0.92, 0.93, 0.94, 0.95, 0.96, 0.97, 0.98, 0.99];

    public function __construct(
        private DuplicateEvalRepositoryInterface $photos,
        private DuplicateClusterService $clusters,
        private DuplicateEvalScorer $scorer,
        private AiRunRecorder $recorder,
    ) {}

    /**
     * The cutoff curve over the photos of the label, the cutoff its rule chooses, and the groups clustered at that cutoff.
     * The timings cover one nearest query per photo and the clustering at the chosen cutoff.
     *
     * @return array{photos: list<DuplicateEvalPhoto>, true_pairs: int, curve: list<array<string, mixed>>, chosen: array{threshold: float, rule: string}, groups: list<DuplicateGroupDraft>, timing: array{photos: int, edges_ms: int, cluster_ms: int, clustering_ms: int}}
     */
    public function calibrate(string $label): array
    {
        $photos = $this->photos->photos($label);
        $ids = array_map(fn (DuplicateEvalPhoto $photo): int => $photo->id, $photos);

        $startedAt = hrtime(true);
        $edges = $this->clusters->edges($ids, $label, min(self::THRESHOLDS));
        $edgesMs = $this->elapsedMs($startedAt);

        $curve = $this->scorer->curve($photos, $edges, self::THRESHOLDS);
        $chosen = $this->scorer->chooseCutoff($curve);

        $startedAt = hrtime(true);
        $groups = $this->clusters->cluster(
            array_filter($edges, fn (array $edge): bool => $edge[2] >= $chosen['threshold']),
            max(2, (int) config('ai.photo_duplicates.max_group', 6)),
        );
        $clusterMs = $this->elapsedMs($startedAt);

        return [
            'photos' => $photos,
            'true_pairs' => $this->scorer->truePairs($photos),
            'curve' => $curve,
            'chosen' => $chosen,
            'groups' => $groups,
            'timing' => ['photos' => count($ids), 'edges_ms' => $edgesMs, 'cluster_ms' => $clusterMs, 'clustering_ms' => $edgesMs + $clusterMs],
        ];
    }

    /**
     * Asks the model, in one call with every photo of the group, which photo is best; retries invalid output up to the
     * attempts of the real judgement. A transport error is one attempt with no retry.
     *
     * @param  list<DuplicateEvalPhoto>  $members  one original and its copies, ascending by id
     */
    public function judge(array $members, int $originalId, string $model): DuplicateEvalOutcome
    {
        $agent = new PhotoBestPickAgent($model);
        $judgeMembers = array_map(fn (DuplicateEvalPhoto $photo): DuplicateJudgeMember => new DuplicateJudgeMember($photo->id, $photo->path, $photo->width, $photo->height), $members);
        $groupIds = array_map(fn (DuplicateEvalPhoto $photo): int => $photo->id, $members);
        $startedAt = hrtime(true);
        $attempts = 0;
        $invocationIds = [];
        $bestPhotoId = null;

        while ($attempts < DuplicateJudgementService::MAX_ATTEMPTS && $bestPhotoId === null) {
            $attempts++;
            $attemptStartedAt = hrtime(true);

            try {
                $response = $agent->judge($judgeMembers);
                $output = $response->toArray();

                if ($output === []) {
                    throw new \UnexpectedValueException('The model returned no structured output.');
                }
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
                    origin: self::ORIGIN,
                );

                if ($e instanceof \UnexpectedValueException) {
                    continue;
                }

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
                origin: self::ORIGIN,
            );

            if (! $invalid) {
                $bestPhotoId = (int) $output['best_photo_id'];
            }
        }

        return new DuplicateEvalOutcome($originalId, $attempts, $bestPhotoId, $this->elapsedMs($startedAt), $invocationIds);
    }

    private function elapsedMs(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
