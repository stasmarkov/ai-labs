<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ListingPriceUpdate;
use App\Support\MercurePublisher;
use App\Support\PriceFormatter;

final readonly class ListingPricePublisher
{
    public function __construct(private MercurePublisher $publisher) {}

    public function publishPublic(ListingPriceUpdate $update): bool
    {
        return $this->publish($update, $this->publicTopic($update->id), false);
    }

    public function publishPrivate(ListingPriceUpdate $update): bool
    {
        return $this->publish($update, $this->privateTopic($update->id), true);
    }

    public function publicTopic(int $listingId): string
    {
        return rtrim((string) config('mercure.topic_base'), '/')."/listings/{$listingId}/price";
    }

    public function privateTopic(int $listingId): string
    {
        return $this->publicTopic($listingId).'/private';
    }

    private function publish(ListingPriceUpdate $update, string $topic, bool $private): bool
    {
        return $this->publisher->publish($topic, [
            'id' => $update->id,
            'price_cents' => $update->priceCents,
            'currency' => $update->currency,
            'formatted' => PriceFormatter::format($update->priceCents, $update->currency),
            'published_at_ms' => (int) floor(microtime(true) * 1000),
        ], $private);
    }
}
