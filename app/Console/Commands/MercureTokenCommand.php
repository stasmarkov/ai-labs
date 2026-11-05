<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ListingPricePublisher;
use App\Support\MercureTokens;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('mercure:token {listing : Listing id; the token names that listing private price topic}')]
#[Description('Print a subscriber JWT that names the private price topic of one listing (only private-topic tokens are issued)')]
final class MercureTokenCommand extends Command
{
    public function handle(MercureTokens $tokens, ListingPricePublisher $publisher): int
    {
        $listing = $this->argument('listing');

        if (! ctype_digit((string) $listing) || (int) $listing < 1) {
            $this->error('Give a positive listing id.');

            return self::INVALID;
        }

        try {
            $token = $tokens->subscriber([$publisher->privateTopic((int) $listing)]);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($token);

        return self::SUCCESS;
    }
}
