<?php

declare(strict_types=1);

namespace App\Ai\AdminTools;

use App\Contracts\AdminListingRepositoryInterface;

final readonly class GetListingTool implements AdminTool
{
    public const DESCRIPTION_LIMIT = 500;

    public function __construct(private AdminListingRepositoryInterface $listings) {}

    public function name(): string
    {
        return 'getListing';
    }

    public function description(): string
    {
        return 'Read one listing by id: its fields, title, tags and description (cut at '.self::DESCRIPTION_LIMIT.' characters).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'The listing id.'],
            ],
            'required' => ['id'],
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
        $errors = [...AdminArguments::unknown($arguments, ['id']), ...AdminArguments::idErrors($arguments)];

        if ($errors === [] && $this->listings->find((int) $arguments['id']) === null) {
            $errors[] = 'No listing with id '.$arguments['id'].'.';
        }

        return $errors;
    }

    public function run(array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        $listing = $this->listings->find($id);

        if ($listing === null) {
            throw new AdminToolFailed("No listing with id {$id}.");
        }

        $description = $listing->description;
        $truncated = $description !== null && mb_strlen($description) > self::DESCRIPTION_LIMIT;

        if ($truncated) {
            $description = mb_substr((string) $description, 0, self::DESCRIPTION_LIMIT - 3).'...';
        }

        return AdminResultCap::cap([
            'id' => $listing->id,
            'make' => $listing->make,
            'model' => $listing->model,
            'year' => $listing->year,
            'fuel' => $listing->fuel,
            'price_eur' => intdiv($listing->priceCents, 100),
            'currency' => $listing->currency,
            'mileage_km' => $listing->mileageKm,
            'city' => $listing->city,
            'title' => $listing->title,
            ...AdminArguments::tagsShown($listing->tags),
            'description' => $description,
            'truncated' => $truncated,
        ]);
    }
}
