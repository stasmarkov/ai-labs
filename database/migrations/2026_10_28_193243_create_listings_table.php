<?php

use App\Enums\Fuel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs()->primary();
            $table->foreignId('dealer_id')->constrained()->restrictOnDelete();
            $table->string('make', 50);
            $table->string('model', 80);
            $table->string('fuel', 16);
            $table->smallInteger('year');
            $table->bigInteger('price_cents');
            $table->char('currency', 3)->default('EUR');
            $table->integer('mileage_km');
            $table->string('city', 80);
            $table->timestampTz('listed_at');
            $table->timestampsTz();
        });

        $fuels = collect(Fuel::cases())->map(fn (Fuel $fuel) => "'{$fuel->value}'")->implode(', ');

        DB::statement("ALTER TABLE listings ADD CONSTRAINT listings_fuel_check CHECK (fuel IN ({$fuels}))");
        DB::statement('ALTER TABLE listings ADD CONSTRAINT listings_year_check CHECK (year >= 1980)');
        DB::statement('ALTER TABLE listings ADD CONSTRAINT listings_price_cents_check CHECK (price_cents > 0)');
        DB::statement("ALTER TABLE listings ADD CONSTRAINT listings_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement('ALTER TABLE listings ADD CONSTRAINT listings_mileage_km_check CHECK (mileage_km >= 0)');

        DB::statement('CREATE INDEX listings_listed_at_id_idx ON listings (listed_at DESC, id DESC)');
        DB::statement('CREATE INDEX listings_make_model_idx ON listings (lower(make), lower(model), listed_at DESC, id DESC)');
        DB::statement('CREATE INDEX listings_city_idx ON listings (lower(city), listed_at DESC, id DESC)');
        DB::statement('CREATE INDEX listings_dealer_id_idx ON listings (dealer_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
