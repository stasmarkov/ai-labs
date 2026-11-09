<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class NlEvalQuery
{
    /**
     * @param  array{make: ?string, model: ?string, city: ?string, fuel: ?string, min_year: ?int, max_price_eur: ?int}  $expected
     * @param  list<string>  $unsupported  the parts of the query the filters cannot express
     */
    public function __construct(
        public string $id,
        public string $query,
        public array $expected,
        public array $unsupported,
    ) {}
}
