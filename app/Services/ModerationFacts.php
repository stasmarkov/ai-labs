<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ListingRepositoryInterface;
use App\DTOs\ModerationFactsResult;
use App\DTOs\ModerationInput;
use Illuminate\Support\Facades\Cache;

final readonly class ModerationFacts
{
    /** @var list<string> uppercase VINs */
    private array $reportedVins;

    public const MIN_COMPARABLE = 5;

    public const CACHE_SECONDS = 3600;

    private const VIN_PATTERN = '/\b[A-HJ-NPR-Z0-9]{17}\b/i';

    /**
     * @param  list<string>  $reportedVins  the register of reported VINs, in any case
     */
    public function __construct(
        private ListingRepositoryInterface $listings,
        private PromptInjectionGuard $guard,
        array $reportedVins,
    ) {
        $this->reportedVins = array_map('strtoupper', $reportedVins);
    }

    public function compute(ModerationInput $input): ModerationFactsResult
    {
        $median = $this->medianEur($input);
        $vins = $this->vinsIn($input->title."\n".$input->description);

        return new ModerationFactsResult(
            marketMedianEur: $median,
            priceRatio: $median !== null && $median > 0 ? round($input->priceEur / $median, 4) : null,
            vinsInText: $vins,
            vinReported: array_intersect($vins, $this->reportedVins) !== [],
            injectionPattern: $this->injectionIn($input),
        );
    }

    /**
     * The guard runs on every free-text field that reaches the model, each on its own so a pattern cannot be split
     * across two fields.
     */
    private function injectionIn(ModerationInput $input): ?string
    {
        foreach ([$input->title, $input->make, $input->model, $input->city, $input->description] as $text) {
            $match = $this->guard->match($text);

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    private function medianEur(ModerationInput $input): ?float
    {
        $key = sprintf('moderation:median:%s:%s:%d', mb_strtolower($input->make), mb_strtolower($input->model), $input->year);

        /** @var array{median: float|null} $cached */
        $cached = Cache::remember($key, self::CACHE_SECONDS, fn (): array => [
            'median' => $this->queryMedian($input),
        ]);

        return $cached['median'];
    }

    private function queryMedian(ModerationInput $input): ?float
    {
        $cents = $this->listings->medianPriceCents(
            $input->make,
            $input->model,
            $input->year - 1,
            $input->year + 1,
            self::MIN_COMPARABLE,
            $input->listingId,
        );

        return $cents === null ? null : round($cents / 100, 2);
    }

    /**
     * @return list<string>
     */
    private function vinsIn(string $text): array
    {
        preg_match_all(self::VIN_PATTERN, $text, $matches);

        return array_values(array_unique(array_map('strtoupper', $matches[0])));
    }
}
