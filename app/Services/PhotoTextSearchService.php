<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiProviders;
use App\Ai\AiRunRecorder;
use App\Ai\PhotoEncoderClient;
use App\Contracts\PhotoTextSearchRepositoryInterface;
use App\DTOs\PhotoTextSearchResult;
use App\DTOs\SimilarPhotoMatch;
use App\Exceptions\IndexNotBuiltException;
use App\Exceptions\PhotoEncoderException;
use App\Exceptions\PhotoSearchRefusedException;
use App\Exceptions\PhotoSearchUnavailableException;
use App\Rules\ValidUtf8Text;
use App\Support\ServerTiming;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class PhotoTextSearchService
{
    public const AGENT = 'PhotoTextSearch';

    public const MIN_QUERY_LENGTH = 2;

    public const MAX_QUERY_LENGTH = 200;

    /** Example queries offered as chips on the lab page. */
    public const EXAMPLES = ['red convertible', 'white SUV in the snow', 'BMW 3 Series', 'a car on a race track'];

    public const REBUILD_COMMAND = 'ddev artisan photos:embed --fresh';

    public function __construct(
        private PhotoTextSearchRepositoryInterface $photos,
        private PhotoEncoderClient $encoder,
        private PromptInjectionGuard $guard,
        private AiProviders $providers,
        private SimilarPhotosService $similar,
        private ServerTiming $timing,
    ) {}

    /**
     * Up to the configured limit of photos, best first, one per listing, at or above the minimum score, for a text
     * query encoded by the text side of the photo encoder. The query never reaches SQL: only its vector does.
     *
     * @param  string  $origin  "api" or "eval", written on the run row
     * @param  bool  $useCache  false reads and writes no cache entry, so the eval measures every step
     *
     * @throws ValidationException when the query is not 2 to 200 characters of valid text.
     * @throws PhotoSearchRefusedException when the query matches the input guard; the encoder is not called.
     * @throws IndexNotBuiltException when no photo carries the current label; the encoder is not called.
     * @throws PhotoSearchUnavailableException when the encode fails.
     */
    public function search(string $query, string $origin = 'api', bool $useCache = true): PhotoTextSearchResult
    {
        $text = $this->validated($query);
        $recorder = app(AiRunRecorder::class);
        $choice = $this->providers->configured('embeddings-photos');
        $label = $this->encoder->label();
        $prompt = $this->prompt($text);
        $startedAt = hrtime(true);

        $pattern = $this->guard->match($text);

        if ($pattern !== null) {
            $invocationId = $recorder->recordOutcome(
                'refused',
                self::AGENT,
                $choice->provider,
                $choice->model,
                $this->promptVersion(),
                0,
                'input:'.pathinfo((string) config('ai.docs.answer.guard'), PATHINFO_FILENAME).':'.$pattern,
                origin: $origin,
            );

            throw new PhotoSearchRefusedException($pattern, $invocationId);
        }

        if (! $this->photos->hasVectors($label)) {
            throw IndexNotBuiltException::forModel($label, self::REBUILD_COMMAND);
        }

        $key = $this->cacheKey($text);
        $cached = $useCache ? Cache::get($key) : null;

        if (is_array($cached)) {
            return new PhotoTextSearchResult(
                $text,
                $prompt,
                $label,
                $this->minScore(),
                true,
                array_map(SimilarPhotoMatch::fromArray(...), $cached),
            );
        }

        try {
            // Measured into Server-Timing, so the lab page shows the encode and the index search apart.
            $vector = $this->timing->measure('embed', fn (): array => $this->encoder->embedText($prompt));
            $candidates = $this->timing->measure('db', fn (): array => $this->photos->nearestToVector(
                $vector,
                $label,
                max(1, (int) config('ai.photo_search.candidates', 40)),
                (int) config('ai.similar_photos.ef_search', 100),
            ));
        } catch (PhotoEncoderException $e) {
            $recorder->recordRun('failed', self::AGENT, $choice->provider, $choice->model, $this->promptVersion(), $this->elapsedMs($startedAt), null, exception: $e, origin: $origin, inputs: 1);

            throw PhotoSearchUnavailableException::because($e);
        }

        $results = $this->filter($candidates);

        $recorder->recordRun('completed', self::AGENT, $choice->provider, $choice->model, $this->promptVersion(), $this->elapsedMs($startedAt), null, origin: $origin, inputs: 1);

        if ($useCache) {
            Cache::put(
                $key,
                array_map(fn (SimilarPhotoMatch $match): array => $match->toArray(), $results),
                max(1, (int) config('ai.photo_search.ttl_seconds', 3600)),
            );
        }

        return new PhotoTextSearchResult($text, $prompt, $label, $this->minScore(), false, $results);
    }

    /**
     * The cache key of a normalised query: label, SL-36 generation (read only), prompt version, thresholds and a hash
     * of the query, so the text itself is never part of a key.
     */
    public function cacheKey(string $text): string
    {
        $label = $this->encoder->label();
        $thresholds = $this->minScore().'|'.$this->limit().'|'.(int) config('ai.photo_search.candidates', 40);
        $generation = $this->similar->generation($label);

        return "photo-search:{$label}:{$generation}:{$this->promptVersion()}:".substr(md5($thresholds), 0, 8).':'.sha1($text);
    }

    public function minScore(): float
    {
        return (float) config('ai.photo_search.min_score');
    }

    /**
     * The prompt version recorded on the run row, such as "photo-search@v1".
     */
    public function promptVersion(): string
    {
        $name = $this->promptName();
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr_replace($name, '@', $dot, 1);
    }

    private function promptName(): string
    {
        return (string) config('ai.photo_search.prompt');
    }

    /**
     * The prompt template with its {query} placeholder, as shown on the lab page.
     */
    public function template(): string
    {
        return trim((string) file_get_contents(resource_path('prompts/'.$this->promptName().'.md')));
    }

    /**
     * The text as the encoder receives it: wrapped by the prompt template.
     */
    public function prompt(string $text): string
    {
        return str_replace('{query}', $text, $this->template());
    }

    private function limit(): int
    {
        return max(1, (int) config('ai.photo_search.limit', 12));
    }

    /**
     * The trimmed query with spaces collapsed, casing kept.
     *
     * @throws ValidationException
     */
    private function validated(string $query): string
    {
        Validator::make(['q' => $query], ['q' => ['string', new ValidUtf8Text]])->validate();

        $text = trim((string) preg_replace('/\s+/u', ' ', $query));

        Validator::make(['q' => $text], ['q' => ['required', 'string', 'min:'.self::MIN_QUERY_LENGTH, 'max:'.self::MAX_QUERY_LENGTH]])->validate();

        return $text;
    }

    /**
     * @param  list<SimilarPhotoMatch>  $candidates  nearest first
     * @return list<SimilarPhotoMatch>
     */
    private function filter(array $candidates): array
    {
        $minScore = $this->minScore();
        $seen = [];
        $kept = [];

        foreach ($candidates as $candidate) {
            if ($candidate->similarity < $minScore || in_array($candidate->listingId, $seen, true)) {
                continue;
            }

            $seen[] = $candidate->listingId;
            $kept[] = $candidate;

            if (count($kept) === $this->limit()) {
                break;
            }
        }

        return $kept;
    }

    private function elapsedMs(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
