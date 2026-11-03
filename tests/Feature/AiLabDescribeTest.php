<?php

declare(strict_types=1);

use App\Models\AiRun;
use App\Models\Dealer;
use App\Models\Listing;
use Inertia\Testing\AssertableInertia as Assert;

it('AC-2 the describe page offers the ten lowest listing ids as a picker', function (): void {
    $dealer = Dealer::factory()->create();

    foreach (range(1, 12) as $number) {
        Listing::factory()->for($dealer)->create(['make' => 'Make'.$number, 'model' => 'Model', 'year' => 2000 + $number, 'city' => 'Leeds']);
    }

    $firstId = Listing::query()->min('id');

    $this->get('/ai/describe')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Describe')
            ->has('listings', 10)
            ->where('listings.0.id', $firstId)
            ->where('listings.0.title', '2001 Make1 Model, Leeds')
            ->where('listings.9.title', '2010 Make10 Model, Leeds')
            ->where('describeUrl', fn (string $url) => str_contains($url, '/listings/') && str_contains($url, '/describe'))
            ->where('runUrl', fn (string $url) => str_contains($url, '/ai/runs/')));
});

it('AC-2 the run endpoint returns the logged run by invocation id', function (): void {
    AiRun::create([
        'invocation_id' => 'run-abc',
        'agent' => 'CarDescriptionAgent',
        'provider' => 'fake-stream',
        'model' => 'fake-slow-1',
        'prompt_version' => 'car-description@v1',
        'tokens_in' => 12,
        'tokens_out' => 34,
        'ttft_ms' => 56,
        'latency_ms' => 789,
        'status' => 'failed',
        'error_class' => 'Illuminate\\Http\\Client\\ConnectionException',
        'error_message' => 'cURL error 7: Failed to connect to host.docker.internal port 11434',
    ]);

    $this->getJson('/ai/runs/run-abc')
        ->assertOk()
        ->assertJson([
            'invocation_id' => 'run-abc',
            'agent' => 'CarDescriptionAgent',
            'provider' => 'fake-stream',
            'model' => 'fake-slow-1',
            'prompt_version' => 'car-description@v1',
            'tokens_in' => 12,
            'tokens_out' => 34,
            'ttft_ms' => 56,
            'latency_ms' => 789,
            'status' => 'failed',
            'error_message' => 'ConnectionException',
        ])
        ->assertJsonStructure(['created_at'])
        ->assertDontSee('host.docker.internal');
});

it('AC-2 the run endpoint answers 404 for an unknown invocation id', function (): void {
    $this->getJson('/ai/runs/missing')->assertNotFound();
});
