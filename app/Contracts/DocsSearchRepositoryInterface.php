<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\DocsHit;

interface DocsSearchRepositoryInterface
{
    /**
     * Chunks embedded with this model, nearest to the vector by cosine distance first, ties by chunk id.
     *
     * @param  list<float>  $vector
     * @return list<DocsHit> value is the cosine similarity
     */
    public function nearest(array $vector, string $model, int $limit, int $efSearch): array;

    /**
     * Chunks matching the text by full text (websearch_to_tsquery, english), best ts_rank_cd first, ties by chunk id.
     *
     * @return list<DocsHit> value is the ts_rank_cd rank
     */
    public function textMatches(string $query, int $limit): array;
}
