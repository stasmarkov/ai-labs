<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ListingRepositoryInterface;
use App\DTOs\ListingDetail;

final readonly class ListingDetailService
{
    public function __construct(private ListingRepositoryInterface $listings) {}

    public function find(int $id): ListingDetail
    {
        return $this->listings->findDetail($id);
    }
}
