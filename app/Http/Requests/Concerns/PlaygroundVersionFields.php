<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\DTOs\NewPlaygroundVersion;
use App\Rules\PlaygroundVersionRules;

/**
 * The text and settings of a prompt version, shared by the requests that create a prompt and add a version. The rules are
 * those of `PlaygroundVersionRules`, which the prompt service applies too.
 */
trait PlaygroundVersionFields
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function versionRules(): array
    {
        return PlaygroundVersionRules::rules();
    }

    public function toVersion(): NewPlaygroundVersion
    {
        $data = $this->validated();

        return new NewPlaygroundVersion(
            (string) $data['system_prompt'],
            filled($data['note'] ?? null) ? (string) $data['note'] : null,
            isset($data['temperature']) ? (float) $data['temperature'] : 0.2,
            isset($data['max_tokens']) ? (int) $data['max_tokens'] : 512,
        );
    }
}
