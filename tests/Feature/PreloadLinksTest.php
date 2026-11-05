<?php

declare(strict_types=1);

use App\Models\Dealer;
use App\Models\Listing;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Vite;

function detailUrl(): string
{
    $listing = Listing::factory()->for(Dealer::factory())->create();

    return '/listings/'.$listing->id;
}

it('AC-1 sends Link preload headers matching the CSS and JS URLs in the HTML', function (): void {
    $manifest = public_path('build/manifest.json');
    $response = $this->get(detailUrl())->assertOk();

    preg_match_all('/<link rel="stylesheet" href="([^"]+)"/', $response->getContent(), $css);
    preg_match_all('/<script type="module" src="([^"]+)"/', $response->getContent(), $js);

    expect($css[1])->not->toBeEmpty()->and($js[1])->not->toBeEmpty();

    $links = $response->headers->all('link');
    $joined = implode(',', $links);

    foreach ($css[1] as $url) {
        expect($joined)->toContain('<'.$url.'>; rel="preload"; as="style"');
    }
    foreach ($js[1] as $url) {
        expect($joined)->toContain('<'.$url.'>; rel="modulepreload"; as="script"');
    }
})->skip(fn (): bool => ! file_exists(public_path('build/manifest.json')), 'requires the Vite build');

it('MNC-1 keeps the HTML body identical with and without the Link middleware', function (): void {
    $url = detailUrl();
    $with = $this->get($url)->assertOk()->getContent();

    Vite::useBuildDirectory('build');
    $without = $this->withoutMiddleware(AddLinkHeadersForPreloadedAssets::class)
        ->get($url)->assertOk();

    expect($without->headers->has('link'))->toBeFalse()
        ->and(maskRenderTime($without->getContent()))->toBe(maskRenderTime($with));
})->skip(fn (): bool => ! file_exists(public_path('build/manifest.json')), 'requires the Vite build');

it('MNC-2 does not add Link headers to other routes', function (): void {
    $this->get('/listings')->assertOk()->assertHeaderMissing('Link');
})->skip(fn (): bool => ! file_exists(public_path('build/manifest.json')), 'requires the Vite build');

it('AC-1 records a live Link versus HTML check for all six servers in tasks.md', function (): void {
    $tasks = file_get_contents(base_path('.ai/specs/spec-20261104-2340-early-hints-compression/tasks.md'));

    foreach (['nginx-fpm', 'apache-fpm', 'frankenphp', 'openlitespeed', 'frankenphp-octane', 'frankenphp-worker'] as $server) {
        expect($tasks)->toContain('live-link: '.$server.' match');
    }
});
