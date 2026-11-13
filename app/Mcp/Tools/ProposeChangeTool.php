<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Ai\AdminTools\AdminResultCap;
use App\Ai\AdminTools\AdminToolFailed;
use App\Ai\AdminTools\AdminWriteTool;
use App\Services\ChangeProposalService;
use App\Services\McpToolCallLogger;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * An MCP tool that proposes one write of the admin assistant. It stores a pending change and returns its id, preview
 * and confirmation URL. It never writes the listing: only the confirm endpoint does.
 */
abstract class ProposeChangeTool extends Tool
{
    use PublishesAdminSchema;

    abstract protected function adminTool(): AdminWriteTool;

    public function handle(Request $request, ChangeProposalService $proposals, McpToolCallLogger $calls): Response
    {
        $startedAt = hrtime(true);
        $arguments = $request->all();

        try {
            $proposal = $proposals->propose($this->adminTool(), $arguments);
        } catch (AdminToolFailed $exception) {
            $calls->log($this->name(), $arguments, 'error', null, $exception->getMessage(), $startedAt);

            return Response::error($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $calls->log($this->name(), $arguments, 'failed', null, $exception::class, $startedAt);

            return Response::error('The tool failed.');
        }

        $result = $proposal->toArray();
        $calls->log($this->name(), $arguments, 'proposed', $calls->summarise($result), null, $startedAt, $proposal->id);

        return Response::text(AdminResultCap::encode($result));
    }
}
