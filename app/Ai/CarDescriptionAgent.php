<?php

declare(strict_types=1);

namespace App\Ai;

use App\DTOs\ListingDetail;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Promptable;

final class CarDescriptionAgent implements Agent, HasProviderOptions, VersionedPrompt
{
    use Promptable;
    use UsesActiveProvider;

    protected function feature(): string
    {
        return 'describe';
    }

    public function instructions(): string
    {
        return trim((string) file_get_contents(resource_path('prompts/'.$this->promptName().'.md')));
    }

    /**
     * The prompt's name and version, read from its file name: "car-description.v1.md" is "car-description@v1".
     */
    public function promptVersion(): string
    {
        $name = $this->promptName();
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr_replace($name, '@', $dot, 1);
    }

    private function promptName(): string
    {
        return (string) config('ai.prompts.car_description');
    }

    public function promptFor(ListingDetail $detail): string
    {
        $listing = $detail->listing;

        return implode("\n", [
            "Car: {$listing->year} {$listing->make} {$listing->model}",
            "Fuel: {$listing->fuel}",
            "Mileage: {$listing->mileageKm} km",
            'Price: '.number_format($listing->priceCents / 100, 2, '.', '').' '.$listing->currency,
            "City: {$listing->city}",
        ]);
    }
}
