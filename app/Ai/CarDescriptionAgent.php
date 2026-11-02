<?php

declare(strict_types=1);

namespace App\Ai;

use App\DTOs\ListingDetail;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

final class CarDescriptionAgent implements Agent, HasProviderOptions, VersionedPrompt
{
    use Promptable;

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

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        // The SDK passes the driver ("ollama"), not the config entry name, so match it against the active entry.
        $driver = $provider instanceof Lab ? $provider->value : $provider;
        $entry = $this->activeProviderEntry();

        return ($entry['driver'] ?? null) === $driver ? (array) ($entry['request_options'] ?? []) : [];
    }

    public function maxTokens(): ?int
    {
        $maxTokens = $this->activeProviderEntry()['max_tokens'] ?? null;

        return $maxTokens === null ? null : (int) $maxTokens;
    }

    /**
     * The SDK calls this instead of its own fallback when it exists, so an entry without "timeout" keeps the SDK's 60 s
     * (Promptable::getTimeout).
     */
    public function timeout(): int
    {
        return (int) ($this->activeProviderEntry()['timeout'] ?? 60);
    }

    /**
     * @return array<string, mixed>
     */
    private function activeProviderEntry(): array
    {
        return (array) config('ai.providers.'.config('ai.default'), []);
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
