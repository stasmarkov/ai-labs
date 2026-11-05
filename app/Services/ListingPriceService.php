<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ListingRepositoryInterface;
use App\DTOs\ListingRepriceResult;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Changes a listing price and tells the live-update hub about it.
 *
 * The publish is synchronous: it runs in the caller's process after the repository's single autocommit
 * UPDATE has committed, waits up to the hub timeout and never throws. That is fine for the CLI, the only
 * caller today. A web request that changes prices should queue the publish (a job that calls
 * ListingPricePublisher with the returned DTO) so a slow hub cannot hold the request; this service must
 * not be called inside an open transaction, or the publish would run before the commit.
 */
final readonly class ListingPriceService
{
    public function __construct(
        private ListingRepositoryInterface $listings,
        private ListingPricePublisher $publisher,
    ) {}

    /**
     * @throws ModelNotFoundException
     */
    public function reprice(int $id, int $priceCents, bool $private = false): ListingRepriceResult
    {
        $update = $this->listings->updatePrice($id, $priceCents);

        if (! config('mercure.enabled')) {
            return new ListingRepriceResult($update, null, null);
        }

        $changed = $update->priceCents !== $update->previousPriceCents;

        return new ListingRepriceResult(
            $update,
            $changed ? $this->publisher->publishPublic($update) : null,
            $private ? $this->publisher->publishPrivate($update) : null,
        );
    }
}
