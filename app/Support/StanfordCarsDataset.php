<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\DatasetPhoto;
use App\Exceptions\PhotoImportException;

/**
 * Reads the layout stanford-cars.sh leaves behind: classes.csv (file,class_name) and images/<file>.
 */
final readonly class StanfordCarsDataset
{
    public function __construct(private string $directory) {}

    /**
     * Photos in class-name order, then file-name order.
     *
     * @return list<DatasetPhoto>
     */
    public function photos(): array
    {
        $handle = is_file($this->directory.'/classes.csv') ? fopen($this->directory.'/classes.csv', 'rb') : false;

        if ($handle === false) {
            throw PhotoImportException::datasetMissing($this->directory);
        }

        $photos = [];

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if (count($row) < 2 || $row[0] === null || ($row[0] === 'file' && $row[1] === 'class_name')) {
                continue;
            }

            $photos[] = new DatasetPhoto(basename($row[0]), $row[1]);
        }

        fclose($handle);
        usort($photos, fn (DatasetPhoto $a, DatasetPhoto $b): int => [$a->className, $a->file] <=> [$b->className, $b->file]);

        return $photos;
    }

    public function imagePath(DatasetPhoto $photo): string
    {
        return $this->directory.'/images/'.$photo->file;
    }
}
