<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AdminTools\AdminArguments;
use App\Contracts\AdminListingRepositoryInterface;
use App\Contracts\McpToolCallRepositoryInterface;
use App\Contracts\PendingChangeRepositoryInterface;
use App\DTOs\AdminListingRow;
use App\DTOs\PendingChangeRecord;

/**
 * What the changes page shows: the pending changes, the recently decided ones, and one change in full. The list rows
 * carry only what the list renders, and a pending change comes with the listing's state now and the nonce that its
 * Confirm and Reject buttons send back.
 */
final readonly class PendingChangePageService
{
    private const PENDING_LIMIT = 50;

    private const RECENT_LIMIT = 20;

    /** The MCP tool a client used to propose a change, by the write tool the change runs. */
    private const PROPOSING_TOOLS = ['updateTitle' => 'propose_title_change', 'addTag' => 'propose_tag'];

    private const SUMMARY_KEYS = ['old_title', 'new_title', 'tag', 'already_tagged'];

    public function __construct(
        private PendingChangeRepositoryInterface $changes,
        private PendingChangeStatusService $statuses,
        private McpToolCallRepositoryInterface $toolCalls,
        private AdminListingRepositoryInterface $listings,
        private PendingChangeNonces $nonces,
    ) {}

    /**
     * @return array{pending: list<array<string, mixed>>, recent: list<array<string, mixed>>}
     */
    public function lists(): array
    {
        $this->changes->expireOverdue();
        $this->statuses->failStuck();

        $pending = $this->changes->pending(self::PENDING_LIMIT);
        $listings = $this->listings->findMany(array_values(array_unique(array_map($this->listingId(...), $pending))));

        return [
            'pending' => array_map(fn (PendingChangeRecord $change): array => $this->row($change, $listings), $pending),
            'recent' => array_map(fn (PendingChangeRecord $change): array => $this->row($change, []), $this->changes->recent(self::RECENT_LIMIT)),
        ];
    }

    /**
     * @return array<string, mixed>|null null when no change has this id
     */
    public function find(string $id): ?array
    {
        $this->statuses->failStuck($id);
        $change = $this->changes->find($id);

        return $change === null ? null : $this->present($change);
    }

    /**
     * One change in full. While it is pending it also carries the listing's state now, next to the preview taken when
     * it was proposed, and the nonce for its decision.
     *
     * @return array<string, mixed>
     */
    public function present(PendingChangeRecord $change): array
    {
        $status = $this->statuses->effectiveStatus($change);
        $call = $this->toolCalls->findByPendingChange($change->id);

        $detail = [
            'id' => $change->id,
            'tool' => $change->tool,
            'status' => $status,
            'arguments' => $change->arguments,
            'preview' => $change->preview,
            'result' => $change->result,
            'error' => $change->error,
            'failure' => $status === 'failed' ? $this->failureMessage($change) : null,
            'proposed_by' => $call === null ? null : [
                'time' => $call->createdAt?->toIso8601String(),
                'tool' => $call->tool,
                'arguments' => $call->arguments,
            ],
            'expires_at' => $change->expiresAt->toIso8601String(),
            'decided_at' => $change->decidedAt?->toIso8601String(),
            'created_at' => $change->createdAt->toIso8601String(),
        ];

        if ($status === 'pending') {
            $id = $this->listingId($change);
            $detail['current'] = $this->current($this->listings->findMany([$id])[$id] ?? null);
            $detail['nonce'] = $this->nonces->issue($change->id);
        }

        return $detail;
    }

    /**
     * @param  array<int, AdminListingRow>  $listings  the listings of the pending changes, by id
     * @return array<string, mixed>
     */
    private function row(PendingChangeRecord $change, array $listings): array
    {
        $status = $this->statuses->effectiveStatus($change);
        $row = [
            'id' => $change->id,
            'tool' => self::PROPOSING_TOOLS[$change->tool] ?? $change->tool,
            'status' => $status,
            'listing' => [
                'id' => $this->listingId($change),
                'make' => $change->preview['make'] ?? null,
                'model' => $change->preview['model'] ?? null,
                'year' => $change->preview['year'] ?? null,
            ],
            'summary' => array_intersect_key($change->preview, array_flip(self::SUMMARY_KEYS)),
            'expires_at' => $change->expiresAt->toIso8601String(),
        ];

        if ($status === 'pending') {
            $row['current'] = $this->current($listings[$this->listingId($change)] ?? null);
            $row['nonce'] = $this->nonces->issue($change->id);
        }

        return $row;
    }

    /**
     * @return array{title: string|null, tags: list<string>, tags_total: int}|null null when the listing is gone
     */
    private function current(?AdminListingRow $listing): ?array
    {
        return $listing === null ? null : ['title' => $listing->title, ...AdminArguments::tagsShown($listing->tags)];
    }

    private function listingId(PendingChangeRecord $change): int
    {
        return (int) ($change->arguments['id'] ?? 0);
    }

    private function failureMessage(PendingChangeRecord $change): string
    {
        $details = $change->result['details'] ?? null;
        $messages = is_array($details) ? array_values(array_filter($details, is_string(...))) : [];

        return $messages === [] ? PendingChangeStatusService::FAILURE : implode(' ', $messages);
    }
}
