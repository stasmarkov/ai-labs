<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ListingRepositoryInterface;
use App\Services\ModerationFacts;
use App\Services\ModerationSetBuilder;
use Database\Seeders\Catalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;

#[Signature('moderation:make-set
    {--seed=11 : Seed of the generated items}
    {--refresh-prices : Rewrite the price reference from the listings in the database and stop}
    {--price-reference= : Price reference file (default: bench/moderation/price-reference.json)}
    {--output= : Set file (default: bench/moderation/listings.v1.jsonl)}')]
#[Description('Write the labelled moderation eval set (100 listings) deterministically from a seed and the committed price reference')]
final class ModerationMakeSetCommand extends Command
{
    private const FIRST_YEAR = 2008;

    private const LAST_YEAR = 2026;

    public function handle(ListingRepositoryInterface $listings, ModerationSetBuilder $builder): int
    {
        $referencePath = (string) ($this->option('price-reference') ?: base_path('bench/moderation/price-reference.json'));

        if ($this->option('refresh-prices')) {
            return $this->refreshPrices($listings, $referencePath);
        }

        $seed = filter_var($this->option('seed'), FILTER_VALIDATE_INT);

        if ($seed === false || $seed < 0) {
            $this->error('The seed must be a non-negative integer.');

            return self::INVALID;
        }

        if (! is_file($referencePath)) {
            $this->error("Price reference not found: {$referencePath}. Run with --refresh-prices first.");

            return self::FAILURE;
        }

        try {
            /** @var array{medians: list<array{make: string, model: string, year: int, median_eur: float}>} $reference */
            $reference = json_decode(File::get($referencePath), true, 512, JSON_THROW_ON_ERROR);
            $items = $builder->build($seed, $reference['medians']);
        } catch (JsonException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $lines = array_map(
            fn (array $item): string => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            array_map(fn ($item): array => $item->toArray(), $items),
        );

        $output = (string) ($this->option('output') ?: base_path('bench/moderation/listings.v1.jsonl'));
        File::ensureDirectoryExists(dirname($output));
        File::put($output, implode("\n", $lines)."\n");

        $this->info(sprintf('Wrote %d items to %s (seed %d).', count($items), $output, $seed));

        return self::SUCCESS;
    }

    private function refreshPrices(ListingRepositoryInterface $listings, string $path): int
    {
        $medians = [];

        foreach (Catalog::MODELS_BY_MAKE as $make => $models) {
            foreach ($models as $model) {
                for ($year = self::FIRST_YEAR; $year <= self::LAST_YEAR; $year++) {
                    $cents = $listings->medianPriceCents($make, $model, $year - 1, $year + 1, ModerationFacts::MIN_COMPARABLE);

                    if ($cents !== null) {
                        $medians[] = ['make' => $make, 'model' => $model, 'year' => $year, 'median_eur' => round($cents / 100, 2)];
                    }
                }
            }
        }

        File::ensureDirectoryExists(dirname($path));
        $rows = array_map(fn (array $row): string => '    '.json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $medians);
        File::put($path, "{\"medians\": [\n".implode(",\n", $rows)."\n]}\n");

        $this->info(sprintf('Wrote %d medians to %s.', count($medians), $path));

        return self::SUCCESS;
    }
}
