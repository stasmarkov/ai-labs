<?php

declare(strict_types=1);

use App\Ai\ResearchCitations;
use App\Support\ResearchReportHtml;

it('NFR-2 links every known number of a marker, grouped or adjacent, and leaves the unknown ones as text', function (): void {
    expect(ResearchCitations::link('Fusion [1, 2] and [3][1] but [9].', [1, 2, 3]))
        ->toBe('Fusion [[1]](#research-source-1) [[2]](#research-source-2) and [[3]](#research-source-3)[[1]](#research-source-1) but [9].');
});

it('NFR-2 never links a marker inside fenced code or an inline code span, and leaves indexes and links alone', function (): void {
    $text = "Use `see [1]` and arr[1] and [text](http://x) and [^1].\n```\n[1]\n```\nUnclosed:\n```\n[2]";

    expect(ResearchCitations::link($text, [1, 2]))->toBe($text);
});

it('NFR-2 renders raw HTML escaped and links a known marker to its source entry', function (): void {
    $html = ResearchReportHtml::render("See [1, 2] and <b>x</b> <img src=x onerror=alert(2)>\n\n<script>alert(1)</script>", [1, 2]);

    expect($html)->not->toContain('<script')
        ->not->toContain('<img')
        ->toContain('&lt;script&gt;')
        ->toContain('<a href="#research-source-1">[1]</a>')
        ->toContain('<a href="#research-source-2">[2]</a>');
});

it('NFR-2 replaces an image by its alt text so the page makes no request for it', function (): void {
    $html = ResearchReportHtml::render('Look ![the chart](http://evil.example/img.png?leak=1) and ![](https://evil.example/pixel.gif) and [![linked](http://evil.example/i.png)](https://evil.example/go).', []);

    expect($html)->not->toContain('<img')
        ->not->toContain('evil.example')
        ->not->toContain('<a ')
        ->toContain('the chart')
        ->toContain('linked');
});

it('NFR-2 keeps only the text of an external, relative, unsafe or bare link, and keeps the source anchors', function (): void {
    $html = ResearchReportHtml::render("[a](https://evil.example) [b](javascript:alert(1)) [c](/ai/runs/x) [d][ref] https://evil.example/bare <https://evil.example/auto> [e](#research-source-2) [f](#elsewhere)\n\n[ref]: https://evil.example/ref", [1]);

    expect($html)->not->toContain('href="http')
        ->not->toContain('javascript:')
        ->not->toContain('href="/ai')
        ->not->toContain('href="#elsewhere"')
        ->toContain('href="#research-source-2"')
        ->toContain('<p>a b c d ')
        ->toContain('https://evil.example/bare');
    expect(substr_count($html, '<a '))->toBe(1);
});

it('NFR-2 keeps the markdown of the report: emphasis, lists and code', function (): void {
    $html = ResearchReportHtml::render("**Bold** and `code [1]`\n\n- one\n- two\n\n```\n[1] stays\n```", [1]);

    expect($html)->toContain('<strong>Bold</strong>')
        ->toContain('<code>code [1]</code>')
        ->toContain('<li>one</li>')
        ->toContain('[1] stays')
        ->not->toContain('research-source');
});
