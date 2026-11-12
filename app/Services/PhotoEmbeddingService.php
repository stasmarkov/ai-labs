<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiProviders;
use App\Ai\AiRunRecorder;
use App\Ai\PhotoEncoderClient;
use App\Ai\PhotoVisionAgent;
use App\Contracts\ListingPhotoRepositoryInterface;
use App\DTOs\PhotoCaptionRow;
use App\DTOs\PhotoEmbedding;
use App\DTOs\PhotoEmbedProgress;
use App\DTOs\PhotoEmbedRun;
use App\DTOs\PhotoExtractionRequest;
use App\DTOs\PhotoTextEmbedding;
use App\Exceptions\EmbeddingDimensionsException;
use App\Exceptions\PhotoEncoderException;
use App\Jobs\EmbedPhotosBatchJob;
use App\Jobs\EmbedPhotoTextsBatchJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class PhotoEmbeddingService
{
    public const FEATURE = 'embeddings-photos';

    public const AGENT = 'PhotoEncoder';

    public const TEXT_AGENT = 'PhotoTextEncoder';

    public const VECTOR_DIMENSIONS = 512;

    public const INVOCATION_PREFIX = 'embed:';

    private const COUNTER_TTL_SECONDS = 86400;

    public function __construct(
        private ListingPhotoRepositoryInterface $photos,
        private PhotoEncoderClient $encoder,
        private AiProviders $providers,
        private AiRunRecorder $recorder,
        private SimilarPhotosService $similar,
        private PhotoTextSearchService $textSearch,
    ) {}

    public function label(): string
    {
        return $this->encoder->label();
    }

    public function batchSize(): int
    {
        $provider = $this->providers->configured(self::FEATURE)->provider;

        return max(1, (int) config("ai.providers.{$provider}.batch", 16));
    }

    /**
     * Refuse a setup that cannot work before any batch is queued: a model whose size is not the column's, an
     * unreachable encoder, or one that answers a generated image with another size.
     *
     * @throws EmbeddingDimensionsException
     * @throws PhotoEncoderException
     */
    public function assertReady(): void
    {
        $provider = $this->providers->configured(self::FEATURE)->provider;

        // The driver first: a provider that is not an image encoder has no image dimensions at all.
        if (config("ai.providers.{$provider}.driver") !== 'image-encoder') {
            throw PhotoEncoderException::notConfigured($provider);
        }

        $dimensions = $this->encoder->dimensions();

        if ($dimensions !== self::VECTOR_DIMENSIONS) {
            throw EmbeddingDimensionsException::forProvider($this->providers->configured(self::FEATURE)->provider, $dimensions, self::VECTOR_DIMENSIONS);
        }

        $this->encoder->embed([$this->probeImage()]);
    }

    /**
     * Queue one job per batch of photos whose vector is missing or made by another label.
     */
    public function dispatch(?int $limit, bool $fresh): PhotoEmbedRun
    {
        $startedAt = CarbonImmutable::now();

        if ($fresh) {
            $this->photos->clearEmbeddings($limit);
            $this->similar->invalidate();
        }

        $ids = $this->photos->staleEmbeddingPhotoIds($this->label(), $limit);
        $batches = array_chunk($ids, $this->batchSize());
        $run = new PhotoEmbedRun((string) Str::uuid(), $startedAt, count($ids), count($batches));

        foreach ($batches as $photoIds) {
            EmbedPhotosBatchJob::dispatch($photoIds, $run->id);
        }

        return $run;
    }

    /**
     * Encode the still-stale photos of one batch in one encoder call and store their vectors. A missing file is
     * skipped; an encoder failure writes a failed ai_runs row and leaves the photos without a vector.
     *
     * @param  list<int>  $photoIds
     * @return int the number of vectors stored
     */
    public function embedBatch(array $photoIds, ?string $runId = null): int
    {
        $label = $this->label();
        $stored = 0;
        $files = [];
        $missing = 0;

        $failed = 0;

        // The run counters are settled in finally: whatever escapes (a read error, a database error, a killed call),
        // `photos:embed --wait` still sees this batch end instead of waiting out its 30-minute limit.
        try {
            foreach ($this->photos->stalePhotos($photoIds, $label) as $photo) {
                $disk = Storage::disk('public');

                if ($disk->exists($photo->path)) {
                    $files[$photo->id] = (string) $disk->get($photo->path);
                } else {
                    $missing++;
                }
            }

            if ($files !== []) {
                $choice = $this->providers->configured(self::FEATURE);
                $startedAt = hrtime(true);

                try {
                    $vectors = $this->encoder->embed(array_values($files));
                    $embeddings = [];

                    foreach (array_keys($files) as $index => $photoId) {
                        $embeddings[] = new PhotoEmbedding($photoId, $this->normalise($vectors[$index]));
                    }

                    $stored = $this->photos->storeEmbeddings($embeddings, $label, CarbonImmutable::now());

                    if ($stored > 0) {
                        $this->similar->invalidate();
                    }

                    $exception = null;
                } catch (Throwable $e) {
                    $failed = count($files);
                    $exception = $e;
                }

                $this->recorder->recordRun(
                    $exception === null ? 'completed' : 'failed',
                    self::AGENT,
                    $choice->provider,
                    $choice->model,
                    null,
                    intdiv(hrtime(true) - $startedAt, 1_000_000),
                    null,
                    invocationId: $runId === null ? null : self::INVOCATION_PREFIX.$runId.':'.substr(str_replace('-', '', (string) Str::uuid()), 0, 16),
                    exception: $exception,
                    inputs: count($files),
                );
            }

        } catch (Throwable $e) {
            $failed = count($photoIds) - $missing;

            throw $e;
        } finally {
            if ($runId !== null) {
                $this->count($runId, 'settled', count($photoIds));
                $this->count($runId, 'failed', $failed);
                $this->count($runId, 'missing', $missing);
            }
        }

        return $stored;
    }

    /**
     * Queue one job per batch of photos whose caption vector is missing, made by another label or from another text.
     * Photos that lost their caption (a new vision model or prompt version without a completed extraction) get a null
     * vector now.
     */
    public function dispatchText(?int $limit, bool $fresh): PhotoEmbedRun
    {
        $startedAt = CarbonImmutable::now();
        $label = $this->label();
        $changed = $fresh ? $this->photos->clearTextEmbeddingsInScope($limit) : 0;

        $rows = $this->photos->captionRows($this->captionRequest(), $limit);
        $lost = array_map(
            fn (PhotoCaptionRow $row): int => $row->photoId,
            array_filter($rows, fn (PhotoCaptionRow $row): bool => $row->caption === null && ($row->hasTextVector || $row->textHash !== null)),
        );
        $changed += $this->photos->clearTextEmbeddings(array_values($lost));

        if ($changed > 0) {
            $this->similar->invalidateText();
        }

        $ids = $this->staleTextIds($rows, $label);
        $batches = array_chunk($ids, $this->batchSize());
        $run = new PhotoEmbedRun((string) Str::uuid(), $startedAt, count($ids), count($batches));

        foreach ($batches as $photoIds) {
            EmbedPhotoTextsBatchJob::dispatch($photoIds, $run->id);
        }

        return $run;
    }

    /**
     * How many of the first $limit photos have no caption from the current extraction, so no text vector.
     */
    public function withoutCaption(?int $limit): int
    {
        return count(array_filter($this->photos->captionRows($this->captionRequest(), $limit), fn (PhotoCaptionRow $row): bool => $row->caption === null));
    }

    /**
     * Encode the still-stale captions of one batch in one encoder call and store their vectors. An encoder failure
     * writes a failed ai_runs row and leaves the photos as they were.
     *
     * @param  list<int>  $photoIds
     * @return int the number of vectors stored
     */
    public function embedTextBatch(array $photoIds, ?string $runId = null): int
    {
        $label = $this->label();
        $stored = 0;
        $failed = 0;

        try {
            $stale = array_values(array_filter(
                $this->photos->captionRowsFor($this->captionRequest(), $photoIds),
                fn (PhotoCaptionRow $row): bool => $row->caption !== null && $this->isTextStale($row, $label),
            ));

            if ($stale !== []) {
                $choice = $this->providers->configured(self::FEATURE);
                $startedAt = hrtime(true);

                try {
                    $texts = array_map(fn (PhotoCaptionRow $row): string => $this->textSearch->prompt((string) $row->caption), $stale);
                    $vectors = $this->encoder->embedTexts($texts);
                    $embeddings = [];

                    foreach ($stale as $index => $row) {
                        $embeddings[] = new PhotoTextEmbedding($row->photoId, $vectors[$index], hash('sha256', $texts[$index]));
                    }

                    $stored = $this->photos->storeTextEmbeddings($embeddings, $label, CarbonImmutable::now());

                    if ($stored > 0) {
                        $this->similar->invalidateText();
                    }

                    $exception = null;
                } catch (Throwable $e) {
                    $failed = count($stale);
                    $exception = $e;
                }

                $this->recorder->recordRun(
                    $exception === null ? 'completed' : 'failed',
                    self::TEXT_AGENT,
                    $choice->provider,
                    $choice->model,
                    null,
                    intdiv(hrtime(true) - $startedAt, 1_000_000),
                    null,
                    invocationId: $runId === null ? null : self::INVOCATION_PREFIX.$runId.':'.substr(str_replace('-', '', (string) Str::uuid()), 0, 16),
                    exception: $exception,
                    inputs: count($stale),
                );
            }
        } catch (Throwable $e) {
            $failed = count($photoIds);

            throw $e;
        } finally {
            if ($runId !== null) {
                $this->count($runId, 'settled', count($photoIds));
                $this->count($runId, 'failed', $failed);
            }
        }

        return $stored;
    }

    public function textEmbeddedSince(CarbonImmutable $since): int
    {
        return $this->photos->countTextEmbeddedSince($since);
    }

    public function progress(PhotoEmbedRun $run): PhotoEmbedProgress
    {
        return new PhotoEmbedProgress(
            (int) Cache::get($this->counterKey($run->id, 'settled'), 0),
            (int) Cache::get($this->counterKey($run->id, 'failed'), 0),
            (int) Cache::get($this->counterKey($run->id, 'missing'), 0),
        );
    }

    public function embeddedSince(CarbonImmutable $since): int
    {
        return $this->photos->countEmbeddedSince($since);
    }

    /**
     * The model and prompt version that wrote the captions in use: the effective photo-vision choice.
     */
    private function captionRequest(): PhotoExtractionRequest
    {
        $choice = $this->providers->configured('photo-vision');

        return new PhotoExtractionRequest($choice->provider, $choice->model, (new PhotoVisionAgent($choice->model))->promptVersion());
    }

    /**
     * @param  list<PhotoCaptionRow>  $rows
     * @return list<int>
     */
    private function staleTextIds(array $rows, string $label): array
    {
        return array_values(array_map(
            fn (PhotoCaptionRow $row): int => $row->photoId,
            array_filter($rows, fn (PhotoCaptionRow $row): bool => $row->caption !== null && $this->isTextStale($row, $label)),
        ));
    }

    private function isTextStale(PhotoCaptionRow $row, string $label): bool
    {
        return ! $row->hasTextVector
            || $row->textModel !== $label
            || $row->textHash !== hash('sha256', $this->textSearch->prompt((string) $row->caption));
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function normalise(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $vector)));

        return $norm > 0.0 ? array_map(fn (float $value): float => $value / $norm, $vector) : $vector;
    }

    private function count(string $runId, string $name, int $by): void
    {
        $key = $this->counterKey($runId, $name);

        Cache::add($key, 0, self::COUNTER_TTL_SECONDS);
        Cache::increment($key, $by);
    }

    private function counterKey(string $runId, string $name): string
    {
        return "photos:embed:{$runId}:{$name}";
    }

    /**
     * An 8 x 8 white JPEG, generated so the probe needs no stored photo.
     */
    private function probeImage(): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));

        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
