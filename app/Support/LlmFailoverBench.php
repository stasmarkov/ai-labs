<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\AiRunRepositoryInterface;
use App\Http\Requests\AssistantAskRequest;
use App\Http\Requests\ModerateListingRequest;
use App\Http\Requests\NlSearchRequest;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Laravel\Ai\AiManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Breaks the local model on purpose and records what each user-facing AI entry point does: the HTTP status, what the
 * client sees, how long the failure takes and whether a failed ai_runs row was written. The requests run in-process
 * through the HTTP kernel with config overrides that are put back afterwards; .env is never touched. No fallback is built.
 */
final readonly class LlmFailoverBench
{
    public const FAULTS = ['unreachable', 'model_missing', 'timeout'];

    public const ENTRIES = ['describe', 'nl-search', 'moderation', 'assistant'];

    public const MISSING_MODEL = 'llm-bench-missing:0';

    public const CLOSED_URL = 'http://127.0.0.1:9';

    private const RUN_WINDOW = 200;

    public function __construct(
        private LlmSpeedBench $speed,
        private AiRunRepositoryInterface $runs,
    ) {}

    /**
     * @return array<string, array<string, array<string, mixed>>> fault => entry point => row
     */
    public function measure(string $model): array
    {
        $matrix = [];

        foreach (self::FAULTS as $fault) {
            $matrix[$fault] = $this->fault($fault, $model);
        }

        return $matrix;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fault(string $fault, string $model): array
    {
        $bodies = $this->bodies();
        $config = (array) config('ai');
        $request = app('request');

        try {
            config($this->overrides($fault, $model));
            app(AiManager::class)->forgetInstance('ollama');

            $rows = [];
            $calls = [
                'describe' => fn (): array => $this->request('describe', 'GET', '/listings/'.LlmSpeedBench::LISTING_ID.'/describe'),
                'nl-search' => fn (): array => $this->request('nl-search', 'GET', '/api/listings/nl?q='.rawurlencode($bodies['nl'])),
                'moderation' => fn (): array => $this->request('moderation', 'POST', '/api/moderation', $bodies['moderation'], ['HTTP_SEC_FETCH_SITE' => 'same-origin']),
                'assistant' => fn (): array => $this->request('assistant', 'POST', '/api/assistant/ask', $bodies['assistant']),
            ];

            foreach ($calls as $entry => $call) {
                if ($fault === 'timeout') {
                    // A load that the 1 s timeout abandoned goes on inside Ollama, so each entry starts from an unloaded model.
                    $this->speed->unload($model);
                }

                $rows[$entry] = $call();
            }

            return $rows;
        } finally {
            config(['ai' => $config]);
            app(AiManager::class)->forgetInstance('ollama');
            app()->instance('request', $request);
            Facade::clearResolvedInstance('request');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function overrides(string $fault, string $model): array
    {
        $overrides = [
            'ai.settings_ui' => false,
            'ai.describe_per_minute' => 1_000_000,
            'ai.nl_search.per_minute' => 1_000_000,
            'ai.moderation.per_minute' => 1_000_000,
            'ai.assistant.per_minute' => 1_000_000,
        ];

        foreach (['describe', 'nl-search', 'moderation', 'assistant'] as $feature) {
            $overrides["ai.features.{$feature}.provider"] = 'ollama';
            $overrides["ai.features.{$feature}.model"] = $fault === 'model_missing' ? self::MISSING_MODEL : $model;
        }

        if ($fault === 'unreachable') {
            $overrides['ai.providers.ollama.url'] = self::CLOSED_URL;
        }

        if ($fault === 'timeout') {
            $overrides['ai.providers.ollama.timeout'] = 1;
            $overrides['ai.nl_search.timeout'] = 1;
        }

        return $overrides;
    }

    /**
     * The inputs are the longest the validators accept, so the prompt is large enough that a model which has to be loaded
     * first cannot answer inside the 1 s timeout.
     *
     * @return array{nl: string, moderation: array<string, mixed>, assistant: array<string, mixed>}
     */
    private function bodies(): array
    {
        $listing = $this->speed->listing()->listing;
        $filler = static fn (string $head, int $length): string => mb_substr($head.str_repeat(' Well kept, one owner, full service history, two keys, no accidents.', (int) ceil($length / 20)), 0, $length);

        return [
            'nl' => $filler('diesel BMW in Lviv under 20000', NlSearchRequest::MAX_QUERY_LENGTH),
            'moderation' => [
                'title' => "{$listing->year} {$listing->make} {$listing->model}",
                'price_eur' => (int) round($listing->priceCents / 100),
                'make' => $listing->make,
                'model' => $listing->model,
                'year' => $listing->year,
                'mileage_km' => $listing->mileageKm,
                'city' => $listing->city,
                'description' => $filler('Well kept car, service book, two keys, available for a viewing on weekdays.', ModerateListingRequest::MAX_DESCRIPTION_LENGTH),
                'listing_id' => $listing->id,
            ],
            'assistant' => ['question' => $filler('Do you have a petrol Volkswagen Golf under 15000 EUR?', AssistantAskRequest::MAX_QUESTION_LENGTH)],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @param  array<string, string>  $server
     * @return array<string, mixed>
     */
    private function request(string $entry, string $method, string $uri, ?array $json = null, array $server = []): array
    {
        $before = $this->knownRuns();
        $request = Request::create($uri, $method, [], [], [], [
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            ...($json === null ? [] : ['CONTENT_TYPE' => 'application/json']),
            ...$server,
        ], $json === null ? null : json_encode($json));

        $kernel = app(Kernel::class);
        $started = hrtime(true);
        $response = $kernel->handle($request);
        $body = $this->body($response);
        $elapsedMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $kernel->terminate($request, $response);

        $new = array_values(array_diff($this->knownRuns(), $before));
        $details = array_filter(array_map(fn (string $id) => $this->runs->findByInvocationId($id), $new));
        $failed = array_values(array_filter($details, static fn ($detail): bool => $detail->summary->status === 'failed'));

        $client = $this->client($response, $body);
        $stored = array_map(fn (string $id): ?string => $this->runs->storedErrorMessage($id), array_map(static fn ($detail): string => $detail->summary->invocationId, $failed));
        $messages = array_values(array_filter(array_map(static fn (?string $message): ?string => $message === null ? null : LlmBenchRedactor::clean($message), $stored)));
        $redacted = array_values(array_filter($stored)) !== $messages;

        return [
            'status' => $response->getStatusCode(),
            'client' => $client,
            'answered' => $response->getStatusCode() < 400 && ($client['kind'] === 'json' || $client['event'] === 'none'),
            'time_to_fail_ms' => $elapsedMs,
            'failed_run_written' => $failed !== [],
            'invocation_ids' => $new,
            'run_error_messages' => $messages,
            'message_redacted' => $redacted,
        ];
    }

    /**
     * @return list<string>
     */
    private function knownRuns(): array
    {
        return array_map(static fn ($run): string => $run->invocationId, $this->runs->recent(self::RUN_WINDOW));
    }

    private function body(Response $response): string
    {
        if (! $response instanceof StreamedResponse) {
            return (string) $response->getContent();
        }

        $captured = '';
        ob_start(static function (string $buffer) use (&$captured): string {
            $captured .= $buffer;

            return '';
        });
        $response->sendContent();
        ob_end_clean();

        return $captured;
    }

    /**
     * What the client reads: the message and code of a JSON error, or the first error or refused event of a stream.
     *
     * @return array<string, mixed>
     */
    private function client(Response $response, string $body): array
    {
        if (! str_starts_with((string) $response->headers->get('Content-Type'), 'text/event-stream')) {
            $json = json_decode($body, true);

            return [
                'kind' => 'json',
                'message' => is_array($json) && is_string($json['message'] ?? null) ? LlmBenchRedactor::clean($json['message']) : null,
                'code' => is_array($json) && is_string($json['code'] ?? null) ? $json['code'] : null,
            ];
        }

        foreach (explode("\n", $body) as $line) {
            $event = str_starts_with($line, 'data: ') ? json_decode(substr($line, 6), true) : null;

            if (is_array($event) && in_array($event['type'] ?? null, ['error', 'refused'], true)) {
                return [
                    'kind' => 'sse',
                    'event' => $event['type'],
                    'code' => is_string($event['code'] ?? null) ? $event['code'] : null,
                    'message' => is_string($event['message'] ?? null) ? LlmBenchRedactor::clean($event['message']) : null,
                ];
            }
        }

        return ['kind' => 'sse', 'event' => 'none', 'code' => null, 'message' => null];
    }
}
