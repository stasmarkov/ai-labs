<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ListingDocumentText;
use App\DTOs\ListingFacts;
use Database\Seeders\Catalog;
use Random\Engine\Mt19937;
use Random\Randomizer;

final readonly class ListingDocumentGenerator
{
    private const COLOURS = ['black', 'white', 'silver', 'grey', 'blue', 'red', 'green', 'dark blue'];

    private const CONDITIONS = [
        'in good condition',
        'well looked after',
        'with a full service history',
        'with one previous owner',
        'ready to drive away',
    ];

    private const FUELS = [
        'petrol' => 'petrol',
        'diesel' => 'diesel',
        'hybrid' => 'hybrid',
        'electric' => 'electric',
        'lpg' => 'LPG',
    ];

    public function generate(ListingFacts $listing): ListingDocumentText
    {
        $random = new Randomizer(new Mt19937($listing->id));
        $bodyType = Catalog::BODY_TYPE_BY_MODEL[$listing->model];

        $colour = self::COLOURS[$random->getInt(0, count(self::COLOURS) - 1)];
        $condition = self::CONDITIONS[$random->getInt(0, count(self::CONDITIONS) - 1)];
        $gearbox = $listing->fuel === 'electric' || $random->getInt(0, 1) === 1 ? 'an automatic' : 'a manual';
        $features = array_slice($random->shuffleArray(Catalog::FEATURES_BY_BODY_TYPE[$bodyType]), 0, $random->getInt(2, 3));

        $description = sprintf(
            '%d %s %s %s in %s, %s with %s gearbox. It has covered %s km and comes %s. Features include %s.',
            $listing->year,
            $listing->make,
            $listing->model,
            $bodyType,
            $colour,
            self::FUELS[$listing->fuel],
            $gearbox,
            number_format($listing->mileageKm),
            $condition,
            $this->joinFeatures($features),
        );

        return new ListingDocumentText($listing->id, $bodyType, $description);
    }

    /**
     * @param  list<string>  $features
     */
    private function joinFeatures(array $features): string
    {
        $last = array_pop($features);

        return $features === [] ? $last : implode(', ', $features).' and '.$last;
    }
}
