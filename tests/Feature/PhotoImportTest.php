<?php

declare(strict_types=1);

use App\Models\Listing;
use App\Models\ListingPhoto;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Storage::fake('public');
    $this->dataset = sys_get_temp_dir().'/photo-import-'.bin2hex(random_bytes(4));
    mkdir($this->dataset.'/images', 0777, true);
    $this->rows = [];
});

afterEach(function (): void {
    File::deleteDirectory($this->dataset);
});

function fixtureJpeg(string $path, int $width, int $height, int $shade): void
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, $shade, 255 - $shade, 128));
    imagejpeg($image, $path, 90);
}

function datasetPhoto(object $test, string $file, string $class, int $width = 64, int $height = 48, ?int $shade = null): void
{
    fixtureJpeg($test->dataset.'/images/'.$file, $width, $height, $shade ?? (int) ltrim(pathinfo($file, PATHINFO_FILENAME), '0') * 20);
    $test->rows[] = [$file, $class];
    $handle = fopen($test->dataset.'/classes.csv', 'wb');
    fputcsv($handle, ['file', 'class_name'], ',', '"', '');

    foreach ($test->rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }

    fclose($handle);
}

function fiesta(int $year): Listing
{
    return Listing::factory()->create(['make' => 'Ford', 'model' => 'Fiesta', 'year' => $year]);
}

function importPhotos(object $test, array $options = [])
{
    return test()->artisan('photos:import', ['--dataset' => $test->dataset, ...$options]);
}

test('AC-2 stores only classes that match a catalog make and model', function () {
    $fiesta = fiesta(2012);
    datasetPhoto($this, '0001.jpg', 'Ford Fiesta Sedan 2012');
    datasetPhoto($this, '0002.jpg', 'Audi S4 Sedan 2012');
    datasetPhoto($this, '0003.jpg', 'Bugatti Veyron Coupe 2009');
    datasetPhoto($this, '0004.jpg', 'Ford Fiesta Sedan');

    importPhotos($this)
        ->expectsOutputToContain('Classes matched: 1')
        ->expectsOutputToContain('Classes skipped: 3')
        ->expectsOutputToContain('Photos stored: 1')
        ->assertSuccessful();

    $photo = ListingPhoto::query()->sole();

    expect($photo->listing_id)->toBe($fiesta->id)
        ->and($photo->source)->toBe('stanford-cars')
        ->and($photo->source_class)->toBe('Ford Fiesta Sedan 2012')
        ->and($photo->source_file)->toBe('0001.jpg')
        ->and($photo->path)->toBe("photos/{$fiesta->id}/{$photo->id}.jpg");
    Storage::disk('public')->assertExists($photo->path);
});

test('AC-2 gives each photo the listing of that model with the nearest year, one photo per listing', function () {
    $far = fiesta(2001);
    $near = fiesta(2013);
    $exact = fiesta(2012);
    datasetPhoto($this, '0001.jpg', 'Ford Fiesta Sedan 2012');
    datasetPhoto($this, '0002.jpg', 'Ford Fiesta Sedan 2012');
    datasetPhoto($this, '0003.jpg', 'Ford Fiesta Sedan 2012');
    datasetPhoto($this, '0004.jpg', 'Ford Fiesta Sedan 2012');
    Listing::factory()->create(['make' => 'Ford', 'model' => 'Focus', 'year' => 2012]);

    importPhotos($this)
        ->expectsOutputToContain('Photos stored: 3')
        ->expectsOutputToContain('Skipped, no free listing: 1')
        ->expectsOutputToContain('Listings with a photo: 3')
        ->assertSuccessful();

    $byFile = ListingPhoto::query()->orderBy('source_file')->pluck('listing_id', 'source_file')->all();

    expect($byFile)->toBe(['0001.jpg' => $exact->id, '0002.jpg' => $near->id, '0003.jpg' => $far->id]);
});

test('AC-2 breaks a year tie by listing id', function () {
    $lower = fiesta(2011);
    fiesta(2013);
    datasetPhoto($this, '0001.jpg', 'Ford Fiesta Sedan 2012');

    importPhotos($this)->assertSuccessful();

    expect(ListingPhoto::query()->sole()->listing_id)->toBe($lower->id);
});

test('AC-2 writes the file to the public disk at most 1024 px on the long side', function () {
    fiesta(2012);
    datasetPhoto($this, '0001.jpg', 'Ford Fiesta Sedan 2012', 2400, 1200);
    datasetPhoto($this, '0002.jpg', 'Ford Fiesta Sedan 2012', 300, 900);
    fiesta(2012);

    importPhotos($this)->assertSuccessful();

    $photos = ListingPhoto::query()->orderBy('source_file')->get();
    $large = getimagesizefromstring(Storage::disk('public')->get($photos[0]->path));
    $small = getimagesizefromstring(Storage::disk('public')->get($photos[1]->path));

    expect([$large[0], $large[1]])->toBe([1024, 512])
        ->and([$photos[0]->width, $photos[0]->height])->toBe([1024, 512])
        ->and($large['mime'])->toBe('image/jpeg')
        ->and([$small[0], $small[1]])->toBe([300, 900])
        ->and(max($small[0], $small[1]))->toBeLessThanOrEqual(1024);
});

test('AC-2 never stores the same file twice, and a re-run adds nothing', function () {
    fiesta(2012);
    fiesta(2013);
    fiesta(2014);
    datasetPhoto($this, '0001.jpg', 'Ford Fiesta Sedan 2012', shade: 40);
    datasetPhoto($this, '0002.jpg', 'Ford Fiesta Sedan 2012', shade: 40);
    copy($this->dataset.'/images/0001.jpg', $this->dataset.'/images/0002.jpg');

    importPhotos($this)
        ->expectsOutputToContain('Photos stored: 1')
        ->expectsOutputToContain('Already imported: 1')
        ->assertSuccessful();

    importPhotos($this)
        ->expectsOutputToContain('Photos stored: 0')
        ->expectsOutputToContain('Already imported: 2')
        ->assertSuccessful();

    expect(ListingPhoto::query()->count())->toBe(1)
        ->and(ListingPhoto::query()->sole()->sha256)->toBe(hash_file('sha256', $this->dataset.'/images/0001.jpg'));
});

test('AC-2 a re-run gives a listing that already has a photo no second one', function () {
    $only = fiesta(2012);
    datasetPhoto($this, '0001.jpg', 'Ford Fiesta Sedan 2012', shade: 10);
    importPhotos($this)->assertSuccessful();
    datasetPhoto($this, '0002.jpg', 'Ford Fiesta Sedan 2012', shade: 90);

    importPhotos($this)
        ->expectsOutputToContain('Photos stored: 0')
        ->expectsOutputToContain('Skipped, no free listing: 1')
        ->assertSuccessful();

    expect(ListingPhoto::query()->where('listing_id', $only->id)->count())->toBe(1);
});

test('AC-2 --per-model caps the photos of every class mapped to one catalog model', function () {
    foreach ([2010, 2011, 2012, 2013] as $year) {
        fiesta($year);
    }

    datasetPhoto($this, '0001.jpg', 'Ford Fiesta Hatchback 2010', shade: 10);
    datasetPhoto($this, '0002.jpg', 'Ford Fiesta Hatchback 2010', shade: 20);
    datasetPhoto($this, '0003.jpg', 'Ford Fiesta Sedan 2012', shade: 30);
    datasetPhoto($this, '0004.jpg', 'Ford Fiesta Sedan 2012', shade: 40);

    importPhotos($this, ['--per-model' => 3])
        ->expectsOutputToContain('Photos stored: 3')
        ->assertSuccessful();
});

test('AC-2 --limit stops the import after that many photos in class and file order', function () {
    foreach ([2010, 2011, 2012] as $year) {
        fiesta($year);
    }

    datasetPhoto($this, '0003.jpg', 'Ford Fiesta Sedan 2012', shade: 30);
    datasetPhoto($this, '0001.jpg', 'Ford Fiesta Hatchback 2010', shade: 10);
    datasetPhoto($this, '0002.jpg', 'Ford Fiesta Hatchback 2010', shade: 20);

    importPhotos($this, ['--limit' => 2])->assertSuccessful();

    expect(ListingPhoto::query()->orderBy('source_file')->pluck('source_file')->all())->toBe(['0001.jpg', '0002.jpg']);
});

test('AC-2 --fresh deletes this source rows and files first', function () {
    $listing = fiesta(2012);
    datasetPhoto($this, '0001.jpg', 'Ford Fiesta Sedan 2012', shade: 10);
    importPhotos($this)->assertSuccessful();
    $old = ListingPhoto::query()->sole();
    $other = ListingPhoto::factory()->create(['source' => 'other', 'listing_id' => Listing::factory()->create()->id]);
    Storage::disk('public')->put($other->path, 'x');

    importPhotos($this, ['--fresh' => true])
        ->expectsOutputToContain('Photos stored: 1')
        ->assertSuccessful();

    $new = ListingPhoto::query()->where('source', 'stanford-cars')->sole();

    expect($new->id)->not->toBe($old->id)
        ->and($new->listing_id)->toBe($listing->id)
        ->and(ListingPhoto::query()->whereKey($other->id)->exists())->toBeTrue();
    Storage::disk('public')->assertMissing($old->path);
    Storage::disk('public')->assertExists($new->path);
    Storage::disk('public')->assertExists($other->path);
});

test('AC-2 a failed file write rolls the row back', function () {
    fiesta(2012);
    datasetPhoto($this, '0001.jpg', 'Ford Fiesta Sedan 2012');
    Storage::shouldReceive('disk')->with('public')->andReturn(new class
    {
        public function put(): bool
        {
            return false;
        }
    });

    importPhotos($this)->assertFailed();

    expect(ListingPhoto::query()->count())->toBe(0);
});

test('AC-2 counts an unreadable image and keeps going', function () {
    fiesta(2012);
    file_put_contents($this->dataset.'/images/0001.jpg', 'not an image');
    $this->rows[] = ['0001.jpg', 'Ford Fiesta Sedan 2012'];
    datasetPhoto($this, '0002.jpg', 'Ford Fiesta Sedan 2012');

    importPhotos($this)
        ->expectsOutputToContain('Unreadable or missing files: 1')
        ->expectsOutputToContain('Photos stored: 1')
        ->assertSuccessful();
});

test('AC-2 fails with the download hint when the dataset is missing', function () {
    $this->artisan('photos:import', ['--dataset' => $this->dataset.'/nowhere'])
        ->expectsOutputToContain('stanford-cars.sh')
        ->assertFailed();
});

test('AC-2 rejects options that are not positive whole numbers', function () {
    importPhotos($this, ['--limit' => 'abc'])->assertExitCode(2);
    importPhotos($this, ['--per-model' => 0])->assertExitCode(2);
});

test('MNC-3 the import runs on generated fixtures only, with no network', function () {
    fiesta(2012);
    datasetPhoto($this, '0001.jpg', 'Ford Fiesta Sedan 2012');

    importPhotos($this)->assertSuccessful();

    expect($this->dataset)->toStartWith(sys_get_temp_dir());
});

test('MNC-5 imported photos land in a git-ignored folder', function () {
    $process = new Process(['git', 'check-ignore', '-q', 'storage/app/public/photos/1/1.jpg'], base_path());
    $process->run();

    expect($process->getExitCode())->toBe(0);
});
