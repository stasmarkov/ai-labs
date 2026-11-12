<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ModerationFactsResult
{
    /**
     * @param  list<string>  $vinsInText
     */
    public function __construct(
        public ?float $marketMedianEur,
        public ?float $priceRatio,
        public array $vinsInText,
        public bool $vinReported,
        public ?string $injectionPattern,
    ) {}

    /**
     * @return array{market_median_eur: float|null, price_ratio: float|null, vins_in_text: list<string>, vin_reported: bool, injection_pattern: string|null}
     */
    public function toArray(): array
    {
        return [
            'market_median_eur' => $this->marketMedianEur,
            'price_ratio' => $this->priceRatio,
            'vins_in_text' => $this->vinsInText,
            'vin_reported' => $this->vinReported,
            'injection_pattern' => $this->injectionPattern,
        ];
    }
}
