<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AdminTools\AdminArguments;
use App\Ai\AdminTools\AdminToolFailed;
use App\Ai\AdminTools\AdminWriteTool;
use App\Contracts\AdminListingRepositoryInterface;
use App\Contracts\PendingChangeRepositoryInterface;
use App\DTOs\ChangeProposal;
use App\DTOs\NewPendingChange;
use Carbon\CarbonImmutable;

/**
 * Stores a change an outside client proposed. It validates the arguments with the write tool's own rules and writes
 * the pending change only; the listing is never touched here, a developer confirms the change in the browser.
 */
final readonly class ChangeProposalService
{
    public function __construct(private PendingChangeRepositoryInterface $changes,
        private AdminListingRepositoryInterface $listings,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @throws AdminToolFailed when the arguments are invalid
     */
    public function propose(AdminWriteTool $tool, array $arguments): ChangeProposal
    {
        if (($errors = $tool->validate($arguments)) !== []) {
            throw new AdminToolFailed(implode(' ', $errors));
        }

        $stored = $this->changes->create(new NewPendingChange(
            tool: $tool->name(),
            arguments: $arguments,
            preview: $this->enrich($tool->preview($arguments), $arguments),
            expiresAt: CarbonImmutable::now()->addMinutes((int) config('ai.mcp.change_ttl_minutes')),
        ));

        return new ChangeProposal($stored->id, $stored->preview, route('ai.changes.show', ['id' => $stored->id]), $stored->expiresAt);
    }

    /**
     * Adds what the confirmation page shows about the listing: its id, make, model and year, and for a tag whether the
     * listing already has it. The tags are cut to the first few with their count, like every other tool result, so the
     * reply to the client stays small. The write tool's own preview stays as the admin assistant uses it.
     *
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function enrich(array $preview, array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        $listing = $this->listings->find($id);
        $enriched = array_merge($preview, [
            'listing_id' => $id,
            'make' => $listing?->make,
            'model' => $listing?->model,
            'year' => $listing?->year,
        ]);

        if (isset($preview['tag'])) {
            $enriched['already_tagged'] = in_array($preview['tag'], $listing->tags ?? [], true);
        }

        if (is_array($preview['tags'] ?? null)) {
            $enriched = array_merge($enriched, AdminArguments::tagsShown(array_values(array_filter($preview['tags'], is_string(...)))));
        }

        return $enriched;
    }
}
