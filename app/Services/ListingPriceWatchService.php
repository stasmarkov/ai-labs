<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ListingPriceWatch;
use App\Support\MercureTokens;
use RuntimeException;

final readonly class ListingPriceWatchService
{
    public function __construct(
        private ListingPricePublisher $topics,
        private MercureTokens $tokens,
    ) {}

    /**
     * The private variant exists only behind the debug gate and only when the subscriber key is set;
     * otherwise the plain public watch is returned.
     */
    public function watch(int $listingId, bool $wantsPrivate): ListingPriceWatch
    {
        $topic = $this->topics->publicTopic($listingId);

        if (! $wantsPrivate || config('app.debug_routes') !== true) {
            return new ListingPriceWatch($topic);
        }

        $privateTopic = $this->topics->privateTopic($listingId);

        $now = time();

        try {
            $token = $this->tokens->subscriber([$privateTopic], $now);
        } catch (RuntimeException) {
            return new ListingPriceWatch($topic);
        }

        return new ListingPriceWatch($topic, $privateTopic, $token, $now + (int) config('mercure.subscriber_token_ttl'));
    }
}
