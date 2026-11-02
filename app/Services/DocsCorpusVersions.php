<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The versions of the two corpora: this repository's commit and the Laravel docs commit recorded by docs:fetch-laravel.
 */
final readonly class DocsCorpusVersions
{
    /**
     * @return array{commit: string|null, dirty: bool}
     */
    public function repo(): array
    {
        $head = Process::path(base_path())->run('git rev-parse HEAD');
        $status = Process::path(base_path())->run('git status --porcelain');

        return [
            'commit' => $head->successful() ? trim($head->output()) : null,
            'dirty' => $status->successful() && trim($status->output()) !== '',
        ];
    }

    /**
     * @return array{branch: string|null, commit: string|null}
     */
    public function laravel(): array
    {
        $path = (string) config('ai.docs.laravel.meta_path');
        $meta = File::isFile($path) ? json_decode((string) File::get($path), true) : null;

        return [
            'branch' => is_array($meta) && is_string($meta['branch'] ?? null) ? $meta['branch'] : null,
            'commit' => is_array($meta) && is_string($meta['commit'] ?? null) ? $meta['commit'] : null,
        ];
    }
}
