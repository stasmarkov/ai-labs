<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\HasStructuredOutput;

#[Temperature(0)]
final class ResearchNoteAgent extends ResearchAgent implements HasStructuredOutput
{
    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'facts' => $schema->array()
                ->items($schema->object([
                    'text' => $schema->string()->required(),
                    'chunk_ids' => $schema->array()->items($schema->integer())->required(),
                ])->withoutAdditionalProperties())
                ->required(),
            'answered' => $schema->boolean()->required(),
        ];
    }

    protected function promptKey(): string
    {
        return 'research_note';
    }
}
