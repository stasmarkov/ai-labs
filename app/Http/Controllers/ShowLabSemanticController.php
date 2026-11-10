<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SemanticSearchRequest;
use App\Services\GuidePageService;
use App\Services\LabProviderService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabSemanticController extends Controller
{
    public function __invoke(GuidePageService $guides, LabProviderService $providers): Response
    {
        return Inertia::render('Lab/Semantic', [
            'howItWorks' => $guides->howItWorks('semantic'),
            'guideHref' => route('ai.guides.show', ['key' => 'semantic'], false),
            'searchUrl' => route('listings.semantic', [], false),
            'embeddingsProvider' => $providers->embeddings('embeddings-listings'),
            'maxQueryLength' => SemanticSearchRequest::MAX_QUERY_LENGTH,
        ]);
    }
}
