<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AiRunRepositoryInterface;
use App\Contracts\ListingRepositoryInterface;
use App\DTOs\AiRunDetail;
use App\DTOs\LabListingOption;
use App\DTOs\ListingSummary;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class LabDescribeService
{
    private const PICKER_SIZE = 10;

    public function __construct(
        private ListingRepositoryInterface $listings,
        private AiRunRepositoryInterface $runs,
    ) {}

    /**
     * @return list<LabListingOption>
     */
    public function listingOptions(): array
    {
        return array_map(
            fn (ListingSummary $listing): LabListingOption => new LabListingOption(
                $listing->id,
                "{$listing->year} {$listing->make} {$listing->model}, {$listing->city}",
            ),
            $this->listings->lowestIds(self::PICKER_SIZE),
        );
    }

    /**
     * @throws NotFoundHttpException
     */
    public function run(string $invocationId): AiRunDetail
    {
        return $this->runs->findByInvocationId($invocationId)
            ?? throw new NotFoundHttpException("No run with invocation id {$invocationId}.");
    }
}
