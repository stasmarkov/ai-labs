<?php

declare(strict_types=1);

use App\DTOs\ChunkedDocument;
use App\Services\MarkdownChunker;

function estimateTokens(string $text): int
{
    return (int) ceil(mb_strlen($text) / 4);
}

/**
 * @param  list<string>  $sentences
 */
function paragraphOf(array $sentences): string
{
    return implode(' ', $sentences);
}

function sentence(string $word, int $words): string
{
    return 'The '.implode(' ', array_fill(0, $words, $word)).' ends here.';
}

function chunkMarkdown(string $markdown, string $fileName = 'sample.md'): ChunkedDocument
{
    return (new MarkdownChunker)->chunk($markdown, $fileName);
}

it('AC-2 chunks start with the document and section prefix', function (): void {
    $padding = str_repeat('word ', 330).'end.';
    $markdown = "# Queues\n\nIntro text. {$padding}\n\n## Workers\n\nRun a worker. {$padding}\n\n### Timeouts\n\nSet a timeout. {$padding}\n";

    $document = chunkMarkdown($markdown);

    expect($document->title)->toBe('Queues');
    $prefixes = array_map(fn ($chunk) => explode("\n\n", $chunk->content, 2)[0], $document->chunks);
    expect($prefixes)->toContain('Document: Queues › Section: Workers');
    expect($prefixes)->toContain('Document: Queues › Section: Workers › Timeouts');
    expect($document->chunks[0]->heading)->toBe('Introduction');
    expect($document->chunks[0]->content)->toStartWith("Document: Queues › Section: Introduction\n\nIntro text.");
    expect($document->chunks[0]->body)->toStartWith('Intro text.');
});

it('AC-2 chunk positions count from zero and tokens include the prefix', function (): void {
    $document = chunkMarkdown("# T\n\n## A\n\n".str_repeat('word ', 300)."\n");

    foreach ($document->chunks as $index => $chunk) {
        expect($chunk->position)->toBe($index);
        expect($chunk->tokenEstimate)->toBe(estimateTokens($chunk->content));
        expect($chunk->contentHash)->toBe(hash('sha256', $chunk->content));
    }
});

it('AC-2 code blocks and tables are never split', function (): void {
    $code = "```php\n".implode("\n", array_map(fn ($i) => "\$value{$i} = compute({$i});", range(1, 60)))."\n```";
    $table = "| Name | Value |\n| --- | --- |\n".implode("\n", array_map(fn ($i) => "| row {$i} | value {$i} |", range(1, 40)));
    $filler = paragraphOf(array_fill(0, 12, sentence('filler', 12)));
    $markdown = "# Doc\n\n## Section\n\n{$filler}\n\n{$code}\n\n{$filler}\n\n{$table}\n\n{$filler}\n";

    $document = chunkMarkdown($markdown);

    $withCode = array_values(array_filter($document->chunks, fn ($chunk) => str_contains($chunk->body, '```')));
    expect($withCode)->toHaveCount(1);
    expect($withCode[0]->body)->toContain($code);
    $withTable = array_values(array_filter($document->chunks, fn ($chunk) => str_contains($chunk->body, '| row 1 |')));
    expect($withTable)->toHaveCount(1);
    expect($withTable[0]->body)->toContain($table);
    foreach ($document->chunks as $chunk) {
        expect($chunk->oversize)->toBeFalse();
        expect($chunk->tokenEstimate)->toBeLessThanOrEqual(500);
    }
});

it('AC-2 a code block of 700 tokens is one oversize chunk', function (): void {
    $code = "```\n".implode("\n", array_fill(0, 70, str_repeat('x', 39)))."\n```";
    expect(estimateTokens($code))->toBeGreaterThan(650);
    $markdown = "# Doc\n\n## Section\n\nBefore the code.\n\n{$code}\n\nAfter the code.\n";

    $document = chunkMarkdown($markdown);

    $oversize = array_values(array_filter($document->chunks, fn ($chunk) => $chunk->oversize));
    expect($oversize)->toHaveCount(1);
    expect($oversize[0]->body)->toBe($code);
    expect($oversize[0]->tokenEstimate)->toBeGreaterThan(500);
    expect(count($document->chunks))->toBe(3);
    expect($document->chunks[2]->body)->toBe('After the code.');
});

it('AC-2 long paragraphs are split only at sentence ends', function (): void {
    $sentences = array_map(fn ($i) => "Sentence number {$i} explains one more detail of the queue system in plain words.", range(1, 60));
    $document = chunkMarkdown("# Doc\n\n## Section\n\n".paragraphOf($sentences)."\n");

    expect(count($document->chunks))->toBeGreaterThan(2);
    foreach ($document->chunks as $chunk) {
        expect($chunk->tokenEstimate)->toBeLessThanOrEqual(500);
        expect($chunk->body)->toStartWith('Sentence number ');
        expect($chunk->body)->toEndWith('plain words.');
    }
});

it('AC-2 abbreviations do not end a sentence', function (): void {
    $filler = paragraphOf(array_fill(0, 40, sentence('filler', 10)));
    $text = $filler.' Use a driver, e.g. Redis, for speed. '.$filler;

    $document = chunkMarkdown("# Doc\n\n## S\n\n{$text}\n");

    foreach ($document->chunks as $chunk) {
        expect($chunk->body)->not->toEndWith('e.g.');
        expect($chunk->body)->not->toStartWith('Redis');
    }
});

it('AC-2 consecutive chunks of a section overlap by 50 to 80 estimated tokens of whole units', function (): void {
    $paragraphs = array_map(
        fn ($i) => "Paragraph {$i} ".implode(' ', array_fill(0, 22, 'alpha')).'.',
        range(1, 30),
    );
    $document = chunkMarkdown("# Doc\n\n## Section\n\n".implode("\n\n", $paragraphs)."\n");

    expect(count($document->chunks))->toBeGreaterThan(2);
    for ($i = 1; $i < count($document->chunks); $i++) {
        $previous = explode("\n\n", $document->chunks[$i - 1]->body);
        $current = explode("\n\n", $document->chunks[$i]->body);
        $shared = [];
        foreach ($current as $unit) {
            if (! in_array($unit, $previous, true)) {
                break;
            }
            $shared[] = $unit;
        }
        expect($shared)->not->toBe([]);
        expect($shared)->toBe(array_slice($previous, -count($shared)));
        $tokens = estimateTokens(implode("\n\n", $shared));
        expect($tokens)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(80);
        expect($document->chunks[$i]->tokenEstimate)->toBeLessThanOrEqual(500);
    }
});

it('AC-2 a long last paragraph overlaps by its last sentences', function (): void {
    $filler = str_repeat('word ', 199).'word.';
    $long = paragraphOf(array_map(fn ($i) => "Sentence {$i} ".implode(' ', array_fill(0, 12, 'beta')).'.', range(1, 12)));
    $next = 'Next paragraph '.str_repeat('omega ', 30).'end.';

    $document = chunkMarkdown("# Doc\n\n## Section\n\n{$filler}\n\n{$long}\n\n{$next}\n");

    expect($document->chunks)->toHaveCount(2);
    expect($document->chunks[0]->body)->toBe($filler."\n\n".$long);
    [$overlap, $rest] = explode("\n\n", $document->chunks[1]->body, 2);
    expect($rest)->toBe($next);
    expect($long)->toEndWith($overlap);
    expect($overlap)->toStartWith('Sentence ')->not->toContain('Sentence 1 ');
    expect(estimateTokens($overlap))->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(80);
});

it('AC-2 there is no overlap across sections or after an oversize chunk', function (): void {
    $block = implode("\n\n", array_map(fn ($i) => "Unit {$i} ".implode(' ', array_fill(0, 22, 'gamma')).'.', range(1, 14)));
    $code = "```\n".implode("\n", array_fill(0, 70, str_repeat('y', 39)))."\n```";
    $markdown = "# Doc\n\n## First\n\n{$block}\n\n## Second\n\n{$block}\n\n## Third\n\nShort lead.\n\n{$code}\n\nTrailing paragraph one.\n";

    $document = chunkMarkdown($markdown);

    $bySection = [];
    foreach ($document->chunks as $chunk) {
        $bySection[$chunk->heading][] = $chunk;
    }
    $firstUnitOfSecond = $bySection['Second'][0]->body;
    expect($firstUnitOfSecond)->toStartWith('Unit 1 ');
    foreach ($bySection['First'] as $chunk) {
        expect($chunk->body)->not->toContain('## Second');
    }
    $third = $bySection['Third'];
    expect(end($third)->body)->toBe('Trailing paragraph one.');
});

it('AC-2 front matter, anchors and comments are stripped', function (): void {
    $markdown = "---\ntitle: Hidden\n---\n\n# Real Title\n\n<!-- a note\nover two lines -->\n\n## <a name=\"anchor\"></a>Heading\n\nText with <b>bold</b> words and `<tag>` kept.\n\n<style>.x { color: red; }</style>\n\n```html\n<div>code stays</div>\n```\n";

    $document = chunkMarkdown($markdown);

    $all = implode("\n", array_map(fn ($chunk) => $chunk->content, $document->chunks));
    expect($document->title)->toBe('Real Title');
    expect($all)->not->toContain('Hidden');
    expect($all)->not->toContain('a note');
    expect($all)->not->toContain('anchor');
    expect($all)->not->toContain('color: red');
    expect($all)->toContain('Section: Heading');
    expect($all)->toContain('Text with bold words and `<tag>` kept.');
    expect($all)->toContain('<div>code stays</div>');
});

it('AC-2 the title comes from the first heading or the file name', function (): void {
    expect(chunkMarkdown("# From Heading\n\nBody.\n", 'queues.md')->title)->toBe('From Heading');
    expect(chunkMarkdown("## Only Section\n\nBody.\n", 'queues.md')->title)->toBe('queues');
    expect(chunkMarkdown("Plain text.\n", 'docs/adr/0001-choice.md')->title)->toBe('0001-choice');
});

it('AC-2 headings inside fenced code are not sections', function (): void {
    $markdown = "# Doc\n\n## Real\n\n```bash\n# a comment line\n## not a heading\n```\n";

    $document = chunkMarkdown($markdown);

    expect($document->chunks)->toHaveCount(1);
    expect($document->chunks[0]->heading)->toBe('Real');
    expect($document->chunks[0]->body)->toContain('## not a heading');
});

it('AC-2 small neighbouring sections merge under the first heading', function (): void {
    $document = chunkMarkdown("# Doc\n\n## One\n\nShort one.\n\n## Two\n\nShort two.\n");

    expect($document->chunks)->toHaveCount(1);
    expect($document->chunks[0]->heading)->toBe('One');
    expect($document->chunks[0]->body)->toContain('Short one.')->toContain('Short two.');
});

it('AC-2 output is deterministic', function (): void {
    $markdown = "# Doc\n\n## A\n\n".str_repeat("A sentence goes here and there. Another one follows it.\n\n", 80);

    expect(chunkMarkdown($markdown))->toEqual(chunkMarkdown($markdown));
});

it('AC-2 an empty document has no chunks', function (): void {
    $document = chunkMarkdown("---\ntitle: x\n---\n\n<!-- nothing -->\n", 'empty.md');

    expect($document->chunks)->toBe([]);
    expect($document->title)->toBe('empty');
});

it('AC-2 a long paragraph without sentence ends is cut at line breaks and spaces, never oversize', function (): void {
    $links = implode("\n", array_map(fn ($i) => "[method{$i}](#method-method{$i})", range(1, 200)));
    $words = implode(' ', array_fill(0, 900, 'word'));

    $document = chunkMarkdown("# Doc\n\n## Links\n\n{$links}\n\n## Words\n\n{$words}\n");

    expect(count($document->chunks))->toBeGreaterThan(2);
    foreach ($document->chunks as $chunk) {
        expect($chunk->oversize)->toBeFalse();
        expect($chunk->tokenEstimate)->toBeLessThanOrEqual(500);
    }
    expect(implode("\n", array_map(fn ($chunk) => $chunk->body, $document->chunks)))->toContain('[method200](#method-method200)');
});

it('AC-2 inline data URIs in images are shortened', function (): void {
    $image = '![Screen](data:image/png;base64,'.str_repeat('iVBORw0KGgo', 3000).')';

    $document = chunkMarkdown("# Doc\n\n## S\n\nSee the screen.\n\n{$image}\n");

    expect($document->chunks)->toHaveCount(1);
    expect($document->chunks[0]->body)->toContain('![Screen](data:…)');
    expect($document->chunks[0]->body)->not->toContain('iVBOR');
});

it('AC-2 an unclosed fence runs to the end of the document', function (): void {
    $document = chunkMarkdown("# Doc\n\n## A\n\n```bash\n# install\nnpm i\n\n## B\n");

    expect($document->chunks)->toHaveCount(1);
    expect($document->chunks[0]->heading)->toBe('A');
    expect($document->chunks[0]->body)->toBe("```bash\n# install\nnpm i\n\n## B");
});

it('AC-2 HTML tags are removed but autolinks and generic types stay', function (): void {
    $document = chunkMarkdown("# Doc\n\n## S\n\n<div class=\"note\">See <https://example.com>, <dev@example.com> and Collection<int, User> or Collection<User> here.</div>\n");

    expect($document->chunks[0]->body)->toBe('See <https://example.com>, <dev@example.com> and Collection<int, User> or Collection<User> here.');
});

it('AC-7 a chunk size of 300 gives no paragraph chunk over 300 estimated tokens', function (): void {
    $paragraph = paragraphOf(array_fill(0, 40, sentence('word', 25)));
    $markdown = "# Doc\n\n## A\n\n{$paragraph}\n\n## B\n\n{$paragraph}\n";

    $default = (new MarkdownChunker)->chunk($markdown, 'sample.md');
    $small = (new MarkdownChunker(maxTokens: 300, mergeBelowTokens: 200))->chunk($markdown, 'sample.md');

    expect(max(array_map(fn ($chunk) => $chunk->tokenEstimate, $default->chunks)))->toBeGreaterThan(300)
        ->and(count($small->chunks))->toBeGreaterThan(count($default->chunks));

    foreach ($small->chunks as $chunk) {
        expect($chunk->oversize)->toBeFalse()
            ->and(estimateTokens($chunk->content))->toBeLessThanOrEqual(300);
    }
});

it('MNC-2 the default settings are 500 and 300', function (): void {
    $chunker = new MarkdownChunker;

    expect($chunker->maxTokens())->toBe(500)->and($chunker->mergeBelowTokens())->toBe(300);
});
