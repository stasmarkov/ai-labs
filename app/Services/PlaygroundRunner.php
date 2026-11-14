<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiProviders;
use App\Ai\AiRunRecorder;
use App\Ai\OllamaServerInfo;
use App\Ai\PlaygroundAgent;
use App\Contracts\PlaygroundPromptRepositoryInterface;
use App\Contracts\PlaygroundRunRepositoryInterface;
use App\DTOs\AiSettingChoice;
use App\DTOs\ModelOption;
use App\DTOs\NewPlaygroundRun;
use App\DTOs\PlaygroundResultRecord;
use App\DTOs\PlaygroundRunRecord;
use App\DTOs\PlaygroundRunRequest;
use App\DTOs\PlaygroundTarget;
use App\Enums\PlaygroundResultStatus;
use App\Exceptions\PlaygroundRunException;
use App\Support\PlaygroundErrorRedactor;
use Throwable;

/**
 * Runs one input against 2 or 3 targets with the same system prompt, one target after another, and stores the run.
 * Everything that can be refused is refused before the first call; after that a failing target only fails its own result.
 */
final readonly class PlaygroundRunner
{
    public const MIN_TARGETS = 2;

    public const MAX_TARGETS = 3;

    public const MAX_INPUT_LENGTH = 4000;

    public const MAX_SYSTEM_PROMPT_LENGTH = 8000;

    public const MIN_MAX_TOKENS = 16;

    public const MAX_MAX_TOKENS = 2048;

    public const MAX_TEMPERATURE = 2.0;

    public const UNSAVED_LABEL = 'playground:unsaved';

    private const DEFAULT_TEMPERATURE = 0.2;

    private const DEFAULT_MAX_TOKENS = 512;

    private const MAX_ERROR_LENGTH = 300;

    private const RUN_LOG_LABEL_LENGTH = 80;

    public function __construct(
        private AiProviders $providers,
        private OllamaServerInfo $ollama,
        private PromptInjectionGuard $guard,
        private PlaygroundPromptRepositoryInterface $prompts,
        private PlaygroundRunRepositoryInterface $runs,
    ) {}

    /**
     * @throws PlaygroundRunException when the request is refused; no target has been called then.
     */
    public function run(PlaygroundRunRequest $request): PlaygroundRunRecord
    {
        [$versionId, $systemPrompt, $label, $temperature, $maxTokens] = $this->resolveSource($request);

        $this->validateInput($request->input);
        $this->validateTargets($request->targets);

        $flag = $this->guard->match($request->input);

        $results = [];

        foreach ($request->targets as $index => $target) {
            $results[] = $this->call($index + 1, $target, $systemPrompt, $request->input, $temperature, $maxTokens, $label);
        }

        return $this->runs->create(new NewPlaygroundRun(
            promptVersionId: $versionId,
            systemPrompt: $systemPrompt,
            input: $request->input,
            inputFlags: $flag === null ? [] : [$flag],
            temperature: $temperature,
            maxTokens: $maxTokens,
            versionLabel: $label,
            results: $results,
        ));
    }

    /**
     * @return array{0: ?int, 1: string, 2: string, 3: float, 4: int}
     */
    private function resolveSource(PlaygroundRunRequest $request): array
    {
        $hasText = $request->systemPrompt !== null;

        if ($request->promptVersionId === null && ! $hasText) {
            throw PlaygroundRunException::sourceRequired();
        }

        if ($request->promptVersionId !== null && $hasText) {
            throw PlaygroundRunException::sourceAmbiguous();
        }

        if ($request->promptVersionId !== null) {
            $version = $this->prompts->findVersion($request->promptVersionId) ?? throw PlaygroundRunException::versionNotFound();
            $versionId = $version->id;
            $systemPrompt = $version->systemPrompt;
            $label = $version->label();
            $temperature = $version->temperature;
            $maxTokens = $version->maxTokens;
        } else {
            $versionId = null;
            $systemPrompt = trim(str_replace(["\r\n", "\r"], "\n", (string) $request->systemPrompt));
            $label = self::UNSAVED_LABEL;
            $temperature = self::DEFAULT_TEMPERATURE;
            $maxTokens = self::DEFAULT_MAX_TOKENS;

            if ($systemPrompt === '' || mb_strlen($systemPrompt) > self::MAX_SYSTEM_PROMPT_LENGTH) {
                throw PlaygroundRunException::invalidSystemPrompt(self::MAX_SYSTEM_PROMPT_LENGTH);
            }
        }

        $temperature = round($request->temperature ?? $temperature, 2);
        $maxTokens = $request->maxTokens ?? $maxTokens;

        if ($temperature < 0 || $temperature > self::MAX_TEMPERATURE) {
            throw PlaygroundRunException::invalidTemperature();
        }

        if ($maxTokens < self::MIN_MAX_TOKENS || $maxTokens > self::MAX_MAX_TOKENS) {
            throw PlaygroundRunException::invalidMaxTokens(self::MIN_MAX_TOKENS, self::MAX_MAX_TOKENS);
        }

        return [$versionId, $systemPrompt, $label, $temperature, $maxTokens];
    }

    private function validateInput(string $input): void
    {
        if (trim($input) === '' || mb_strlen($input) > self::MAX_INPUT_LENGTH) {
            throw PlaygroundRunException::invalidInput(self::MAX_INPUT_LENGTH);
        }
    }

    /**
     * @param  list<PlaygroundTarget>  $targets
     */
    private function validateTargets(array $targets): void
    {
        if (count($targets) < self::MIN_TARGETS || count($targets) > self::MAX_TARGETS) {
            throw PlaygroundRunException::invalidTargetCount(self::MIN_TARGETS, self::MAX_TARGETS);
        }

        $seen = [];
        $installed = null;

        foreach ($targets as $index => $target) {
            if (! $this->providers->isEnabledTextProvider($target->provider)) {
                throw PlaygroundRunException::providerNotEnabled($index);
            }

            if (isset($seen[$this->identity($target)])) {
                throw PlaygroundRunException::duplicateTarget($index);
            }

            $seen[$this->identity($target)] = true;

            if (config("ai.providers.{$target->provider}.driver") === 'ollama') {
                $installed ??= array_map(
                    fn (ModelOption $option): string => $option->name,
                    array_filter($this->ollama->models(), fn (ModelOption $option): bool => $option->kind === ModelOption::TEXT),
                );
            }

            if (! $this->isListedModel($target, $installed ?? [])) {
                throw PlaygroundRunException::modelNotAvailable($index);
            }
        }
    }

    /**
     * @param  list<string>  $installed  the text models the Ollama server lists, without a ":latest" tag
     */
    private function isListedModel(PlaygroundTarget $target, array $installed): bool
    {
        $driver = (string) config("ai.providers.{$target->provider}.driver");

        if ($driver === 'ollama') {
            return in_array(OllamaServerInfo::canonical($target->model), $installed, true);
        }

        if ($this->providers->isCloud($target->provider)) {
            // The playground itself is local-only, where the developer may type any id of the right shape. Should it
            // ever be reachable elsewhere, a cloud key must only serve the models the operator configured.
            return $this->providers->playgroundAvailable()
                ? preg_match(AiSettingChoice::MODEL_PATTERN, $target->model) === 1
                : in_array($target->model, [config("ai.providers.{$target->provider}.models.text.default"), ...$this->ollama->available($target->provider)], true);
        }

        $configured = [config("ai.providers.{$target->provider}.models.text.default"), ...$this->ollama->available($target->provider)];

        return in_array($target->model, $configured, true);
    }

    private function identity(PlaygroundTarget $target): string
    {
        $model = config("ai.providers.{$target->provider}.driver") === 'ollama' ? OllamaServerInfo::canonical($target->model) : $target->model;

        return "{$target->provider}:{$model}";
    }

    private function call(int $position, PlaygroundTarget $target, string $systemPrompt, string $input, float $temperature, int $maxTokens, string $label): PlaygroundResultRecord
    {
        $agent = new PlaygroundAgent($systemPrompt, $target->provider, $target->model, $temperature, $maxTokens, (int) config('ai.playground.target_timeout', 60));
        $recorder = app(AiRunRecorder::class);
        $startedAt = hrtime(true);

        try {
            $response = $agent->prompt($input);
        } catch (Throwable $e) {
            report($e);

            $durationMs = intdiv(hrtime(true) - $startedAt, 1_000_000);
            $invocationId = $recorder->recordRun('failed', class_basename($agent), $target->provider, $target->model, $this->runLogLabel($label), $durationMs, null, exception: $e);

            return new PlaygroundResultRecord(
                position: $position,
                provider: $target->provider,
                model: $target->model,
                status: $this->isTimeout($e) ? PlaygroundResultStatus::Timeout : PlaygroundResultStatus::Failed,
                output: null,
                error: $this->redact($e),
                tokensIn: null,
                tokensOut: null,
                ttftMs: null,
                durationMs: $durationMs,
                aiRunInvocationId: $invocationId,
            );
        }

        $durationMs = intdiv(hrtime(true) - $startedAt, 1_000_000);
        $invocationId = $recorder->recordRun(
            'completed',
            class_basename($agent),
            $target->provider,
            $target->model,
            $this->runLogLabel($label),
            $durationMs,
            null,
            tokensIn: $response->usage->inputTokens,
            tokensOut: $response->usage->outputTokens,
            invocationId: $response->invocationId,
        );

        return new PlaygroundResultRecord(
            position: $position,
            provider: $target->provider,
            model: $target->model,
            status: PlaygroundResultStatus::Ok,
            output: $response->text,
            error: null,
            tokensIn: $response->usage->inputTokens,
            tokensOut: $response->usage->outputTokens,
            ttftMs: null,
            durationMs: $durationMs,
            aiRunInvocationId: $invocationId,
        );
    }

    /**
     * The label as the run log stores it: `ai_runs.prompt_version` holds 80 characters, and a long prompt name would
     * lose the whole row. The name is cut and the `@vN` suffix kept, so the version stays readable.
     */
    private function runLogLabel(string $label): string
    {
        if (mb_strlen($label) <= self::RUN_LOG_LABEL_LENGTH) {
            return $label;
        }

        $suffixAt = (int) strrpos($label, '@v');
        $suffix = $suffixAt > 0 ? substr($label, $suffixAt) : '';

        return rtrim(mb_substr($label, 0, self::RUN_LOG_LABEL_LENGTH - mb_strlen($suffix))).$suffix;
    }

    /**
     * A connect or read timeout of the HTTP client; cURL reports both as error 28.
     */
    private function isTimeout(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'cURL error 28') || stripos($e->getMessage(), 'timed out') !== false;
    }

    /**
     * The error as stored and shown: keys, urls, hosts, addresses and ports are removed, and the text is cut.
     */
    private function redact(Throwable $e): string
    {
        return mb_substr(PlaygroundErrorRedactor::clean(AiRunRecorder::safeMessage($e)), 0, self::MAX_ERROR_LENGTH);
    }
}
