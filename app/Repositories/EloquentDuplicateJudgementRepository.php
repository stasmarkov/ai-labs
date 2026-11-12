<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\DuplicateJudgementRepositoryInterface;
use App\DTOs\DuplicateJudgeMember;
use App\DTOs\DuplicateJudgeRequest;
use App\DTOs\DuplicateJudgeTarget;
use App\DTOs\PhotoExtractionStatusCounts;
use App\Models\PhotoDuplicateJudgement;
use Illuminate\Support\Facades\DB;

final readonly class EloquentDuplicateJudgementRepository implements DuplicateJudgementRepositoryInterface
{
    public function groupIds(string $label, ?DuplicateJudgeRequest $request = null): array
    {
        return DB::table('photo_duplicate_groups')
            ->where('label', $label)
            // A reviewed group keeps the judgement its review was decided against.
            ->whereNotExists(fn ($review) => $review->selectRaw('1')->from('photo_duplicate_reviews')
                ->whereColumn('photo_duplicate_reviews.group_id', 'photo_duplicate_groups.id'))
            // A group already judged by this provider, model and prompt is not asked again.
            ->when($request !== null, fn ($query) => $query->whereNotExists(fn ($judged) => $judged->selectRaw('1')
                ->from('photo_duplicate_judgements')
                ->whereColumn('photo_duplicate_judgements.group_id', 'photo_duplicate_groups.id')
                ->where('photo_duplicate_judgements.status', 'completed')
                ->where('photo_duplicate_judgements.provider', $request->provider)
                ->where('photo_duplicate_judgements.model', $request->model)
                ->where('photo_duplicate_judgements.prompt_version', $request->promptVersion)))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function queue(int $groupId, DuplicateJudgeRequest $request): ?int
    {
        return DB::transaction(function () use ($groupId, $request): ?int {
            $keys = ['group_id' => $groupId, 'provider' => $request->provider, 'model' => $request->model, 'prompt_version' => $request->promptVersion];
            $existing = PhotoDuplicateJudgement::query()->where($keys)->lockForUpdate()->first();

            if ($existing !== null && in_array($existing->status, [PhotoDuplicateJudgement::STATUS_QUEUED, PhotoDuplicateJudgement::STATUS_RUNNING], true)) {
                return null;
            }

            return PhotoDuplicateJudgement::query()->updateOrCreate($keys, [
                'status' => PhotoDuplicateJudgement::STATUS_QUEUED,
                'attempts' => 0,
                'best_photo_id' => null,
                'reasons' => null,
                'issues' => null,
                'errors' => null,
                'invocation_ids' => null,
                'latency_ms' => null,
            ])->id;
        });
    }

    public function findTarget(int $judgementId): ?DuplicateJudgeTarget
    {
        $judgement = PhotoDuplicateJudgement::query()->find($judgementId);

        if ($judgement === null) {
            return null;
        }

        $members = DB::table('photo_duplicate_members')
            ->join('listing_photos', 'listing_photos.id', '=', 'photo_duplicate_members.photo_id')
            ->where('photo_duplicate_members.group_id', $judgement->group_id)
            ->orderBy('listing_photos.id')
            ->get(['listing_photos.id', 'listing_photos.path', 'listing_photos.width', 'listing_photos.height'])
            ->map(fn (object $row): DuplicateJudgeMember => new DuplicateJudgeMember((int) $row->id, (string) $row->path, (int) $row->width, (int) $row->height))
            ->all();

        return new DuplicateJudgeTarget(
            $judgement->id,
            $judgement->group_id,
            $judgement->provider,
            $judgement->model,
            $judgement->prompt_version,
            $members,
        );
    }

    public function markRunning(int $judgementId): void
    {
        PhotoDuplicateJudgement::query()->whereKey($judgementId)->update(['status' => PhotoDuplicateJudgement::STATUS_RUNNING]);
    }

    public function complete(int $judgementId, int $bestPhotoId, array $reasons, array $issues, int $attempts, array $invocationIds, int $latencyMs): void
    {
        PhotoDuplicateJudgement::query()->whereKey($judgementId)->first()?->update([
            'status' => PhotoDuplicateJudgement::STATUS_COMPLETED,
            'attempts' => $attempts,
            'best_photo_id' => $bestPhotoId,
            'reasons' => $reasons,
            'issues' => $issues,
            'errors' => null,
            'invocation_ids' => $invocationIds,
            'latency_ms' => $latencyMs,
        ]);
    }

    public function fail(int $judgementId, array $errors, int $attempts, array $invocationIds, int $latencyMs): void
    {
        PhotoDuplicateJudgement::query()->whereKey($judgementId)->first()?->update([
            'status' => PhotoDuplicateJudgement::STATUS_FAILED,
            'attempts' => $attempts,
            'best_photo_id' => null,
            'reasons' => null,
            'issues' => null,
            'errors' => $errors,
            'invocation_ids' => $invocationIds,
            'latency_ms' => $latencyMs,
        ]);
    }

    public function statusCounts(array $judgementIds): PhotoExtractionStatusCounts
    {
        /** @var array<string, int> $counts */
        $counts = PhotoDuplicateJudgement::query()
            ->whereIn('id', $judgementIds)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        return new PhotoExtractionStatusCounts(
            $counts[PhotoDuplicateJudgement::STATUS_QUEUED] ?? 0,
            $counts[PhotoDuplicateJudgement::STATUS_RUNNING] ?? 0,
            $counts[PhotoDuplicateJudgement::STATUS_COMPLETED] ?? 0,
            $counts[PhotoDuplicateJudgement::STATUS_FAILED] ?? 0,
        );
    }
}
