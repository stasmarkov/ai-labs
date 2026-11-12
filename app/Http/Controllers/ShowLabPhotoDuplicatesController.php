<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\DuplicateReviewGroup;
use App\Services\DuplicateReviewService;
use App\Services\GuidePageService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabPhotoDuplicatesController extends Controller
{
    private const ID_PLACEHOLDER = 'GROUP_ID';

    public function __invoke(Request $request, DuplicateReviewService $review, GuidePageService $guides): Response
    {
        $page = $review->page($request->integer('page', 1));

        return Inertia::render('Lab/PhotoDuplicates', [
            'howItWorks' => $guides->howItWorks('photo-duplicates'),
            'guideHref' => route('ai.guides.show', ['key' => 'photo-duplicates'], absolute: false),
            'label' => $review->label(),
            'cutoff' => $review->cutoff(),
            'summary' => $review->summary()->toArray(),
            'groups' => array_map(fn (DuplicateReviewGroup $group): array => $group->toArray(), $page->items()),
            'pagination' => [
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'pageUrl' => route('ai.photos.duplicates', absolute: false),
            'keepUrl' => route('ai.photos.duplicates.keep', ['group' => self::ID_PLACEHOLDER], false),
            'undoUrl' => route('ai.photos.duplicates.undo', ['group' => self::ID_PLACEHOLDER], false),
            'idPlaceholder' => self::ID_PLACEHOLDER,
            'commands' => [
                'ddev artisan photos:make-duplicates --photos=20',
                'ddev artisan photos:embed --wait',
                'ddev artisan photos:duplicates --judge --wait',
            ],
        ]);
    }
}
