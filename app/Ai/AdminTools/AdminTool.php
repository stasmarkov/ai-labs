<?php

declare(strict_types=1);

namespace App\Ai\AdminTools;

/**
 * One tool of the admin assistant's hand-written loop. The loop sends name, description and parameters to the model,
 * calls validate on what comes back and, only when that returns no errors, run. A write tool is never run by the loop:
 * it only proposes the change, and the confirmation service runs it after the developer clicked Confirm.
 */
interface AdminTool
{
    public function name(): string;

    public function description(): string;

    /**
     * The JSON schema of the arguments, in the OpenAI function format.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    public function isWrite(): bool;

    /**
     * Checks the arguments in PHP before anything runs.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<string>
     */
    public function validate(array $arguments): array;

    /**
     * Runs the tool with arguments that passed validate and returns a result of at most
     * AdminResultCap::MAX_CHARACTERS characters of JSON.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws AdminToolFailed
     */
    public function run(array $arguments): array;
}
