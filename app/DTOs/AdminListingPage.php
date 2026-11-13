<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class AdminListingPage
{
    /**
     * @param  list<AdminListingRow>  $rows
     */
    public function __construct(public int $total, public array $rows) {}
}
