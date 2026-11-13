<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Ai\AdminTools\AdminTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Builds an MCP input schema from the parameters of an admin tool, so the two entry points publish the same arguments.
 */
trait PublishesAdminSchema
{
    abstract protected function adminTool(): AdminTool;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $parameters = $this->adminTool()->parameters();
        $required = $parameters['required'] ?? [];
        $properties = [];

        foreach ($parameters['properties'] ?? [] as $name => $definition) {
            $type = ($definition['type'] ?? 'string') === 'integer' ? $schema->integer() : $schema->string();

            if (isset($definition['enum'])) {
                $type->enum($definition['enum']);
            }

            $type->description($definition['description'] ?? '');

            if (in_array($name, $required, true)) {
                $type->required();
            }

            $properties[$name] = $type;
        }

        return $properties;
    }
}
