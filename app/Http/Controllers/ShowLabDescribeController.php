<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\LabListingOption;
use App\Services\GuidePageService;
use App\Services\LabDescribeService;
use App\Services\LabProviderService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabDescribeController extends Controller
{
    private const ID_PLACEHOLDER = 'LISTING_ID';

    public function __invoke(LabDescribeService $service, GuidePageService $guides, LabProviderService $providers): Response
    {
        return Inertia::render('Lab/Describe', [
            'howItWorks' => $guides->howItWorks('describe'),
            'guideHref' => route('ai.guides.show', ['key' => 'describe'], false),
            'listings' => array_map(fn (LabListingOption $option): array => $option->toArray(), $service->listingOptions()),
            'describeUrl' => route('listings.describe', ['id' => self::ID_PLACEHOLDER], false),
            'runUrl' => route('ai.runs.show', ['invocationId' => self::ID_PLACEHOLDER], false),
            'enabledTextProviders' => $providers->textOptions('describe'),
            'idPlaceholder' => self::ID_PLACEHOLDER,
        ]);
    }
}
