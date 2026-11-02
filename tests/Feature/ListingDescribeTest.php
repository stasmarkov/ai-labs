<?php

declare(strict_types=1);

use App\Ai\CarDescriptionAgent;
use App\DTOs\DealerSummary;
use App\DTOs\ListingDetail;
use App\DTOs\ListingSummary;
use App\Models\AiRun;
use App\Models\Dealer;
use App\Models\Listing;

it('AC-1 describe streams the description as server-sent events', function (): void {
    $listing = Listing::factory()->for(Dealer::factory())->create(['make' => 'Land Rover', 'model' => 'Defender', 'year' => 2019]);

    $response = $this->get('/listings/'.$listing->id.'/describe');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream')
        ->and($response->headers->get('X-Accel-Buffering'))->toBe('no')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache');

    $lines = array_values(array_filter(explode("\n", $response->streamedContent()), fn (string $line): bool => $line !== ''));
    expect($lines)->not->toBeEmpty()
        ->and(end($lines))->toBe('data: [DONE]');

    $types = [];
    $deltas = [];
    foreach (array_slice($lines, 0, -1) as $line) {
        expect($line)->toStartWith('data: ');
        $event = json_decode(substr($line, 6), true, flags: JSON_THROW_ON_ERROR);
        $types[] = $event['type'];
        if ($event['type'] === 'text_delta') {
            $deltas[] = $event['delta'];
        }
    }

    // One data line per SDK event: start, text start, every delta, text end, stream end.
    expect($types[0])->toBe('stream_start')
        ->and($types)->toContain('text_start', 'text_end', 'stream_end')
        ->and(end($types))->toBe('stream_end')
        ->and($deltas)->toHaveCount(20)
        ->and(implode('', $deltas))->toStartWith('2019 Land Rover Defender')
        ->and(str_word_count(implode('', $deltas), 0, '0123456789'))->toBe(20);
});

it('AC-2 describe answers 404 for an unknown listing and logs nothing', function (): void {
    $response = $this->get('/listings/999999/describe');

    $response->assertNotFound()->assertJsonPath('success', false)->assertJsonPath('status', 404);
    expect($response->headers->get('Content-Type'))->not->toContain('text/event-stream');
});

it('AC-7 the prompt version comes from the prompt file name', function (): void {
    $listing = new ListingDetail(
        new ListingSummary(1, 'Volvo', 'V60', 2020, 'diesel', 1000, 2000000, 'EUR', 'Hamburg', '2026-01-01', 1),
        new DealerSummary(1, 'Autohaus Nord', 'Hamburg'),
        '/images/heroes/hero-1.webp',
    );
    $agent = new CarDescriptionAgent;

    expect($agent->promptVersion())->toBe('car-description@v1')
        ->and((string) $agent->instructions())->toBe(trim((string) file_get_contents(resource_path('prompts/car-description.v1.md'))))
        ->and($agent->promptFor($listing))->toBe(implode("\n", [
            'Car: 2020 Volvo V60',
            'Fuel: diesel',
            'Mileage: 1000 km',
            'Price: 20000.00 EUR',
            'City: Hamburg',
        ]));
});

it('AC-7 a prompt file named in config is read and its version recorded', function (): void {
    $path = resource_path('prompts/car-description.v2.md');
    file_put_contents($path, "Describe the car in two sentences.\n");

    try {
        config(['ai.prompts.car_description' => 'car-description.v2']);
        $listing = Listing::factory()->for(Dealer::factory())->create();

        expect((new CarDescriptionAgent)->instructions())->toBe('Describe the car in two sentences.');

        $this->get('/listings/'.$listing->id.'/describe')->streamedContent();
    } finally {
        unlink($path);
    }

    expect(AiRun::firstOrFail()->prompt_version)->toBe('car-description@v2');
});

it('AC-8 describe streams its body when the app runs under Octane', function (): void {
    $_SERVER['LARAVEL_OCTANE'] = '1';

    try {
        $listing = Listing::factory()->for(Dealer::factory())->create();

        $body = $this->get('/listings/'.$listing->id.'/describe')->streamedContent();
    } finally {
        unset($_SERVER['LARAVEL_OCTANE']);
    }

    expect($body)->toContain('"type":"text_delta"')->toEndWith("data: [DONE]\n\n");
});

it('AC-2 an id outside the route pattern also gets the JSON 404', function (string $id): void {
    $response = $this->get('/listings/'.$id.'/describe');

    $response->assertNotFound();
    expect($response->headers->get('Content-Type'))->toContain('application/json');
})->with(['0', 'abc', '12345678901234567890']);
