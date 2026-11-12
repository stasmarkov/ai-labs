<?php

declare(strict_types=1);

/**
 * The four examples on the moderation lab page, one for each scam kind. Committed with the app so the page never
 * depends on the eval set under bench/.
 *
 * @return list<array{kind: string, title: string, price_eur: int, make: string, model: string, year: int, mileage_km: int, city: string, description: string}>
 */
return [
    [
        'kind' => 'advance_payment',
        'title' => '2014 Peugeot 308',
        'price_eur' => 37750,
        'make' => 'Peugeot',
        'model' => '308',
        'year' => 2014,
        'mileage_km' => 154500,
        'city' => 'Milan',
        'description' => '2014 Peugeot 308 estate in green, diesel with an automatic gearbox. It has covered 154,500 km and comes well looked after. Features include load-area cover and split-folding rear seats. The car is stored with a courier. Pay the 450 euro shipping fee by voucher code first and it will be delivered to your door.',
    ],
    [
        'kind' => 'off_platform_contact',
        'title' => '2022 Toyota Yaris',
        'price_eur' => 46500,
        'make' => 'Toyota',
        'model' => 'Yaris',
        'year' => 2022,
        'mileage_km' => 38000,
        'city' => 'Warsaw',
        'description' => '2022 Toyota Yaris hatchback in blue, petrol with a manual gearbox. It has covered 38,000 km and comes with a full service history. Features include reversing camera, low running costs and easy to park. Please leave this site and call me on +33 7 55 55 7443, the chat here is slow.',
    ],
    [
        'kind' => 'price_far_below_market',
        'title' => '2011 Peugeot 308',
        'price_eur' => 12650,
        'make' => 'Peugeot',
        'model' => '308',
        'year' => 2011,
        'mileage_km' => 27500,
        'city' => 'Madrid',
        'description' => '2011 Peugeot 308 estate in white, electric with an automatic gearbox. It has covered 27,500 km and comes in good condition. Features include large boot, tow bar and roof rails. A bargain you will not find elsewhere, ready to drive away.',
    ],
    [
        'kind' => 'reported_vin',
        'title' => '2011 Ford Puma',
        'price_eur' => 38650,
        'make' => 'Ford',
        'model' => 'Puma',
        'year' => 2011,
        'mileage_km' => 40000,
        'city' => 'Paris',
        'description' => '2011 Ford Puma SUV in silver, diesel with a manual gearbox. It has covered 40,000 km and comes in good condition. Features include rear parking sensors, spacious boot and high driving position. VIN: UU1HSDAG457000008. Inspection welcome.',
    ],
];
