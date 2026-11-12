<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ModerationSetItem
{
    /**
     * @param  list<string>  $reasons
     * @param  string  $kind  safe (generated), hard_negative (hand-written safe item that looks suspicious), risky (generated, one scam kind) or mixed (hand-written, several reasons)
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $make,
        public string $model,
        public int $year,
        public int $priceEur,
        public int $mileageKm,
        public string $city,
        public string $description,
        public string $label,
        public array $reasons,
        public string $kind,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'price_eur' => $this->priceEur,
            'mileage_km' => $this->mileageKm,
            'city' => $this->city,
            'description' => $this->description,
            'label' => $this->label,
            'kind' => $this->kind,
            'reasons' => $this->reasons,
        ];
    }
}
