<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A playground run that is refused before any target is called. The field names the request key to blame, so a 422 can
 * carry it, and the reason is a stable code.
 */
final class PlaygroundRunException extends RuntimeException
{
    private function __construct(public readonly string $reason, public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function sourceRequired(): self
    {
        return new self('source_required', 'system_prompt', 'Send either a prompt version or a system prompt.');
    }

    public static function sourceAmbiguous(): self
    {
        return new self('source_ambiguous', 'system_prompt', 'Send a prompt version or a system prompt, not both.');
    }

    public static function versionNotFound(): self
    {
        return new self('version_not_found', 'prompt_version_id', 'The prompt version does not exist.');
    }

    public static function invalidSystemPrompt(int $max): self
    {
        return new self('invalid_system_prompt', 'system_prompt', "The system prompt is 1 to {$max} characters.");
    }

    public static function invalidInput(int $max): self
    {
        return new self('invalid_input', 'input', "The input is 1 to {$max} characters.");
    }

    public static function invalidTargetCount(int $min, int $max): self
    {
        return new self('invalid_target_count', 'targets', "Pick {$min} to {$max} targets.");
    }

    public static function duplicateTarget(int $index): self
    {
        return new self('duplicate_target', "targets.{$index}", 'Each target must be a different provider and model.');
    }

    public static function providerNotEnabled(int $index): self
    {
        return new self('provider_not_enabled', "targets.{$index}.provider", 'This provider is not enabled.');
    }

    public static function modelNotAvailable(int $index): self
    {
        return new self('model_not_available', "targets.{$index}.model", 'This model is not available for the provider.');
    }

    public static function invalidTemperature(): self
    {
        return new self('invalid_temperature', 'temperature', 'The temperature is between 0 and 2.');
    }

    public static function invalidMaxTokens(int $min, int $max): self
    {
        return new self('invalid_max_tokens', 'max_tokens', "The maximum tokens are {$min} to {$max}.");
    }
}
