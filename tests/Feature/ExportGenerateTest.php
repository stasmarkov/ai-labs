<?php

declare(strict_types=1);

use App\Exceptions\ExportGenerateException;
use App\Models\Dealer;
use App\Models\Export;
use App\Models\Listing;
use App\Models\ListingDocument;
use App\Services\ExportFileService;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/exports-test-'.bin2hex(random_bytes(4));
    config(['exports.directory' => $this->directory]);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

function exportListings(int $count): void
{
    $dealer = Dealer::factory()->create();

    foreach (range(1, $count) as $number) {
        $listing = Listing::factory()->for($dealer)->create(['make' => 'Audi', 'model' => 'A4', 'price_cents' => 1_234_550]);
        ListingDocument::query()->create([
            'listing_id' => $listing->id,
            'body_type' => 'sedan',
            'description' => $number === 1 ? 'Quote "one", with comma' : "Description {$number}",
        ]);
    }
}

function exportRows(string $path): array
{
    $handle = fopen($path, 'rb');
    $rows = [];

    while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($handle);

    return $rows;
}

test('AC-1 generate writes the header, repeats snapshots to the target and records the file', function () {
    exportListings(3);
    $target = 3000;

    app(ExportFileService::class)->generate('listings', $target);

    $path = $this->directory.'/listings.csv';
    $rows = exportRows($path);
    $size = filesize($path);

    expect($rows[0])->toBe(['snapshot', 'listing_id', 'make', 'model', 'year', 'price', 'currency', 'city', 'fuel', 'mileage_km', 'description']);
    expect($size)->toBeGreaterThanOrEqual($target)->toBeLessThan($target + 200);
    expect(array_column($rows, 0))->toContain('2', '3');
    expect($rows[1][0])->toBe('1')->and($rows[1][5])->toBe('12345.50')->and($rows[1][10])->toBe('Quote "one", with comma');
    expect($rows[4][0])->toBe('2')->and($rows[4][1])->toBe($rows[1][1]);

    $ids = array_map('intval', array_column(array_slice($rows, 1, 3), 1));
    expect($ids[0])->toBeLessThan($ids[1]);

    $record = Export::query()->findOrFail('listings');
    expect($record->bytes)->toBe($size)
        ->and($record->sha256)->toBe(hash_file('sha256', $path))
        ->and($record->rows)->toBe(count($rows) - 1)
        ->and($record->path)->toBe($path);
    expect(glob($this->directory.'/*.tmp-*'))->toBe([]);
});

test('AC-1 generating again replaces the file and the record', function () {
    exportListings(2);
    app(ExportFileService::class)->generate('listings', 500);
    $first = Export::query()->findOrFail('listings');
    $firstHash = hash_file('sha256', $first->path);

    app(ExportFileService::class)->generate('listings', 1500);

    $second = Export::query()->findOrFail('listings');
    expect(Export::query()->count())->toBe(1)
        ->and($second->bytes)->toBeGreaterThan($first->bytes)
        ->and(filesize($second->path))->toBe($second->bytes)
        ->and(hash_file('sha256', $second->path))->toBe($second->sha256)->not->toBe($firstHash);
    expect(glob($this->directory.'/*'))->toBe([$this->directory.'/listings.csv']);
});

test('AC-1 a failed generate keeps the old file', function () {
    exportListings(1);
    app(ExportFileService::class)->generate('listings', 300);
    $before = hash_file('sha256', $this->directory.'/listings.csv');

    Listing::query()->delete();
    ListingDocument::query()->delete();

    $this->artisan('exports:generate', ['--target-mb' => 1])->assertFailed();

    expect(hash_file('sha256', $this->directory.'/listings.csv'))->toBe($before);
    expect(glob($this->directory.'/*.tmp-*'))->toBe([]);
});

test('AC-1 exports:generate rejects an invalid id', function () {
    $this->artisan('exports:generate', ['--id' => '../etc'])->assertExitCode(2);

    expect(is_dir($this->directory))->toBeFalse();
});

test('AC-1 exports:generate accepts 1 to 1024 MiB only and exits 2 otherwise', function (string $size) {
    $this->artisan('exports:generate', ['--target-mb' => $size])->assertExitCode(2);

    expect(is_dir($this->directory))->toBeFalse();
})->with(['zero' => '0', 'below one' => '0.5', 'negative' => '-3', 'text' => 'big', 'above the cap' => '1025', 'huge' => '100000']);

test('AC-1 exports:generate writes a file of at least 1 MiB for --target-mb=1', function () {
    exportListings(3);

    $this->artisan('exports:generate', ['--target-mb' => '1'])->assertSuccessful();

    expect(filesize($this->directory.'/listings.csv'))->toBeGreaterThanOrEqual(1048576);
});

test('AC-1 a target that leaves no room for a data row fails and writes nothing', function () {
    exportListings(2);

    expect(fn () => app(ExportFileService::class)->generate('listings', 5))->toThrow(ExportGenerateException::class, 'not even one data row');

    expect(glob($this->directory.'/*'))->toBe([])->and(Export::query()->count())->toBe(0);
});

test('AC-1 a short write fails the generate, removes the temporary file and leaves the old record', function () {
    exportListings(2);
    $service = app(ExportFileService::class);
    $old = $service->generate('listings', 500);
    $oldHash = hash_file('sha256', $old->path);

    ShortWriteStream::register();
    config(['exports.directory' => 'shortwrite://exports']);
    ShortWriteStream::$files = [];

    expect(fn () => $service->generate('other', 500))->toThrow(ExportGenerateException::class, 'short write');

    expect(ShortWriteStream::$files)->toBe([])->and(ShortWriteStream::$opened)->toBe(1);
    ShortWriteStream::unregister();

    config(['exports.directory' => $this->directory]);
    expect(Export::query()->count())->toBe(1)
        ->and(hash_file('sha256', $old->path))->toBe($oldHash)
        ->and(Export::query()->findOrFail('listings')->sha256)->toBe($oldHash);
});

/**
 * A stream wrapper whose writes always store one byte less than asked, like a disk that fills up.
 */
final class ShortWriteStream
{
    /** @var array<string, string> */
    public static array $files = [];

    public static int $opened = 0;

    public $context;

    private string $name = '';

    public static function register(): void
    {
        @stream_wrapper_unregister('shortwrite');
        stream_wrapper_register('shortwrite', self::class);
        self::$opened = 0;
    }

    public static function unregister(): void
    {
        stream_wrapper_unregister('shortwrite');
    }

    public function url_stat(string $path, int $flags): array|false
    {
        if (str_ends_with($path, 'exports')) {
            return ['mode' => 0040775];
        }

        return array_key_exists($path, self::$files) ? ['mode' => 0100644, 'size' => strlen(self::$files[$path])] : false;
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return true;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $this->name = $path;
        self::$files[$path] = '';
        self::$opened++;

        return true;
    }

    public function stream_write(string $data): int
    {
        self::$files[$this->name] .= $data;

        return max(0, strlen($data) - 1);
    }

    public function stream_close(): void {}

    public function unlink(string $path): bool
    {
        unset(self::$files[$path]);

        return true;
    }

    public function rename(string $from, string $to): bool
    {
        self::$files[$to] = self::$files[$from];
        unset(self::$files[$from]);

        return true;
    }
}
