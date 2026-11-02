<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

#[Signature('docs:fetch-laravel')]
#[Description('Shallow-clone the Laravel docs at the configured branch and print the commit')]
final class FetchLaravelDocsCommand extends Command
{
    public function handle(): int
    {
        $repository = (string) config('ai.docs.laravel.repository');
        $branch = (string) config('ai.docs.laravel.branch');
        $target = (string) config('ai.docs.laravel.path');
        $metaPath = (string) config('ai.docs.laravel.meta_path');
        $temporary = $target.'.tmp-'.getmypid();

        File::deleteDirectory($temporary);
        File::ensureDirectoryExists(dirname($target));

        try {
            Process::timeout(300)->run(['git', 'clone', '--depth', '1', '--branch', $branch, $repository, $temporary])->throw();
            $commit = trim(Process::run(['git', '-C', $temporary, 'rev-parse', 'HEAD'])->throw()->output());
        } catch (ProcessFailedException $exception) {
            File::deleteDirectory($temporary);
            $this->error("git clone of {$branch} failed: ".$this->lastLine($exception->result->errorOutput()));

            return self::FAILURE;
        } catch (Throwable $exception) {
            File::deleteDirectory($temporary);
            $this->error('git could not run: '.$exception->getMessage());

            return self::FAILURE;
        }

        // Keep the old copy until the new one is in place, so a failed swap loses nothing.
        $backup = $target.'.old-'.getmypid();
        File::deleteDirectory($backup);

        if (File::isDirectory($target) && ! File::moveDirectory($target, $backup)) {
            File::deleteDirectory($temporary);
            $this->error("Could not move the old copy out of {$target}; it is unchanged.");

            return self::FAILURE;
        }

        if (! File::moveDirectory($temporary, $target)) {
            if (File::isDirectory($backup)) {
                File::moveDirectory($backup, $target);
            }
            File::deleteDirectory($temporary);
            $this->error("Could not move the new clone to {$target}; the old copy is kept.");

            return self::FAILURE;
        }

        File::deleteDirectory($backup);
        File::put($metaPath, json_encode(['branch' => $branch, 'commit' => $commit], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->info("Laravel docs {$branch} at commit {$commit}");

        return self::SUCCESS;
    }

    private function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));

        return $lines === [] ? 'no output from git' : end($lines);
    }
}
