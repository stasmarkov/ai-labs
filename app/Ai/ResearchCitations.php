<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Removes the [n] markers of a report that point at no source, using the same marker rules as CitationParser: markers in
 * fenced code stay, and so do arr[0][1], [text](url) and [^1].
 */
final class ResearchCitations
{
    private const MARKERS = '/( ?)(?<![\w\]`])((?:\[\d+(?:\s*,\s*\d+)*\])+)(?!\()/u';

    /**
     * @return array{text: string, unknown: list<int>} the report without unknown markers, and the distinct unknown numbers
     */
    public static function strip(string $report, int $sourceCount): array
    {
        $unknown = CitationParser::parse($report, $sourceCount)['unknown'];

        if ($unknown === []) {
            return ['text' => $report, 'unknown' => []];
        }

        $parts = self::splitCode($report);

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = (string) preg_replace_callback(self::MARKERS, fn (array $found): string => self::keepKnown($found, $sourceCount), $part);
            }
        }

        return ['text' => implode('', $parts), 'unknown' => $unknown];
    }

    /**
     * Turns each known number of a marker, grouped ("[1, 2]") or not, into a markdown link to that source's entry, never
     * inside fenced code or an inline code span. A number that is not a source stays as plain text.
     *
     * @param  list<int>  $known  the source numbers
     */
    public static function link(string $report, array $known): string
    {
        $parts = self::splitCode($report);

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = (string) preg_replace_callback(
                    self::MARKERS,
                    fn (array $found): string => $found[1].self::linkRun($found[2], $known),
                    $part,
                );
            }
        }

        return implode('', $parts);
    }

    /**
     * The text split into prose (even indexes) and code (odd indexes): a fenced block, open to the end of the text when
     * it is never closed, or an inline code span.
     *
     * @return list<string>
     */
    private static function splitCode(string $text): array
    {
        return preg_split('/(```.*?(?:```|\z)|`[^`\n]+`)/s', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
    }

    /**
     * @param  list<int>  $known
     */
    private static function linkRun(string $run, array $known): string
    {
        return (string) preg_replace_callback('/\[([^\]]+)\]/', function (array $group) use ($known): string {
            preg_match_all('/\d+/', $group[1], $numbers);

            return implode(' ', array_map(
                fn (string $number): string => in_array((int) $number, $known, true) ? '[['.(int) $number.']](#research-source-'.(int) $number.')' : "[{$number}]",
                $numbers[0],
            ));
        }, $run);
    }

    /**
     * @param  array<int, string>  $found  the match: leading space, then the run of markers
     */
    private static function keepKnown(array $found, int $sourceCount): string
    {
        preg_match_all('/\[([^\]]+)\]/', $found[2], $groups);

        $kept = '';

        foreach ($groups[1] as $group) {
            preg_match_all('/\d+/', $group, $numbers);
            $known = array_values(array_filter($numbers[0], fn (string $number): bool => (int) $number >= 1 && (int) $number <= $sourceCount));

            if ($known !== []) {
                $kept .= '['.implode(', ', $known).']';
            }
        }

        return $kept === '' ? '' : $found[1].$kept;
    }
}
