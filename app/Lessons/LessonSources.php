<?php

declare(strict_types=1);

namespace App\Lessons;

/**
 * Short builders for the KPI pointers a lesson file writes, so a lesson file stays data: each returns the array the
 * repository validates (a result file, a dot path, a scale and a rounding), and none of them reads a number.
 */
final class LessonSources
{
    /**
     * A value as the file holds it, times the scale, rounded to the given decimals.
     *
     * @return array{file: string, path: string, scale: int|float, round: int}
     */
    public static function figure(string $file, string $path, int $round = 0, int|float $scale = 1): array
    {
        return ['file' => $file, 'path' => $path, 'scale' => $scale, 'round' => $round];
    }

    /**
     * Milliseconds in the file, shown in seconds with one decimal.
     *
     * @return array{file: string, path: string, scale: int|float, round: int}
     */
    public static function seconds(string $file, string $path): array
    {
        return self::figure($file, $path, 1, 0.001);
    }

    /**
     * A rate from 0 to 1 in the file, shown as a whole percent.
     *
     * @return array{file: string, path: string, scale: int|float, round: int}
     */
    public static function percent(string $file, string $path): array
    {
        return self::figure($file, $path, 0, 100);
    }

    /**
     * The same pointer, shown without a better or worse colour: for a scenario switch or a summary.
     *
     * @param  array{file: string, path: string, scale: int|float, round: int}  $source
     * @return array{file: string, path: string, scale: int|float, round: int, trend: false}
     */
    public static function uncompared(array $source): array
    {
        return [...$source, 'trend' => false];
    }
}
