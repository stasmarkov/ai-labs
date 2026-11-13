<?php

declare(strict_types=1);

namespace App\Ai\AdminTools;

use App\Contracts\AdminListingRepositoryInterface;

final readonly class CountByTagTool implements AdminTool
{
    public function __construct(private AdminListingRepositoryInterface $listings) {}

    public function name(): string
    {
        return 'countByTag';
    }

    public function description(): string
    {
        return 'Count the listings that carry a tag.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tag' => ['type' => 'string', 'description' => 'The tag: '.AdminArguments::TAG_MIN.' to '.AdminArguments::TAG_MAX.' lower-case letters, digits and hyphens, for example "family-car".'],
            ],
            'required' => ['tag'],
            'additionalProperties' => false,
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        $arguments = AdminArguments::normalise($arguments);

        return [...AdminArguments::unknown($arguments, ['tag']), ...AdminArguments::tagErrors($arguments, true)];
    }

    public function run(array $arguments): array
    {
        $tag = AdminArguments::tag($arguments['tag'] ?? null) ?? '';

        return AdminResultCap::cap(['tag' => $tag, 'count' => $this->listings->countByTag($tag)]);
    }
}
