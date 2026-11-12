<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\PhotoEncoderClient;
use App\Contracts\DuplicateGroupRepositoryInterface;
use App\Contracts\DuplicateReviewRepositoryInterface;
use App\DTOs\DuplicateClusterReport;
use App\DTOs\DuplicateGroupDraft;
use App\DTOs\DuplicateMember;

/**
 * Joins near-duplicate photos into groups: every photo with a vector reads its nearest candidates, pairs at or above
 * the cutoff become edges, and the connected components become groups. A component larger than the cap is cut along its
 * weakest links until every part fits.
 */
final readonly class DuplicateClusterService
{
    public function __construct(
        private DuplicateGroupRepositoryInterface $groups,
        private DuplicateReviewRepositoryInterface $reviews,
        private PhotoEncoderClient $encoder,
    ) {}

    /**
     * Rebuild the groups of the current vector label, replacing the unreviewed ones stored before. A reviewed group stays
     * as it is, and its photos are left out of the new groups.
     */
    public function rebuild(): DuplicateClusterReport
    {
        $startedAt = hrtime(true);
        $label = $this->encoder->label();
        $cutoff = (float) config('ai.photo_duplicates.cutoff');
        $cap = max(2, (int) config('ai.photo_duplicates.max_group', 6));
        $reviewed = array_flip($this->reviews->reviewedPhotoIds($label));
        $photoIds = array_values(array_filter($this->groups->photoIdsWithVector($label), fn (int $photoId): bool => ! isset($reviewed[$photoId])));

        $drafts = $this->cluster($this->edges($photoIds, $label, $cutoff, $reviewed), $cap);
        $replaced = $this->groups->replaceGroups($label, $cutoff, $drafts);

        return new DuplicateClusterReport(
            label: $label,
            cutoff: $cutoff,
            photos: count($photoIds),
            groups: count($drafts),
            photosGrouped: array_sum(array_map(fn (DuplicateGroupDraft $draft): int => count($draft->members), $drafts)),
            splitGroups: count(array_filter($drafts, fn (DuplicateGroupDraft $draft): bool => $draft->split)),
            replacedGroups: $replaced,
            elapsedMs: (int) round((hrtime(true) - $startedAt) / 1_000_000),
        );
    }

    /**
     * One nearest query per photo. Similarities are compared at the six decimals a stored vector can carry, so a pair
     * sitting exactly on the cutoff is kept.
     *
     * @param  list<int>  $photoIds
     * @param  array<int, int>  $skipped  photos of reviewed groups, never joined to a new group
     * @return array<string, array{0: int, 1: int, 2: float}> keyed "low:high"
     */
    public function edges(array $photoIds, string $label, float $cutoff, array $skipped = []): array
    {
        $candidates = max(2, (int) config('ai.photo_duplicates.candidates', 20));
        $efSearch = (int) config('ai.photo_duplicates.ef_search', 100);
        $edges = [];

        foreach ($photoIds as $photoId) {
            foreach ($this->groups->nearest($photoId, $label, $candidates, $efSearch) as $match) {
                $similarity = round($match->similarity, 6);

                if ($match->photoId === $photoId || $similarity < $cutoff || isset($skipped[$match->photoId])) {
                    continue;
                }

                $low = min($photoId, $match->photoId);
                $high = max($photoId, $match->photoId);
                $key = "{$low}:{$high}";

                if (! isset($edges[$key]) || $edges[$key][2] < $similarity) {
                    $edges[$key] = [$low, $high, $similarity];
                }
            }
        }

        return $edges;
    }

    /**
     * @param  array<string, array{0: int, 1: int, 2: float}>  $edges
     * @return list<DuplicateGroupDraft>
     */
    public function cluster(array $edges, int $cap): array
    {
        $parent = [];

        foreach ($edges as [$low, $high]) {
            $this->union($parent, $low, $high);
        }

        $components = [];

        foreach (array_keys($parent) as $photoId) {
            $components[$this->find($parent, $photoId)][] = $photoId;
        }

        $drafts = [];

        foreach ($components as $members) {
            sort($members);

            if (count($members) <= $cap) {
                $drafts[] = $this->draft($members, $edges, false);

                continue;
            }

            foreach ($this->split($members, $edges, $cap) as $part) {
                $drafts[] = $this->draft($part, $edges, true);
            }
        }

        usort($drafts, fn (DuplicateGroupDraft $a, DuplicateGroupDraft $b): int => $a->members[0]->photoId <=> $b->members[0]->photoId);

        return $drafts;
    }

    /**
     * The parts of an oversized component: the maximum spanning tree (strongest edges first, ties by the smaller ids),
     * then the weakest tree edge inside any part above the cap is removed (ties: the higher ids) until all fit.
     * Single photos left alone are dropped.
     *
     * @param  list<int>  $members
     * @param  array<string, array{0: int, 1: int, 2: float}>  $edges
     * @return list<list<int>>
     */
    private function split(array $members, array $edges, int $cap): array
    {
        $inside = array_flip($members);
        $candidates = array_values(array_filter($edges, fn (array $edge): bool => isset($inside[$edge[0]])));
        usort($candidates, fn (array $a, array $b): int => [$b[2], $a[0], $a[1]] <=> [$a[2], $b[0], $b[1]]);

        $parent = [];
        $tree = [];

        foreach ($candidates as $edge) {
            if ($this->find($parent, $edge[0]) !== $this->find($parent, $edge[1])) {
                $this->union($parent, $edge[0], $edge[1]);
                $tree[] = $edge;
            }
        }

        $parts = [];
        $pending = [[$members, $tree]];

        while ($pending !== []) {
            [$nodes, $treeEdges] = array_pop($pending);

            if (count($nodes) <= $cap) {
                if (count($nodes) > 1) {
                    sort($nodes);
                    $parts[] = $nodes;
                }

                continue;
            }

            usort($treeEdges, fn (array $a, array $b): int => [$a[2], $b[1], $b[0]] <=> [$b[2], $a[1], $a[0]]);
            array_shift($treeEdges);

            foreach ($this->components($nodes, $treeEdges) as $side) {
                $sideNodes = array_flip($side);
                $pending[] = [$side, array_values(array_filter($treeEdges, fn (array $edge): bool => isset($sideNodes[$edge[0]])))];
            }
        }

        return $parts;
    }

    /**
     * @param  list<int>  $nodes
     * @param  list<array{0: int, 1: int, 2: float}>  $treeEdges
     * @return list<list<int>>
     */
    private function components(array $nodes, array $treeEdges): array
    {
        $parent = [];

        foreach ($nodes as $node) {
            $parent[$node] = $node;
        }

        foreach ($treeEdges as [$low, $high]) {
            $this->union($parent, $low, $high);
        }

        $sides = [];

        foreach ($nodes as $node) {
            $sides[$this->find($parent, $node)][] = $node;
        }

        return array_values($sides);
    }

    /**
     * @param  list<int>  $members
     * @param  array<string, array{0: int, 1: int, 2: float}>  $edges
     */
    private function draft(array $members, array $edges, bool $split): DuplicateGroupDraft
    {
        $inside = array_flip($members);
        $strongest = array_fill_keys($members, -1.0);

        foreach ($edges as [$low, $high, $similarity]) {
            if (isset($inside[$low], $inside[$high])) {
                $strongest[$low] = max($strongest[$low], $similarity);
                $strongest[$high] = max($strongest[$high], $similarity);
            }
        }

        return new DuplicateGroupDraft(
            array_map(fn (int $photoId): DuplicateMember => new DuplicateMember($photoId, $strongest[$photoId]), $members),
            $split,
        );
    }

    /**
     * @param  array<int, int>  $parent
     */
    private function find(array &$parent, int $node): int
    {
        $parent[$node] ??= $node;

        while ($parent[$node] !== $node) {
            $parent[$node] = $parent[$parent[$node]];
            $node = $parent[$node];
        }

        return $node;
    }

    /**
     * @param  array<int, int>  $parent
     */
    private function union(array &$parent, int $a, int $b): void
    {
        $rootA = $this->find($parent, $a);
        $rootB = $this->find($parent, $b);

        if ($rootA !== $rootB) {
            $parent[max($rootA, $rootB)] = min($rootA, $rootB);
        }
    }
}
