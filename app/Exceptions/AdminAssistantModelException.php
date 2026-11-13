<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\DTOs\AdminStepReport;
use RuntimeException;
use Throwable;

/**
 * A model call of the admin assistant failed. "model_unavailable" (503) covers a timeout, a bad status and a body that
 * is not a chat completion; "model_unsupported" (422) means the model cannot use tools.
 */
final class AdminAssistantModelException extends RuntimeException
{
    public const UNAVAILABLE = 'model_unavailable';

    public const UNSUPPORTED = 'model_unsupported';

    /**
     * @param  string  $reason  one of the constants above
     */
    private function __construct(string $message, public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function unavailable(string $detail, ?Throwable $previous = null): self
    {
        return new self('The admin assistant model could not be reached: '.$detail, self::UNAVAILABLE, $previous);
    }

    public static function unsupported(string $model): self
    {
        return new self("The model \"{$model}\" does not support tools.", self::UNSUPPORTED);
    }

    /**
     * What the loop had done when this call failed, so a caller that keeps a committed decision can report it.
     *
     * @var list<AdminStepReport>
     */
    public array $steps = [];

    /**
     * @var list<string>
     */
    public array $runIds = [];

    /**
     * @param  list<AdminStepReport>  $steps
     * @param  list<string>  $runIds
     */
    public function withProgress(array $steps, array $runIds): self
    {
        $this->steps = $steps;
        $this->runIds = $runIds;

        return $this;
    }

    public function status(): int
    {
        return $this->reason === self::UNSUPPORTED ? 422 : 503;
    }
}
