<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\LabModerationListing;
use App\Http\Requests\ModerateListingRequest;
use App\Services\GuidePageService;
use App\Services\LabModerationService;
use App\Services\LabProviderService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabModerationController extends Controller
{
    private const ID_PLACEHOLDER = 'RUN_ID';

    public function __invoke(LabModerationService $moderation, GuidePageService $guides, LabProviderService $providers): Response
    {
        return Inertia::render('Lab/Moderation', [
            'howItWorks' => $guides->howItWorks('moderation'),
            'guideHref' => route('ai.guides.show', ['key' => 'moderation'], false),
            'moderateUrl' => route('moderation.moderate', [], false),
            'runUrl' => route('ai.runs.show', ['invocationId' => self::ID_PLACEHOLDER], false),
            'enabledTextProviders' => $providers->textOptions('moderation'),
            'idPlaceholder' => self::ID_PLACEHOLDER,
            'limits' => [
                'title' => ModerateListingRequest::MAX_TITLE_LENGTH,
                'name' => ModerateListingRequest::MAX_NAME_LENGTH,
                'city' => ModerateListingRequest::MAX_CITY_LENGTH,
                'description' => ModerateListingRequest::MAX_DESCRIPTION_LENGTH,
                'price_eur' => ModerateListingRequest::MAX_PRICE_EUR,
                'mileage_km' => ModerateListingRequest::MAX_MILEAGE_KM,
                'min_year' => ModerateListingRequest::MIN_YEAR,
                'max_year' => now()->year + 1,
            ],
            'reasons' => $moderation->reasons(),
            'listings' => array_map(fn (LabModerationListing $listing): array => $listing->toArray(), $moderation->listingOptions()),
            'examples' => array_map(fn (LabModerationListing $listing): array => $listing->toArray(), $moderation->examples()),
        ]);
    }
}
