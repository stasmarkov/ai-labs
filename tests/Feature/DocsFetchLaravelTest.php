<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\QueryException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

const FETCH_COMMIT = '625d1dfa0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e';

beforeEach(function (): void {
    $this->root = storage_path('framework/testing/docs-fetch-'.uniqid());
    config([
        'ai.docs.laravel.path' => $this->root.'/laravel',
        'ai.docs.laravel.meta_path' => $this->root.'/laravel.json',
        'ai.docs.laravel.branch' => '13.x',
        'ai.docs.laravel.repository' => 'https://example.test/docs.git',
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

/**
 * @param  list<string>  $log  receives every faked command line
 */
function fakeGit(array &$log, ?ProcessResult $cloneResult = null): void
{
    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use (&$log, $cloneResult) {
        $command = (array) $process->command;
        $log[] = implode(' ', $command);

        if ($command[1] === 'clone') {
            if ($cloneResult !== null) {
                return $cloneResult;
            }
            $destination = end($command);
            File::ensureDirectoryExists($destination);
            File::put($destination.'/queries.md', '# Queries');

            return Process::result();
        }

        return Process::result(FETCH_COMMIT."\n");
    });
}

it('AC-1 fetch clones the configured branch and prints the commit', function (): void {
    $log = [];
    fakeGit($log);

    $this->artisan('docs:fetch-laravel')
        ->expectsOutputToContain(FETCH_COMMIT)
        ->assertSuccessful();

    expect($log[0])->toStartWith('git clone --depth 1 --branch 13.x https://example.test/docs.git '.$this->root.'/laravel.tmp-');
    expect(File::exists($this->root.'/laravel/queries.md'))->toBeTrue();
    expect(File::glob($this->root.'/laravel.tmp-*'))->toBe([]);
    expect(json_decode(File::get($this->root.'/laravel.json'), true))->toBe(['branch' => '13.x', 'commit' => FETCH_COMMIT]);
});

it('AC-1 a second fetch replaces the old copy', function (): void {
    $log = [];
    fakeGit($log);
    File::ensureDirectoryExists($this->root.'/laravel');
    File::put($this->root.'/laravel/stale.md', 'old');

    $this->artisan('docs:fetch-laravel')->assertSuccessful();

    expect(File::exists($this->root.'/laravel/stale.md'))->toBeFalse();
    expect(File::exists($this->root.'/laravel/queries.md'))->toBeTrue();
});

it('AC-1 a failing git leaves the old copy in place and exits 1', function (): void {
    $log = [];
    fakeGit($log, Process::result('', "Cloning...\nfatal: Remote branch 99.x not found", 128));
    File::ensureDirectoryExists($this->root.'/laravel');
    File::put($this->root.'/laravel/old.md', 'keep');
    File::put($this->root.'/laravel.json', '{"commit":"old"}');

    $this->artisan('docs:fetch-laravel')
        ->expectsOutputToContain('fatal: Remote branch 99.x not found')
        ->assertExitCode(1);

    expect(File::get($this->root.'/laravel/old.md'))->toBe('keep');
    expect(File::get($this->root.'/laravel.json'))->toBe('{"commit":"old"}');
    expect(File::glob($this->root.'/laravel.tmp-*'))->toBe([]);
});

it('AC-1 a missing git binary exits 1 and changes nothing', function (): void {
    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process): void {
        throw new RuntimeException('posix_spawn failed: No such file or directory');
    });
    File::ensureDirectoryExists($this->root.'/laravel');
    File::put($this->root.'/laravel/old.md', 'keep');
    File::put($this->root.'/laravel.json', '{"commit":"old"}');

    $this->artisan('docs:fetch-laravel')
        ->expectsOutputToContain('git could not run')
        ->assertExitCode(1);

    expect(File::get($this->root.'/laravel/old.md'))->toBe('keep');
    expect(File::get($this->root.'/laravel.json'))->toBe('{"commit":"old"}');
    expect(File::glob($this->root.'/laravel.tmp-*'))->toBe([]);
});

it('AC-3 docs tables have the columns, constraints and indexes', function (): void {
    $columns = fn (string $table): array => collect(DB::select(
        'select column_name, data_type, is_nullable, is_generated from information_schema.columns where table_name = ?',
        [$table],
    ))->keyBy('column_name')->all();

    $documents = $columns('docs_documents');
    expect(array_keys($documents))->toEqualCanonicalizing(['id', 'source', 'path', 'title', 'content_hash', 'indexed_at', 'created_at', 'updated_at']);

    $chunks = $columns('docs_chunks');
    expect(array_keys($chunks))->toEqualCanonicalizing([
        'id', 'document_id', 'position', 'heading', 'body', 'content', 'token_estimate', 'oversize', 'content_hash',
        'embedding', 'embedding_model', 'embedded_at', 'tsv', 'created_at', 'updated_at',
    ]);
    expect($chunks['embedding']->is_nullable)->toBe('YES');
    expect($chunks['tsv']->is_generated)->toBe('ALWAYS');

    $dimension = DB::selectOne("select atttypmod as size from pg_attribute where attrelid = 'docs_chunks'::regclass and attname = 'embedding'");
    expect($dimension->size)->toBe(1024);

    $indexes = collect(DB::select("select indexdef from pg_indexes where tablename in ('docs_documents', 'docs_chunks')"))->pluck('indexdef')->implode("\n");
    expect($indexes)
        ->toContain('USING hnsw (embedding vector_cosine_ops)')
        ->toContain('USING gin (tsv)')
        ->toContain('UNIQUE INDEX docs_chunks_document_id_position_unique')
        ->toContain('UNIQUE INDEX docs_documents_source_path_unique')
        ->toContain('(content_hash)');
});

it('AC-3 deleting a document deletes its chunks and positions are unique per document', function (): void {
    $documentId = DB::table('docs_documents')->insertGetId(['source' => 'repo', 'path' => 'a.md', 'title' => 'A', 'content_hash' => str_repeat('a', 64)]);
    $chunk = fn (int $position): array => [
        'document_id' => $documentId, 'position' => $position, 'heading' => 'Intro', 'body' => 'flushpackets here',
        'content' => 'Document: A', 'token_estimate' => 5, 'content_hash' => str_repeat('b', 64),
    ];
    DB::table('docs_chunks')->insert($chunk(0));

    expect(fn () => DB::transaction(fn () => DB::table('docs_chunks')->insert($chunk(0))))->toThrow(QueryException::class);
    expect(DB::selectOne("select tsv @@ to_tsquery('english', 'flushpackets') as hit from docs_chunks")->hit)->toBeTrue();

    DB::table('docs_documents')->delete();
    expect(DB::table('docs_chunks')->count())->toBe(0);
});
