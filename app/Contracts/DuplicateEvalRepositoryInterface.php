<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\DuplicateEvalPhoto;

interface DuplicateEvalRepositoryInterface
{
    /**
     * Every photo (imported and generated) whose image vector was made with the label, ascending by id.
     *
     * @return list<DuplicateEvalPhoto>
     */
    public function photos(string $label): array;
}
