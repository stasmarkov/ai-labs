<?php

declare(strict_types=1);

use App\Services\StanfordCarsClassParser;

test('AC-2 parses make, model, body and year against the catalog', function (string $name, string $make, string $model, string $body, int $year) {
    $class = (new StanfordCarsClassParser)->parse($name);

    expect($class)->not->toBeNull()
        ->and([$class->make, $class->model, $class->body, $class->year])->toBe([$make, $model, $body, $year])
        ->and($class->name)->toBe($name);
})->with([
    'plain' => ['Ford Fiesta Sedan 2012', 'Ford', 'Fiesta', 'Sedan', 2012],
    'model with a space' => ['BMW 3 Series Sedan 2012', 'BMW', '3 Series', 'Sedan', 2012],
    'model with a hyphen' => ['Mercedes-Benz C-Class Sedan 2012', 'Mercedes-Benz', 'C-Class', 'Sedan', 2012],
    'make alias' => ['Mercedes C-Class Sedan 2012', 'Mercedes-Benz', 'C-Class', 'Sedan', 2012],
    'vw alias' => ['VW Golf Hatchback 2012', 'Volkswagen', 'Golf', 'Hatchback', 2012],
    'multi-word body' => ['Toyota RAV4 Crew Cab 2012', 'Toyota', 'RAV4', 'Crew Cab', 2012],
    'case-insensitive' => ['tesla model s sedan 2012', 'Tesla', 'Model S', 'sedan', 2012],
    'class model starts with the catalog model' => ['BMW X3 SUV 2012', 'BMW', 'X3', 'SUV', 2012],
    'no body' => ['Ford Fiesta 2012', 'Ford', 'Fiesta', '', 2012],
]);

test('AC-2 skips names with no catalog make, no catalog model or no year', function (string $name) {
    expect((new StanfordCarsClassParser)->parse($name))->toBeNull();
})->with([
    'unknown make' => ['Bugatti Veyron Coupe 2009'],
    'make only' => ['Audi S4 Sedan 2012'],
    'no year' => ['Ford Fiesta Sedan'],
    'model must end on a word boundary' => ['Tesla Model SX Sedan 2012'],
    'make must end on a word boundary' => ['Fordson Fiesta Sedan 2012'],
    'empty' => [''],
]);

test('AC-2 prefers the longest catalog model', function () {
    $class = (new StanfordCarsClassParser)->parse('Peugeot 3008 SUV 2012');

    expect($class->model)->toBe('3008');
});
