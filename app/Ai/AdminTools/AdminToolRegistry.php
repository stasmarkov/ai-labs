<?php

declare(strict_types=1);

namespace App\Ai\AdminTools;

use LogicException;

/**
 * The tools the admin assistant offers, in the order the model is told about them.
 */
final readonly class AdminToolRegistry
{
    /** @var list<AdminTool> */
    private array $tools;

    public function __construct(
        SearchListingsTool $searchListings,
        GetListingTool $getListing,
        CountByTagTool $countByTag,
        UpdateTitleTool $updateTitle,
        AddTagTool $addTag,
    ) {
        $this->tools = [$searchListings, $getListing, $countByTag, $updateTitle, $addTag];
    }

    /**
     * @return list<AdminTool>
     */
    public function all(): array
    {
        return $this->tools;
    }

    public function find(string $name): ?AdminTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Runs a read tool. The loop goes through here and never holds a way to run a write: a write tool is refused, and
     * only the confirmation service runs one, after the developer's decision.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws AdminToolFailed
     * @throws LogicException when the tool is a write
     */
    public function runRead(AdminTool $tool, array $arguments): array
    {
        if ($tool->isWrite()) {
            throw new LogicException("The write tool {$tool->name()} cannot be run without a confirmation.");
        }

        return $tool->run($arguments);
    }

    /**
     * The tools in the OpenAI function format.
     *
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function definitions(): array
    {
        return array_map(fn (AdminTool $tool): array => [
            'type' => 'function',
            'function' => ['name' => $tool->name(), 'description' => $tool->description(), 'parameters' => $tool->parameters()],
        ], $this->tools);
    }
}
