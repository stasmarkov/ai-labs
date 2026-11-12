<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\PhotoEncoderClient;
use App\Contracts\DuplicateReviewRepositoryInterface;
use App\DTOs\DuplicateReviewGroup;
use App\DTOs\DuplicateReviewSummary;
use App\Enums\DuplicateReviewSource;
use App\Exceptions\PhotoNotInDuplicateGroupException;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The learner's review of duplicate groups. It only marks photos: the kept one and the ones to remove. No photo row or
 * file is ever deleted here.
 */
final readonly class DuplicateReviewService
{
    public const PER_PAGE = 10;

    public function __construct(
        private DuplicateReviewRepositoryInterface $reviews,
        private DuplicateJudgementService $judgements,
        private PhotoEncoderClient $encoder,
    ) {}

    /**
     * The vector label whose groups the review shows: the one the encoder is configured with now.
     */
    public function label(): string
    {
        return $this->encoder->label();
    }

    public function cutoff(): float
    {
        return (float) config('ai.photo_duplicates.cutoff');
    }

    /**
     * @return LengthAwarePaginator<int, DuplicateReviewGroup>
     */
    public function page(int $page): LengthAwarePaginator
    {
        return $this->reviews->page($this->label(), $this->judgements->request(), max(1, $page), self::PER_PAGE);
    }

    public function summary(): DuplicateReviewSummary
    {
        return $this->reviews->summary($this->label(), $this->judgements->request());
    }

    /**
     * Keep one photo of a group and mark the others for removal. A pick that is not the model's is stored as an
     * override.
     *
     * @throws NotFoundHttpException
     * @throws PhotoNotInDuplicateGroupException
     */
    public function keep(int $groupId, int $photoId): DuplicateReviewGroup
    {
        $memberIds = $this->reviews->memberIds($groupId, $this->label()) ?? throw new NotFoundHttpException("No duplicate group {$groupId}.");

        if (! in_array($photoId, $memberIds, true)) {
            throw new PhotoNotInDuplicateGroupException($groupId, $photoId);
        }

        $judging = $this->judgements->request();
        $source = $this->reviews->pickedPhotoId($groupId, $judging) === $photoId ? DuplicateReviewSource::Model : DuplicateReviewSource::Override;

        $this->reviews->saveReview($groupId, $photoId, $source, array_values(array_filter($memberIds, fn (int $id): bool => $id !== $photoId)));

        return $this->group($groupId);
    }

    /**
     * Take the marks of a group back. A group with no review is left as it is.
     *
     * @throws NotFoundHttpException
     */
    public function undo(int $groupId): DuplicateReviewGroup
    {
        if ($this->reviews->memberIds($groupId, $this->label()) === null) {
            throw new NotFoundHttpException("No duplicate group {$groupId}.");
        }

        $this->reviews->clearReview($groupId);

        return $this->group($groupId);
    }

    private function group(int $groupId): DuplicateReviewGroup
    {
        return $this->reviews->find($groupId, $this->judgements->request())
            ?? throw new NotFoundHttpException("No duplicate group {$groupId}.");
    }
}
