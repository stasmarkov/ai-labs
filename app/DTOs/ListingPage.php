<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ListingPage
{
    public int $lastPage;

    /**
     * @param  list<ListingSummary>  $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
        $this->lastPage = max(1, (int) ceil($total / max(1, $perPage)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function itemsToArray(): array
    {
        return array_map(fn (ListingSummary $item): array => $item->toArray(), $this->items);
    }

    /**
     * @return array{current_page: int, per_page: int, total: int, last_page: int}
     */
    public function meta(): array
    {
        return [
            'current_page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'last_page' => $this->lastPage,
        ];
    }
}
