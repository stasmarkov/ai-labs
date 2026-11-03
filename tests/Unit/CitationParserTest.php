<?php

declare(strict_types=1);

use App\Ai\CitationParser;

it('AC-2 finds markers in order of first use and splits unknown numbers', function (): void {
    $found = CitationParser::parse('See [2] and [1, 3], again [2], then [9] and [0].', 3);

    expect($found)->toBe(['used' => [2, 1, 3], 'unknown' => [9, 0]]);
});

it('AC-2 ignores indexes, links, footnotes and fenced code', function (): void {
    $text = "Use arr[0] or `x[1]` or [text](http://x) or [^1].\n```php\n\$a = [3];\n```\nDone [1].";

    expect(CitationParser::parse($text, 3))->toBe(['used' => [1], 'unknown' => []]);
});

it('AC-2 ignores an unclosed fence to the end', function (): void {
    expect(CitationParser::parse("Yes [1].\n```\n[2]", 3))->toBe(['used' => [1], 'unknown' => []]);
});

it('AC-2 counts adjacent markers such as [2][3], the form the prompt shows the model', function (): void {
    expect(CitationParser::parse('Limits apply [2][3]. Also [1].', 6))->toBe(['used' => [2, 3, 1], 'unknown' => []])
        ->and(CitationParser::parse('Keys use arr[0][1] here [1].', 3))->toBe(['used' => [1], 'unknown' => []]);
});
