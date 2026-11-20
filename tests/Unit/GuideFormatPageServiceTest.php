<?php

declare(strict_types=1);

use App\Enums\GuideFormatType;
use App\Guides\GuideFormatRepository;
use App\Guides\GuideRepository;
use App\Guides\SnippetResolver;
use App\Services\GuideFormatPageService;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->service = new GuideFormatPageService(
        new GuideFormatRepository(resource_path('guides/formats'), new SnippetResolver(base_path())),
        new GuideRepository(resource_path('guides')),
    );
});

it('AC-1 names the first item that teaches each outcome as the coverage of a format', function (): void {
    $entries = collect($this->service->compare('photos')['entries'])->keyBy('key');

    expect($entries['build-along']['outcomes'])->toBe([
        'LO-1' => 'Start from one command',
        'LO-2' => 'Ask for a schema, not prose',
        'LO-3' => 'Check the answer again in PHP',
        'LO-4' => 'Give the work its own queue',
        'LO-5' => 'Change the model and confirm it',
    ])->and($entries['baseline']['outcomes']['LO-4'])->toBe('Support');
});

it('AC-2 sends each snippet of a format once and points the items at it', function (GuideFormatType $type): void {
    $props = $this->service->format('photos', $type);
    $references = collect($props['snippets'])->map(fn (array $snippet): string => $snippet['file'].':'.$snippet['startLine'].'-'.$snippet['endLine']);

    expect($references->duplicates()->all())->toBe([])
        ->and(collect($props['items'])->pluck('snippet')->reject(fn (mixed $index): bool => $index === null)->unique()->sort()->values()->all())
        ->toBe(array_keys($props['snippets']));
})->with(GuideFormatType::cases());

it('AC-8 builds the trial link from the format set of the guide', function (): void {
    expect($this->service->trial('photos'))->toBe([
        'title' => 'Guide format trial',
        'summary' => 'The "Read a car photo" guide taught five ways: Build-along, Follow one photo, Predict and check, Break and fix and Code tour. Try them side by side.',
        'href' => '/ai/guides/photos/formats',
    ]);
});

it('AC-8 a guide without a format set has no trial link', function (): void {
    expect($this->service->trial('describe'))->toBeNull()
        ->and($this->service->trial('nope'))->toBeNull();
});
