<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\CorpusFile;
use Illuminate\Support\Facades\File;

/**
 * Lists the markdown files of the two corpora. Only paths that can be documentation are
 * listed, so agent instructions, tool logs and dependencies are never opened.
 */
final readonly class DocsCorpus
{
    public const REPO = 'repo';

    public const LARAVEL = 'laravel';

    private const REPO_FILES = ['README.md', 'BENCHMARKS.md', 'product-brief.md', 'backlog.md'];

    private const LARAVEL_EXCLUDED = ['readme.md', 'license.md', 'documentation.md'];

    /**
     * @return list<string>
     */
    public function sources(): array
    {
        return [self::REPO, self::LARAVEL];
    }

    public function isAvailable(string $source): bool
    {
        return is_dir($this->root($source));
    }

    /**
     * @return list<CorpusFile>
     */
    public function files(string $source): array
    {
        $paths = $source === self::REPO ? $this->repoPaths() : $this->laravelPaths();
        sort($paths);

        return array_map(
            fn (string $path): CorpusFile => new CorpusFile($source, $path, $this->root($source).'/'.$path),
            $paths,
        );
    }

    public function read(CorpusFile $file): string
    {
        return File::get($file->absolutePath);
    }

    private function root(string $source): string
    {
        return rtrim((string) config("ai.docs.{$source}.path"), '/');
    }

    /**
     * @return list<string>
     */
    private function repoPaths(): array
    {
        $root = $this->root(self::REPO);
        $paths = array_values(array_filter(self::REPO_FILES, fn (string $file): bool => is_file("{$root}/{$file}")));

        foreach (['docs', 'docs/adr'] as $folder) {
            foreach (glob("{$root}/{$folder}/*.md") ?: [] as $file) {
                $paths[] = substr($file, strlen($root) + 1);
            }
        }

        foreach (glob("{$root}/.ai/specs/*/spec-lite.md") ?: [] as $file) {
            if ($this->isDone($file)) {
                $paths[] = substr($file, strlen($root) + 1);
            }
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function laravelPaths(): array
    {
        $root = $this->root(self::LARAVEL);
        $paths = [];

        foreach (glob("{$root}/*.md") ?: [] as $file) {
            $name = basename($file);

            if (! in_array(strtolower($name), self::LARAVEL_EXCLUDED, true)) {
                $paths[] = $name;
            }
        }

        return $paths;
    }

    private function isDone(string $specFile): bool
    {
        if (preg_match('/\A---\R(.*?)\R---/s', File::get($specFile), $frontMatter) !== 1) {
            return false;
        }

        return preg_match('/^status:\s*done\s*$/m', $frontMatter[1]) === 1;
    }
}
