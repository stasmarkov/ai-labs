<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * A listing the moderation form can be filled from: a stored listing (with its id) or an example from the eval set.
 */
final readonly class LabModerationListing
{
    public function __construct(
        public string $label,
        public ModerationInput $input,
    ) {}

    /**
     * @return array{id: int|null, label: string, title: string, price_eur: float, make: string, model: string, year: int, mileage_km: int, city: string, description: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->input->listingId,
            'label' => $this->label,
            'title' => $this->input->title,
            'price_eur' => $this->input->priceEur,
            'make' => $this->input->make,
            'model' => $this->input->model,
            'year' => $this->input->year,
            'mileage_km' => $this->input->mileageKm,
            'city' => $this->input->city,
            'description' => $this->input->description,
        ];
    }
}
