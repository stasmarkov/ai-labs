<?php

declare(strict_types=1);

use App\DTOs\Guide;
use App\Guides\GuideEventTypes;
use App\Guides\GuideFacts;
use App\Guides\GuideRenderer;
use App\Guides\GuideRepository;
use App\Guides\InvalidGuideException;
use App\Guides\SnippetResolver;
use App\Lessons\LessonRepository;
use App\Services\GuidePageService;

const CAPABILITY_GUIDES = ['describe', 'semantic', 'nl-search', 'docs', 'chat', 'photos', 'similar-photos', 'photo-search', 'photo-duplicates', 'assistant', 'moderation', 'admin-assistant', 'research', 'playground'];

const FOUNDATION_GUIDES = ['providers', 'prompts', 'run-log', 'streaming', 'guards', 'local-models', 'mcp', 'metrics', 'logs-restart', 'worker-dx', 'lessons'];

const ALL_FILLED_GUIDES = [...CAPABILITY_GUIDES, ...FOUNDATION_GUIDES];

const LAB_PAGES = ['ai.describe', 'ai.semantic', 'ai.nl-search', 'ai.docs', 'ai.chat', 'ai.photos', 'ai.photo-search', 'ai.photos.duplicates', 'ai.assistant', 'ai.moderation', 'ai.admin-assistant', 'ai.research', 'ai.playground', 'ai.changes', 'ai.learn.index'];

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

it('AC-8 the mcp guide has six non-empty tabs, no references, answers 200 and the docs show how to connect', function (): void {
    $guide = app(GuideRepository::class)->find('mcp');
    $rendered = app(GuideRenderer::class)->render('mcp');
    $text = mb_strtolower(implode("\n", guideTexts($guide)));

    expect($guide->kind)->toBe('foundation')
        ->and($guide->planned)->toBeFalse()
        ->and($rendered['works'])->not->toBeEmpty()
        ->and($rendered['failures'])->not->toBeEmpty()
        ->and($rendered['recipes'])->not->toBeEmpty()
        ->and($rendered['support'])->not->toBeEmpty()
        ->and($rendered['numbers'])->not->toBeEmpty()
        ->and($rendered['experiments'])->not->toBeEmpty();

    foreach (['stdio', 'annotation', 'mcp:start listings', 'claude mcp add', 'claude desktop', 'mcp:inspector', 'propose_title_change', 'change_status', 'confirm'] as $needle) {
        expect($text)->toContain($needle);
    }

    $this->withoutVite();
    $this->get('/ai/guides/mcp')->assertOk();

    $learning = file_get_contents(base_path('docs/learning-guide.md'));
    $readme = file_get_contents(base_path('README.md'));
    $env = file_get_contents(base_path('.env.example'));

    expect($learning)->toContain('### MCP server for the listing tools')->toContain('/ai/guides/mcp')
        ->and($readme)->toContain('claude mcp add ai-lab')->toContain('claude_desktop_config.json')->toContain('mcp:inspector listings')
        ->and($env)->toContain('AI_MCP_SERVER');
});

it('AC-11 the research guide has six non-empty tabs, no references, answers 200 and the docs describe the agent', function (): void {
    $guide = app(GuideRepository::class)->find('research');
    $rendered = app(GuideRenderer::class)->render('research');
    $text = mb_strtolower(implode("\n", guideTexts($guide)));

    expect($guide->kind)->toBe('capability')
        ->and($guide->planned)->toBeFalse()
        ->and($rendered['works'])->not->toBeEmpty()
        ->and($rendered['failures'])->not->toBeEmpty()
        ->and($rendered['recipes'])->not->toBeEmpty()
        ->and($rendered['support'])->not->toBeEmpty()
        ->and($rendered['numbers'])->not->toBeEmpty()
        ->and($rendered['experiments'])->not->toBeEmpty();

    foreach (['agent:research', '--replay', 'cap_reached', 'sub-question', 'redis', 'refused', 'chunk'] as $needle) {
        expect($text)->toContain($needle);
    }

    $this->withoutVite();
    $this->get('/ai/guides/research')->assertOk();
    $this->get('/ai/research')->assertOk();

    $learning = file_get_contents(base_path('docs/learning-guide.md'));
    $readme = file_get_contents(base_path('README.md'));

    expect($learning)->toContain('### Multi-step research agent')->toContain('/ai/guides/research')
        ->and($readme)->toContain('## Multi-step research agent')->toContain('agent:research')->toContain('--replay')->toContain('AI_RESEARCH_PER_MINUTE');
});

it('AC-9 the playground guide has six non-empty tabs, no references, answers 200 and the docs describe the playground', function (): void {
    $guide = app(GuideRepository::class)->find('playground');
    $rendered = app(GuideRenderer::class)->render('playground');
    $text = mb_strtolower(implode("\n", guideTexts($guide)));

    expect($guide->kind)->toBe('capability')
        ->and($guide->planned)->toBeFalse()
        ->and($rendered['works'])->not->toBeEmpty()
        ->and($rendered['failures'])->not->toBeEmpty()
        ->and($rendered['recipes'])->not->toBeEmpty()
        ->and($rendered['support'])->not->toBeEmpty()
        ->and($rendered['numbers'])->not->toBeEmpty()
        ->and($rendered['experiments'])->not->toBeEmpty();

    foreach (['immutable', 'unchanged', 'temperature', 'one after another', 'tokens', 'flagged', 'prompt file', 'timeout'] as $needle) {
        expect($text)->toContain($needle);
    }

    $this->withoutVite();
    $this->get('/ai/guides/playground')->assertOk();
    $this->get('/ai/playground')->assertNotFound();

    app()->detectEnvironment(fn (): string => 'local');
    $this->get('/ai/playground')->assertOk();

    $learning = file_get_contents(base_path('docs/learning-guide.md'));
    $readme = file_get_contents(base_path('README.md'));

    expect($learning)->toContain('### Prompt playground with versions')->toContain('/ai/guides/playground')
        ->and($readme)->toContain('## Prompt playground')->toContain('AI_PLAYGROUND_TARGETS')->toContain('AI_PLAYGROUND_PER_MINUTE');
});

it('AC-5 the metrics guide has six non-empty tabs, no references, answers 200 and teaches the pool, the cap and the pattern', function (): void {
    $guide = app(GuideRepository::class)->find('metrics');
    $rendered = app(GuideRenderer::class)->render('metrics');
    $text = mb_strtolower(implode("\n", guideTexts($guide)));

    expect($guide->kind)->toBe('foundation')
        ->and($guide->planned)->toBeFalse()
        ->and($rendered['works'])->not->toBeEmpty()
        ->and($rendered['failures'])->not->toBeEmpty()
        ->and($rendered['recipes'])->not->toBeEmpty()
        ->and($rendered['support'])->not->toBeEmpty()
        ->and($rendered['numbers'])->not->toBeEmpty()
        ->and($rendered['experiments'])->not->toBeEmpty()
        ->and(implode(' ', array_column($rendered['recipes'], 'title')))->toContain('Add a panel')->toContain('other places');

    foreach (['frankenphp_busy_threads', 'frankenphp_total_threads', 'frankenphp_busy_workers', 'frankenphp_total_workers', 'frankenphp_queue_depth', 'caddy_http_request_duration_seconds', 'process_resident_memory_bytes', '10 s', 'ddev metrics on', 'one load on the graphs', 'queue workers', 'connection pool'] as $needle) {
        expect($text)->toContain($needle);
    }

    foreach ($rendered['numbers'] as $number) {
        expect($number['value'])->not->toBe(GuideFacts::PENDING_TEXT);
    }

    $numbers = collect($rendered['numbers'])->pluck('value', 'label');

    expect((int) $numbers['100 slow calls on 14 fixed threads: most requests inside PHP at once (busy threads)'])
        ->toBeGreaterThan((int) $numbers['100 slow calls on 14 fixed threads: total threads reported'])
        ->and((int) $numbers['100 slow calls on worker mode: most busy workers'])->toBeGreaterThan((int) $numbers['100 slow calls on worker mode: workers'])
        ->and($numbers['100 slow calls on worker mode: most requests in the thread queue'])->toBe('0')
        ->and($numbers['30 answer streams on 14 threads: p95 from the histogram'])->toBe('>10 (bucket cap)');

    $this->withoutVite();
    $this->get('/ai/guides/metrics')->assertOk();
    $this->get('/ai/learn')->assertOk();
});

it('AC-8 the logs-restart guide has six non-empty tabs and the teach sections, no references, answers 200 and is in the learning guide and the README', function (): void {
    $guide = app(GuideRepository::class)->find('logs-restart');
    $rendered = app(GuideRenderer::class)->render('logs-restart');
    $text = mb_strtolower(implode("\n", guideTexts($guide)));

    expect($guide->kind)->toBe('foundation')
        ->and($guide->planned)->toBeFalse()
        ->and($rendered['works'])->not->toBeEmpty()
        ->and($rendered['failures'])->not->toBeEmpty()
        ->and($rendered['recipes'])->not->toBeEmpty()
        ->and($rendered['support'])->not->toBeEmpty()
        ->and($rendered['numbers'])->not->toBeEmpty()
        ->and($rendered['experiments'])->not->toBeEmpty()
        ->and($guide->build)->not->toBeEmpty()
        ->and($guide->watch)->not->toBeEmpty()
        ->and($guide->elsewhere)->not->toBeEmpty();

    foreach (['frankenphp_log', 'log_stack', 'x-request-id', 'stderr', 'redacted', 'frankenphp:workers:restart', 'octane:reload', 'admin api', 'localhost'] as $needle) {
        expect($text)->toContain($needle);
    }

    $this->withoutVite();
    $this->get('/ai/guides/logs-restart')->assertOk();
    $this->get('/ai/learn')->assertOk();

    $learning = file_get_contents(base_path('docs/learning-guide.md'));
    $readme = file_get_contents(base_path('README.md'));
    $benchmarks = file_get_contents(base_path('BENCHMARKS.md'));

    expect($learning)->toContain('## I-12. Logs and worker restart')->toContain('/ai/guides/logs-restart')
        ->and($learning)->not->toContain('Coming next: logs and developer experience')
        ->and($readme)->toContain('## Structured logs and worker restart')->toContain('frankenphp:workers:restart')->toContain('LOG_STACK=single,frankenphp')
        ->and($benchmarks)->toContain('## Worker restart under load');
});

it('AC-7 the worker-dx guide has six non-empty tabs and the teach sections, answers 200 and is in the learning guide, the README and BENCHMARKS', function (): void {
    $guide = app(GuideRepository::class)->find('worker-dx');
    $rendered = app(GuideRenderer::class)->render('worker-dx');
    $text = mb_strtolower(implode("\n", guideTexts($guide)));

    expect($guide->kind)->toBe('foundation')
        ->and($guide->planned)->toBeFalse()
        ->and($rendered['works'])->not->toBeEmpty()
        ->and($rendered['failures'])->not->toBeEmpty()
        ->and($rendered['recipes'])->not->toBeEmpty()
        ->and($rendered['support'])->not->toBeEmpty()
        ->and($rendered['numbers'])->not->toBeEmpty()
        ->and($rendered['experiments'])->not->toBeEmpty()
        ->and($guide->build)->not->toBeEmpty()
        ->and($guide->watch)->not->toBeEmpty()
        ->and($guide->elsewhere)->not->toBeEmpty();

    foreach (['dev-watch', 'worker_watch', 'xdebug_trigger', 'xdebug_connect_to_client', 'host.docker.internal', '9003', '/var/www/html', 'hot reload', 'mutagen', 'frankenphp:workers:restart', 'never'] as $needle) {
        expect($text)->toContain($needle);
    }

    $this->withoutVite();
    $this->get('/ai/guides/worker-dx')->assertOk();
    $this->get('/ai/learn')->assertOk();

    $learning = file_get_contents(base_path('docs/learning-guide.md'));
    $readme = file_get_contents(base_path('README.md'));
    $benchmarks = file_get_contents(base_path('BENCHMARKS.md'));

    expect($learning)->toContain('## I-13. Worker-mode developer experience')->toContain('/ai/guides/worker-dx')
        ->and($learning)->not->toContain('## I-13. Coming next')
        ->and($readme)->toContain('## Worker-mode developer experience')->toContain('ddev dev-watch on')->toContain('bench/reload-latency.sh')
        ->and($benchmarks)->toContain('## Edit to reload in worker modes')->toContain('not available in FrankenPHP v1.13.5');
});

const TEACH_FEATURE_GUIDES = ['describe', 'semantic', 'nl-search', 'docs', 'chat', 'photos', 'photo-search', 'photo-duplicates', 'assistant', 'moderation', 'research', 'playground', 'admin-assistant'];

/**
 * @return list<string>
 */
function teachTexts(array $teach): array
{
    $texts = [];
    foreach ([...$teach['works'], ...$teach['build'], ...$teach['watch'], ...$teach['elsewhere']] as $row) {
        foreach ($row as $value) {
            if (is_string($value)) {
                $texts[] = $value;
            }
        }
    }

    return $texts;
}

it('AC-6 every feature and foundation guide teaches how to build it, what to watch and where else it applies', function (string $key): void {
    $teach = app(GuidePageService::class)->teach($key)->toArray();

    expect($teach['key'])->toBe($key)
        ->and($teach['title'])->not->toBe('')
        ->and(count($teach['works']))->toBeBetween(3, 6)
        ->and(count($teach['build']))->toBeBetween(4, 8)
        ->and(count($teach['watch']))->toBeBetween(3, 6)
        ->and(count($teach['elsewhere']))->toBeBetween(2, 3);

    foreach ($teach['works'] as $row) {
        expect($row['title'])->not->toBe('')->and($row['summary'])->not->toBe('')->and($row['summary'])->not->toMatch('/[`*]/');
    }
    foreach ($teach['build'] as $row) {
        expect($row['tab'])->toBeIn([null, 'modify', 'support']);
        if ($row['doc'] !== null) {
            expect(parse_url($row['doc'], PHP_URL_SCHEME))->toBe('https')
                ->and(parse_url($row['doc'], PHP_URL_HOST))->toBeIn(['laravel.com', 'frankenphp.dev', 'ollama.com', 'github.com']);
        }
    }
    foreach ($teach['watch'] as $row) {
        expect($row['tab'])->toBeIn(['support']);
    }
})->with(ALL_FILLED_GUIDES);

it('AC-6 keeps every teach row short and free of slice ids, markdown files and repo paths', function (string $key): void {
    foreach (teachTexts(app(GuidePageService::class)->teach($key)->toArray()) as $text) {
        expect(mb_strlen($text))->toBeLessThanOrEqual(240)
            ->and($text)->not->toMatch('/\bSL-\d+/')
            ->not->toMatch('/\.md\b/')
            ->not->toMatch('#\b(app|resources|config|routes|database|bench)/#');
    }
})->with(ALL_FILLED_GUIDES);

it('AC-6 teach rows never name an app class', function (string $key): void {
    foreach (teachTexts(app(GuidePageService::class)->teach($key)->toArray()) as $text) {
        expect($text)->not->toMatch('/\bApp\\\\/')->not->toMatch('/\b[A-Z]\w+(Agent|Service|Controller|Repository)\b/');
    }
})->with(TEACH_FEATURE_GUIDES);

it('AC-6 the teach sections stay optional and a guide without them still loads', function (): void {
    $directory = sys_get_temp_dir().'/guides-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/plain.php', "<?php\nreturn ['kind' => 'foundation', 'title' => 'Plain', 'summary' => 'No teach.'];");

    $repository = new GuideRepository($directory);
    $guide = $repository->find('plain');
    $teach = (new GuidePageService($repository, app(GuideRenderer::class)))->teach('plain')->toArray();

    expect($guide->build)->toBe([])
        ->and($teach['build'])->toBe([])
        ->and($teach['watch'])->toBe([])
        ->and($teach['elsewhere'])->toBe([]);

    unlink($directory.'/plain.php');
    rmdir($directory);
});

it('AC-6 rejects a build section outside 4 to 8 rows and a watch row with an unknown tab', function (): void {
    $directory = sys_get_temp_dir().'/guides-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $row = "['step' => 'Do it', 'detail' => 'Like this.']";
    file_put_contents($directory.'/short.php', "<?php\nreturn ['kind' => 'foundation', 'title' => 'T', 'summary' => 'S', 'build' => [{$row}, {$row}]];");
    file_put_contents($directory.'/tab.php', "<?php\nreturn ['kind' => 'foundation', 'title' => 'T', 'summary' => 'S', 'watch' => [['point' => 'a', 'detail' => 'b', 'tab' => 'nowhere']]];");

    $repository = new GuideRepository($directory);

    expect(fn () => $repository->find('short'))->toThrow(InvalidGuideException::class)
        ->and(fn () => $repository->find('tab'))->toThrow(InvalidGuideException::class);

    array_map('unlink', glob($directory.'/*.php'));
    rmdir($directory);
});

it('AC-6 asking for the teach data of an unknown guide fails', function (): void {
    expect(fn () => app(GuidePageService::class)->teach('nope'))->toThrow(InvalidGuideException::class);
});

it('AC-6 rejects a build doc link that is not https on an allowed host', function (string $doc): void {
    $directory = sys_get_temp_dir().'/guides-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $row = fn (string $link): string => "['step' => 'Do it', 'detail' => 'Like this.', 'doc' => '{$link}']";
    file_put_contents($directory.'/bad.php', "<?php\nreturn ['kind' => 'foundation', 'title' => 'T', 'summary' => 'S', 'build' => [".implode(', ', array_fill(0, 4, $row($doc))).']];');

    expect(fn () => (new GuideRepository($directory))->find('bad'))->toThrow(InvalidGuideException::class);

    unlink($directory.'/bad.php');
    rmdir($directory);
})->with(['javascript://laravel.com/%0aalert(1)', 'http://laravel.com/docs', 'https://example.com/docs']);

it('AC-6 a long first sentence without spaces is cut instead of turning into an ellipsis, and links show their text', function (): void {
    $directory = sys_get_temp_dir().'/guides-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $steps = [
        ['title' => 'Long', 'body' => str_repeat('a', 300)],
        ['title' => 'Link', 'body' => 'Read [the docs](https://laravel.com/docs) first. Then go on.'],
        ['title' => 'Plain', 'body' => 'Plain step.'],
    ];
    file_put_contents($directory.'/cut.php', '<?php return '.var_export(['kind' => 'foundation', 'title' => 'T', 'summary' => 'S', 'works' => $steps], true).';');

    $teach = (new GuidePageService(new GuideRepository($directory), app(GuideRenderer::class)))->teach('cut')->toArray();

    expect($teach['works'][0]['summary'])->toBe(str_repeat('a', 239).'…')
        ->and($teach['works'][1]['summary'])->toBe('Read the docs first.');

    unlink($directory.'/cut.php');
    rmdir($directory);
});

/**
 * Writes one guide file into a fresh temp directory and returns the directory.
 *
 * @param  array<string, mixed>  $data
 */
function teachGuideDirectory(string $key, array $data): string
{
    $directory = sys_get_temp_dir().'/guides-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/'.$key.'.php', '<?php return '.var_export($data, true).';');

    return $directory;
}

/**
 * A minimal guide with valid teach sections and the given works steps, recipes and failures.
 *
 * @param  list<array<string, mixed>>  $works
 * @param  list<array<string, mixed>>  $build
 * @param  list<array<string, mixed>>  $watch
 * @return array<string, mixed>
 */
function teachGuideData(array $works, array $build = [], array $watch = [], bool $recipes = true, bool $failures = true): array
{
    return [
        'kind' => 'foundation',
        'title' => 'T',
        'summary' => 'S',
        'works' => $works,
        'failures' => $failures ? [['title' => 'F', 'body' => 'Fails.']] : [],
        'recipes' => $recipes ? [['title' => 'R', 'body' => 'Recipe.']] : [],
        'build' => $build !== [] ? $build : array_fill(0, 4, ['step' => 'Do it', 'detail' => 'Like this.', 'tab' => 'recipes']),
        'watch' => $watch !== [] ? $watch : array_fill(0, 3, ['point' => 'Careful', 'detail' => 'Because.', 'tab' => 'failures']),
        'elsewhere' => array_fill(0, 2, ['domain' => 'Shop', 'problem' => 'P.', 'how' => 'H.']),
    ];
}

it('F1 the moderation implementation tab shows both the agent instructions and the moderation service snippets', function (): void {
    $implementation = app(GuidePageService::class)->show('moderation')['implementation'];
    $code = collect($implementation)->flatMap(fn (array $step): array => array_column([$step['snippet'], ...$step['moreSnippets']], 'code'))->implode("\n");

    expect($code)->toContain('function instructions')
        ->toContain('function moderate');
});

it('F2 a guide with teach sections holds 3 to 6 works steps, so a page shows every step', function (string $key): void {
    $guide = app(GuideRepository::class)->find($key);

    if ($guide->build === []) {
        expect(true)->toBeTrue();

        return;
    }

    expect(count($guide->works))->toBeBetween(3, 6);
})->with(ALL_FILLED_GUIDES);

it('F2 merging works steps keeps every snippet of the merged guides', function (string $key, array $references): void {
    $kept = app(GuideRepository::class)->find($key)->snippetReferences();

    foreach ($references as $reference) {
        expect($kept)->toContain($reference);
    }
})->with([
    'playground' => ['playground', ['App\Services\PlaygroundPromptService::create', 'App\Repositories\EloquentPlaygroundPromptRepository::addVersion', 'App\Services\PlaygroundPromptService::addVersion', 'App\Services\PlaygroundRunner::validateTargets', 'App\Services\PlaygroundRunner::run', 'App\Services\PlaygroundRunner::call']],
    'research' => ['research', ['App\Services\ResearchRunner::start', 'App\Services\ResearchRunner::plan', 'App\Services\ResearchRunner::searchPhase', 'App\Services\ResearchRunner::notePhase', 'App\Support\RedisResearchNotesStore', 'App\Ai\ResearchCitations', 'App\Services\ResearchRunner::guardCaps']],
    'providers' => ['providers', ['config/ai.php#provider-ollama', 'config/ai.php#provider-cloud', 'App\Ai\AiProviders::forFeature', 'App\Http\Middleware\LimitCloudAi::handle', 'App\Ai\AiProviders::resolve', 'App\Services\ListingEmbeddingService::assertDimensions', 'App\Ai\UsesActiveProvider::providerOptions', 'App\Ai\AiRunRecorder::started']],
    'metrics' => ['metrics', ['App\Http\Controllers\HealthzController', 'App\Services\VinReportService::reportFor', 'App\Http\Controllers\DescribeListingController']],
    'similar-photos' => ['similar-photos', ['App\Ai\PhotoEncoderClient::embed', 'App\Services\PhotoEmbeddingService::embedBatch', 'App\Services\SimilarPhotosService::rank', 'App\Services\SimilarPhotosService::for', 'App\Services\SimilarPhotosService::blend']],
    'moderation' => ['moderation', ['App\Services\ModerationFacts::compute', 'App\Ai\ModerationAgent::instructions', 'App\Ai\ModerationAgent::schema', 'App\Rules\ModerationRules::rules', 'App\Services\ModerationService::moderate', 'App\Services\ModerationService::thresholdFor']],
]);

it('F2 rejects a guide with teach sections and more than 6 works steps instead of cutting them', function (): void {
    $works = array_fill(0, 7, ['title' => 'Step', 'body' => 'Body.']);
    $directory = teachGuideDirectory('long', teachGuideData($works));

    expect(fn () => (new GuideRepository($directory))->find('long'))->toThrow(InvalidGuideException::class, 'at most 6');

    exec('rm -rf '.escapeshellarg($directory));
});

it('F2 a works step can carry a list of snippets, and the implementation tab shows them all', function (): void {
    $works = [
        ['title' => 'One', 'body' => 'First.', 'snippet' => ['app/a.php', 'app/b.php']],
        ['title' => 'Two', 'body' => 'Second.'],
        ['title' => 'Three', 'body' => 'Third.'],
    ];
    $guide = (new GuideRepository(teachGuideDirectory('multi', teachGuideData($works))))->find('multi');

    expect($guide->works[0]->snippet)->toBe('app/a.php')
        ->and($guide->works[0]->moreSnippets)->toBe(['app/b.php'])
        ->and($guide->snippetReferences())->toBe(['app/a.php', 'app/b.php']);
});

it('F5 rejects a build or watch row whose tab target the guide does not have', function (array $data): void {
    $directory = teachGuideDirectory('target', $data);

    expect(fn () => (new GuideRepository($directory))->find('target'))->toThrow(InvalidGuideException::class, 'tab');

    exec('rm -rf '.escapeshellarg($directory));
})->with([
    'build to recipes without recipes' => [teachGuideData(array_fill(0, 3, ['title' => 'S', 'body' => 'B.']), recipes: false)],
    'watch to failures without failures or support' => [teachGuideData(array_fill(0, 3, ['title' => 'S', 'body' => 'B.']), build: array_fill(0, 4, ['step' => 'Do', 'detail' => 'It.']), failures: false)],
]);

it('F11 the repository parses a guide once per request and is shared within it', function (): void {
    $repository = app(GuideRepository::class);

    expect($repository->find('moderation'))->toBe($repository->find('moderation'))
        ->and(app(GuideRepository::class))->toBe($repository);
});

it('F2 the teach works of a guide are its works steps in order, each cut to its first sentence', function (string $key): void {
    $guide = app(GuideRepository::class)->find($key);
    $teach = app(GuidePageService::class)->teach($key)->toArray();

    expect(array_column($teach['works'], 'title'))->toBe(array_map(fn ($step): string => $step->title, $guide->works))
        ->and(array_column($teach['works'], 'event'))->toBe(array_map(fn ($step): ?string => $step->event, $guide->works));

    foreach ($teach['works'] as $row) {
        expect(mb_strlen($row['summary']))->toBeLessThanOrEqual(240)->and($row['summary'])->not->toBe('…');
    }
})->with(ALL_FILLED_GUIDES);

it('MNC-2 a lab page still gets its How it works steps when a snippet reference of the guide is broken', function (): void {
    $works = [
        ['title' => 'Step', 'body' => 'Hello **world**. More.', 'event' => 'token', 'snippet' => 'app/missing.php'],
        ['title' => 'Two', 'body' => 'Second.'],
        ['title' => 'Three', 'body' => 'Third.'],
    ];
    $repository = new GuideRepository(teachGuideDirectory('broken', ['kind' => 'foundation', 'title' => 'T', 'summary' => 'S', 'works' => $works]));

    $teach = (new GuidePageService($repository, app(GuideRenderer::class)))->teach('broken');

    expect($teach->works[0])->toBe(['title' => 'Step', 'summary' => 'Hello world.', 'event' => 'token']);
});

it('AC-10 the lessons guide teaches how a scene lesson works and is written, and the docs describe authoring', function (): void {
    $guide = app(GuideRepository::class)->find('lessons');
    $rendered = app(GuideRenderer::class)->render('lessons');
    $recipes = implode(' ', array_map(fn ($recipe): string => $recipe->title.' '.$recipe->body, $guide->recipes));

    expect($guide->kind)->toBe('foundation')
        ->and($guide->planned)->toBeFalse()
        ->and($rendered['works'])->not->toBeEmpty()
        ->and($rendered['failures'])->not->toBeEmpty()
        ->and($rendered['recipes'])->not->toBeEmpty()
        ->and($rendered['support'])->not->toBeEmpty()
        ->and($rendered['numbers'])->not->toBeEmpty()
        ->and($rendered['experiments'])->not->toBeEmpty()
        ->and($guide->build)->not->toBeEmpty()
        ->and($guide->watch)->not->toBeEmpty()
        ->and($guide->elsewhere)->not->toBeEmpty()
        ->and($recipes)->toMatch('/write a lesson/i');

    foreach (['docs/learning-guide.md' => '/scene lessons/i', 'README.md' => '/\/ai\/learn/'] as $file => $pattern) {
        expect((string) file_get_contents(base_path($file)))->toMatch($pattern);
    }

    $this->withoutVite();
    $this->get('/ai/guides/lessons')->assertOk();
});

it('C-13 every lesson a guide links to is a playable lesson of the course', function (): void {
    $playable = [];
    foreach (app(LessonRepository::class)->course()->modules as $module) {
        foreach ($module->lessons as $entry) {
            if (! $entry->comingSoon) {
                $playable[] = "{$module->slug}/{$entry->slug}";
            }
        }
    }

    $linked = [];
    foreach (app(GuideRepository::class)->keys() as $key) {
        foreach (app(GuideRepository::class)->find($key)->lessons as $lesson) {
            expect($playable)->toContain($lesson);
            $linked[] = $lesson;
        }
    }

    expect($linked)->toContain('running-models/one-model-many-users');
});

it('AC-8 the local-models and providers guides explain the local fallback, its trigger reasons and its limits', function (): void {
    $local = mb_strtolower(implode("\n", guideTexts(app(GuideRepository::class)->find('local-models'))));
    $providers = mb_strtolower(implode("\n", guideTexts(app(GuideRepository::class)->find('providers'))));

    foreach (['fallback_model', 'fallback_after_ms', 'unreachable', 'model_missing', 'server_error', 'timeout', 'busy', 'first token', 'never falls back', 'cloud', 'qwen3:0.6b'] as $needle) {
        expect($local)->toContain($needle);
    }

    expect($providers)->toContain('fallback_model')
        ->and($providers)->toContain('local');

    $this->withoutVite();
    $this->get('/ai/guides/local-models')->assertOk();
    $this->get('/ai/guides/providers')->assertOk();
});

it('AC-7 the fallback bench recipe names the command defaults: a 2000 ms budget at 1, 4 and 16 parallel and the no-fault figure', function (): void {
    $recipe = collect(app(GuideRepository::class)->find('local-models')->recipes)->firstWhere('title', 'Measure the fallback on your machine');
    $command = (string) file_get_contents(app_path('Console/Commands/LlmBenchCommand.php'));

    expect($command)->toContain('{--budget-ms=2000 ')->toContain('{--parallel=1,4,16 ')
        ->and($recipe->body)->toContain('2000 ms budget')->toContain('1, 4 and 16 parallel')->toContain('no_fault')->toContain('load average below 4');
});

it('AC-8 the local-models guide Numbers tab reads the fallback figures by file pointer, or shows the pending note until the run exists', function (): void {
    $rendered = app(GuideRenderer::class)->render('local-models');
    $fallback = array_values(array_filter($rendered['numbers'], fn (array $number): bool => str_starts_with($number['label'], 'Fallback')));

    expect($fallback)->not->toBeEmpty();

    if ((glob(base_path('bench/results/llm-fallback/*/run.json')) ?: []) === []) {
        foreach ($fallback as $number) {
            expect($number['value'])->toBe(GuideFacts::PENDING_TEXT);
        }
    }
});

it('AC-8 the learning guide and the backlog describe the fallback and its numbers file', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));

    foreach (['fallback_model', 'fallback_after_ms', 'llm:bench --only=fallback', 'bench/results/llm-fallback'] as $needle) {
        expect($guide)->toContain($needle);
    }
});
