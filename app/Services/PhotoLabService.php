<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiProviders;
use App\Ai\PhotoVisionAgent;
use App\Contracts\PhotoLabRepositoryInterface;
use App\DTOs\PhotoExtractionRequest;
use App\DTOs\PhotoLabDetail;
use App\DTOs\PhotoLabItem;
use App\Exceptions\PhotoExtractionInFlightException;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class PhotoLabService
{
    public const PER_PAGE = 24;

    public function __construct(
        private PhotoLabRepositoryInterface $photos,
        private PhotoExtractionService $extraction,
        private AiProviders $providers,
    ) {}

    /**
     * The model and prompt version the lab shows and runs: the configured ones, as in `photos:extract`.
     */
    public function request(): PhotoExtractionRequest
    {
        $configured = $this->providers->configured('photo-vision');

        return new PhotoExtractionRequest(
            $configured->provider,
            $configured->model,
            (new PhotoVisionAgent($configured->model))->promptVersion(),
        );
    }

    /**
     * @return LengthAwarePaginator<int, PhotoLabItem>
     */
    public function page(int $page): LengthAwarePaginator
    {
        return $this->photos->paginate($this->request(), max(1, $page), self::PER_PAGE);
    }

    /**
     * The detail of the chosen photo; with no choice, or an unknown one, the first photo of the page.
     *
     * @param  LengthAwarePaginator<int, PhotoLabItem>  $page
     */
    public function selected(LengthAwarePaginator $page, ?int $photoId): ?PhotoLabDetail
    {
        $request = $this->request();

        if ($photoId !== null) {
            $chosen = $this->photos->find($photoId, $request);

            if ($chosen !== null) {
                return $chosen;
            }
        }

        $first = $page->items()[0] ?? null;

        return $first === null ? null : $this->photos->find($first->id, $request);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function status(int $photoId): PhotoLabDetail
    {
        return $this->photos->find($photoId, $this->request())
            ?? throw new NotFoundHttpException("No photo {$photoId}.");
    }

    /**
     * Queue one extraction of a stored photo. A photo already queued or running is not queued twice.
     *
     * @throws PhotoExtractionInFlightException
     * @throws NotFoundHttpException
     */
    public function extract(int $photoId): PhotoLabDetail
    {
        $this->providers->forFeature('photo-vision');

        $request = $this->request();
        $current = $this->status($photoId);

        if ($current->isInFlight()) {
            throw new PhotoExtractionInFlightException($current);
        }

        if ($this->extraction->queue($photoId, $request) === null) {
            throw new PhotoExtractionInFlightException($this->status($photoId));
        }

        return $this->status($photoId);
    }
}
