<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ListingFacts;
use App\DTOs\ModerationSetItem;
use App\Enums\Fuel;
use Database\Seeders\Catalog;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Builds the labelled moderation eval set from a seed, a price reference and the committed hand-written items.
 */
final readonly class ModerationSetBuilder
{
    public const SAFE_GENERATED = 35;

    public const PER_KIND = 10;

    /**
     * Ids passed to the description generator start here, far above the seeded listing ids, so the generated
     * texts never repeat the indexed listing documents.
     */
    public const GENERATOR_ID_BASE = 100_000_000;

    public const KINDS = ['advance_payment', 'off_platform_contact', 'price_far_below_market', 'reported_vin'];

    private const ADVANCE_PAYMENT = [
        'To hold the car, send a deposit of %1$s euros by Western Union and I will reserve it for you.',
        'Pay %1$s euros in advance by gift card and the car is yours, no viewing needed.',
        'Please transfer %1$s euros as a booking fee to my agent before you see the car, the money is refunded at delivery.',
        'The car is stored with a courier. Pay the %1$s euro shipping fee by voucher code first and it will be delivered to your door.',
        'Because of many requests I take a non-refundable advance of %1$s euros by money transfer before anything else.',
        'I am abroad, so transfer %1$s euros to the escrow service I name first, then the keys are sent to you.',
    ];

    private const OFF_PLATFORM = [
        'Do not use the messages here, write to me on WhatsApp at +49 170 555%1$s.',
        'Contact me only by email at quick.car.%1$s@mailbox.example, I do not check this site.',
        'Message me on Telegram, my number is +49 151 555%1$s, and we will agree everything there.',
        'Please leave this site and call me on +33 7 55 55 %1$s, the chat here is slow.',
        'Reach me on Signal at +31 6 555%1$s for the papers and more photos.',
        'For a quick answer text me directly on +49 160 555%1$s, not through the platform.',
    ];

    private const BELOW_MARKET = [
        'Selling quickly, the price is firm and the first buyer takes it.',
        'Everything works and the history is clean, the price is so low because I need a fast sale.',
        'Priced to go today, so do not wait.',
        'A bargain you will not find elsewhere, ready to drive away.',
        'Unbeatable price for a car in this condition, serious buyers only.',
        'I want it gone this week, so the price is much lower than similar cars.',
    ];

    private const REPORTED_VIN = [
        'VIN %1$s, papers ready.',
        'Chassis number %1$s, all documents are in order.',
        'The VIN is %1$s if you want to look it up.',
        'See the registration for VIN %1$s, the car is ready for a quick sale.',
        'VIN: %1$s. Inspection welcome.',
    ];

    /**
     * @param  array{hard_negatives: list<array<string, mixed>>, mixed: list<array<string, mixed>>}  $seedData
     * @param  list<string>  $reportedVins
     */
    public function __construct(
        private ListingDocumentGenerator $descriptions,
        private array $seedData,
        private array $reportedVins,
    ) {}

    /**
     * @param  list<array{make: string, model: string, year: int, median_eur: float}>  $reference
     * @return list<ModerationSetItem>
     */
    public function build(int $seed, array $reference): array
    {
        $groups = $reference;
        usort($groups, fn (array $a, array $b): int => [$a['make'], $a['model'], $a['year']] <=> [$b['make'], $b['model'], $b['year']]);

        if ($groups === [] || $this->reportedVins === []) {
            throw new InvalidArgumentException('The price reference and the reported VIN register must not be empty.');
        }

        $random = new Randomizer(new Mt19937($seed));
        $generatorId = self::GENERATOR_ID_BASE + $seed * 1000;
        $vins = $random->shuffleArray($this->reportedVins);
        $drafts = [];

        for ($i = 0; $i < self::SAFE_GENERATED; $i++) {
            $drafts[] = $this->generated($random, $groups, $generatorId++, 'safe', [], null);
        }

        foreach ($this->seedData['hard_negatives'] as $item) {
            $drafts[] = $this->handWritten($item, $groups, 'safe', [], $vins, 0);
        }

        foreach (self::KINDS as $kind) {
            for ($i = 0; $i < self::PER_KIND; $i++) {
                $drafts[] = $this->generated($random, $groups, $generatorId++, 'risky', [$kind], $vins[$i % count($vins)]);
            }
        }

        foreach ($this->seedData['mixed'] as $index => $item) {
            /** @var list<string> $reasons */
            $reasons = $item['reasons'];
            $drafts[] = $this->handWritten($item, $groups, 'risky', $reasons, $vins, $index);
        }

        $items = [];

        foreach ($drafts as $index => $draft) {
            $items[] = new ModerationSetItem(sprintf('mod-%03d', $index + 1), ...$draft);
        }

        return $items;
    }

    /**
     * @param  list<array{make: string, model: string, year: int, median_eur: float}>  $groups
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private function generated(Randomizer $random, array $groups, int $id, string $label, array $reasons, ?string $vin): array
    {
        $group = $groups[$random->getInt(0, count($groups) - 1)];
        $fuel = Fuel::cases()[$random->getInt(0, count(Fuel::cases()) - 1)];
        $mileage = $random->getInt(50, 2200) * 100;
        $city = Catalog::CITIES[$random->getInt(0, count(Catalog::CITIES) - 1)];

        $kind = $reasons[0] ?? null;
        $ratio = $kind === 'price_far_below_market' ? $random->getInt(25, 45) / 100 : $random->getInt(85, 115) / 100;

        $text = $this->descriptions->generate(new ListingFacts($id, $group['year'], $group['make'], $group['model'], $fuel->value, $mileage))->description;
        $line = $this->scamLine($random, $kind, $vin);

        return [
            'title' => sprintf('%d %s %s', $group['year'], $group['make'], $group['model']),
            'make' => $group['make'],
            'model' => $group['model'],
            'year' => $group['year'],
            'priceEur' => $this->price($group['median_eur'], $ratio),
            'mileageKm' => $mileage,
            'city' => $city,
            'description' => $line === null ? $text : $text.' '.$line,
            'label' => $label,
            'kind' => $label === 'safe' ? 'safe' : 'risky',
            'reasons' => $reasons,
        ];
    }

    private function scamLine(Randomizer $random, ?string $kind, ?string $vin): ?string
    {
        $pick = fn (array $templates): string => $templates[$random->getInt(0, count($templates) - 1)];

        return match ($kind) {
            'advance_payment' => sprintf($pick(self::ADVANCE_PAYMENT), $random->getInt(2, 20) * 50),
            'off_platform_contact' => sprintf($pick(self::OFF_PLATFORM), $random->getInt(1000, 9999)),
            'price_far_below_market' => $pick(self::BELOW_MARKET),
            'reported_vin' => sprintf($pick(self::REPORTED_VIN), $vin),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<array{make: string, model: string, year: int, median_eur: float}>  $groups
     * @param  list<string>  $reasons
     * @param  list<string>  $vins
     * @return array<string, mixed>
     */
    private function handWritten(array $item, array $groups, string $label, array $reasons, array $vins, int $index): array
    {
        $group = $this->group($groups, (string) $item['make'], (string) $item['model'], (int) $item['year']);

        return [
            'title' => sprintf('%d %s %s', $group['year'], $group['make'], $group['model']),
            'make' => $group['make'],
            'model' => $group['model'],
            'year' => $group['year'],
            'priceEur' => $this->price($group['median_eur'], (float) $item['ratio']),
            'mileageKm' => (int) $item['mileage_km'],
            'city' => (string) $item['city'],
            'description' => str_replace('{vin}', $vins[$index % count($vins)], (string) $item['description']),
            'label' => $label,
            'kind' => $label === 'safe' ? 'hard_negative' : 'mixed',
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  list<array{make: string, model: string, year: int, median_eur: float}>  $groups
     * @return array{make: string, model: string, year: int, median_eur: float}
     */
    private function group(array $groups, string $make, string $model, int $year): array
    {
        foreach ($groups as $group) {
            if ($group['make'] === $make && $group['model'] === $model && $group['year'] === $year) {
                return $group;
            }
        }

        throw new InvalidArgumentException("No price reference for {$year} {$make} {$model}.");
    }

    private function price(float $median, float $ratio): int
    {
        return max(50, (int) (round($median * $ratio / 50) * 50));
    }
}
