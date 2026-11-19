<?php

declare(strict_types=1);

namespace App\Lessons;

use App\Guides\GuideFacts;
use App\Guides\InvalidGuideException;

/**
 * Reads measured numbers for a lesson through the guides' result-file reader (one path syntax for both) and formats
 * them. Bound per request, so each file is decoded once; files under raw/ are never committed, so they are refused.
 */
final class LessonNumbers
{
    public const FORMATS = ['ms', 's', 'percent', 'count', 'tokens-per-s', 'bytes', 'text'];

    /** @var array<string, array<array-key, mixed>> */
    private array $documents = [];

    public function __construct(private readonly GuideFacts $facts) {}

    /**
     * The number at a dot path of a JSON result file; a segment that contains a dot is quoted (models."qwen3.5:9b").
     */
    public function read(string $file, string $path, string $where): float
    {
        $value = $this->facts->at($this->document($file, $where), $path);

        if ($value === null) {
            throw new InvalidLessonException("{$where}: path [{$path}] was not found in [{$file}].");
        }

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidLessonException("{$where}: path [{$path}] in [{$file}] is not a number.");
        }

        return (float) $value;
    }

    public function format(float $value, string $format, ?int $round = null, ?string $unit = null): string
    {
        if ($format === 'bytes') {
            [$divisor, $suffix] = $value >= 1_000_000_000 ? [1_000_000_000, 'GB'] : [1_000_000, 'MB'];

            return number_format($value / $divisor, $round ?? 1, '.', ',').' '.$suffix;
        }

        $number = number_format($value, $round ?? 0, '.', ',');
        $suffix = match ($format) {
            'ms' => 'ms',
            's' => 's',
            'percent' => '%',
            'tokens-per-s' => 'tok/s',
            default => $unit,
        };

        return $suffix === null || $suffix === '' ? $number : $number.' '.$suffix;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(string $file, string $where): array
    {
        if (isset($this->documents[$file])) {
            return $this->documents[$file];
        }

        if ($this->isUncommitted($file)) {
            throw new InvalidLessonException("{$where}: result file [{$file}] is uncommitted; read numbers from committed results, not raw/.");
        }

        if (! str_ends_with($file, '.json')) {
            throw new InvalidLessonException("{$where}: result file [{$file}] was not found.");
        }

        try {
            return $this->documents[$file] = $this->facts->document($file);
        } catch (InvalidGuideException $exception) {
            $reason = str_contains($exception->getMessage(), 'not a JSON object') ? 'is not valid JSON' : 'was not found';

            throw new InvalidLessonException("{$where}: result file [{$file}] {$reason}.");
        }
    }

    /**
     * True when the path, with its dot segments folded, lies under raw/ (gitignored: never committed).
     */
    private function isUncommitted(string $file): bool
    {
        $segments = [];

        foreach (explode('/', $file) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return ($segments[0] ?? '') === 'raw';
    }
}
