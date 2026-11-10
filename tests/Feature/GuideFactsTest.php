<?php

declare(strict_types=1);

use App\Guides\GuideFacts;
use App\Guides\GuideRenderer;
use App\Guides\GuideRepository;
use App\Guides\InvalidGuideException;
use App\Guides\SnippetResolver;
use Illuminate\Support\Facades\Schema;

function factsGuide(array $numbers): string
{
    return "<?php\nreturn ".var_export([
        'kind' => 'foundation',
        'title' => 'Facts',
        'summary' => 'Sum',
        'works' => [['title' => 'Step', 'body' => 'Body']],
        'numbers' => $numbers,
    ], true).";\n";
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/guide-facts-'.uniqid();
    mkdir($this->root.'/resources/guides', 0777, true);
    mkdir($this->root.'/bench/results/run', 0777, true);
    file_put_contents($this->root.'/bench/results/run/summary.json', json_encode(['p95' => ['ms' => 42]]));
    foreach (['providers', 'run-log'] as $key) {
        file_put_contents($this->root.'/resources/guides/'.$key.'.php', factsGuide([['label' => 'Later', 'pending' => true]]));
    }
    $this->render = function (string $key): array {
        $this->app->bind(GuideRepository::class, fn () => new GuideRepository($this->root.'/resources/guides'));
        $this->app->bind(SnippetResolver::class, fn () => new SnippetResolver($this->root));
        $this->app->bind(GuideFacts::class, fn () => new GuideFacts($this->root.'/bench/results'));

        return $this->app->make(GuideRenderer::class)->render($key);
    };
});

afterEach(fn () => exec('rm -rf '.escapeshellarg($this->root)));

it('AC-7 renders the configured provider and model after the render cache', function (): void {
    config([
        'ai.default' => 'ollama',
        'ai.providers.ollama.models.text.default' => 'model-a',
        'ai.default_for_embeddings' => 'ollama',
        'ai.providers.ollama.models.embeddings.default' => 'embed-a',
    ]);
    expect(($this->render)('providers')['facts'])->toBe([
        ['label' => 'Provider', 'value' => 'ollama'],
        ['label' => 'Model', 'value' => 'model-a'],
        ['label' => 'Embeddings provider', 'value' => 'ollama'],
        ['label' => 'Embeddings model', 'value' => 'embed-a'],
    ]);

    config(['ai.providers.ollama.models.text.default' => 'model-b']);
    expect(($this->render)('providers')['facts'][1]['value'])->toBe('model-b');
});

it('AC-7 lists the real ai_runs columns on the run-log guide', function (): void {
    expect(($this->render)('run-log')['facts'][0]['value'])
        ->toBe(implode(', ', Schema::getColumnListing('ai_runs')))
        ->toContain('invocation_id');
});

it('AC-2 reads a number by JSON path and shows not measured yet only when pending', function (): void {
    file_put_contents($this->root.'/resources/guides/providers.php', factsGuide([
        ['label' => 'P95', 'file' => 'run/summary.json', 'path' => 'p95.ms'],
        ['label' => 'Later', 'pending' => true],
    ]));
    $numbers = ($this->render)('providers')['numbers'];

    expect($numbers[0]['value'])->toBe('42')->and($numbers[1]['value'])->toBe('not measured yet');
});

it('AC-2 fails on a missing number file, a missing path, or a traversal', function (string $file, string $path): void {
    file_put_contents($this->root.'/resources/guides/providers.php', factsGuide([
        ['label' => 'X', 'file' => $file, 'path' => $path],
    ]));
    ($this->render)('providers');
})->with([
    'missing file' => ['run/none.json', 'p95.ms'],
    'missing path' => ['run/summary.json', 'p99.ms'],
    'traversal' => ['../../resources/guides/providers.php', 'x'],
])->throws(InvalidGuideException::class);
