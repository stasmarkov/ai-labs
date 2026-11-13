<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One entry of a model answer's tool_calls. The arguments stay the JSON string the model wrote: they are decoded, and
 * may fail to decode, only where the loop handles the call.
 */
final readonly class AdminToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public string $arguments,
    ) {}

    /**
     * @return array{id: string, type: string, function: array{name: string, arguments: string}}
     */
    public function toMessage(): array
    {
        return ['id' => $this->id, 'type' => 'function', 'function' => ['name' => $this->name, 'arguments' => $this->arguments]];
    }
}
