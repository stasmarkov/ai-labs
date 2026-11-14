<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PlaygroundPromptRepositoryInterface;
use App\DTOs\NewPlaygroundVersion;
use App\DTOs\PlaygroundPromptRecord;
use App\DTOs\PlaygroundPromptSummary;
use App\DTOs\PlaygroundVersionRecord;
use App\Exceptions\PlaygroundPromptException;
use App\Rules\PlaygroundVersionRules;
use Illuminate\Validation\ValidationException;

/**
 * The rules of the playground's prompts: a name is lowercased and checked once, a version is never edited, and a save
 * that changes nothing is refused.
 */
final readonly class PlaygroundPromptService
{
    private const NAME_PATTERN = '/^[a-z0-9]([a-z0-9 _.-]*[a-z0-9])?$/';

    public function __construct(private PlaygroundPromptRepositoryInterface $prompts) {}

    public static function normaliseName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * @throws PlaygroundPromptException
     * @throws ValidationException when the first version breaks the version rules
     */
    public function create(string $name, NewPlaygroundVersion $first): PlaygroundPromptRecord
    {
        $name = self::normaliseName($name);

        if (mb_strlen($name) < 3 || mb_strlen($name) > 80 || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw PlaygroundPromptException::invalidName();
        }

        PlaygroundVersionRules::validate($first);

        if ($this->prompts->nameExists($name)) {
            throw PlaygroundPromptException::nameTaken();
        }

        return $this->prompts->create($name, $first);
    }

    /**
     * @throws PlaygroundPromptException
     * @throws ValidationException when the version breaks the version rules
     */
    public function addVersion(int $promptId, NewPlaygroundVersion $version): PlaygroundVersionRecord
    {
        PlaygroundVersionRules::validate($version);

        return $this->prompts->addVersion($promptId, $version) ?? throw PlaygroundPromptException::unchanged();
    }

    /**
     * @throws PlaygroundPromptException
     */
    public function find(int $id): PlaygroundPromptRecord
    {
        return $this->prompts->find($id) ?? throw PlaygroundPromptException::notFound();
    }

    /**
     * @return list<PlaygroundPromptSummary>
     */
    public function all(): array
    {
        return $this->prompts->all();
    }

    /**
     * @throws PlaygroundPromptException
     */
    public function delete(int $id): void
    {
        if (! $this->prompts->delete($id)) {
            throw PlaygroundPromptException::notFound();
        }
    }
}
