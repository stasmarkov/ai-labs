<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ListingDetail;
use App\DTOs\ListingFilter;
use App\DTOs\ListingPage;
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
}
