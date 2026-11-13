<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\ChangeStatusTool;
use App\Mcp\Tools\CountByTagTool;
use App\Mcp\Tools\GetListingTool;
use App\Mcp\Tools\ProposeTagTool;
use App\Mcp\Tools\ProposeTitleChangeTool;
use App\Mcp\Tools\SearchListingsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('ai-lab listings')]
#[Version('1.0.0')]
#[Instructions('Listing tools of the ai-lab car marketplace. Read tools return capped results. Changes are only proposed. Give the developer the link; confirming a change needs the developer in the browser.')]
final class ListingsServer extends Server
{
    /** @var array<int, class-string<Server\Tool>> */
    protected array $tools = [
        SearchListingsTool::class,
        GetListingTool::class,
        CountByTagTool::class,
        ChangeStatusTool::class,
        ProposeTitleChangeTool::class,
        ProposeTagTool::class,
    ];
}
