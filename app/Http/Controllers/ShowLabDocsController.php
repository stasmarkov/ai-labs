<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DocsSearchService;
use App\Services\GuidePageService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabDocsController extends Controller
{
    public function __invoke(DocsSearchService $service, GuidePageService $guides): Response
    {
        return Inertia::render('Lab/Docs', [
            'howItWorks' => $guides->howItWorks('docs'),
            'guideHref' => route('ai.guides.show', ['key' => 'docs'], false),
            'searchUrl' => route('docs.search', [], false),
            'maxQueryLength' => DocsSearchService::MAX_QUERY_LENGTH,
            'k' => $service->defaultK(),
            'maxK' => $service->maxK(),
        ]);
    }
}
