<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Ai\AdminTools\AdminResultCap;
use App\Ai\AdminTools\AdminTool;
use App\Ai\AdminTools\AdminToolFailed;
use App\Ai\AdminTools\AdminToolRegistry;
use App\Services\McpToolCallLogger;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * An MCP tool that publishes one tool of the admin assistant. Its schema comes from the admin tool's parameters, it
 * validates with the admin tool's own rules, and its result is the capped result the admin tool returns, so the two
 * entry points cannot drift apart.
 */
abstract class AdminToolWrapper extends Tool
{
    use PublishesAdminSchema;

    abstract protected function adminTool(): AdminTool;

    public function handle(Request $request, McpToolCallLogger $calls, AdminToolRegistry $registry): Response
    {
        $startedAt = hrtime(true);
        $arguments = $request->all();

        try {
            $tool = $this->adminTool();
            $errors = $tool->validate($arguments);

            if ($errors !== []) {
                return $this->refused($calls, $arguments, implode(' ', $errors), $startedAt);
            }

            $result = $registry->runRead($tool, $arguments);
        } catch (AdminToolFailed $exception) {
            return $this->refused($calls, $arguments, $exception->getMessage(), $startedAt);
        } catch (Throwable $exception) {
            report($exception);
            $calls->log($this->name(), $arguments, 'failed', null, $exception::class, $startedAt);

            return Response::error('The tool failed.');
        }

        $calls->log($this->name(), $arguments, 'ok', $calls->summarise($result), null, $startedAt);

        return Response::text(AdminResultCap::encode($result));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function refused(McpToolCallLogger $calls, array $arguments, string $message, int $startedAt): Response
    {
        $calls->log($this->name(), $arguments, 'error', null, $message, $startedAt);

        return Response::error($message);
    }
}
