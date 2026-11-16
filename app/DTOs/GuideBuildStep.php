<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class GuideBuildStep
{
    public function __construct(
        public string $step,
        public string $detail,
        public ?string $tab = null,
        public ?string $doc = null,
    ) {}

    /**
     * @return array{step: string, detail: string, tab: string|null, doc: string|null}
     */
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'detail' => $this->detail,
            'tab' => $this->tab,
            'doc' => $this->doc,
        ];
    }
}
