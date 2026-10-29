<?php

namespace Database\Seeders;

use Faker\Factory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DealerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(int $count = 500): void
    {
        $faker = Factory::create('en_US');
        $faker->seed(Catalog::SEED);
        mt_srand(Catalog::SEED);

        DB::statement('TRUNCATE TABLE dealers RESTART IDENTITY CASCADE');

        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'name' => mb_substr($faker->company(), 0, 120),
                'city' => Catalog::CITIES[mt_rand(0, count(Catalog::CITIES) - 1)],
                'created_at' => Catalog::REFERENCE_TIMESTAMP,
                'updated_at' => Catalog::REFERENCE_TIMESTAMP,
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('dealers')->insert($chunk);
        }
    }
}
