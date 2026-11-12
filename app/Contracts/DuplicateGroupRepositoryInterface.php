<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\DuplicateGroupDraft;
use App\DTOs\SimilarPhotoMatch;

interface DuplicateGroupRepositoryInterface
{
    /**
     * Ids, in ascending order, of the photos (imported and augmented) whose image vector was made with the label.
     *
     * @return list<int>
     */
    public function photoIdsWithVector(string $label): array;

    /**
     * The photos nearest to the given photo's vector by cosine distance (the photo itself first), among vectors made
     * with the label. Augmented photos and photos of the same listing are included.
     *
     * @return list<SimilarPhotoMatch>
     */
    public function nearest(int $photoId, string $label, int $limit, int $efSearch): array;

    /**
     * Store the groups of the label in one transaction, removing the unreviewed groups stored for that label before; a reviewed group stays.
     *
     * @param  list<DuplicateGroupDraft>  $groups
     * @return int the number of older groups removed
     */
    public function replaceGroups(string $label, float $cutoff, array $groups): int;

    /**
     * Delete every group that holds a photo of the given source, with its members, judgements and review, and clear the
     * review marks those groups wrote on their photos.
     */
    public function deleteGroupsHolding(string $source): void;
}
