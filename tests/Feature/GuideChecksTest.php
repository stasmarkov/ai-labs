<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuideChecksTest extends TestCase
{
    public function test_mnc_3_non_lab_routes_still_answer(): void
    {
        $this->get('/up')->assertOk();
        $this->getJson('/api/ping')->assertOk();
        $this->getJson('/listings')->assertOk();
    }

    public function test_mnc_4_main_entry_does_not_import_the_lab_code(): void
    {
        foreach (['resources/js/app.js', 'resources/css/app.css'] as $entry) {
            $source = file_get_contents(base_path($entry));

            $this->assertStringNotContainsString('/ai/', $source, $entry);
            $this->assertStringNotContainsString('guide', strtolower($source), $entry);
        }
    }

    public function test_mnc_6_guide_page_makes_no_network_call(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->get('/ai/guides/chat')->assertOk();

        Http::assertNotSent(fn ($request) => ! str_ends_with($request->url(), '/api/version'));
    }
}
