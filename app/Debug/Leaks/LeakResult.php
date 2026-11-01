<?php

declare(strict_types=1);

namespace App\Debug\Leaks;

final readonly class LeakResult
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public int|string|null $requested,
        public int|string|null $observed,
        public array $extra = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requested' => $this->requested,
            'observed' => $this->observed,
            'leaked' => $this->observed !== $this->requested,
            ...$this->extra,
        ];
    }
}
