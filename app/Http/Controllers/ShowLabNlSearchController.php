<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\NlSearchRequest;
use App\Services\GuidePageService;
use App\Services\LabProviderService;
use App\Services\NlSearchService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabNlSearchController extends Controller
{
    private const ID_PLACEHOLDER = 'RUN_ID';

    public function __invoke(GuidePageService $guides, LabProviderService $providers): Response
    {
        return Inertia::render('Lab/NlSearch', [
            'howItWorks' => $guides->howItWorks('nl-search'),
            'guideHref' => route('ai.guides.show', ['key' => 'nl-search'], false),
            'searchUrl' => route('listings.nl', [], false),
            'runUrl' => route('ai.runs.show', ['invocationId' => self::ID_PLACEHOLDER], false),
            'enabledTextProviders' => $providers->textOptions('nl-search'),
            'idPlaceholder' => self::ID_PLACEHOLDER,
            'maxQueryLength' => NlSearchRequest::MAX_QUERY_LENGTH,
            'models' => NlSearchService::allowedModels(),
        ]);
    }
}
