<?php

declare(strict_types=1);

namespace App\Ai\AdminTools;

use App\Contracts\AdminListingRepositoryInterface;
use App\DTOs\AdminListingRow;
use App\DTOs\ListingFilter;
use App\Enums\Fuel;
use App\Rules\ListingFilterRules;

final readonly class SearchListingsTool implements AdminTool
{
    public const MAX_LIMIT = 10;

    public const DEFAULT_LIMIT = 5;

    public function __construct(private AdminListingRepositoryInterface $listings) {}

    public function name(): string
    {
        return 'searchListings';
    }

    public function description(): string
    {
        return 'Search the used-car listings. Every argument is optional. Returns the total number of matches and up to '
            .self::MAX_LIMIT.' rows, newest first, each with id, make, model, year, price, city, title and tags.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'make' => ['type' => 'string', 'description' => 'Car make, for example "Volkswagen".'],
                'model' => ['type' => 'string', 'description' => 'Car model, for example "Golf".'],
                'city' => ['type' => 'string', 'description' => 'City of the listing.'],
                'fuel' => ['type' => 'string', 'enum' => array_column(Fuel::cases(), 'value'), 'description' => 'Fuel type.'],
                'min_year' => ['type' => 'integer', 'description' => 'Earliest model year.'],
                'max_price' => ['type' => 'integer', 'description' => 'Highest price in whole euros.'],
                'tag' => ['type' => 'string', 'description' => 'Only listings with this tag, for example "family-car".'],
                'limit' => ['type' => 'integer', 'description' => 'Rows to return, 1 to '.self::MAX_LIMIT.', default '.self::DEFAULT_LIMIT.'.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        $arguments = AdminArguments::normalise($arguments);
        $errors = AdminArguments::unknown($arguments, [...array_keys(ListingFilterRules::rules()), 'tag', 'limit']);

        $errors = [...$errors, ...AdminArguments::ruleErrors($arguments, ListingFilterRules::rules() + [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ])];

        foreach (['min_year', 'max_price', 'limit'] as $field) {
            $value = $arguments[$field] ?? null;

            if ($value !== null && ! is_int($value) && is_numeric($value)) {
                $errors[] = "The {$field} field must be an integer.";
            }
        }

        return [...$errors, ...AdminArguments::tagErrors($arguments, false)];
    }

    public function run(array $arguments): array
    {
        $arguments = AdminArguments::normalise($arguments);
        $limit = (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT);
        $filter = ListingFilter::fromValidated(
            array_intersect_key($arguments, ListingFilterRules::rules()),
            $limit,
        );
        $page = $this->listings->search($filter, AdminArguments::tag($arguments['tag'] ?? null), $limit);

        return AdminResultCap::cap([
            'total' => $page->total,
            'rows' => array_map(fn (AdminListingRow $row): array => [
                'id' => $row->id,
                'make' => $row->make,
                'model' => $row->model,
                'year' => $row->year,
                'price_eur' => intdiv($row->priceCents, 100),
                'city' => $row->city,
                'title' => $row->title,
                ...AdminArguments::tagsShown($row->tags),
            ], $page->rows),
        ]);
    }
}
