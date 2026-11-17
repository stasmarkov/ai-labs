<?php

declare(strict_types=1);

use App\Ai\PhotoVisionAgent;
use App\DTOs\GuideFormatItem;
use App\Enums\GuideFormatType;
use App\Guides\GuideFormatRepository;
use App\Guides\InvalidGuideException;
use App\Guides\SnippetResolver;
use App\Jobs\ExtractPhotoJob;

const FORMAT_FILES = ['_compare', 'build-along', 'trace', 'quiz', 'debug', 'tour'];

/**
 * Copies the real photos format files to a temporary directory so a test can break one of them.
 */
function formatFixture(): string
{
    $directory = sys_get_temp_dir().'/guide-formats-'.bin2hex(random_bytes(4));
    mkdir($directory.'/photos', 0777, true);
    foreach (FORMAT_FILES as $file) {
        copy(resource_path('guides/formats/photos/'.$file.'.php'), $directory.'/photos/'.$file.'.php');
    }

    return $directory;
}

function formatRepository(string $directory): GuideFormatRepository
{
    return new GuideFormatRepository($directory, new SnippetResolver(base_path()));
}

/**
 * The shipped items of one format as arrays, each with its resolved snippet (or null).
 *
 * @return list<array<string, mixed>>
 */
function shippedItems(GuideFormatType $type): array
{
    $file = formatRepository(resource_path('guides/formats'))->format('photos', $type);

    return array_map(fn (GuideFormatItem $item): array => [
        ...$item->toArray(),
        'snippet' => $item->snippet === null ? null : $file->snippets[$item->snippet],
    ], $file->items);
}

/**
 * Every it() title under tests/.
 *
 * @return list<string>
 */
function testTitles(): array
{
    $titles = [];
    foreach (File::allFiles(base_path('tests')) as $file) {
        if ($file->getExtension() === 'php' && preg_match_all("/\\bit\\('((?:[^'\\\\]|\\\\.)+)'/", (string) file_get_contents($file->getPathname()), $matches) > 0) {
            array_push($titles, ...array_map(stripslashes(...), $matches[1]));
        }
    }

    return $titles;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/guide-formats-*') ?: [] as $directory) {
        foreach (glob($directory.'/photos/*.php') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($directory.'/photos');
        @rmdir($directory);
    }
});

it('AC-4 the shipped format files load', function (): void {
    $repository = formatRepository(resource_path('guides/formats'));

    expect($repository->keys())->toBe(['photos'])
        ->and($repository->has('photos'))->toBeTrue()
        ->and($repository->has('describe'))->toBeFalse()
        ->and($repository->compare('photos')->entries)->toHaveCount(5);

    foreach (GuideFormatType::cases() as $type) {
        expect($repository->format('photos', $type)->items)->not->toBeEmpty();
    }
});

it('AC-3 a folder without format files is unknown and a partial set is not a format set', function (): void {
    $directory = formatFixture();
    foreach (glob($directory.'/photos/*.php') ?: [] as $file) {
        unlink($file);
    }
    expect(formatRepository($directory)->has('photos'))->toBeFalse()
        ->and(formatRepository($directory)->keys())->toBe([]);

    copy(resource_path('guides/formats/photos/_compare.php'), $directory.'/photos/_compare.php');
    $repository = formatRepository($directory);

    expect($repository->has('photos'))->toBeFalse()
        ->and($repository->keys())->toBe([])
        ->and(fn () => $repository->format('photos', GuideFormatType::Quiz))->toThrow(InvalidGuideException::class, 'missing the file');
});

it('AC-4 a broken format file names the file and field', function (string $file, string $search, string $replace, string $needle): void {
    $directory = formatFixture();
    $path = $directory.'/photos/'.$file.'.php';
    file_put_contents($path, str_replace($search, $replace, (string) file_get_contents($path), $count));
    expect($count)->toBeGreaterThan(0);

    expect(fn () => formatRepository($directory)->format('photos', GuideFormatType::from($file)))
        ->toThrow(InvalidGuideException::class, $needle);
})->with([
    'missing field' => ['build-along', "'expected' =>", "'expectedx' =>", 'steps.0.expected'],
    'unknown outcome' => ['quiz', "'LO-1'", "'LO-9'", 'LO-9'],
    'unresolvable snippet' => ['tour', 'App\Ai\PhotoVisionAgent::schema', 'App\Ai\Missing::schema', '.snippet'],
]);

it('AC-4 an error names the file', function (): void {
    $directory = formatFixture();
    $path = $directory.'/photos/trace.php';
    file_put_contents($path, str_replace("'stages' =>", "'stagez' =>", (string) file_get_contents($path)));

    expect(fn () => formatRepository($directory)->format('photos', GuideFormatType::Trace))
        ->toThrow(InvalidGuideException::class, 'photos/trace.php');
});

it('AC-4 a key with only some of the six files is an invalid set', function (): void {
    $directory = formatFixture();
    unlink($directory.'/photos/debug.php');

    expect(fn () => formatRepository($directory)->compare('photos'))
        ->toThrow(InvalidGuideException::class, 'debug');
});

it('AC-5 every format covers every learning outcome', function (GuideFormatType $type): void {
    $items = formatRepository(resource_path('guides/formats'))->items('photos', $type);

    foreach (GuideFormatRepository::OUTCOMES as $outcome) {
        expect(collect($items)->contains(fn (GuideFormatItem $item): bool => in_array($outcome, $item->outcomes, true)))
            ->toBeTrue("{$type->value} teaches no {$outcome}");
    }
})->with(GuideFormatType::cases());

it('AC-6 every build-along step has a goal, code, command and expected result', function (): void {
    $steps = shippedItems(GuideFormatType::BuildAlong);

    expect($steps)->not->toBeEmpty();

    foreach ($steps as $step) {
        expect($step['goal'])->not->toBe('')
            ->and($step['snippet']['code'])->not->toBe('')
            ->and($step['snippet']['startLine'])->toBeGreaterThan(0)
            ->and($step['command'])->toStartWith('ddev artisan ')
            ->and($step['expected'])->not->toBe('');
    }
});

it('AC-8 the trace stages follow the pipeline order and each has sample data and code', function (): void {
    $stages = shippedItems(GuideFormatType::Trace);

    expect(array_column($stages, 'id'))->toBe(['command', 'queue', 'job', 'agent', 'model-server', 'schema', 'php-rules', 'row']);

    foreach ($stages as $stage) {
        expect($stage['what'])->not->toBe('')
            ->and($stage['sample'])->not->toBe('')
            ->and($stage['snippet']['code'])->not->toBe('')
            ->and($stage['snippet']['startLine'])->toBeGreaterThan(0);
    }
});

it('AC-8 the trace samples use the command output and the prompt version format of the code', function (): void {
    $stages = collect(shippedItems(GuideFormatType::Trace))->keyBy('id');
    $promptVersion = (new PhotoVisionAgent)->promptVersion();

    expect($promptVersion)->toBe('photo-vision@v1')
        ->and($stages['command']['sample'])->toMatch('/^Queued \d+ photos on '.preg_quote(ExtractPhotoJob::QUEUE, '/').' for \S+ \('.preg_quote($promptVersion, '/').'\); \d+ skipped\.$/m')
        ->and($stages['command']['sample'])->toContain('Run a worker: ddev artisan queue:work --queue='.ExtractPhotoJob::QUEUE)
        ->and($stages['agent']['sample'])->toContain($promptVersion);

    foreach ($stages as $stage) {
        if (preg_match_all('/prompt[ _]version:\s*(\S+)/', $stage['sample'], $versions) > 0) {
            expect($versions[1])->each->toBe($promptVersion);
        }
    }
});

it('AC-7 the quiz has 8 to 10 questions, each with one correct option, an explanation and code', function (): void {
    $questions = shippedItems(GuideFormatType::Quiz);

    expect(count($questions))->toBeGreaterThanOrEqual(8)->toBeLessThanOrEqual(10);

    foreach ($questions as $question) {
        expect($question['options'])->toBeArray()->not->toBeEmpty()
            ->and($question['correct'])->toBeInt()->toBeGreaterThanOrEqual(0)->toBeLessThan(count($question['options']))
            ->and($question['explanation'])->not->toBe('')
            ->and($question['snippet']['code'])->not->toBe('');
    }
});

it('AC-9 the debug format has 4 or 5 scenarios, each with a symptom, clues, one correct cause, a fix and a test', function (): void {
    $scenarios = shippedItems(GuideFormatType::Debug);

    expect(count($scenarios))->toBeGreaterThanOrEqual(4)->toBeLessThanOrEqual(5);

    foreach ($scenarios as $scenario) {
        expect($scenario['symptom'])->not->toBe('')
            ->and(count($scenario['clues']))->toBeGreaterThanOrEqual(2)
            ->and(count($scenario['causes']))->toBeGreaterThanOrEqual(2)
            ->and($scenario['correct'])->toBeInt()->toBeGreaterThanOrEqual(0)->toBeLessThan(count($scenario['causes']))
            ->and($scenario['fix'])->not->toBe('')
            ->and($scenario['test'])->not->toBe('');

        foreach ($scenario['causes'] as $cause) {
            expect($cause['why'])->not->toBe('');
        }
    }
});

it('AC-9 the skip scenario shows the command output and the query that decides what to skip', function (): void {
    $scenario = collect(shippedItems(GuideFormatType::Debug))->firstWhere('id', 'old-model');

    $code = html_entity_decode($scenario['snippet']['code'], ENT_QUOTES);

    expect($scenario['symptom'])->toMatch('/^No photos to extract; \d+ already have an extraction for \S+ \(photo-vision@v1\)\.$/')
        ->and($code)->toContain('function pendingPhotoIds')
        ->and($code)->toContain("'photo_extractions.provider'")
        ->and($code)->toContain("'photo_extractions.model'")
        ->and($code)->toContain("'photo_extractions.prompt_version'");
});

it('AC-10 every tour stop resolves to real source lines and each note starts on a line of code', function (): void {
    $stops = shippedItems(GuideFormatType::Tour);

    expect($stops)->not->toBeEmpty();

    foreach ($stops as $stop) {
        $snippet = $stop['snippet'];
        $lines = explode("\n", html_entity_decode($snippet['code']));

        expect($snippet['code'])->not->toBe('')
            ->and($snippet['startLine'])->toBeGreaterThan(0)
            ->and($snippet['endLine'])->toBeGreaterThanOrEqual($snippet['startLine'])
            ->and($snippet['file'])->not->toStartWith('/')
            ->and($snippet['file'])->not->toContain(base_path())
            ->and($stop['notes'])->not->toBeEmpty();

        foreach ($stop['notes'] as $note) {
            $first = trim($lines[$note['from'] - 1]);

            expect($note['from'])->toBeGreaterThanOrEqual(1)
                ->and($note['to'])->toBeGreaterThanOrEqual($note['from'])
                ->and($note['to'])->toBeLessThanOrEqual(count($lines))
                ->and($note['text'])->not->toBe('')
                ->and($first)->not->toMatch('#^(?:$|/\*|\*|//|[{}()\[\];,]+$)#', "{$stop['id']}: a note starts on [{$first}]");
        }
    }
});

it('AC-10 a tour note that points at a docblock, a brace or a blank line is a load error', function (string $search, string $replace): void {
    $directory = formatFixture();
    $path = $directory.'/photos/tour.php';
    file_put_contents($path, preg_replace($search, $replace, (string) file_get_contents($path), 1, $count));
    expect($count)->toBe(1);

    expect(fn () => formatRepository($directory)->format('photos', GuideFormatType::Tour))
        ->toThrow(InvalidGuideException::class, 'stops.0.notes.0');
})->with([
    'docblock or signature brace' => ["/\['from' => \d+, 'to' => \d+,/", "['from' => 2, 'to' => 2,"],
    'past the end' => ["/\['from' => \d+, 'to' => \d+,/", "['from' => 1, 'to' => 999,"],
]);

it('AC-8 NFR-1 the trace page steps every 2.5 s, moves from the focused stage with the arrows and labels the sample as an example', function (): void {
    $source = (string) file_get_contents(resource_path('js/ai/Pages/Lab/GuideFormat/Trace.vue'));

    expect($source)->toContain('const STEP_MS = 2500;')
        ->and($source)->toContain('@keydown.left.prevent')
        ->and($source)->toContain('@keydown.right.prevent')
        ->and($source)->toContain(':data-stage="index"')
        ->and($source)->toContain('document.activeElement')
        ->and($source)->not->toContain('ref="stageButtons"')
        ->and($source)->not->toMatch('/:ref="\(/')
        ->and($source)->toContain('Example data at this stage');
});

it('AC-9 NFR-1 the debug page locks the cause until a clue is shown, offers Show answer and labels the symptom as an example', function (): void {
    $source = file_get_contents(resource_path('js/ai/Pages/Lab/GuideFormat/Debug.vue'));

    expect($source)
        ->toContain('shown(scenario) === 0')
        ->toContain('Show answer')
        ->toContain('revealed')
        ->toContain('What you see (example)');
});

it('AC-2 AC-6 every build-along test command filters on a real test title or test file', function (): void {
    $commands = collect(shippedItems(GuideFormatType::BuildAlong))
        ->pluck('command')
        ->filter(fn (string $command): bool => str_contains($command, 'artisan test'));

    expect($commands)->not->toBeEmpty();

    $titles = testTitles();
    $files = collect(File::allFiles(base_path('tests')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php' && str_ends_with($file->getBasename(), 'Test.php'))
        ->map(fn (SplFileInfo $file): string => $file->getBasename('.php'))
        ->reject(fn (string $name): bool => $name === 'GuideFormatContentTest');

    foreach ($commands as $command) {
        expect(preg_match('/--filter=(?:"([^"]+)"|(\S+))/', $command, $match))->toBe(1, "[{$command}] has no --filter");
        $filter = $match[1] !== '' ? $match[1] : $match[2];

        $matches = $files->contains($filter)
            || collect($titles)->contains(fn (string $title): bool => str_contains($title, $filter));

        expect($matches)->toBeTrue("--filter={$filter} matches no test file or it() title");
    }
});

it('AC-9 every debug preventing test quotes the title of an existing it() test and names no file path', function (): void {
    $titles = testTitles();

    foreach (shippedItems(GuideFormatType::Debug) as $scenario) {
        expect(preg_match('/^"([^"]+)"/', $scenario['test'], $match))->toBe(1, "scenario {$scenario['id']} test quotes no title")
            ->and($scenario['test'])->not->toContain('tests/')->not->toContain('.php');

        expect($titles)->toContain($match[1]);
    }
});
