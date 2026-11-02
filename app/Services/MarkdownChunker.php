<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ChunkedDocument;
use App\DTOs\DocChunk;
use App\DTOs\MarkdownAtom;
use App\DTOs\MarkdownUnit;
use App\Enums\MarkdownUnitKind;

/**
 * Splits one markdown file into chunks that follow its structure: sections by
 * heading, code blocks and tables whole, long paragraphs cut at sentence ends,
 * with a short overlap between neighbouring chunks of a section.
 */
final readonly class MarkdownChunker
{
    /** Raise when the chunking rules change, so docs:index re-chunks files whose text did not change. */
    public const VERSION = 2;

    private const MAX_TOKENS = 500;

    private const MERGE_BELOW_TOKENS = 300;

    private const OVERLAP_MIN_TOKENS = 50;

    private const OVERLAP_MAX_TOKENS = 80;

    private const INTRODUCTION = 'Introduction';

    public function chunk(string $markdown, string $fileName): ChunkedDocument
    {
        $units = $this->units($markdown);
        $title = $this->titleOf($units) ?? pathinfo($fileName, PATHINFO_FILENAME);

        $sections = [];
        foreach ($this->sections($units) as [$heading, $headingLine, $sectionUnits]) {
            $sections[] = [
                'heading' => $heading,
                'line' => $headingLine,
                'atoms' => $this->atoms($sectionUnits, $this->bodyLimit($title, $heading)),
            ];
        }

        $chunks = [];
        foreach ($this->merged($title, $sections) as $section) {
            foreach ($this->pack($title, $section['heading'], $section['atoms']) as [$body, $oversize]) {
                $content = $this->prefix($title, $section['heading'])."\n\n".$body;
                $chunks[] = new DocChunk(
                    position: count($chunks),
                    heading: $section['heading'],
                    body: $body,
                    content: $content,
                    tokenEstimate: $this->tokens($content),
                    oversize: $oversize,
                    contentHash: hash('sha256', $content),
                );
            }
        }

        return new ChunkedDocument($title, $chunks);
    }

    /**
     * Headings, paragraphs, lists, tables and fenced code in document order.
     *
     * @return list<MarkdownUnit>
     */
    private function units(string $markdown): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $lines = $this->withoutFrontMatter($lines);

        $units = [];
        $buffer = [];
        $count = count($lines);
        $index = 0;

        while ($index < $count) {
            if (preg_match('/^\s*(`{3,}|~{3,})/', $lines[$index], $fence) === 1) {
                array_push($units, ...$this->textUnits($buffer));
                $buffer = [];
                $end = $this->fenceEnd($lines, $index, $fence[1]);
                $units[] = new MarkdownUnit(
                    MarkdownUnitKind::Code,
                    rtrim(implode("\n", array_slice($lines, $index, $end - $index + 1))),
                );
                $index = $end + 1;

                continue;
            }
            $buffer[] = $lines[$index];
            $index++;
        }

        array_push($units, ...$this->textUnits($buffer));

        return $units;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function withoutFrontMatter(array $lines): array
    {
        if (rtrim($lines[0]) !== '---') {
            return $lines;
        }
        for ($i = 1, $count = count($lines); $i < $count; $i++) {
            if (in_array(rtrim($lines[$i]), ['---', '...'], true)) {
                return array_slice($lines, $i + 1);
            }
        }

        return $lines;
    }

    /**
     * Index of the closing fence; an unclosed fence runs to the end of the document,
     * as in CommonMark, because a `#` line inside code is usually a comment.
     *
     * @param  list<string>  $lines
     */
    private function fenceEnd(array $lines, int $start, string $marker): int
    {
        $count = count($lines);
        $character = preg_quote($marker[0], '/');
        $length = strlen($marker);

        for ($i = $start + 1; $i < $count; $i++) {
            if (preg_match('/^\s*('.$character.'{'.$length.',})\s*$/', $lines[$i]) === 1) {
                return $i;
            }
        }

        return $count - 1;
    }

    /**
     * @param  list<string>  $lines
     * @return list<MarkdownUnit>
     */
    private function textUnits(array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $units = [];
        /** @var MarkdownUnitKind|null $kind */
        $kind = null;
        $accumulated = [];
        $close = function () use (&$units, &$kind, &$accumulated): void {
            if ($kind !== null) {
                $units[] = new MarkdownUnit($kind, rtrim(implode("\n", $accumulated)));
            }
            $kind = null;
            $accumulated = [];
        };

        foreach (explode("\n", $this->withoutHtml(implode("\n", $lines))) as $line) {
            if (preg_match('/^ {0,3}(#{1,3})[ \t]+(.+?)(?:[ \t]+#+)?[ \t]*$/', $line, $heading) === 1) {
                $close();
                $units[] = new MarkdownUnit(MarkdownUnitKind::Heading, $heading[2], strlen($heading[1]));
            } elseif (trim($line) === '') {
                $close();
            } elseif (preg_match('/^\s*\|/', $line) === 1) {
                if ($kind !== MarkdownUnitKind::Table) {
                    $close();
                    $kind = MarkdownUnitKind::Table;
                }
                $accumulated[] = $line;
            } elseif ($this->isItem($line)) {
                if ($kind !== MarkdownUnitKind::Items) {
                    $close();
                    $kind = MarkdownUnitKind::Items;
                }
                $accumulated[] = $line;
            } else {
                if ($kind === MarkdownUnitKind::Table) {
                    $close();
                }
                $kind ??= MarkdownUnitKind::Paragraph;
                $accumulated[] = $line;
            }
        }
        $close();

        return $units;
    }

    private function isItem(string $line): bool
    {
        return preg_match('/^\s*(?:[-*+]|\d+[.)])\s+\S/', $line) === 1;
    }

    /**
     * Removes HTML tags but keeps autolinks (`<https://…>`, `<a@b.c>`) and generic
     * types (`Collection<int, User>`): a tag name must end at a space, `/` or `>`,
     * and an opening tag must not follow a letter. Inline `data:` image URIs are
     * shortened, as their base64 text is noise for search.
     */
    private function withoutHtml(string $text): string
    {
        $stripped = preg_replace_callback(
            '/(`[^`\n]*`)|<!--.*?-->|<style\b.*?<\/style>|<\/[a-zA-Z][a-zA-Z0-9-]*\s*>|(?<!\w)<[a-zA-Z][a-zA-Z0-9-]*(?:\s[^>\n]*)?\/?>/su',
            fn (array $match): string => $match[1] ?? '',
            $text,
        );

        return preg_replace('/\]\(data:[^;,)\s]*[;,][^)\s]*\)/u', '](data:…)', $stripped ?? $text) ?? $text;
    }

    /**
     * @param  list<MarkdownUnit>  $units
     */
    private function titleOf(array $units): ?string
    {
        foreach ($units as $unit) {
            if ($unit->kind === MarkdownUnitKind::Heading && $unit->level === 1) {
                return $unit->text;
            }
        }

        return null;
    }

    /**
     * @param  list<MarkdownUnit>  $units
     * @return list<array{string, string, list<MarkdownUnit>}> heading path, heading line, content units
     */
    private function sections(array $units): array
    {
        $sections = [];
        $heading = self::INTRODUCTION;
        $line = '';
        $content = [];
        $second = null;
        $titleSeen = false;

        foreach ($units as $unit) {
            if ($unit->kind !== MarkdownUnitKind::Heading) {
                $content[] = $unit;

                continue;
            }
            if ($unit->level === 1 && ! $titleSeen) {
                $titleSeen = true;

                continue;
            }
            if ($content !== []) {
                $sections[] = [$heading, $line, $content];
            }
            $content = [];
            if ($unit->level === 3) {
                $heading = $second === null ? $unit->text : $second.' › '.$unit->text;
                $line = '### '.$unit->text;
            } else {
                $second = $unit->text;
                $heading = $unit->text;
                $line = '## '.$unit->text;
            }
        }
        if ($content !== []) {
            $sections[] = [$heading, $line, $content];
        }

        return $sections;
    }

    private function prefix(string $title, string $heading): string
    {
        return 'Document: '.$title.' › Section: '.$heading;
    }

    /**
     * Characters left for the body so that the whole chunk stays within the token limit.
     */
    private function bodyLimit(string $title, string $heading): int
    {
        return max(1, self::MAX_TOKENS * 4 - mb_strlen($this->prefix($title, $heading)) - 2);
    }

    private function tokens(string $text): int
    {
        return intdiv(mb_strlen($text) + 3, 4);
    }

    /**
     * @param  list<MarkdownUnit>  $units
     * @return list<MarkdownAtom>
     */
    private function atoms(array $units, int $limit): array
    {
        $atoms = [];

        foreach ($units as $unit) {
            switch ($unit->kind) {
                case MarkdownUnitKind::Code:
                case MarkdownUnitKind::Table:
                    $atoms[] = new MarkdownAtom($unit->text, "\n\n", false);
                    break;
                case MarkdownUnitKind::Items:
                    $first = true;
                    foreach ($this->items($unit->text) as $item) {
                        foreach ($this->pieces($item, $limit, "\n") as $piece) {
                            $atoms[] = new MarkdownAtom($piece['text'], $first ? "\n\n" : $piece['joiner'], true);
                            $first = false;
                        }
                    }
                    break;
                default:
                    if (mb_strlen($unit->text) <= $limit) {
                        $atoms[] = new MarkdownAtom($unit->text, "\n\n", true);
                        break;
                    }
                    $first = true;
                    foreach ($this->sentences($unit->text) as $sentence) {
                        foreach ($this->pieces($sentence, $limit) as $piece) {
                            $atoms[] = new MarkdownAtom($piece['text'], $first ? "\n\n" : $piece['joiner'], true);
                            $first = false;
                        }
                    }
            }
        }

        return $atoms;
    }

    /**
     * A sentence or item above the limit, cut at line breaks and then at the last
     * space before the limit, so only code blocks and tables can be oversize.
     *
     * @return list<array{text: string, joiner: string}>
     */
    private function pieces(string $text, int $limit, string $joiner = ' '): array
    {
        if (mb_strlen($text) <= $limit) {
            return [['text' => $text, 'joiner' => $joiner]];
        }

        $pieces = [];
        foreach (explode("\n", $text) as $index => $line) {
            $lineJoiner = $index === 0 ? $joiner : "\n";
            while (mb_strlen($line) > $limit) {
                $cut = mb_strrpos(mb_substr($line, 0, $limit + 1), ' ');
                $cut = $cut === false || $cut === 0 ? $limit : $cut;
                $pieces[] = ['text' => rtrim(mb_substr($line, 0, $cut)), 'joiner' => $lineJoiner];
                $line = ltrim(mb_substr($line, $cut));
                $lineJoiner = ' ';
            }
            if (trim($line) !== '') {
                $pieces[] = ['text' => $line, 'joiner' => $lineJoiner];
            }
        }

        return $pieces;
    }

    /**
     * @return list<string>
     */
    private function items(string $text): array
    {
        $items = [];
        foreach (explode("\n", $text) as $line) {
            if ($items === [] || $this->isItem($line)) {
                $items[] = $line;
            } else {
                $items[array_key_last($items)] .= "\n".$line;
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function sentences(string $text): array
    {
        $text = trim($text);
        $parts = preg_split('/(?<=[.!?])\s+(?=[\p{Lu}0-9])/u', $text) ?: [$text];

        $sentences = [];
        foreach ($parts as $part) {
            $last = array_key_last($sentences);
            if ($last !== null && preg_match('/(?:^|[\s(])(?:\p{L}|e\.g|i\.e|etc|vs)\.$/iu', $sentences[$last]) === 1) {
                $sentences[$last] .= ' '.$part;

                continue;
            }
            $sentences[] = $part;
        }

        return $sentences;
    }

    /**
     * Joins a small section to the next one when both fit in one chunk.
     *
     * @param  list<array{heading: string, line: string, atoms: list<MarkdownAtom>}>  $sections
     * @return list<array{heading: string, line: string, atoms: list<MarkdownAtom>}>
     */
    private function merged(string $title, array $sections): array
    {
        $result = [];
        $current = null;

        foreach ($sections as $section) {
            if ($current === null) {
                $current = $section;

                continue;
            }
            $combined = [
                ...$current['atoms'],
                new MarkdownAtom($section['line'], "\n\n", true),
                ...$section['atoms'],
            ];
            $prefixLength = mb_strlen($this->prefix($title, $current['heading'])) + 2;

            if (
                $this->tokens(str_repeat('x', $prefixLength + $this->bodyLength($current['atoms']))) < self::MERGE_BELOW_TOKENS
                && $prefixLength + $this->bodyLength($combined) <= self::MAX_TOKENS * 4
            ) {
                $current['atoms'] = $combined;

                continue;
            }
            $result[] = $current;
            $current = $section;
        }
        if ($current !== null) {
            $result[] = $current;
        }

        return $result;
    }

    /**
     * @param  list<MarkdownAtom>  $atoms
     */
    private function bodyLength(array $atoms): int
    {
        $length = 0;
        foreach ($atoms as $index => $atom) {
            $length += ($index === 0 ? 0 : mb_strlen($atom->joiner)) + mb_strlen($atom->text);
        }

        return $length;
    }

    /**
     * @param  list<MarkdownAtom>  $atoms
     */
    private function join(array $atoms): string
    {
        $body = '';
        foreach ($atoms as $index => $atom) {
            $body .= ($index === 0 ? '' : $atom->joiner).$atom->text;
        }

        return $body;
    }

    /**
     * Greedy packing of one section's atoms into chunk bodies.
     *
     * @param  list<MarkdownAtom>  $atoms
     * @return list<array{string, bool}> body, oversize
     */
    private function pack(string $title, string $heading, array $atoms): array
    {
        $limit = $this->bodyLimit($title, $heading);
        $chunks = [];
        $current = [];
        $length = 0;

        foreach ($atoms as $atom) {
            $own = mb_strlen($atom->text);

            if ($own > $limit) {
                if ($current !== []) {
                    $chunks[] = [$this->join($current), false];
                }
                $chunks[] = [$atom->text, true];
                $current = [];
                $length = 0;

                continue;
            }

            if ($current !== [] && $length + mb_strlen($atom->joiner) + $own <= $limit) {
                $current[] = $atom;
                $length += mb_strlen($atom->joiner) + $own;

                continue;
            }

            if ($current !== []) {
                $chunks[] = [$this->join($current), false];
                $seed = $this->overlap($current);
                $current = [];
                $length = 0;
                if ($seed !== [] && $this->bodyLength($seed) + mb_strlen($atom->joiner) + $own <= $limit) {
                    $current = $seed;
                    $length = $this->bodyLength($seed) + mb_strlen($atom->joiner);
                    $current[] = $atom;
                    $length += $own;

                    continue;
                }
            }

            $current = [$atom];
            $length = $own;
        }
        if ($current !== []) {
            $chunks[] = [$this->join($current), false];
        }

        return $chunks;
    }

    /**
     * The last whole units of a closed chunk covering 50 to 80 estimated tokens.
     *
     * @param  list<MarkdownAtom>  $atoms
     * @return list<MarkdownAtom>
     */
    private function overlap(array $atoms): array
    {
        $maxChars = self::OVERLAP_MAX_TOKENS * 4;
        $minChars = (self::OVERLAP_MIN_TOKENS - 1) * 4 + 1;
        $tail = [];
        $chars = 0;

        for ($i = count($atoms) - 1; $i >= 0; $i--) {
            $atom = $atoms[$i];
            if (! $atom->overlappable) {
                break;
            }
            $joiner = $tail === [] ? 0 : mb_strlen($tail[0]->joiner);

            if ($chars + $joiner + mb_strlen($atom->text) <= $maxChars) {
                array_unshift($tail, $atom);
                $chars += $joiner + mb_strlen($atom->text);
                if ($chars >= $minChars) {
                    break;
                }

                continue;
            }

            $part = [];
            $sentences = $this->sentences($atom->text);
            for ($j = count($sentences) - 1; $j >= 0; $j--) {
                $extra = ($part === [] ? $joiner : 1) + mb_strlen($sentences[$j]);
                if ($chars + $extra > $maxChars) {
                    break;
                }
                array_unshift($part, $sentences[$j]);
                $chars += $extra;
                if ($chars >= $minChars) {
                    break;
                }
            }
            if ($part !== []) {
                array_unshift($tail, new MarkdownAtom(implode(' ', $part), "\n\n", true));
            }
            break;
        }

        return $chars >= $minChars ? $tail : [];
    }
}
