<?php

declare(strict_types=1);

use App\DTOs\ListingFilter;
use App\Enums\Fuel;
use App\Http\Requests\ListListingsRequest;
use App\Rules\ListingFilterRules;

it('MNC-1 shares one set of filter rules with the listings request', function (): void {
    $rules = ListingFilterRules::rules();

    expect(array_keys($rules))->toBe(['make', 'model', 'city', 'fuel', 'min_year', 'max_price'])
        ->and((new ListListingsRequest)->rules())->toEqual($rules + [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
});

it('MNC-1 maps validated input to one filter, euros to cents, for /listings and the natural-language search', function (): void {
    $filter = ListingFilter::fromValidated(
        ['make' => 'BMW', 'fuel' => 'diesel', 'min_year' => '2019', 'max_price' => '20000', 'city' => 'Munich'],
        perPage: 5,
        page: 2,
    );

    expect($filter->make)->toBe('BMW')
        ->and($filter->model)->toBeNull()
        ->and($filter->fuel)->toBe(Fuel::Diesel)
        ->and($filter->city)->toBe('Munich')
        ->and($filter->minYear)->toBe(2019)
        ->and($filter->maxPriceCents)->toBe(2_000_000)
        ->and($filter->perPage)->toBe(5)
        ->and($filter->page)->toBe(2);
});
