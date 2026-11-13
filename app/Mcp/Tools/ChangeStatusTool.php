<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Ai\AdminTools\AdminResultCap;
use App\Services\McpToolCallLogger;
use App\Services\PendingChangeStatusService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('change_status')]
#[Description('Read the status of a proposed change: pending, confirmed (with the applied result), rejected, expired or failed. Confirming a change needs the developer in the browser. Read only.')]
#[IsReadOnly]
final class ChangeStatusTool extends Tool
{
    public function handle(Request $request, PendingChangeStatusService $statuses, McpToolCallLogger $calls): Response
    {
        $startedAt = hrtime(true);
        $arguments = $request->all();
        $id = $arguments['id'] ?? null;

        try {
            $description = is_string($id) ? $statuses->describe(trim($id)) : null;
        } catch (Throwable $exception) {
            report($exception);
            $calls->log($this->name(), $arguments, 'failed', null, $exception::class, $startedAt);

            return Response::error('The tool failed.');
        }

        if ($description === null) {
            $calls->log($this->name(), $arguments, 'error', null, 'No change with this id.', $startedAt);

            return Response::error('No change with this id.');
        }

        $calls->log($this->name(), $arguments, 'ok', $calls->summarise($description), null, $startedAt);

        return Response::text(AdminResultCap::encode($description));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The change id a propose tool returned.')->required(),
        ];
    }
}
