<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GuidePageService;
use App\Services\LabProviderService;
use App\Services\PhotoTextSearchService;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabPhotoSearchController extends Controller
{
    private const LISTING_PLACEHOLDER = 'LISTING_ID';

    private const PHOTO_PLACEHOLDER = 'PHOTO_ID';

    public function __invoke(GuidePageService $guides, LabProviderService $providers, PhotoTextSearchService $search): Response
    {
        return Inertia::render('Lab/PhotoSearch', [
            'howItWorks' => $guides->howItWorks('photo-search'),
            'guideHref' => route('ai.guides.show', ['key' => 'photo-search'], false),
            'searchUrl' => route('photos.search', [], false),
            'embeddingsProvider' => $providers->embeddings('embeddings-photos'),
            'minQueryLength' => PhotoTextSearchService::MIN_QUERY_LENGTH,
            'maxQueryLength' => PhotoTextSearchService::MAX_QUERY_LENGTH,
            'promptTemplate' => $search->template(),
            'promptVersion' => $search->promptVersion(),
            'minScore' => $search->minScore(),
            'examples' => PhotoTextSearchService::EXAMPLES,
            'photoUrlPrefix' => rtrim((string) parse_url(Storage::disk('public')->url(''), PHP_URL_PATH), '/').'/',
            'photoUrlTemplate' => route('ai.photos', ['photo' => self::PHOTO_PLACEHOLDER], false),
            'listingUrlTemplate' => route('listings.show', ['id' => self::LISTING_PLACEHOLDER], false),
        ]);
    }
}
