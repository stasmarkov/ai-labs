<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ListingPriceService;
use App\Support\PriceFormatter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

#[Signature('listings:reprice
    {id : Listing id}
    {price? : New price in cents}
    {--private : Also publish to the private topic}
    {--restore= : Set the price back to this many cents instead of the price argument}')]
#[Description('Change a listing price and publish the live update')]
final class ListingsRepriceCommand extends Command
{
    public function handle(ListingPriceService $prices): int
    {
        $id = $this->positive($this->argument('id'));
        $price = $this->positive($this->option('restore') ?? $this->argument('price'));

        if ($id === null || $price === null) {
            $this->error('The listing id and the price in cents must be positive whole numbers.');

            return self::INVALID;
        }

        try {
            $result = $prices->reprice($id, $price, (bool) $this->option('private'));
        } catch (ModelNotFoundException) {
            $this->error("Listing {$id} not found.");

            return self::FAILURE;
        }

        $update = $result->update;
        $old = PriceFormatter::format($update->previousPriceCents, $update->currency);
        $new = PriceFormatter::format($update->priceCents, $update->currency);
        $this->info("Listing {$id}: {$old} -> {$new} ({$update->previousPriceCents} -> {$update->priceCents} cents)");

        if ($result->publicPublished === false) {
            $this->warn('The public update was not published; the price is saved.');
        }

        if ($result->privatePublished === false) {
            $this->warn('The private update was not published; the price is saved.');
        }

        return self::SUCCESS;
    }

    private function positive(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 && (string) (int) $value === (string) $value ? (int) $value : null;
    }
}
