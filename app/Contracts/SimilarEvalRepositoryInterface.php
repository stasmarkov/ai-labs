<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\EncoderRunTotals;
use App\DTOs\SimilarEvalPhoto;

interface SimilarEvalRepositoryInterface
{
    /**
     * The photos embedded with the model label, by id, with the make and model of their listing.
     *
     * @return list<SimilarEvalPhoto>
     */
    public function embeddedPhotos(string $label): array;

    /**
     * The distinct listing titles ("year make model") of the photos embedded with the model label.
     *
     * @return list<string>
     */
    public function embeddedTitles(string $label): array;

    /**
     * The photos and the encoder latency summed over the completed batches of the latest photos:embed run of the
     * provider and model, told apart by the agent and the invocation id prefix.
     */
    public function encoderRunTotals(string $agent, string $invocationPrefix, string $provider, string $model): EncoderRunTotals;
}
