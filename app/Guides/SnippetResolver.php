<?php

declare(strict_types=1);

namespace App\Guides;

use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

final readonly class SnippetResolver
{
    public const WHOLE_FILE_LIMIT = 80;

    private const ALLOWED = ['app', 'config', 'resources', 'routes', 'database'];

    public function __construct(private string $basePath) {}

    /**
     * Reference forms: `App\Class`, `App\Class::method`, `app/File.php` (80 lines or fewer),
     * `app/File.php#name` (lines between `guide:start name` and `guide:end name` markers).
     *
     * @return array{file: string, startLine: int, endLine: int, code: string}
     */
    public function resolve(string $reference): array
    {
        if (str_contains($reference, '/') || str_contains($reference, '.')) {
            return $this->fromFile($reference);
        }

        return $this->fromSymbol($reference);
    }

    public function sourcePath(string $reference): string
    {
        if (str_contains($reference, '/') || str_contains($reference, '.')) {
            return $this->allowedPath(explode('#', $reference, 2)[0]);
        }

        return $this->symbolPath($this->reflect($reference));
    }

    /**
     * @return array{file: string, startLine: int, endLine: int, code: string}
     */
    private function fromSymbol(string $reference): array
    {
        $reflection = $this->reflect($reference);
        $path = $this->symbolPath($reflection);

        return $this->slice(
            $path,
            $this->leadingStart($path, (int) $reflection->getStartLine()),
            (int) $reflection->getEndLine(),
        );
    }

    /**
     * Reflection starts at the `function` or `class` line; the docblock and attributes above it belong to the symbol.
     */
    private function leadingStart(string $path, int $start): int
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $index = $start - 2;

        while ($index >= 0) {
            $line = trim($lines[$index]);

            if (str_starts_with($line, '#[')) {
                $index--;

                continue;
            }

            if (str_ends_with($line, '*/')) {
                while ($index >= 0 && ! str_contains($lines[$index], '/**')) {
                    $index--;
                }
                $index--;

                continue;
            }

            break;
        }

        return $index + 2;
    }

    private function reflect(string $reference): ReflectionClass|ReflectionMethod|ReflectionFunction
    {
        try {
            if (str_contains($reference, '::')) {
                [$class, $method] = explode('::', $reference, 2);

                return new ReflectionMethod($class, $method);
            }

            return function_exists($reference) ? new ReflectionFunction($reference) : new ReflectionClass($reference);
        } catch (ReflectionException) {
            throw new InvalidGuideException("Snippet symbol [{$reference}] does not exist.");
        }
    }

    private function symbolPath(ReflectionClass|ReflectionMethod|ReflectionFunction $reflection): string
    {
        $file = $reflection->getFileName();

        if ($file === false) {
            throw new InvalidGuideException('Snippet symbol has no source file.');
        }

        return $this->allowedPath($this->relative($file));
    }

    /**
     * @return array{file: string, startLine: int, endLine: int, code: string}
     */
    private function fromFile(string $reference): array
    {
        [$file, $anchor] = array_pad(explode('#', $reference, 2), 2, null);
        $path = $this->allowedPath($file);
        $lines = $this->lines($path);

        if ($anchor === null) {
            if (count($lines) > self::WHOLE_FILE_LIMIT) {
                throw new InvalidGuideException("Snippet file [{$file}] has more than ".self::WHOLE_FILE_LIMIT.' lines; use a symbol or an anchor.');
            }

            return $this->slice($path, 1, count($lines));
        }

        $startPattern = '/guide:start\s+'.preg_quote($anchor, '/').'\s*$/';
        $endPattern = '/guide:end\s+'.preg_quote($anchor, '/').'\s*$/';
        $starts = $ends = [];
        foreach ($lines as $index => $line) {
            if (preg_match($startPattern, $line) === 1) {
                $starts[] = $index;
            } elseif (preg_match($endPattern, $line) === 1) {
                $ends[] = $index;
            }
        }

        if (count($starts) > 1 || count($ends) > 1) {
            throw new InvalidGuideException("Snippet anchor [{$anchor}] must occur exactly once in [{$file}].");
        }

        $start = isset($starts[0]) ? $starts[0] + 2 : null;
        $end = $ends[0] ?? null;

        if ($start === null || $end === null || $end < $start) {
            throw new InvalidGuideException("Snippet anchor [{$anchor}] not found in [{$file}].");
        }

        return $this->slice($path, $start, $end);
    }

    /**
     * @return array{file: string, startLine: int, endLine: int, code: string}
     */
    private function slice(string $path, int $start, int $end): array
    {
        $code = implode("\n", array_slice($this->lines($path), $start - 1, $end - $start + 1));

        return [
            'file' => $this->relative($path),
            'startLine' => $start,
            'endLine' => $end,
            'code' => htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ];
    }

    /**
     * @return list<string>
     */
    private function lines(string $path): array
    {
        return explode("\n", rtrim(str_replace("\r\n", "\n", (string) file_get_contents($path)), "\n"));
    }

    private function allowedPath(string $relative): string
    {
        $base = realpath($this->basePath);
        $real = realpath($base.'/'.ltrim($relative, '/'));

        if ($base === false || $real === false || ! is_file($real)) {
            throw new InvalidGuideException("Snippet file [{$relative}] was not found.");
        }

        foreach (self::ALLOWED as $directory) {
            if (str_starts_with($real, $base.'/'.$directory.'/')) {
                return $real;
            }
        }

        throw new InvalidGuideException("Snippet file [{$relative}] is outside the allowed directories.");
    }

    private function relative(string $path): string
    {
        $base = realpath($this->basePath);
        $real = realpath($path) ?: $path;

        return ltrim(substr($real, strlen((string) $base)), '/');
    }
}
