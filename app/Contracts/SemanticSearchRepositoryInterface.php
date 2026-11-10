<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\SemanticMatch;

interface SemanticSearchRepositoryInterface
{
    /**
     * Embedded listings nearest to the vector by cosine distance, ties by listing id ascending. A model label restricts the search to vectors embedded by that model.
     *
     * @param  list<float>  $vector
     * @return list<SemanticMatch>
     */
    public function nearest(array $vector, int $limit, int $efSearch, string $model): array;
}
