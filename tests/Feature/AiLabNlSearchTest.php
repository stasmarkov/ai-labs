<?php

declare(strict_types=1);

use App\Http\Requests\NlSearchRequest;
use App\Services\NlSearchService;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('AC-7 the natural-language page renders with its search settings', function (): void {
    $this->get('/ai/nl-search')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/NlSearch')
            ->where('searchUrl', '/api/listings/nl')
            ->where('runUrl', '/ai/runs/RUN_ID')
            ->where('idPlaceholder', 'RUN_ID')
            ->where('maxQueryLength', NlSearchRequest::MAX_QUERY_LENGTH)
            ->where('models', NlSearchService::allowedModels()));
});

it('AC-7 the overview lists the NL search page as a ready capability right after semantic search', function (): void {
    Http::fake(['*/api/version' => Http::response(['version' => '0.12.0'])]);

    $this->get('/ai')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('capabilities', 11)
            ->where('capabilities.1.key', 'semantic')
            ->where('capabilities.2.key', 'nl-search')
            ->where('capabilities.3.key', 'docs')
            ->where('capabilities.2.route', '/ai/nl-search')
            ->where('capabilities.2.title', 'NL search')
            ->where('capabilities.2.status', 'ready')
            ->where('capabilities.2.guideKey', 'nl-search')
            ->where('capabilities.5.key', 'photos'));
});
