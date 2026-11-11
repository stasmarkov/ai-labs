<?php

declare(strict_types=1);

namespace App\Ai;

use App\Exceptions\PhotoEncoderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The client of the image-encoder sidecar's OpenAI-style /embeddings endpoint. Images go out as base64 data URLs, in
 * batches of the provider's "batch" size; every vector is checked against the provider's configured dimensions.
 */
final readonly class PhotoEncoderClient
{
    private const FEATURE = 'embeddings-photos';

    public function __construct(private AiProviders $providers) {}

    /**
     * The "provider/model" label stored beside each vector.
     */
    public function label(): string
    {
        $choice = $this->providers->configured(self::FEATURE);

        return $choice->provider.'/'.$choice->model;
    }

    /**
     * The vector size the encoder model is configured to return.
     */
    public function dimensions(): int
    {
        return (int) config("ai.providers.{$this->providers->configured(self::FEATURE)->provider}.models.image_embeddings.dimensions", 0);
    }

    /**
     * Whether the encoder's /health answers within a second.
     */
    public function healthy(): bool
    {
        try {
            return $this->request($this->providers->configured(self::FEATURE)->provider, 1)->get('/health')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * One vector per image, in the order given.
     *
     * @param  list<string>  $images  raw image bytes
     * @return list<list<float>>
     *
     * @throws PhotoEncoderException
     */
    public function embed(array $images): array
    {
        $choice = $this->providers->configured(self::FEATURE);
        $entry = config("ai.providers.{$choice->provider}");

        if (! is_array($entry) || ($entry['driver'] ?? null) !== 'image-encoder') {
            throw PhotoEncoderException::notConfigured($choice->provider);
        }

        $size = max(1, (int) ($entry['batch'] ?? 16));
        $vectors = [];

        foreach (array_chunk($images, $size) as $batch) {
            array_push($vectors, ...$this->embedBatch($choice->provider, $choice->model, $batch, $this->dimensions()));
        }

        return $vectors;
    }

    /**
     * @param  list<string>  $batch
     * @return list<list<float>>
     */
    private function embedBatch(string $provider, string $model, array $batch, int $dimensions): array
    {
        try {
            $response = $this->request($provider, (int) config("ai.providers.{$provider}.timeout", 120))
                ->retry(2, 200, fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false)
                ->post('/embeddings', [
                    'model' => $model,
                    'modality' => 'image',
                    'input' => array_map(fn (string $bytes): string => $this->dataUrl($bytes), $batch),
                ]);
        } catch (ConnectionException) {
            throw PhotoEncoderException::unreachable($provider);
        }

        if (! $response->successful()) {
            throw PhotoEncoderException::failed($provider, $response->status());
        }

        $rows = $response->json('data');
        $rows = is_array($rows) ? $rows : [];
        usort($rows, fn (mixed $a, mixed $b): int => (int) ($a['index'] ?? 0) <=> (int) ($b['index'] ?? 0));

        if (count($rows) !== count($batch)) {
            throw PhotoEncoderException::wrongCount(count($batch), count($rows));
        }

        // Vectors are paired with photos by position, so the indices must be exactly 0..n-1: a duplicate or a gap
        // would store one photo's vector against another.
        if (array_map(fn (mixed $row): mixed => is_array($row) ? ($row['index'] ?? null) : null, $rows) !== range(0, count($batch) - 1)) {
            throw PhotoEncoderException::wrongCount(count($batch), count($rows));
        }

        $vectors = [];

        foreach ($rows as $row) {
            $vector = is_array($row['embedding'] ?? null) ? array_map(floatval(...), array_values($row['embedding'])) : [];

            if (count($vector) !== $dimensions) {
                throw PhotoEncoderException::wrongDimensions($model, $dimensions, count($vector));
            }

            $vectors[] = $vector;
        }

        return $vectors;
    }

    private function request(string $provider, int $timeout): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config("ai.providers.{$provider}.url"), '/'))
            ->timeout($timeout)
            ->connectTimeout(min($timeout, 5))
            ->acceptJson()
            ->asJson();
    }

    private function dataUrl(string $bytes): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return 'data:'.(is_string($mime) && str_starts_with($mime, 'image/') ? $mime : 'image/jpeg').';base64,'.base64_encode($bytes);
    }
}
