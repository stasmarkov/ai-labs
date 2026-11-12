<?php

declare(strict_types=1);

use App\Models\AiRun;

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * Blanks the file caption and the verbatim source of every snippet (an array with file, startLine, endLine and
 * code): the caption is the only place a repository path may appear, and source comments are shown as written.
 * Any other key named file, such as a number's result file, is left in and fails.
 *
 * @param  array<array-key, mixed>  $props
 * @return array<array-key, mixed>
 */
function withoutSnippetFiles(array $props): array
{
    if (isset($props['file'], $props['startLine'], $props['endLine'], $props['code'])) {
        $props['file'] = '';
        $props['code'] = '';
    }

    foreach ($props as $key => $value) {
        if (is_array($value)) {
            $props[$key] = withoutSnippetFiles($value);
        }
    }

    return $props;
}

/**
 * @return list<string>
 */
function labUiPaths(): array
{
    $keys = array_map(fn (string $file): string => basename($file, '.php'), glob(dirname(__DIR__, 2).'/resources/guides/*.php') ?: []);

    return [
        '/',
        '/ai',
        '/ai/describe',
        '/ai/semantic',
        '/ai/nl-search',
        '/ai/docs',
        '/ai/chat',
        '/ai/assistant',
        '/ai/moderation',
        '/ai/photos',
        '/ai/photo-search',
        '/ai/photos/duplicates',
        '/ai/guides',
        ...array_map(fn (string $key): string => '/ai/guides/'.$key, $keys),
    ];
}

it('AC-1 no lab page shows a slice id, a markdown file name or a repository path', function (string $path): void {
    $response = $this->get($path)->assertOk();

    $props = json_encode(withoutSnippetFiles($response->viewData('page')['props']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $html = (string) preg_replace('#<script[^>]*data-page[^>]*>.*?</script>|data-page="[^"]*"#s', '', $response->getContent());

    foreach ([$props, $html] as $text) {
        expect($text)->not->toMatch('/SL-\d+/')
            ->not->toMatch('/\.md\b/')
            ->not->toMatch('#\b(?:app|resources|config|routes|database|bench)/#');
    }
})->with(fn (): array => array_combine(labUiPaths(), array_map(fn (string $path): array => [$path], labUiPaths())));

it('AC-1 the run detail route shows no slice id, markdown file name or repository path', function (): void {
    AiRun::create([
        'invocation_id' => 'run-walk',
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
        'error_message' => 'cURL error 7: Failed to connect',
    ]);

    $text = $this->getJson('/ai/runs/run-walk')->assertOk()->getContent();

    expect($text)->not->toMatch('/SL-\d+/')
        ->not->toMatch('/\.md\b/')
        ->not->toMatch('#\b(?:app|resources|config|routes|database)/#');
})->with(['/ai/runs/{invocationId}']);

it('AC-8 home, overview and photos link to guides that exist', function (): void {
    $home = $this->get('/')->viewData('page')['props'];
    $overview = $this->get('/ai')->viewData('page')['props'];
    $photos = $this->get('/ai/photos')->viewData('page')['props'];

    $keys = [
        ...array_column($home['capabilities'], 'guideKey'),
        ...array_column($home['coming'], 'guideKey'),
        ...array_column($overview['capabilities'], 'guideKey'),
    ];

    expect($keys)->not->toBeEmpty();

    foreach (array_unique($keys) as $key) {
        $this->get('/ai/guides/'.$key)->assertOk();
    }

    $this->get($photos['guideHref'])->assertOk();
});

it('AC-8 every capability and coming item carries a guide href and a live route, rendered in a Link', function (): void {
    $home = $this->get('/')->viewData('page')['props'];
    $overview = $this->get('/ai')->viewData('page')['props'];

    $items = [...$home['capabilities'], ...$home['coming'], ...$overview['capabilities']];

    expect($items)->not->toBeEmpty();

    foreach ($items as $item) {
        expect($item['guideHref'])->toBe(route('ai.guides.show', ['key' => $item['guideKey']], false));
        $this->get($item['guideHref'])->assertOk();
        $this->get($item['route'])->assertOk();
    }

    foreach (['Pages/Lab/Overview.vue', 'Pages/Home.vue'] as $page) {
        $source = (string) file_get_contents(resource_path('js/ai/'.$page));

        expect($source)->toMatch('/<Link[^>]*:href="[^"]*guideHref[^"]*"/');
    }
});
