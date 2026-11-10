<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SemanticSearchRequest;
use App\Services\GuidePageService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabSemanticController extends Controller
{
    public function __invoke(GuidePageService $guides): Response
    {
        return Inertia::render('Lab/Semantic', [
            'howItWorks' => $guides->howItWorks('semantic'),
            'guideHref' => route('ai.guides.show', ['key' => 'semantic'], false),
            'searchUrl' => route('listings.semantic', [], false),
            'maxQueryLength' => SemanticSearchRequest::MAX_QUERY_LENGTH,
        ]);
    }
}
