<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ListingDetail;
use App\DTOs\ListingFilter;
use App\DTOs\ListingPage;
use App\DTOs\ListingPriceUpdate;
use App\DTOs\ListingSummary;
use Illuminate\Database\Eloquent\ModelNotFoundException;

interface ListingRepositoryInterface
{
    /**
     * Newest-first page of listings matching every filter set.
     */
    public function search(ListingFilter $filter): ListingPage;

    /**
     * @throws ModelNotFoundException
     */
    public function findDetail(int $id): ListingDetail;

    /**
     * The listings with the lowest ids, in id order.
     *
     * @return list<ListingSummary>
     */
    public function lowestIds(int $limit): array;

    /**
     * Saves the price and returns the one DTO that describes the change (new price, currency, previous price).
     *
     * @throws ModelNotFoundException
     */
    public function updatePrice(int $id, int $priceCents): ListingPriceUpdate;

    /**
     * The median price in cents of listings with the same make and model (case-insensitive) and a year in range,
     * or null with fewer than $minCount of them. A listing id is left out of the sample.
     */
    public function medianPriceCents(string $make, string $model, int $minYear, int $maxYear, int $minCount, ?int $excludeId = null): ?float;
}
