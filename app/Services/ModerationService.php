<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiRunRecorder;
use App\Ai\ModerationAgent;
use App\DTOs\ModerationFactsResult;
use App\DTOs\ModerationInput;
use App\DTOs\ModerationResult;
use App\Exceptions\ProviderNotConfiguredException;
use App\Exceptions\ProviderNotEnabledException;
use App\Rules\ModerationRules;
use Throwable;
use UnexpectedValueException;

final readonly class ModerationService
{
    public const MAX_ATTEMPTS = 2;

    /**
     * The least risk a listing gets when PHP finds a signal itself (an injection pattern in the text or a reported VIN),
     * whatever the model answered.
     */
    public const FLOOR_RISK = 0.7;

    public function __construct(
        private ModerationFacts $facts,
        private AiRunRecorder $recorder,
    ) {}

    /**
     * Computes the facts, asks the model and checks the answer in PHP. An answer that fails the rules is asked for once
     * more; two rejected answers end as "invalid". A transport error is one attempt with no retry and ends as "failed".
     * Every attempt is one ai_runs row. A text that matches the input guard is classified like any other; the
     * match is a fact the model sees. The facts also set a floor in PHP: an injection pattern or a reported VIN lifts
     * the risk to FLOOR_RISK and adds the matching reason (and the listing is always flagged, whatever the threshold), so a model that was talked out of it still cannot rate the
     * listing safe. The result keeps the model's own risk next to the final one.
     *
     * @param  string|null  $model  a text model of the active provider that replaces the configured one (the eval pins it)
     *
     * @throws ProviderNotConfiguredException when the feature's cloud provider has no key; no run is recorded.
     * @throws ProviderNotEnabledException when the provider override is not an enabled text provider.
     */
    public function moderate(ModerationInput $input, ?string $provider = null, ?string $model = null): ModerationResult
    {
        $facts = $this->facts->compute($input);
        $agent = (new ModerationAgent)->usingProvider($provider)->usingModel($model);
        $invocationIds = [];
        $errors = [];
        $attempts = 0;
        $status = ModerationResult::INVALID;

        while ($attempts < self::MAX_ATTEMPTS) {
            $attempts++;
            $startedAt = hrtime(true);

            try {
                $response = $agent->classify($input, $facts);
                $output = $response->toArray();

                if ($output === []) {
                    throw new UnexpectedValueException('The model returned no structured output.');
                }
            } catch (ProviderNotConfiguredException|ProviderNotEnabledException $e) {
                throw $e;
            } catch (Throwable $e) {
                $unparsable = $e instanceof UnexpectedValueException;
                $invocationIds[] = $this->record(
                    $agent,
                    'failed',
                    $startedAt,
                    exception: $e,
                    validationFailed: $unparsable ? true : null,
                );

                if ($unparsable) {
                    $errors = ['output' => [AiRunRecorder::safeMessage($e)]];

                    continue;
                }

                $status = ModerationResult::FAILED;
                $errors = ['transport' => [$e::class, AiRunRecorder::safeMessage($e)]];

                break;
            }

            $validator = ModerationRules::validator($output);
            $invalid = $validator->fails();

            $invocationIds[] = $this->record(
                $agent,
                'completed',
                $startedAt,
                tokensIn: $response->usage->inputTokens,
                tokensOut: $response->usage->outputTokens,
                invocationId: $response->invocationId,
                validationFailed: $invalid,
            );

            if (! $invalid) {
                /** @var array{risk: int|float, reasons: array<int, string>, explanation: string} $data */
                $data = $validator->validated();
                $modelRisk = (float) $data['risk'];
                [$risk, $reasons, $floors] = $this->applyFloors($modelRisk, array_values($data['reasons']), $facts);
                $threshold = $this->thresholdFor($agent->modelName());

                return new ModerationResult(
                    ModerationResult::CLASSIFIED,
                    $risk,
                    $risk >= $threshold || $facts->injectionPattern !== null || $facts->vinReported,
                    $reasons,
                    $data['explanation'],
                    $facts,
                    $threshold,
                    $agent->providerName(),
                    $agent->modelName(),
                    $agent->promptVersion(),
                    $attempts,
                    $invocationIds,
                    modelRisk: $modelRisk,
                    floors: $floors,
                );
            }

            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();
        }

        return new ModerationResult(
            $status,
            null,
            null,
            [],
            null,
            $facts,
            $this->thresholdFor($agent->modelName()),
            $agent->providerName(),
            $agent->modelName(),
            $agent->promptVersion(),
            $attempts,
            $invocationIds,
            $errors,
        );
    }

    /**
     * @param  list<string>  $reasons
     * @return array{0: float, 1: list<string>, 2: list<string>} the risk, the reasons and the rules that changed them
     */
    private function applyFloors(float $risk, array $reasons, ModerationFactsResult $facts): array
    {
        $signals = [
            'injection_pattern' => [$facts->injectionPattern !== null, 'other'],
            'vin_reported' => [$facts->vinReported, 'reported_vin'],
        ];
        $applied = [];

        foreach ($signals as $name => [$found, $reason]) {
            if (! $found) {
                continue;
            }

            $changed = false;

            if ($risk < self::FLOOR_RISK) {
                $risk = self::FLOOR_RISK;
                $changed = true;
            }

            if (! in_array($reason, $reasons, true)) {
                $reasons = array_slice([$reason, ...$reasons], 0, ModerationRules::MAX_REASONS);
                $changed = true;
            }

            if ($changed) {
                $applied[] = $name;
            }
        }

        return [$risk, $reasons, $applied];
    }

    /**
     * The calibrated threshold of the model that answered, else the shared default.
     */
    public function thresholdFor(string $model): float
    {
        $thresholds = (array) config('ai.moderation.thresholds', []);

        return (float) ($thresholds[$model] ?? config('ai.moderation.threshold', 0.5));
    }

    private function record(
        ModerationAgent $agent,
        string $status,
        int $startedAt,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?string $invocationId = null,
        ?Throwable $exception = null,
        ?bool $validationFailed = null,
    ): string {
        return $this->recorder->recordRun(
            $status,
            class_basename($agent),
            $agent->providerName(),
            $agent->modelName(),
            $agent->promptVersion(),
            intdiv(hrtime(true) - $startedAt, 1_000_000),
            null,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            invocationId: $invocationId,
            exception: $exception,
            validationFailed: $validationFailed,
        );
    }
}
