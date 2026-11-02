<?php

declare(strict_types=1);

namespace App\Ai;

interface VersionedPrompt
{
    /**
     * The prompt's name and version, such as "car-description@v1".
     */
    public function promptVersion(): string;
}
