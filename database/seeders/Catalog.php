<?php

namespace Database\Seeders;

final class Catalog
{
    /**
     * @var array<string, list<string>>
     */
    public const MODELS_BY_MAKE = [
        'Audi' => ['A3', 'A4', 'A6', 'Q3', 'Q5'],
        'BMW' => ['1 Series', '3 Series', '5 Series', 'X1', 'X3'],
        'Ford' => ['Fiesta', 'Focus', 'Kuga', 'Mustang', 'Puma'],
        'Honda' => ['Civic', 'CR-V', 'Jazz', 'HR-V'],
        'Hyundai' => ['i20', 'i30', 'Kona', 'Tucson'],
        'Mercedes-Benz' => ['A-Class', 'C-Class', 'E-Class', 'GLA', 'GLC'],
        'Opel' => ['Astra', 'Corsa', 'Insignia', 'Mokka'],
        'Peugeot' => ['208', '308', '3008', '5008'],
        'Renault' => ['Clio', 'Megane', 'Captur', 'Zoe'],
        'Skoda' => ['Fabia', 'Octavia', 'Superb', 'Kodiaq'],
        'Tesla' => ['Model 3', 'Model S', 'Model X', 'Model Y'],
        'Toyota' => ['Yaris', 'Corolla', 'RAV4', 'C-HR'],
        'Volkswagen' => ['Golf', 'Passat', 'Polo', 'Tiguan', 'ID.3'],
        'Volvo' => ['V40', 'V60', 'XC40', 'XC60', 'XC90'],
    ];

    /**
     * @var list<string>
     */
    public const CITIES = [
        'Amsterdam', 'Berlin', 'Brussels', 'Cologne', 'Dresden', 'Dusseldorf', 'Frankfurt', 'Hamburg',
        'Hannover', 'Leipzig', 'Lyon', 'Madrid', 'Milan', 'Munich', 'Paris', 'Prague', 'Rotterdam',
        'Stuttgart', 'Vienna', 'Warsaw',
    ];

    public const REFERENCE_TIMESTAMP = '2026-01-01 00:00:00+00';

    public const SEED = 20260101;
}
