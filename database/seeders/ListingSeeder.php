<?php

namespace Database\Seeders;

use App\Enums\Fuel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ListingSeeder extends Seeder
{
    private const TWO_YEARS_IN_SECONDS = 63_072_000;

    private const REFERENCE_UNIX_TIME = 1_767_225_600;

    /**
     * Run the database seeds.
     */
    public function run(int $count = 50_000): void
    {
        mt_srand(Catalog::SEED);

        $dealers = DB::table('dealers')->orderBy('id')->get(['id', 'city'])->all();
        $makes = array_keys(Catalog::MODELS_BY_MAKE);
        $fuels = Fuel::cases();

        DB::statement('TRUNCATE TABLE listings RESTART IDENTITY');

        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $dealer = $dealers[mt_rand(0, count($dealers) - 1)];
            $make = $makes[mt_rand(0, count($makes) - 1)];
            $models = Catalog::MODELS_BY_MAKE[$make];

            $rows[] = [
                'dealer_id' => $dealer->id,
                'make' => $make,
                'model' => $models[mt_rand(0, count($models) - 1)],
                'fuel' => $fuels[mt_rand(0, count($fuels) - 1)]->value,
                'year' => mt_rand(2008, 2026),
                'price_cents' => mt_rand(1_000, 80_000) * 100,
                'currency' => 'EUR',
                'mileage_km' => mt_rand(0, 300_000),
                'city' => $dealer->city,
                'listed_at' => gmdate('Y-m-d H:i:s', self::REFERENCE_UNIX_TIME - mt_rand(0, self::TWO_YEARS_IN_SECONDS)).'+00',
                'created_at' => Catalog::REFERENCE_TIMESTAMP,
                'updated_at' => Catalog::REFERENCE_TIMESTAMP,
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('listings')->insert($chunk);
        }
    }
}
