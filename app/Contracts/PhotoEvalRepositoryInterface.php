<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\PhotoEvalSample;

interface PhotoEvalRepositoryInterface
{
    public function countPhotos(): int;

    /**
     * The first photos ordered by the sha256 of their source file, a sample that does not change between runs.
     *
     * @return list<PhotoEvalSample>
     */
    public function sample(int $limit): array;
}
