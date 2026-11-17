<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

function docsLinksFiles(): array
{
    $files = ['README.md', 'BENCHMARKS.md', 'backlog.md'];

    foreach (File::allFiles(base_path('docs')) as $file) {
        if ($file->getExtension() === 'md') {
            $files[] = 'docs/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
        }
    }

    sort($files);

    return $files;
}

function docsLinksRead(string $relative): string
{
    return (string) file_get_contents(base_path($relative));
}

/**
 * Lines of a markdown file with fenced code blocks blanked out, keeping line numbers.
 *
 * @return list<string>
 */
function docsLinksProseLines(string $markdown): array
{
    $inFence = false;
    $lines = [];

    foreach (explode("\n", $markdown) as $line) {
        if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
            $inFence = ! $inFence;
            $lines[] = '';

            continue;
        }

        $lines[] = $inFence ? '' : $line;
    }

    return $lines;
}

function docsLinksSlug(string $heading): string
{
    $text = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $heading) ?? $heading;
    $text = preg_replace('/<[^>]+>/', '', $text) ?? $text;
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^\p{L}\p{N}_ -]/u', '', $text) ?? $text;

    return str_replace(' ', '-', $text);
}

/**
 * @return list<array{level: int, text: string, slug: string, line: int}>
 */
function docsLinksHeadings(string $markdown): array
{
    $seen = [];
    $headings = [];

    foreach (docsLinksProseLines($markdown) as $index => $line) {
        if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $match) !== 1) {
            continue;
        }

        $slug = docsLinksSlug($match[2]);
        $count = $seen[$slug] ?? 0;
        $seen[$slug] = $count + 1;

        $headings[] = [
            'level' => strlen($match[1]),
            'text' => $match[2],
            'slug' => $count === 0 ? $slug : $slug.'-'.$count,
            'line' => $index + 1,
        ];
    }

    return $headings;
}

/**
 * @return list<array{target: string, line: int}>
 */
function docsLinksLinks(string $markdown): array
{
    $links = [];

    foreach (docsLinksProseLines($markdown) as $index => $line) {
        $line = preg_replace('/`[^`]*`/', '', $line) ?? $line;

        if (preg_match_all('/!?\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $line, $matches) < 1) {
            continue;
        }

        foreach ($matches[1] as $target) {
            $links[] = ['target' => trim($target, '<>'), 'line' => $index + 1];
        }
    }

    return $links;
}

function docsLinksResolve(string $fromFile, string $path): string
{
    $base = str_starts_with($path, '/') ? [] : explode('/', dirname($fromFile));
    $parts = $base === ['.'] ? [] : $base;

    foreach (explode('/', ltrim($path, '/')) as $segment) {
        if ($segment === '..') {
            array_pop($parts);
        } elseif ($segment !== '.' && $segment !== '') {
            $parts[] = $segment;
        }
    }

    return implode('/', $parts);
}

/**
 * @return list<string>
 */
function docsLinksErrors(): array
{
    $errors = [];
    $slugs = [];

    foreach (docsLinksFiles() as $file) {
        $slugs[$file] = array_column(docsLinksHeadings(docsLinksRead($file)), 'slug');
    }

    foreach (docsLinksFiles() as $file) {
        foreach (docsLinksLinks(docsLinksRead($file)) as $link) {
            $target = $link['target'];

            if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) === 1) {
                continue;
            }

            $where = "$file:{$link['line']} -> $target";
            [$path, $anchor] = array_pad(explode('#', $target, 2), 2, null);
            $path = rawurldecode($path);
            $resolved = $path === '' ? $file : docsLinksResolve($file, $path);
            $absolute = base_path($resolved);

            if (! file_exists($absolute)) {
                $errors[] = "$where: missing file";

                continue;
            }

            if (str_ends_with($path, '/') && ! is_dir($absolute)) {
                $errors[] = "$where: not a directory";

                continue;
            }

            if ($anchor === null || $anchor === '') {
                continue;
            }

            $anchor = mb_strtolower(rawurldecode($anchor));

            if (is_file($absolute) && preg_match('/^l\d+(-l\d+)?$/', $anchor) === 1) {
                continue;
            }

            if (! is_file($absolute) || ! str_ends_with($resolved, '.md')) {
                $errors[] = "$where: anchor on a non-markdown target";

                continue;
            }

            $slugs[$resolved] ??= array_column(docsLinksHeadings(docsLinksRead($resolved)), 'slug');

            if (! in_array($anchor, $slugs[$resolved], true)) {
                $errors[] = "$where: no heading with that slug";
            }
        }
    }

    return $errors;
}

/**
 * @return array<string, string> the `##` sections of the guide, keyed by heading text
 */
function docsLinksGuideSections(): array
{
    $headings = array_values(array_filter(
        docsLinksHeadings(docsLinksRead('docs/learning-guide.md')),
        fn (array $heading): bool => $heading['level'] <= 2,
    ));
    $lines = explode("\n", docsLinksRead('docs/learning-guide.md'));
    $sections = [];

    foreach ($headings as $index => $heading) {
        if ($heading['level'] !== 2) {
            continue;
        }

        $end = $headings[$index + 1]['line'] ?? count($lines) + 1;
        $sections[$heading['text']] = implode("\n", array_slice($lines, $heading['line'], $end - $heading['line'] - 1));
    }

    return $sections;
}

test('AC-3 every relative link and anchor in the guide, README, BENCHMARKS, backlog and docs resolves', function (): void {
    expect(docsLinksErrors())->toBe([]);
});

test('AC-3 the slug rule matches GitHub for the heading shapes used in the docs', function (): void {
    expect(docsLinksSlug('1. The app and one request'))->toBe('1-the-app-and-one-request')
        ->and(docsLinksSlug('II-3. Early Hints & `compression` — fast'))->toBe('ii-3-early-hints--compression--fast')
        ->and(docsLinksSlug('HTTP/3 and [QUIC](x.md)…'))->toBe('http3-and-quic');

    $headings = docsLinksHeadings("# A\n\n## B\n\n## B\n\n```\n# not a heading\n```\n");

    expect(array_column($headings, 'slug'))->toBe(['a', 'b', 'b-1']);
});

test('AC-3 no bare section number references remain in the covered docs', function (): void {
    $found = [];

    foreach (docsLinksFiles() as $file) {
        foreach (docsLinksProseLines(docsLinksRead($file)) as $index => $line) {
            $line = preg_replace('/`[^`]*`/', '', $line) ?? $line;
            $joinedToLink = preg_match('/\]\(#[^)]*\)\s*(,|and|or|to)\s+\d+\b/i', $line) === 1;
            $line = preg_replace('/\]\([^)]*\)/', ']', $line) ?? $line;

            if ($joinedToLink || preg_match('/§\s*\d|\bsections?\s+\d+\b/i', $line) === 1) {
                $found[] = $file.':'.($index + 1);
            }
        }
    }

    expect($found)->toBe([]);
});

test('AC-1 the guide has two parts and the sections follow the learning order', function (): void {
    $expected = [
        'I' => ['one request', 'run PHP', 'Switching servers', 'Worker mode', 'Measuring fairly', 'Slow I/O', 'send the file', 'Early Hints', 'HTTP/3', 'Mercure', 'Metrics', 'Logs and worker restart', 'Worker-mode developer experience'],
        'II' => ['LLM call', 'Streaming', 'locally', 'providers', 'limits', 'structured output', 'Rating', 'Vision', 'Embeddings', 'Chunking', 'citations', 'Measuring RAG', 'Caching answers', 'Stop', 'Similar', 'Duplicate', 'tool calling', 'tool loop', 'MCP', 'research agent', 'playground', 'lab UI', 'guides', 'map of the code'],
    ];

    $headings = docsLinksHeadings(docsLinksRead('docs/learning-guide.md'));
    $texts = array_column(array_filter($headings, fn (array $heading): bool => $heading['level'] === 1), 'text');

    expect($texts)->toContain('Part I — The server')->toContain('Part II — AI');

    $part = null;
    $actual = ['I' => [], 'II' => []];

    foreach ($headings as $heading) {
        if ($heading['level'] === 1) {
            $part = match ($heading['text']) {
                'Part I — The server' => 'I',
                'Part II — AI' => 'II',
                default => $part,
            };

            continue;
        }

        if ($heading['level'] !== 2) {
            continue;
        }

        if (preg_match('/^(I|II)-(\d+)\. (.+)$/', $heading['text'], $match) === 1) {
            expect($match[1])->toBe($part, "section {$heading['text']} sits under the wrong part");
            $actual[$match[1]][(int) $match[2]] = $match[3];
        }
    }

    foreach ($expected as $partId => $titles) {
        expect(array_keys($actual[$partId]))->toBe(range(1, count($titles)), "Part $partId numbering");

        foreach ($titles as $index => $needle) {
            expect(mb_strtolower($actual[$partId][$index + 1]))->toContain(mb_strtolower($needle));
        }
    }
});

test('AC-1 the table of contents lists every section in order', function (): void {
    $guide = docsLinksRead('docs/learning-guide.md');
    $sections = array_values(array_filter(
        docsLinksHeadings($guide),
        fn (array $heading): bool => $heading['level'] === 2,
    ));

    $contents = array_values(array_filter($sections, fn (array $heading): bool => strcasecmp($heading['text'], 'Contents') === 0));
    expect($contents)->toHaveCount(1);

    $others = array_values(array_filter($sections, fn (array $heading): bool => strcasecmp($heading['text'], 'Contents') !== 0));
    $body = docsLinksGuideSections()['Contents'] ?? '';

    $listed = [];
    foreach (docsLinksLinks($body) as $link) {
        if (str_starts_with($link['target'], '#')) {
            $listed[] = substr($link['target'], 1);
        }
    }

    expect($listed)->toBe(array_column($others, 'slug'));
});

/**
 * Lines of a markdown file with every span between `<!-- history -->` and `<!-- /history -->`
 * removed, keeping line numbers. A span may open and close on one line or run across lines;
 * text before an opening marker and after a closing marker stays.
 *
 * @return array{lines: list<string>, unclosed: bool}
 */
function docsLinksOutsideHistory(string $markdown): array
{
    $inHistory = false;
    $lines = [];

    foreach (explode("\n", $markdown) as $line) {
        $kept = '';
        $rest = $line;

        while ($rest !== '') {
            $marker = $inHistory ? '<!-- /history -->' : '<!-- history -->';
            $position = strpos($rest, $marker);

            if ($position === false) {
                if (! $inHistory) {
                    $kept .= $rest;
                }

                break;
            }

            if (! $inHistory) {
                $kept .= substr($rest, 0, $position);
            }

            $rest = substr($rest, $position + strlen($marker));
            $inHistory = ! $inHistory;
        }

        $lines[] = $kept;
    }

    return ['lines' => $lines, 'unclosed' => $inHistory];
}

/**
 * The italic `*History: …*` label that opens a section measured on removed servers (LearningGuideTest requires it
 * as the first line). It is a history marker in itself, so only that label, at the start of a line, is exempt.
 */
function docsLinksHistoryLabel(): string
{
    return '/^\*History: [^*\n]+\*/';
}

test('AC-4 only text between the history markers is exempt', function (): void {
    $removed = '/nginx-fpm/';
    $flagged = function (string $markdown) use ($removed): array {
        $result = docsLinksOutsideHistory($markdown);

        return array_keys(array_filter($result['lines'], fn (string $line): bool => preg_match($removed, $line) === 1));
    };

    expect($flagged('nginx-fpm runs PHP <!-- history -->nginx-fpm ran PHP<!-- /history -->'))->toBe([0])
        ->and($flagged('<!-- history -->nginx-fpm ran PHP<!-- /history --> and nginx-fpm runs PHP'))->toBe([0])
        ->and($flagged("<!-- history -->\nnginx-fpm ran PHP\n<!-- /history -->\nnginx-fpm runs PHP"))->toBe([3])
        ->and($flagged("Before nginx-fpm <!-- history -->\nnginx-fpm ran PHP\nstill history<!-- /history --> nginx-fpm after"))->toBe([0, 2])
        ->and($flagged('<!-- history -->nginx-fpm ran<!-- /history --> fine <!-- history -->nginx-fpm too<!-- /history -->'))->toBe([])
        ->and(docsLinksOutsideHistory("<!-- history -->\nnever closed")['unclosed'])->toBeTrue()
        ->and(preg_replace(docsLinksHistoryLabel(), '', '*History: measured on nginx-fpm.* nginx-fpm runs PHP'))->toBe(' nginx-fpm runs PHP')
        ->and(preg_replace(docsLinksHistoryLabel(), '', 'Today *History: nginx-fpm.*'))->toBe('Today *History: nginx-fpm.*');
});

test('AC-4 the guide, README and BENCHMARKS present no removed server as current', function (): void {
    $found = [];

    foreach (['docs/learning-guide.md', 'README.md', 'BENCHMARKS.md'] as $file) {
        $result = docsLinksOutsideHistory(docsLinksRead($file));

        if ($result['unclosed']) {
            $found[] = $file.': a history span is never closed';
        }

        foreach ($result['lines'] as $index => $line) {
            $line = preg_replace(docsLinksHistoryLabel(), '', $line) ?? $line;

            if (preg_match('/nginx-fpm|apache-fpm|\bApache\b|OpenLiteSpeed/', $line) === 1) {
                $found[] = $file.':'.($index + 1).': '.trim($line);
            }
        }
    }

    expect($found)->toBe([]);
});

test('AC-4 the guide has no Planned sections list, explains SL-16 in I-12 and SL-17 in I-13 with no reserved section left', function (): void {
    $guide = docsLinksRead('docs/learning-guide.md');

    expect($guide)->not->toContain('## Planned sections')
        ->and($guide)->toMatch('/^## I-12\. Logs and worker restart$/m')
        ->and($guide)->toMatch('/^## I-13\. Worker-mode developer experience$/m')
        ->and($guide)->not->toContain('Reserved for SL-17')
        ->and($guide)->not->toContain('## I-13. Coming next');

    $sections = docsLinksGuideSections();

    expect($sections['I-12. Logs and worker restart'] ?? '')->toContain('frankenphp:workers:restart')
        ->and($sections['I-12. Logs and worker restart'] ?? '')->not->toContain('SL-17')
        ->and($sections['I-13. Worker-mode developer experience'] ?? '')->toContain('ddev dev-watch')->toContain('xdebug');
});

/**
 * The text under the heading with the given slug, up to the next heading of the same or a higher level.
 */
function docsLinksSectionBySlug(string $markdown, string $slug): ?string
{
    $headings = docsLinksHeadings($markdown);
    $lines = explode("\n", $markdown);

    foreach ($headings as $index => $heading) {
        if ($heading['slug'] !== $slug) {
            continue;
        }

        $end = count($lines) + 1;

        foreach (array_slice($headings, $index + 1) as $next) {
            if ($next['level'] <= $heading['level']) {
                $end = $next['line'];

                break;
            }
        }

        return implode("\n", array_slice($lines, $heading['line'], $end - $heading['line'] - 1));
    }

    return null;
}

/**
 * The quoted numbers of a text worth checking against a source: decimals and numbers of 3 or more digits
 * (`10 000` style groups included), backticked or not. Link targets, dates, slice ids and model tags such
 * as `qwen3.5:9b` are skipped, and so are small integers.
 *
 * @return list<string>
 */
function docsLinksQuotedNumbers(string $text): array
{
    $text = preg_replace('/\]\([^)]*\)/', ']', $text) ?? $text;
    $text = preg_replace('/\d{4}-\d{2}-\d{2}|\b(?:SL|BR|AC|NFR|MNC|C)-\d+|\bI{1,2}-\d+|[a-z][\w.-]*:\d+(?:\.\d+)?[a-z]+/i', ' ', $text) ?? $text;

    preg_match_all('/(?<![\w.])\d{1,3}(?: \d{3})+(?:\.\d+)?(?![\w])|(?<![\w.])\d+(?:\.\d+)?(?![\w]|\.\d)/', $text, $matches);

    return array_values(array_unique(array_filter(
        $matches[0],
        fn (string $number): bool => str_contains($number, '.') || strlen(str_replace(' ', '', $number)) >= 3,
    )));
}

function docsLinksMentionsNumber(string $text, string $number): bool
{
    $variants = array_unique([$number, str_replace(' ', '', $number)]);

    foreach ($variants as $variant) {
        if (preg_match('/(?<![\d.])'.str_replace(' ', '[ \x{00A0}]', preg_quote($variant, '/')).'(?!\d|\.\d)/u', $text) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Each headline bullet of the closing summary with the numbers it quotes and the sections it links.
 *
 * @return list<array{bullet: string, numbers: list<string>, guide: list<string>, benchmarks: list<string>}>
 */
function docsLinksHeadlineBullets(string $summary): array
{
    $list = explode('**Headline numbers.**', $summary)[1] ?? '';
    $list = explode('**Read next.**', $list)[0];
    $bullets = [];

    foreach (explode("\n", $list) as $line) {
        if (! str_starts_with($line, '- ')) {
            continue;
        }

        preg_match_all('/\]\(#([^)]+)\)/', $line, $guide);
        preg_match_all('/\]\(\.\.\/BENCHMARKS\.md#([^)]+)\)/', $line, $benchmarks);

        $bullets[] = [
            'bullet' => $line,
            'numbers' => docsLinksQuotedNumbers($line),
            'guide' => $guide[1],
            'benchmarks' => $benchmarks[1],
        ];
    }

    return $bullets;
}

test('AC-6 the number extraction skips ids, dates and small integers', function (): void {
    expect(docsLinksQuotedNumbers('From `64` ms to 2724 ms after 10 000 requests at 0.75 on qwen3.5:9b, SL-44, II-5, 2026-11-13, [x](#ii-5-a-2026-11-13) and 6.3 MB.'))
        ->toBe(['2724', '10 000', '0.75', '6.3'])
        ->and(docsLinksMentionsNumber('grew to 11.3 MB after 10 000 requests', '10 000'))->toBeTrue()
        ->and(docsLinksMentionsNumber('2369 ms', '236'))->toBeFalse()
        ->and(docsLinksMentionsNumber('0.8751', '0.875'))->toBeFalse();
});

test('AC-6 the guide ends with a closing summary', function (): void {
    $guide = docsLinksRead('docs/learning-guide.md');
    $sections = docsLinksGuideSections();

    expect(array_key_last($sections))->toBe('Closing summary');

    $summary = $sections['Closing summary'];

    foreach (['measure before claiming', 'validation boundary', 'marked data', 'caps on every loop', 'logs for every call', 'read next'] as $needle) {
        expect(mb_strtolower($summary))->toContain($needle);
    }

    expect(preg_match_all('/\]\(#(?:i|ii)-\d+-/', $summary))->toBeGreaterThanOrEqual(5)
        ->and($guide)->toMatch('/^\*\*Status \(\d{4}-\d{2}-\d{2}\):\*\*/m');

    expect($summary)->toContain('**Headline numbers.**');

    $bullets = docsLinksHeadlineBullets($summary);
    $benchmarks = docsLinksRead('BENCHMARKS.md');
    $problems = [];

    expect(count($bullets))->toBeGreaterThanOrEqual(3)
        ->and(array_sum(array_map(fn (array $bullet): int => count($bullet['numbers']), $bullets)))->toBeGreaterThanOrEqual(5);

    foreach ($bullets as $bullet) {
        if ($bullet['guide'] === [] || $bullet['benchmarks'] === []) {
            $problems[] = "links no guide section or no BENCHMARKS section: {$bullet['bullet']}";

            continue;
        }

        $sections = array_map(fn (string $slug): string => docsLinksSectionBySlug($guide, $slug) ?? '', $bullet['guide']);
        $sources = array_map(fn (string $slug): string => docsLinksSectionBySlug($benchmarks, $slug) ?? '', $bullet['benchmarks']);

        foreach ($bullet['numbers'] as $number) {
            if (! array_filter($sections, fn (string $section): bool => docsLinksMentionsNumber($section, $number))) {
                $problems[] = "$number is not in the linked guide section";
            }

            if (! array_filter($sources, fn (string $source): bool => docsLinksMentionsNumber($source, $number))) {
                $problems[] = "$number is not in the linked BENCHMARKS section";
            }
        }
    }

    expect($problems)->toBe([]);
});

test('AC-5 every number in the local models section is in its BENCHMARKS source', function (): void {
    $section = docsLinksGuideSections()['II-5. Local models and their limits'] ?? '';
    $source = docsLinksSectionBySlug(docsLinksRead('BENCHMARKS.md'), 'local-models-and-limits-2026-11-13') ?? '';

    expect($section)->not->toBe('')
        ->and($source)->not->toBe('');

    $numbers = docsLinksQuotedNumbers($section);
    expect(count($numbers))->toBeGreaterThanOrEqual(5);

    $missing = array_values(array_filter($numbers, fn (string $number): bool => ! docsLinksMentionsNumber($source, $number)));

    expect($missing)->toBe([]);
});

/**
 * @return list<int> the shipped AI-track slices of a backlog (C-1)
 */
function docsLinksShippedAiSlices(string $backlog): array
{
    $serverTrack = [47, 49, 50];
    $shipped = [];

    foreach (preg_split('/^### (?=SL-\d+ )/m', $backlog) ?: [] as $block) {
        if (preg_match('/^SL-(\d+) /', $block, $id) !== 1) {
            continue;
        }

        $number = (int) $id[1];

        if ($number >= 20 && ! in_array($number, $serverTrack, true) && preg_match('/^- \*\*Status:\*\* shipped$/m', $block) === 1) {
            $shipped[] = $number;
        }
    }

    return $shipped;
}

/**
 * @param  list<int>  $slices
 * @return list<int> the slices no numbered guide section or the closing summary names
 */
function docsLinksUnnamedSlices(array $slices): array
{
    $named = '';
    foreach (docsLinksGuideSections() as $title => $body) {
        if (preg_match('/^(\d+|I{1,2}-\d+)\. /', $title) === 1 || $title === 'Closing summary') {
            $named .= "\n".$body;
        }
    }

    return array_values(array_filter(
        $slices,
        fn (int $number): bool => preg_match('/\bSL-'.$number.'\b/', $named) !== 1,
    ));
}

test('AC-7 every shipped AI slice in the backlog is named in a guide section', function (): void {
    $shipped = docsLinksShippedAiSlices(docsLinksRead('backlog.md'));

    expect($shipped)->toContain(20)->toContain(52)
        ->and(docsLinksUnnamedSlices($shipped))->toBe([]);
});

test('AC-7 SL-44 is named once the ship commit marks it shipped', function (): void {
    $backlog = docsLinksRead('backlog.md');
    $shippedBacklog = preg_replace('/(^### SL-44 .*?^- \*\*Status:\*\* )\w+$/ms', '${1}shipped', $backlog, 1);

    expect($shippedBacklog)->not->toBeNull();

    $shipped = docsLinksShippedAiSlices((string) $shippedBacklog);

    expect($shipped)->toContain(44)
        ->and(docsLinksUnnamedSlices($shipped))->toBe([]);
});
