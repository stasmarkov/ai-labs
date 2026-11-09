<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class NlSearchResult
{
    /**
     * @param  array<string, mixed>  $extracted  the model's six fields, as returned
     * @param  list<string>  $unsupported
     * @param  array<string, string|int>  $filters  the /listings parameters, null fields stripped
     */
    public function __construct(
        public string $query,
        public string $model,
        public array $extracted,
        public array $unsupported,
        public array $filters,
        public ListingPage $page,
        public string $invocationId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'model' => $this->model,
            'extracted' => $this->extracted,
            'unsupported' => $this->unsupported,
            'filters' => $this->filters,
            'listings' => $this->page->itemsToArray(),
            'meta' => ['total' => $this->page->total, 'per_page' => $this->page->perPage, 'page' => $this->page->page],
            'invocation_id' => $this->invocationId,
        ];
    }
}
