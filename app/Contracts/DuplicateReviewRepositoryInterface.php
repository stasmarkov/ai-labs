<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\DuplicateJudgeRequest;
use App\DTOs\DuplicateReviewGroup;
use App\DTOs\DuplicateReviewSummary;
use App\Enums\DuplicateReviewSource;
use Illuminate\Pagination\LengthAwarePaginator;

interface DuplicateReviewRepositoryInterface
{
    /**
     * The stored groups of the vector label, each with the judgement of the given model and prompt and its review.
     *
     * @return LengthAwarePaginator<int, DuplicateReviewGroup>
     */
    public function page(string $label, DuplicateJudgeRequest $judging, int $page, int $perPage): LengthAwarePaginator;

    public function summary(string $label, DuplicateJudgeRequest $judging): DuplicateReviewSummary;

    public function find(int $groupId, DuplicateJudgeRequest $judging): ?DuplicateReviewGroup;

    /**
     * Ids of the photos of a group, or null when there is no such group.
     *
     * @return list<int>|null
     */
    public function memberIds(int $groupId, ?string $label = null): ?array;

    /**
     * Ids of the photos that sit in a reviewed group of the label. A rebuild keeps those groups and leaves their photos
     * out of the new ones.
     *
     * @return list<int>
     */
    public function reviewedPhotoIds(string $label): array;

    /**
     * The photo a completed judgement of the given model and prompt picked, or null when there is none.
     */
    public function pickedPhotoId(int $groupId, DuplicateJudgeRequest $judging): ?int;

    /**
     * Store the review of a group and set the marks of its photos: the kept one and the ones to remove. Rows and
     * files stay as they are.
     *
     * @param  list<int>  $removePhotoIds
     */
    public function saveReview(int $groupId, int $keptPhotoId, DuplicateReviewSource $source, array $removePhotoIds): void;

    /**
     * Remove the review of a group and clear the marks of its photos.
     */
    public function clearReview(int $groupId): void;
}
