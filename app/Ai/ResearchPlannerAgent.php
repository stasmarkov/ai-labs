<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\HasStructuredOutput;

#[Temperature(0)]
final class ResearchPlannerAgent extends ResearchAgent implements HasStructuredOutput
{
    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sub_questions' => $schema->array()->items($schema->string())->required(),
        ];
    }

    protected function promptKey(): string
    {
        return 'research_planner';
    }
}
