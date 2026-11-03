<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DocsCachePair;
use App\Exceptions\DocsSearchUnavailableException;

/**
 * Cosine similarity of each pair, embedding every distinct text once.
 */
final readonly class DocsCachePairScorer
{
    public function __construct(private DocsSearchService $search) {}

    /**
     * @param  list<DocsCachePair>  $pairs
     * @return array<string, float> similarity by pair id
     *
     * @throws DocsSearchUnavailableException when a text cannot be embedded.
     */
    public function score(array $pairs): array
    {
        $vectors = [];

        foreach ($pairs as $pair) {
            foreach ([$pair->a, $pair->b] as $text) {
                $vectors[$text] ??= $this->search->embedQuery($text);
            }
        }

        $scores = [];

        foreach ($pairs as $pair) {
            $scores[$pair->id] = DocsCacheCalibrator::cosine($vectors[$pair->a], $vectors[$pair->b]);
        }

        return $scores;
    }
}
