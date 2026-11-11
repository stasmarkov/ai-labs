<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\PhotoLabItem;
use App\Jobs\ExtractPhotoJob;
use App\Services\GuidePageService;
use App\Services\PhotoLabService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabPhotosController extends Controller
{
    private const ID_PLACEHOLDER = 'PHOTO_ID';

    public function __invoke(Request $request, PhotoLabService $lab, GuidePageService $guides): Response
    {
        $page = $lab->page($request->integer('page', 1));
        $selectedId = $request->query('photo');
        $selected = $lab->selected($page, is_string($selectedId) && ctype_digit($selectedId) && strlen($selectedId) <= 18 ? (int) $selectedId : null);
        $extraction = $lab->request();

        return Inertia::render('Lab/Photos', [
            'howItWorks' => $guides->howItWorks('photos'),
            'guideHref' => route('ai.guides.show', ['key' => 'photos'], absolute: false),
            'photos' => array_map(fn (PhotoLabItem $photo): array => $photo->toArray(), $page->items()),
            'pagination' => [
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'selected' => $selected?->toArray(),
            'similar' => $selected === null ? null : $lab->similar($selected)->toArray(),
            'extraction' => [
                'provider' => $extraction->provider,
                'model' => $extraction->model,
                'promptVersion' => $extraction->promptVersion,
                'queue' => ExtractPhotoJob::QUEUE,
            ],
            'pageUrl' => route('ai.photos', absolute: false),
            'extractUrl' => route('ai.photos.extract', ['photo' => self::ID_PLACEHOLDER], false),
            'statusUrl' => route('ai.photos.status', ['photo' => self::ID_PLACEHOLDER], false),
            'idPlaceholder' => self::ID_PLACEHOLDER,
        ]);
    }
}
