<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Finds the [n] and [n, m] markers in an answer, including adjacent ones such as [2][3]. A run of markers is not preceded
 * by a word character, "]" or a backtick and not followed by "(", which leaves out arr[0][1], [text](url) and [^1];
 * fenced code blocks are ignored.
 */
final class CitationParser
{
    /**
     * @return array{used: list<int>, unknown: list<int>} the distinct numbers in order of first use, split by whether 1..$sourceCount holds them
     */
    public static function parse(string $answer, int $sourceCount): array
    {
        $text = preg_replace('/```.*?(```|\z)/s', '', $answer) ?? $answer;
        preg_match_all('/(?<![\w\]`])(?:\[\d+(?:\s*,\s*\d+)*\])+(?!\()/u', $text, $runs);

        $used = [];
        $unknown = [];

        foreach ($runs[0] as $run) {
            preg_match_all('/\d+/', $run, $numbers);

            foreach ($numbers[0] as $number) {
                $n = (int) trim($number);

                if ($n >= 1 && $n <= $sourceCount) {
                    $used[$n] = $n;
                } else {
                    $unknown[$n] = $n;
                }
            }
        }

        return ['used' => array_values($used), 'unknown' => array_values($unknown)];
    }
}
