<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * What one request to the admin assistant produced. Steps and run ids cover that request only.
 */
final readonly class AdminTurn
{
    /**
     * @param  list<AdminStepReport>  $steps
     * @param  list<string>  $runIds
     */
    public function __construct(
        public string $answer,
        public array $steps = [],
        public ?AdminPendingAction $pending = null,
        public bool $limitReached = false,
        public array $runIds = [],
        public bool $refused = false,
        public ?string $pattern = null,
        public ?string $code = null,
    ) {}

    /**
     * The same turn with one more step in front, such as the drop of an expired action.
     */
    public function withLeadingStep(AdminStepReport $step): self
    {
        return new self($this->answer, [$step, ...$this->steps], $this->pending, $this->limitReached, $this->runIds, $this->refused, $this->pattern, $this->code);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'answer' => $this->answer,
            'steps' => array_map(fn (AdminStepReport $step): array => $step->toArray(), $this->steps),
            'pending_action' => $this->pending?->toArray(),
            'limit_reached' => $this->limitReached,
            'run_ids' => $this->runIds,
            ...($this->refused ? ['refused' => true, 'pattern' => $this->pattern] : []),
            ...($this->code !== null ? ['code' => $this->code] : []),
        ];
    }
}
