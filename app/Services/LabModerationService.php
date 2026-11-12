<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ListingRepositoryInterface;
use App\DTOs\LabModerationListing;
use App\DTOs\ListingFacts;
use App\DTOs\ListingSummary;
use App\DTOs\ModerationInput;
use App\Rules\ModerationRules;

final readonly class LabModerationService
{
    private const PICKER_SIZE = 10;

    /** What each reason means, shown next to the chips on the lab page. */
    private const DEFINITIONS = [
        'advance_payment' => 'The seller asks for money before the buyer can see the car.',
        'off_platform_contact' => 'The seller pushes the buyer to leave the platform for email, a messenger or a phone number.',
        'price_far_below_market' => 'The price is half of the market median or less.',
        'reported_vin' => 'A VIN in the text is in the register of reported vehicles.',
        'pressure_tactics' => 'False urgency, such as many other buyers waiting.',
        'inconsistent_details' => 'Details that contradict each other, such as a mileage that does not fit the description.',
        'other' => 'Anything else that is suspicious, including text that tries to steer the moderator.',
    ];

    /**
     * @param  list<mixed>  $examples  the committed examples (resources/moderation/lab-examples.php), empty when missing
     */
    public function __construct(
        private ListingRepositoryInterface $listings,
        private ListingDocumentGenerator $descriptions,
        private array $examples = [],
    ) {}

    /**
     * The 10 lowest listing ids, each with the description the document generator writes for it.
     *
     * @return list<LabModerationListing>
     */
    public function listingOptions(): array
    {
        return array_map(fn (ListingSummary $listing): LabModerationListing => new LabModerationListing(
            "{$listing->id} · {$listing->year} {$listing->make} {$listing->model}, {$listing->city}",
            new ModerationInput(
                title: "{$listing->year} {$listing->make} {$listing->model}",
                priceEur: $listing->priceCents / 100,
                make: $listing->make,
                model: $listing->model,
                year: $listing->year,
                mileageKm: $listing->mileageKm,
                city: $listing->city,
                description: $this->descriptions->generate(new ListingFacts($listing->id, $listing->year, $listing->make, $listing->model, $listing->fuel, $listing->mileageKm))->description,
                listingId: $listing->id,
            ),
        ), $this->listings->lowestIds(self::PICKER_SIZE));
    }

    /**
     * The committed example for each of the four scam kinds, labelled with the kind. Rows that are incomplete are left
     * out, so a broken file shows fewer examples instead of an error.
     *
     * @return list<LabModerationListing>
     */
    public function examples(): array
    {
        $examples = [];

        foreach ($this->examples as $row) {
            if (! is_array($row) || ! $this->isComplete($row)) {
                continue;
            }

            $examples[] = new LabModerationListing((string) $row['kind'], new ModerationInput(
                (string) $row['title'],
                (float) $row['price_eur'],
                (string) $row['make'],
                (string) $row['model'],
                (int) $row['year'],
                (int) $row['mileage_km'],
                (string) $row['city'],
                (string) $row['description'],
            ));
        }

        return $examples;
    }

    /**
     * @return list<array{key: string, definition: string}>
     */
    public function reasons(): array
    {
        return array_map(fn (string $key): array => ['key' => $key, 'definition' => self::DEFINITIONS[$key]], ModerationRules::REASONS);
    }

    /**
     * @param  array<mixed>  $row
     */
    private function isComplete(array $row): bool
    {
        foreach (['kind', 'title', 'make', 'model', 'city', 'description'] as $key) {
            if (! is_string($row[$key] ?? null)) {
                return false;
            }
        }

        foreach (['price_eur', 'year', 'mileage_km'] as $key) {
            if (! is_int($row[$key] ?? null) && ! is_float($row[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
