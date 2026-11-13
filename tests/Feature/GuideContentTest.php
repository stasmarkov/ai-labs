<?php

declare(strict_types=1);

use App\DTOs\Guide;
use App\Guides\GuideEventTypes;
use App\Guides\GuideFacts;
use App\Guides\GuideRenderer;
use App\Guides\GuideRepository;
use App\Guides\SnippetResolver;

const CAPABILITY_GUIDES = ['describe', 'semantic', 'nl-search', 'docs', 'chat', 'photos', 'similar-photos', 'photo-search', 'photo-duplicates', 'assistant', 'moderation', 'admin-assistant'];

const FOUNDATION_GUIDES = ['providers', 'prompts', 'run-log', 'streaming', 'guards', 'local-models'];

const ALL_FILLED_GUIDES = [...CAPABILITY_GUIDES, ...FOUNDATION_GUIDES];

const LAB_PAGES = ['ai.describe', 'ai.semantic', 'ai.nl-search', 'ai.docs', 'ai.chat', 'ai.photos', 'ai.photo-search', 'ai.photos.duplicates', 'ai.assistant', 'ai.moderation', 'ai.admin-assistant'];

/**
 * @return list<string>
 */
function guideTexts(Guide $guide): array
{
    $texts = [$guide->title, $guide->summary];
    foreach ([...$guide->works, ...$guide->failures, ...$guide->recipes] as $section) {
        $texts[] = $section->title;
        $texts[] = $section->body;
    }
    foreach ($guide->support as $row) {
        $texts[] = $row->note;
    }
    foreach ($guide->experiments as $experiment) {
        $texts[] = $experiment->title;
        $texts[] = $experiment->answer['body'];
        foreach ($experiment->predictions as $prediction) {
            $texts[] = $prediction['text'];
        }
    }

    return $texts;
}

it('AC-2 ships a file for every capability guide', function (): void {
    expect(app(GuideRepository::class)->keys())->toContain(...CAPABILITY_GUIDES);
});

it('AC-4 declares the kind of every guide file, the one source for capability, foundation and planned', function (): void {
    $repository = app(GuideRepository::class);
    $kinds = array_combine($repository->keys(), array_map(fn (string $key): string => $repository->find($key)->kind, $repository->keys()));

    expect(array_keys(array_filter($kinds, fn (string $kind): bool => $kind === 'capability')))->toEqualCanonicalizing(CAPABILITY_GUIDES)
        ->and(array_keys(array_filter($kinds, fn (string $kind): bool => $kind === 'foundation')))->toEqualCanonicalizing(FOUNDATION_GUIDES)
        ->and(array_keys(array_filter($kinds, fn (string $kind): bool => $kind === 'planned')))->toBe([]);
});

it('AC-2 meets the minimum counts of the six tabs', function (string $key): void {
    $guide = app(GuideRepository::class)->find($key);
    $implementation = array_filter($guide->works, fn ($step): bool => $step->snippet !== null);
    $swapsModel = array_filter($guide->recipes, fn ($recipe): bool => preg_match('/model|provider/i', $recipe->title) === 1);
    $statuses = array_unique(array_map(fn ($row): string => $row->status, $guide->support));

    expect($guide->planned)->toBeFalse()
        ->and(count($guide->works))->toBeGreaterThanOrEqual(3)
        ->and(count($implementation))->toBeGreaterThanOrEqual(2)
        ->and(count($guide->recipes))->toBeGreaterThanOrEqual(2)
        ->and($swapsModel)->not->toBeEmpty()
        ->and(count($guide->failures))->toBeGreaterThanOrEqual(2)
        ->and($statuses)->toContain('logged', 'guarded', 'runbook')
        ->and(count($guide->numbers))->toBeGreaterThanOrEqual(1)
        ->and(count($guide->experiments))->toBeGreaterThanOrEqual(1);
})->with(CAPABILITY_GUIDES);

it('AC-3 resolves every snippet to the current source lines', function (string $key): void {
    $guide = app(GuideRepository::class)->find($key);
    $resolver = app(SnippetResolver::class);

    expect($guide->snippetReferences())->not->toBeEmpty();
    foreach ($guide->snippetReferences() as $reference) {
        $snippet = $resolver->resolve($reference);

        expect($snippet['code'])->not->toBe('', "{$key}: snippet [{$reference}] is empty")
            ->and($snippet['startLine'])->toBeGreaterThan(0)
            ->and($snippet['endLine'])->toBeGreaterThanOrEqual($snippet['startLine']);
    }
})->with(ALL_FILLED_GUIDES);

it('AC-3 renders a guide with every number and snippet resolved', function (string $key): void {
    $rendered = app(GuideRenderer::class)->render($key);

    foreach ($rendered['numbers'] as $number) {
        expect($number['value'])->not->toBe('')
            ->and(array_keys($number))->toBe(['label', 'unit', 'value']);
    }
    expect($rendered['works'])->not->toBeEmpty();
})->with(ALL_FILLED_GUIDES);

it('AC-2 points every related key at an existing guide', function (string $key): void {
    $repository = app(GuideRepository::class);

    foreach ($repository->find($key)->related as $related) {
        expect($repository->has($related))->toBeTrue("{$key}: related guide [{$related}] does not exist");
        expect($related)->not->toBe($key);
    }
})->with(ALL_FILLED_GUIDES);

it('AC-2 only maps steps to event types the protocols know', function (string $key): void {
    $events = array_filter(array_map(fn ($step): ?string => $step->event, app(GuideRepository::class)->find($key)->works));

    expect($events)->toBeArray();

    foreach ($events as $event) {
        expect(GuideEventTypes::KNOWN)->toContain($event);
    }
})->with(ALL_FILLED_GUIDES);

it('AC-6 sends experiments to a lab page with only the q preset', function (string $key): void {
    foreach (app(GuideRepository::class)->find($key)->experiments as $experiment) {
        expect(LAB_PAGES)->toContain($experiment->page)
            ->and(array_keys($experiment->params))->each->toBe('q');
    }
})->with(ALL_FILLED_GUIDES);

it('AC-1 keeps slice ids, markdown files and repo paths out of the prose', function (string $key): void {
    foreach (guideTexts(app(GuideRepository::class)->find($key)) as $text) {
        expect($text)
            ->not->toMatch('/\bSL-\d+/')
            ->not->toMatch('/\.md\b/')
            ->not->toMatch('#\b(app|resources|config|routes|database|bench)/#');
    }
})->with(ALL_FILLED_GUIDES);

it('AC-4 ships the foundation guides and the photos guide', function (): void {
    expect(app(GuideRepository::class)->keys())->toContain(...[...FOUNDATION_GUIDES, 'photos']);
});

it('AC-4 meets the minimum counts of a foundation guide', function (string $key): void {
    $guide = app(GuideRepository::class)->find($key);
    $statuses = array_unique(array_map(fn ($row): string => $row->status, $guide->support));

    expect($guide->planned)->toBeFalse()
        ->and(count($guide->works))->toBeGreaterThanOrEqual(3)
        ->and(count($guide->failures))->toBeGreaterThanOrEqual(2)
        ->and(count($guide->recipes))->toBeGreaterThanOrEqual(2)
        ->and($statuses)->toContain('logged', 'guarded', 'runbook')
        ->and(count($guide->numbers))->toBeGreaterThanOrEqual(1)
        ->and(count($guide->experiments))->toBeGreaterThanOrEqual(1);
})->with(FOUNDATION_GUIDES);

it('AC-7 counts photos as a capability guide with six non-empty tabs and no references', function (): void {
    $guide = app(GuideRepository::class)->find('photos');
    $rendered = app(GuideRenderer::class)->render('photos');

    expect($guide->kind)->toBe('capability')
        ->and($guide->planned)->toBeFalse()
        ->and($guide->plan)->toBeEmpty()
        ->and($rendered['works'])->not->toBeEmpty()
        ->and($rendered['failures'])->not->toBeEmpty()
        ->and($rendered['recipes'])->not->toBeEmpty()
        ->and($rendered['support'])->not->toBeEmpty()
        ->and($rendered['numbers'])->not->toBeEmpty()
        ->and($rendered['experiments'])->not->toBeEmpty();
});

it('AC-9 the similar-photos guide has six non-empty tabs, no references, and is linked from the photos guide', function (): void {
    $guide = app(GuideRepository::class)->find('similar-photos');
    $rendered = app(GuideRenderer::class)->render('similar-photos');

    expect($guide->kind)->toBe('capability')
        ->and($guide->planned)->toBeFalse()
        ->and($rendered['works'])->not->toBeEmpty()
        ->and($rendered['failures'])->not->toBeEmpty()
        ->and($rendered['recipes'])->not->toBeEmpty()
        ->and($rendered['support'])->not->toBeEmpty()
        ->and($rendered['numbers'])->not->toBeEmpty()
        ->and($rendered['experiments'])->not->toBeEmpty()
        ->and(app(GuideRepository::class)->find('photos')->related)->toContain('similar-photos');

    $this->withoutVite();
    $this->get('/ai/guides/similar-photos')->assertOk();
});

it('AC-8 the photo-search guide has six non-empty tabs, no references, and is linked from the photo guides', function (): void {
    $guide = app(GuideRepository::class)->find('photo-search');
    $rendered = app(GuideRenderer::class)->render('photo-search');

    expect($guide->kind)->toBe('capability')
        ->and($guide->planned)->toBeFalse()
        ->and($rendered['works'])->not->toBeEmpty()
        ->and($rendered['failures'])->not->toBeEmpty()
        ->and($rendered['recipes'])->not->toBeEmpty()
        ->and($rendered['support'])->not->toBeEmpty()
        ->and($rendered['numbers'])->not->toBeEmpty()
        ->and($rendered['experiments'])->not->toBeEmpty()
        ->and(app(GuideRepository::class)->find('similar-photos')->related)->toContain('photo-search')
        ->and(app(GuideRepository::class)->find('photos')->related)->toContain('photo-search');

    $this->withoutVite();
    $this->get('/ai/guides/photo-search')->assertOk();
});

it('AC-9 the providers capability table lists the image encoder with its vector size', function (): void {
    $rows = collect(app(GuideRenderer::class)->render('providers')['table']['rows'])->keyBy('provider');

    expect($rows['photo-encoder'])->toMatchArray(['where' => 'local', 'text' => 'no', 'embeddings' => '512 dimensions (images)', 'key' => 'none needed']);
});

it('AC-6 every experiment offers 2 to 4 predictions and its answer id is one of them', function (string $key): void {
    foreach (app(GuideRepository::class)->find($key)->experiments as $experiment) {
        $ids = array_column($experiment->predictions, 'id');

        expect(count($experiment->predictions))->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(4)
            ->and($ids)->toContain($experiment->answer['id']);
    }
})->with(CAPABILITY_GUIDES);

it('AC-6 the providers guide covers the capability table, key setup, the add-a-provider recipe and a failure per cause', function (): void {
    $guide = app(GuideRepository::class)->find('providers');
    $rendered = app(GuideRenderer::class)->render('providers');
    $text = mb_strtolower(implode("\n", guideTexts($guide)));

    expect($rendered['table']['rows'])->not->toBeEmpty();

    foreach (['anthropic_api_key', 'openai_api_key', 'gemini_api_key', 'add a provider', '1024', 'index_not_built', 'provider_not_enabled', 'cloud-ai'] as $needle) {
        expect($text)->toContain($needle);
    }
});

it('AC-7 photos works steps carry request_sent, response_received, validation_failed and request_failed', function (): void {
    $events = array_column(app(GuideRepository::class)->find('photos')->works, 'event');

    expect($events)->toContain('request_sent', 'response_received', 'validation_failed', 'request_failed');
});

it('AC-7 a photos recipe covers adding a field to the schema', function (): void {
    $recipe = null;
    foreach (app(GuideRepository::class)->find('photos')->recipes as $candidate) {
        if (str_contains(mb_strtolower($candidate->title), 'add a field to the schema')) {
            $recipe = $candidate;
        }
    }
    $text = $recipe === null ? '' : $recipe->title."\n".$recipe->body;

    expect($recipe)->not->toBeNull()
        ->and($text)->toContain('PhotoExtractionRules')
        ->and(mb_strtolower($text))->toContain('schema')
        ->and(mb_strtolower($text))->toContain('prompt version');
});

it('AC-5 the photos empty state names both the download script and photos:import', function (): void {
    $source = file_get_contents(resource_path('js/ai/Pages/Lab/Photos.vue'));

    expect($source)->toContain('stanford-cars.sh --accept-terms')
        ->and($source)->toContain('photos:import');
});

it('AC-2 photo search maps a refusal to the guard step, before the encoder and index steps', function (): void {
    $steps = app(GuideRepository::class)->find('photo-search')->works;
    $events = array_map(fn ($step): ?string => $step->event, $steps);

    expect(array_search('refused', $events, true))->toBe(0)
        ->and(array_search('refused', $events, true))->toBeLessThan(array_search('response_received', $events, true));
});

it('AC-7 the similar-photos guide teaches the hybrid step, the recipe, the two failure modes and the hybrid numbers', function (): void {
    $rendered = app(GuideRenderer::class)->render('similar-photos');
    $titles = fn (string $tab): array => array_column($rendered[$tab], 'title');

    expect(implode(' ', $titles('works')))->toContain('Hybrid')
        ->and(implode(' ', $titles('recipes')))->toContain('hybrid weight')
        ->and(implode(' ', $titles('failures')))->toContain('Title leakage')->toContain('Hybrid changes little')
        ->and(implode(' ', array_column($rendered['numbers'], 'label')))->toContain('Hybrid precision')->toContain('baseline')
        ->and(collect(app(GuideRepository::class)->find('similar-photos')->works)->pluck('snippet')->filter()->all())->toContain('App\\Services\\SimilarPhotosService::blend');

    $this->withoutVite();
    $this->get('/ai/guides/similar-photos')->assertOk();

    $learning = file_get_contents(base_path('docs/learning-guide.md'));

    expect($learning)->toContain('Hybrid similar photos')->toContain('HybridEvalScorer');
});

it('AC-8 the photo-duplicates guide has six non-empty tabs, no references, and is linked from the photo guides', function (): void {
    $guide = app(GuideRepository::class)->find('photo-duplicates');
    $rendered = app(GuideRenderer::class)->render('photo-duplicates');

    expect($guide->kind)->toBe('capability')
        ->and($guide->planned)->toBeFalse()
        ->and($rendered['works'])->not->toBeEmpty()
        ->and($rendered['failures'])->not->toBeEmpty()
        ->and($rendered['recipes'])->not->toBeEmpty()
        ->and($rendered['support'])->not->toBeEmpty()
        ->and($rendered['numbers'])->not->toBeEmpty()
        ->and($rendered['experiments'])->not->toBeEmpty()
        ->and(app(GuideRepository::class)->find('similar-photos')->related)->toContain('photo-duplicates')
        ->and(app(GuideRepository::class)->find('photos')->related)->toContain('photo-duplicates');

    $this->withoutVite();
    $this->get('/ai/guides/photo-duplicates')->assertOk();
});

it('AC-9 the assistant guide has six non-empty tabs, no references, and its lab page renders', function (): void {
    $guide = app(GuideRepository::class)->find('assistant');
    $rendered = app(GuideRenderer::class)->render('assistant');

    expect($guide->kind)->toBe('capability')
        ->and($guide->planned)->toBeFalse()
        ->and($rendered['works'])->not->toBeEmpty()
        ->and($rendered['failures'])->not->toBeEmpty()
        ->and($rendered['recipes'])->not->toBeEmpty()
        ->and($rendered['support'])->not->toBeEmpty()
        ->and($rendered['numbers'])->not->toBeEmpty()
        ->and($rendered['experiments'])->not->toBeEmpty();

    $this->withoutVite();
    $this->get('/ai/guides/assistant')->assertOk();
    $this->get('/ai/assistant')->assertOk();
});

it('AC-8 the moderation guide has six non-empty tabs, no references, and its lab page renders', function (): void {
    $guide = app(GuideRepository::class)->find('moderation');
    $rendered = app(GuideRenderer::class)->render('moderation');

    expect($guide->kind)->toBe('capability')
        ->and($guide->planned)->toBeFalse()
        ->and($rendered['works'])->not->toBeEmpty()
        ->and($rendered['failures'])->not->toBeEmpty()
        ->and($rendered['recipes'])->not->toBeEmpty()
        ->and($rendered['support'])->not->toBeEmpty()
        ->and($rendered['numbers'])->not->toBeEmpty()
        ->and($rendered['experiments'])->not->toBeEmpty();

    $this->withoutVite();
    $this->get('/ai/guides/moderation')->assertOk();
    $this->get('/ai/moderation')->assertOk();
});

it('AC-8 the local-models guide has six non-empty tabs, teaches the local model limits, has no references and answers 200', function (): void {
    $guide = app(GuideRepository::class)->find('local-models');
    $rendered = app(GuideRenderer::class)->render('local-models');
    $text = mb_strtolower(implode("\n", guideTexts($guide)));

    expect($guide->kind)->toBe('foundation')
        ->and($guide->planned)->toBeFalse()
        ->and($rendered['works'])->not->toBeEmpty()
        ->and($rendered['failures'])->not->toBeEmpty()
        ->and($rendered['recipes'])->not->toBeEmpty()
        ->and($rendered['support'])->not->toBeEmpty()
        ->and($rendered['numbers'])->not->toBeEmpty()
        ->and($rendered['experiments'])->not->toBeEmpty();

    foreach (['tokens per second', 'time to first token', 'num_ctx', 'ollama_num_parallel', 'keep_alive', 'cold', 'fallback chain', 'slows'] as $needle) {
        expect($text)->toContain($needle);
    }

    $this->withoutVite();
    $this->get('/ai/guides/local-models')->assertOk();
});

it('AC-8 the local-models guide Numbers tab shows the concurrency load figures', function (): void {
    $rendered = app(GuideRenderer::class)->render('local-models');
    $load = array_values(array_filter($rendered['numbers'], fn (array $number): bool => str_contains($number['label'], 'parallel')));

    expect($load)->not->toBeEmpty();
    foreach ($load as $number) {
        expect((float) $number['value'])->toBeGreaterThan(0);
    }
});

it('AC-8 the local-models guide Numbers tab shows the search p95 figures per server, or the pending note until the run exists', function (): void {
    $rendered = app(GuideRenderer::class)->render('local-models');
    $search = array_values(array_filter($rendered['numbers'], fn (array $number): bool => str_starts_with($number['label'], 'Search p95')));
    $files = glob(base_path('bench/results/llm-load/*/search-under-load.json')) ?: [];
    rsort($files);

    if ($files === []) {
        expect($search)->toHaveCount(3);

        foreach ($search as $number) {
            expect($number['value'])->toBe(GuideFacts::PENDING_TEXT);
        }

        return;
    }

    $data = json_decode((string) file_get_contents($files[0]), true);
    $deltas = array_values(array_filter($search, fn (array $number): bool => str_contains($number['label'], 'change')));

    expect($search)->toHaveCount(3 * count($data['servers']))
        ->and($deltas)->toHaveCount(count($data['servers']));

    foreach ($deltas as $index => $number) {
        expect((float) $number['value'])->toBe((float) $data['servers'][$index]['p95_delta_ms']);
    }
});
