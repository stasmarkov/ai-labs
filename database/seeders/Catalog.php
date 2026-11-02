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
     * @var array<string, string>
     */
    public const BODY_TYPE_BY_MODEL = [
        'A3' => 'hatchback', 'A4' => 'saloon', 'A6' => 'estate', 'Q3' => 'SUV', 'Q5' => 'SUV',
        '1 Series' => 'hatchback', '3 Series' => 'saloon', '5 Series' => 'saloon', 'X1' => 'SUV', 'X3' => 'SUV',
        'Fiesta' => 'hatchback', 'Focus' => 'hatchback', 'Kuga' => 'SUV', 'Mustang' => 'coupe', 'Puma' => 'SUV',
        'Civic' => 'hatchback', 'CR-V' => 'SUV', 'Jazz' => 'hatchback', 'HR-V' => 'SUV',
        'i20' => 'hatchback', 'i30' => 'estate', 'Kona' => 'SUV', 'Tucson' => 'SUV',
        'A-Class' => 'hatchback', 'C-Class' => 'saloon', 'E-Class' => 'estate', 'GLA' => 'SUV', 'GLC' => 'SUV',
        'Astra' => 'estate', 'Corsa' => 'hatchback', 'Insignia' => 'saloon', 'Mokka' => 'SUV',
        '208' => 'hatchback', '308' => 'estate', '3008' => 'SUV', '5008' => 'MPV',
        'Clio' => 'hatchback', 'Megane' => 'hatchback', 'Captur' => 'SUV', 'Zoe' => 'hatchback',
        'Fabia' => 'hatchback', 'Octavia' => 'estate', 'Superb' => 'saloon', 'Kodiaq' => 'SUV',
        'Model 3' => 'saloon', 'Model S' => 'saloon', 'Model X' => 'SUV', 'Model Y' => 'SUV',
        'Yaris' => 'hatchback', 'Corolla' => 'estate', 'RAV4' => 'SUV', 'C-HR' => 'SUV',
        'Golf' => 'hatchback', 'Passat' => 'estate', 'Polo' => 'hatchback', 'Tiguan' => 'SUV', 'ID.3' => 'hatchback',
        'V40' => 'hatchback', 'V60' => 'estate', 'XC40' => 'SUV', 'XC60' => 'SUV', 'XC90' => 'SUV',
    ];

    /**
     * @var array<string, list<string>>
     */
    public const FEATURES_BY_BODY_TYPE = [
        'hatchback' => ['compact size for city parking', 'easy to park', 'low running costs', 'folding rear seats', 'reversing camera'],
        'saloon' => ['comfortable ride', 'leather seats', 'dual-zone climate control', 'spacious rear legroom', 'cruise control'],
        'estate' => ['large boot', 'roof rails', 'split-folding rear seats', 'tow bar', 'load-area cover'],
        'SUV' => ['high driving position', 'spacious boot', 'all-weather tyres', 'roof rails', 'rear parking sensors'],
        'MPV' => ['seven seats', 'sliding rear doors', 'huge boot with seats folded', 'flexible seating', 'rear air conditioning'],
        'coupe' => ['sports suspension', 'alloy wheels', 'premium sound system', 'sport seats', 'launch control'],
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
