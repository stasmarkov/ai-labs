<?php

declare(strict_types=1);

namespace App\Ai\AdminTools;

use App\Contracts\AdminListingRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class AddTagTool implements AdminWriteTool
{
    public function __construct(private AdminListingRepositoryInterface $listings) {}

    public function name(): string
    {
        return 'addTag';
    }

    public function description(): string
    {
        return 'Propose adding a tag to a listing. Adding a tag it already has changes nothing. Nothing changes until the developer confirms it.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'The listing id.'],
                'tag' => ['type' => 'string', 'description' => 'The tag: '.AdminArguments::TAG_MIN.' to '.AdminArguments::TAG_MAX.' lower-case letters, digits and hyphens, for example "family-car".'],
            ],
            'required' => ['id', 'tag'],
            'additionalProperties' => false,
        ];
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function validate(array $arguments): array
    {
        $arguments = AdminArguments::normalise($arguments);
        $errors = [
            ...AdminArguments::unknown($arguments, ['id', 'tag']),
            ...AdminArguments::idErrors($arguments),
            ...AdminArguments::tagErrors($arguments, true),
        ];

        if (AdminArguments::idErrors($arguments) === [] && $this->listings->find((int) $arguments['id']) === null) {
            $errors[] = 'No listing with id '.$arguments['id'].'.';
        }

        return $errors;
    }

    public function preview(array $arguments): array
    {
        $listing = $this->listings->find((int) ($arguments['id'] ?? 0));

        return [
            'id' => (int) ($arguments['id'] ?? 0),
            'make' => $listing?->make,
            'model' => $listing?->model,
            'year' => $listing?->year,
            'title' => $listing?->title,
            'tags' => $listing->tags ?? [],
            'tag' => AdminArguments::tag($arguments['tag'] ?? null),
        ];
    }

    public function run(array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);

        try {
            $change = $this->listings->addTag($id, AdminArguments::tag($arguments['tag'] ?? null) ?? '');
        } catch (ModelNotFoundException) {
            throw new AdminToolFailed("No listing with id {$id}.");
        }

        return AdminResultCap::cap([
            'id' => $change->id,
            'tag' => $change->tag,
            'already_tagged' => $change->alreadyTagged,
        ]);
    }
}
