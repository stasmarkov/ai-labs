<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\NewPlaygroundVersion;
use App\DTOs\PlaygroundPromptRecord;
use App\DTOs\PlaygroundPromptSummary;
use App\DTOs\PlaygroundVersionRecord;

interface PlaygroundPromptRepositoryInterface
{
    /**
     * Stores a prompt with its first version. The name is already normalised.
     */
    public function create(string $name, NewPlaygroundVersion $first): PlaygroundPromptRecord;

    /**
     * Stores the next version number under a lock on the prompt. Returns null when the text and settings equal the latest
     * version, and throws `PlaygroundPromptException` (`not_found`) for an unknown prompt.
     */
    public function addVersion(int $promptId, NewPlaygroundVersion $version): ?PlaygroundVersionRecord;

    public function nameExists(string $name): bool;

    public function find(int $id): ?PlaygroundPromptRecord;

    public function findVersion(int $versionId): ?PlaygroundVersionRecord;

    /**
     * @return list<PlaygroundPromptSummary> the most recently changed first
     */
    public function all(): array;

    /**
     * Deletes the prompt and its versions. Runs that used a version are kept. Returns false for an unknown prompt.
     */
    public function delete(int $id): bool;
}
