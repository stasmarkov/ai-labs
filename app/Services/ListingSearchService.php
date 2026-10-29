<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ListingRepositoryInterface;
use App\DTOs\ListingFilter;
use App\DTOs\ListingPage;

final readonly class ListingSearchService
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function __construct(private ListingRepositoryInterface $listings) {}

    public function search(ListingFilter $filter): ListingPage
    {
        $perPage = min(max($filter->perPage, 1), self::MAX_PER_PAGE);

        return $this->listings->search($filter->withPerPage($perPage));
    }
}
