<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiProviders;
use App\Ai\AiRunRecorder;
use App\Ai\ListingFilterAgent;
use App\DTOs\ListingFilter;
use App\DTOs\NlSearchQuery;
use App\DTOs\NlSearchResult;
use App\Exceptions\NlSearchInvalidException;
use App\Exceptions\NlSearchRefusedException;
use App\Exceptions\NlSearchUnavailableException;
use App\Exceptions\ProviderNotConfiguredException;
use App\Rules\ListingFilterRules;
use App\Support\ServerTiming;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Throwable;

final readonly class NlSearchService
{
    public const FILTER_FIELDS = ['make', 'model', 'city', 'fuel', 'min_year', 'max_price_eur'];

    private const MAX_UNSUPPORTED = 10;

    private const MAX_UNSUPPORTED_LENGTH = 80;

    public function __construct(
        private ListingSearchService $listings,
        private PromptInjectionGuard $guard,
        private ServerTiming $timing,
    ) {}

    /**
     * @return list<string>
     */
    public static function allowedModels(): array
    {
        $configured = config('ai.nl_search.models');

        if (is_array($configured)) {
            return array_values(array_map('strval', $configured));
        }

        $configured = app(AiProviders::class)->configured('nl-search');

        // The second model is an Ollama id; offer it only when the feature runs on Ollama, never to a cloud API.
        return array_values(array_unique(array_filter([
            $configured->model,
            $configured->driver === 'ollama' ? 'gemma4:12b' : '',
        ], fn (string $model): bool => $model !== '')));
    }

    /**
     * @throws NlSearchRefusedException when the query matches the input guard; no model is called.
     * @throws NlSearchInvalidException when the model's output fails the listing filter rules; no listing query runs.
     * @throws NlSearchUnavailableException when the model call fails.
     * @throws ProviderNotConfiguredException when the feature's cloud provider has no key; no run is recorded.
     */
    public function search(NlSearchQuery $query): NlSearchResult
    {
        $recorder = app(AiRunRecorder::class);
        $agent = (new ListingFilterAgent($query->model))->usingProvider($query->provider);
        $startedAt = hrtime(true);

        $pattern = $this->guard->match($query->text);

        if ($pattern !== null) {
            $invocationId = $recorder->recordOutcome(
                'refused',
                class_basename($agent),
                $agent->providerName(),
                $agent->modelName(),
                $agent->promptVersion(),
                $this->elapsedMs($startedAt),
                'input:'.pathinfo((string) config('ai.docs.answer.guard'), PATHINFO_FILENAME).':'.$pattern,
            );

            throw new NlSearchRefusedException($pattern, $invocationId);
        }

        try {
            $response = $this->timing->measure('model', fn () => $agent->prompt($query->text, model: $agent->hasProviderOverride() ? null : $query->model));
            $structured = $response->structured;

            if ($structured === []) {
                throw new \UnexpectedValueException('The model returned no structured output.');
            }
        } catch (ProviderNotConfiguredException $e) {
            throw $e;
        } catch (Throwable $e) {
            $recorder->recordRun('failed', class_basename($agent), $agent->providerName(), $agent->modelName(), $agent->promptVersion(), $this->elapsedMs($startedAt), null, exception: $e);

            throw NlSearchUnavailableException::because($e);
        }

        $extracted = $this->extracted($structured);
        $unsupported = $this->unsupported($structured['unsupported'] ?? []);
        $input = $this->filterInput($extracted);

        $validator = Validator::make($input, ListingFilterRules::rules());
        $validator->after(fn (ValidatorContract $validator) => $this->rejectLooseNumbers($validator, $input));
        $failed = $validator->fails();

        $invocationId = $recorder->recordRun(
            'completed',
            class_basename($agent),
            $agent->providerName(),
            $agent->modelName(),
            $agent->promptVersion(),
            $this->elapsedMs($startedAt),
            null,
            tokensIn: $response->usage->inputTokens,
            tokensOut: $response->usage->outputTokens,
            invocationId: $response->invocationId,
            validationFailed: $failed,
        );

        if ($failed) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();

            throw new NlSearchInvalidException($errors, $extracted, $unsupported, $invocationId);
        }

        /** @var array<string, mixed> $data */
        $data = $validator->validated();
        $filters = array_filter($data, fn (mixed $value): bool => $value !== null);

        $page = $this->timing->measure('db', fn () => $this->listings->search(ListingFilter::fromValidated($data, $query->perPage, $query->page)));

        return new NlSearchResult($query->text, $agent->modelName(), $extracted, $unsupported, $filters, $page, $invocationId);
    }

    /**
     * Laravel's `integer` rule also accepts true, 2019.0 and "2019". The model must send a JSON integer, so anything
     * else is a field error, not a value quietly coerced into a different filter.
     *
     * @param  array<string, mixed>  $input
     */
    private function rejectLooseNumbers(ValidatorContract $validator, array $input): void
    {
        foreach (['min_year', 'max_price'] as $field) {
            $value = $input[$field];

            if ($value !== null && ! is_int($value) && ! $validator->errors()->has($field)) {
                $validator->errors()->add($field, "The {$field} field must be an integer.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function extracted(array $structured): array
    {
        $extracted = [];

        foreach (self::FILTER_FIELDS as $field) {
            $value = $structured[$field] ?? null;
            $extracted[$field] = is_string($value) ? (self::trimText($value) === '' ? null : self::trimText($value)) : $value;
        }

        return $extracted;
    }

    /**
     * The model's fields under the names of the /listings parameters, with the fuel lowercased.
     *
     * @param  array<string, mixed>  $extracted
     * @return array<string, mixed>
     */
    private function filterInput(array $extracted): array
    {
        return [
            'make' => $extracted['make'],
            'model' => $extracted['model'],
            'city' => $extracted['city'],
            'fuel' => is_string($extracted['fuel']) ? mb_strtolower($extracted['fuel']) : $extracted['fuel'],
            'min_year' => $extracted['min_year'],
            'max_price' => $extracted['max_price_eur'],
        ];
    }

    /**
     * @return list<string>
     */
    private function unsupported(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $terms = [];

        foreach ($value as $term) {
            if (is_string($term) && trim($term) !== '') {
                $terms[] = mb_substr(trim($term), 0, self::MAX_UNSUPPORTED_LENGTH);
            }
        }

        return array_slice($terms, 0, self::MAX_UNSUPPORTED);
    }

    /**
     * Trims whitespace only; PHP's default also strips NUL, which would hide a NUL byte from the UTF-8 rule.
     */
    private static function trimText(string $value): string
    {
        return trim($value, " \t\n\r\v\f");
    }

    private function elapsedMs(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
