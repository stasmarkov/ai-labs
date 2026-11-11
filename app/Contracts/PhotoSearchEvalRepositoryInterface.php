<?php

declare(strict_types=1);

namespace App\Contracts;

interface PhotoSearchEvalRepositoryInterface
{
    /**
     * The ids of the photos embedded with the model label.
     *
     * @return list<int>
     */
    public function embeddedPhotoIds(string $label): array;

    /**
     * The dominant colours of each photo's completed extraction for the provider model and prompt version, by photo id;
     * the most recently updated row wins when there are several. Photos without one are absent.
     *
     * @return array<int, list<string>>
     */
    public function dominantColours(string $model, string $promptVersion): array;
}
