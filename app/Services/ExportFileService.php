<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ExportRepositoryInterface;
use App\DTOs\ExportListingRow;
use App\DTOs\ExportRecord;
use App\Exceptions\ExportGenerateException;
use App\Exceptions\ExportNotFoundException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final readonly class ExportFileService
{
    public const ID_PATTERN = '[a-z0-9-]{1,40}';

    private const HEADER = ['snapshot', 'listing_id', 'make', 'model', 'year', 'price', 'currency', 'city', 'fuel', 'mileage_km', 'description'];

    private const PAGE = 1000;

    public function __construct(private ExportRepositoryInterface $exports) {}

    public static function isValidId(string $id): bool
    {
        return preg_match('/^'.self::ID_PATTERN.'$/', $id) === 1;
    }

    public function path(string $id): string
    {
        return rtrim((string) config('exports.directory'), '/')."/{$id}.csv";
    }

    /**
     * The path of a generated export, after checking that it has a record and that its file is there.
     *
     * @throws ExportNotFoundException
     */
    public function locate(string $id): string
    {
        if (! self::isValidId($id) || $this->exports->find($id) === null) {
            throw ExportNotFoundException::unknown($id);
        }

        $path = $this->path($id);

        if (! is_file($path)) {
            Log::warning('Export record without a file.', ['export_id' => $id]);

            throw ExportNotFoundException::notGenerated();
        }

        return $path;
    }

    /**
     * Write the export file atomically: all listings in id order, repeated as snapshots
     * until the file reaches the target size, finishing the current row.
     */
    public function generate(string $id, int $targetBytes): ExportRecord
    {
        if (! self::isValidId($id)) {
            throw new ExportGenerateException("Invalid export id \"{$id}\".");
        }

        $path = $this->path($id);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new ExportGenerateException("Cannot create {$directory}.");
        }

        $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));
        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            throw new ExportGenerateException("Cannot write {$temporary}.");
        }

        try {
            [$bytes, $rows, $sha256] = $this->write($handle, $targetBytes);

            if (! fclose($handle)) {
                throw new ExportGenerateException("Cannot finish writing {$temporary}.");
            }

            if (! rename($temporary, $path)) {
                throw new ExportGenerateException("Cannot move {$temporary} to {$path}.");
            }
        } catch (\Throwable $e) {
            is_resource($handle) && fclose($handle);
            file_exists($temporary) && unlink($temporary);

            throw $e;
        }

        $record = new ExportRecord($id, $path, $bytes, $sha256, $rows, CarbonImmutable::now());
        $this->exports->save($record);

        return $record;
    }

    /**
     * @param  resource  $handle
     * @return array{int, int, string} bytes, data rows, sha256
     */
    private function write($handle, int $targetBytes): array
    {
        $hash = hash_init('sha256');
        $buffer = fopen('php://memory', 'w+b');
        $bytes = 0;
        $rows = 0;

        $emit = function (array $fields) use ($handle, $hash, $buffer, &$bytes): void {
            ftruncate($buffer, 0);
            rewind($buffer);
            fputcsv($buffer, $fields, ',', '"', '', "\n");
            rewind($buffer);
            $line = (string) stream_get_contents($buffer);

            if (fwrite($handle, $line) !== strlen($line)) {
                throw new ExportGenerateException('A short write: the disk may be full.');
            }

            hash_update($hash, $line);
            $bytes += strlen($line);
        };

        $emit(self::HEADER);

        for ($snapshot = 1; $bytes < $targetBytes; $snapshot++) {
            $afterId = 0;
            $seen = 0;

            do {
                $page = $this->exports->listingRowsAfter($afterId, self::PAGE);

                foreach ($page as $row) {
                    $emit($this->fields($snapshot, $row));
                    $rows++;
                    $seen++;
                    $afterId = $row->listingId;

                    if ($bytes >= $targetBytes) {
                        break 3;
                    }
                }
            } while (count($page) === self::PAGE);

            if ($seen === 0) {
                throw new ExportGenerateException('There are no listings to export.');
            }
        }

        fclose($buffer);

        if ($rows === 0) {
            throw new ExportGenerateException('The target is too small: not even one data row would be written.');
        }

        return [$bytes, $rows, hash_final($hash)];
    }

    /**
     * @return list<int|string>
     */
    private function fields(int $snapshot, ExportListingRow $row): array
    {
        return [
            $snapshot,
            $row->listingId,
            $row->make,
            $row->model,
            $row->year,
            number_format($row->priceCents / 100, 2, '.', ''),
            $row->currency,
            $row->city,
            $row->fuel,
            $row->mileageKm,
            $row->description,
        ];
    }
}
