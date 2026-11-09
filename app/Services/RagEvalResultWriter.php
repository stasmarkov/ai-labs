<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\RagEvalOptionException;
use App\Exceptions\RagEvalResultException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;

/**
 * Writes the result files of one run so that a failure leaves nothing: they go to a temporary sibling directory that is then renamed.
 * Only directories inside the results root are written, and only a directory that holds nothing but result files is replaced.
 */
final readonly class RagEvalResultWriter
{
    /**
     * The files a run writes, and the only ones an overwrite may delete.
     */
    public const FILES = ['recall.json', 'calibration.json', 'answers.json'];

    /**
     * Checks that a result directory lies inside the results root and returns it with the root's real path resolved.
     *
     * @throws RagEvalOptionException when the path has a ".." segment, or its parent is not inside the results root, or it is the root itself.
     */
    public function contain(string $path): string
    {
        $root = realpath((string) config('ai.docs.eval_results_root'));

        if ($root === false || in_array('..', explode('/', $path), true)) {
            throw RagEvalOptionException::outputOutsideResults($path, (string) config('ai.docs.eval_results_root'));
        }

        $missing = [];
        $existing = rtrim($path, '/');

        while ($existing !== '' && ! file_exists($existing) && ! is_link($existing)) {
            array_unshift($missing, basename($existing));
            $existing = dirname($existing);
        }

        $real = realpath($existing);

        if ($real === false) {
            throw RagEvalOptionException::outputOutsideResults($path, $root);
        }

        $resolved = $real.($missing === [] ? '' : '/'.implode('/', $missing));
        $parent = dirname($resolved);

        if (($parent !== $root && ! str_starts_with($parent, $root.'/')) || $resolved === $root) {
            throw RagEvalOptionException::outputOutsideResults($path, $root);
        }

        return $resolved;
    }

    /**
     * @throws RagEvalResultException when the directory exists and $force is false, or when it holds anything but result files.
     */
    public function assertWritable(string $directory, bool $force): void
    {
        if (! File::exists($directory) && ! is_link($directory)) {
            return;
        }

        if (! $force) {
            throw RagEvalResultException::labelExists($directory);
        }

        if (is_link($directory) || ! is_dir($directory)) {
            throw RagEvalResultException::notADirectory($directory);
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && (! in_array($entry, self::FILES, true) || ! is_file("{$directory}/{$entry}") || is_link("{$directory}/{$entry}"))) {
                throw RagEvalResultException::foreignFiles($directory);
            }
        }
    }

    /**
     * @throws RagEvalResultException when the file exists and $force is false, or when it is not a plain file.
     */
    public function assertWritableFile(string $directory, string $name, bool $force): void
    {
        $target = "{$directory}/{$name}";

        if (! File::exists($target) && ! is_link($target)) {
            return;
        }

        if (! $force) {
            throw RagEvalResultException::labelExists($target);
        }

        if (is_link($target) || ! is_file($target)) {
            throw RagEvalResultException::cannotWrite($target);
        }
    }

    /**
     * Writes one JSON file into a contained directory: to a temporary sibling file that is then renamed over the target,
     * so a failure leaves nothing and an existing file is replaced whole.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RagEvalResultException when the file exists and $force is false, or when it cannot be written.
     * @throws JsonException
     */
    public function writeFile(string $directory, string $name, array $data, bool $force): void
    {
        $this->assertWritableFile($directory, $name, $force);
        $target = "{$directory}/{$name}";

        File::ensureDirectoryExists($directory);
        $temporary = "{$directory}/.{$name}.tmp-".Str::lower(Str::random(8));

        try {
            File::put($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

            if (! rename($temporary, $target)) {
                throw RagEvalResultException::cannotWrite($target);
            }
        } finally {
            File::delete($temporary);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $files  JSON content by file name
     *
     * @throws RagEvalResultException
     * @throws JsonException
     */
    public function write(string $directory, array $files, bool $force): void
    {
        $this->assertWritable($directory, $force);

        $parent = dirname($directory);
        File::ensureDirectoryExists($parent);
        $suffix = Str::lower(Str::random(8));
        $temporary = $parent.'/.'.basename($directory).'.tmp-'.$suffix;
        $backup = $parent.'/.'.basename($directory).'.bak-'.$suffix;
        $backedUp = false;

        try {
            File::ensureDirectoryExists($temporary);

            foreach ($files as $name => $data) {
                File::put("{$temporary}/{$name}", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
            }

            if (File::exists($directory)) {
                if (! File::moveDirectory($directory, $backup)) {
                    throw RagEvalResultException::cannotWrite($directory);
                }

                $backedUp = true;
            }

            if (! File::moveDirectory($temporary, $directory)) {
                if ($backedUp && File::moveDirectory($backup, $directory)) {
                    $backedUp = false;
                }

                throw RagEvalResultException::cannotWrite($directory);
            }
        } finally {
            File::deleteDirectory($temporary);

            if ($backedUp && File::exists($directory)) {
                File::deleteDirectory($backup);
            }
        }
    }
}
