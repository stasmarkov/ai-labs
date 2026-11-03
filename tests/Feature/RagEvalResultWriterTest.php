<?php

declare(strict_types=1);

use App\Exceptions\RagEvalResultException;
use App\Services\RagEvalResultWriter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

function writerRoot(): string
{
    return storage_path('framework/testing/rag-writer');
}

beforeEach(function (): void {
    File::ensureDirectoryExists(writerRoot());
});

afterEach(function (): void {
    File::deleteDirectory(writerRoot());
});

it('AC-11 puts the old directory back when the new one cannot be moved in, and loses neither', function (): void {
    $target = writerRoot().'/run';
    File::ensureDirectoryExists($target);
    File::put("{$target}/recall.json", 'old');

    $real = new Filesystem;
    $calls = 0;
    File::partialMock()->shouldReceive('moveDirectory')->andReturnUsing(function (string $from, string $to) use ($real, &$calls): bool {
        // The second move, temporary into place, fails; the backup and its restore are real.
        return ++$calls === 2 ? false : $real->moveDirectory($from, $to);
    });

    expect(fn () => (new RagEvalResultWriter)->write($target, ['recall.json' => ['new' => true]], true))->toThrow(RagEvalResultException::class, 'Cannot write');

    expect(File::get("{$target}/recall.json"))->toBe('old')
        ->and(glob(writerRoot().'/.run.*'))->toBe([]);
});

it('AC-11 replaces the old directory and removes the backup on success', function (): void {
    $target = writerRoot().'/run';
    File::ensureDirectoryExists($target);
    File::put("{$target}/recall.json", 'old');

    (new RagEvalResultWriter)->write($target, ['recall.json' => ['new' => true]], true);

    expect(json_decode(File::get("{$target}/recall.json"), true))->toBe(['new' => true])
        ->and(glob(writerRoot().'/.run.*'))->toBe([]);
});
